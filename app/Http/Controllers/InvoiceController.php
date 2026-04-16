<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Item;
use App\Models\Party;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
class InvoiceController extends Controller
{
    /**
     * Map incoming item payload fields → invoice_item table columns.
     */
    private function mapItem(array $item, int $invId): array
    {
        return [
            'inv_id'       => $invId,
            'item_id'      => $item['item_id']      ?? null,
            'hsnocde'      => $item['hsn_code']      ?? $item['hsnocde'] ?? null,
            'description'  => $item['description']  ?? null,
            'rate'         => $item['price']         ?? $item['rate']    ?? 0,
            'qty'          => $item['qty']           ?? 0,
            'payment'      => $item['amount']        ?? $item['payment'] ?? 0,
            'with_without' => $item['with_without']  ?? 0,
            'gst'          => $item['tax_pct']       ?? $item['gst']     ?? 0,
            'gst_amt'      => $item['tax_amt']       ?? $item['gst_amt'] ?? 0,
        ];
    }

    /**
     * Build line-item rows from getamtlist record (inv_item, info, or default).
     *
     * - inv_item: JSON array, array, plain string (e.g. "Registration payment"), or null
     * - info: e.g. "2000+2000+1000" → one invoice_item per numeric part
     * - if nothing else: one line with full invoice amount
     *
     * @return list<array<string, mixed>>
     */
    private function collectSyncInvoiceItemLines(array $record, float $invoiceAmt): array
    {
        $raw = $record['inv_item'] ?? $record['inv_items'] ?? null;

        if (is_array($raw)) {
            return array_is_list($raw) ? $raw : [$raw];
        }

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return array_is_list($decoded) ? $decoded : [$decoded];
            }

