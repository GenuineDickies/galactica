<?php
/*
 * Copyright (c) 2026 White Knight Roadside, LLC. All Rights Reserved.
 *
 * Proprietary and confidential. This software is LICENSED, NOT SOLD.
 * Unauthorized copying, modification, distribution, or use of this file,
 * via any medium, is strictly prohibited and will be prosecuted to the
 * fullest extent of the law (17 U.S.C. Sections 501-505). See LICENSE.txt.
 * Licensing: licensing@wkrllc.com
 */
declare(strict_types=1);

final class InvoiceController
{
    public static function index(): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        View::render('pages/inv_index', [
            'title' => 'Invoices', 'crumb' => 'Money', 'nav' => 'invoices',
            'rows'  => Db::all(
                "SELECT i.*, c.first_name, c.last_name, c.company, s.doc_number sr_no
                 FROM invoices i
                 JOIN customers c ON c.id = i.customer_id
                 JOIN service_requests s ON s.id = i.service_request_id
                 ORDER BY i.id DESC LIMIT 200"
            ),
        ]);
    }

    /**
     * Field collection. The office handles any invoice; a technician may open
     * and settle ONLY the invoice for a job assigned to them — the person
     * standing at the vehicle collects the signature, the cash and the card.
     * Everything else about invoices (the index, line edits, PO, voids) stays
     * office-side, because the field bills what the work order recorded.
     */
    public static function authorized(int $id): array
    {
        $inv = self::find($id);
        if (Auth::is('TECHNICIAN')) {
            $techId = $inv['work_order_id']
                ? (int) Db::val('SELECT technician_id FROM work_orders WHERE id = ?', [(int) $inv['work_order_id']], 0)
                : 0;
            if ($techId !== (int) Auth::id()) { Auth::requireRole('ADMIN', 'DISPATCH'); }
        } else {
            Auth::requireRole('ADMIN', 'DISPATCH');
        }
        return $inv;
    }

    /** Raised from the work order — including by the technician who ran the job. */
    public static function createFromWo(array $a): void
    {
        Auth::require();
        $wo = WorkOrderController::find((int) $a['woId']);
        if (Auth::is('TECHNICIAN') && (int) $wo['technician_id'] !== (int) Auth::id()) {
            Auth::requireRole('ADMIN', 'DISPATCH');
        }
        EstimateController::toInvoice(['id' => (string) $wo['estimate_id']]);
    }

    public static function show(array $a): void
    {
        $inv = self::authorized((int) $a['id']);
        $t   = self::recalc($inv);
        $inv = self::find((int) $inv['id']);
        $sr  = ServiceRequestController::find((int) $inv['service_request_id']);
        $est = $inv['estimate_id'] ? Db::one('SELECT * FROM estimates WHERE id = ?', [(int) $inv['estimate_id']]) : null;

        $estTotal   = $est ? (float) $est['total'] : 0.0;
        $needsAuth  = Rules::varianceNeedsAuth($estTotal, $t['total']) && (int) $inv['variance_authorized'] !== 1;

        View::render('pages/inv_show', [
            'title'    => $inv['doc_number'],
            'crumb'    => 'Invoice',
            'nav'      => Auth::is('TECHNICIAN') ? 'work-orders' : 'invoices',
            'inv'      => $inv,
            'sr'       => $sr,
            'est'      => $est,
            'customer' => Db::one('SELECT * FROM customers WHERE id = ?', [(int) $inv['customer_id']]),
            'vehicle'  => $inv['vehicle_id'] ? Db::one('SELECT * FROM vehicles WHERE id = ?', [(int) $inv['vehicle_id']]) : null,
            'lines'    => Lines::forDoc('INV', (int) $inv['id']),
            'totals'   => $t,
            'catalog'  => Db::all('SELECT * FROM catalog_items WHERE is_active = 1 ORDER BY item_type, category, name'),
            'payments' => Db::all('SELECT * FROM payments WHERE invoice_id = ? ORDER BY id', [(int) $inv['id']]),
            'links'    => Db::all('SELECT * FROM payment_links WHERE invoice_id = ? ORDER BY id DESC', [(int) $inv['id']]),
            'vehGate'  => Rules::invoiceVehicleGate($inv),
            'estTotal' => $estTotal,
            'variance' => $estTotal > 0 ? $t['total'] - $estTotal : 0.0,
            'varThresh'=> $estTotal > 0 ? Rules::varianceThreshold($estTotal) : 0.0,
            'needsAuth'=> $needsAuth,
            'audit'    => Audit::for('invoice', (int) $inv['id']),
        ]);
    }

    public static function printable(array $a): void
    {
        $inv = self::authorized((int) $a['id']);
        $sr  = ServiceRequestController::find((int) $inv['service_request_id']);
        View::render('pages/doc_print', [
            'title'    => $inv['doc_number'], '__bare' => true,
            'docKind'  => 'INVOICE', 'doc' => $inv, 'sr' => $sr,
            'customer' => Db::one('SELECT * FROM customers WHERE id = ?', [(int) $inv['customer_id']]),
            'vehicle'  => $inv['vehicle_id'] ? Db::one('SELECT * FROM vehicles WHERE id = ?', [(int) $inv['vehicle_id']]) : null,
            'lines'    => Lines::forDoc('INV', (int) $inv['id']),
            'payments' => Db::all('SELECT * FROM payments WHERE invoice_id = ? ORDER BY id', [(int) $inv['id']]),
        ]);
    }

    public static function addLine(array $a): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        $inv = self::find((int) $a['id']);
        self::assertDraft($inv);
        $itemId = intval_or_null('catalog_item_id');
        if (!$itemId) { flash('Pick an item from the catalog.', 'err'); redirect('/invoices/' . $inv['id']); }
        try {
            Lines::add('INV', (int) $inv['id'], $itemId, num('qty', 1), price_or_null(), (string) input('line_notes', ''), price_or_null('unit_cost'), overridden_flag(), misc_name());
        } catch (RuntimeException $e) {
            flash($e->getMessage(), 'err');
            redirect('/invoices/' . $inv['id']);
        }
        self::recalc($inv);
        Audit::log('invoice', (int) $inv['id'], 'line:added');
        redirect('/invoices/' . $inv['id']);
    }

    public static function delLine(array $a): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        $inv = self::find((int) $a['id']);
        self::assertDraft($inv);
        Lines::remove('INV', (int) $inv['id'], (int) $a['lineId']);
        self::recalc($inv);
        Audit::log('invoice', (int) $inv['id'], 'line:removed');
        redirect('/invoices/' . $inv['id']);
    }

    /** Re-authorization when the final total drifts past min($200, 10%).
     *  Open to the assigned technician: the customer signs the new number on
     *  the device that is in front of them. */
    public static function authorizeVariance(array $a): void
    {
        $inv  = self::authorized((int) $a['id']);
        $name = trim((string) input('variance_auth_name', ''));
        $sig  = (string) input('signature_data', '');
        if ($name === '' || $sig === '') {
            flash('Both the approver name and a signature are required to authorize the change in scope.', 'err');
            redirect('/invoices/' . $inv['id']);
        }
        $t = self::recalc($inv);
        Db::update('invoices', (int) $inv['id'], [
            'variance_authorized' => 1,
            'variance_auth_name'  => $name,
            'variance_auth_at'    => now(),
            'variance_amount'     => $t['total'] - (float) (Db::val('SELECT total FROM estimates WHERE id = ?', [(int) $inv['estimate_id']], 0)),
            'updated_at'          => now(),
        ]);
        Audit::log('invoice', (int) $inv['id'], 'variance:authorized', $name . ' · signature captured · IP ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
        flash('Change in scope authorized. The invoice can now be issued.', 'ok');
        redirect('/invoices/' . $inv['id']);
    }

    /** PO number, editable while the invoice is a draft. */
    public static function setPo(array $a): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        $inv = self::find((int) $a['id']);
        self::assertDraft($inv);
        $po = trim((string) input('po_number', ''));
        Db::update('invoices', (int) $inv['id'], ['po_number' => $po ?: null, 'updated_at' => now()]);
        Audit::log('invoice', (int) $inv['id'], 'po:set', ($inv['po_number'] ?: '—') . ' → ' . ($po ?: '—'));
        flash($po ? 'PO number saved.' : 'PO number cleared.', 'ok');
        redirect('/invoices/' . $inv['id']);
    }

    public static function noVehicle(array $a): void
    {
        $inv = self::authorized((int) $a['id']);
        $reason = trim((string) input('no_vehicle_reason', ''));
        if ($reason === '') { flash('State why no vehicle was serviced.', 'err'); redirect('/invoices/' . $inv['id']); }
        Db::update('invoices', (int) $inv['id'], ['no_vehicle_reason' => $reason, 'updated_at' => now()]);
        Audit::log('invoice', (int) $inv['id'], 'no_vehicle', $reason);
        redirect('/invoices/' . $inv['id']);
    }

    public static function issue(array $a): void
    {
        $inv = self::authorized((int) $a['id']);
        self::assertDraft($inv);
        $t   = self::recalc($inv);
        $inv = self::find((int) $inv['id']);

        $gate = Rules::invoiceVehicleGate($inv);
        if (!$gate['ok']) { flash($gate['reason'], 'err'); redirect('/invoices/' . $inv['id']); }

        // Billed core deposits and part-line core charges must agree before
        // the invoice can issue — see Rules::coreDepositGate for why.
        $cores = Rules::coreDepositGate($inv);
        if (!$cores['ok']) { flash($cores['reason'], 'err'); redirect('/invoices/' . $inv['id']); }

        $estTotal = (float) (Db::val('SELECT total FROM estimates WHERE id = ?', [(int) $inv['estimate_id']], 0) ?? 0);
        if (Rules::varianceNeedsAuth($estTotal, $t['total']) && (int) $inv['variance_authorized'] !== 1) {
            flash('The final total differs from the approved estimate by more than ' . money(Rules::varianceThreshold($estTotal)) . '. Capture re-authorization first.', 'err');
            redirect('/invoices/' . $inv['id']);
        }

        // Due date comes from the terms snapshotted at creation: COD accounts
        // (every account by default) are due on receipt; Net 15/30 only when
        // that credit was deliberately granted. Never read from the live
        // customer record — the snapshot is the contract.
        $issuedAt = now();
        Db::tx(function () use ($inv, $t, $issuedAt) {
            Db::update('invoices', (int) $inv['id'], [
                'status'      => 'ISSUED',
                'issued_at'   => $issuedAt,
                'due_at'      => Rules::invoiceDueAt($issuedAt, $inv['terms'] ?? null),
                'balance_due' => $t['total'] - (float) $inv['amount_paid'],
                'updated_at'  => now(),
            ]);
            Db::update('service_requests', (int) $inv['service_request_id'], ['status' => 'COMPLETED', 'updated_at' => now()]);

            /* The books move when the invoice is issued, not when it is paid —
             * that is what accrual means and what makes Accounts Receivable a
             * real balance. Inside this transaction on purpose: if the entry
             * cannot be written the invoice does not issue, because an issued
             * invoice with nothing behind it in the ledger is the exact silent
             * hole the ledger was built to close. */
            $fresh = InvoiceController::find((int) $inv['id']);
            Posting::invoiceIssued($fresh);

            /* Open a custody record per core charged. The money side is
             * already handled above — the core line credits 2050 like any
             * other line, because the catalog item points there. This is the
             * PHYSICAL side: which old unit is now owed back, by when, and
             * eventually who is carrying it. */
            Cores::openForInvoice($fresh);
        });

        $c = Db::one('SELECT * FROM customers WHERE id = ?', [(int) $inv['customer_id']]);
        Sms::queue($c, 'invoice', ['{total}' => money($t['total']), '{eta}' => '', '{doc}' => $inv['doc_number']], (int) $inv['service_request_id']);

        Audit::log('invoice', (int) $inv['id'], 'issued', money($t['total']));
        flash('Invoice issued. It is now locked — corrections go through a void or credit.', 'ok');
        redirect('/invoices/' . $inv['id']);
    }

    public static function void(array $a): void
    {
        Auth::requireRole('ADMIN');
        $inv    = self::find((int) $a['id']);
        $reason = trim((string) input('void_reason', ''));
        if ($reason === '') { flash('A reason is required to void an invoice.', 'err'); redirect('/invoices/' . $inv['id']); }
        if ((float) $inv['amount_paid'] > 0) {
            flash('This invoice has payments against it, so voiding would orphan money already taken. '
                . 'Record a refund instead, from <a href="' . e(url('payments')) . '">Payments</a>.', 'err');
            redirect('/invoices/' . $inv['id']);
        }
        Db::tx(function () use ($inv, $reason) {
            Db::update('invoices', (int) $inv['id'], ['status' => 'VOID', 'void_reason' => $reason, 'updated_at' => now()]);
            /* A reversing entry, never an erasure. Both halves stay on the
             * books and net to zero, so a void is visible to anyone reading
             * the ledger rather than tidied out of it. */
            Posting::invoiceVoided($inv, $reason);
        });
        Audit::log('invoice', (int) $inv['id'], 'voided', $reason);
        flash('Invoice voided. The record is retained — nothing is ever deleted.', 'warn');
        redirect('/invoices/' . $inv['id']);
    }

    public static function recalc(array $inv): array
    {
        $t    = Lines::totals('INV', (int) $inv['id'], (float) $inv['tax_rate']);
        $paid = (float) Db::val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id = ? AND status='COMPLETED'", [(int) $inv['id']]);
        $bal  = round($t['total'] - $paid, 2);

        $status = $inv['status'];
        if (in_array($status, ['ISSUED', 'PARTIAL', 'PAID'], true)) {
            $status = ($t['total'] > 0 && $bal <= 0.004) ? 'PAID' : ($paid > 0 ? 'PARTIAL' : 'ISSUED');
        }

        Db::update('invoices', (int) $inv['id'], [
            'subtotal'       => $t['subtotal'],
            'discount_total' => $t['discount'],
            'tax_total'      => $t['tax'],
            'total'          => $t['total'],
            'amount_paid'    => $paid,
            'balance_due'    => max(0, $bal),
            'status'         => $status,
        ]);
        return $t;
    }

    private static function assertDraft(array $inv): void
    {
        if ($inv['status'] !== 'DRAFT') {
            flash('This invoice has been issued and is locked. Void it or raise a credit instead.', 'err');
            redirect('/invoices/' . $inv['id']);
        }
    }

    public static function find(int $id): array
    {
        $i = Db::one('SELECT * FROM invoices WHERE id = ?', [$id]);
        if (!$i) { http_response_code(404); View::render('pages/404', ['title' => 'Not found']); exit; }
        return $i;
    }
}

