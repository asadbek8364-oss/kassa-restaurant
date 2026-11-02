<?php

namespace App\Http\Controllers\Api\Kassa;

use App\Models\Kassa\Employee;
use App\Rules\KassaPin;
use App\Services\Kassa\KassaReportService;
use App\Support\Kassa\Presenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeeController extends KassaController
{
    public function __construct(private readonly KassaReportService $reports) {}

    public function index(): JsonResponse
    {
        return response()->json(['employees' => $this->reports->employees()]);
    }

    public function show(Employee $employee): JsonResponse
    {
        return response()->json($this->reports->employeeDetail($employee));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->check($request, $this->rules(pinRequired: true));
        $employee = Employee::create($this->clean($data) + ['active' => true]);

        return response()->json(['employee' => Presenter::employee($employee)], 201);
    }

    public function update(Request $request, Employee $employee): JsonResponse
    {
        $data = $this->clean($this->check($request, $this->rules(pinRequired: false)));
        if ($employee->isOwner() && $data['role'] !== 'owner' && $this->isLastOwner($employee)) {
            throw ValidationException::withMessages(['role' => 'В магазине должен остаться хотя бы один владелец']);
        }
        $employee->update($data);

        return response()->json(['employee' => Presenter::employee($employee)]);
    }

    /** Отключить или вернуть сотрудника. Отключение сразу закрывает его входы. */
    public function toggle(Request $request, Employee $employee): JsonResponse
    {
        if ($employee->active && $employee->isOwner() && $this->isLastOwner($employee)) {
            throw ValidationException::withMessages(['employee_id' => 'Нельзя отключить единственного владельца']);
        }
        if ($employee->id === $this->me($request)->id) {
            throw ValidationException::withMessages(['employee_id' => 'Нельзя отключить самого себя']);
        }
        $employee->update(['active' => ! $employee->active]);
        if (! $employee->active) {
            $employee->tokens()->delete();
        }

        return response()->json(['employee' => Presenter::employee($employee)]);
    }

    private function rules(bool $pinRequired): array
    {
        return [
            'name' => 'required|string|max:60',
            'role' => ['required', Rule::in(Employee::ROLES)],
            'phone' => 'nullable|string|max:20',
            'pin' => [$pinRequired ? 'required' : 'nullable', new KassaPin],
        ];
    }

    private function clean(array $data): array
    {
        $data['name'] = trim($data['name']);
        $phone = trim((string) ($data['phone'] ?? ''));
        $data['phone'] = $phone === '' || $phone === '+998' ? null : $phone;
        if (empty($data['pin'])) {
            unset($data['pin']);
        }

        return $data;
    }

    private function isLastOwner(Employee $employee): bool
    {
        return ! Employee::where('role', 'owner')->where('active', true)->whereKeyNot($employee->id)->exists();
    }
}
