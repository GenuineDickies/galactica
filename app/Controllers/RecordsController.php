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

final class CustomerController
{
    public static function index(): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        $q    = (string) input('q', '');
        $kind = (string) input('kind', '');           // '' | 'individuals' | 'business'
        $sql  = 'SELECT * FROM customers WHERE 1=1';
        $args = [];
        if ($q !== '') {
            $sql .= ' AND (first_name LIKE ? OR last_name LIKE ? OR company LIKE ? OR phone_e164 LIKE ? OR email LIKE ?)';
            array_push($args, "%$q%", "%$q%", "%$q%", '%' . preg_replace('/\D/', '', $q) . '%', "%$q%");
        }
        if ($kind === 'individuals') { $sql .= " AND customer_type = 'INDIVIDUAL'"; }
        if ($kind === 'business') { $sql .= " AND customer_type IN ('COMMERCIAL','FLEET')"; }
        $sql .= ' ORDER BY id DESC LIMIT 300';
        View::render('pages/cust_index', [
            'title' => 'Customers', 'crumb' => 'Records', 'nav' => 'customers',
            'rows'  => Db::all($sql, $args), 'q' => $q, 'kind' => $kind,
        ]);
    }

    public static function create(): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        View::render('pages/cust_new', ['title' => 'New Customer', 'crumb' => 'Records', 'nav' => 'customers']);
    }

    /**
     * Type-ahead search for binding an existing customer to a record. The one
     * way to reach an existing account from other forms — nothing ever lists
     * the whole customer base. Returns JSON, max 10 rows, 2+ characters.
     */
    public static function search(): void
    {
        // Intake and estimates are office work; a technician has no screen
        // that consumes this and must not be able to walk the customer base.
        Auth::requireRole('ADMIN', 'DISPATCH');
        header('Content-Type: application/json');
        $q = trim((string) input('q', ''));
        if (mb_strlen($q) < 2) { echo json_encode(['ok' => true, 'results' => []]); return; }

        $digits = preg_replace('/\D/', '', $q);
        $sql    = 'SELECT id, customer_type, first_name, last_name, company, phone_e164, city
                   FROM customers WHERE (first_name LIKE ? OR last_name LIKE ? OR company LIKE ? OR email LIKE ?';
        $args   = ["%$q%", "%$q%", "%$q%", "%$q%"];
        if ($digits !== '') { $sql .= ' OR phone_e164 LIKE ?'; $args[] = "%$digits%"; }
        $sql .= ') ORDER BY id DESC LIMIT 10';

        $out = [];
        foreach (Db::all($sql, $args) as $c) {
            $out[] = [
                'id'    => (int) $c['id'],
                'label' => customer_name($c, true),
                'phone' => phone_display($c['phone_e164']),
                'kind'  => customer_type_label($c['customer_type']),
                'city'  => (string) ($c['city'] ?? ''),
            ];
        }
        echo json_encode(['ok' => true, 'results' => $out]);
    }

    /** The accepted customer types. INDIVIDUAL is retail; the rest are business. */
    public const TYPES = ['INDIVIDUAL', 'COMMERCIAL', 'FLEET'];

    /**
     * Validate the person/business fields as one unit; flashes and redirects
     * on failure. A person must have a name and never carries a company; a
     * business must have a company — it IS the customer — and the person
     * fields are its optional billing contact.
     */
    private static function accountFields(array $fallback, string $backTo): array
    {
        $type = strtoupper((string) input('customer_type', (string) ($fallback['customer_type'] ?? 'INDIVIDUAL')));
        if (!in_array($type, self::TYPES, true)) { $type = 'INDIVIDUAL'; }

        $terms = strtoupper((string) input('payment_terms', (string) ($fallback['payment_terms'] ?? 'DUE_ON_RECEIPT')));
        if (!array_key_exists($terms, payment_terms_options())) { $terms = 'DUE_ON_RECEIPT'; }

        $company = trim((string) input('company', (string) ($fallback['company'] ?? '')));
        $first   = trim((string) input('first_name', (string) ($fallback['first_name'] ?? '')));
        $last    = trim((string) input('last_name', (string) ($fallback['last_name'] ?? '')));

        $gate = Rules::customerGate($type, $company, $first, $last);
        if (!$gate['ok']) { keep_input($backTo); flash($gate['reason'], 'err'); redirect($backTo); }

        return [
            'customer_type' => $type,
            'payment_terms' => $terms,
            'company'       => Rules::accountCompany($type, $company),   // person: always blank
            'first_name'    => $first,
            'last_name'     => $last,
        ];
    }

    public static function store(): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        $phone = phone_to_e164((string) input('phone'));
        if (!$phone) { keep_input('/customers/new'); flash('A valid 10-digit phone number is required.', 'err'); redirect('/customers/new'); }

        $acct = self::accountFields([], '/customers/new');

        // Never create a customer without looking for them first — one rule,
        // shared with SR promotion. Exact match: that record already exists,
        // go there. Possible match: a human decides, with an explicit override.
        $dup = Rules::duplicateCustomer(
            $acct['customer_type'], $acct['company'], $acct['first_name'], $acct['last_name'], $phone
        );
        /* Names are typed by whoever created the record and flash renders its
         * message as HTML, so every dynamic value here goes through e() —
         * otherwise a customer saved with markup in the company field runs
         * script in the NEXT staff member's session (fixed 2026-08-27). */
        if ($dup['level'] === 'exact') {
            flash('Already on file: ' . e(customer_name($dup['match'], true)) . ' · '
                . e(phone_display($dup['match']['phone_e164'])) . '. No duplicate was created — this is that record.', 'warn');
            redirect('/customers/' . (int) $dup['match']['id']);
        }
        if ($dup['level'] === 'possible' && !input('dup_override')) {
            keep_input('/customers/new');
            flash('Possible duplicate: ' . e(customer_name($dup['match'], true)) . ' · '
                . e(phone_display($dup['match']['phone_e164'])) . ' is already on file. Open that record instead, '
                . 'or tick "Not a duplicate" and save again.', 'warn');
            redirect('/customers/new');
        }

        $consent = input('sms_approved') ? 1 : 0;
        $id = Db::insert('customers', [
            'customer_type'      => $acct['customer_type'],
            'first_name'         => $acct['first_name'],
            'last_name'          => $acct['last_name'],
            'company'            => $acct['company'],
            'phone_e164'         => $phone,
            'phone2_e164'        => phone_to_e164((string) input('phone2')),
            'email'              => (string) input('email', ''),
            'address_line1'      => (string) input('address_line1', ''),
            'city'               => (string) input('city', ''),
            'state'              => (string) input('state', 'OR'),
            'postal_code'        => (string) input('postal_code', ''),
            'is_provider'        => input('is_provider') ? 1 : 0,
            'provider_code'      => (string) input('provider_code', ''),
            'payment_terms'      => $acct['payment_terms'],
            'sms_approved'       => $consent,
            'sms_consent_at'     => $consent ? now() : null,
            'sms_consent_source' => $consent ? (string) input('sms_consent_source', 'verbal_at_intake') : null,
            'tax_exempt'         => input('tax_exempt') ? 1 : 0,
            'notes'              => (string) input('notes', ''),
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
        Audit::log('customer', $id, 'created',
            input('dup_override') ? 'Dispatcher overrode a possible-duplicate warning' : '');
        flash('Customer created.', 'ok');
        redirect('/customers/' . $id);
    }

    public static function show(array $a): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        $id = (int) $a['id'];
        $c  = Db::one('SELECT * FROM customers WHERE id = ?', [$id]);
        if (!$c) { http_response_code(404); View::render('pages/404', ['title' => 'Not found']); exit; }

        View::render('pages/cust_show', [
            'title' => customer_name($c, true) ?: 'Customer',
            'crumb' => 'Customer', 'nav' => 'customers', 'c' => $c,
            'vehicles' => Db::all('SELECT * FROM vehicles WHERE customer_id = ? ORDER BY id DESC', [$id]),
            'srs'      => Db::all('SELECT * FROM service_requests WHERE customer_id = ? ORDER BY id DESC LIMIT 50', [$id]),
            'invoices' => Db::all('SELECT * FROM invoices WHERE customer_id = ? ORDER BY id DESC LIMIT 50', [$id]),
            'balance'  => (float) Db::val("SELECT COALESCE(SUM(balance_due),0) FROM invoices WHERE customer_id = ? AND status IN ('ISSUED','PARTIAL')", [$id]),
            'lifetime' => (float) Db::val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE customer_id = ?", [$id]),
        ]);
    }

    public static function update(array $a): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        $id = (int) $a['id'];
        $c  = Db::one('SELECT * FROM customers WHERE id = ?', [$id]);
        $acct    = self::accountFields($c, '/customers/' . $id);
        $consent = input('sms_approved') ? 1 : 0;

        // Account-level changes are worth spelling out in the audit trail.
        $changes = [];
        if ($acct['customer_type'] !== $c['customer_type']) {
            $changes[] = 'type ' . $c['customer_type'] . ' → ' . $acct['customer_type'];
        }
        if ($acct['payment_terms'] !== ($c['payment_terms'] ?: 'DUE_ON_RECEIPT')) {
            $changes[] = 'terms ' . ($c['payment_terms'] ?: 'DUE_ON_RECEIPT') . ' → ' . $acct['payment_terms']
                       . ' (existing invoices keep their snapshotted terms)';
        }

        Db::update('customers', $id, [
            'customer_type'  => $acct['customer_type'],
            'payment_terms'  => $acct['payment_terms'],
            'first_name'     => $acct['first_name'],
            'last_name'      => $acct['last_name'],
            'company'        => $acct['company'],
            'email'          => (string) input('email', $c['email']),
            'address_line1'  => (string) input('address_line1', $c['address_line1']),
            'city'           => (string) input('city', $c['city']),
            'state'          => (string) input('state', $c['state']),
            'postal_code'    => (string) input('postal_code', $c['postal_code']),
            'sms_approved'   => $consent,
            'sms_consent_at' => $consent ? ($c['sms_consent_at'] ?: now()) : null,
            'do_not_contact' => input('do_not_contact') ? 1 : 0,
            'notes'          => (string) input('notes', $c['notes']),
            'updated_at'     => now(),
        ]);
        Audit::log('customer', $id, 'updated', implode(' · ', $changes));
        flash('Customer saved.', 'ok');
        redirect('/customers/' . $id);
    }
}

