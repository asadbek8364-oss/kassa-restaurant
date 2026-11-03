<?php

namespace App\Http\Controllers\Api\Kassa;

use App\Models\Kassa\Employee;
use App\Models\Kassa\Store;
use App\Models\Kassa\Token;
use App\Rules\KassaPin;
use App\Services\Kassa\KassaDemoService;
use App\Support\Kassa\Presenter;
use App\Support\Kassa\StoreContext;
use App\Support\Restaurant\Apps;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends KassaController
{
    private const MAX_PIN_ATTEMPTS = 5;

    /** После 5 неверных PIN подряд вход сотрудника закрыт на 15 минут. */
    private const PIN_LOCK_SECONDS = 900;

    /**
     * Экран входа: магазины, сотрудники выбранного магазина (его продавцы и владельцы), нужна ли первая настройка.
     * Телефон помнит свой магазин и передаёт его в ?store_id; без него показывается первый магазин.
     */
    public function options(Request $request, StoreContext $context): JsonResponse
    {
        $store = $this->storeFor($request->query('store_id'));
        $context->set($store);

        return response()->json([
            'store' => Presenter::store($store),
            'stores' => Store::orderBy('id')->get(['id', 'name'])->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->all(),
            'needs_setup' => ! Employee::withoutGlobalScopes()->exists(),
            'employees' => Employee::where('active', true)->whereIn('role', Apps::roles('kassa'))->orderByRaw("CASE WHEN role = 'owner' THEN 0 ELSE 1 END")->orderBy('name')
                ->get(['id', 'name', 'role'])->map(fn ($e) => ['id' => $e->id, 'name' => $e->name, 'role' => $e->role])->all(),
            'demo_hint' => $store->is_demo && KassaDemoService::allowed() ? KassaDemoService::hint() : null,
            'demo_available' => KassaDemoService::allowed(),
            'setup_code_required' => self::setupState()['required'],
            'setup_ready' => self::setupState()['ready'],
        ]);
    }

    /** Магазин по id из запроса или первый (при пустой базе он создаётся). */
    private function storeFor(mixed $id): Store
    {
        $found = is_numeric($id) ? Store::find((int) $id) : null;

        return $found ?? Store::query()->orderBy('id')->first() ?? Store::current();
    }

    /**
     * Код установки обязателен, если задан KASSA_SETUP_CODE или сервер рабочий (APP_ENV=production).
     * На рабочем сервере без кода мастер закрыт — так магазин не займёт посторонний.
     */
    public static function setupState(): array
    {
        $code = (string) config('kassa.setup_code');
        $production = app()->environment('production');

        return ['code' => $code, 'required' => $code !== '' || $production, 'ready' => $code !== '' || ! $production];
    }

    /** Первая настройка: доступна, пока нет ни одного сотрудника. */
    public function setup(Request $request, KassaDemoService $demo): JsonResponse
    {
        if (Employee::withoutGlobalScopes()->exists()) {
            return response()->json(['message' => 'Касса уже настроена — войдите по PIN'], 409);
        }

        $setup = self::setupState();
        if (! $setup['ready']) {
            return response()->json(['message' => 'Мастер установки закрыт: задайте KASSA_SETUP_CODE в файле .env на сервере'], 403);
        }
        if ($setup['required'] && ! hash_equals($setup['code'], (string) $request->input('setup_code'))) {
            throw ValidationException::withMessages(['setup_code' => 'Неверный код установки — он задан в KASSA_SETUP_CODE в файле .env на сервере']);
        }

        if ($request->boolean('demo')) {
            if (! KassaDemoService::allowed()) {
                return response()->json(['message' => 'Демо-данные на этом сервере отключены'], 404);
            }
            $demo->load();
            $owner = Employee::withoutGlobalScopes()->where('role', 'owner')->orderBy('id')->firstOrFail();
        } else {
            $data = $this->check($request, [
                'store_name' => 'required|string|max:60',
                'owner_name' => 'required|string|max:60',
                'pin' => ['required', new KassaPin],
            ]);
            $owner = DB::transaction(function () use ($data) {
                Store::current()->update(['name' => trim($data['store_name'])]);

                return Employee::create(['name' => trim($data['owner_name']), 'role' => 'owner', 'pin' => $data['pin']]);
            });
        }

        return $this->session($owner, Store::query()->orderBy('id')->firstOrFail(), 201);
    }

    /**
     * Вход по PIN. Продавец попадает в свой магазин, владелец — в магазин телефона (store_id) или в первый.
     */
    public function login(Request $request): JsonResponse
    {
        $data = $this->check($request, [
            'employee_id' => 'required|integer',
            'pin' => 'required|digits_between:4,6',
            'store_id' => 'nullable|integer',
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
        if (! Apps::allows('kassa', $employee->role)) {
            // официант и повар работают в своих приложениях ресторана
            throw ValidationException::withMessages(['employee_id' => 'Ваш интерфейс — '.Apps::NAMES[Apps::home($employee->role)].': '.Apps::url(Apps::home($employee->role))]);
        }

        $store = $employee->isOwner() ? $this->storeFor($data['store_id'] ?? null) : Store::find($employee->store_id);
        if (! $store) {
            throw ValidationException::withMessages(['employee_id' => 'Магазин сотрудника не найден — обратитесь к владельцу']);
        }

        return $this->session($employee, $store);
    }

    /** Проверка связи для офлайн-очереди: сервер доступен и вход ещё действует. */
    public function ping(): JsonResponse
    {
        return response()->json(['ok' => true, 'store_id' => Store::current()->id, 'time' => now()->toIso8601String()]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->attributes->get('kassa_token')?->delete();

        return response()->json(['ok' => true]);
    }

    private function session(Employee $employee, Store $store, int $status = 200): JsonResponse
    {
        app(StoreContext::class)->set($store);

        return response()->json([
            'token' => Token::issue($employee, $store),
            'employee' => Presenter::employee($employee),
            'store' => Presenter::store($store),
        ], $status);
    }
}
