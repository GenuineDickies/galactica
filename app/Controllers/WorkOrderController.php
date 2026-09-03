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

final class WorkOrderController
{
    /**
     * Technician-driven flow. Dispatch assigns; the tech drives everything after.
     *
     * IN_PROGRESS is its own step because "no work begins without a signed
     * estimate" needs a moment to attach to. ON_SITE means arrived — the tech
     * still has to price the real scope and get the signature. Beginning work
     * is a deliberate, gated, timestamped act.
     */
    public const FLOW = ['PENDING', 'ASSIGNED', 'EN_ROUTE', 'ON_SITE', 'IN_PROGRESS', 'COMPLETED'];
    public const OUTCOMES = [
        'COMPLETED'             => 'Completed',
        'ATTEMPTED_UNSUCCESSFUL'=> 'Attempted — unsuccessful (billable)',
        'CUSTOMER_NO_SHOW'      => 'Customer no-show',
        'UNSAFE'                => 'Unsafe to proceed',
        'TOW_REQUIRED'          => 'Tow required — out of scope',
    ];

    public static function index(): void
    {
        Auth::require();
        $sql = "SELECT w.*, e.doc_number est_no, e.service_type, e.city, e.state,
                       s.doc_number sr_no, s.priority,
                       c.first_name, c.last_name, c.company,
                       u.first_name tech_first, u.last_name tech_last
                FROM work_orders w
                JOIN estimates e         ON e.id = w.estimate_id
                JOIN service_requests s  ON s.id = w.service_request_id
                JOIN customers c         ON c.id = e.customer_id
                LEFT JOIN users u        ON u.id = w.technician_id";
        $args = [];
        if (Auth::is('TECHNICIAN')) { $sql .= ' WHERE w.technician_id = ?'; $args[] = Auth::id(); }
        $sql .= ' ORDER BY w.id DESC LIMIT 200';

        View::render('pages/wo_index', [
            'title' => Auth::is('TECHNICIAN') ? 'My Work Orders' : 'Work Orders',
            'crumb' => 'Field', 'nav' => 'work-orders', 'rows' => Db::all($sql, $args),
        ]);
    }

    /* createFromEstimate was deleted 2026-08-27: no route referenced it, and
     * it carried a second, drifted copy of the dispatch gate (it checked
     * status = APPROVED where Rules::dispatchGate checks lines and
     * authorized_at). Dispatching happens from the estimate, through the one
     * gate — a rule may not live twice. */

