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
 * 1. SERVICE REQUEST
 *
 * The initial record that a person needs service. It may arrive by phone, from
 * the website, by text, or electronically from a provider. It is not required
 * to be accurate — it says roughly who, roughly what, roughly where, and that
 * is all. It carries no pricing and no line items, and it does not create a
 * customer or a vehicle.
 *
 * Turning it into something billable is a deliberate act: promoting it to an
 * Estimate, which is where the customer is confirmed and the work is priced.
 */
final class ServiceRequestController
{
    public const STATUSES = ['PENDING', 'ACCEPTED', 'COMPLETED', 'CANCELLED', 'REJECTED'];

    public static function index(): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        $status = (string) input('status', '');
        $q      = (string) input('q', '');

        $sql  = 'SELECT * FROM service_requests WHERE 1=1';
        $args = [];
        if ($status !== '') { $sql .= ' AND status = ?'; $args[] = $status; }
        if ($q !== '') {
            $digits = preg_replace('/\D/', '', $q) ?: '~';
            $sql .= ' AND (doc_number LIKE ? OR reported_name LIKE ? OR reported_phone LIKE ? OR reported_location LIKE ?)';
            array_push($args, "%$q%", "%$q%", "%$digits%", "%$q%");
        }
        $sql .= ' ORDER BY id DESC LIMIT 200';

