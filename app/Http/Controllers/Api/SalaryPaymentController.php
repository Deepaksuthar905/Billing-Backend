<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PayBy;
use App\Models\SalaryPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SalaryPaymentController extends Controller
{
    private function notDeletedScope(): \Closure
    {
        return fn ($q) => $q->whereNull('isdel')->orWhere('isdel', '!=', 1);
    }

    private function resolvePayBy(array $validated): ?int
    {
        if (isset($validated['payby']) && $validated['payby'] !== '' && $validated['payby'] !== null) {
            return (int) $validated['payby'];
        }

        return PayBy::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', ['cash'])
            ->value('pbid');
    }

    private function calculateNetAmount(
        float $gross,
        float $incentive,
        float $arrears,
        float $deduction
    ): float {
        return round($gross + $incentive + $arrears - $deduction, 2);
    }

    private function nextReceiptNo(): string
    {
        $max = SalaryPayment::query()
            ->where($this->notDeletedScope())
            ->pluck('receipt_no')
            ->map(function (string $receiptNo) {
                if (preg_match('/^SAL-(\d+)$/i', trim($receiptNo), $matches)) {
                    return (int) $matches[1];
                }

                return 0;
            })
            ->max();

        $next = ((int) $max) + 1;

        return 'SAL-'.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatSalaryPaymentForResponse(SalaryPayment $payment): array
    {
        $row = $payment->toArray();

        foreach (['gross_amt', 'incentive_amt', 'arrears_amt', 'deduction_amt', 'net_amt'] as $field) {
            $raw = $payment->getRawOriginal($field);
            $row[$field] = $raw === null ? null : number_format((float) $raw, 2, '.', '');
        }

        return $row;
    }

    private function assertNoDuplicateMonthlySalary(
        int $empid,
        string $salaryMonth,
        string $paymentType,
        ?int $excludeSalid = null
    ): void {
        if ($paymentType !== 'salary') {
            return;
        }

        $query = SalaryPayment::query()
            ->where('empid', $empid)
            ->where('salary_month', $salaryMonth)
            ->where('payment_type', 'salary')
            ->where($this->notDeletedScope());

        if ($excludeSalid !== null) {
            $query->where('salid', '!=', $excludeSalid);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'salary_month' => ['Salary for this employee and month already exists.'],
            ]);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'receipt_no' => ['nullable', 'string', 'max:30', 'unique:salary_payments,receipt_no'],
            'empid' => ['required', 'integer', Rule::exists('employees', 'empid')->where(fn ($q) => $q->whereNull('isdel')->orWhere('isdel', '!=', 1))],
            'salary_month' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'dt' => ['required', 'date'],
            'gross_amt' => ['nullable', 'numeric', 'min:0'],
            'incentive_amt' => ['nullable', 'numeric', 'min:0'],
            'arrears_amt' => ['nullable', 'numeric', 'min:0'],
            'deduction_amt' => ['nullable', 'numeric', 'min:0'],
            'net_amt' => ['nullable', 'numeric', 'min:0'],
            'payby' => ['nullable', 'integer', 'exists:pay_by,pbid'],
            'refno' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:255'],
            'payment_type' => ['nullable', 'string', Rule::in(['salary', 'advance', 'bonus', 'final'])],
        ]);

        $employee = Employee::query()
            ->where('empid', $validated['empid'])
            ->where($this->notDeletedScope())
            ->first();

        if (! $employee) {
            return response()->json(['message' => 'Employee not found'], 404);
        }

        $paymentType = $validated['payment_type'] ?? 'salary';
        $this->assertNoDuplicateMonthlySalary((int) $validated['empid'], $validated['salary_month'], $paymentType);

        $gross = array_key_exists('gross_amt', $validated) && $validated['gross_amt'] !== null
            ? round((float) $validated['gross_amt'], 2)
            : round((float) $employee->monthly_salary, 2);

        $incentive = round((float) ($validated['incentive_amt'] ?? 0), 2);
        $arrears = round((float) ($validated['arrears_amt'] ?? 0), 2);
        $deduction = round((float) ($validated['deduction_amt'] ?? 0), 2);

        $net = array_key_exists('net_amt', $validated) && $validated['net_amt'] !== null
            ? round((float) $validated['net_amt'], 2)
            : $this->calculateNetAmount($gross, $incentive, $arrears, $deduction);

        if ($net < 0) {
            throw ValidationException::withMessages([
                'net_amt' => ['Net amount cannot be negative.'],
            ]);
        }

        $payment = SalaryPayment::create([
            'receipt_no' => $validated['receipt_no'] ?? $this->nextReceiptNo(),
            'empid' => (int) $validated['empid'],
            'salary_month' => $validated['salary_month'],
            'dt' => $validated['dt'],
            'gross_amt' => $gross,
            'incentive_amt' => $incentive,
            'arrears_amt' => $arrears,
            'deduction_amt' => $deduction,
            'net_amt' => $net,
            'payby' => $this->resolvePayBy($validated),
            'refno' => $validated['refno'] ?? null,
            'remarks' => $validated['remarks'] ?? null,
            'payment_type' => $paymentType,
        ]);

        return response()->json([
            'message' => 'Salary payment created successfully',
            'data' => $this->formatSalaryPaymentForResponse($payment->load(['employee', 'payBy'])),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'empid' => ['nullable', 'integer', 'exists:employees,empid'],
            'salary_month' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'payment_type' => ['nullable', 'string', Rule::in(['salary', 'advance', 'bonus', 'final'])],
        ]);

        $query = SalaryPayment::with(['employee', 'payBy'])->where($this->notDeletedScope());

        $from = $request->query('from');
        $to = $request->query('to');

        if ($from && $to) {
            $query->whereBetween('dt', [$from, $to]);
        } elseif ($from) {
            $query->whereDate('dt', '>=', $from);
        } elseif ($to) {
            $query->whereDate('dt', '<=', $to);
        }

        if ($request->filled('empid')) {
            $query->where('empid', (int) $request->input('empid'));
        }

        if ($request->filled('salary_month')) {
            $query->where('salary_month', $request->input('salary_month'));
        }

        if ($request->filled('payment_type')) {
            $query->where('payment_type', $request->input('payment_type'));
        }

        $payments = $query->orderByDesc('dt')->orderByDesc('salid')->get();

        return response()->json([
            'message' => 'Salary payments fetched successfully',
            'data' => $payments
                ->map(fn (SalaryPayment $payment) => $this->formatSalaryPaymentForResponse($payment))
                ->values(),
        ], 200);
    }

    public function show(int $salid): JsonResponse
    {
        $payment = SalaryPayment::with(['employee', 'payBy'])
            ->where('salid', $salid)
            ->where($this->notDeletedScope())
            ->first();

        if (! $payment) {
            return response()->json(['message' => 'Salary payment not found'], 404);
        }

        return response()->json([
            'message' => 'Salary payment fetched successfully',
            'data' => $this->formatSalaryPaymentForResponse($payment),
        ], 200);
    }

    public function update(Request $request, int $salid): JsonResponse
    {
        $payment = SalaryPayment::query()
            ->where('salid', $salid)
            ->where($this->notDeletedScope())
            ->first();

        if (! $payment) {
            return response()->json(['message' => 'Salary payment not found'], 404);
        }

        $validated = $request->validate([
            'empid' => ['sometimes', 'integer', Rule::exists('employees', 'empid')->where(fn ($q) => $q->whereNull('isdel')->orWhere('isdel', '!=', 1))],
            'salary_month' => ['sometimes', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'dt' => ['sometimes', 'date'],
            'gross_amt' => ['nullable', 'numeric', 'min:0'],
            'incentive_amt' => ['nullable', 'numeric', 'min:0'],
            'arrears_amt' => ['nullable', 'numeric', 'min:0'],
            'deduction_amt' => ['nullable', 'numeric', 'min:0'],
            'net_amt' => ['nullable', 'numeric', 'min:0'],
            'payby' => ['nullable', 'integer', 'exists:pay_by,pbid'],
            'refno' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:255'],
            'payment_type' => ['nullable', 'string', Rule::in(['salary', 'advance', 'bonus', 'final'])],
        ]);

        $empid = (int) ($validated['empid'] ?? $payment->empid);
        $salaryMonth = $validated['salary_month'] ?? $payment->salary_month;
        $paymentType = $validated['payment_type'] ?? $payment->payment_type ?? 'salary';

        $this->assertNoDuplicateMonthlySalary($empid, $salaryMonth, $paymentType, $salid);

        $gross = array_key_exists('gross_amt', $validated) && $validated['gross_amt'] !== null
            ? round((float) $validated['gross_amt'], 2)
            : round((float) $payment->gross_amt, 2);

        $incentive = array_key_exists('incentive_amt', $validated)
            ? round((float) ($validated['incentive_amt'] ?? 0), 2)
            : round((float) $payment->incentive_amt, 2);

        $arrears = array_key_exists('arrears_amt', $validated)
            ? round((float) ($validated['arrears_amt'] ?? 0), 2)
            : round((float) $payment->arrears_amt, 2);

        $deduction = array_key_exists('deduction_amt', $validated)
            ? round((float) ($validated['deduction_amt'] ?? 0), 2)
            : round((float) $payment->deduction_amt, 2);

        $net = array_key_exists('net_amt', $validated) && $validated['net_amt'] !== null
            ? round((float) $validated['net_amt'], 2)
            : $this->calculateNetAmount($gross, $incentive, $arrears, $deduction);

        if ($net < 0) {
            throw ValidationException::withMessages([
                'net_amt' => ['Net amount cannot be negative.'],
            ]);
        }

        $payload = [
            'empid' => $empid,
            'salary_month' => $salaryMonth,
            'gross_amt' => $gross,
            'incentive_amt' => $incentive,
            'arrears_amt' => $arrears,
            'deduction_amt' => $deduction,
            'net_amt' => $net,
            'payment_type' => $paymentType,
        ];

        if (array_key_exists('dt', $validated)) {
            $payload['dt'] = $validated['dt'];
        }
        if (array_key_exists('payby', $validated)) {
            $payload['payby'] = $this->resolvePayBy($validated);
        }
        if (array_key_exists('refno', $validated)) {
            $payload['refno'] = $validated['refno'];
        }
        if (array_key_exists('remarks', $validated)) {
            $payload['remarks'] = $validated['remarks'];
        }

        $payment->update($payload);

        return response()->json([
            'message' => 'Salary payment updated successfully',
            'data' => $this->formatSalaryPaymentForResponse($payment->fresh(['employee', 'payBy'])),
        ], 200);
    }
}