final class VehicleController
{
    /**
     * Feeds the year/make/model cascading dropdowns from vehicle_catalog.
     * Returns JSON: years always; makes when a year is given; models when a
     * year and make are given. Reference data only — the form's fields stay
     * plain text and every picker keeps a free-text escape hatch, so this
     * endpoint suggests and never restricts. See data/seed_vehicles.php.
     */
    public static function options(): void
    {
        Auth::require();
        header('Content-Type: application/json');
        if (!Db::tableExists('vehicle_catalog')) {           // deployed ahead of its schema
            echo json_encode(['ok' => false, 'error' => 'vehicle_catalog not installed']);
            return;
        }

        $out  = ['ok' => true];
        $year = intval_or_null('year');
        $make = trim((string) input('make', ''));

        $out['years'] = array_map('intval',
            array_column(Db::all('SELECT DISTINCT year FROM vehicle_catalog ORDER BY year DESC'), 'year'));
        if ($year !== null) {
            $out['makes'] = array_column(Db::all(
                'SELECT DISTINCT make FROM vehicle_catalog WHERE year = ? ORDER BY make', [$year]), 'make');
        }
        if ($year !== null && $make !== '') {
            $out['models'] = array_column(Db::all(
                'SELECT DISTINCT model FROM vehicle_catalog WHERE year = ? AND make = ? ORDER BY model',
                [$year, $make]), 'model');
        }
        echo json_encode($out);
    }

    public static function index(): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        $q    = (string) input('q', '');
        $sql  = "SELECT v.*, c.first_name, c.last_name, c.company FROM vehicles v
                 LEFT JOIN customers c ON c.id = v.customer_id WHERE 1=1";
        $args = [];
        if ($q !== '') {
            $sql .= ' AND (v.vin LIKE ? OR v.plate LIKE ? OR v.make LIKE ? OR v.model LIKE ?)';
            $up = strtoupper($q);
            array_push($args, "%$up%", "%$up%", "%$q%", "%$q%");
        }
        $sql .= ' ORDER BY v.id DESC LIMIT 300';
        View::render('pages/veh_index', [
            'title' => 'Vehicles', 'crumb' => 'Records', 'nav' => 'vehicles',
            'rows'  => Db::all($sql, $args), 'q' => $q,
        ]);
    }

    public static function create(): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        View::render('pages/veh_new', ['title' => 'New Vehicle', 'crumb' => 'Records', 'nav' => 'vehicles']);
    }

    public static function store(): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        $vin = strtoupper((string) input('vin', ''));
        if (!vin_is_valid($vin)) {
            flash('A vehicle record can only be created from a valid 17-character VIN. Use a plate only to find an existing VIN record.', 'err');
            redirect('/vehicles/new');
        }
        if ($dupe = Db::one('SELECT id FROM vehicles WHERE vin = ?', [$vin])) {
            flash('That VIN is already on file.', 'warn');
            redirect('/vehicles/' . $dupe['id']);
        }
        $id = Db::insert('vehicles', [
            'customer_id'     => null,
            'vin'             => $vin,
            'plate'           => strtoupper((string) input('plate', '')),
            'plate_state'     => (string) input('plate_state', ''),
            'no_plate'        => input('no_plate') ? 1 : 0,
            'no_plate_reason' => (string) input('no_plate_reason', ''),
            'year'            => intval_or_null('year'),
            'make'            => (string) input('make', ''),
            'model'           => (string) input('model', ''),
            'color'           => (string) input('color', ''),
            'odometer'        => intval_or_null('odometer'),
            'unit_number'     => (string) input('unit_number', ''),
            /* Through the one decoder driver — a helpers.php copy used to
             * shadow it, so vehicles decoded differently depending on where
             * they were born (deleted 2026-08-27). */
            'vin_decoded'     => json_encode(Integrations::vin()->decode($vin)),
            'notes'           => (string) input('notes', ''),
            'created_at'      => now(),
        ]);
        Audit::log('vehicle', $id, 'created', 'VIN ' . $vin);
        flash('Vehicle created.', 'ok');
        redirect('/vehicles/' . $id);
    }

    public static function show(array $a): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        $id = (int) $a['id'];
        $v  = Db::one('SELECT * FROM vehicles WHERE id = ?', [$id]);
        if (!$v) { http_response_code(404); View::render('pages/404', ['title' => 'Not found']); exit; }
        View::render('pages/veh_show', [
            'title' => trim(($v['year'] ?: '') . ' ' . $v['make'] . ' ' . $v['model']) ?: 'Vehicle',
            'crumb' => 'Vehicle', 'nav' => 'vehicles', 'v' => $v,
            'customer' => $v['customer_id'] ? Db::one('SELECT * FROM customers WHERE id = ?', [(int) $v['customer_id']]) : null,
            // A vehicle is attached at the estimate, never at the request.
            'history'  => Db::all(
                'SELECT e.*, s.doc_number sr_no, s.reported_service, s.city
                 FROM estimates e JOIN service_requests s ON s.id = e.service_request_id
                 WHERE e.vehicle_id = ? ORDER BY e.id DESC',
                [$id]
            ),
            'decoded'  => $v['vin_decoded'] ? json_decode((string) $v['vin_decoded'], true) : null,
        ]);
    }
}

final class CatalogController
{
    public static function index(): void
    {
        Auth::require();
        View::render('pages/catalog', [
            'title' => 'Products & Services', 'crumb' => 'Records', 'nav' => 'catalog',
            'rows'  => Db::all('SELECT * FROM catalog_items ORDER BY item_type, category, sort_order, name'),
            'revenue_accounts' => Accounts::forType('REVENUE'),
            'cogs_accounts'    => Accounts::forType('COGS'),
        ]);
    }


    /**
     * Propose a part number for a not-yet-saved item, without writing anything.
     * Called from the Add-item form's "Generate" button; returns JSON.
     */
    public static function suggestSku(): void
    {
        Auth::requireRole('ADMIN');
        header('Content-Type: application/json');
        if (trim((string) input('name', '')) === '') {
            echo json_encode(['ok' => false, 'error' => 'Enter a name first.']);
            return;
        }
        $out = PartNumbers::suggest([
            'item_type'   => (string) input('item_type', 'SERVICE'),
            'name'        => (string) input('name', ''),
            'category'    => (string) input('category', ''),
            'description' => (string) input('description', ''),
        ]);
        echo json_encode(['ok' => true] + $out);
    }

