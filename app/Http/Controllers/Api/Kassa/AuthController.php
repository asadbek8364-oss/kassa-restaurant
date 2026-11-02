<?php

namespace App\Http\Controllers\Api\Kassa;

use App\Models\Kassa\Employee;
use App\Models\Kassa\Store;
use App\Models\Kassa\Token;
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

    /** Экран входа: название магазина, список сотрудников, нужна ли первая настройка. */
    public function options(): JsonResponse
    {
        $store = Store::current();

        return response()->json([
            'store' => Presenter::store($store),
            'needs_setup' => ! Employee::exists(),
            'employees' => Employee::where('active', true)->orderByRaw("CASE WHEN role = 'owner' THEN 0 ELSE 1 END")->orderBy('name')
                ->get(['id', 'name', 'role'])->map(fn ($e) => ['id' => $e->id, 'name' => $e->name, 'role' => $e->role])->all(),
            'demo_hint' => $store->is_demo ? 'Демо: PIN владельца 1111, продавцов 2222, 3333, 4444' : null,
        ]);
    }

    /** Первая настройка: доступна, пока нет ни одного сотрудника. */
    public function setup(Request $request, KassaDemoService $demo): JsonResponse
    {
        if (Employee::exists()) {
            return response()->json(['message' => 'Касса уже настроена — войдите по PIN'], 409);
        }

        if ($request->boolean('demo')) {
            $demo->load();
            $owner = Employee::where('role', 'owner')->firstOrFail();
        } else {
            $data = $this->check($request, [
                'store_name' => 'required|string|max:60',
                'owner_name' => 'required|string|max:60',
                'pin' => 'required|digits_between:4,6',
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
            RateLimiter::hit($key, 60);
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
