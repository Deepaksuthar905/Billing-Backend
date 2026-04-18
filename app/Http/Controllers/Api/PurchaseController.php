<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Models\Expense;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseController extends Controller
{
    /**
     * Create a new purchase record.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'item_id' => ['required', 'integer', 'exists:items,item_id'],
            'p_inv_no' => ['nullable', 'string', 'max:100'],
            'dt' => ['nullable', 'date'],
            'state' => ['nullable', 'string', 'max:100'],
            'payment' => ['nullable', 'numeric', 'min:0'],
            'taxable_amt' => ['nullable', 'numeric', 'min:0'],
            'party_id' => ['nullable', 'integer', 'exists:party,pid'],
            // Frontend sends prhid; must be validated or it is stripped from $validated
            'prhid' => ['nullable', 'integer', 'exists:party,pid'],
            'gst' => ['nullable', 'numeric', 'min:0'],
            'cgst' => ['nullable', 'numeric', 'min:0'],
            'sgst' => ['nullable', 'numeric', 'min:0'],
            'igst' => ['nullable', 'numeric', 'min:0'],
            'payby' => ['nullable', 'integer', 'exists:pay_by,pbid'],
            'refno' => ['nullable', 'string', 'max:100'],
        ]);

        if (
            (! array_key_exists('party_id', $validated) || $validated['party_id'] === null || $validated['party_id'] === '')
            && isset($validated['prhid'])
        ) {
            $validated['party_id'] = (int) $validated['prhid'];
        }
        unset($validated['prhid']);

        $purchase = Purchase::create($validated);

        return response()->json([
            'message' => 'Purchase created successfully',
            'data' => $purchase,
        ], 201);
    }

    /**
     * Update an existing purchase. PUT /api/purchases/{id} (id = prid).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $purchase = Purchase::query()
            ->where('prid', $id)
            ->where(function ($q) {
                $q->whereNull('isdel')->orWhere('isdel', '!=', 1);
            })
            ->first();

        if (! $purchase) {
            return response()->json(['message' => 'Purchase not found'], 404);
        }

        $validated = $request->validate([
            'item_id' => ['sometimes', 'integer', 'exists:items,item_id'],
            'p_inv_no' => ['nullable', 'string', 'max:100'],
            'dt' => ['nullable', 'date'],
            'state' => ['nullable', 'string', 'max:100'],
            'payment' => ['nullable', 'numeric', 'min:0'],
            'taxable_amt' => ['nullable', 'numeric', 'min:0'],
            'party_id' => ['nullable', 'integer', 'exists:party,pid'],
            'prhid' => ['nullable', 'integer', 'exists:party,pid'],
            'gst' => ['nullable', 'numeric', 'min:0'],
            'cgst' => ['nullable', 'numeric', 'min:0'],
            'sgst' => ['nullable', 'numeric', 'min:0'],
            'igst' => ['nullable', 'numeric', 'min:0'],
            'payby' => ['nullable', 'integer', 'exists:pay_by,pbid'],
            'refno' => ['nullable', 'string', 'max:100'],
        ]);

        if (
            (! array_key_exists('party_id', $validated) || $validated['party_id'] === null || $validated['party_id'] === '')
            && isset($validated['prhid'])
        ) {
            $validated['party_id'] = (int) $validated['prhid'];
        }
        unset($validated['prhid']);

        $purchase->update($validated);

        return response()->json([
            'message' => 'Purchase updated successfully',
            'data' => $purchase->fresh(['party', 'payBy', 'item']),
        ], 200);
    }

    /**
     * Fetch all purchases (purchase-orders). Query: search=
     */
    public function index(Request $request): JsonResponse
    {
        $query = Purchase::with(['party', 'payBy'])->where(function ($q) {
            $q->whereNull('isdel')->orWhere('isdel', '!=', 1);
        });

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('p_inv_no', 'like', "%{$search}%")
                    ->orWhereHas('party', fn ($p) => $p->where('partyname', 'like', "%{$search}%"));
            });
        }

        $purchases = $query->orderByDesc('dt')->get();

        $data = $purchases->map(fn ($p) => [
            'id' => $p->prid,
            'date' => $p->dt?->format('Y-m-d'),
            'vendor' => $p->party?->partyname,
            'amount' => (float) $p->payment,
            'status' => 'completed',
            'taxable_amt' => (float) $p->taxable_amt,
            'items' => $p->item?->item_name,
            'item_id' => $p->item,
            'payby' => $p->payBy?->pbid,
            'refno' => $p->refno,
            // 'igst' => (float) $p->igst,
            // 'cgst' => (float) $p->cgst,
            // 'sgst' => (float) $p->sgst,
            // 'state' => $p->state,
            'gst' => (float) $p->gst,
            'p_inv_no' => $p->p_inv_no,

        ]);

        return response()->json(['data' => $data], 200);
    }

    public function delpurchase(Request $request, ?int $prid = null): JsonResponse
    {
        if ($prid !== null) {
            $request->merge(['prid' => $prid]);
        }

        $validated = $request->validate([
            'prid' => ['required', 'integer', 'exists:purchase,prid'],
        ]);

        $updated = Purchase::query()
            ->where('prid', $validated['prid'])
            ->update(['isdel' => 1]);

        if ($updated === 0) {
            return response()->json(['message' => 'Purchase not found'], 404);
        }

        return response()->json(['message' => 'Purchase deleted successfully'], 200);
    }

    public function gstratereport(Request $request): JsonResponse
    {
        //in params we recives from and to date
        $fromDate = $request->query('from');
        $toDate = $request->query('to');
        //than fetch the total amount of igst, cgst, sgst from purchase table according to the from and to date
        $totalIgst = Purchase::whereBetween('dt', [$fromDate, $toDate])->sum('igst');
        $totalCgst = Purchase::whereBetween('dt', [$fromDate, $toDate])->sum('cgst');
        $totalSgst = Purchase::whereBetween('dt', [$fromDate, $toDate])->sum('sgst');

        //fetch the igst, cgst, sgst from expenses table according to the from and to date
        $totalIgst2 = Expense::whereBetween('dt', [$fromDate, $toDate])->sum('igst');
        $totalCgst2 = Expense::whereBetween('dt', [$fromDate, $toDate])->sum('cgst');
        $totalSgst2 = Expense::whereBetween('dt', [$fromDate, $toDate])->sum('sgst');


        //sum both the total igst, cgst, sgst
        $totalIgst = $totalIgst + $totalIgst2;
        $totalCgst = $totalCgst + $totalCgst2;
        $totalSgst = $totalSgst + $totalSgst2;

        //fetch the igst, cgst, sgst from invoice table according to the from and to date
        $totalIgst3 = Invoice::whereBetween('dt', [$fromDate, $toDate])->sum('igst');
        $totalCgst3 = Invoice::whereBetween('dt', [$fromDate, $toDate])->sum('cgst');
        $totalSgst3 = Invoice::whereBetween('dt', [$fromDate, $toDate])->sum('sgst');

        return response()->json(['data' => [
            'igstexp' => $totalIgst,
            'cgstexp' => $totalCgst,
            'sgstexp' => $totalSgst,
            'igstsales' => $totalIgst3,
            'cgstsales' => $totalCgst3,
            'sgstsales' => $totalSgst3,
        ]], 200);
    }

    /**
     * Expense Report: purchases + expenses combined, with date filter.
     * Query: from=Y-m-d, to=Y-m-d (both optional)
     *
     * type: 'purchase' | 'expense'
     */
    public function expensereport(Request $request): JsonResponse
    {
        $from = $request->query('from');
        $to   = $request->query('to');

        $notDeleted = fn ($q) => $q->where(function ($q2) {
            $q2->whereNull('isdel')->orWhere('isdel', '!=', 1);
        });

        // ---- Purchases -------------------------------------------------------
        $purchaseQuery = Purchase::with(['party', 'item', 'payBy'])
            ->tap($notDeleted);

        if ($from && $to) {
            $purchaseQuery->whereBetween('dt', [$from, $to]);
        } elseif ($from) {
            $purchaseQuery->whereDate('dt', '>=', $from);
        } elseif ($to) {
            $purchaseQuery->whereDate('dt', '<=', $to);
        }

        $purchases = $purchaseQuery->orderByDesc('dt')->get()->map(fn ($p) => [
            'type'        => 'purchase',
            'id'          => $p->prid,
            'date'        => $p->dt?->format('Y-m-d'),
            'inv_no'      => $p->p_inv_no,
            'party_name'  => $p->party?->partyname ?? '-',
            'item_name'   => $p->item?->name ?? '-',
            'description' => null,
            'payment'     => (float) $p->payment,
            'taxable_amt' => (float) $p->taxable_amt,
            'gst'         => (float) $p->gst,
            'cgst'        => (float) $p->cgst,
            'sgst'        => (float) $p->sgst,
            'igst'        => (float) $p->igst,
            'payby'       => $p->payBy?->name ?? ($p->payby == 1 ? 'Bank' : 'Cash'),
            'refno'       => $p->refno,
            'state'       => $p->state,
        ]);

        // ---- Expenses --------------------------------------------------------
        $expenseQuery = Expense::with(['expensesHead', 'partyRelation'])
            ->tap($notDeleted);

        if ($from && $to) {
            $expenseQuery->whereBetween('dt', [$from, $to]);
        } elseif ($from) {
            $expenseQuery->whereDate('dt', '>=', $from);
        } elseif ($to) {
            $expenseQuery->whereDate('dt', '<=', $to);
        }

        $expenses = $expenseQuery->orderByDesc('dt')->get()->map(fn ($e) => [
            'type'        => 'expense',
            'id'          => $e->exid,
            'date'        => $e->dt?->format('Y-m-d'),
            'inv_no'      => (string) ($e->receipt_no ?? '-'),
            'party_name'  => $e->partyRelation?->partyname ?? '-',
            'item_name'   => null,
            'description' => $e->description ?? ($e->expensesHead?->name ?? '-'),
            'payment'     => (float) $e->payment,
            'taxable_amt' => (float) $e->taxable_amt,
            'gst'         => 0.0,
            'cgst'        => (float) $e->cgst,
            'sgst'        => (float) $e->sgst,
            'igst'        => (float) $e->igst,
            'payby'       => $e->payby == 1 ? 'Bank' : 'Cash',
            'refno'       => $e->refno,
            'state'       => null,
        ]);

        // ---- Merge & sort ----------------------------------------------------
        $all = $purchases->concat($expenses)->sortByDesc('date')->values();

        $summary = [
            'total_payment'     => round($all->sum('payment'), 2),
            'total_taxable_amt' => round($all->sum('taxable_amt'), 2),
            'total_cgst'        => round($all->sum('cgst'), 2),
            'total_sgst'        => round($all->sum('sgst'), 2),
            'total_igst'        => round($all->sum('igst'), 2),
            'total_gst'         => round($all->sum('gst'), 2),
        ];

        return response()->json([
            'from'    => $from,
            'to'      => $to,
            'summary' => $summary,
            'data'    => $all,
        ], 200);
    }
}