    /**
     * Live customer-price suggestion for a given cost, from the markup matrix.
     * The one place the browser can ask for a marked-up price — the formula
     * itself never leaves the Markup service. Returns JSON.
     */
    public static function suggestPrice(): void
    {
        Auth::require();
        header('Content-Type: application/json');
        $s = Markup::suggest(input('unit_cost'));
        echo json_encode([
            'ok'            => true,
            'needs_pricing' => $s['needs_pricing'],
            'price'         => $s['price'],
            'markup_pct'    => $s['markup_pct'],
        ]);
    }

    public static function store(): void
    {
        Auth::requireRole('ADMIN');
        $sku = strtoupper(trim((string) input('sku', '')));
        if (input('name') === '') { flash('The item needs a name — it becomes the line the customer reads on their document. Add one and save again.', 'err'); redirect('/catalog'); }

        // No SKU entered — assign one automatically from the numbering rules.
        if ($sku === '') {
            $gen = PartNumbers::suggest([
                'item_type'   => (string) input('item_type', 'SERVICE'),
                'name'        => (string) input('name', ''),
                'category'    => (string) input('category', ''),
                'description' => (string) input('description', ''),
            ]);
            $sku = $gen['sku'];
            flash('Assigned part number ' . e($sku) . '. ' . e($gen['note']), 'ok');
        }

        if (Db::one('SELECT id FROM catalog_items WHERE sku = ?', [$sku])) {
            flash('That SKU already exists. Codes are never reused once invoices reference them.', 'err');
            redirect('/catalog');
        }

        // Price = the matrix suggestion unless it was manually overridden. The
        // server is authoritative so a non-JS save still gets a correct price.
        $cost       = num('unit_cost');
        $overridden = overridden_flag();
        /* A misc slot is never priced from the matrix — it has no standard
         * price at all, and a marked-up number written here would become the
         * silent fallback for every line added from it. See Lines::add(). */
        $isMisc     = (bool) input('is_misc');
        $suggestion = $isMisc ? ['price' => null] : Markup::suggest($cost);
        if ($overridden) {
            $price = num('unit_price');
        } elseif ($suggestion['price'] !== null) {
            $price      = (float) $suggestion['price'];
            $overridden = false;
        } else {
            $price      = num('unit_price');   // nothing to mark up — keep what was typed
            $overridden = false;
        }

        $id = Db::insert('catalog_items', [
            'sku'                  => $sku,
            'item_type'            => (string) input('item_type', 'SERVICE'),
            'category'             => (string) input('category', ''),
            'name'                 => (string) input('name', ''),
            'description'          => (string) input('description', ''),
            'pricing_model'        => (string) input('pricing_model', 'FLAT'),
            'unit_price'           => $price,
            'unit_cost'            => $cost,
            'price_overridden'     => $overridden ? 1 : 0,
            'uom'                  => (string) input('uom', 'job'),
            'taxable'              => input('taxable') ? 1 : 0,
            'vehicle_not_required' => input('vehicle_not_required') ? 1 : 0,
            'warranty_months'      => (int) num('warranty_months'),
            'mfr_warranty'         => trim((string) input('mfr_warranty', '')) ?: null,
            'vendor_name'          => trim((string) input('vendor_name', '')) ?: null,
            'vendor_part_number'   => strtoupper(trim((string) input('vendor_part_number', ''))) ?: null,
            'core_charge'          => num('core_charge'),
            'revenue_account'      => (string) input('revenue_account', ''),
            'cogs_account'         => (string) input('cogs_account', ''),
            'is_active'            => 1,
            'is_misc'              => input('is_misc') ? 1 : 0,
            'sort_order'           => 0,
        ]);
        Audit::log('catalog_item', $id, 'created', $sku);
        flash('Catalog item added.', 'ok');
        redirect('/catalog');
    }

    /**
     * Edit an item.
     *
     * Until this existed the catalog was create-and-deactivate only: a typo'd
     * price could be fixed only by retiring the item and re-creating it under
     * a new SKU, which is a heavy price for a fat finger.
     *
     * THE SKU IS NOT EDITABLE. A part number is identity — documents snapshot
     * it and the whole codes-are-never-reused rule rests on it holding still.
     * A wrong number is still retired and re-created, exactly as before.
     *
     * Nothing here reaches an issued document. Lines snapshot their price,
     * cost, markup, accounts and core value at the moment they are added, so
     * correcting an item changes what the NEXT line will carry and nothing
     * that has already been quoted, invoiced or posted.
     */
    public static function update(array $a): void
    {
        Auth::requireRole('ADMIN');
        $id = (int) $a['id'];
        $it = Db::one('SELECT * FROM catalog_items WHERE id = ?', [$id]);
        if (!$it) { flash('That catalog item is no longer on file — the list you were looking at was stale. It has been refreshed; pick the item again.', 'err'); redirect('/catalog'); }

        if (trim((string) input('name', '')) === '') {
            flash('The item needs a name — it becomes the line the customer reads on their document. Add one and save again.', 'err');
            redirect('/catalog');
        }

        $cost       = num('unit_cost');
        $overridden = overridden_flag();
        // Same exemption as on create — a misc slot is not priced by matrix.
        $suggestion = input('is_misc') ? ['price' => null] : Markup::suggest($cost);
        if ($overridden) {
            $price = num('unit_price');
        } elseif ($suggestion['price'] !== null) {
            $price      = (float) $suggestion['price'];
            $overridden = false;
        } else {
            $price      = num('unit_price');
            $overridden = false;
        }

        $before = sprintf('%s %s cost %s core %s',
            $it['name'], money($it['unit_price']), money($it['unit_cost']), money($it['core_charge'] ?? 0));

        Db::update('catalog_items', $id, [
            'item_type'            => (string) input('item_type', (string) $it['item_type']),
            'category'             => (string) input('category', ''),
            'name'                 => (string) input('name', ''),
            'description'          => (string) input('description', ''),
            'pricing_model'        => (string) input('pricing_model', (string) $it['pricing_model']),
            'unit_price'           => $price,
            'unit_cost'            => $cost,
            'price_overridden'     => $overridden ? 1 : 0,
            'uom'                  => (string) input('uom', 'job'),
            'taxable'              => input('taxable') ? 1 : 0,
            'vehicle_not_required' => input('vehicle_not_required') ? 1 : 0,
            'warranty_months'      => (int) num('warranty_months'),
            'mfr_warranty'         => trim((string) input('mfr_warranty', '')) ?: null,
            'vendor_name'          => trim((string) input('vendor_name', '')) ?: null,
            'vendor_part_number'   => strtoupper(trim((string) input('vendor_part_number', ''))) ?: null,
            'core_charge'          => num('core_charge'),
            'revenue_account'      => (string) input('revenue_account', ''),
            'cogs_account'         => (string) input('cogs_account', ''),
            'is_misc'              => input('is_misc') ? 1 : 0,
        ]);

        $after = sprintf('%s %s cost %s core %s',
            (string) input('name', ''), money($price), money($cost), money(num('core_charge')));

        Audit::log('catalog_item', $id, 'updated', $before . '  ->  ' . $after);
        flash('Saved. Documents already issued keep the values they snapshotted.', 'ok');
        redirect('/catalog');
    }

    public static function toggle(array $a): void
    {
        Auth::requireRole('ADMIN');
        $id = (int) $a['id'];
        $it = Db::one('SELECT * FROM catalog_items WHERE id = ?', [$id]);
        Db::update('catalog_items', $id, ['is_active' => (int) $it['is_active'] === 1 ? 0 : 1]);
        Audit::log('catalog_item', $id, 'toggled');
        redirect('/catalog');
    }
}

/**
 * The imported Square history, for reading and classifying.
 *
 * The mirror holds thousands of rows and the account mixes White Knight work
 * with the owner's own spending, so nothing here can post until a human has
 * said which is which. This is where that happens — and it exists because a
 * command-line tool is no use for a judgement that needs eyes on the data.
 *
 * Classifying is NOT posting. It records a decision; the entry comes later.
 */
final class SquareController
{
    private const PER_PAGE = 100;

    /** Named views onto the pile, so the interesting rows are one click away. */
    public const LENSES = [
        'unreviewed' => 'Needs review',
        'tiny'       => 'Under $5',
        'noncard'    => 'Not on a card',
        'largest'    => 'Largest',
        'declined'   => 'Declined',
        'business'   => 'Business',
        'personal'   => 'Personal',
        'all'        => 'Everything',
    ];

