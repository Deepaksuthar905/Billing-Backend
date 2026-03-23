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
            'gst' => ['nullable', 'numeric', 'min:0'],
            'cgst' => ['nullable', 'numeric', 'min:0'],
            'sgst' => ['nullable', 'numeric', 'min:0'],
            'igst' => ['nullable', 'numeric', 'min:0'],
            'payby' => ['nullable', 'integer', 'exists:pay_by,pbid'],
            'refno' => ['nullable', 'string', 'max:100'],
        ]);

        $purchase = Purchase::create($validated);

        return response()->json([
            'message' => 'Purchase created successfully',
            'data' => $purchase,
        ], 201);
    }

    /**
     * Fetch all purchases (purchase-orders). Query: search=
     */
    public function index(Request $request): JsonResponse
    {
        $query = Purchase::with(['party', 'payBy']);

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
            'igst' => (float) $p->igst,
            'cgst' => (float) $p->cgst,
            'sgst' => (float) $p->sgst,
            'state' => $p->state,
            'gst' => (float) $p->gst,
        ]);

        return response()->json(['data' => $data], 200);
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
}