    public static function show(array $a): void
    {
        Auth::require();
        $wo = self::find((int) $a['id']);
        if (Auth::is('TECHNICIAN') && (int) $wo['technician_id'] !== Auth::id()) {
            Auth::requireRole('ADMIN', 'DISPATCH');
        }
        $sr  = ServiceRequestController::find((int) $wo['service_request_id']);
        $est = EstimateController::find((int) $wo['estimate_id']);

        View::render('pages/wo_show', [
            'title'    => $wo['doc_number'],
            'crumb'    => 'Work Order',
            'nav'      => 'work-orders',
            'wo'       => $wo,
            'sr'       => $sr,
            'est'      => $est,
            'customer' => Db::one('SELECT * FROM customers WHERE id = ?', [(int) $est['customer_id']]),
            'vehicle'  => $est['vehicle_id'] ? Db::one('SELECT * FROM vehicles WHERE id = ?', [(int) $est['vehicle_id']]) : null,
            'lines'    => Lines::forDoc('WO', (int) $wo['id']),
            /* The estimate's snapshotted rate, never the live setting — the
             * signature flow already did this, and a rate change mid-job must
             * not show the customer two different totals (fixed 2026-08-27). */
            'totals'   => Lines::totals('WO', (int) $wo['id'], (float) $est['tax_rate']),
            'catalog'  => Db::all('SELECT * FROM catalog_items WHERE is_active = 1 ORDER BY item_type, category, name'),
            'techs'    => Db::all("SELECT * FROM users WHERE role IN ('TECHNICIAN','ADMIN') AND is_active = 1 AND can_accept_jobs = 1 ORDER BY first_name"),
            'photos'   => Db::all("SELECT * FROM attachments WHERE entity_type = 'work_order' AND entity_id = ? ORDER BY id", [(int) $wo['id']]),
            'invoice'  => Db::one('SELECT * FROM invoices WHERE estimate_id = ?', [(int) $est['id']]),
            /* Wrapped: diagnostic_reports postdates most installs, and the
             * page must not fall over before /admin/schema has been run. */
            'diagDraft'  => (static function () use ($wo): ?array {
                try { return Db::one("SELECT id FROM diagnostic_reports WHERE work_order_id = ? AND status = 'DRAFT' ORDER BY id DESC LIMIT 1", [(int) $wo['id']]); }
                catch (Throwable) { return null; }
            })(),
            'diagIssued' => (static function () use ($wo): array {
                try { return Db::all("SELECT id, doc_number, issued_at FROM diagnostic_reports WHERE work_order_id = ? AND status = 'ISSUED' ORDER BY id DESC", [(int) $wo['id']]); }
                catch (Throwable) { return []; }
            })(),
            'gate'     => Rules::workOrderCompletionGate($wo, $est),
            'audit'    => Audit::for('work_order', (int) $wo['id']),
            // An outstanding texted link, so the technician can see whether the
            // customer has opened it yet rather than guessing.
            'authReq'  => SignatureRequest::openFor('WO', (int) $wo['id'], 'AUTH'),
            'doneReq'  => SignatureRequest::openFor('WO', (int) $wo['id'], 'COMPLETION'),
        ]);
    }

    public static function assign(array $a): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        $wo   = self::find((int) $a['id']);
        if (in_array($wo['status'], ['COMPLETED', 'CANCELLED', 'NO_SHOW'], true)) {
            flash('A closed work order cannot be assigned or reopened.', 'err');
            redirect('/work-orders/' . $wo['id']);
        }
        $tech = intval_or_null('technician_id');
        if (!$tech) {
            flash('A technician is required to dispatch this work order.', 'err');
            redirect('/work-orders/' . $wo['id']);
        }
        Db::update('work_orders', (int) $wo['id'], [
            'technician_id' => $tech, 'status' => 'ASSIGNED', 'assigned_at' => now(), 'updated_at' => now(),
        ]);
        Audit::log('work_order', (int) $wo['id'], 'assigned', 'technician #' . $tech);