    public static function index(): void
    {
        Auth::requireRole('ADMIN');

        if (!Db::tableExists('square_transactions')) {
            View::render('pages/square', [
                'title' => 'Square History', 'crumb' => 'Money', 'nav' => 'square',
                'rows' => [], 'lens' => 'unreviewed', 'lenses' => self::LENSES,
                'q' => '', 'page' => 1, 'pages' => 1, 'total' => 0,
                'counts' => [], 'needs_schema' => true, 'summary' => null,
            ]);
            return;
        }

        $lens = (string) input('lens', 'unreviewed');
        if (!array_key_exists($lens, self::LENSES)) { $lens = 'unreviewed'; }
        $q    = trim((string) input('q', ''));
        $page = max(1, (int) num('page', 1));

        [$where, $args, $order] = self::lens($lens);

        if ($q !== '') {
            /* Search across everything that identifies a charge, not just the
             * name — six years of these are found by receipt number, by the
             * last four, by which device rang it up, or by what the customer
             * saw on their statement. */
            $where .= " AND (customer_name LIKE ? OR cardholder_name LIKE ? OR note LIKE ?
                             OR buyer_email LIKE ? OR square_id LIKE ? OR reference_id LIKE ?
                             OR receipt_number LIKE ? OR device_name LIKE ? OR statement_desc LIKE ?
                             OR card_last4 LIKE ? OR decline_detail LIKE ?)";
            $like = '%' . $q . '%';
            array_push($args, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like);
        }

        $total = (int) Db::val("SELECT COUNT(*) FROM square_transactions WHERE $where", $args, 0);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page  = min($page, $pages);
        $off   = ($page - 1) * self::PER_PAGE;

        View::render('pages/square', [
            'title'  => 'Square History',
            'crumb'  => 'Money',
            'nav'    => 'square',
            'rows'   => Db::all(
                "SELECT * FROM square_transactions WHERE $where ORDER BY $order LIMIT " . self::PER_PAGE . " OFFSET $off",
                $args
            ),
            'lens'   => $lens,
            'lenses' => self::LENSES,
            'q'      => $q,
            'page'   => $page,
            'pages'  => $pages,
            'total'  => $total,
            'counts' => self::counts(),
            'summary' => Db::one(
                "SELECT COUNT(*) n, COALESCE(SUM(amount),0) t FROM square_transactions WHERE $where", $args
            ),
            'needs_schema' => false,
        ]);
    }

    /**
     * The filters behind each lens.
     *
     * Kept in one place so the page, the counts and the bulk action can never
     * disagree about what "Under $5" means — a bulk update that matched a
     * different set than the list showed would classify rows the operator
     * never saw.
     *
     * @return array{0:string,1:array<int,mixed>,2:string}
     */
    private static function lens(string $lens): array
    {
        return match ($lens) {
            'tiny'      => ["object_type='PAYMENT' AND status='COMPLETED' AND amount > 0 AND amount < 5", [], 'amount ASC, occurred_at'],
            'noncard'   => ["object_type='PAYMENT' AND status='COMPLETED' AND source_type <> 'CARD'", [], 'amount DESC'],
            'largest'   => ["object_type='PAYMENT' AND status='COMPLETED'", [], 'amount DESC'],
            'declined'  => ["object_type='PAYMENT' AND status <> 'COMPLETED'", [], 'occurred_at DESC'],
            'business'  => ["classification='BUSINESS'", [], 'occurred_at DESC'],
            'personal'  => ["classification='PERSONAL'", [], 'occurred_at DESC'],
            'all'       => ['1=1', [], 'occurred_at DESC'],
            default     => ["classification='UNREVIEWED'", [], 'occurred_at DESC'],
        };
    }

    private static function counts(): array
    {
        $out = [];
        foreach (array_keys(self::LENSES) as $k) {
            [$w, $a] = self::lens($k);
            $out[$k] = (int) Db::val("SELECT COUNT(*) FROM square_transactions WHERE $w", $a, 0);
        }
        return $out;
    }

    /**
     * One transaction, in full.
     *
     * Including the untouched payload. Six years of charges with no documents
     * behind them get told apart by circumstance — which device, which app,
     * what the customer saw on their statement — and no fixed set of columns
     * will anticipate every question. So the mapped fields are shown for
     * scanning and the raw JSON is there underneath for when they are not
     * enough. Keeping the payload was the point of keeping the payload.
     */
    public static function show(array $a): void
    {
        Auth::requireRole('ADMIN');
        $id  = (int) $a['id'];
        $row = Db::one('SELECT * FROM square_transactions WHERE id = ?', [$id]);
        if ($row === null) { flash('That Square transaction is not in the mirror — it may not have synced yet. Run a sync from this page, then try again.', 'err'); redirect('/square'); }

        /* Everything else ever charged to the same physical card. Square
         * fingerprints the card, so this groups a repeat customer across six
         * years even when nobody ever captured their name. */
        $sameCard = [];
        if (trim((string) $row['card_fingerprint']) !== '') {
            $sameCard = Db::all(
                "SELECT id, occurred_at, amount, status, customer_name, classification
                 FROM square_transactions
                 WHERE card_fingerprint = ? AND id <> ? ORDER BY occurred_at DESC LIMIT 25",
                [$row['card_fingerprint'], $id]
            );
        }

        View::render('pages/square_show', [
            'title' => 'Square transaction', 'crumb' => 'Money', 'nav' => 'square',
            'r' => $row,
            'raw' => json_encode(json_decode((string) $row['raw'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'sameCard' => $sameCard,
            'customer' => $row['square_customer_id']
                ? Db::one('SELECT * FROM square_customers WHERE square_customer_id = ?', [$row['square_customer_id']])
                : null,
        ]);
    }

    /** Classify one row. */
    public static function classify(array $a): void
    {
        Auth::requireRole('ADMIN');
        $id = (int) $a['id'];
        $as = strtoupper((string) input('as', ''));
        self::apply([$id], $as);
        redirect('/square?' . http_build_query([
            'lens' => (string) input('lens', 'unreviewed'),
            'q'    => (string) input('q', ''),
            'page' => (string) input('page', '1'),
        ]));
    }

    /**
     * Classify everything the current lens matches.
     *
     * The filter is recomputed server-side from the lens name rather than
     * trusted from the form, so a bulk action can only ever touch the rows
     * that lens actually selects.
     */
    public static function bulk(): void
    {
        Auth::requireRole('ADMIN');
        $lens = (string) input('lens', 'unreviewed');
        $as   = strtoupper((string) input('as', ''));
        if (!array_key_exists($lens, self::LENSES)) { $lens = 'unreviewed'; }

        [$where, $args] = self::lens($lens);
        $ids = array_column(
            Db::all("SELECT id FROM square_transactions WHERE $where AND posted_entry_id IS NULL", $args),
            'id'
        );
        $n = self::apply(array_map('intval', $ids), $as);

        flash($n . ' transaction' . ($n === 1 ? '' : 's') . ' marked ' . e($as)
            . '. Nothing has posted to the ledger — that is a separate step.', 'ok');
        redirect('/square?lens=' . $lens);
    }

    /**
     * @param array<int,int> $ids
     * @return int how many actually changed
     */
    private static function apply(array $ids, string $as): int
    {
        if (!in_array($as, ['UNREVIEWED', 'BUSINESS', 'PERSONAL', 'TRANSFER'], true)) {
            flash('Unknown classification.', 'err');
            return 0;
        }

        $user = Auth::user();
        $done = 0; $blocked = 0;

        foreach ($ids as $id) {
            $row = Db::one('SELECT classification, posted_entry_id FROM square_transactions WHERE id = ?', [$id]);
            if ($row === null) { continue; }
            /* A row that has already posted cannot be relabelled: the entry
             * would stand while the mirror said something else. Reverse the
             * entry first. */
            if ($row['posted_entry_id'] !== null) { $blocked++; continue; }

            Db::update('square_transactions', $id, [
                'classification' => $as,
                'classified_by'  => $user['id'] ?? null,
                'classified_at'  => now(),
            ]);
            Audit::log('square_transaction', $id, 'classified', $row['classification'] . ' -> ' . $as);
            $done++;
        }

        if ($blocked > 0) {
            flash($blocked . ' row(s) were left alone because they have already posted to the ledger. '
                . 'Reverse the entry first.', 'warn');
        }
        return $done;
    }
}

/**
 * The books — reports read straight from the ledger.
 *
 * Separate from ReportController on purpose. That one reports on OPERATIONS
 * from the documents; this one reports what the LEDGER says. Where the two
 * disagree, that disagreement is information, and merging the pages would
 * bury it.
 *
 * Every report shares one shape (see LedgerReports), so this controller does
 * not know or care which one it is rendering — and adding a report never
 * touches this file.
 */
final class BooksController
{
    public static function index(array $a = []): void
    {
        Auth::requireRole('ADMIN');

        $key = (string) ($a['key'] ?? input('r', 'trial-balance'));
        if (!array_key_exists($key, LedgerReports::REPORTS)) { $key = 'trial-balance'; }

        $report = LedgerReports::run($key, [
            'from'    => (string) input('from', date('Y-01-01')),
            'to'      => (string) input('to', ''),
            'account' => (string) input('account', ''),
        ]);

        View::render('pages/books', [
            'title'    => 'The Books',
            'crumb'    => 'Money',
            'nav'      => 'books',
            'report'   => $report,
            'key'      => $key,
            'reports'  => LedgerReports::REPORTS,
            'from'     => (string) input('from', date('Y-01-01')),
            'to'       => (string) input('to', ''),
            'account'  => (string) input('account', ''),
            'accounts' => Accounts::all(),
        ]);
    }
}

/**
 * Core deposits — the money held and the parts owed.
 *
 * Everything here is a physical fact being recorded: the technician has the
 * old alternator, it went back to the jobber, the jobber paid up. The ledger
 * follows on its own; this screen is for the person holding the part.
 */
final class CoreController
{
    public static function index(): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');

        /* core_records postdates every existing install, and production applies
         * schema changes by hand. Until that happens this page would throw and
         * render a blank 500 — which tells an operator nothing and looks like
         * the application is broken rather than one step behind. Say what is
         * actually wrong and where to fix it. */
        if (!Db::tableExists('core_records')) {
            View::render('pages/cores', [
                'title' => 'Core Deposits', 'crumb' => 'Money', 'nav' => 'cores',
                'rows' => [], 'status' => 'OPEN', 'summary' => ['count' => 0, 'total' => '0.00'],
                'overdue' => [], 'window' => 30, 'techs' => [],
                'needs_schema' => true,
            ]);
            return;
        }

        $status = strtoupper((string) input('status', 'OPEN'));
        $in     = implode(',', array_map(static fn($s) => "'" . $s . "'", Cores::OPEN));

        $where = match ($status) {
            'ALL'       => '1=1',
            'SETTLED'   => "status = 'SETTLED'",
            'FORFEITED' => "status = 'FORFEITED'",
            default     => "status IN ($in)",
        };

        View::render('pages/cores', [
            'title'   => 'Core Deposits',
            'crumb'   => 'Money',
            'nav'     => 'cores',
            'rows'    => Db::all("SELECT * FROM core_records WHERE $where ORDER BY due_back_by, id"),
            'status'  => $status,
            'summary' => Cores::openSummary(),
            'overdue' => Cores::overdue(),
            'window'  => Cores::windowDays(),
            'techs'   => Db::all("SELECT id, first_name, last_name FROM users WHERE is_active = 1 ORDER BY first_name"),
        ]);
    }

    /**
     * Move one core along. The state machine refuses anything illegal and the
     * ledger entry, where there is one, is raised inside the same transaction.
     */
    public static function move(array $a): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        $id = (int) $a['id'];
        $to = strtoupper((string) input('to', ''));

        try {
            Cores::move($id, $to, [
                'collected_by_id' => (int) num('collected_by_id') ?: null,
                'supplier_name'   => (string) input('supplier_name', ''),
                'notes'           => (string) input('notes', ''),
            ]);
            flash('Core moved to ' . e(Cores::label($to)) . '.', 'ok');
        } catch (Throwable $e) {
            flash($e->getMessage(), 'err');
        }
        redirect('/cores');
    }

    /**
     * Forfeit everything past its window.
     *
     * It PROPOSES on GET and acts only on POST, because forfeiting is the one
     * transition that turns somebody else's money into revenue. Nothing here
     * runs on a timer — a sweep that quietly books income while nobody is
     * looking is how a shop ends up paying tax on deposits it still owes.
     */
    public static function sweep(): void
    {
        Auth::requireRole('ADMIN');
        $due = Cores::overdue();
        if (!$due) {
            flash('No cores are past their return window.', 'ok');
            redirect('/cores');
        }

        $done = 0; $failed = 0;
        foreach ($due as $c) {
            try {
                Cores::move((int) $c['id'], Cores::FORFEITED, [
                    'notes' => 'Forfeited by sweep — past the ' . Cores::windowDays() . '-day window.',
                ]);
                $done++;
            } catch (Throwable) { $failed++; }
        }

        Audit::log('system', 0, 'cores:swept', $done . ' forfeited, ' . $failed . ' refused');
        flash($done . ' core' . ($done === 1 ? '' : 's') . ' forfeited to revenue.'
            . ($failed > 0 ? ' ' . $failed . ' could not be moved.' : ''), $failed > 0 ? 'warn' : 'ok');
        redirect('/cores');
    }
}

