<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesController extends Controller
{
    /**
     * Sales list: id, date, customer, items, total, status.
     * Query: search=
     */
    public function index(Request $request): JsonResponse
    {
        $query = Invoice::with(['party', 'invoiceItems.item']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('inv_no', 'like', "%{$search}%")
                    ->orWhereHas('party', fn ($p) => $p->where('partyname', 'like', "%{$search}%"));
            });
        }

        $invoices = $query->orderByDesc('dt')->get();

        $data = $invoices->map(function ($inv) {
            $items = $inv->invoiceItems->map(fn ($ii) => [
                'item_id' => $ii->item_id,
                'description' => $ii->description,
                'rate' => (float) $ii->rate,
                'qty' => (float) $ii->qty,
                'amount' => (float) $ii->payment,
            ]);

            return [
                'id' => $inv->invid,
                'date' => $inv->dt?->format('Y-m-d'),
                'customer' => $inv->party?->partyname,
                'items' => $items,
                'total' => (float) $inv->payment,
                'status' => ($inv->balance && (float) $inv->balance > 0) ? 'pending' : 'paid',
            ];
        });

        return response()->json(['data' => $data], 200);
    }
}
