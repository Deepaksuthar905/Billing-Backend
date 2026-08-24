<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpensesHead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpensesHeadController extends Controller
{
    /**
     * Create a new expenses_head record.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'integer', 'in:0,1'],
        ]);

        $expensesHead = ExpensesHead::create($validated);

        return response()->json([
            'message' => 'Expenses head created successfully',
            'data' => $expensesHead,
        ], 201);
    }

    /**
     * Fetch all expenses_head records.
     * Includes: payment (sum), party/payby/last_payment from latest expense under that head.
     */
    public function index(): JsonResponse
    {
        $notDeleted = fn ($q) => $q->whereNull('isdel')->orWhere('isdel', '!=', 1);

        $paymentByHead = Expense::query()
            ->selectRaw('exhid, COALESCE(SUM(payment), 0) as total_payment')
            ->where($notDeleted)
            ->groupBy('exhid')
            ->pluck('total_payment', 'exhid');

        // Latest expense per head (for autofill: party, payby, last amount)
        $latestByHead = Expense::query()
            ->where($notDeleted)
            ->whereNotNull('exhid')
            ->orderByDesc('dt')
            ->orderByDesc('exid')
            ->get(['exhid', 'party', 'payby', 'payment'])
            ->unique('exhid')
            ->keyBy('exhid');

        $expensesHeads = ExpensesHead::all()->map(function (ExpensesHead $head) use ($paymentByHead, $latestByHead) {
            $row = $head->toArray();
            $row['payment'] = (float) ($paymentByHead[$head->exhid] ?? 0);

            $latest = $latestByHead->get($head->exhid);
            $row['party'] = $latest?->party !== null ? (int) $latest->party : null;
            $row['payby'] = $latest?->payby !== null ? (int) $latest->payby : null;
            $row['last_payment'] = $latest ? round((float) $latest->payment, 2) : 0.0;

            return $row;
        });

        return response()->json([
            'message' => 'Expenses heads fetched successfully',
            'data' => $expensesHeads,
        ], 200);
    }
}