/**
 * Chart of accounts. Numbers are permanent identity — a typo'd NAME is
 * renamed here; a typo'd NUMBER is retired and re-created correctly, the
 * same way a bad SKU is. Nothing is ever deleted.
 */
final class AccountController
{
    public static function index(): void
    {
        Auth::requireRole('ADMIN');

        // How many catalog items point at each number, so the page can show
        // what a retire would take out of the pickers.
        $usage = [];
        foreach (Db::all(
            "SELECT revenue_account a, COUNT(*) n FROM catalog_items WHERE revenue_account != '' GROUP BY revenue_account
             UNION ALL
             SELECT cogs_account, COUNT(*) FROM catalog_items WHERE cogs_account != '' GROUP BY cogs_account"
        ) as $r) {
            $usage[$r['a']] = ($usage[$r['a']] ?? 0) + (int) $r['n'];
        }

        View::render('pages/accounts', [
            'title' => 'Accounts', 'crumb' => 'Admin', 'nav' => 'accounts',
            'rows'  => Accounts::all(),
            'usage' => $usage,
        ]);
    }

    /**
     * Create an account. Serves two callers: the catalog form's picker posts
     * with json=1 and gets JSON back (so the half-filled Add-item modal is
     * never lost), and the accounts page posts a plain form and gets a
     * flash + redirect. Validation lives in Accounts::create — this is
     * transport.
     */
    public static function store(): void
    {
        Auth::requireRole('ADMIN');
        $number = trim((string) input('account_number', ''));
        $name   = trim((string) input('name', ''));
        $type   = strtoupper(trim((string) input('account_type', '')));
        $json   = input('json') !== null;

        $errors = Accounts::create($number, $name, $type);

        if ($json) {
            header('Content-Type: application/json');
            echo $errors
                ? json_encode(['ok' => false, 'error' => implode(' ', $errors)])
                : json_encode(['ok' => true, 'account' => ['number' => $number, 'name' => $name, 'type' => $type]]);
            return;
        }
        if ($errors) { flash(implode(' ', $errors), 'err'); }
        else         { flash("Account $number added.", 'ok'); }
        redirect('/accounts');
    }

    /** Rename only — the number is identity and cannot change. */
    public static function rename(array $a): void
    {
        Auth::requireRole('ADMIN');
        $id   = (int) $a['id'];
        $acct = Db::one('SELECT * FROM gl_accounts WHERE id = ?', [$id]);
        $name = trim((string) input('name', ''));
        if (!$acct)        { flash('That account is not in the chart — it may have been deleted from another tab. The list has been refreshed; pick again.', 'err'); redirect('/accounts'); }
        if ($name === '')  { flash('The account needs a name that says what the money in it means — add one and save again.', 'err'); redirect('/accounts'); }
        if ($name !== $acct['name']) {
            Db::update('gl_accounts', $id, ['name' => $name]);
            Audit::log('gl_account', $id, 'renamed', $acct['name'] . ' → ' . $name);
            flash('Account ' . $acct['account_number'] . ' renamed.', 'ok');
        }
        redirect('/accounts');
    }

