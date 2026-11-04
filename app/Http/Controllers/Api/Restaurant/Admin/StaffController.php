<?php

namespace App\Http\Controllers\Api\Restaurant\Admin;

use App\Http\Controllers\Api\Restaurant\RestaurantController;
use App\Models\Kassa\Employee;
use App\Rules\KassaPin;
use App\Support\Restaurant\Apps;
use App\Support\Restaurant\Presenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Сотрудники ресторана: администраторы, кассиры, официанты, повара и их PIN (правила PIN — как в кассе: простые запрещены).
 * Владелец общий для всех ресторанов и здесь не редактируется.
 */
class StaffController extends RestaurantController
{
    public function index(): JsonResponse
    {
        $staff = Employee::orderByDesc('active')
            ->orderByRaw("CASE role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 WHEN 'cashier' THEN 2 WHEN 'waiter' THEN 3 WHEN 'cook' THEN 4 ELSE 5 END")
            ->orderBy('name')->get();

        return response()->json(['staff' => $staff->map(fn ($e) => Presenter::staff($e))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->data($request, pinRequired: true);
        $employee = Employee::create($data + ['active' => true]);

        return response()->json(['employee' => Presenter::staff($employee)], 201);
    }

    public function update(Request $request, Employee $employee): JsonResponse
    {
        $this->editable($employee);
        $employee->update($this->data($request, pinRequired: false));

        return response()->json(['employee' => Presenter::staff($employee)]);
    }

    /** Отключить или вернуть. Отключённый сразу теряет вход на всех устройствах. */
    public function toggle(Request $request, Employee $employee): JsonResponse
    {
        $this->editable($employee);
        if ($employee->id === $this->me($request)->id) {
            throw ValidationException::withMessages(['employee_id' => 'Нельзя отключить самого себя']);
        }
        $employee->update(['active' => ! $employee->active]);
        if (! $employee->active) {
            $employee->tokens()->delete();
        }

        return response()->json(['employee' => Presenter::staff($employee)]);
    }

    private function editable(Employee $employee): void
    {
        if ($employee->isOwner()) {
            throw ValidationException::withMessages(['employee_id' => 'Владельца меняют в кассе: Ещё → Сотрудники']);
        }
    }

    private function data(Request $request, bool $pinRequired): array
    {
        $data = $this->check($request, [
            'name' => 'required|string|max:60',
            'role' => ['required', Rule::in(Apps::STAFF_ROLES)],
            'phone' => 'nullable|string|max:20',
            'pin' => [$pinRequired ? 'required' : 'nullable', new KassaPin],
        ]);
        $phone = trim((string) ($data['phone'] ?? ''));
        $out = ['name' => trim($data['name']), 'role' => $data['role'], 'phone' => $phone === '' || $phone === '+998' ? null : $phone];
        if (! empty($data['pin'])) {
            $out['pin'] = $data['pin'];
        }

        return $out;
    }
}