final class PaymentController
{
    public static function index(): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        View::render('pages/pay_index', [
            'title' => 'Payments', 'crumb' => 'Money', 'nav' => 'payments',
            'rows'  => Db::all(
                "SELECT p.*, i.doc_number inv_no, c.first_name, c.last_name, c.company,
                        r.doc_number rcpt_no, r.id rcpt_id
                 FROM payments p
                 JOIN invoices i  ON i.id = p.invoice_id
                 JOIN customers c ON c.id = p.customer_id
                 LEFT JOIN receipts r ON r.payment_id = p.id
                 ORDER BY p.id DESC LIMIT 200"
            ),
            'totals' => [
                'today' => (float) Db::val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE substr(paid_at,1,10)=?", [date('Y-m-d')]),
                'month' => (float) Db::val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE substr(paid_at,1,7)=?", [date('Y-m')]),
                'tips'  => (float) Db::val("SELECT COALESCE(SUM(tip_amount),0) FROM payments WHERE substr(paid_at,1,7)=?", [date('Y-m')]),
            ],
        ]);
    }

    public static function take(array $a): void
    {
        // Office, or the technician assigned to this invoice's job — the
        // person at the vehicle takes the cash, the check, and the card.
        $inv = InvoiceController::authorized((int) $a['invoiceId']);

        if (!in_array($inv['status'], ['ISSUED', 'PARTIAL'], true)) {
            flash('Payments can only be taken against an issued invoice.', 'err');
            redirect('/invoices/' . $inv['id']);
        }
        $amount = num('amount');
        if ($amount <= 0) { flash('Enter a payment amount.', 'err'); redirect('/invoices/' . $inv['id']); }

        $res = self::record($inv, $amount, (string) input('method', 'CARD'), [
            'tip'             => num('tip_amount'),
            'reference'       => (string) input('reference', ''),
            'processor_ref'   => (string) input('processor_ref', ''),
            'idempotency_key' => (string) input('idempotency_key', ''),
            'note'            => (string) input('note', ''),
        ]);

        flash($res ? 'Payment recorded and a receipt was generated.'
                   : 'That payment reference has already been recorded. Nothing was written twice.',
              $res ? 'ok' : 'warn');
        if ($res && $amount > (float) $inv['balance_due']) {
            flash('The amount was ' . money($amount - (float) $inv['balance_due']) . ' over the balance. '
                . 'That extra is held — <a href="' . e(url('payments')) . '">open Payments</a> to confirm it as a tip or refund it.', 'warn');
        }
        redirect('/invoices/' . $inv['id']);
    }

    /**
     * A person says what a held overpayment was. Two outcomes, both one
     * click on /payments: it was a tip (reclassified into tip revenue), or
     * it is owed back (recorded as refunded). The status flip and the ledger
     * entry share a transaction, and the flip claims the HELD row the way a
     * signing token is claimed — a second click finds nothing to resolve.
     */
    public static function resolveOverpayment(array $a): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        $id = (int) $a['id'];
        $p  = Db::one('SELECT * FROM payments WHERE id = ?', [$id]);
        $as = (string) input('resolve', '');
        if (!$p || (string) $p['overpayment_status'] !== 'HELD' || Markup::toCents($p['overpayment_amount']) <= 0) {
            flash('Nothing is held on that payment.', 'err');
            redirect('/payments');
        }
        if (!in_array($as, ['tip', 'refund'], true)) {
            flash('Say which it was — tip or refund.', 'err');
            redirect('/payments');
        }
        try {
            Db::tx(function () use ($p, $as, $id) {
                $st = Db::q("UPDATE payments SET overpayment_status = ? WHERE id = ? AND overpayment_status = 'HELD'",
                    [$as === 'tip' ? 'TIP' : 'REFUNDED', $id]);
                if ($st->rowCount() !== 1) {
                    throw new RuntimeException('Someone else already resolved this overpayment.');
                }
                $as === 'tip' ? Posting::overpaymentTip($p) : Posting::overpaymentRefunded($p);
            });
        } catch (RuntimeException $e) {
            flash($e->getMessage(), 'warn');
            redirect('/payments');
        }
        Audit::log('payment', $id, 'overpayment:' . $as, money($p['overpayment_amount']));
        flash($as === 'tip'
            ? money($p['overpayment_amount']) . ' confirmed as a tip.'
            : money($p['overpayment_amount']) . ' recorded as refunded to the customer.', 'ok');
        redirect('/payments');
    }

    /**
     * The single path by which money is written down — used by the till, and by
     * the payment webhook. Returns false when the processor reference has
     * already been recorded, which is what makes a replayed callback harmless.
     */
    public static function record(array $inv, float $amount, string $method, array $opts = []): bool
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Payment amount must be greater than zero.');
        }

        /* The invoice balance is the amount applied to the bill. A tip is a
         * tip only when it arrived LABELLED as one (the checkout page's tip
         * box, Square's tip prompt, the till form's tip field). Anything else
         * above the balance is an unverified overpayment: it may be "keep the
         * change", it may be a typo the customer is owed back — so it is held
         * on 2060 and flagged on /payments for a person to resolve, never
         * guessed into income (owner's decision, 2026-08-27; it used to be
         * silently added to the tip). */
        InvoiceController::recalc($inv);
        $inv = InvoiceController::find((int) $inv['id']);
        $tip = max(0.0, (float) ($opts['tip'] ?? 0));
        $balance = max(0.0, (float) $inv['balance_due']);
        $overpay = 0.0;
        if ($amount > $balance) {
            $overpay = $amount - $balance;
            $amount  = $balance;
        }
        /* Money arriving against a settled invoice is still money that
         * arrived — refuse only when there is truly nothing to write down.
         * The whole amount lands as a held overpayment for a person to
         * resolve (refund it, most likely). */
        if ($amount <= 0 && $tip <= 0 && $overpay <= 0) {
            throw new InvalidArgumentException('This invoice is already settled.');
        }

        $procRef = trim((string) ($opts['processor_ref'] ?? '')) ?: null;

        if ($procRef !== null && Db::one('SELECT id FROM payments WHERE processor_ref = ?', [$procRef])) {
            ApiLog::write('payment', (string) ($opts['processor'] ?? 'manual'), 'duplicate', $procRef, true,
                'Already recorded — callback ignored.');
            return false;
        }

        /* Cash and checks have no processor reference, so the till form mints
         * its own idempotency key when it renders — a double-click or a
         * back-button resubmit arrives carrying the same key and is refused
         * here (and by uq_pay_idem under true concurrency), the same way a
         * replayed webhook is (fixed 2026-08-27). */
        $idemKey = trim((string) ($opts['idempotency_key'] ?? ''))
            ?: 'invoice-' . $inv['id'] . '-payment-' . bin2hex(random_bytes(6));
        if (Db::one('SELECT id FROM payments WHERE idempotency_key = ?', [$idemKey])) {
            ApiLog::write('payment', (string) ($opts['processor'] ?? 'manual'), 'duplicate', $idemKey, true,
                'Same payment form submitted twice — recorded once.');
            return false;
        }

        try {
            Db::tx(function () use ($inv, $amount, $method, $opts, $procRef, $tip, $overpay, $idemKey) {
                $payId = Db::insert('payments', [
                    'doc_number'      => DocNumber::next('PAY'),
                    'invoice_id'      => (int) $inv['id'],
                    'customer_id'     => (int) $inv['customer_id'],
                    'method'          => $method,
                    'amount'          => $amount,
                    'tip_amount'      => $tip,
                    'overpayment_amount' => $overpay,
                    'overpayment_status' => $overpay > 0 ? 'HELD' : null,
                    'reference'       => (string) ($opts['reference'] ?? ''),
                    'processor'       => (string) ($opts['processor'] ?? ($method === 'CARD' ? Integrations::payments()->driverName() : '')) ?: null,
                    'processor_ref'   => $procRef,
                    // Minted by the till form at render, or server-side here — see above.
                    'idempotency_key' => $idemKey,
                    'status'          => 'COMPLETED',
                    'paid_at'         => now(),
                    'note'            => (string) ($opts['note'] ?? ''),
                    'created_at'      => now(),
                ]);
                Db::insert('receipts', [
                    'doc_number' => DocNumber::next('RCT'),
                    'payment_id' => $payId,
                    'invoice_id' => (int) $inv['id'],
                    'issued_at'  => now(),
                ]);
                Audit::log('invoice', (int) $inv['id'], 'payment:recorded',
                    money($amount) . ' via ' . $method . ($procRef ? ' · ' . $procRef : ''));

                /* Payment settles the receivable; it does not create revenue —
                 * that happened when the invoice was issued. A card payment lands
                 * in Square Clearing, not Checking, because the money is not in
                 * the bank until the processor transfers it. */
                Posting::paymentRecorded(Db::one('SELECT * FROM payments WHERE id = ?', [$payId]) ?? [], $inv);
            });
        } catch (Throwable $e) {
            if (!self::isDuplicatePayment($e)) { throw $e; }
            ApiLog::write('payment', (string) ($opts['processor'] ?? 'manual'), 'duplicate',
                $procRef ?? $idemKey, true, 'Concurrent duplicate — the payment was already recorded once.');
            return false;
        }

        InvoiceController::recalc($inv);
        $fresh = InvoiceController::find((int) $inv['id']);
        if ($fresh['status'] === 'PAID') {
            Db::q("UPDATE payment_links SET status = 'PAID' WHERE invoice_id = ? AND status = 'OPEN'", [(int) $inv['id']]);
            $c = Db::one('SELECT * FROM customers WHERE id = ?', [(int) $inv['customer_id']]);
            Sms::queue($c, 'receipt', ['{doc}' => $fresh['doc_number'], '{total}' => money($fresh['total']), '{eta}' => ''],
                (int) $inv['service_request_id']);
        }
        return true;
    }

    private static function isDuplicatePayment(Throwable $e): bool
    {
        if (!($e instanceof PDOException) || $e->getCode() !== '23000') { return false; }
        $msg = strtolower($e->getMessage());
        // Either uniqueness net catching the same fish: the processor's
        // reference (webhooks) or the till form's key (uq_pay_idem).
        return str_contains($msg, 'processor_ref')
            || str_contains($msg, 'uq_pay_idem') || str_contains($msg, 'idempotency_key');
    }

    /**
     * Issues a hosted checkout page for the outstanding balance, and texts it to
     * the customer when consent is on file. The customer pays from wherever they
     * are; the webhook records it.
     */
    public static function link(array $a): void
    {
        $inv = InvoiceController::authorized((int) $a['invoiceId']);
        $gw  = Integrations::payments();

        if (!in_array($inv['status'], ['ISSUED', 'PARTIAL'], true)) {
            flash('A payment link can only be issued against an issued invoice.', 'err');
            redirect('/invoices/' . $inv['id']);
        }
        $balance = (float) $inv['balance_due'];
        if ($balance <= 0) { flash('Nothing is outstanding on this invoice.', 'warn'); redirect('/invoices/' . $inv['id']); }

        // Minted before the call, stored after it — a retry reuses the same key.
        $key = 'invoice-' . $inv['id'] . '-link-' . bin2hex(random_bytes(6));
        $res = $gw->paymentLink($balance, $key, [
            'reference' => (string) $inv['doc_number'],
            'name'      => App::config('company')['short'] . ' — ' . $inv['doc_number'],
            'redirect'  => Http::baseUrl() . '/invoices/' . $inv['id'],
        ]);

        if (!$res->ok) {
            flash('Could not create a payment link: ' . e($res->message), 'err');
            redirect('/invoices/' . $inv['id']);
        }

        Db::insert('payment_links', [
            'invoice_id' => (int) $inv['id'],
            'provider'   => $gw->driverName(),
            'link_id'    => (string) ($res->raw['link_id'] ?? ''),
            'order_id'   => (string) ($res->raw['order_id'] ?? $res->reference),
            'url'        => (string) ($res->raw['url'] ?? ''),
            'amount'     => $balance,
            'status'     => 'OPEN',
            'created_by' => Auth::id(),
            'created_at' => now(),
        ]);
        Audit::log('invoice', (int) $inv['id'], 'payment_link:created', money($balance) . ' · ' . $res->reference);

        $c    = Db::one('SELECT * FROM customers WHERE id = ?', [(int) $inv['customer_id']]);
        $gate = Sms::queue($c, 'pay_link', [
            '{total}' => money($balance), '{doc}' => $inv['doc_number'],
            '{link}'  => (string) ($res->raw['url'] ?? ''), '{eta}' => '',
        ], (int) $inv['service_request_id']);

        flash('Payment link created.' . match (true) {
                  (bool) ($gate['sent'] ?? false) => ' Texted to the customer.',
                  (bool) ($gate['held'] ?? false) => ' Texting is not connected, so it was NOT texted. Take payment in person or by card over the counter instead.',
                  default                         => ' The text did NOT go out: ' . e($gate['reason']) . ' Give the customer the link another way.',
              },
              ($gate['sent'] ?? false) ? 'ok' : 'warn');
        redirect('/invoices/' . $inv['id']);
    }

    public static function receipt(array $a): void
    {
        Auth::require();
        $r = Db::one('SELECT * FROM receipts WHERE id = ?', [(int) $a['id']]);
        if (!$r) { http_response_code(404); View::render('pages/404', ['title' => 'Not found']); exit; }
        $p   = Db::one('SELECT * FROM payments WHERE id = ?', [(int) $r['payment_id']]);
        $inv = InvoiceController::authorized((int) $r['invoice_id']);
        $sr  = ServiceRequestController::find((int) $inv['service_request_id']);

        View::render('pages/doc_print', [
            'title'   => $r['doc_number'], '__bare' => true,
            'docKind' => 'RECEIPT', 'doc' => $inv, 'receipt' => $r, 'payment' => $p, 'sr' => $sr,
            'customer'=> Db::one('SELECT * FROM customers WHERE id = ?', [(int) $inv['customer_id']]),
            'vehicle' => $inv['vehicle_id'] ? Db::one('SELECT * FROM vehicles WHERE id = ?', [(int) $inv['vehicle_id']]) : null,
            'lines'   => Lines::forDoc('INV', (int) $inv['id']),
            'payments'=> Db::all('SELECT * FROM payments WHERE invoice_id = ? ORDER BY id', [(int) $inv['id']]),
        ]);
    }
}
