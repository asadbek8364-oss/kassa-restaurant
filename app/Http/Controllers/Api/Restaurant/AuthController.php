<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Api\Kassa\AuthController as KassaAuth;
use App\Models\Kassa\Employee;
use App\Models\Kassa\Store;
use App\Models\Kassa\Token;
use App\Models\Restaurant\Settings;
use App\Services\Restaurant\RestaurantDemoService;
use App\Services\Restaurant\Sync\SyncStatus;
use App\Support\Kassa\Presenter as KassaPresenter;
use App\Support\Kassa\StoreContext;
use App\Support\Restaurant\Apps;
use App\Support\Restaurant\Mode;
use App\Support\Restaurant\Presenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Вход в приложения ресторана — тот же PIN, что в кассе (одна таблица сотрудников, общий счётчик попыток).
 * Роль определяет, какой интерфейс откроется: если сотрудник вошёл не в своё приложение,
 * в ответе home — куда его перенаправить.
 */
class AuthController extends RestaurantController
{
    private const MAX_PIN_ATTEMPTS = 5;

    private const PIN_LOCK_SECONDS = 900;

    public const APPS = Apps::RESTAURANT;

    /** Экран входа приложения: ресторан, его сотрудники с доступом к приложению, нужна ли первая настройка. */
    public function options(Request $request, StoreContext $context): JsonResponse
    {
        $data = $this->check($request, ['app' => ['required', Rule::in(self::APPS)], 'store_id' => 'nullable|integer']);
        $found = isset($data['store_id']) ? Store::find((int) $data['store_id']) : null;
        $store = $found ?? Store::query()->orderBy('id')->first() ?? Store::current();
        $context->set($store);
        $app = $data['app'];
        $homeRole = Apps::HOME_ROLE[$app];
        $setup = KassaAuth::setupState();

        return response()->json([
            'app' => $app,
            'mode' => Mode::current(),
            'store' => KassaPresenter::store($store),
            'stores' => Store::orderBy('id')->get(['id', 'name'])->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->all(),
            'needs_setup' => ! Employee::withoutGlobalScopes()->exists(),
            'setup_code_required' => $setup['required'],
            'setup_ready' => $setup['ready'],
            // сначала те, для кого это приложение «своё»
            'employees' => Employee::where('active', true)->whereIn('role', Apps::roles($app))
                ->orderByRaw('CASE WHEN role = ? THEN 0 WHEN role = ? THEN 1 ELSE 2 END', [$homeRole, 'admin'])->orderBy('name')
                ->get(['id', 'name', 'role'])
                ->map(fn ($e) => ['id' => $e->id, 'name' => $e->name, 'role' => $e->role, 'role_name' => Apps::ROLE_NAMES[$e->role] ?? $e->role])->all(),
            'demo_hint' => $store->is_demo && RestaurantDemoService::allowed() ? RestaurantDemoService::HINT : null,
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $this->check($request, [
            'employee_id' => 'required|integer',
            'pin' => 'required|digits_between:4,6',
            'store_id' => 'nullable|integer',
            'app' => ['required', Rule::in(self::APPS)],
        ]);

        $key = 'kassa-pin:'.$data['employee_id'];
        if (RateLimiter::tooManyAttempts($key, self::MAX_PIN_ATTEMPTS)) {
            return response()->json(['message' => 'Слишком много попыток. Попробуйте через '.RateLimiter::availableIn($key).' сек.'], 429);
        }
        $employee = Employee::withoutGlobalScopes()->where('active', true)->find($data['employee_id']);
        if (! $employee || ! Hash::check($data['pin'], $employee->pin)) {
            RateLimiter::hit($key, self::PIN_LOCK_SECONDS);
            throw ValidationException::withMessages(['pin' => 'Неверный PIN']);
        }
        RateLimiter::clear($key);

        $store = $employee->isOwner()
            ? (isset($data['store_id']) ? Store::find((int) $data['store_id']) : null) ?? Store::query()->orderBy('id')->firstOrFail()
            : Store::find($employee->store_id);
        if (! $store) {
            throw ValidationException::withMessages(['employee_id' => 'Ресторан сотрудника не найден — обратитесь к администратору']);
        }
        app(StoreContext::class)->set($store);

        // своё приложение или то, в которое вошли, если роль его открывает
        $app = Apps::allows($data['app'], $employee->role) ? $data['app'] : Apps::home($employee->role);

        return response()->json([
            'token' => Token::issue($employee, $store),
            'employee' => Presenter::staff($employee),
            'store' => KassaPresenter::store($store),
            'app' => $app,
            'home' => Apps::url($app),
            'redirect' => $app !== $data['app'],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->attributes->get('kassa_token')?->delete();

        return response()->json(['ok' => true]);
    }

    /** Кто вошёл, ресторан, режим сервера, что ему доступно — при старте приложения. */
    public function whoami(Request $request, SyncStatus $sync): JsonResponse
    {
        $me = $this->me($request);
        $store = Store::current();
        $settings = Settings::current();
        $apps = array_values(array_filter([...Apps::RESTAURANT, 'kassa'], fn ($a) => Apps::allows($a, $me->role)));

        return response()->json([
            'employee' => Presenter::staff($me),
            'store' => KassaPresenter::store($store),
            'stores' => $me->isOwner() ? Store::orderBy('id')->get()->map(fn ($s) => KassaPresenter::store($s))->all() : [KassaPresenter::store($store)],
            'apps' => array_map(fn ($a) => ['app' => $a, 'url' => Apps::url($a), 'name' => Apps::NAMES[$a]], $apps),
            'mode' => Mode::current(),
            'mode_name' => Mode::label(),
            'timezone' => config('kassa.timezone'),
            'settings' => [
                'cook_minutes' => $settings->cook_minutes,
                'printer_mode' => $settings->printer_mode,
                'terminal' => ['enabled' => $settings->terminalOn(), 'test' => (bool) $settings->terminal_test],
            ],
            'poll_seconds' => (int) config('restaurant.poll_seconds', 5),
            'sync_configured' => Mode::local() && $settings->syncConfigured(),
            'sync_attention' => Apps::allows('admin', $me->role) && Mode::local() ? $sync->summary()['attention'] : false,
        ]);
    }
}
