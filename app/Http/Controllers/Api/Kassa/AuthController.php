<?php

namespace App\Http\Controllers\Api\Kassa;

use App\Models\Kassa\Employee;
use App\Models\Kassa\Store;
use App\Models\Kassa\Token;
use App\Rules\KassaPin;
use App\Services\Kassa\KassaDemoService;
use App\Support\Kassa\Presenter;
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

    /** Экран входа: название магазина, список сотрудников, нужна ли первая настройка. */
    public function options(): JsonResponse
    {
        $store = Store::current();

        return response()->json([
            'store' => Presenter::store($store),
            'needs_setup' => ! Employee::exists(),
            'employees' => Employee::where('active', true)->orderByRaw("CASE WHEN role = 'owner' THEN 0 ELSE 1 END")->orderBy('name')
                ->get(['id', 'name', 'role'])->map(fn ($e) => ['id' => $e->id, 'name' => $e->name, 'role' => $e->role])->all(),
            'demo_hint' => $store->is_demo && KassaDemoService::allowed() ? 'Демо: PIN владельца 1111, продавцов 2222, 3333, 4444' : null,
            'demo_available' => KassaDemoService::allowed(),
            'setup_code_required' => $this->setupState()['required'],
            'setup_ready' => $this->setupState()['ready'],
        ]);
    }

    /**
     * Код установки обязателен, если задан KASSA_SETUP_CODE или сервер рабочий (APP_ENV=production).
     * На рабочем сервере без кода мастер закрыт — так магазин не займёт посторонний.
     */
    private function setupState(): array
    {
        $code = (string) config('kassa.setup_code');
        $production = app()->environment('production');

        return ['code' => $code, 'required' => $code !== '' || $production, 'ready' => $code !== '' || ! $production];
    }

    /** Первая настройка: доступна, пока нет ни одного сотрудника. */
    public function setup(Request $request, KassaDemoService $demo): JsonResponse
    {
        if (Employee::exists()) {
            return response()->json(['message' => 'Касса уже настроена — войдите по PIN'], 409);
        }

        $setup = $this->setupState();
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
            $owner = Employee::where('role', 'owner')->firstOrFail();
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

        return $this->session($owner, 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $this->check($request, [
            'employee_id' => 'required|integer',
            'pin' => 'required|digits_between:4,6',
        ]);

        $key = 'kassa-pin:'.$data['employee_id'];
        if (RateLimiter::tooManyAttempts($key, self::MAX_PIN_ATTEMPTS)) {
            return response()->json(['message' => 'Слишком много попыток. Попробуйте через '.RateLimiter::availableIn($key).' сек.'], 429);
        }

        $employee = Employee::where('active', true)->find($data['employee_id']);
        if (! $employee || ! Hash::check($data['pin'], $employee->pin)) {
            RateLimiter::hit($key, self::PIN_LOCK_SECONDS);
            throw ValidationException::withMessages(['pin' => 'Неверный PIN']);
        }
        RateLimiter::clear($key);

        return $this->session($employee);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->attributes->get('kassa_token')?->delete();

        return response()->json(['ok' => true]);
    }

    private function session(Employee $employee, int $status = 200): JsonResponse
    {
        return response()->json([
            'token' => Token::issue($employee),
            'employee' => Presenter::employee($employee),
            'store' => Presenter::store(Store::current()),
        ], $status);
    }
}
