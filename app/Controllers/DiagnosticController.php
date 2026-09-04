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
 * Diagnostic reports — what the technician found, written for the customer.
 *
 * Lives under the work order: /work-orders/{id}/diagnostic is the working
 * screen (the open draft, or a new one), /diagnostics/{id}/print is the
 * customer copy. A report carries no line items and is not a quote — the
 * estimate is the quote. Repair OPTIONS ("replace the pump" / "replace the
 * impeller") are estimates on the same request that point back at the report;
 * addOption() opens one, everything after that is EstimateController.
 * See Schema::diagnostic_reports for the record.
 */
final class DiagnosticController
{
    public const DRIVABILITY = [
        'SAFE'       => 'Safe to drive',
        'CAUTION'    => 'Drive with caution — repair soon',
        'DO_NOT_DRIVE' => 'Do not drive — tow or repair on site',
    ];

    /** The working screen: the open draft on this work order, or a blank one. */
    public static function edit(array $a): void
    {
        $wo  = self::workOrderFor((int) $a['id']);
        $ctx = self::context($wo);

        $draft = Db::one("SELECT * FROM diagnostic_reports WHERE work_order_id = ? AND status = 'DRAFT' ORDER BY id DESC LIMIT 1", [(int) $wo['id']]);
        $issued = Db::all("SELECT d.*, u.first_name tech_first, u.last_name tech_last
                           FROM diagnostic_reports d LEFT JOIN users u ON u.id = d.technician_id
                           WHERE d.work_order_id = ? AND d.status = 'ISSUED' ORDER BY d.id DESC", [(int) $wo['id']]);

        View::render('pages/diag_edit', [
            'title'   => 'Diagnostic report — ' . $wo['doc_number'],
            'crumb'   => 'Work Order',
            'nav'     => 'work-orders',
            'wo'      => $wo,
            'report'  => $draft,
            'issued'  => $issued,
            /* Options belong to the report the customer is looking at: the
             * open draft, or — once issued — the latest issued one. Issuing
             * freezes the words, not the quotes. */
            'optFor'  => $optFor = ($draft ?: ($issued[0] ?? null)),
            'options' => $optFor ? self::options((int) $optFor['id']) : [],
            'canQuote'=> Auth::is('ADMIN', 'DISPATCH'),
            'gate'    => $draft ? Rules::diagnosticIssueGate($draft) : ['ok' => false, 'reason' => 'Nothing saved yet.'],
            'audit'   => $draft ? Audit::for('diagnostic', (int) $draft['id']) : [],
        ] + $ctx);
    }

    /** Save the draft. Creates it on first save; a DRAFT is edited in place. */
    public static function save(array $a): void
    {
        $wo = self::workOrderFor((int) $a['id']);

        $data = [
            'concern'         => (string) input('concern', ''),
            'findings'        => (string) input('findings', ''),
            'recommendations' => (string) input('recommendations', ''),
            'drivability'     => self::drivability(),
            'internal_notes'  => (string) input('internal_notes', ''),
            'updated_at'      => now(),
        ];

        $draft = Db::one("SELECT * FROM diagnostic_reports WHERE work_order_id = ? AND status = 'DRAFT' ORDER BY id DESC LIMIT 1", [(int) $wo['id']]);
        if ($draft) {
            Db::update('diagnostic_reports', (int) $draft['id'], $data);
            Audit::log('diagnostic', (int) $draft['id'], 'saved');
            $id = (int) $draft['id'];
        } else {
            $id = Db::tx(function () use ($wo, $data): int {
                $id = Db::insert('diagnostic_reports', $data + [
                    'doc_number'    => DocNumber::next('DIA'),
                    'work_order_id' => (int) $wo['id'],
                    /* The technician of record is whoever holds the job, not
                     * whoever is typing — dispatch may transcribe a phone call. */
                    'technician_id' => $wo['technician_id'] ? (int) $wo['technician_id'] : Auth::id(),
                    'status'        => 'DRAFT',
                    'created_at'    => now(),
                ]);
                Audit::log('diagnostic', $id, 'created', 'on ' . $wo['doc_number']);
                Audit::log('work_order', (int) $wo['id'], 'diagnostic:created', '#' . $id);
                return $id;
            });
        }
        flash('Diagnostic report saved.');
        redirect('/work-orders/' . $wo['id'] . '/diagnostic');
    }

    /** Freeze the draft and make it the customer's copy. */
    public static function issue(array $a): void
    {
        $r  = self::find((int) $a['id']);
        $wo = self::workOrderFor((int) $r['work_order_id']);

        $gate = Rules::diagnosticIssueGate($r);
        if (!$gate['ok']) {
            flash($gate['reason'], 'err');
            redirect('/work-orders/' . $wo['id'] . '/diagnostic');
        }
        Db::update('diagnostic_reports', (int) $r['id'], [
            'status' => 'ISSUED', 'issued_at' => now(), 'issued_by' => Auth::id(), 'updated_at' => now(),
        ]);
        Audit::log('diagnostic', (int) $r['id'], 'issued');
        Audit::log('work_order', (int) $wo['id'], 'diagnostic:issued', $r['doc_number']);
        flash('Report ' . $r['doc_number'] . ' issued. Print it or save it as a PDF for the customer.');
        redirect('/diagnostics/' . $r['id'] . '/print');
    }

    /**
     * Open a repair option: a new estimate on the same request, same customer
     * and vehicle, tagged with this report. It starts empty — lines go on
     * through the estimate page exactly like any other quote.
     */
    public static function addOption(array $a): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        $r   = self::find((int) $a['id']);
        $wo  = self::workOrderFor((int) $r['work_order_id']);
        $est = EstimateController::find((int) $wo['estimate_id']);

        $label = trim((string) input('option_label', ''));
        if ($label === '') {
            flash('Name the option — "Replace pump" or "Replace impeller only" — so the customer can tell them apart.', 'err');
            redirect('/work-orders/' . $wo['id'] . '/diagnostic');
        }

        $id = Db::tx(function () use ($r, $wo, $est, $label): int {
            $id = Db::insert('estimates', [
                'doc_number'           => DocNumber::next('EST'),
                'service_request_id'   => (int) $est['service_request_id'],
                'customer_id'          => (int) $est['customer_id'],
                'vehicle_id'           => $est['vehicle_id'] ? (int) $est['vehicle_id'] : null,
                'status'               => 'DRAFT',
                'service_type'         => $est['service_type'],
                'po_number'            => $est['po_number'],
                'scope_summary'        => $label,
                'nearest_address'      => $est['nearest_address'],
                'city'                 => $est['city'],
                'state'                => $est['state'],
                'postal_code'          => $est['postal_code'],
                'latitude'             => $est['latitude'],
                'longitude'            => $est['longitude'],
                'nearest_intersection' => $est['nearest_intersection'],
                'location_captured_at' => $est['location_captured_at'],
                /* Fresh rate and terms, not the diagnostic visit's: this is a
                 * new quote, priced today. */
                'tax_rate'             => App::taxRate(),
                'terms_text'           => (string) App::setting('estimate_terms', ''),
                'diagnostic_report_id' => (int) $r['id'],
                'option_label'         => $label,
                'option_timeframe'     => trim((string) input('option_timeframe', '')) ?: null,
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);
            Audit::log('estimate', $id, 'created', 'option "' . $label . '" on ' . $r['doc_number']);
            Audit::log('diagnostic', (int) $r['id'], 'option:added', $label);
            return $id;
        });
        flash('Option "' . e($label) . '" opened. Add the parts and labor, then come back to the report.');
        redirect('/estimates/' . $id);
    }

    /**
     * The customer copy. Findings, recommendation, drivability, and the
     * repair options side by side — price only. Never internal_notes, never
     * cost or margin: nothing this page receives carries them.
     */
    public static function printable(array $a): void
    {
        $r  = self::find((int) $a['id']);
        $wo = self::workOrderFor((int) $r['work_order_id']);
        $tech = $r['technician_id'] ? Db::one('SELECT first_name, last_name FROM users WHERE id = ?', [(int) $r['technician_id']]) : null;

        View::render('pages/diag_print', [
            'title'   => $r['doc_number'], '__bare' => true,
            'report'  => $r,
            'wo'      => $wo,
            'tech'    => $tech,
            /* Declined options stay off the customer copy — a superseded
             * option is history, not a choice. */
            'options' => array_values(array_filter(self::options((int) $r['id']), fn($o) => $o['status'] !== 'DECLINED')),
        ] + self::context($wo));
    }

    /* ------------------------------------------------------------------ */

    /** The work order, with the same ownership rule WorkOrderController::show applies. */
    private static function workOrderFor(int $id): array
    {
        Auth::require();
        $wo = WorkOrderController::find($id);
        if (Auth::is('TECHNICIAN') && (int) $wo['technician_id'] !== Auth::id()) {
            Auth::requireRole('ADMIN', 'DISPATCH');
        }
        return $wo;
    }

    /** Customer and vehicle come from the estimate — never the request. */
    private static function context(array $wo): array
    {
        $est = EstimateController::find((int) $wo['estimate_id']);
        return [
            'est'      => $est,
            'sr'       => ServiceRequestController::find((int) $wo['service_request_id']),
            'customer' => Db::one('SELECT * FROM customers WHERE id = ?', [(int) $est['customer_id']]),
            'vehicle'  => $est['vehicle_id'] ? Db::one('SELECT * FROM vehicles WHERE id = ?', [(int) $est['vehicle_id']]) : null,
        ];
    }

    /**
     * The options on a report, each with its customer-facing lines (name,
     * qty, unit price, total — the same columns doc_print shows). Cost and
     * markup columns exist on the rows and are deliberately not selected:
     * this array feeds the customer copy.
     */
    public static function options(int $reportId): array
    {
        $opts = Db::all('SELECT * FROM estimates WHERE diagnostic_report_id = ? ORDER BY id', [$reportId]);
        foreach ($opts as &$o) {
            $o['lines'] = Db::all(
                'SELECT name, sku, qty, uom, unit_price, line_total, notes, warranty_months, mfr_warranty
                 FROM doc_lines WHERE doc_type = ? AND doc_id = ? ORDER BY line_no, id',
                ['EST', (int) $o['id']]);
        }
        return $opts;
    }

    private static function drivability(): ?string
    {
        $v = (string) input('drivability', '');
        return isset(self::DRIVABILITY[$v]) ? $v : null;
    }

    public static function find(int $id): array
    {
        /* Sign-in before the lookup, so a stranger probing /diagnostics/N
         * gets the same redirect for every N and learns nothing. */
        Auth::require();
        $r = Db::one('SELECT * FROM diagnostic_reports WHERE id = ?', [$id]);
        if (!$r) { http_response_code(404); View::render('pages/404', ['title' => 'Not found']); exit; }
        return $r;
    }
}
