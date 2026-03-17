<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Party;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class InvoiceController extends Controller
{
    /**
     * Create a new invoice.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'inv_no' => ['nullable', 'string', 'max:100'],
            'dt' => ['nullable', 'date'],
            'state' => ['nullable', 'string', 'max:100'],
            'pid' => ['required', 'integer', 'exists:party,pid'],
            'gst' => ['nullable', 'numeric', 'min:0'],
            'payment' => ['nullable', 'numeric', 'min:0'],
            'cgst' => ['nullable', 'numeric', 'min:0'],
            'sgst' => ['nullable', 'numeric', 'min:0'],
            'igst' => ['nullable', 'numeric', 'min:0'],
            'paytype' => ['required', 'integer', 'in:0,1'],
            'paynow' => ['nullable', 'numeric', 'min:0'],
            'payby' => ['nullable', 'integer', 'exists:pay_by,pbid'],
            'refno' => ['nullable', 'string', 'max:100'],
            'paylater' => ['nullable', 'numeric', 'min:0'],
            'balance' => ['nullable', 'numeric'],
        ]);

        $invoice = Invoice::create($validated);

        return response()->json([
            'message' => 'Invoice created successfully',
            'data' => $invoice,
        ], 201);
    }

    /**
     * Invoices list. Query: search=, status= (paid|pending|all).
     * Response: data: [ { id, date, customer, amount, gst, status } ]
     */
    public function index(Request $request): JsonResponse
    {
        $query = Invoice::with('party');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('inv_no', 'like', "%{$search}%")
                    ->orWhereHas('party', fn ($p) => $p->where('partyname', 'like', "%{$search}%"));
            });
        }

        $invoices = $query->orderByDesc('dt')->get();

        $data = $invoices->map(function ($inv) {
            $status = ($inv->balance && (float) $inv->balance > 0) ? 'pending' : 'paid';
            return [
                'id' => $inv->invid,
                'date' => $inv->dt?->format('Y-m-d'),
                'customer' => $inv->party?->partyname,
                'amount' => (float) $inv->payment,
                'gst' => (float) $inv->gst,
                'status' => $status,
            ];
        });

        $statusFilter = $request->query('status');
        if (in_array($statusFilter, ['paid', 'pending'], true)) {
            $data = $data->filter(fn ($d) => $d['status'] === $statusFilter)->values();
        }

        return response()->json(['data' => $data], 200);
    }

    /**
     * Sync invoices from external API. Query: from, to (dates in Y-m-d).
     */
    public function sync(Request $request): JsonResponse
    {
        $from_date = $request->query('from');
        $to_date = $request->query('to');

        if (! $from_date || ! $to_date) {
            return response()->json(['message' => 'from and to query params are required'], 422);
        }

        $response = Http::post('https://superplayerauction.com/dataapi/msc/getamtlist?from=' . $from_date . '&to=' . $to_date);

        if (! $response->successful()) {
            return response()->json(['message' => 'External API request failed', 'status' => $response->status()], 502);
        }

        $body = $response->json();
        $data = is_array($body) && isset($body['data']) ? $body['data'] : (is_array($body) ? $body : []);

        $created = 0;
        foreach ($data as $record) {
            $cid = $record['cid'] ?? $record['pid'] ?? $record['party_id'] ?? $record['customer_id'] ?? null;
            if ($cid === null) {
                continue;
            }

            $party = Party::where('cid', (string) $cid)->first();
            if (! $party) {
                $party = Party::create([
                    'cid' => (string) $cid,
                    'partyname' => $record['customer_name'] ?? $record['partyname'] ?? 'Synced-' . $cid,
                ]);
            }

            Invoice::create([
                'pid' => $party->pid,
                'inv_no' => $record['inv_no'] ?? null,
                'dt' => $record['dt'] ?? now()->format('Y-m-d'),
                'state' => $record['state'] ?? null,
                'gst' => $record['gst'] ?? 0,
                'payment' => $record['payment'] ?? 0,
                'cgst' => $record['cgst'] ?? 0,
                'sgst' => $record['sgst'] ?? 0,
                'igst' => $record['igst'] ?? 0,
                'paytype' => $record['paytype'] ?? 0,
                'paynow' => $record['paynow'] ?? 0,
                'payby' => $record['payby'] ?? null,
                'refno' => $record['refno'] ?? null,
                'paylater' => $record['paylater'] ?? 0,
                'balance' => $record['balance'] ?? 0,
            ]);
            $created++;
        }

        return response()->json(['message' => 'Invoices synced successfully', 'created' => $created], 200);
    }
}
