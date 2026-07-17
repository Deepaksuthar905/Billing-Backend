<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    private function notDeletedScope(): \Closure
    {
        return fn ($q) => $q->whereNull('isdel')->orWhere('isdel', '!=', 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatEmployeeForResponse(Employee $employee): array
    {
        $row = $employee->toArray();
        $row['monthly_salary'] = number_format((float) $employee->getRawOriginal('monthly_salary'), 2, '.', '');

        return $row;
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'emp_code' => ['nullable', 'string', 'max:20', 'unique:employees,emp_code'],
            'empname' => ['required', 'string', 'max:150'],
            'mobno' => ['nullable', 'string', 'max:15'],
            'designation' => ['nullable', 'string', 'max:100'],
            'joining_date' => ['nullable', 'date'],
            'monthly_salary' => ['nullable', 'numeric', 'min:0'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'bank_account' => ['nullable', 'string', 'max:30'],
            'ifsc' => ['nullable', 'string', 'max:11'],
            'pan_no' => ['nullable', 'string', 'max:10'],
            'addr' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'integer', 'in:0,1'],
        ]);

        if (isset($validated['monthly_salary'])) {
            $validated['monthly_salary'] = round((float) $validated['monthly_salary'], 2);
        }

        $employee = Employee::create($validated);

        return response()->json([
            'message' => 'Employee created successfully',
            'data' => $this->formatEmployeeForResponse($employee),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        if ($request->filled('is_active')) {
            $request->validate([
                'is_active' => ['integer', 'in:0,1'],
            ]);
        }

        $query = Employee::query()->where($this->notDeletedScope());

        if ($request->filled('is_active')) {
            $query->where('is_active', (int) $request->input('is_active'));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('empname', 'like', '%'.$search.'%')
                    ->orWhere('emp_code', 'like', '%'.$search.'%')
                    ->orWhere('mobno', 'like', '%'.$search.'%');
            });
        }

        $employees = $query->orderBy('empname')->get();

        return response()->json([
            'message' => 'Employees fetched successfully',
            'data' => $employees
                ->map(fn (Employee $employee) => $this->formatEmployeeForResponse($employee))
                ->values(),
        ], 200);
    }
}
