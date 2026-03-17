<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Party;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
        echo $response->body();

        $body = $response->json();
        // getamtlist returns data.list
        $data = $body['data']['list'] ?? $body['data'] ?? (is_array($body) ? $body : []);

        $partiesCreated = 0;
        $invoicesCreated = 0;

        foreach ($data as $record) {
            $cid = $record['cid'] ?? null;
            $cidStr = $cid !== null && $cid !== '' ? (string) $cid : null;

            // Party: cid se find, nahi mila to create with full mapping
            $party = $cidStr !== null ? Party::where('cid', $cidStr)->first() : null;

            $partyAttrs = [
                'partyname' => $record['billing_name'] ?? '',
                'mobno' => $record['billing_name'] ?? '',
                'cid' => $cidStr ?? (string) ($record['cid'] ?? ''),
                'billing_name' => $record['billing_name'] ?? null,
                'gst_no' => $record['gstno'] ?? null,
                'city' => $record['city'] ?? null,
                'state' => $record['state'] ?? null,
                'gst_reg' => isset($record['gst_regular']) ? (int) (bool) $record['gst_regular'] : 0,
                'same_state' => isset($record['state']) && trim((string) $record['state']) === 'Rajasthan' ? 1 : 0,
            ];

            if ($party) {
                $party->update($partyAttrs);
            } else {
                $partyAttrs['partyname'] = $partyAttrs['partyname'] ?: ('Synced-' . ($cidStr ?? $record['aid'] ?? ''));
                $party = Party::create($partyAttrs);
                $partiesCreated++;
            }

            // Invoice: getamtlist keys -> amt, dt, invoice, payby, state
            Invoice::create([
                'pid' => $party->pid,
                'inv_no' => $record['invoice'] ?? $record['amid'] ?? null,
                'dt' => $record['dt'] ?? now()->format('Y-m-d'),
                'state' => $record['state'] ?? null,
                'gst' => (float) ($record['gstext'] ?? 0),
                'payment' => (float) ($record['amt'] ?? 0),
                'cgst' => 0,
                'sgst' => 0,
                'igst' => 0,
                'paytype' => 0,
                'paynow' => (float) ($record['amt'] ?? 0),
                'payby' => (isset($record['payby']) && trim((string) $record['payby']) === 'Bank-CR') ? 1 : 0,
                'refno' => $record['aid'] ?? null,
                'paylater' => 0,
                'balance' => 0,
            ]);
            $invoicesCreated++;
        }

        return response()->json([
            'message' => 'Sync successful',
            'parties_created' => $partiesCreated,
            'invoices_created' => $invoicesCreated,
        ], 200);
    }
}