    /**
     * Retire / restore. Retiring removes the account from the pickers only —
     * catalog items and documents that carry the number keep it.
     */
    public static function toggle(array $a): void
    {
        Auth::requireRole('ADMIN');
        $id   = (int) $a['id'];
        $acct = Db::one('SELECT * FROM gl_accounts WHERE id = ?', [$id]);
        if (!$acct) { flash('That account is not in the chart — it may have been deleted from another tab. The list has been refreshed; pick again.', 'err'); redirect('/accounts'); }
        $active = (int) $acct['is_active'] === 1 ? 0 : 1;
        Db::update('gl_accounts', $id, ['is_active' => $active]);
        Audit::log('gl_account', $id, $active ? 'restored' : 'retired', $acct['account_number']);
        flash('Account ' . $acct['account_number'] . ($active ? ' restored.' : ' retired. Items already using it are unaffected.'), 'ok');
        redirect('/accounts');
    }

    /**
     * Delete an account outright. Retire is still there for the case where
     * history matters; this is for the case where the account was a mistake.
     *
     * Validation and the tombstone live in Accounts::delete — this is
     * transport, same as store().
     */
    public static function destroy(array $a): void
    {
        Auth::requireRole('ADMIN');
        $id   = (int) $a['id'];
        $acct = Db::one('SELECT * FROM gl_accounts WHERE id = ?', [$id]);
        if (!$acct) { flash('That account is not in the chart — it may have been deleted from another tab. The list has been refreshed; pick again.', 'err'); redirect('/accounts'); }

        $number = (string) $acct['account_number'];
        $used   = Accounts::usage($number);
        $errors = Accounts::delete($id);

        if ($errors) { flash(implode(' ', $errors), 'err'); redirect('/accounts'); }

        /* Say what the deletion touched rather than leaving it to be
         * discovered later on a picker that no longer matches. */
        $note = '';
        if ($used) {
            $parts = [];
            foreach ($used as $table => $n) {
                $parts[] = $n . ' ' . str_replace('_', ' ', $table);
            }
            $note = ' Still referenced by ' . implode(', ', $parts)
                  . ' — posted lines keep their own copy of the number and name.';
        }
        flash("Account $number deleted." . $note, 'ok');
        redirect('/accounts');
    }
}

final class ExpenseController
{
    public static function index(): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        View::render('pages/exp_index', [
            'title' => 'Expenses', 'crumb' => 'Money', 'nav' => 'expenses',
            'rows'  => Db::all('SELECT * FROM expenses ORDER BY expense_date DESC, id DESC LIMIT 200'),
            'mtd'   => (float) Db::val('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE substr(expense_date,1,7) = ?', [date('Y-m')]),
            'srs'   => Db::all('SELECT id, doc_number FROM service_requests ORDER BY id DESC LIMIT 50'),
        ]);
    }

    public static function store(): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        /* The account code must exist in the chart — a typo ("50000" for
         * "5000") used to post cleanly with a NULL type on the journal line,
         * and lines with no type silently drop out of every type-filtered
         * report: an expense in the journal but missing from the P&L (fixed
         * 2026-08-27). Blank stays legal — it falls back to 6900. */
        $code = trim((string) input('account_code', ''));
        if ($code !== '' && !Db::one('SELECT account_number FROM gl_accounts WHERE account_number = ?', [$code])) {
            keep_input('/expenses');
            flash('Account ' . e($code) . ' is not in the chart of accounts. Pick a real account, '
                . 'or leave it blank to post to 6900 Other Expenses.', 'err');
            redirect('/expenses');
        }
        $id = Db::tx(static function () use ($code): int {
            $id = Db::insert('expenses', [
                'doc_number'         => DocNumber::next('EXP'),
                'vendor_name'        => (string) input('vendor_name', ''),
                'category'           => (string) input('category', ''),
                'account_code'       => $code,
                'description'        => (string) input('description', ''),
                'amount'             => num('amount'),
                'tax_amount'         => num('tax_amount'),
                'expense_date'       => (string) input('expense_date', date('Y-m-d')),
                'payment_method'     => (string) input('payment_method', 'CARD'),
                'service_request_id' => intval_or_null('service_request_id'),
                'notes'              => (string) input('notes', ''),
                'created_at'         => now(),
            ]);
            /* An expense with no account code posts to 6900 Other Expenses
             * rather than being dropped — an unposted expense understates cost
             * and overstates profit, which is the worse of the two errors. */
            Posting::expenseRecorded(Db::one('SELECT * FROM expenses WHERE id = ?', [$id]) ?? []);
            return $id;
        });
        Audit::log('expense', $id, 'created');
        flash('Expense recorded.', 'ok');
        redirect('/expenses');
    }
}

final class MessageController
{
    public static function index(): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        View::render('pages/msg_index', [
            'title' => 'Messages', 'crumb' => 'Records', 'nav' => 'messages',
            'rows'  => Db::all(
                'SELECT m.*, c.first_name, c.last_name, c.company, s.doc_number sr_no
                 FROM messages m
                 LEFT JOIN customers c ON c.id = m.customer_id
                 LEFT JOIN service_requests s ON s.id = m.service_request_id
                 ORDER BY m.id DESC LIMIT 200'
            ),
            'receiptHealth' => self::receiptHealth(),
        ]);
    }

    /**
     * What the /messages page warns about: the configuration checks live in
     * Health (one source, shared with the every-page banner and the settings
     * gate), plus one thing only this page adds — the most recent REFUSED
     * callback, with the cause the webhook handler logged for it. That is a
     * live observation rather than a configuration state, which is why it is
     * here and not in Health.
     */
    private static function receiptHealth(): array
    {
        $sms = Integrations::sms();
        if ($sms->driverName() !== 'telnyx') { return []; }

        $issues = array_merge(Health::smsSend(), Health::smsReceipts());

        $rej = Db::one(
            "SELECT detail, created_at FROM api_log
             WHERE service = 'sms' AND operation = 'webhook:rejected'
             ORDER BY id DESC LIMIT 1");
        if ($rej) {
            $issues[] = ['what' => 'A delivery callback was refused ' . ago((string) $rej['created_at'])
                                 . ' — ' . (string) $rej['detail'],
                         'fix'  => 'If this repeats, the cause named above is current, not historical.'];
        }

        return $issues;
    }

    /*
     * There is deliberately NO "mark sent" action. A message either went out
     * through the connected carrier or it did not go out at all. Texting a
     * customer from a private phone is outside the scope of this application —
     * the person who does it has stepped outside the system, and the system
     * does not record or facilitate it (owner's decision, 2026-08-06). A
     * "sent" the carrier never saw would be a fabricated delivery record.
     */

    /**
     * Records a consent change the carrier cannot see — a caller says "stop
     * texting me" on the phone, or asks at the counter to get texts again.
     *
     * The obligation to honour a verbal STOP is identical to a texted one; the
     * EVIDENCE is different, and this records the evidence honestly: who took
     * the call, what was said, applied through the same Consent logic the
     * Telnyx webhook uses. An earlier version fed a pretend inbound SMS
     * through the webhook handler, which wrote a message row for a text that
     * never existed — a fabricated record, replaced by this.
     */
    public static function recordConsent(): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');

        $phone  = phone_to_e164((string) input('phone', ''));
        $action = (string) input('consent_action', '');
        $note   = trim((string) input('note', ''));

        if (!$phone || !in_array($action, ['stop', 'start'], true)) {
            flash('A valid number and an opt-out / opt-in choice are required.', 'err');
            redirect('/messages');
        }
        if ($note === '') {
            flash('Say how you learned it — "caller said stop texting on today\'s call". '
                . 'That sentence is the evidence of compliance.', 'err');
            redirect('/messages');
        }

        $u   = Auth::user();
        $how = ($action === 'stop' ? 'VERBAL opt-out' : 'VERBAL opt-in')
             . ' recorded by ' . $u['first_name'] . ' ' . $u['last_name'] . ': ' . $note;

        $cust = Db::one('SELECT * FROM customers WHERE phone_e164 = ?', [$phone]);
        $hits = 0;

        if ($cust) {
            // optOut() also clears intake consent on the customer's open
            // requests — the same routine the texted STOP uses.
            $action === 'stop'
                ? Consent::optOut($cust, 'revoked_verbal', $how)
                : Consent::optIn($cust, 'granted_verbal', $how);
            $hits++;
        } elseif ($action === 'stop') {
            // A stranded caller may exist only as a service request — intake
            // consent lives on the request until a customer record does. A
            // verbal stop covers those too; compliance does not wait for promotion.
            $hits += Consent::revokeRequests($phone, $how);
        }

        if ($hits === 0) {
            flash('No customer or request carries ' . e(phone_display($phone))
                . ' — there was nothing to change, and nothing would have been texted to it anyway.', 'warn');
        } else {
            flash($action === 'stop'
                ? 'Opt-out recorded and in force immediately. Nothing will be texted to '
                    . e(phone_display($phone)) . ' until they opt back in.'
                : 'Opt-in recorded for ' . e(phone_display($phone)) . '.', 'ok');
        }
        redirect('/messages');
    }
}