            // Plain text label (API often sends "Registration payment" not JSON)
            return [[
                'description' => trim($raw),
                'payment'     => $invoiceAmt,
                'qty'         => 1,
                'rate'        => $invoiceAmt,
            ]];
        }

        // inv_item null / empty → split info "2000+2000+1000"
        $info = $record['info'] ?? null;
        if (is_string($info) && $info !== '' && str_contains($info, '+')) {
            $parts = array_map('trim', explode('+', $info));
            $nums = [];
            foreach ($parts as $p) {
                if ($p !== '' && is_numeric($p)) {
                    $nums[] = (float) $p;
                }
            }
            if ($nums !== []) {
                $lines = [];
                foreach ($nums as $i => $partAmt) {
                    $lines[] = [
                        'description' => 'Part '.($i + 1),
                        'payment'     => $partAmt,
                        'qty'         => 1,
                        'rate'        => $partAmt,
                    ];
                }

                return $lines;
            }
        }

        // Default: single line = full invoice total
        return [[
            'description' => 'Invoice amount',
            'payment'     => $invoiceAmt,
            'qty'         => 1,
            'rate'        => $invoiceAmt,
        ]];
    }

    /**
     * Map one line from superauction → invoice_item row (sync).
     */
    private function mapSyncInvItemRow(array $line, int $invId, float $invoiceAmtFallback, int $lineCountInBatch = 1): array
    {
        $pay = (float) ($line['amount'] ?? $line['payment'] ?? $line['amt'] ?? $line['total'] ?? 0);
        if ($pay <= 0 && $invoiceAmtFallback > 0 && $lineCountInBatch === 1) {
            $pay = $invoiceAmtFallback;
        }

        $qty = (float) ($line['qty'] ?? $line['quantity'] ?? 0);
        if ($qty <= 0) {
            $qty = 1;
        }

        $rate = (float) ($line['price'] ?? $line['rate'] ?? 0);
        if ($rate <= 0 && $pay > 0) {
            $rate = $qty > 0 ? $pay / $qty : $pay;
        }

        $row = [
            'inv_id'       => $invId,
            'item_id'      => isset($line['item_id']) ? (int) $line['item_id'] : null,
            'hsnocde'      => $line['hsn_code'] ?? $line['hsnocde'] ?? $line['hsncode'] ?? null,
            'description'  => $line['description'] ?? (isset($line['item']) ? (string) $line['item'] : null),
            'rate'         => $rate,
            'qty'          => $qty,
            'payment'      => $pay > 0 ? $pay : ($rate * $qty),
            'with_without' => (int) ($line['with_without'] ?? 0),
            'gst'          => (float) ($line['tax_pct'] ?? $line['gst'] ?? $line['gst_pct'] ?? 0),
            'gst_amt'      => (float) ($line['tax_amt'] ?? $line['gst_amt'] ?? 0),
        ];

        $iid = $row['item_id'];
        if ($iid === null || ! Item::where('item_id', $iid)->exists()) {
            $fallback = Item::query()->orderBy('item_id')->value('item_id');
            if ($fallback !== null) {
                $row['item_id'] = (int) $fallback;
            }
        }

        return $row;
    }

    /**
     * Create a new invoice (with optional items[]).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'inv_no'      => ['nullable', 'string', 'max:100'],
            'dt'          => ['nullable', 'date'],
            'state'       => ['nullable', 'string', 'max:100'],
            'addr'        => ['nullable', 'string'],
            'pid'         => ['required', 'integer', 'exists:party,pid'],
            'gst'         => ['nullable', 'numeric', 'min:0'],
            'payment'     => ['nullable', 'numeric', 'min:0'],
            'taxable_amt' => ['nullable', 'numeric', 'min:0'],
            'cgst'        => ['nullable', 'numeric', 'min:0'],
            'sgst'        => ['nullable', 'numeric', 'min:0'],
            'igst'        => ['nullable', 'numeric', 'min:0'],
            'paytype'     => ['required', 'integer', 'in:0,1'],
            'paynow'      => ['nullable', 'numeric', 'min:0'],
            'payby'       => ['nullable', 'integer', 'exists:pay_by,pbid'],
            'refno'       => ['nullable', 'string', 'max:100'],
            'paylater'    => ['nullable', 'numeric', 'min:0'],
            'balance'     => ['nullable', 'numeric'],
            'items'       => ['nullable', 'array'],
            'items.*'     => ['array'],
        ]);

        $invoice = Invoice::create($validated);

        // Save items if provided
        if (! empty($validated['items'])) {
            $rows = array_map(fn ($item) => $this->mapItem($item, $invoice->invid), $validated['items']);
            InvoiceItem::insert($rows);
        }

        return response()->json([
            'message' => 'Invoice created successfully',
            'data'    => $invoice->load('invoiceItems'),
        ], 201);
    }

    /**
     * Single invoice detail by ID.
     */
    public function show(int $id): JsonResponse
    {
        $inv = Invoice::with(['party', 'invoiceItems'])
            ->where('invid', $id)
            ->where(fn ($q) => $q->whereNull('isdel')->orWhere('isdel', '!=', 1))
            ->first();

        if (! $inv) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        return response()->json([
            'data' => [
                'id'          => $inv->invid,
                'inv_no'      => $inv->inv_no,
                'date'        => $inv->dt?->format('Y-m-d'),
                'customer'    => $inv->party?->partyname,
                'pid'         => $inv->pid,
                'addr'        => $inv->addr,
                'state'       => $inv->state,
                'amount'      => (float) $inv->payment,
                'taxable_amt' => (float) $inv->taxable_amt,
                'gst'         => (float) $inv->gst,
                'cgst'        => (float) $inv->cgst,
                'sgst'        => (float) $inv->sgst,
                'igst'        => (float) $inv->igst,
                'paytype'     => $inv->paytype,
                'paynow'      => (float) $inv->paynow,
                'payby'       => $inv->payby,
                'refno'       => $inv->refno,
                'paylater'    => (float) $inv->paylater,
                'balance'     => (float) $inv->balance,
                'status'      => ($inv->balance && (float) $inv->balance > 0) ? 'pending' : 'paid',
                'items'       => $inv->invoiceItems,
            ],
        ], 200);
    }

    /**
     * Update an existing invoice by ID.
     * PUT /api/invoices/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $invoice = Invoice::where('invid', $id)
            ->where(fn ($q) => $q->whereNull('isdel')->orWhere('isdel', '!=', 1))
            ->first();

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        $validated = $request->validate([
            'inv_no'      => ['nullable', 'string', 'max:100'],
            'dt'          => ['nullable', 'date'],
            'state'       => ['nullable', 'string', 'max:100'],
            'addr'        => ['nullable', 'string'],
            'pid'         => ['nullable', 'integer', 'exists:party,pid'],
            'gst'         => ['nullable', 'numeric', 'min:0'],
            'payment'     => ['nullable', 'numeric', 'min:0'],
            'taxable_amt' => ['nullable', 'numeric', 'min:0'],
            'cgst'        => ['nullable', 'numeric', 'min:0'],
            'sgst'        => ['nullable', 'numeric', 'min:0'],
            'igst'        => ['nullable', 'numeric', 'min:0'],
            'paytype'     => ['nullable', 'integer', 'in:0,1'],
            'paynow'      => ['nullable', 'numeric', 'min:0'],
            'payby'       => ['nullable', 'integer', 'exists:pay_by,pbid'],
            'refno'       => ['nullable', 'string', 'max:100'],
            'paylater'    => ['nullable', 'numeric', 'min:0'],
            'balance'     => ['nullable', 'numeric'],
            'items'       => ['nullable', 'array'],
            'items.*'     => ['array'],
        ]);

        $invoice->update($validated);

        // Replace items if provided
        if (array_key_exists('items', $validated)) {
            InvoiceItem::where('inv_id', $invoice->invid)->delete();
            if (! empty($validated['items'])) {
                $rows = array_map(fn ($item) => $this->mapItem($item, $invoice->invid), $validated['items']);
                InvoiceItem::insert($rows);
            }
        }

        return response()->json([
            'message' => 'Invoice updated successfully',
            'data'    => $invoice->fresh(['party', 'invoiceItems']),
        ], 200);
    }

    /**
     * Invoices list. Query: from=, to= (Y-m-d), search=, status= (paid|pending|all).
     * Response: data: [ { id, date, customer, amount, gst, status } ]
     */
    public function index(Request $request): JsonResponse
    {
        $query = Invoice::with('party')->where(function ($q) {
            $q->whereNull('isdel')->orWhere('isdel', '!=', 1);
        });

        if ($from = $request->query('from')) {
            $to = $request->query('to', $from);
            $query->whereBetween('dt', [$from, $to]);
        }

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
                'inv_no' => $inv->inv_no,
                'date' => $inv->dt?->format('Y-m-d'),
                'customer' => $inv->party?->partyname,
                'amount' => (float) $inv->payment,
                'gst' => (float) $inv->gst,
                'cgst' => (float) $inv->cgst,
                'sgst' => (float) $inv->sgst,
                'igst' => (float) $inv->igst,
                'paytype' => $inv->paytype,
                'paynow' => (float) $inv->paynow,
                'payby' => $inv->payby,
                'refno' => $inv->refno,
                'paylater' => (float) $inv->paylater,
                'balance' => (float) $inv->balance,
                'state' => $inv->state,
                'status' => $status,
            ];
        });

        $statusFilter = $request->query('status');
        if (in_array($statusFilter, ['paid', 'pending'], true)) {
            $data = $data->filter(fn ($d) => $d['status'] === $statusFilter)->values();
        }

        return response()->json(['data' => $data], 200);
    }


    public function delinvoice($id): JsonResponse
    {
        $invoice = Invoice::find($id);
        if (!$invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }
        //isme eek isdel column h jishko 1 krr dena hai
        $invoice->isdel = 1;
        $invoice->save();

        return response()->json(['message' => 'Invoice deleted successfully'], 200);
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
                'mobno' => $record['mob'] ?? null,
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
            $refNo = isset($record['amid']) ? trim((string) $record['amid']) : null;
            $refNo = $refNo !== '' ? $refNo : null;

            // Avoid creating duplicate invoice entries for same refno.
            // If an invoice was soft-deleted (isdel=1), allow re-sync for same refno.
            if ($refNo !== null && Invoice::where('refno', $refNo)->where(function ($q) {
                $q->whereNull('isdel')->orWhere('isdel', '!=', 1);
            })->exists()) {
                continue;
            }

            $invoiceAmt = (float) ($record['amt'] ?? 0);

            $invoice = Invoice::create([
                'pid' => $party->pid,
                'inv_no' => $record['invoice'] !== null && $record['invoice'] !== '' ? (string) $record['invoice'] : null,
                'dt' => $record['dt'] ?? now()->format('Y-m-d'),
                'state' => $record['state'] ?? null,
                'addr' => $record['addr'] ?? $record['city'] ?? null,
                'gst' => (float) ($record['gstext'] ?? 0),
                'payment' => $invoiceAmt,
                'cgst' => 0,
                'sgst' => 0,
                'igst' => 0,
                'paytype' => 0,
                'paynow' => $invoiceAmt,
                'payby' => (isset($record['payby']) && trim((string) $record['payby']) === 'Bank-CR') ? 1 : 0,
                'refno' => $refNo,
                'paylater' => 0,
                'balance' => 0,
            ]);

            $lines = $this->collectSyncInvoiceItemLines($record, $invoiceAmt);
            $n = count($lines);
            $rows = [];
            foreach ($lines as $line) {
                if (! is_array($line)) {
                    continue;
                }
                $mapped = $this->mapSyncInvItemRow($line, $invoice->invid, $invoiceAmt, $n);
                if ($mapped['item_id'] !== null) {
                    $rows[] = $mapped;
                }
            }
            if ($rows !== []) {
                InvoiceItem::insert($rows);
            }

            $invoicesCreated++;
        }

        return response()->json([
            'message' => 'Sync successful',
            'parties_created' => $partiesCreated,
            'invoices_created' => $invoicesCreated,
        ], 200);
    }
}
