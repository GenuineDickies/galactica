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

/**
 * 2. ESTIMATE
 *
 * The record that we are under contract with a verified customer. It holds the
 * priced scope and the customer's authorization, and it is what defends a
 * chargeback. Nothing reaches a technician until it is authorized, and nothing
 * is invoiced that did not start here.
 */
final class EstimateController
{
    public const STATUSES = ['DRAFT', 'SENT', 'APPROVED', 'DECLINED'];

    public static function index(): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        View::render('pages/est_index', [
            'title' => 'Estimates', 'crumb' => 'Operations', 'nav' => 'estimates',
            'rows'  => Db::all(
                "SELECT e.*, s.doc_number sr_no, c.first_name, c.last_name, c.company
                 FROM estimates e
                 JOIN service_requests s ON s.id = e.service_request_id
                 JOIN customers c        ON c.id = e.customer_id
                 ORDER BY e.id DESC LIMIT 200"
            ),
        ]);
    }

    public static function show(array $a): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        $est = self::find((int) $a['id']);
        $t   = self::recalc($est);
        $est = self::find((int) $est['id']);

        View::render('pages/est_show', [
            'title'     => $est['doc_number'],
            'crumb'     => 'Estimate',
            'nav'       => 'estimates',
            'est'       => $est,
            /* A repair option points back at its diagnostic report. Wrapped:
             * the column may predate the table on an install mid-upgrade. */
            'optionOf'  => (static function () use ($est): ?array {
                if (empty($est['diagnostic_report_id'])) { return null; }
                try { return Db::one('SELECT id, doc_number, work_order_id FROM diagnostic_reports WHERE id = ?', [(int) $est['diagnostic_report_id']]); }
                catch (Throwable) { return null; }
            })(),
            'sr'        => ServiceRequestController::find((int) $est['service_request_id']),
            'customer'  => Db::one('SELECT * FROM customers WHERE id = ?', [(int) $est['customer_id']]),
            'vehicle'   => $est['vehicle_id'] ? Db::one('SELECT * FROM vehicles WHERE id = ?', [(int) $est['vehicle_id']]) : null,
            'vehicles'  => Db::all('SELECT * FROM vehicles WHERE customer_id = ? ORDER BY id DESC', [(int) $est['customer_id']]),
            'lines'     => Lines::forDoc('EST', (int) $est['id']),
            'totals'    => $t,
            'catalog'   => Db::all('SELECT * FROM catalog_items WHERE is_active = 1 ORDER BY item_type, category, name'),
            'sigNeeded' => Rules::signatureRequired($t['total']),
            'gate'      => Rules::dispatchGate($est),
            'wos'       => Db::all(
                'SELECT w.*, u.first_name tech_first, u.last_name tech_last
                 FROM work_orders w LEFT JOIN users u ON u.id = w.technician_id
                 WHERE w.estimate_id = ? ORDER BY w.id DESC',
                [(int) $est['id']]
            ),
            'invoice'   => Db::one('SELECT * FROM invoices WHERE estimate_id = ?', [(int) $est['id']]),
            'techs'     => Db::all("SELECT * FROM users WHERE role IN ('TECHNICIAN','ADMIN') AND is_active = 1 AND can_accept_jobs = 1 ORDER BY first_name"),
            'audit'     => Audit::for('estimate', (int) $est['id']),
            'locOpen'   => LocationRequest::openFor('EST', (int) $est['id']),
        ]);
    }

    /**
     * Text the customer a location link. The position that comes back lands
     * on THIS estimate — the service-address fields the operator typed stay
     * untouched, because "where the customer's phone is" and "where the
     * operator wrote down" are different facts until someone confirms them.
     */
    public static function sendLocateLink(array $a): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        $est  = self::find((int) $a['id']);
        $cust = Db::one('SELECT * FROM customers WHERE id = ?', [(int) $est['customer_id']]);

        /* A location link asks a phone where IT is, so it is only ever worth
         * sending to the phone that is with the vehicle. On a provider job the
         * customer of record is the broker's office — texting them would ask a
         * desk in another state to report the motorist's position. Fall back to
         * the caller's number on the request, under the intake consent. */
        $sr       = ServiceRequestController::find((int) $est['service_request_id']);
        $toBroker = (int) ($cust['is_provider'] ?? 0) === 1;
        $phone    = $toBroker
            ? (phone_to_e164((string) $sr['reported_phone']) ?: '')
            : (string) $cust['phone_e164'];

        if ($toBroker && $phone === '') {
            flash('This is a provider job, so the location link goes to the caller, not the provider — '
                . 'and the request has no valid callback number to send it to.', 'err');
            redirect('/estimates/' . $est['id']);
        }
        if (!$toBroker) {
            $gate = Sms::gate($cust);
            if (!$gate['ok']) {
                flash('Cannot text this customer: ' . e($gate['reason']), 'err');
                redirect('/estimates/' . $est['id']);
            }
        }

        $req  = LocationRequest::issue('EST', (int) $est['id'], $phone,
                                       (int) $est['service_request_id'],
                                       $toBroker ? null : (int) $cust['id']);
        $gate = Sms::queueOnScene($sr, $cust, 'locate', ['{link}' => LocationRequest::url($req)]);
        if ($gate['sent'] ?? false) { Db::update('location_requests', (int) $req['id'], ['sent_at' => now()]); }

        /* The number the link actually went to, which on a provider job is the
         * caller's rather than the customer of record's. Logging the customer's
         * number here would misreport who was asked for the position. */
        Audit::log('estimate', (int) $est['id'], 'location:link', match (true) {
            (bool) ($gate['sent'] ?? false) => 'location link texted to ' . $phone . ($toBroker ? ' (the caller — provider job)' : ''),
            (bool) ($gate['held'] ?? false) => 'location link held in outbox for ' . $phone . ($toBroker ? ' (the caller — provider job)' : ''),
            default                         => 'NOT SENT — ' . $gate['reason'],
        });
        flash(match (true) {
            (bool) ($gate['sent'] ?? false) => 'Location link texted. The position will appear on this estimate when the customer answers it.',
            (bool) ($gate['held'] ?? false) => 'Texting is not connected, so NO location link went to the customer — call them and get the location verbally.',
            default                         => 'The location link did NOT go out: ' . e($gate['reason']) . ' Get the location verbally — do not leave the customer waiting on a text.',
        }, ($gate['sent'] ?? false) ? 'ok' : 'warn');
        redirect('/estimates/' . $est['id']);
    }

    public static function printable(array $a): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        $est = self::find((int) $a['id']);
        View::render('pages/doc_print', [
            'title'   => $est['doc_number'], '__bare' => true,
            'docKind' => 'ESTIMATE', 'doc' => $est,
            'sr'      => ServiceRequestController::find((int) $est['service_request_id']),
            'customer'=> Db::one('SELECT * FROM customers WHERE id = ?', [(int) $est['customer_id']]),
            'vehicle' => $est['vehicle_id'] ? Db::one('SELECT * FROM vehicles WHERE id = ?', [(int) $est['vehicle_id']]) : null,
            'lines'   => Lines::forDoc('EST', (int) $est['id']),
        ]);
    }

    public static function addLine(array $a): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        $est = self::find((int) $a['id']);
        self::assertOpen($est);

        $item = intval_or_null('catalog_item_id');
        if (!$item) {
            flash('Pick an item from the catalog. Free-typed line items are not allowed.', 'err');
            redirect('/estimates/' . $est['id']);
        }
        try {
            Lines::add('EST', (int) $est['id'], $item, num('qty', 1), price_or_null(), (string) input('line_notes', ''), price_or_null('unit_cost'), overridden_flag(), misc_name());
        } catch (RuntimeException $e) {
            flash($e->getMessage(), 'err');
            redirect('/estimates/' . $est['id']);
        }
        self::recalc($est);
        Audit::log('estimate', (int) $est['id'], 'line:added');
        redirect('/estimates/' . $est['id']);
    }

    public static function delLine(array $a): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        $est = self::find((int) $a['id']);
        self::assertOpen($est);
        Lines::remove('EST', (int) $est['id'], (int) $a['lineId']);
        self::recalc($est);
        Audit::log('estimate', (int) $est['id'], 'line:removed');
        redirect('/estimates/' . $est['id']);
    }

    /**
     * A vehicle record exists only if it has a valid VIN. A plate never creates one.
     *
     * $back lets the work-order screen reuse this without bouncing a technician
     * into the estimate, which they are not allowed to open.
     */
    public static function attachVehicle(array $a, ?string $back = null): void
    {
        /* Direct hits on /estimates/{id}/vehicle are office-only, like every
         * other estimate write. A technician reaches this code only through
         * WorkOrderController::captureVin, which has already checked that the
         * work order is theirs and passes $back. */
        if ($back === null) { Auth::requireRole('ADMIN', 'DISPATCH'); } else { Auth::require(); }
        $est  = self::find((int) $a['id']);
        $back = $back ?: '/estimates/' . $est['id'];
        $sr  = ServiceRequestController::find((int) $est['service_request_id']);
        $vin = strtoupper(trim((string) input('vin', '')));
        $plate = strtoupper(trim((string) input('plate', '')));
        $plateState = strtoupper(trim((string) input('plate_state', (string) ($sr['v_plate_state'] ?? ''))));
        if ($vin === '' && $plate !== '') {
            $vin = (string) (Db::val(
                'SELECT vin FROM vehicles WHERE plate = ? AND (plate_state = ? OR ? = \'\') LIMIT 1',
                [$plate, $plateState, $plateState]
            ) ?? '');
            if ($vin === '') {
                flash('That plate is not linked to a vehicle VIN on file. A vehicle record must be created from its VIN first.', 'err');
                redirect($back);
            }
        }
        if (!vin_is_valid($vin)) {
            flash('Enter a valid VIN, or a plate that is already linked to a valid VIN.', 'err');
            redirect($back);
        }

        $veh = Db::one('SELECT * FROM vehicles WHERE vin = ?', [$vin]);
        if (!$veh) {
            $vehId = Db::insert('vehicles', [
                'customer_id'     => (int) $est['customer_id'],
                'vin'             => $vin,
                'plate'           => $plate ?: strtoupper((string) $sr['v_plate']),
                'plate_state'     => $plateState,
                'no_plate'        => input('no_plate') ? 1 : 0,
                'no_plate_reason' => (string) input('no_plate_reason', ''),
                'year'            => intval_or_null('year') ?: ($sr['v_year'] ?: null),
                'make'            => (string) input('make', (string) $sr['v_make']),
                'model'           => (string) input('model', (string) $sr['v_model']),
                'color'           => (string) input('color', (string) $sr['v_color']),
                'odometer'        => intval_or_null('odometer'),
                'vin_decoded'     => json_encode(Integrations::vin()->decode($vin)),
                'created_at'      => now(),
            ]);
            Audit::log('vehicle', $vehId, 'created', 'VIN ' . $vin . ' captured on ' . $est['doc_number']);
        } else {
            $vehId = (int) $veh['id'];
        }
        Db::update('estimates', (int) $est['id'], ['vehicle_id' => $vehId, 'updated_at' => now()]);
        // Any invoice already raised from this estimate inherits the vehicle.
        Db::q('UPDATE invoices SET vehicle_id = ? WHERE estimate_id = ? AND vehicle_id IS NULL', [$vehId, (int) $est['id']]);
        Audit::log('estimate', (int) $est['id'], 'vehicle:linked', 'VIN ' . $vin);
        flash('VIN captured and vehicle linked. The invoice gate is now clear.', 'ok');
        redirect($back);
    }

    /**
     * PO number — per-document data for business accounts. Entered here, it
     * carries EST → WO → INV; each document stays editable while it is open.
     */
    public static function setPo(array $a): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        $est = self::find((int) $a['id']);
        self::assertOpen($est);
        $po = trim((string) input('po_number', ''));
        Db::update('estimates', (int) $est['id'], ['po_number' => $po ?: null, 'updated_at' => now()]);
        Audit::log('estimate', (int) $est['id'], 'po:set', ($est['po_number'] ?: '—') . ' → ' . ($po ?: '—'));
        flash($po ? 'PO number saved. It will carry to the work order and invoice.' : 'PO number cleared.', 'ok');
        redirect('/estimates/' . $est['id']);
    }

    public static function send(array $a): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        $est = self::find((int) $a['id']);
        $t   = self::recalc($est);

        if (!Lines::forDoc('EST', (int) $est['id'])) {
            flash('Add at least one line item before sending.', 'err');
            redirect('/estimates/' . $est['id']);
        }
        Db::update('estimates', (int) $est['id'], ['status' => 'SENT', 'updated_at' => now()]);

        $c    = Db::one('SELECT * FROM customers WHERE id = ?', [(int) $est['customer_id']]);
        $gate = Sms::queue($c, 'estimate', ['{total}' => money($t['total']), '{eta}' => '', '{doc}' => $est['doc_number']],
                           (int) $est['service_request_id']);

        Audit::log('estimate', (int) $est['id'], 'sent', money($t['total']));
        flash('Estimate marked as sent.' . ($gate['ok'] ? ' SMS queued to the customer.' : ' SMS blocked: ' . e($gate['reason'])),
              $gate['ok'] ? 'ok' : 'warn');
        redirect('/estimates/' . $est['id']);
    }

    /**
     * Customer authorization. This is the contract, and the evidence package:
     * who, how, when, from what address, on what device.
     */
    public static function authorize(array $a): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        $est = self::find((int) $a['id']);
        $t   = self::recalc($est);

        $name   = trim((string) input('authorized_by', ''));
        $method = (string) input('authorization_method', 'VERBAL');

        if (!Lines::forDoc('EST', (int) $est['id'])) {
            flash('Price the work before asking the customer to authorize it.', 'err');
            redirect('/estimates/' . $est['id']);
        }
        if ($name === '') {
            flash('Record the first and last name of the person authorizing the work.', 'err');
            redirect('/estimates/' . $est['id']);
        }
        // The estimate is authorized VERBALLY — no signature is taken here at
        // any amount. What a signature gates is work on the vehicle, and it is
        // captured on the WORK ORDER: on the technician's device, or through a
        // link texted to the customer. See Rules::workBeginsGate().
        $owed = Rules::signatureRequired($t['total']);

        /* A repair option on a diagnostic report: one per report. */
        $opt = Rules::optionAuthorizeGate($est);
        if (!$opt['ok']) {
            flash($opt['reason'], 'err');
            redirect('/estimates/' . $est['id']);
        }

        Db::update('estimates', (int) $est['id'], [
            'status'               => 'APPROVED',
            'authorized_by'        => $name,
            'authorization_method' => $method,
            'authorized_at'        => now(),
            'authorization_ip'     => $_SERVER['REMOTE_ADDR'] ?? null,
            'authorization_agent'  => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250),
            'updated_at'           => now(),
        ]);
        Audit::log('estimate', (int) $est['id'], 'authorized',
            $name . ' · ' . $method
            . ($owed ? ' · signature owed on the work order before work begins' : '')
            . ' · ' . money($t['total'])
            . ' · IP ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));

        /* The customer chose this option, so the others are off the table —
         * declined with the reason on record, never deleted, and gone from
         * the open-estimates count. */
        $chosen = $est['option_label'] ?: $est['doc_number'];
        foreach ($opt['siblings'] as $s) {
            Db::update('estimates', (int) $s['id'], [
                'status'         => 'DECLINED',
                'decline_reason' => 'Superseded — customer chose ' . $chosen,
                'updated_at'     => now(),
            ]);
            Audit::log('estimate', (int) $s['id'], 'declined', 'superseded by ' . $est['doc_number'] . ' (' . $chosen . ')');
        }
        if ($est['diagnostic_report_id']) {
            Audit::log('diagnostic', (int) $est['diagnostic_report_id'], 'option:chosen', $est['doc_number'] . ' — ' . $chosen);
        }

        flash($owed
            ? 'Authorization recorded — dispatch away. This estimate is over '
              . money(Rules::cfg('authorization_threshold'))
              . ', so the customer must sign the work order before any work begins.'
            : 'Authorization recorded. This estimate can now be dispatched to a technician.', 'ok');
        redirect('/estimates/' . $est['id']);
    }

    public static function decline(array $a): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        $est = self::find((int) $a['id']);
        Db::update('estimates', (int) $est['id'], [
            'status'         => 'DECLINED',
            'decline_reason' => (string) input('decline_reason', ''),
            'updated_at'     => now(),
        ]);
        Audit::log('estimate', (int) $est['id'], 'declined', (string) input('decline_reason', ''));
        flash('Estimate marked declined.', 'warn');
        redirect('/estimates/' . $est['id']);
    }

    /** Raising the work order is what actually activates a technician. */
    public static function dispatchWork(array $a): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        $est  = self::find((int) $a['id']);
        $gate = Rules::dispatchGate($est);

        if (!$gate['ok']) {
            flash($gate['reason'], 'err');
            redirect('/estimates/' . $est['id']);
        }

        // What dispatch is rolling for, carried onto the field copy. The work
        // order owns its own value from here: if the job turns out to be a
        // different category the technician corrects it there, and the two
        // together are the dispatch accuracy rate.
        $srcSr = ServiceRequestController::find((int) $est['service_request_id']);
        $woCat = ServiceCategory::coerce(
            $srcSr['service_category'] ?? null,
            (string) ($est['service_type'] ?: $srcSr['reported_service'])
        );

        $woId = Db::tx(function () use ($est, $woCat) {
            $tech = intval_or_null('technician_id');
            $id = Db::insert('work_orders', [
                'doc_number'         => DocNumber::next('WOR'),
                'estimate_id'        => (int) $est['id'],
                'service_request_id' => (int) $est['service_request_id'],
                'technician_id'      => $tech,
                'status'             => $tech ? 'ASSIGNED' : 'PENDING',
                'service_category'   => $woCat,
                'po_number'          => $est['po_number'] ?: null,   // carries forward; editable per document
                'assigned_at'        => $tech ? now() : null,
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);
            // The authorized scope carries forward to the field.
            Lines::copy('EST', (int) $est['id'], 'WO', $id);
            return $id;
        });

        Audit::log('estimate', (int) $est['id'], 'dispatched', 'Work order raised');
        Audit::log('work_order', $woId, 'created', 'From ' . $est['doc_number']);

        /* A tech assigned at dispatch gets the location link right away, same
         * as one assigned later on the work order — routing needs the truck. */
        $wo   = Db::one('SELECT * FROM work_orders WHERE id = ?', [$woId]);
        $note = '';
        $warn = false;
        if ($wo && $wo['technician_id']) {
            $out  = WorkOrderController::textTechLocateLink($wo, (int) $wo['technician_id']);
            $note = match (true) {
                (bool) ($out['sent'] ?? false) => ' Location link texted to the technician.',
                (bool) ($out['held'] ?? false) => ' Texting is not connected, so NO location link went to the technician.',
                default                        => ' Tech location link NOT sent: ' . e($out['reason']),
            };
            $warn = !($out['sent'] ?? false);
        }
        flash('Work order created and sent to the field.' . $note, $warn && $note !== '' ? 'warn' : 'ok');
        redirect('/work-orders/' . $woId);
    }

    /** Builds the invoice from the completed work order, or from the estimate. */
    public static function toInvoice(array $a): void
    {
        Auth::require();
        $est = self::find((int) $a['id']);
        $wo  = Db::one('SELECT * FROM work_orders WHERE estimate_id = ? ORDER BY id DESC', [(int) $est['id']]);

        // The office invoices anything; the technician who ran the job may
        // invoice it from the field so they can collect on the spot.
        if (Auth::is('TECHNICIAN') && !($wo && (int) $wo['technician_id'] === (int) Auth::id())) {
            Auth::requireRole('ADMIN', 'DISPATCH');
        }

        if ($exists = Db::one('SELECT * FROM invoices WHERE estimate_id = ?', [(int) $est['id']])) {
            redirect('/invoices/' . $exists['id']);
        }
        if ($wo && !in_array($wo['status'], ['COMPLETED', 'CANCELLED', 'NO_SHOW'], true)) {
            flash('Close out the work order before invoicing — the bill should reflect what was actually done.', 'err');
            redirect('/work-orders/' . $wo['id']);
        }

        $invId = Db::tx(function () use ($est, $wo) {
            // Terms are SNAPSHOTTED here, like line pricing: editing the account
            // later never changes this invoice. NULL/COD behaves like today.
            $cust = Db::one('SELECT payment_terms FROM customers WHERE id = ?', [(int) $est['customer_id']]);
            $id = Db::insert('invoices', [
                'doc_number'         => DocNumber::next('INV'),
                'service_request_id' => (int) $est['service_request_id'],
                'estimate_id'        => (int) $est['id'],
                'work_order_id'      => $wo ? (int) $wo['id'] : null,
                'customer_id'        => (int) $est['customer_id'],
                'vehicle_id'         => $est['vehicle_id'] ?: null,
                'status'             => 'DRAFT',
                // Prefer the PO as the field last knew it; fall back to the estimate's.
                'po_number'          => ($wo['po_number'] ?? null) ?: ($est['po_number'] ?: null),
                'terms'              => ($cust['payment_terms'] ?? null) ?: 'DUE_ON_RECEIPT',
                'tax_rate'           => App::taxRate(),
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);
            // Prefer what the technician actually recorded; fall back to the estimate.
            $wo ? Lines::copy('WO', (int) $wo['id'], 'INV', $id)
                : Lines::copy('EST', (int) $est['id'], 'INV', $id);
            return $id;
        });

        Audit::log('invoice', $invId, 'created', 'From ' . ($wo['doc_number'] ?? $est['doc_number']));
        redirect('/invoices/' . $invId);
    }

    /** @return array{subtotal:float,discount:float,taxable:float,tax:float,total:float} */
    public static function recalc(array $est): array
    {
        $t = Lines::totals('EST', (int) $est['id'], (float) $est['tax_rate']);
        Db::update('estimates', (int) $est['id'], [
            'subtotal'       => $t['subtotal'],
            'discount_total' => $t['discount'],
            'tax_total'      => $t['tax'],
            'total'          => $t['total'],
        ]);
        return $t;
    }

    private static function assertOpen(array $est): void
    {
        if (in_array($est['status'], ['APPROVED', 'DECLINED'], true)) {
            $wo = Db::one('SELECT id, doc_number FROM work_orders WHERE estimate_id = ?', [(int) $est['id']]);
            flash('This estimate is authorized and locked. Field additions go on '
                . ($wo ? '<a href="' . e(url('work-orders/' . (int) $wo['id'])) . '">work order '
                       . e($wo['doc_number']) . '</a>'
                       : 'the work order (dispatch one from this estimate first)')
                . ' instead.', 'err');
            redirect('/estimates/' . $est['id']);
        }
    }

    public static function find(int $id): array
    {
        $e = Db::one('SELECT * FROM estimates WHERE id = ?', [$id]);
        if (!$e) { http_response_code(404); View::render('pages/404', ['title' => 'Not found']); exit; }
        return $e;
    }
}