final class ReportController
{
    public static function index(): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        $from = (string) input('from', date('Y-m-01'));
        $to   = (string) input('to', date('Y-m-d'));

        View::render('pages/reports', [
            'title' => 'Reports', 'crumb' => 'Money', 'nav' => 'reports', 'from' => $from, 'to' => $to,
            'revenue' => (float) Db::val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='COMPLETED' AND substr(paid_at,1,10) BETWEEN ? AND ?", [$from, $to]),
            'tips'    => (float) Db::val("SELECT COALESCE(SUM(tip_amount),0) FROM payments WHERE substr(paid_at,1,10) BETWEEN ? AND ?", [$from, $to]),
            'expense' => (float) Db::val("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date BETWEEN ? AND ?", [$from, $to]),
            'ar'      => (float) Db::val("SELECT COALESCE(SUM(balance_due),0) FROM invoices WHERE status IN ('ISSUED','PARTIAL')"),
            'byService' => Db::all(
                "SELECT l.item_type, l.name, COUNT(*) n, SUM(l.line_total) revenue, SUM(l.unit_cost*l.qty) cost
                 FROM doc_lines l JOIN invoices i ON i.id = l.doc_id AND l.doc_type='INV'
                 WHERE i.status IN ('ISSUED','PARTIAL','PAID') AND substr(i.issued_at,1,10) BETWEEN ? AND ?
                 GROUP BY l.item_type, l.name ORDER BY revenue DESC LIMIT 25", [$from, $to]
            ),
            'bySource' => Db::all(
                "SELECT s.job_source, COUNT(DISTINCT i.id) jobs, COALESCE(SUM(i.total),0) revenue
                 FROM invoices i JOIN service_requests s ON s.id = i.service_request_id
                 WHERE i.status IN ('ISSUED','PARTIAL','PAID') AND substr(i.issued_at,1,10) BETWEEN ? AND ?
                 GROUP BY s.job_source", [$from, $to]
            ),
            'byMethod' => Db::all(
                "SELECT method, COUNT(*) n, SUM(amount) total FROM payments
                 WHERE substr(paid_at,1,10) BETWEEN ? AND ? GROUP BY method ORDER BY total DESC", [$from, $to]
            ),
            'unpaid' => Db::all(
                "SELECT i.*, c.first_name, c.last_name, c.company FROM invoices i
                 JOIN customers c ON c.id = i.customer_id
                 WHERE i.status IN ('ISSUED','PARTIAL') ORDER BY i.due_at"
            ),
        ]);
    }
}

/**
 * The Telnyx configuration check, run where it means something.
 *
 * This exists as a page and not only as a CLI script because the answer depends
 * on WHICH install is asking: the profile id, the public key and the base URL
 * all come from the settings of the machine running the check. Run from a
 * workstation against a development database, half the output describes a
 * database that never receives a callback, while looking exactly like a report
 * about production. Served from the live site, every value is the real one.
 */
final class TelnyxCheckController
{
    public static function index(): void
    {
        Auth::requireRole('ADMIN');
        View::render('pages/telnyx_check', [
            'title' => 'Telnyx check', 'crumb' => 'Admin', 'nav' => 'telnyx-check',
            'audit' => TelnyxAudit::run(),
        ]);
    }
}

/**
 * THE OUTSIDE-CALL LOG, READ BACK.
 *
 * api_log has been written faithfully since the first integration went in, and
 * until now nothing read it. That is the worst shape for a diagnostic record:
 * the evidence exists, so nobody adds a different one, but it cannot be seen
 * without database access — which on SiteGround means SSH for a question as
 * ordinary as "did that webhook arrive?".
 *
 * A rejected callback is the case this screen is really for. Square answers a
 * 403 with a retry and nothing else; the reason lives only here.
 */
final class ApiLogController
{
    private const PER_PAGE = 200;

    public static function index(): void
    {
        Auth::requireRole('ADMIN');

        $service = trim((string) ($_GET['service'] ?? ''));
        $outcome = trim((string) ($_GET['outcome'] ?? ''));
        $q       = trim((string) ($_GET['q'] ?? ''));

        $where = [];
        $args  = [];
        if ($service !== '') { $where[] = 'service = ?';   $args[] = $service; }
        if ($outcome === 'fail') { $where[] = 'ok = 0'; }
        if ($outcome === 'ok')   { $where[] = 'ok = 1'; }
        /* Reference and detail both, because you rarely know which one holds
         * the string you half-remember — a payment id lives in reference, the
         * reason a signature failed lives in detail. */
        if ($q !== '') {
            $where[] = '(reference LIKE ? OR detail LIKE ? OR operation LIKE ?)';
            $like = '%' . $q . '%';
            $args[] = $like; $args[] = $like; $args[] = $like;
        }
        $sql = 'SELECT * FROM api_log'
             . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
             . ' ORDER BY id DESC LIMIT ' . self::PER_PAGE;

        View::render('pages/api_log', [
            'title' => 'Integration log', 'crumb' => 'Admin', 'nav' => 'api-log',
            'rows'      => Db::all($sql, $args),
            'services'  => Db::all('SELECT service, COUNT(*) n,
                                           SUM(CASE WHEN ok = 0 THEN 1 ELSE 0 END) bad
                                    FROM api_log GROUP BY service ORDER BY service'),
            'failures'  => (int) Db::val('SELECT COUNT(*) FROM api_log WHERE ok = 0', [], 0),
            'total'     => (int) Db::val('SELECT COUNT(*) FROM api_log', [], 0),
            'f'         => ['service' => $service, 'outcome' => $outcome, 'q' => $q],
            'per_page'  => self::PER_PAGE,
        ]);
    }
}

final class SettingsController
{
    public static function index(): void
    {
        Auth::requireRole('ADMIN');
        View::render('pages/settings', [
            'title' => 'Settings', 'crumb' => 'Admin', 'nav' => 'settings',
            's'     => Db::all('SELECT * FROM settings'),
        ]);
    }

    /** Plain settings — saved exactly as typed, blank included. */
    public const KEYS = [
        'company_name', 'company_phone', 'company_email', 'company_address',
        'tax_rate', 'labor_rate', 'mileage_rate', 'core_forfeit_days',
        'invoice_terms', 'invoice_footer', 'estimate_terms',
        'app_base_url', 'telnyx_from', 'telnyx_profile_id',
        'square_location_id', 'square_environment',
        'driver_sms', 'driver_payments', 'driver_geocoder',
        'driver_routes', 'driver_partnum', 'anthropic_model', 'partnum_rules',
    ];

    /**
     * Credentials. Rendered masked and only written when something was actually
     * typed, so saving the form with the field left blank keeps the stored key
     * rather than silently disconnecting the integration.
     */
    public const SECRETS = [
        'telnyx_api_key', 'telnyx_public_key',
        'square_access_token', 'square_signature_key', 'google_maps_key',
        'anthropic_api_key',
    ];