        View::render('pages/sr_index', [
            'title' => 'Service Requests', 'crumb' => 'Intake', 'nav' => 'service-requests',
            'rows'  => Db::all($sql, $args), 'status' => $status, 'q' => $q,
        ]);
    }

    public static function create(): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        View::render('pages/sr_new', [
            'title'     => 'New Service Request',
            'crumb'     => 'Intake',
            'nav'       => 'service-requests',
            'providers' => Db::all('SELECT * FROM customers WHERE is_provider = 1 ORDER BY company'),
        ]);
    }

    /**
     * Intake is deliberately forgiving. Nothing is verified, no customer record
     * is created and no vehicle is touched. All we are recording is that
     * somebody asked for help.
     */
    public static function store(): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');

        $priority = (string) input('priority', 'STANDARD');
        /* A promise only a person can make. promised_eta used to be clocked
         * automatically from the per-priority table — "promised ETA 3:09 AM"
         * on a call where nobody quoted a time, and the same fabricated value
         * once reached a customer by text, hours stale. Since 2026-08-31 it is
         * recorded only when the dispatcher actually told the caller a time —
         * as a clock time, because that is what gets said on the phone. */
        $etaAt = self::quotedEtaToTimestamp((string) input('promised_eta_time', ''));
        $raw      = (string) input('reported_phone', '');

        /* THE CATEGORY DECIDES, AND IT DECIDES FIRST. It is what dispatch
         * chose and what gets loaded on the truck, so the job is coerced to
         * something that category can actually roll rather than the pair being
         * trusted as posted. A type that does not belong to the category did
         * not come from the form as rendered. */
        $category = ServiceCategory::coerce(
            (string) input('service_category', ''),
            (string) input('reported_service', 'OTHER'));
        $reported = ServiceCategory::coerceServiceType(
            $category, (string) input('reported_service', 'OTHER'));

        /* A retail job carries no provider account and no claim reference. The
         * form hides both, and hiding is not clearing — see Rules::providerLink. */
        $jobSource = (string) input('job_source', 'RETAIL');
        $link      = Rules::providerLink($jobSource, intval_or_null('provider_id'),
                                         (string) input('provider_ref', ''));

        $id = Db::insert('service_requests', [
            'doc_number'        => DocNumber::next('SER'),
            'channel'           => (string) input('channel', 'PHONE'),
            'status'            => 'PENDING',
            'job_source'        => $jobSource,
            'provider_id'       => $link['provider_id'],
            'provider_ref'      => $link['provider_ref'],
            'priority'          => $priority,
            'reported_name'     => (string) input('reported_name', ''),
            'reported_phone'    => phone_to_e164($raw) ?: $raw,
            'reported_service'  => $reported,
            // What we are rolling for, which the caller does not decide.
            'service_category'  => $category,
            'reported_problem'  => (string) input('reported_problem', ''),
            'reported_location' => (string) input('reported_location', ''),
            'city'              => (string) input('city', ''),
            'state'             => (string) input('state', 'OR'),
            'postal_code'       => (string) input('postal_code', ''),
            /* A pin the dispatcher dropped on the map, with the nearest street
             * address worked out from it. location_captured_at is deliberately
             * NOT set: that column means "the customer's phone answered", and a
             * dispatcher pointing at a map is a different kind of fact. Keeping
             * them apart is what lets anyone later tell a position the customer
             * gave from one somebody inferred. */
            'latitude'             => coord_or_null('latitude'),
            'longitude'            => coord_or_null('longitude'),
            'nearest_address'      => (string) input('nearest_address', ''),
            'nearest_intersection' => (string) input('nearest_intersection', ''),
            'v_year'            => intval_or_null('v_year'),
            'v_make'            => (string) input('v_make', ''),
            'v_model'           => (string) input('v_model', ''),
            'v_color'           => (string) input('v_color', ''),
            'v_plate'           => strtoupper((string) input('v_plate', '')),
            'v_plate_state'     => (string) input('v_plate_state', ''),
            'promised_eta'      => $etaAt,
            'comms_consent'     => input('comms_consent') ? 1 : 0,
            'intake_notes'      => (string) input('intake_notes', ''),
            'created_by'        => Auth::id(),
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        Audit::log('service_request', $id, 'created', 'Intake via ' . input('channel', 'PHONE'));

        /* Audited separately from the intake itself, and named for what it is.
         * A position on a job is evidence; whoever reads this later needs to
         * know a human placed it rather than a device reporting itself. */
        if (coord_or_null('latitude') !== null && coord_or_null('longitude') !== null) {
            Audit::log('service_request', $id, 'location:pinned',
                'Dispatcher dropped a pin at ' . input('latitude') . ', ' . input('longitude')
                . ((string) input('nearest_address', '') !== ''
                    ? ' · nearest address ' . input('nearest_address') : ' · no street address nearby'));
        }

        // "Capture GPS" on the intake form: log the request, then immediately
        // text the caller a link that asks THEIR phone where it is. The save
        // happens either way — a failed text never loses the intake.
        if (input('send_location_link')) {
            $out = self::textLocationLink(self::find($id));
            /* The caller is stranded and watching their phone. Only a carrier-
             * accepted send may be described as "texted" — anything else and
             * the dispatcher needs to know NOW, while the caller is still on
             * the line and can be asked for their location in words. */
            flash(match (true) {
                (bool) ($out['sent'] ?? false) => 'Request logged and a location link was texted to the caller. Their position will appear here when they answer it — keep taking the call and finish the details below.',
                (bool) ($out['held'] ?? false) => 'Request logged. Texting is not connected, so NO location link went to the caller — get the location verbally while they are still on the line.',
                default                        => 'Request logged, but the location link was NOT sent: ' . e($out['reason']) . ' Get the location verbally — do not leave the caller waiting on a text.',
            }, ($out['sent'] ?? false) ? 'ok' : 'warn');

            /* THE BUTTON THAT ENDS INTAKE HAS TO HAND INTAKE BACK.
             * Capture GPS submits from the middle of the form so the caller's
             * phone starts buzzing early — which means the dispatcher is still
             * on the call with details left to take. Land on the request with
             * the edit form already open at the vehicle, rather than making
             * them find "Edit details" while a stranded customer talks. */
            redirect('/service-requests/' . $id . '?continue=vehicle');
        }

        flash('Request logged. Promote it to an estimate once you have confirmed who and what.', 'ok');
        redirect('/service-requests/' . $id);
    }

    /**
     * Correct the reported details after the fact. Callers text back a plate,
     * locations get clarified, a retail call turns out to be a provider job.
     * Only allowed while the request is still PENDING — once it is promoted the
     * estimate holds the confirmed record, and corrections belong there.
     * Every change is written to the audit trail field by field.
     */
    public static function update(array $a): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        $id = (int) $a['id'];
        $sr = self::find($id);

        if ($sr['status'] !== 'PENDING') {
            flash('Only a pending request can be edited. This one was already '
                . e(status_label((string) $sr['status'])) . ' — corrections now belong on the estimate.', 'err');
            redirect('/service-requests/' . $id);
        }

        $rawPhone = (string) input('reported_phone', '');

        /* Same order as intake — the category decides, the job is coerced to
         * it. The one exception is a request logged before the type split: its
         * retired type still belongs to its category, so allows() accepts it
         * and editing anything else on the record leaves the job alone rather
         * than renaming it to one nobody verified. */
        $newCategory = ServiceCategory::coerce(
            (string) input('service_category', (string) ($sr['service_category'] ?? '')),
            (string) input('reported_service', (string) $sr['reported_service']));
        $newReported = ServiceCategory::coerceServiceType(
            $newCategory,
            (string) input('reported_service', (string) $sr['reported_service']));

        /* Same separation as intake, and it matters more here: correcting a
         * provider job to retail has to drop the provider link with it, or the
         * record keeps a claim number for a job nobody is claiming. Both sides
         * of the change land on the audit trail below. */
        $newSource = (string) input('job_source', (string) $sr['job_source']);
        $link      = Rules::providerLink($newSource, intval_or_null('provider_id'),
                                         (string) input('provider_ref', ''));

        $new = [
            'channel'           => (string) input('channel', (string) $sr['channel']),
            'job_source'        => $newSource,
            'provider_id'       => $link['provider_id'],
            'provider_ref'      => $link['provider_ref'],
            'priority'          => (string) input('priority', (string) $sr['priority']),
            'reported_name'     => (string) input('reported_name', ''),
            'reported_phone'    => phone_to_e164($rawPhone) ?: $rawPhone,
            'reported_service'  => $newReported,
            'service_category'  => $newCategory,
            'reported_problem'  => (string) input('reported_problem', ''),
            'reported_location' => (string) input('reported_location', ''),
            'city'              => (string) input('city', ''),
            'state'             => (string) input('state', (string) $sr['state']),
            'postal_code'       => (string) input('postal_code', ''),
            'v_year'            => intval_or_null('v_year'),
            'v_make'            => (string) input('v_make', ''),
            'v_model'           => (string) input('v_model', ''),
            'v_color'           => (string) input('v_color', ''),
            'v_plate'           => strtoupper((string) input('v_plate', '')),
            'v_plate_state'     => (string) input('v_plate_state', ''),
            'comms_consent'     => input('comms_consent') ? 1 : 0,
        ];

        $labels = [
            'channel' => 'Channel', 'job_source' => 'Job source', 'provider_id' => 'Provider',
            'provider_ref' => 'Provider ref', 'priority' => 'Priority', 'reported_name' => 'Name',
            'reported_phone' => 'Callback', 'reported_service' => 'Service',
            'service_category' => 'Category', 'reported_problem' => 'Problem',
            'reported_location' => 'Location', 'city' => 'City', 'state' => 'State',
            'postal_code' => 'ZIP', 'v_year' => 'Vehicle year', 'v_make' => 'Make', 'v_model' => 'Model',
            'v_color' => 'Colour', 'v_plate' => 'Plate', 'v_plate_state' => 'Plate state',
            'comms_consent' => 'SMS consent',
        ];

        $show = static function (string $field, $v): string {
            if ($v === null || $v === '') { return '—'; }
            if ($field === 'reported_phone')   { return phone_display((string) $v); }
            if ($field === 'reported_service') { return service_type_label((string) $v); }
            if ($field === 'service_category') { return ServiceCategory::label((string) $v); }
            if ($field === 'comms_consent')    { return ((int) $v === 1) ? 'yes' : 'no'; }
            return (string) $v;
        };

        $changes = [];
        $upd     = [];
        foreach ($new as $field => $value) {
            $old = $sr[$field];
            // Compare loosely enough that 2018 == '2018' but strictly on emptiness.
            if ((string) ($old ?? '') === (string) ($value ?? '')) { continue; }
            $upd[$field] = $value;
            $changes[]   = $labels[$field] . ': ' . $show($field, $old) . ' → ' . $show($field, $value);
        }

        // The promise is manual, same as at intake — a priority change no
        // longer re-clocks anything, because urgency is not a quoted time.
        // Blank leaves the recorded promise alone; a time entered here
        // replaces it, as of the moment it was said.
        $etaAt = self::quotedEtaToTimestamp((string) input('promised_eta_time', ''));
        if ($etaAt !== null) {
            $upd['promised_eta'] = $etaAt;
            $changes[] = 'Promised ETA: quoted ' . fdate($etaAt, 'g:i A');
        }

        if (!$upd) {
            flash('Nothing changed.', 'ok');
            redirect('/service-requests/' . $id);
        }

        $upd['updated_at'] = now();
        Db::update('service_requests', $id, $upd);
        Audit::log('service_request', $id, 'details:updated', implode('; ', $changes));

        flash('Details updated. Every change is on the audit trail below.', 'ok');
        redirect('/service-requests/' . $id);
    }

    /** Text the caller a fresh location link from the request page. */
    /**
     * "HH:MM" as quoted on the phone → the NEXT occurrence of that clock time
     * in local time, snapped to the quarter-hour grid promises are made on.
     * A time already past today means tomorrow — "12:15" quoted at 11:50 PM
     * wraps midnight rather than recording a promise 23 hours broken. Blank
     * or malformed → null: no promise.
     */
    private static function quotedEtaToTimestamp(string $hhmm): ?string
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($hhmm), $m)) { return null; }
        $h = (int) $m[1];
        $i = (int) $m[2];
        if ($h > 23 || $i > 59) { return null; }
        $i = (int) (round($i / 15) * 15);
        if ($i === 60) { $i = 0; $h = ($h + 1) % 24; }
        $ts = mktime($h, $i, 0);
        if ($ts <= time()) { $ts += 86400; }
        return date('Y-m-d H:i:s', $ts);
    }

    public static function sendLocateLink(array $a): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        $id  = (int) $a['id'];
        $out = self::textLocationLink(self::find($id));
        /* Same doctrine as intake: only a carrier-accepted send may be called
         * "texted". `ok` is true for a HELD (outbox) message too, and telling
         * the dispatcher a link went out when nothing did leaves them waiting
         * for a position that is never coming (fixed 2026-08-27). */
        flash(match (true) {
            (bool) ($out['sent'] ?? false) => 'Location link texted. Their position will appear here when they answer it.',
            (bool) ($out['held'] ?? false) => 'Texting is not connected, so NO location link went out — the message is in the outbox. Get the location verbally.',
            default                        => 'Location link blocked: ' . e($out['reason']) . ' Nothing was sent.',
        }, ($out['sent'] ?? false) ? 'ok' : 'warn');
        redirect('/service-requests/' . $id);
    }

    /**
     * Issue a one-shot location link for this request and text it to the
     * reported number. Gated on the intake consent checkbox — there is no
     * customer record yet to carry consent, and the workbook's rule is
     * explicit: the dispatcher's checked box that verbal consent was given
     * is what authorizes this one text.
     */
    private static function textLocationLink(array $sr): array
    {
        $phone = phone_to_e164((string) $sr['reported_phone']) ?: '';

        if ((int) $sr['comms_consent'] !== 1 || $phone === '') {
            // Record the blocked attempt like any other blocked send —
            // nothing sends silently, and nothing is refused silently.
            $gate = Sms::queueForRequest($sr, 'locate', ['{link}' => '']);
            Audit::log('service_request', (int) $sr['id'], 'location:link', 'blocked — ' . $gate['reason']);
            return $gate;
        }

        $req  = LocationRequest::issue('SR', (int) $sr['id'], $phone, (int) $sr['id'],
                                       $sr['customer_id'] ? (int) $sr['customer_id'] : null);
        $gate = Sms::queueForRequest($sr, 'locate', ['{link}' => LocationRequest::url($req)]);
        if ($gate['sent'] ?? false) { Db::update('location_requests', (int) $req['id'], ['sent_at' => now()]); }

        Audit::log('service_request', (int) $sr['id'], 'location:link', match (true) {
            (bool) ($gate['sent'] ?? false) => 'location link texted to ' . $phone,
            (bool) ($gate['held'] ?? false) => 'location link held in outbox for ' . $phone,
            default                         => 'NOT SENT — ' . $gate['reason'],
        });
        return $gate;
    }

    public static function show(array $a): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        $id = (int) $a['id'];
        $sr = self::find($id);

        // Duplicate candidates for the promote modal, seeded so the search has
        // already run the moment it opens: the reported number, plus the
        // reported name matched as a person or a company. Hints, never an
        // identity — the dispatcher verifies before binding.
        $digits = preg_replace('/\D/', '', (string) $sr['reported_phone']) ?: '~';
        $rName  = trim((string) $sr['reported_name']);
        $candSql  = 'SELECT * FROM customers WHERE phone_e164 LIKE ?';
        $candArgs = ["%$digits%"];
        if ($rName !== '') {
            $candSql   .= ' OR lower(company) = lower(?)';
            $candArgs[] = $rName;
            $np = explode(' ', $rName, 2);
            if (count($np) === 2) {
                $candSql .= ' OR (lower(first_name) = lower(?) AND lower(last_name) = lower(?))';
                array_push($candArgs, $np[0], $np[1]);
            } else {
                $candSql .= ' OR lower(last_name) = lower(?)';
                $candArgs[] = $rName;
            }
        }
        $candSql .= ' ORDER BY id DESC LIMIT 8';

        View::render('pages/sr_show', [
            'title'      => $sr['doc_number'],
            'crumb'      => 'Service Request',
            'nav'        => 'service-requests',
            'sr'         => $sr,
            'customer'   => $sr['customer_id'] ? Db::one('SELECT * FROM customers WHERE id = ?', [(int) $sr['customer_id']]) : null,
            'estimates'  => Db::all('SELECT * FROM estimates WHERE service_request_id = ? ORDER BY id DESC', [$id]),
            // Phone- and name-matched hints only. The full base is never
            // listed — other records reach a customer through /customers/search.
            'candidates' => Db::all($candSql, $candArgs),
            'provider'   => $sr['provider_id'] ? Db::one('SELECT * FROM customers WHERE id = ?', [(int) $sr['provider_id']]) : null,
            'providers'  => Db::all('SELECT * FROM customers WHERE is_provider = 1 ORDER BY company'),
            'messages'   => Db::all('SELECT * FROM messages WHERE service_request_id = ? ORDER BY id DESC', [$id]),
            'audit'      => Audit::for('service_request', $id),
            'locOpen'    => LocationRequest::openFor('SR', $id),
        ]);
    }

    /**
     * Promotion — the moment the record stops being hearsay. Bind or create the
     * real customer, confirm the service and address, and open the Estimate.
     */
    public static function promote(array $a): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        $id = (int) $a['id'];
        $sr = self::find($id);

        if (in_array($sr['status'], ['CANCELLED', 'REJECTED'], true)) {
            flash('This request was closed. Reopen it before raising an estimate.', 'err');
            redirect('/service-requests/' . $id);
        }

        /* WHOSE CONTRACT IS THIS.
         * On a provider job the provider is the customer of record — they
         * dispatched the work and they pay the invoice — so the estimate binds
         * straight to that account and the caller is never made into a
         * customer. The motorist is not lost: their name and number stay on
         * the request as reported, which is where the on-scene texts read them
         * from (see Sms::queueOnScene).
         *
         * The gate is here rather than on the insert deliberately. Logging a
         * provider call without an account yet is normal; pricing one is not. */
        $provGate = Rules::providerJobGate($sr);
        if (!$provGate['ok']) {
            keep_input('/service-requests/' . $id);
            flash($provGate['reason'], 'err');
            redirect('/service-requests/' . $id);
        }
        $providerJob = strtoupper((string) $sr['job_source']) === 'PROVIDER';

        $customerId = $providerJob ? (int) $sr['provider_id'] : intval_or_null('customer_id');
        if (!$providerJob && !$customerId && !phone_to_e164((string) input('phone'))) {
            keep_input('/service-requests/' . $id);
            flash('A valid 10-digit phone number is required to create the customer record.', 'err');
            redirect('/service-requests/' . $id);
        }
        if (!$customerId) {
            $gate = Rules::customerGate(
                (string) input('customer_type', 'INDIVIDUAL'), (string) input('company', ''),
                (string) input('first_name', ''), (string) input('last_name', '')
            );
            if (!$gate['ok']) { keep_input('/service-requests/' . $id); flash($gate['reason'], 'err'); redirect('/service-requests/' . $id); }

            // Never create a customer without looking for them first. An exact
            // match IS the customer — bind it. A possible match stops the
            // promotion until a human either binds it or overrides, once.
            $dup = Rules::duplicateCustomer(
                (string) input('customer_type', 'INDIVIDUAL'), (string) input('company', ''),
                (string) input('first_name', ''), (string) input('last_name', ''),
                phone_to_e164((string) input('phone'))
            );
            if ($dup['level'] === 'exact') {
                $customerId = (int) $dup['match']['id'];
            } elseif ($dup['level'] === 'possible' && !input('dup_override')) {
                keep_input('/service-requests/' . $id);
                flash('Possible duplicate: ' . customer_name($dup['match'], true) . ' · '
                    . phone_display($dup['match']['phone_e164']) . ' is already on file. Bind them with the '
                    . 'customer search, or tick "Not a duplicate" and promote again.', 'warn');
                redirect('/service-requests/' . $id);
            }
        }

        /* THE POSITION GATE. A job needs to know where the vehicle IS before it
         * becomes a contract, and that is the pin — coordinates, from the
         * customer's phone or dropped by a dispatcher. Nothing else routes a
         * truck. Intake stays deliberately thin, so the requirement lands here,
         * at the same moment the customer stops being hearsay.
         *
         * The nearest physical address is NOT required. It is a derived
         * convenience: a label for the point, used for pricing and for printing
         * something a human can read. Plenty of real jobs have no addressable
         * building anywhere near them — a shoulder, a rest area, a rural
         * milepost — and refusing to raise an estimate because the geocoder had
         * nothing to say would block work the pin describes perfectly well. */
        /* A pin posted with this form, else whatever the request already had.
         * coord_or_null treats exactly-zero as absent — see the note there;
         * 0,0 is a real place and would otherwise pass every check below. */
        $pinLat = coord_or_null('pin_lat') ?? (is_numeric($sr['latitude'])  && abs((float) $sr['latitude'])  > 0.000001 ? (float) $sr['latitude']  : null);
        $pinLng = coord_or_null('pin_lng') ?? (is_numeric($sr['longitude']) && abs((float) $sr['longitude']) > 0.000001 ? (float) $sr['longitude'] : null);
        if ($pinLat === null || $pinLng === null) {
            keep_input('/service-requests/' . $id);
            flash('This job has no position yet. Drop a pin on the map where the vehicle actually is — '
                . 'the pin is what a truck drives to.', 'err');
            redirect('/service-requests/' . $id);
        }

        /* An address is optional, but it is still an ADDRESS. If something was
         * typed it has to be one — a description written into the address field
         * would print on a document the customer signs, and "blue sedan on the
         * shoulder" is not somewhere you can bill from. Leaving it blank is a
         * fine answer; putting the wrong kind of thing there is not. */
        $pinLine  = trim((string) input('pin_line', ''));
        $pinCity  = trim((string) input('pin_city', ''));
        $pinState = trim((string) input('pin_state', ''));
        $pinPost  = trim((string) input('pin_postal', ''));
        $pinCross = (string) ($sr['nearest_intersection'] ?? '');

        /* Retry the derived label when a customer location arrived without a
         * usable reverse-geocoder response. Coordinates remain sufficient for
         * promotion; this only improves what gets printed on the estimate. */
        if ($pinLine === '' && trim((string) ($sr['nearest_address'] ?? '')) === '') {
            try {
                $near = Integrations::geocoder()->reverse($pinLat, $pinLng);
                $pinLine  = trim((string) ($near['address'] ?? ''));
                $pinCity  = $pinCity !== '' ? $pinCity : trim((string) ($near['city'] ?? ''));
                $pinState = $pinState !== '' ? $pinState : trim((string) ($near['state'] ?? ''));
                $pinPost  = $pinPost !== '' ? $pinPost : trim((string) ($near['postal_code'] ?? ''));
                $pinCross = $pinCross !== '' ? $pinCross : trim((string) ($near['intersection'] ?? ''));
            } catch (Throwable $e) {
                error_log('[geocode] promotion reverse lookup failed: ' . $e->getMessage());
            }
        }

        if ($pinLine === '' && trim((string) ($sr['nearest_address'] ?? '')) !== '') {
            $pinLine = (string) $sr['nearest_address'];
        }
        $addr = Address::check($pinLine, $pinCity, $pinState, $pinPost);
        /* A reverse geocoder may return a named highway such as "I-205".
         * That is useful context but not a billable street address. Do not
         * block promotion on that derived label; only reject an invalid value
         * when it differs from the value already stored on the request and was
         * therefore entered or changed in this promotion form. */
        $derivedLine = trim((string) ($sr['nearest_address'] ?? ''));
        $highwayLabel = (bool) preg_match('/^(?:I|US|OR|SR|Hwy|Highway)\s*[- ]?\d+[A-Za-z]?\b/i', $pinLine);
        if ($pinLine !== '' && !$addr['ok'] && $pinLine !== $derivedLine && !$highwayLabel) {
            keep_input('/service-requests/' . $id);
            flash($addr['reason'] . ' Leave it blank if there is no address near the pin.', 'err');
            redirect('/service-requests/' . $id);
        }
        if (!$addr['ok']) {
            $addr = Address::check('', $pinCity, $pinState, $pinPost);
        }

        $estId = Db::tx(function () use ($sr, $id, $customerId, $addr, $pinLat, $pinLng, $pinCross) {
            if (!$customerId) {
                // Duplicates were already ruled out (or overridden) above —
                // an exact match never reaches this insert.
                $consent = input('sms_approved') ? 1 : 0;
                $ctype = strtoupper((string) input('customer_type', 'INDIVIDUAL'));
                $customerId = Db::insert('customers', [
                    'customer_type'      => $ctype,
                    'first_name'         => (string) input('first_name', ''),
                    'last_name'          => (string) input('last_name', ''),
                    'company'            => Rules::accountCompany($ctype, (string) input('company', '')),
                    'phone_e164'         => phone_to_e164((string) input('phone')),
                    'email'              => (string) input('email', ''),
                    'city'               => (string) $sr['city'],
                    'state'              => (string) $sr['state'],
                    'sms_approved'       => $consent,
                    'sms_consent_at'     => $consent ? now() : null,
                    'sms_consent_source' => $consent ? 'verbal_at_intake' : null,
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ]);
                Audit::log('customer', $customerId, 'created', 'Created when ' . $sr['doc_number'] . ' was promoted'
                    . (input('dup_override') ? ' — dispatcher overrode a possible-duplicate warning' : ''));
            }

            $estId = Db::insert('estimates', [
                'doc_number'         => DocNumber::next('EST'),
                'service_request_id' => $id,
                'customer_id'        => $customerId,
                'status'             => 'DRAFT',
                'service_type'       => (string) input('service_type', (string) $sr['reported_service']),
                'scope_summary'      => (string) input('scope_summary', (string) $sr['reported_problem']),
                /* The claim number is typed ONCE, at intake, and carries the
                 * rest of the way on its own — the estimate hands it to the
                 * work order, which hands it to the invoice. Retyping it per
                 * document is how the number we bill against stops matching
                 * the number on the provider's claim. Still editable on every
                 * document; this only decides where it starts. */
                'po_number'          => ((string) $sr['provider_ref']) !== '' ? (string) $sr['provider_ref'] : null,
                /* The point, and a label for it when there is one. An address
                 * that failed validation never lands here — it was refused
                 * above — so this is either a real address or empty.
                 * reported_location is NOT consulted: it holds the caller's
                 * description of where they are, which is testimony, not an
                 * address, and it stays on the request. */
                'nearest_address'      => $addr['ok'] ? $addr['line'] : '',
                'city'                 => $addr['ok'] && $addr['city'] !== ''   ? $addr['city']   : (string) $sr['city'],
                'state'                => $addr['ok'] && $addr['state'] !== ''  ? $addr['state']  : (string) $sr['state'],
                'postal_code'          => $addr['ok'] && $addr['postal'] !== '' ? $addr['postal'] : (string) $sr['postal_code'],
                'latitude'             => $pinLat,
                'longitude'            => $pinLng,
                'nearest_intersection' => $pinCross,
                'location_captured_at' => $sr['location_captured_at'],
                'tax_rate'           => App::taxRate(),
                'terms_text'         => (string) App::setting('estimate_terms', ''),
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);

            Db::update('service_requests', $id, [
                'customer_id' => $customerId,
                'status'      => 'ACCEPTED',
                'updated_at'  => now(),
            ]);

            /* Name the party the estimate was billed to. On a provider job the
             * customer of record is NOT the person who called, and that is
             * exactly the fact somebody will need to see six months later. */
            $bound = Db::one('SELECT * FROM customers WHERE id = ?', [(int) $customerId]);
            Audit::log('service_request', $id, 'promoted', 'Estimate opened — customer of record '
                . ($bound ? customer_name($bound, true) : '#' . $customerId)
                . ((int) ($bound['is_provider'] ?? 0) === 1 ? ' (provider account — the caller is not a customer)' : ''));
            Audit::log('estimate', $estId, 'created', 'From ' . $sr['doc_number']);
            return $estId;
        });

        flash('Estimate opened. Price the work, then capture the customer authorization.', 'ok');
        redirect('/estimates/' . $estId);
    }

    public static function status(array $a): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        $id  = (int) $a['id'];
        $sr  = self::find($id);
        $new = strtoupper((string) input('status', ''));

        if (!in_array($new, self::STATUSES, true)) {
            flash('A service request can only be Pending, Accepted, Completed, Cancelled or Rejected.', 'err');
            redirect('/service-requests/' . $id);
        }
        if (in_array($new, ['CANCELLED', 'REJECTED'], true) && input('close_reason') === '') {
            flash('A reason is required to cancel or reject a request.', 'err');
            redirect('/service-requests/' . $id);
        }

        Db::update('service_requests', $id, [
            'status'       => $new,
            'close_reason' => (string) input('close_reason', (string) ($sr['close_reason'] ?? '')),
            'updated_at'   => now(),
        ]);
        Audit::log('service_request', $id, 'status:' . $new, (string) input('close_reason', ''));
        flash('Request is now <strong>' . e(status_label($new)) . '</strong>.', 'ok');
        redirect('/service-requests/' . $id);
    }

    public static function notes(array $a): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        $id = (int) $a['id'];
        self::find($id);
        Db::update('service_requests', $id, ['intake_notes' => (string) input('intake_notes', ''), 'updated_at' => now()]);
        Audit::log('service_request', $id, 'notes:updated');
        flash('Notes saved.', 'ok');
        redirect('/service-requests/' . $id);
    }

    public static function sendSms(array $a): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        $id = (int) $a['id'];
        $sr = self::find($id);

        if (!$sr['customer_id']) {
            flash('No customer record is bound to this request yet. Promote it first.', 'warn');
            redirect('/service-requests/' . $id);
        }
        $c    = Db::one('SELECT * FROM customers WHERE id = ?', [(int) $sr['customer_id']]);
        $tpl  = (string) input('template', 'dispatch');
        /* Every template on this panel is an on-scene message — en route,
         * arrived, opt-in confirmation. They belong to the caller's phone,
         * which on a provider job is not the account bound to the request. */
        /* A promised ETA already in the past must not be texted: {eta} renders
         * as clock time, so yesterday's "2:30 PM" reads as a fresh promise. */
        $etaFresh = $sr['promised_eta'] && strtotime((string) $sr['promised_eta']) > time();
        $gate = Sms::queueOnScene($sr, $c, $tpl, [
            '{eta}'   => $etaFresh ? fdate((string) $sr['promised_eta'], 'g:i A') : 'shortly',
            '{total}' => '', '{doc}' => $sr['doc_number'],
        ]);

        Audit::log('service_request', $id, 'sms:' . $tpl, $gate['ok'] ? 'queued' : 'blocked — ' . $gate['reason']);
        /* Truthful send results, same doctrine as the locate link: "queued"
         * when a live carrier actually sent it was the harmless direction of
         * the same lie (fixed 2026-08-27). */
        flash(match (true) {
            (bool) ($gate['sent'] ?? false) => 'Message texted to the caller.',
            (bool) ($gate['held'] ?? false) => 'Message queued in the outbox — texting is not connected, so nothing went out.',
            default                         => 'Message blocked: ' . e($gate['reason']) . ' Nothing was sent.',
        }, ($gate['ok'] ?? false) ? 'ok' : 'warn');
        redirect('/service-requests/' . $id);
    }

    public static function find(int $id): array
    {
        $sr = Db::one('SELECT * FROM service_requests WHERE id = ?', [$id]);
        if (!$sr) { http_response_code(404); View::render('pages/404', ['title' => 'Not found']); exit; }
        return $sr;
    }
}