        /* The tech gets the same one-shot location link a customer gets, so
         * routing can start from where the truck actually is. Their answer
         * lands on this work order (tech_latitude/longitude), and the ETA
         * suggestion on this page uses it. */
        $out  = self::textTechLocateLink($wo, $tech);
        flash('Work order dispatched to the technician.' . match (true) {
            (bool) ($out['sent'] ?? false) => ' Location link texted — their position will appear here when they answer.',
            (bool) ($out['held'] ?? false) => ' Texting is not connected, so NO location link went out — the ETA will need to be entered by hand.',
            default                        => ' Location link NOT sent: ' . e($out['reason']),
        }, ($out['sent'] ?? false) ? 'ok' : 'warn');
        redirect('/work-orders/' . $wo['id']);
    }

    /** Re-send from the work order page — links are one-shot and expire. */
    public static function sendTechLocateLink(array $a): void
    {
        Auth::require();
        $wo = self::authorized((int) $a['id']);
        if (!$wo['technician_id']) {
            flash('No technician is assigned yet — assign one first.', 'err');
            redirect('/work-orders/' . $wo['id']);
        }
        $out = self::textTechLocateLink($wo, (int) $wo['technician_id']);
        flash(match (true) {
            (bool) ($out['sent'] ?? false) => 'Location link texted to the technician.',
            (bool) ($out['held'] ?? false) => 'Texting is not connected, so NO link went out — the message is in the outbox.',
            default                        => 'Location link blocked: ' . e($out['reason']) . ' Nothing was sent.',
        }, ($out['sent'] ?? false) ? 'ok' : 'warn');
        redirect('/work-orders/' . $wo['id']);
    }

    /** Public: EstimateController::dispatchWork assigns a tech at WO creation and sends the same link. */
    public static function textTechLocateLink(array $wo, int $techId): array
    {
        $user  = Db::one('SELECT * FROM users WHERE id = ?', [$techId]) ?? [];
        $phone = phone_to_e164((string) ($user['phone_e164'] ?? '')) ?: '';

        if ($phone === '' || (int) ($user['is_active'] ?? 0) !== 1) {
            // Record the blocked attempt like any other blocked send.
            $gate = Sms::queueToTech($user, 'tech_locate', ['{doc}' => $wo['doc_number'], '{link}' => '']);
            Audit::log('work_order', (int) $wo['id'], 'location:link', 'tech link blocked — ' . $gate['reason']);
            return $gate;
        }

        $req  = LocationRequest::issue('WO', (int) $wo['id'], $phone, (int) $wo['service_request_id'], null);
        $gate = Sms::queueToTech($user, 'tech_locate',
            ['{doc}' => $wo['doc_number'], '{link}' => LocationRequest::url($req)],
            (int) $wo['service_request_id']);
        if ($gate['sent'] ?? false) { Db::update('location_requests', (int) $req['id'], ['sent_at' => now()]); }

        Audit::log('work_order', (int) $wo['id'], 'location:link', match (true) {
            (bool) ($gate['sent'] ?? false) => 'tech location link texted to ' . $phone,
            (bool) ($gate['held'] ?? false) => 'tech location link held in outbox for ' . $phone,
            default                         => 'tech link NOT SENT — ' . $gate['reason'],
        });
        return $gate;
    }

    /** Deliberate under-promise on the suggested ETA; the raw drive time is what goes on record. */
    public const ETA_PAD_MINUTES = 5;

    /**
     * Live route suggestion for the En route form: truck pin → job pin,
     * actual road miles and minutes from the routing driver. Snapshotted onto
     * the work order the moment they are calculated — a matter of record,
     * independent of whether a text is later sent. JSON; the calculation
     * never lives in the browser (same doctrine as /pricing/suggest).
     */
    public static function etaSuggest(array $a): void
    {
        Auth::require();
        header('Content-Type: application/json');
        $wo = self::authorized((int) $a['id']);

        if (!$wo['tech_latitude'] || !$wo['tech_longitude']) {
            echo json_encode(['ok' => false, 'error' => 'The technician has not shared their location yet — re-text them the link, or enter the minutes by hand.']);
            return;
        }

        $sr    = ServiceRequestController::find((int) $wo['service_request_id']);
        $est   = EstimateController::find((int) $wo['estimate_id']);
        $toLat = $sr['latitude'] ?: ($est['latitude'] ?? null);
        $toLng = $sr['longitude'] ?: ($est['longitude'] ?? null);
        if (!$toLat || !$toLng) {
            echo json_encode(['ok' => false, 'error' => 'The job has no location pin — capture the customer\'s GPS first, or enter the minutes by hand.']);
            return;
        }

        $planner = Integrations::routes();
        $route   = $planner->route((float) $wo['tech_latitude'], (float) $wo['tech_longitude'], (float) $toLat, (float) $toLng);
        if (!$route['ok']) {
            echo json_encode(['ok' => false, 'error' => $route['reason']]);
            return;
        }

        Db::update('work_orders', (int) $wo['id'], [
            'drive_miles'         => sprintf('%.1F', (float) $route['miles']),
            'drive_minutes'       => (int) $route['minutes'],
            'route_driver'        => $planner->driverName(),
            'route_calculated_at' => now(),
            'updated_at'          => now(),
        ]);
        Audit::log('work_order', (int) $wo['id'], 'route:calculated',
            $route['miles'] . ' mi · ' . $route['minutes'] . ' min drive, from the tech\'s position shared at '
            . (string) $wo['tech_located_at'] . ' · ' . $planner->driverName());

        $suggest = (int) $route['minutes'] + self::ETA_PAD_MINUTES;
        echo json_encode([
            'ok'            => true,
            'miles'         => $route['miles'],
            'drive_minutes' => (int) $route['minutes'],
            'eta_minutes'   => $suggest,
            'eta_clock'     => fdate(date('Y-m-d H:i:s', time() + $suggest * 60), 'g:i A'),
            'located_at'    => fdate((string) $wo['tech_located_at'], 'g:i A'),
        ]);
    }

    public static function status(array $a): void
    {
        Auth::require();
        $wo  = self::authorized((int) $a['id']);
        $new = strtoupper((string) input('status', ''));

        // COMPLETED is deliberately NOT reachable here: closing a job runs
        // gates (signed estimate, signature ordering, VIN) that live in
        // complete(). Allowing it through the plain status route would walk
        // straight past them.
        $allowed = Rules::workOrderTransitions((string) $wo['status']);
        if (!in_array($new, $allowed, true)) {
            flash('A ' . e(status_label((string) $wo['status'])) . ' work order can\'t jump straight to '
                . e(status_label($new)) . '. From here it can go to: '
                . e(implode(', ', array_map('status_label', $allowed)) ?: 'nowhere — it is closed')
                . '. The steps exist so the job\'s timeline reads true afterwards.', 'err');
            redirect('/work-orders/' . $wo['id']);
        }

        // The signed estimate gates the start of work, not arrival.
        if ($new === 'IN_PROGRESS') {
            $est  = EstimateController::find((int) $wo['estimate_id']);
            $gate = Rules::workBeginsGate($est, $wo);
            if (!$gate['ok']) {
                flash($gate['reason'], 'err');
                redirect('/work-orders/' . $wo['id']);
            }
        }

        $stamp = [
            'EN_ROUTE'    => 'en_route_at',
            'ON_SITE'     => 'on_site_at',
            'IN_PROGRESS' => 'work_started_at',
        ][$new] ?? null;
        $data  = ['status' => $new, 'updated_at' => now()];
        if ($stamp) { $data[$stamp] = now(); }
        if ($new === 'NO_SHOW') { $data['outcome_code'] = 'CUSTOMER_NO_SHOW'; $data['completed_at'] = now(); }
        Db::update('work_orders', (int) $wo['id'], $data);
        Audit::log('work_order', (int) $wo['id'], 'status:' . $new,
            $new === 'IN_PROGRESS' ? 'work started on the vehicle — estimate signed beforehand' : '');

        $note  = '';
        $class = 'ok';
        if (in_array($new, ['EN_ROUTE', 'ON_SITE'], true)) {
            /* The dispatcher decides per send. This used to text the customer
             * automatically on every en-route/on-site click — with the ETA
             * copied from the promise made at intake, which by en-route time
             * is exactly when it is most likely stale (a "2:30 PM" promised
             * yesterday reads as a fresh promise, because {eta} is formatted
             * as clock time). Now the status form carries the decision
             * (send_sms) and a fresh ETA in minutes from right now; the
             * intake promise stays on the request as the original promise
             * and is never texted from here again. The flash reports what
             * actually happened — nothing sends silently. */
            if ((int) input('send_sms', 0) === 1) {
                $sr  = ServiceRequestController::find((int) $wo['service_request_id']);
                $est = EstimateController::find((int) $wo['estimate_id']);
                $c   = Db::one('SELECT * FROM customers WHERE id = ?', [(int) $est['customer_id']]);
                $mins = max(0, (int) input('eta_minutes', 0));
                $eta  = $mins > 0 ? fdate(date('Y-m-d H:i:s', time() + $mins * 60), 'g:i A') : 'shortly';
                /* "En route" and "arrived" are for whoever is standing next to
                 * the vehicle. On a provider job that is the caller, not the
                 * account being billed — queueOnScene decides, once, for both. */
                $sms = Sms::queueOnScene($sr, $c, $new === 'EN_ROUTE' ? 'dispatch' : 'on_site',
                    ['{eta}' => $eta, '{total}' => '', '{doc}' => $sr['doc_number']]);
                Audit::log('work_order', (int) $wo['id'], 'sms:' . strtolower($new),
                    (($sms['sent'] ?? false) ? 'texted' : (($sms['held'] ?? false) ? 'held in outbox' : 'blocked — ' . $sms['reason']))
                    . ($new === 'EN_ROUTE' ? ', ETA ' . $eta : ''));
                $note = match (true) {
                    (bool) ($sms['sent'] ?? false) => ' Texted the caller' . ($new === 'EN_ROUTE' ? ' — ETA ' . e($eta) : '') . '.',
                    (bool) ($sms['held'] ?? false) => ' The text was queued in the outbox — texting is not connected, so nothing reached the customer.',
                    default                        => ' The text was NOT sent: ' . e($sms['reason']),
                };
                $class = ($sms['ok'] ?? false) ? 'ok' : 'warn';
            } else {
                $note = ' No text was sent.';
            }
        }
        flash('Status updated to <strong>' . e(status_label($new)) . '</strong>.' . $note, $class);
        redirect('/work-orders/' . $wo['id']);
    }

    /* A closed work order is an evidence record — the customer's completion
     * signature was captured over exactly these lines, so they may not change
     * afterwards (fixed 2026-08-27: add/remove had no closed-status guard
     * while assign and setPo did). Corrections go on the invoice. */
    private static function assertOpenForLines(array $wo): void
    {
        if (in_array($wo['status'], ['COMPLETED', 'CANCELLED', 'NO_SHOW'], true)) {
            flash('This work order is closed — its lines are what the customer signed off on. '
                . 'Make corrections on ' . self::invoiceLink($wo) . '.', 'err');
            redirect('/work-orders/' . $wo['id']);
        }
    }

    /** "the invoice" as a live link when one exists, plain guidance when not. */
    private static function invoiceLink(array $wo): string
    {
        $inv = Db::one('SELECT id, doc_number FROM invoices WHERE estimate_id = ?', [(int) $wo['estimate_id']]);
        return $inv
            ? '<a href="' . e(url('invoices/' . (int) $inv['id'])) . '">invoice ' . e($inv['doc_number']) . '</a>'
            : 'the invoice (raise it from the estimate first)';
    }

    public static function addLine(array $a): void
    {
        Auth::require();
        $wo = self::authorized((int) $a['id']);
        self::assertOpenForLines($wo);
        $itemId = intval_or_null('catalog_item_id');
        if (!$itemId) {
            flash('Pick an item from the catalog.', 'err');
            redirect('/work-orders/' . $wo['id']);
        }
        try {
            Lines::add('WO', (int) $wo['id'], $itemId, num('qty', 1), price_or_null(), (string) input('line_notes', ''), price_or_null('unit_cost'), overridden_flag(), misc_name());
        } catch (RuntimeException $e) {
            flash($e->getMessage(), 'err');
            redirect('/work-orders/' . $wo['id']);
        }
        Audit::log('work_order', (int) $wo['id'], 'line:added', 'added in the field');
        flash('Line added. If this pushes the total past the authorized amount, the invoice will require re-authorization.', 'info');
        redirect('/work-orders/' . $wo['id']);
    }

    public static function delLine(array $a): void
    {
        Auth::require();
        $wo = self::authorized((int) $a['id']);
        self::assertOpenForLines($wo);
        Lines::remove('WO', (int) $wo['id'], (int) $a['lineId']);
        Audit::log('work_order', (int) $wo['id'], 'line:removed');
        redirect('/work-orders/' . $wo['id']);
    }

    /** PO number, editable while the work order is still open in the field. */
    public static function setPo(array $a): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        $wo = self::find((int) $a['id']);
        if (in_array($wo['status'], ['COMPLETED', 'CANCELLED', 'NO_SHOW'], true)) {
            flash('This work order is closed — record the PO on ' . self::invoiceLink($wo) . ' instead.', 'err');
            redirect('/work-orders/' . $wo['id']);
        }
        $po = trim((string) input('po_number', ''));
        Db::update('work_orders', (int) $wo['id'], ['po_number' => $po ?: null, 'updated_at' => now()]);
        Audit::log('work_order', (int) $wo['id'], 'po:set', ($wo['po_number'] ?: '—') . ' → ' . ($po ?: '—'));
        flash($po ? 'PO number saved.' : 'PO number cleared.', 'ok');
        redirect('/work-orders/' . $wo['id']);
    }

    /**
     * Correct the operational category once the job is actually seen.
     *
     * A caller says "flat tire" and dispatch rolls roadside; the technician
     * arrives and the puncture is in the shoulder, so the tire has to come off
     * the wheel and the job is Mobile Tire. This is that correction. It is
     * allowed to the technician on their own job — they are the one who can
     * see it — and it never touches the request, because what dispatch decided
     * is the other half of the measurement.
     */
    public static function recategorise(array $a): void
    {
        $wo = self::find((int) $a['id']);
        if (Auth::is('TECHNICIAN') && (int) $wo['technician_id'] !== Auth::id()) {
            Auth::requireRole('ADMIN', 'DISPATCH');
        }
        if (in_array($wo['status'], ['CANCELLED'], true)) {
            flash('A cancelled work order is not recategorised.', 'err');
            redirect('/work-orders/' . $wo['id']);
        }

        $was = (string) ($wo['service_category'] ?? '');
        $now = (string) input('service_category', '');
        if (!ServiceCategory::isValid($now)) {
            flash('That is not one of the service categories — pick one from the list on this page.', 'err');
            redirect('/work-orders/' . $wo['id']);
        }
        if ($now === $was) {
            flash('Category unchanged.', 'ok');
            redirect('/work-orders/' . $wo['id']);
        }

        Db::update('work_orders', (int) $wo['id'], [
            'service_category' => $now, 'updated_at' => now(),
        ]);
        Audit::log('work_order', (int) $wo['id'], 'category:changed',
            ServiceCategory::label($was) . ' → ' . ServiceCategory::label($now)
            . ((string) input('why', '') !== '' ? ' — ' . input('why') : ''));

        flash('Recorded as ' . ServiceCategory::label($now)
            . '. What dispatch rolled for is left as it was.', 'ok');
        redirect('/work-orders/' . $wo['id']);
    }

    /** The driver is responsible for capturing the VIN, not the customer. */
    public static function captureVin(array $a): void
    {
        $wo = self::find((int) $a['id']);
        if (Auth::is('TECHNICIAN') && (int) $wo['technician_id'] !== Auth::id()) {
            Auth::requireRole('ADMIN', 'DISPATCH');
        }
        EstimateController::attachVehicle(['id' => (string) $wo['estimate_id']], '/work-orders/' . $wo['id']);
    }

    /**
     * The customer signs on the technician's device, standing there.
     *
     * The "display for customer" path: the technician turns the screen around,
     * the customer reads the work order and signs. Nothing is texted and
     * nothing is waited on.
     */
    public static function signInPerson(array $a): void
    {
        Auth::require();
        $wo = self::authorized((int) $a['id']);

        $sig  = (string) input('signature_data', '');
        $name = trim((string) input('signer_name', ''));

        if ($sig === '') {
            flash('Nothing was signed. Have the customer sign before starting work.', 'err');
            redirect('/work-orders/' . $wo['id']);
        }
        if ($name === '') {
            flash('Record the name of the person signing.', 'err');
            redirect('/work-orders/' . $wo['id']);
        }

        self::recordAuthSignature($wo, $sig, $name, 'IN_PERSON');
        SignatureRequest::voidOpenFor('WO', (int) $wo['id'], 'AUTH');
        flash('Signature captured. Work may begin.', 'ok');
        redirect('/work-orders/' . $wo['id']);
    }

    /**
     * Text the customer a link to review and sign the work order.
     *
     * For when they are not on scene. Consent is checked before anything is
     * sent — Sms::queue() refuses without it and records the block — so a
     * customer who never opted in cannot be texted even by accident.
     */
    public static function sendSignLink(array $a): void
    {
        Auth::require();
        $wo      = self::authorized((int) $a['id']);
        $est     = EstimateController::find((int) $wo['estimate_id']);
        $sr      = ServiceRequestController::find((int) $wo['service_request_id']);
        $cust    = Db::one('SELECT * FROM customers WHERE id = ?', [(int) $est['customer_id']]);
        $purpose = strtoupper((string) input('purpose', 'AUTH')) === 'COMPLETION' ? 'COMPLETION' : 'AUTH';

        $gate = Sms::gate($cust);
        if (!$gate['ok']) {
            flash('Cannot text this customer: ' . $gate['reason']
                . ' Show the work order on your device and take the signature in person instead.', 'err');
            redirect('/work-orders/' . $wo['id']);
        }

        $totals = Lines::totals('WO', (int) $wo['id'], (float) $est['tax_rate']);
        $req    = SignatureRequest::issue('WO', (int) $wo['id'], $purpose, $cust, (float) $totals['total']);

        /* The customer this link goes to is usually NOT on scene — keys left,
         * went home — and they are waiting on this text to get their vehicle
         * back. "Link texted" may only be said when the carrier took it. An
         * earlier version flashed it unconditionally, which meant a failed
         * send left the customer watching a phone that would never buzz and
         * the technician believing the ball was in the customer's court. */
        $out = Sms::queue($cust, $purpose === 'AUTH' ? 'sign_auth' : 'sign_done', [
            '{doc}'   => $wo['doc_number'],
            '{total}' => money((float) $totals['total']),
            '{link}'  => SignatureRequest::url($req),
        ], (int) $sr['id']);

        if ($out['sent']) { Db::update('signature_requests', (int) $req['id'], ['sent_at' => now()]); }
        Audit::log('work_order', (int) $wo['id'], 'signature:requested',
            SignatureRequest::PURPOSES[$purpose]
            . ($out['sent'] ? ' texted to ' : ($out['held'] ? ' held in outbox for ' : ' NOT SENT to '))
            . $cust['phone_e164'] . ($out['reason'] !== '' ? ' — ' . $out['reason'] : ''));

        if ($out['sent']) {
            flash('Link texted to ' . e((string) $cust['phone_e164'])
                . '. You will see it here the moment they sign.', 'ok');
        } elseif ($out['held']) {
            flash('Texting is not connected, so no link went to the customer. '
                . 'Take the signature in person on your device — that is the working path.', 'warn');
        } else {
            flash('The link did NOT reach the customer: ' . e($out['reason'])
                . ' Call them, or take the signature in person — do not leave them waiting on a text.', 'err');
        }
        redirect('/work-orders/' . $wo['id']);
    }

    /** Write an authorization signature, however it arrived. */
    public static function recordAuthSignature(array $wo, string $sig, string $name, string $method): void
    {
        Db::update('work_orders', (int) $wo['id'], [
            'auth_signer_name' => $name,
            'auth_signature'   => $sig,
            'auth_signed_at'   => now(),
            'auth_method'      => $method,
            'auth_ip'          => $_SERVER['REMOTE_ADDR'] ?? null,
            'auth_agent'       => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250),
            'updated_at'       => now(),
        ]);
        Audit::log('work_order', (int) $wo['id'], 'authorization:signed',
            $name . ' · ' . ($method === 'SMS' ? 'signed remotely via texted link' : 'signed in person on the technician\'s device')
            . ' · IP ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    }

    /** The technician who owns this job, or a dispatcher/admin. */
    private static function authorized(int $id): array
    {
        $wo = self::find($id);
        if (Auth::is('TECHNICIAN') && (int) $wo['technician_id'] !== Auth::id()) {
            Auth::requireRole('ADMIN', 'DISPATCH');
        }
        return $wo;
    }

    public static function complete(array $a): void
    {
        Auth::require();
        $wo   = self::authorized((int) $a['id']);
        $est  = EstimateController::find((int) $wo['estimate_id']);
        $gate = Rules::workOrderCompletionGate($wo, $est);

        if (!$gate['ok']) {
            flash($gate['reason'], 'err');
            redirect('/work-orders/' . $wo['id']);
        }

        // The completion sign-off is asked for insistently but never forced —
        // a customer cannot be compelled to agree the job was done well. When
        // it is absent the reason is recorded, so "unsigned" is a documented
        // outcome rather than a silent gap.
        $sig    = (string) input('signature_data', '');
        $reason = trim((string) input('unsigned_reason', ''));
        if ($sig === '' && $reason === '') {
            flash('Ask the customer to sign off on the completed work. If they will not or cannot, record why — it cannot simply be left blank.', 'err');
            redirect('/work-orders/' . $wo['id']);
        }

        Db::update('work_orders', (int) $wo['id'], [
            'status'          => 'COMPLETED',
            'outcome_code'    => (string) input('outcome_code', 'COMPLETED'),
            'completed_at'    => now(),
            'odometer'        => intval_or_null('odometer'),
            'field_notes'     => (string) input('field_notes', $wo['field_notes'] ?? ''),
            'signer_name'     => (string) input('signer_name', ''),
            'signature_data'  => $sig ?: null,
            'signed_at'       => $sig !== '' ? now() : null,
            'signed_method'   => $sig !== '' ? 'IN_PERSON' : null,
            'unsigned_reason' => $sig === '' ? $reason : null,
            'updated_at'      => now(),
        ]);
        SignatureRequest::voidOpenFor('WO', (int) $wo['id'], 'COMPLETION');
        Audit::log('work_order', (int) $wo['id'], 'completed',
            (string) input('outcome_code', 'COMPLETED')
            . ($sig !== '' ? ' · customer signed off' : ' · NOT signed off: ' . $reason));
        flash($sig !== ''
            ? 'Work order completed and signed off. Ready to invoice.'
            : 'Work order completed without a customer sign-off — the reason is on the record. Ready to invoice.', $sig !== '' ? 'ok' : 'warn');
        redirect('/work-orders/' . $wo['id']);
    }

    public static function photo(array $a): void
    {
        Auth::require();
        $wo = self::authorized((int) $a['id']);
        $f  = $_FILES['photo'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            flash('No photo was received — choose an image file first, then press Upload. Very large photos can also fail; if it keeps happening, try a smaller one.', 'err');
            redirect('/work-orders/' . $wo['id']);
        }
        $ok = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $mime = (string) (mime_content_type($f['tmp_name']) ?: '');
        if (!isset($ok[$mime])) {
            flash('Only JPG, PNG or WEBP images are accepted.', 'err');
            redirect('/work-orders/' . $wo['id']);
        }
        $label = strtoupper((string) input('label', 'SITE'));
        $seq   = (int) Db::val("SELECT COUNT(*)+1 FROM attachments WHERE entity_type='work_order' AND entity_id=?", [(int) $wo['id']]);
        $name  = sprintf('%s-%s-%03d-%s.%s', $wo['doc_number'], date('Ymd'), $seq, $label, $ok[$mime]);
        $dir   = dirname(__DIR__, 2) . '/storage/uploads';
        if (!is_dir($dir)) { mkdir($dir, 0775, true); }
        move_uploaded_file($f['tmp_name'], $dir . '/' . $name);

        Db::insert('attachments', [
            'entity_type' => 'work_order', 'entity_id' => (int) $wo['id'], 'kind' => 'PHOTO',
            'label' => $label, 'filename' => $name, 'stored_path' => 'storage/uploads/' . $name,
            'mime' => $mime, 'bytes' => (int) $f['size'], 'created_at' => now(),
        ]);
        Audit::log('work_order', (int) $wo['id'], 'photo:' . $label, $name);
        flash('Photo attached to the evidence record.', 'ok');
        redirect('/work-orders/' . $wo['id']);
    }

    public static function find(int $id): array
    {
        $w = Db::one('SELECT * FROM work_orders WHERE id = ?', [$id]);
        if (!$w) { http_response_code(404); View::render('pages/404', ['title' => 'Not found']); exit; }
        return $w;
    }
}