    public static function save(): void
    {
        Auth::requireRole('ADMIN');

        /*
         * The stop. A live driver is either fully configured or not on —
         * there is no half-connected state to be discovered in the field by a
         * stranded customer waiting on a text that was never going to arrive.
         *
         * Judged against the state this save would produce: a secret typed in
         * THIS save counts, a stored one counts, blank-means-keep counts. A
         * driver that fails the check is forced back to its offline mode
         * (outbox / manual), the rest of the save goes through, and the error
         * says exactly which pieces are missing. Nothing is silently dropped.
         */
        $effective = static function (string $key): string {
            $typed = trim((string) input($key, ''));
            if ($typed === self::CLEAR) { return ''; }
            if ($typed !== '')          { return $typed; }
            return trim((string) App::setting($key, ''));
        };
        $forced = [];
        foreach ([['driver_sms', 'telnyx', 'outbox', 'SMS driver'],
                  ['driver_payments', 'square', 'manual', 'payment driver']] as [$key, $live, $offline, $label]) {
            if ((string) input($key, '') !== $live) { continue; }
            $missing = Health::missingFor($live, $effective);
            if ($missing === []) { continue; }
            $_POST[$key] = $offline;   // what put() below will now store
            $forced[] = ucfirst($label) . ' stays on ' . $offline . ' — ' . $live . ' is missing: '
                      . implode(', ', $missing) . '.';
        }

        foreach (self::KEYS as $k) {
            self::put($k, (string) input($k, ''));
        }

        $touched = [];
        foreach (self::SECRETS as $k) {
            $v = trim((string) input($k, ''));
            if ($v === '') { continue; }              // left blank: keep what is stored
            if ($v === self::CLEAR) { self::put($k, ''); $touched[] = $k . ' (cleared)'; continue; }
            self::put($k, $v);
            $touched[] = $k;
        }

        // Credential values are never written to the audit detail.
        Audit::log('settings', 0, 'updated',
            ($touched ? 'credentials changed: ' . implode(', ', $touched) : '')
            . ($forced ? ($touched ? ' · ' : '') . 'driver activation refused: ' . implode(' ', $forced) : ''));

        if ($forced) {
            flash('Settings saved, but a driver could not be turned on. '
                . e(implode(' ', $forced))
                . ' Add the missing pieces and save again.', 'err');
        } else {
            flash('Settings saved.', 'ok');
        }
        redirect('/settings');
    }

    /** Typed into a credential field to deliberately remove it. */
    public const CLEAR = '-';

    private static function put(string $key, string $value): void
    {
        Db::q('DELETE FROM settings WHERE skey = ?', [$key]);
        Db::insert('settings', ['skey' => $key, 'svalue' => $value]);
    }
}

final class UserController
{
    public static function index(): void
    {
        Auth::requireRole('ADMIN');
        Rules::setupAdminHeal();
        View::render('pages/users', [
            'title' => 'Users', 'crumb' => 'Admin', 'nav' => 'users',
            'rows'  => Db::all('SELECT * FROM users ORDER BY role, first_name'),
        ]);
    }

    public static function store(): void
    {
        Auth::requireRole('ADMIN');
        $email = strtolower(trim((string) input('email', '')));
        $pw    = (string) input('password', '');
        if ($email === '' || strlen($pw) < 8) {
            flash('An email and a password of at least 8 characters are required.', 'err');
            redirect('/users');
        }
        if (Db::one('SELECT id FROM users WHERE email = ?', [$email])) {
            flash('That email already belongs to a login — every user signs in with their own address. Use a different one, or reactivate the existing account from the list below.', 'err');
            redirect('/users');
        }
        $role = strtoupper(trim((string) input('role', 'TECHNICIAN')));
        if (!in_array($role, ['ADMIN', 'DISPATCH', 'TECHNICIAN'], true)) {
            flash('Pick a role from the list — that is not one of them.', 'err');
            redirect('/users');
        }
        Rules::setupAdminHeal();
        $id = Db::insert('users', [
            'role'          => $role,
            'first_name'    => (string) input('first_name', ''),
            'last_name'     => (string) input('last_name', ''),
            'email'         => $email,
            'phone_e164'    => phone_to_e164((string) input('phone')),
            'password_hash' => password_hash($pw, PASSWORD_DEFAULT),
            'is_active'     => 1,
            'is_setup'      => 0,
            'can_accept_jobs' => input('can_accept_jobs') ? 1 : 0,
            'notes'         => (string) input('notes', ''),
            'created_at'    => now(),
        ]);
        Audit::log('user', $id, 'created', $email);
        flash('User created.', 'ok');
        // A real admin now exists — the temporary setup login is done.
        foreach (Rules::retireSetupAdmins() as $u) {
            flash('Temporary setup login ' . e($u['email']) . ' has been deactivated. '
                . 'Sign in with the new admin account from now on.', 'warn');
        }
        redirect('/users');
    }

    /** Activate / deactivate a login. Never deletes; the row and its audit trail stay. */
    public static function toggle(array $a): void
    {
        Auth::requireRole('ADMIN');
        $id = (int) $a['id'];
        $u  = Db::one('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$u) {
            flash('That user is not on file — the list you were looking at was stale. It has been refreshed; try again.', 'err');
            redirect('/users');
        }
        $active = (int) $u['is_active'] === 1;
        if ($active && $id === (int) (Auth::user()['id'] ?? 0)) {
            flash('You cannot deactivate the account you are signed in with.', 'err');
            redirect('/users');
        }
        if ($active && $u['role'] === 'ADMIN'
            && (int) Db::val("SELECT COUNT(*) FROM users WHERE role = 'ADMIN' AND is_active = 1") <= 1) {
            flash('That is the last active admin — create another admin first.', 'err');
            redirect('/users');
        }
        Db::update('users', $id, ['is_active' => $active ? 0 : 1]);
        Audit::log('user', $id, $active ? 'deactivated' : 'reactivated', $u['email']);
        flash(($active ? 'Deactivated ' : 'Reactivated ') . e($u['email']) . '.', 'ok');
        redirect('/users');
    }

    public static function password(array $a): void
    {
        Auth::requireRole('ADMIN');
        $id = (int) $a['id'];
        $u  = Db::one('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$u) {
            flash('That user is not on file — the list you were looking at was stale. It has been refreshed; try again.', 'err');
            redirect('/users');
        }
        $pw = (string) input('password', '');
        if (strlen($pw) < 8) {
            flash('The new password must be at least 8 characters.', 'err');
            redirect('/users');
        }
        Db::update('users', $id, ['password_hash' => password_hash($pw, PASSWORD_DEFAULT)]);
        Audit::log('user', $id, 'password_reset', $u['email']);
        flash('Password updated for ' . e($u['email']) . '.', 'ok');
        redirect('/users');
    }
}

/**
 * The parts markup matrix editor.
 *
 * The whole matrix is edited and saved as one set so overlap/gap validation is
 * atomic — the stored tiers are only ever replaced wholesale by a validated
 * matrix, never left half-edited. Replacing tiers never touches historical
 * documents: line items snapshot their markup at creation (see Lines::add).
 */
final class MarkupController
{
    public static function index(): void
    {
        Auth::requireRole('ADMIN');
        View::render('pages/markup', [
            'title' => 'Markup matrix', 'crumb' => 'Admin', 'nav' => 'markup',
            'tiers' => Markup::tiers(),
            'errors' => [],
        ]);
    }

    public static function save(): void
    {
        Auth::requireRole('ADMIN');

        // Collect the submitted rows (parallel arrays), dropping fully blank ones.
        $mins = (array) input('min_cost', []);
        $maxs = (array) input('max_cost', []);
        $pcts = (array) input('markup_pct', []);

        $rows = [];
        foreach ($mins as $i => $min) {
            $min = trim((string) $min); $max = trim((string) ($maxs[$i] ?? '')); $pct = trim((string) ($pcts[$i] ?? ''));
            if ($min === '' && $max === '' && $pct === '') { continue; }
            $rows[] = ['min_cost' => $min, 'max_cost' => $max === '' ? null : $max, 'markup_pct' => $pct === '' ? '0' : $pct];
        }

        // Sort ascending by min cost, then validate the whole set.
        usort($rows, fn ($a, $b) => Markup::toCents($a['min_cost']) <=> Markup::toCents($b['min_cost']));
        $errors = Markup::validate($rows);

        if ($errors) {
            // Re-render with what they typed so nothing is lost.
            View::render('pages/markup', [
                'title' => 'Markup matrix', 'crumb' => 'Admin', 'nav' => 'markup',
                'tiers' => $rows, 'errors' => $errors,
            ]);
            return;
        }

        Db::tx(function () use ($rows) {
            Db::q('DELETE FROM markup_tiers');
            foreach (array_values($rows) as $i => $r) {
                Db::insert('markup_tiers', [
                    'min_cost'   => Markup::centsToStr(Markup::toCents($r['min_cost'])),
                    'max_cost'   => $r['max_cost'] === null ? null : Markup::centsToStr(Markup::toCents($r['max_cost'])),
                    'markup_pct' => Markup::centsToStr(Markup::pctToHundredths($r['markup_pct'])),
                    'sort_order' => $i,
                ]);
            }
        });

        Audit::log('markup_matrix', 0, 'updated', count($rows) . ' tiers');
        flash('Markup matrix saved. Existing quotes and invoices are unaffected.', 'ok');
        redirect('/markup');
    }
}
