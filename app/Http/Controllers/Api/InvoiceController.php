<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Item;
use App\Models\Party;
use App\Models\PayBy;
use App\Models\PayIn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class InvoiceController extends Controller
{
    private function applyInvoiceNotDeletedScope(Builder $query): Builder
    {
        if (Schema::hasColumn('invoice', 'isdel')) {
            $query->where(function ($q) {
                $q->whereNull('isdel')->orWhere('isdel', '!=', 1);
            });
        }

        return $query;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectSyncInvoiceList(array $body): array
    {
        $list = $body['data']['list'] ?? $body['list'] ?? null;
        if (! is_array($list)) {
            return [];
        }

        return array_values(array_filter($list, fn ($row) => is_array($row)));
    }

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
     * From this date (invoice dt), sync stores amt as the payment total;
     * info is kept as description only. Older records keep info-splitting.
     */
    private const SYNC_AMT_ONLY_FROM = '2025-03-01';

    /**
     * Invoice dt on/before this date → 6% GST (3% CGST + 3% SGST).
     * From next day → 18% GST (9% + 9%).
     */
    private const SYNC_GST_6_PERCENT_UNTIL = '2025-12-31';

    /** Master item used for sync line items when no selling item matches. */
    private const SYNC_EASY_ITEM_NAME = 'Easy';

    /** @var int|null */
    private ?int $syncEasyItemId = null;

    /**
     * Cached selling items (sales=1) for sync matching.
     * Each entry: item_id, item_name, description, name_norm, desc_norm
     *
     * @var list<array{item_id: int, item_name: string, description: string, name_norm: string, desc_norm: string}>
     */
    private array $syncSellingItems = [];

    private bool $syncSellingItemsLoaded = false;

    /**
     * Load items.sales=1 once per sync run (in-memory "json" list for matching).
     */
    private function loadSyncSellingItems(): void
    {
        if ($this->syncSellingItemsLoaded) {
            return;
        }

        $this->syncSellingItemsLoaded = true;
        $this->syncSellingItems = [];

        if (! Schema::hasColumn('items', 'sales')) {
            return;
        }

        $rows = Item::query()
            ->where('sales', 1)
            ->orderBy('item_id')
            ->get(['item_id', 'item_name', 'description']);

        foreach ($rows as $row) {
            $name = trim((string) ($row->item_name ?? ''));
            $desc = trim((string) ($row->description ?? ''));
            $this->syncSellingItems[] = [
                'item_id' => (int) $row->item_id,
                'item_name' => $name,
                'description' => $desc,
                'name_norm' => $this->normalizeSyncMatchText($name),
                'desc_norm' => $this->normalizeSyncMatchText($desc),
            ];
        }
    }

    private function normalizeSyncMatchText(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9\s]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * Match incoming sync label (inv_item / description) to a selling item (sales=1).
     * Exact → contains → similar_text; fallback null (caller uses Easy).
     */
    private function matchSellingItemId(?string $label): ?int
    {
        $this->loadSyncSellingItems();

        $needle = $this->normalizeSyncMatchText((string) $label);
        if ($needle === '' || $this->syncSellingItems === []) {
            return null;
        }

        // 1) Exact name / description
        foreach ($this->syncSellingItems as $item) {
            if ($item['name_norm'] !== '' && $item['name_norm'] === $needle) {
                return $item['item_id'];
            }
            if ($item['desc_norm'] !== '' && $item['desc_norm'] === $needle) {
                return $item['item_id'];
            }
        }

        // 2) One contains the other
        foreach ($this->syncSellingItems as $item) {
            foreach ([$item['name_norm'], $item['desc_norm']] as $hay) {
                if ($hay === '') {
                    continue;
                }
                if (str_contains($hay, $needle) || str_contains($needle, $hay)) {
                    return $item['item_id'];
                }
            }
        }

        // 3) Best similar_text score (threshold 55%)
        $bestId = null;
        $bestScore = 0.0;
        foreach ($this->syncSellingItems as $item) {
            foreach ([$item['name_norm'], $item['desc_norm']] as $hay) {
                if ($hay === '') {
                    continue;
                }
                similar_text($needle, $hay, $pct);
                if ($pct > $bestScore) {
                    $bestScore = $pct;
                    $bestId = $item['item_id'];
                }
            }
        }

        return $bestScore >= 55.0 ? $bestId : null;
    }

    /**
     * Financial year for a date (Apr–Mar): [from, to] as Y-m-d.
     *
     * @return array{0: string, 1: string}
     */
    private function financialYearRangeForDate(string $date): array
    {
        $dt = substr(trim($date), 0, 10);
        $ts = strtotime($dt) ?: time();
        $y = (int) date('Y', $ts);
        $m = (int) date('n', $ts);

        if ($m >= 4) {
            return [sprintf('%04d-04-01', $y), sprintf('%04d-03-31', $y + 1)];
        }

        return [sprintf('%04d-04-01', $y - 1), sprintf('%04d-03-31', $y)];
    }

    /**
     * Find or create the "Easy" master item for sync invoice lines (fallback).
     */
    private function resolveEasySyncItemId(): ?int
    {
        if ($this->syncEasyItemId !== null) {
            return $this->syncEasyItemId;
        }

        $existing = Item::query()
            ->whereRaw('LOWER(TRIM(item_name)) = ?', [strtolower(self::SYNC_EASY_ITEM_NAME)])
            ->value('item_id');

        if ($existing !== null) {
            return $this->syncEasyItemId = (int) $existing;
        }

        $attrs = [
            'item_name' => self::SYNC_EASY_ITEM_NAME,
            'hsncode' => null,
            'description' => 'Auto-created for invoice sync',
            'rate' => 0,
            'with_without' => 1,
            'gst' => 18,
            'gst_amt' => 0,
        ];
        if (Schema::hasColumn('items', 'sales')) {
            $attrs['sales'] = 0;
        }

        $item = Item::create($attrs);

        return $this->syncEasyItemId = (int) $item->item_id;
    }

    /**
     * Build line-item rows from getamtlist record (inv_item, info, or default).
     *
     * - inv_item: JSON array, array, plain string (e.g. "Registration payment"), or null
     * - info (only before SYNC_AMT_ONLY_FROM): e.g. "2000+2000+1000" → one invoice_item per numeric part
     * - dt >= SYNC_AMT_ONLY_FROM: single line, payment = amt, info as description
     * - if nothing else: one line with full invoice amount
     *
     * @return list<array<string, mixed>>
     */
    private function collectSyncInvoiceItemLines(array $record, float $invoiceAmt, bool $amtOnly = false): array
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

        $info = $record['info'] ?? null;

        // dt >= SYNC_AMT_ONLY_FROM: amt is the total; info is description only.
        if ($amtOnly) {
            return [[
                'description' => is_string($info) && trim($info) !== '' ? trim($info) : 'Invoice amount',
                'payment'     => $invoiceAmt,
                'qty'         => 1,
                'rate'        => $invoiceAmt,
            ]];
        }

        // inv_item null / empty → split info "2000+2000+1000" (legacy, pre SYNC_AMT_ONLY_FROM)
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
     * Line description for sync: keep API label + amid tag so same text/amount
     * from different external rows stay unique (4 entries → 4 Easy lines).
     */
    private function syncItemDescription(array $record, array $line, ?string $refNo): string
    {
        $base = trim((string) ($line['description'] ?? $line['item'] ?? ''));
        if ($base === '') {
            $invItem = $record['inv_item'] ?? null;
            if (is_string($invItem) && trim($invItem) !== '') {
                $base = trim($invItem);
            } elseif (is_string($record['info'] ?? null) && trim((string) $record['info']) !== '') {
                $base = trim((string) $record['info']);
            } else {
                $base = 'Invoice amount';
            }
        }

        if ($refNo !== null && $refNo !== '') {
            $tag = '[#'.$refNo.']';
            if (! str_contains($base, $tag)) {
                $base = trim($base.' '.$tag);
            }
        }

        return $base;
    }

    /**
     * One invoice per party + inv_no within FY. Prefer that over amid so multiple
     * external amids for invoice "530" merge into one bill with multiple Easy lines.
     *
     * @return array{0: ?\App\Models\Invoice, 1: bool} [invoice, createdNew]
     */
    private function resolveSyncInvoice(
        Party $party,
        ?string $invNo,
        string $invDt,
        ?string $refNo,
        array $createAttrs
    ): array {
        $existing = null;

        if ($invNo !== null && $invNo !== '') {
            [$fyFrom, $fyTo] = $this->financialYearRangeForDate($invDt);
            $group = $this->applyInvoiceNotDeletedScope(
                Invoice::where('pid', $party->pid)
                    ->where('inv_no', $invNo)
                    ->whereBetween('dt', [$fyFrom, $fyTo])
            )->orderBy('invid')->get();

            if ($group->isNotEmpty()) {
                $existing = $group->first();
                // Soft-delete sibling invoices with same inv_no in FY (old amid-split rows).
                foreach ($group->skip(1) as $sibling) {
                    InvoiceItem::where('inv_id', $sibling->invid)->delete();
                    $sibling->isdel = 1;
                    $sibling->save();
                }
            }
        }

        if (! $existing && $refNo !== null) {
            $existing = $this->applyInvoiceNotDeletedScope(Invoice::where('refno', $refNo))->first();
        }

        if ($existing) {
            return [$existing, false];
        }

        $invoice = Invoice::create($createAttrs);

        return [$invoice, true];
    }

    /**
     * Map one line from superauction → invoice_item row (sync).
     * item_id: best match from items.sales=1, else Easy fallback.
     */
    private function mapSyncInvItemRow(array $line, int $invId, float $invoiceAmtFallback, int $lineCountInBatch = 1, ?string $invoiceDt = null, bool $gstIncluded = true): array
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

        $payment = $pay > 0 ? $pay : ($rate * $qty);
        if ($rate <= 0 && $payment > 0) {
            $rate = $qty > 0 ? $payment / $qty : $payment;
        }

        $gstRate = $this->syncGstRateForDate($invoiceDt);
        $gstPct = round($gstRate * 100, 2);
        $gstAmt = 0.0;
        if ($payment > 0 && $gstRate > 0) {
            if ($gstIncluded) {
                $taxable = $payment * 100 / (100 + $gstPct);
                $gstAmt = $payment - $taxable;
            } else {
                $gstAmt = $payment * $gstRate;
            }
        }

        $matchLabel = trim((string) (
            $line['description']
            ?? $line['item']
            ?? $line['item_name']
            ?? $line['name']
            ?? ''
        ));
        $matchedId = $this->matchSellingItemId($matchLabel !== '' ? $matchLabel : null);
        $itemId = $matchedId ?? $this->resolveEasySyncItemId();

        $hsn = $line['hsn_code'] ?? $line['hsnocde'] ?? $line['hsncode'] ?? null;
        if (($hsn === null || $hsn === '') && $matchedId !== null) {
            $hsn = Item::where('item_id', $matchedId)->value('hsncode');
        }

        return [
            'inv_id' => $invId,
            'item_id' => $itemId,
            'hsnocde' => $hsn,
            'description' => $line['description'] ?? (isset($line['item']) ? (string) $line['item'] : null),
            'rate' => round($rate, 2),
            'qty' => $qty,
            'payment' => round($payment, 2),
            'with_without' => (int) ($line['with_without'] ?? ($gstIncluded ? 1 : 0)),
            'gst' => (float) ($line['tax_pct'] ?? $line['gst'] ?? $line['gst_pct'] ?? $gstPct),
            'gst_amt' => round((float) ($line['tax_amt'] ?? $line['gst_amt'] ?? $gstAmt), 2),
        ];
    }

    /**
     * GST rate for sync: <= 2025-12-31 → 6%; after that → 18%.
     */
    private function syncGstRateForDate(?string $invoiceDt): float
    {
        $dt = $invoiceDt !== null && $invoiceDt !== ''
            ? substr(trim($invoiceDt), 0, 10)
            : now()->format('Y-m-d');

        return $dt <= self::SYNC_GST_6_PERCENT_UNTIL ? 0.06 : 0.18;
    }

    /**
     * GST included in gross: taxable = gross × 100/(100+rate%).
     * Rajasthan → half CGST + half SGST; else full IGST.
     *
     * @return array{0: float, 1: float, 2: float, 3: float} taxable_amt, cgst, sgst, igst
     */
    private function splitInclusiveGst(float $gross, ?string $state, float $gstRate): array
    {
        if ($gross <= 0 || $gstRate <= 0) {
            return [0.0, 0.0, 0.0, 0.0];
        }

        $taxable = $gross * 100 / (100 + ($gstRate * 100));
        if (trim((string) ($state ?? '')) === 'Rajasthan') {
            $half = $taxable * ($gstRate / 2);

            return [$taxable, $half, $half, 0.0];
        }

        return [$taxable, 0.0, 0.0, $taxable * $gstRate];
    }

    /**
     * getamtlist state: "Out of India" / "Outside India" → treat as Rajasthan (CGST+SGST, same-state).
     */
    private function normalizeSyncState(?string $state): ?string
    {
        if ($state === null || trim($state) === '') {
            return $state;
        }

        $normalized = strtolower(trim($state));
        if (
            str_contains($normalized, 'out of india')
            || str_contains($normalized, 'outside india')
            || str_contains($normalized, 'out-of-india')
        ) {
            return 'Rajasthan';
        }

        return trim($state);
    }

    /**
     * getamtlist gstext (0 or 18 etc.): source already finalised amt.
     * Even when gstext=18 ("extra"), amt is the FINAL payable — never add GST on top.
     * We only split GST out of amt for books (inclusive).
     */
    private function isSyncGstIncluded(array $record): bool
    {
        return true;
    }

    /**
     * @return array{taxable: float, cgst: float, sgst: float, igst: float, gst: float, total: float}
     */
    private function computeSyncGstAmounts(float $baseAmt, ?string $state, bool $gstIncluded, ?string $invoiceDt = null): array
    {
        $zero = ['taxable' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'igst' => 0.0, 'gst' => 0.0, 'total' => 0.0];
        if ($baseAmt <= 0) {
            return $zero;
        }

        $gstRate = $this->syncGstRateForDate($invoiceDt);

        if ($gstIncluded) {
            [$taxable, $cgst, $sgst, $igst] = $this->splitInclusiveGst($baseAmt, $state, $gstRate);

            return [
                'taxable' => $taxable,
                'cgst' => $cgst,
                'sgst' => $sgst,
                'igst' => $igst,
                'gst' => $cgst + $sgst + $igst,
                'total' => $baseAmt,
            ];
        }

        $taxable = $baseAmt;
        if (trim((string) ($state ?? '')) === 'Rajasthan') {
            $half = $taxable * ($gstRate / 2);
            $cgst = $half;
            $sgst = $half;
            $igst = 0.0;
        } else {
            $cgst = 0.0;
            $sgst = 0.0;
            $igst = $taxable * $gstRate;
        }
        $gst = $cgst + $sgst + $igst;

        return [
            'taxable' => $taxable,
            'cgst' => $cgst,
            'sgst' => $sgst,
            'igst' => $igst,
            'gst' => $gst,
            'total' => $taxable + $gst,
        ];
    }

    /** @var array<string, int|null> */
    private array $syncPayByCache = [];

    /**
     * getamtlist payby: "Bank-CR" → Harsh Technology ledger; anything else → Cash.
     */
    private function resolveSyncPayById(array $record): ?int
    {
        if (! array_key_exists('harsh', $this->syncPayByCache)) {
            $this->syncPayByCache['harsh'] = PayBy::query()
                ->where(function ($q) {
                    $q->whereRaw('LOWER(TRIM(name)) LIKE ?', ['%harsh%technology%'])
                        ->orWhereRaw('LOWER(TRIM(name)) = ?', ['harsh technology']);
                })
                ->value('pbid');
            $this->syncPayByCache['cash'] = PayBy::query()
                ->whereRaw('LOWER(TRIM(name)) = ?', ['cash'])
                ->value('pbid');
        }

        $external = trim((string) ($record['payby'] ?? ''));

        if (strcasecmp($external, 'Bank-CR') === 0) {
            return $this->syncPayByCache['harsh'];
        }

        return $this->syncPayByCache['cash'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectSyncPayInList(array $body): array
    {
        $payin = $body['data']['payin'] ?? $body['payin'] ?? null;
        if (! is_array($payin)) {
            return [];
        }

        $list = $payin['list'] ?? [];

        return is_array($list) ? array_values($list) : [];
    }

    /**
     * payin.list → pay_in (direct field map only).
     *
     * @param  list<array<string, mixed>>  $records
     * @return array{created: int, skipped: int}
     */
    private function syncPayInRecords(array $records): array
    {
        $created = 0;
        $skipped = 0;

        foreach ($records as $record) {
            if (! is_array($record)) {
                $skipped++;
                continue;
            }

            if ((int) ($record['isdel'] ?? 0) === 1) {
                $skipped++;
                continue;
            }

            $pnid = isset($record['pnid']) ? (int) $record['pnid'] : 0;
            if ($pnid <= 0) {
                $skipped++;
                continue;
            }

            if (Schema::hasColumn('pay_in', 'ext_pnid') && PayIn::where('ext_pnid', $pnid)->exists()) {
                $skipped++;
                continue;
            }

            // Never use API invoice_id as local inv_id — it is their inv_no, not our invid.
            // Only link pay-in when the invoice already exists locally (amid → refno / [#amid] line).
            $invoice = null;
            $amid = isset($record['amid']) ? trim((string) $record['amid']) : '';
            if ($amid !== '') {
                $invoice = $this->applyInvoiceNotDeletedScope(Invoice::where('refno', $amid))
                    ->orderByDesc('invid')
                    ->first();
            }
            // Multiple amids can share one inv_no; line descriptions carry [#amid].
            if (! $invoice && $amid !== '') {
                $invIdFromItem = InvoiceItem::query()
                    ->where('description', 'like', '%[#'.$amid.']%')
                    ->orderByDesc('inv_id')
                    ->value('inv_id');
                if ($invIdFromItem) {
                    $invoice = $this->applyInvoiceNotDeletedScope(Invoice::where('invid', $invIdFromItem))->first();
                }
            }
            // Invoice not in our DB yet → skip this pay-in (do not store inv_no into pay_in.inv_id).
            if (! $invoice) {
                $skipped++;
                continue;
            }

            $invId = (int) $invoice->invid;

            $partyId = null;
            if (isset($record['cid']) && $record['cid'] !== '' && $record['cid'] !== null) {
                $partyId = Party::where('cid', (string) $record['cid'])->value('pid');
            }
            if ($partyId === null) {
                $partyId = $invoice->pid;
            }
            if ($partyId === null) {
                $skipped++;
                continue;
            }

            $amount = (float) ($record['amt'] ?? 0);
            if ($amount <= 0) {
                $skipped++;
                continue;
            }

            // Pay-in mapping rule:
            // - API payby = 0  → Harsh Technology
            // - anything else → Cash
            $apiPayby = isset($record['payby']) ? (int) $record['payby'] : null;
            if ($apiPayby === 0) {
                $payby = $this->syncPayByCache['harsh'] ?? $this->resolveSyncPayById(['payby' => 'Bank-CR']);
                if ($payby === null) {
                    $payby = $this->resolveSyncPayById(['payby' => '']); // fallback to Cash if harsh missing
                }
            } else {
                $payby = $this->resolveSyncPayById(['payby' => '']); // Cash
            }

            $ref = isset($record['ref']) ? trim((string) $record['ref']) : '';

            $attrs = [
                'party_id' => (int) $partyId,
                'inv_id' => $invId,
                'dt' => ! empty($record['dt']) ? (string) $record['dt'] : now()->format('Y-m-d'),
                'description' => isset($record['detail']) ? (string) $record['detail'] : null,
                'payby' => $payby,
                'amount' => $amount,
                'referal' => $ref !== '' ? $ref : null,
            ];
            if (Schema::hasColumn('pay_in', 'ext_pnid')) {
                $attrs['ext_pnid'] = $pnid;
            }

            try {
                PayIn::create($attrs);
                $created++;
            } catch (Throwable $e) {
                Log::warning('pay-in sync row skipped', ['pnid' => $pnid, 'error' => $e->getMessage()]);
                $skipped++;
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * getamtlist isinv=1 → due invoice (pending); nothing collected yet.
     */
    private function isSyncRecordDueInvoice(array $record): bool
    {
        if (! array_key_exists('isinv', $record)) {
            return false;
        }

        return (int) $record['isinv'] === 1;
    }

    /**
     * Set paynow / paylater / balance / paytype from gross and due flag.
     */
    private function applySyncPaymentStatus(Invoice $invoice, float $gross, bool $isDue): void
    {
        if ($gross <= 0) {
            return;
        }

        if ($isDue) {
            $invoice->paynow = 0;
            $invoice->paylater = $gross;
            $invoice->balance = $gross;
            $invoice->paytype = 1;
        } else {
            $invoice->paynow = $gross;
            $invoice->paylater = 0;
            $invoice->balance = 0;
            $invoice->paytype = 0;
        }
    }

    /**
     * Align invoice header with line items: gross = sum(invoice_item.payment) when present, else $fallbackAmt.
     * Recomputes taxable_amt, GST split, payment total from line items (or $fallbackAmt).
     *
     * @param  string|null  $stateForGst  Prefer current sync row state; falls back to $invoice->state.
     */
    private function applyInvoiceTotalsFromLineItems(Invoice $invoice, float $fallbackAmt, ?string $stateForGst = null, bool $isDue = false, bool $gstIncluded = true, ?string $invoiceDt = null): void
    {
        $sum = (float) InvoiceItem::where('inv_id', $invoice->invid)->sum('payment');
        $baseAmt = $sum > 0 ? $sum : $fallbackAmt;

        if ($baseAmt <= 0) {
            return;
        }

        $state = $stateForGst ?? $invoice->state;
        $dt = $invoiceDt
            ?? ($invoice->dt instanceof \Carbon\Carbon ? $invoice->dt->format('Y-m-d') : (string) ($invoice->getRawOriginal('dt') ?? ''));
        $amounts = $this->computeSyncGstAmounts($baseAmt, $state, $gstIncluded, $dt);

        $invoice->payment = $amounts['total'];
        $invoice->taxable_amt = $amounts['taxable'];
        $invoice->gst = $amounts['gst'];
        $invoice->cgst = $amounts['cgst'];
        $invoice->sgst = $amounts['sgst'];
        $invoice->igst = $amounts['igst'];
        $this->applySyncPaymentStatus($invoice, $amounts['total'], $isDue);
        $invoice->save();
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
                'gstno'        => $inv->gstno ?? null,
                'gst_no'       => $inv->gstno ?? null,
                'gst_regular'  => (int) ($inv->gst_regular ?? 0),
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

        $invoices = $query->withSum([
                'payIns as pay_in_total' => function ($q) {
                    if (Schema::hasColumn('pay_in', 'isdel')) {
                        $q->where(function ($q2) {
                            $q2->whereNull('isdel')->orWhere('isdel', '!=', 1);
                        });
                    }
                },
            ], 'amount')
            ->orderByDesc('dt')
            ->get();

        $data = $invoices->map(function ($inv) {
            $invoiceBalance = (float) $inv->balance;
            $payInTotal = (float) ($inv->pay_in_total ?? 0);

            if ($invoiceBalance > 0) {
                $pendingBalance = max(0, round($invoiceBalance - $payInTotal, 2));
                $status = $pendingBalance > 0 ? 'pending' : 'paid';
            } else {
                $pendingBalance = 0.0;
                $status = 'paid';
            }

            return [
                'id' => $inv->invid,
                'inv_no' => $inv->inv_no,
                'date' => $inv->dt?->format('Y-m-d'),
                'customer' => $inv->party?->partyname,
                'pid' => $inv->pid,
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
                'balance' => $pendingBalance,
                'state' => $inv->state,
                'status' => $status,
                'billing_name' => $inv->billing_name ?? $inv->party?->billing_name,
                'gstno' => $inv->gstno ?? null,
                'gst_no' => $inv->gstno ?? null,
                'gst_regular' => (int) ($inv->gst_regular ?? 0),
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

        // Soft-delete related pay-in rows so they don't stay as due / ledger entries
        $payInQuery = PayIn::where('inv_id', $id);
        if (Schema::hasColumn('pay_in', 'isdel')) {
            $payInQuery
                ->where(function ($q) {
                    $q->whereNull('isdel')->orWhere('isdel', '!=', 1);
                })
                ->update(['isdel' => 1]);
        } else {
            $payInQuery->delete();
        }

        return response()->json(['message' => 'Invoice deleted successfully'], 200);
    }

    /**
     * Return invoice table column names from the database.
     */
    public function columns(): JsonResponse
    {
        return response()->json([
            'table' => 'invoice',
            'columns' => Schema::getColumnListing('invoice'),
        ], 200);
    }

    /**
     * Sync invoices from external API. Query: from, to (dates in Y-m-d).
     */
    public function sync(Request $request): JsonResponse
    {
        try {
            return $this->runSync($request);
        } catch (Throwable $e) {
            Log::error('Invoice sync failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'message' => 'Sync failed',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    private function runSync(Request $request): JsonResponse
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

        $body = $response->json() ?? [];
        $data = $this->collectSyncInvoiceList($body);

        // Build selling-items list (sales=1) once before processing rows.
        $this->loadSyncSellingItems();
        $this->resolveEasySyncItemId();

        $partiesCreated = 0;
        $invoicesCreated = 0;
        $invoicesMatched = 0;
        $invoiceItemsCreated = 0;

        foreach ($data as $record) {
            $cid = $record['cid'] ?? null;
            $cidStr = $cid !== null && $cid !== '' ? (string) $cid : null;

            // Party: cid se find, nahi mila to create with full mapping
            $party = $cidStr !== null ? Party::where('cid', $cidStr)->first() : null;
            $syncState = $this->normalizeSyncState(isset($record['state']) ? (string) $record['state'] : null);

            $partyAttrs = [
                'partyname' => $record['billing_name'] ?? '',
                'mobno' => $record['billing_name'] ?? '',
                'cid' => $cidStr ?? (string) ($record['cid'] ?? ''),
                'billing_name' => $record['billing_name'] ?? null,
                'gst_no' => $record['gstno'] ?? null,
                'city' => $record['city'] ?? null,
                'state' => $syncState,
                'mobno' => $record['mob'] ?? null,
                'gst_reg' => isset($record['gst_regular']) ? (int) (bool) $record['gst_regular'] : 0,
                'same_state' => $syncState !== null && trim($syncState) === 'Rajasthan' ? 1 : 0,
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

            $invNo = isset($record['invoice']) && $record['invoice'] !== '' ? (string) $record['invoice'] : null;
            $invDt = isset($record['dt']) && $record['dt'] !== '' ? (string) $record['dt'] : now()->format('Y-m-d');

            $invoiceAmt = (float) ($record['amt'] ?? 0);
            $isDue = $this->isSyncRecordDueInvoice($record);
            $paybyId = $this->resolveSyncPayById($record);
            $gstIncluded = $this->isSyncGstIncluded($record);
            $gstAmounts = $this->computeSyncGstAmounts($invoiceAmt, $syncState, $gstIncluded, $invDt);
            $totalAmt = $gstAmounts['total'];

            // Buyer GSTIN claimed on superplayerauction → store on invoice for B2B reports.
            $syncGstNo = isset($record['gstno']) ? trim((string) $record['gstno']) : '';
            $syncGstNo = $syncGstNo !== '' ? $syncGstNo : null;
            if (array_key_exists('gst_regular', $record) && $record['gst_regular'] !== null && $record['gst_regular'] !== '') {
                $syncGstRegular = (int) (bool) $record['gst_regular'];
            } else {
                $syncGstRegular = $syncGstNo !== null ? 1 : 0;
            }

            [$invoice, $createdNew] = $this->resolveSyncInvoice(
                $party,
                $invNo,
                $invDt,
                $refNo,
                [
                    'pid' => $party->pid,
                    'inv_no' => $invNo,
                    'dt' => $invDt,
                    'state' => $syncState,
                    'addr' => $record['addr'] ?? $record['city'] ?? null,
                    'gstno' => $syncGstNo,
                    'gst_regular' => $syncGstRegular,
                    'gst' => $gstAmounts['gst'],
                    'payment' => $totalAmt,
                    'cgst' => $gstAmounts['cgst'],
                    'sgst' => $gstAmounts['sgst'],
                    'igst' => $gstAmounts['igst'],
                    'paytype' => $isDue ? 1 : 0,
                    'paynow' => $isDue ? 0 : $totalAmt,
                    'payby' => $paybyId,
                    'refno' => $refNo,
                    'taxable_amt' => $gstAmounts['taxable'],
                    'paylater' => $isDue ? $totalAmt : 0,
                    'balance' => $isDue ? $totalAmt : 0,
                ]
            );

            if ($createdNew) {
                $invoicesCreated++;
            } else {
                $invoicesMatched++;
            }

            // 01-03-2025 se: payment = amt only, info split nahi hota.
            $amtOnly = $invDt >= self::SYNC_AMT_ONLY_FROM;

            $lines = $this->collectSyncInvoiceItemLines($record, $invoiceAmt, $amtOnly);
            $n = count($lines);
            $rows = [];
            foreach ($lines as $line) {
                if (! is_array($line)) {
                    continue;
                }
                $mapped = $this->mapSyncInvItemRow($line, $invoice->invid, $invoiceAmt, $n, $invDt, $gstIncluded);
                $mapped['description'] = $this->syncItemDescription($record, $mapped, $refNo);
                if ($mapped['item_id'] !== null && (float) $mapped['payment'] > 0) {
                    $rows[] = $mapped;
                }
            }
            if ($rows !== []) {
                // Dedup by amid tag in description (same external row only).
                $toInsert = [];
                foreach ($rows as $r) {
                    if ($refNo !== null) {
                        $dup = InvoiceItem::where('inv_id', $r['inv_id'])
                            ->where('description', 'like', '%[#'.$refNo.']%')
                            ->exists();
                    } else {
                        $dup = InvoiceItem::where('inv_id', $r['inv_id'])
                            ->where('item_id', $r['item_id'])
                            ->where('description', $r['description'])
                            ->where('rate', $r['rate'])
                            ->where('qty', $r['qty'])
                            ->where('payment', $r['payment'])
                            ->exists();
                    }

                    if (! $dup) {
                        $toInsert[] = $r;
                    }
                }

                if ($toInsert !== []) {
                    InvoiceItem::insert($toInsert);
                    $invoiceItemsCreated += count($toInsert);
                }
            }

            if ($paybyId !== null) {
                $invoice->payby = $paybyId;
            }

            $invoice->state = $syncState;
            if (Schema::hasColumn('invoice', 'gstno')) {
                $invoice->gstno = $syncGstNo;
            }
            if (Schema::hasColumn('invoice', 'gst_regular')) {
                $invoice->gst_regular = $syncGstRegular;
            }
            // Keep first amid as refno if empty; otherwise leave so pay-in can still link via amid match on other rows.
            if ($refNo !== null && ($invoice->refno === null || $invoice->refno === '')) {
                $invoice->refno = $refNo;
            }

            $this->applyInvoiceTotalsFromLineItems($invoice, $invoiceAmt, $syncState, $isDue, $gstIncluded, $invDt);
        }

        $payInStats = $this->syncPayInRecords($this->collectSyncPayInList($body));

        return response()->json([
            'message' => 'Sync successful',
            'parties_created' => $partiesCreated,
            'invoices_created' => $invoicesCreated,
            'invoices_matched' => $invoicesMatched,
            'invoice_items_created' => $invoiceItemsCreated,
            'pay_ins_created' => $payInStats['created'],
            'pay_ins_skipped' => $payInStats['skipped'],
        ], 200);
    }
}
