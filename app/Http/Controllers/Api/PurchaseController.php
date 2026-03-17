<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
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
            'prhid' => ['required', 'integer', 'exists:purchase_head,prhid'],
            'p_inv_no' => ['nullable', 'string', 'max:100'],
            'dt' => ['nullable', 'date'],
            'state' => ['nullable', 'string', 'max:100'],
            'payment' => ['nullable', 'numeric', 'min:0'],
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
        $query = Purchase::with(['purchaseHead', 'party', 'payBy']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('p_inv_no', 'like', "%{$search}%")
                    ->orWhereHas('purchaseHead', fn ($h) => $h->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('party', fn ($p) => $p->where('partyname', 'like', "%{$search}%"));
            });
        }

        $purchases = $query->orderByDesc('dt')->get();

        $data = $purchases->map(fn ($p) => [
            'id' => $p->prid,
            'date' => $p->dt?->format('Y-m-d'),
            'vendor' => $p->purchaseHead?->name ?? $p->party?->partyname,
            'amount' => (float) $p->payment,
            'status' => 'completed',
        ]);

        return response()->json(['data' => $data], 200);
    }
}
