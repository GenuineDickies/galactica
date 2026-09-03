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
 * Single source of truth for the database schema.
 * Emits dialect-correct DDL for sqlite and mysql so the two can never drift.
 *
 * The document chain:
 *   Service Request → Estimate → Work Order → Invoice → Payment → Receipt
 */
final class Schema
{
    public static function statements(string $driver): array
    {
        ['tables' => $t, 'indexes' => $indexes, 'mysql' => $mysql, 'suffix' => $suffix] = self::define($driver);

        $out    = [];
        $inline = [];
        if ($mysql) {
            foreach ($indexes as [$name, $table, $cols, $unique]) {
                $inline[$table][] = ($unique ? 'UNIQUE KEY ' : 'KEY ') . $name . ' (' . $cols . ')';
            }
        }

        foreach ($t as $name => $cols) {
            $body = $cols . (isset($inline[$name]) ? ",\n            " . implode(",\n            ", $inline[$name]) : '');
            $out[] = "CREATE TABLE IF NOT EXISTS $name ($body)$suffix";
        }

        if (!$mysql) {
            foreach ($indexes as [$name, $table, $cols, $unique]) {
                $kind  = $unique ? 'CREATE UNIQUE INDEX' : 'CREATE INDEX';
                $out[] = "$kind IF NOT EXISTS $name ON $table($cols)";
            }
        }

        return $out;
    }

    /**
     * The index declarations, for callers that need them apart from the DDL.
     *
     * Db::addMissingIndexes uses this to create indexes on tables that already
     * existed when the index was added — a case neither emission path covers,
     * because both only fire inside CREATE TABLE.
     *
     * @return array<int,array{0:string,1:string,2:string,3:bool}> [name, table, columns, unique]
     */
    public static function indexes(string $driver): array
    {
        return self::define($driver)['indexes'];
    }

    /**
     * table => [column => declaration], parsed from the same single definition.
     * Db::migrate() uses this to ALTER-add columns missing from an existing
     * database — the additive upgrade path. Constraint lines (PRIMARY KEY …)
     * are skipped; only real columns are returned.
     */
    public static function columns(string $driver): array
    {
        $out = [];
        foreach (self::define($driver)['tables'] as $table => $body) {
            /* STRIP COMMENTS BEFORE PARSING LINES. The DDL below carries
             * explanatory /* … *​/ blocks — a retired column says why it is
             * retired, right where someone would otherwise re-add it. MySQL
             * and SQLite both accept them inside CREATE TABLE, so the tables
             * built fine; the damage was here. Splitting on newlines turned
             * every comment line into a phantom column named '/*' or '*',
             * which no live table can ever have, so pending() reported them
             * missing forever and addMissingColumns() ran
             * `ALTER TABLE estimates ADD COLUMN /* …` and threw. That broke
             * install.php and wipe.php outright and put bogus rows on
             * /admin/schema. A comment must never be able to generate DDL. */
            $body = (string) preg_replace('#/\*.*?\*/#s', '', $body);

            foreach (explode("\n", $body) as $line) {
                $line = trim($line);
                $line = rtrim($line, ',');
                if ($line === '') { continue; }
                if (preg_match('/^(PRIMARY|UNIQUE|FOREIGN|CONSTRAINT|KEY|CHECK)\b/i', $line)) { continue; }
                $parts = preg_split('/\s+/', $line, 2);
                /* Belt and braces: only a real identifier can name a column. */
                if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $parts[0])) { continue; }
                $out[$table][$parts[0]] = trim($parts[1] ?? '');
            }
        }
        return $out;
    }

    /** The one place the schema is written down. */
    private static function define(string $driver): array
    {
        $mysql = ($driver === 'mysql');
        $pk    = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $money = 'DECIMAL(12,2)';
        $txt   = 'TEXT';
        $ts    = $mysql ? 'DATETIME' : 'TEXT';
        /* COLLATE stated explicitly: README and install.php create the
         * DATABASE as utf8mb4_unicode_ci, but a table that names only the
         * charset gets MySQL 8's default utf8mb4_0900_ai_ci instead — the
         * README's choice was silently a no-op at table level (2026-08-27). */
        $suffix= $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        $t = [];

        $t['users'] = "
            id              $pk,
            role            VARCHAR(16)  NOT NULL DEFAULT 'ADMIN',
            first_name      VARCHAR(64)  NOT NULL,
            last_name       VARCHAR(64)  NOT NULL,
            email           VARCHAR(160) NOT NULL,
            phone_e164      VARCHAR(16),
            password_hash   VARCHAR(255) NOT NULL,
            is_active       INTEGER NOT NULL DEFAULT 1,
            is_setup        INTEGER NOT NULL DEFAULT 0,
            can_accept_jobs INTEGER NOT NULL DEFAULT 1,
            notes           $txt,
            created_at      $ts";

        $t['settings'] = "
            skey            VARCHAR(64) PRIMARY KEY,
            svalue          $txt";

        $t['customers'] = "
            id                 $pk,
            customer_type      VARCHAR(16)  NOT NULL DEFAULT 'INDIVIDUAL',
            first_name         VARCHAR(64),
            last_name          VARCHAR(64),
            company            VARCHAR(160),
            phone_e164         VARCHAR(16)  NOT NULL,
            phone2_e164        VARCHAR(16),
            email              VARCHAR(160),
            address_line1      VARCHAR(160),
            city               VARCHAR(80),
            state              VARCHAR(2),
            postal_code        VARCHAR(10),
            is_provider        INTEGER NOT NULL DEFAULT 0,
            provider_code      VARCHAR(32),
            payment_terms      VARCHAR(32) DEFAULT 'DUE_ON_RECEIPT',
            sms_approved       INTEGER NOT NULL DEFAULT 0,
            sms_consent_at     $ts,
            sms_consent_source VARCHAR(48),
            do_not_contact     INTEGER NOT NULL DEFAULT 0,
            tax_exempt         INTEGER NOT NULL DEFAULT 0,
            notes              $txt,
            created_at         $ts,
            updated_at         $ts";

        $t['vehicles'] = "
            id              $pk,
            customer_id     INTEGER,
            vin             VARCHAR(17) NOT NULL,
            plate           VARCHAR(12),
            plate_state     VARCHAR(2),
            no_plate        INTEGER NOT NULL DEFAULT 0,
            no_plate_reason VARCHAR(32),
            year            SMALLINT,
            make            VARCHAR(48),
            model           VARCHAR(48),
            color           VARCHAR(24),
            odometer        INTEGER,
            unit_number     VARCHAR(32),
            vin_decoded     $txt,
            notes           $txt,
            created_at      $ts";

        /* Reference list of known year/make/model combinations, feeding the
         * intake form's cascading dropdowns. PURE REFERENCE DATA — nothing
         * points at it. The service request keeps storing v_year/v_make/
         * v_model as plain text (and every form keeps a free-text escape
         * hatch), so a 1987 motorhome or a farm tractor is always enterable
         * and reseeding this table never touches an existing document.
         * Seeded from data/vehicle-models.csv (see data/seed_vehicles.php);
         * source says where a row came from: 'csv' or 'vpic'. */
        $t['vehicle_catalog'] = "
            id         $pk,
            year       SMALLINT    NOT NULL,
            make       VARCHAR(48) NOT NULL,
            model      VARCHAR(64) NOT NULL,
            source     VARCHAR(12) NOT NULL DEFAULT 'csv',
            created_at $ts";

        /* category is the PRICE BOOK grouping — free text, as fine-grained as
         * the operator wants, and only ever a heading on /catalog.
         * service_category is the OPERATIONAL bucket the item rolls up to, one
         * of ServiceCategory::ALL. They are deliberately different axes: the
         * tire items sit under one price-book heading but split across two
         * operational categories, because a plug leaves the tire on its rim
         * (even when the wheel comes off the vehicle) and an internal patch
         * needs the bead broken off the rim. */
        $t['catalog_items'] = "
            id                   $pk,
            sku                  VARCHAR(48) NOT NULL,
            item_type            VARCHAR(12) NOT NULL DEFAULT 'SERVICE',
            category             VARCHAR(48),
            service_category     VARCHAR(16),
            name                 VARCHAR(160) NOT NULL,
            description          $txt,
            pricing_model        VARCHAR(16) NOT NULL DEFAULT 'FLAT',
            unit_price           $money NOT NULL DEFAULT 0,
            unit_cost            $money NOT NULL DEFAULT 0,
            price_overridden     INTEGER NOT NULL DEFAULT 0,
            uom                  VARCHAR(16) NOT NULL DEFAULT 'job',
            taxable              INTEGER NOT NULL DEFAULT 1,
            vehicle_not_required INTEGER NOT NULL DEFAULT 0,
            warranty_months      SMALLINT NOT NULL DEFAULT 0,
            mfr_warranty         VARCHAR(64),
            vendor_name          VARCHAR(160),
            vendor_part_number   VARCHAR(48),
            core_charge          $money NOT NULL DEFAULT 0,
            revenue_account      VARCHAR(8),
            cogs_account         VARCHAR(8),
            is_active            INTEGER NOT NULL DEFAULT 1,
            /* An ad-hoc charge slot rather than a real product. Two behaviours
             * follow from the one fact that the charge is priced by judgement
             * at the moment of sale: the line may carry a typed name of its
             * own instead of copying this item's, and the markup matrix is not
             * consulted, so the price entered is the price billed. The line
             * still points at THIS catalog_item_id, so a misc charge is as
             * traceable to a SKU and a revenue account as anything else — the
             * catalog-only rule is intact. See Lines::add(). */
            is_misc              INTEGER NOT NULL DEFAULT 0,
            sort_order           INTEGER NOT NULL DEFAULT 0";

        /* Parts markup matrix — ordered cost tiers, each with a markup %.
         * max_cost NULL means the open-ended top tier. Consulted only when a
         * price is suggested (catalog entry, line creation); never queried to
         * re-price a document after the fact. See the Markup service. */
        $t['markup_tiers'] = "
            id          $pk,
            min_cost    $money NOT NULL DEFAULT 0,
            max_cost    $money,
            markup_pct  DECIMAL(7,2) NOT NULL DEFAULT 0,
            sort_order  INTEGER NOT NULL DEFAULT 0";

        /* Chart of accounts. catalog_items.revenue_account / cogs_account
         * reference account_number (not id) — the number IS the identity in
         * accounting, and documents snapshot it as text the same way they
         * snapshot prices. An account can be retired (is_active=0) to take it
         * out of the pickers while keeping history readable, or deleted
         * outright. Seeded from Accounts::DEFAULTS. */
        $t['gl_accounts'] = "
            id              $pk,
            account_number  VARCHAR(8)   NOT NULL,
            name            VARCHAR(120) NOT NULL,
            account_type    VARCHAR(16)  NOT NULL,
            is_active       INTEGER NOT NULL DEFAULT 1,
            sort_order      INTEGER NOT NULL DEFAULT 0,
            created_at      $ts";

        /* Deleted seeded defaults.
         *
         * Accounts::ensureSeeded() is additive and runs on every read, so
         * deleting a row from gl_accounts is not enough — the next page load
         * puts it straight back. A tombstone records "this default was removed
         * on purpose" so the seeder skips it.
         *
         * Only DEFAULTS need tombstoning. An operator-created account has no
         * seed behind it and stays deleted on its own. Re-adding the number by
         * hand on /accounts clears the tombstone, because at that point the
         * operator has said plainly that they want it back. */
        $t['gl_account_tombstones'] = "
            id              $pk,
            account_number  VARCHAR(8) NOT NULL,
            deleted_by      INTEGER,
            created_at      $ts";

        /* -----------------------------------------------------------------
         * 1. SERVICE REQUEST
         *    The initial record that somebody needs service. It may arrive by
         *    phone, from the website, or electronically from a provider. It is
         *    NOT required to be accurate — roughly who, roughly what, roughly
         *    where. No pricing, no line items, no customer, no vehicle.
         *
         *    reported_service and service_category are two different things and
         *    neither replaces the other. reported_service is what the CALLER
         *    said ("flat tire") — testimony, never corrected, because the gap
         *    between what they said and what it was is worth measuring.
         *    service_category is what DISPATCH decided to roll for, which is
         *    what determines truck loadout. The caller can be wrong, so the
         *    work order carries its own service_category holding what the job
         *    actually turned out to be; SR vs WO is the dispatch accuracy rate.
         *
         *    ORDER OF ENTRY, THOUGH, IS CATEGORY FIRST. Dispatch picks the
         *    category and reported_service narrows to the jobs that category
         *    can roll, so the pair is consistent by construction rather than
         *    by a rep classifying a job after naming it. Both columns stay
         *    VARCHAR and neither is an enum: reported_service still holds
         *    retired types from before the split (ServiceCategory::RETIRED),
         *    which are read and re-saved but never offered again.
         * --------------------------------------------------------------- */
        $t['service_requests'] = "
            id                 $pk,
            doc_number         VARCHAR(32) NOT NULL,
            channel            VARCHAR(16) NOT NULL DEFAULT 'PHONE',
            status             VARCHAR(16) NOT NULL DEFAULT 'PENDING',
            job_source         VARCHAR(16) NOT NULL DEFAULT 'RETAIL',
            provider_id        INTEGER,
            provider_ref       VARCHAR(64),
            priority           VARCHAR(16) NOT NULL DEFAULT 'STANDARD',
            customer_id        INTEGER,
            reported_name      VARCHAR(120),
            reported_phone     VARCHAR(16),
            reported_service   VARCHAR(32),
            service_category   VARCHAR(16),
            reported_problem   $txt,
            reported_location  VARCHAR(255),
            city               VARCHAR(80),
            state              VARCHAR(2),
            postal_code        VARCHAR(10),
            latitude           DECIMAL(10,7),
            longitude          DECIMAL(10,7),
            nearest_address    VARCHAR(255),
            nearest_intersection VARCHAR(160),
            location_captured_at $ts,
            v_year             SMALLINT,
            v_make             VARCHAR(48),
            v_model            VARCHAR(48),
            v_color            VARCHAR(24),
            v_plate            VARCHAR(12),
            v_plate_state      VARCHAR(2),
            promised_eta       $ts,
            comms_consent      INTEGER NOT NULL DEFAULT 0,
            intake_notes       $txt,
            close_reason       VARCHAR(160),
            created_by         INTEGER,
            created_at         $ts,
            updated_at         $ts";

        /* -----------------------------------------------------------------
         * 2. ESTIMATE
         *    Verified customer, priced scope, customer authorization.
         *    This is the contract. Created by promoting a request.
         *
         *    authorized_at and signature_at are deliberately separate events.
         *    Signatures are only ever captured on scene, so an estimate over
         *    the threshold is authorized VERBALLY first — that is what releases
         *    the technician — and signed on arrival. authorized_at holds the
         *    verbal moment; signature_at holds the moment ink hit the screen.
         *    The gap between them is normal, and their order relative to the
         *    work order's work_started_at is the evidence that the customer
         *    authorized the work before it was performed.
         * --------------------------------------------------------------- */
        $t['estimates'] = "
            id                   $pk,
            doc_number           VARCHAR(32) NOT NULL,
            service_request_id   INTEGER NOT NULL,
            customer_id          INTEGER NOT NULL,
            vehicle_id           INTEGER,
            status               VARCHAR(16) NOT NULL DEFAULT 'DRAFT',
            service_type         VARCHAR(32),
            po_number            VARCHAR(64),
            scope_summary        $txt,
            /* service_address was retired in favour of nearest_address: a job
             * has one physical address, and it is the nearest one to the pin.
             * New installs never get the column; existing databases keep it
             * until data/drop-service-address.php is run deliberately. */
            city                 VARCHAR(80),
            state                VARCHAR(2),
            postal_code          VARCHAR(10),
            latitude             DECIMAL(10,7),
            longitude            DECIMAL(10,7),
            nearest_address      VARCHAR(255),
            nearest_intersection VARCHAR(160),
            location_captured_at $ts,
            subtotal             $money NOT NULL DEFAULT 0,
            discount_total       $money NOT NULL DEFAULT 0,
            tax_rate             DECIMAL(6,4) NOT NULL DEFAULT 0,
            tax_total            $money NOT NULL DEFAULT 0,
            total                $money NOT NULL DEFAULT 0,
            authorized_by        VARCHAR(120),
            authorization_method VARCHAR(24),
            authorized_at        $ts,
            signature_data       $txt,
            signature_at         $ts,
            authorization_ip     VARCHAR(64),
            authorization_agent  VARCHAR(255),
            decline_reason       VARCHAR(160),
            terms_text           $txt,
            customer_notes       $txt,
            /* A repair OPTION offered on a diagnostic report — replace the
             * pump, or replace the impeller only. Each option is an ordinary
             * estimate on the same request, so lines, pricing, authorization
             * and dispatch all work unchanged; these three columns are the
             * only difference. The customer authorizes ONE; its siblings are
             * declined as superseded (Rules::optionAuthorizeGate). */
            diagnostic_report_id INTEGER,
            option_label         VARCHAR(80),
            option_timeframe     VARCHAR(120),
            created_at           $ts,
            updated_at           $ts";

        /* 3. WORK ORDER — what activates a technician.
         *
         *    work_started_at is the moment a hand went on the vehicle. It is a
         *    gated transition (IN_PROGRESS), not a side effect of arriving:
         *    ON_SITE means the technician is there and can still price the real
         *    scope, and work may not start until the estimate carries a
         *    signature. Recording the start separately is what makes
         *    "signed before work began" provable rather than assumed. */
        $t['work_orders'] = "
            id                 $pk,
            doc_number         VARCHAR(32) NOT NULL,
            estimate_id        INTEGER NOT NULL,
            service_request_id INTEGER NOT NULL,
            technician_id      INTEGER,
            status             VARCHAR(16) NOT NULL DEFAULT 'PENDING',
            service_category   VARCHAR(16),
            po_number          VARCHAR(64),
            outcome_code       VARCHAR(32),
            assigned_at        $ts,
            en_route_at        $ts,
            on_site_at         $ts,
            work_started_at    $ts,
            completed_at       $ts,
            odometer           INTEGER,
            /* Where the technician's truck was when they shared their position
             * (texted locate link at assign — the same mechanism the customer
             * gets), and the route plotted from there to the job pin: actual
             * drive miles and minutes, as a matter of record. Snapshots —
             * recalculating later or switching the routing driver never
             * rewrites what was measured at the time. */
            tech_latitude       DECIMAL(10,7),
            tech_longitude      DECIMAL(10,7),
            tech_located_at     $ts,
            drive_miles         DECIMAL(7,1),
            drive_minutes       INTEGER,
            route_driver        VARCHAR(16),
            route_calculated_at $ts,
            field_notes        $txt,
            auth_signer_name   VARCHAR(120),
            auth_signature     $txt,
            auth_signed_at     $ts,
            auth_method        VARCHAR(16),
            auth_ip            VARCHAR(64),
            auth_agent         VARCHAR(255),
            signer_name        VARCHAR(120),
            signature_data     $txt,
            signed_at          $ts,
            signed_method      VARCHAR(16),
            unsigned_reason    VARCHAR(160),
            created_at         $ts,
            updated_at         $ts";

        /**
         * A request for a customer signature on a document.
         *
         * Mirrors payment_links: an unguessable token is the whole of the
         * access control, because the person opening it is not a user of this
         * system — they have a text message and nothing else.
         *
         * purpose AUTH gates the start of work; COMPLETION is the sign-off
         * that the job was done, which is asked for but never forced.
         * Every step is timestamped, because when the customer saw the
         * document and when they signed it is the evidence.
         */
        $t['signature_requests'] = "
            id            $pk,
            token         VARCHAR(64) NOT NULL,
            doc_type      VARCHAR(4)  NOT NULL,
            doc_id        INTEGER     NOT NULL,
            purpose       VARCHAR(12) NOT NULL DEFAULT 'AUTH',
            customer_id   INTEGER     NOT NULL,
            channel       VARCHAR(12) NOT NULL DEFAULT 'SMS',
            phone_e164    VARCHAR(16),
            status        VARCHAR(12) NOT NULL DEFAULT 'OPEN',
            amount        $money NOT NULL DEFAULT 0,
            sent_at       $ts,
            viewed_at     $ts,
            signed_at     $ts,
            signer_name   VARCHAR(120),
            void_reason   VARCHAR(160),
            signed_ip     VARCHAR(64),
            signed_agent  VARCHAR(255),
            created_by    INTEGER,
            created_at    $ts";

        /**
         * A texted request for the customer's GPS position. Same shape of
         * thing as a signature request: an unguessable token IS the access
         * control, because the stranded caller is not a user of this system.
         *
         * One-shot and short-lived — the link answers "where are you right
         * now", which is stale within hours. What comes back (coordinates,
         * the nearest address, the nearest intersection) is snapshotted onto
         * the document that asked, and this row keeps the evidence of when
         * the link was sent, opened, and answered.
         */
        $t['location_requests'] = "
            id                   $pk,
            token                VARCHAR(64) NOT NULL,
            doc_type             VARCHAR(4)  NOT NULL,
            doc_id               INTEGER     NOT NULL,
            service_request_id   INTEGER,
            customer_id          INTEGER,
            phone_e164           VARCHAR(16),
            status               VARCHAR(12) NOT NULL DEFAULT 'OPEN',
            expires_at           $ts,
            sent_at              $ts,
            viewed_at            $ts,
            received_at          $ts,
            latitude             DECIMAL(10,7),
            longitude            DECIMAL(10,7),
            accuracy_m           DECIMAL(8,1),
            nearest_address      VARCHAR(255),
            nearest_intersection VARCHAR(160),
            geo_driver           VARCHAR(24),
            void_reason          VARCHAR(160),
            received_ip          VARCHAR(64),
            received_agent       VARCHAR(255),
            created_by           INTEGER,
            created_at           $ts";

        /* 4. INVOICE */
        $t['invoices'] = "
            id                  $pk,
            doc_number          VARCHAR(32) NOT NULL,
            service_request_id  INTEGER NOT NULL,
            estimate_id         INTEGER NOT NULL,
            work_order_id       INTEGER,
            customer_id         INTEGER NOT NULL,
            vehicle_id          INTEGER,
            no_vehicle_reason   VARCHAR(160),
            status              VARCHAR(16) NOT NULL DEFAULT 'DRAFT',
            po_number           VARCHAR(64),
            terms               VARCHAR(32),
            issued_at           $ts,
            due_at              $ts,
            subtotal            $money NOT NULL DEFAULT 0,
            discount_total      $money NOT NULL DEFAULT 0,
            tax_rate            DECIMAL(6,4) NOT NULL DEFAULT 0,
            tax_total           $money NOT NULL DEFAULT 0,
            total               $money NOT NULL DEFAULT 0,
            amount_paid         $money NOT NULL DEFAULT 0,
            balance_due         $money NOT NULL DEFAULT 0,
            variance_amount     $money NOT NULL DEFAULT 0,
            variance_authorized INTEGER NOT NULL DEFAULT 0,
            variance_auth_name  VARCHAR(120),
            variance_auth_at    $ts,
            variance_signature  $txt,
            void_reason         VARCHAR(160),
            notes               $txt,
            created_at          $ts,
            updated_at          $ts";

        /* Polymorphic line items, snapshotted from the catalog at add time.
         *
         * revenue_account / cogs_account / core_charge are snapshotted for the
         * same reason unit_price and markup_pct are: the ledger posts from the
         * LINE, not from the catalog item behind it. Re-pointing a catalog item
         * at a different revenue account, or changing a part's core value, must
         * never silently rewrite what an issued invoice already posted. */
        $t['doc_lines'] = "
            id                   $pk,
            doc_type             VARCHAR(4) NOT NULL,
            doc_id               INTEGER NOT NULL,
            line_no              INTEGER NOT NULL DEFAULT 1,
            catalog_item_id      INTEGER NOT NULL,
            sku                  VARCHAR(48),
            item_type            VARCHAR(12),
            name                 VARCHAR(160),
            description          $txt,
            qty                  DECIMAL(10,2) NOT NULL DEFAULT 1,
            uom                  VARCHAR(16),
            unit_price           $money NOT NULL DEFAULT 0,
            unit_cost            $money NOT NULL DEFAULT 0,
            markup_pct           DECIMAL(7,2),
            suggested_price      $money,
            price_overridden     INTEGER NOT NULL DEFAULT 0,
            taxable              INTEGER NOT NULL DEFAULT 1,
            vehicle_not_required INTEGER NOT NULL DEFAULT 0,
            discount_amount      $money NOT NULL DEFAULT 0,
            line_total           $money NOT NULL DEFAULT 0,
            warranty_months      SMALLINT NOT NULL DEFAULT 0,
            mfr_warranty         VARCHAR(64),
            core_charge          $money NOT NULL DEFAULT 0,
            revenue_account      VARCHAR(8),
            cogs_account         VARCHAR(8),
            notes                VARCHAR(255)";

        /* 5. PAYMENT   6. RECEIPT */
        $t['payments'] = "
            id              $pk,
            doc_number      VARCHAR(32) NOT NULL,
            invoice_id      INTEGER NOT NULL,
            customer_id     INTEGER NOT NULL,
            method          VARCHAR(16) NOT NULL,
            amount          $money NOT NULL DEFAULT 0,
            tip_amount      $money NOT NULL DEFAULT 0,
            /* Money above the bill that arrived WITHOUT being labelled a tip.
             * Held on 2060 Customer Refunds Payable until a person says what
             * it was: HELD -> TIP (reclassified to 4300) or REFUNDED (paid
             * back out). Extra money is never guessed into revenue
             * (owner's decision, 2026-08-27). NULL status = no unlabelled
             * extra on this payment. */
            overpayment_amount $money NOT NULL DEFAULT 0,
            overpayment_status VARCHAR(16),
            reference       VARCHAR(120),
            processor       VARCHAR(24),
            processor_ref   VARCHAR(120),
            idempotency_key VARCHAR(120),
            status          VARCHAR(16) NOT NULL DEFAULT 'COMPLETED',
            paid_at         $ts,
            note            VARCHAR(255),
            created_at      $ts";

        /**
         * A hosted checkout page issued for an invoice. The provider's order id
         * is what a payment webhook arrives carrying, so this table is how a
         * callback finds its way back to the right invoice.
         */
        $t['payment_links'] = "
            id          $pk,
            invoice_id  INTEGER NOT NULL,
            provider    VARCHAR(24) NOT NULL,
            link_id     VARCHAR(120),
            order_id    VARCHAR(120),
            url         VARCHAR(512),
            amount      $money NOT NULL DEFAULT 0,
            status      VARCHAR(16) NOT NULL DEFAULT 'OPEN',
            created_by  INTEGER,
            created_at  $ts";

        $t['receipts'] = "
            id         $pk,
            doc_number VARCHAR(32) NOT NULL,
            payment_id INTEGER NOT NULL,
            invoice_id INTEGER NOT NULL,
            issued_at  $ts";

        $t['expenses'] = "
            id                 $pk,
            doc_number         VARCHAR(32) NOT NULL,
            vendor_name        VARCHAR(160),
            category           VARCHAR(64),
            account_code       VARCHAR(8),
            description        VARCHAR(255),
            amount             $money NOT NULL DEFAULT 0,
            tax_amount         $money NOT NULL DEFAULT 0,
            expense_date       VARCHAR(10),
            payment_method     VARCHAR(24),
            service_request_id INTEGER,
            notes              $txt,
            created_at         $ts";

        /* Delivery of an outbound message is three separate facts, and only the
         * middle one used to be recorded.
         *
         *   sent_at      we handed it to the carrier
         *   delivered_at the carrier confirmed it reached the handset
         *   status       where it got to, including UNCONFIRMED — the carrier
         *                returned no receipt, which is NOT a failure
         *
         * Only delivered_at answers whether the customer had the ETA text
         * before the truck arrived. failure_reason carries the provider error
         * code and title, because a number out of service and a message blocked
         * as spam need opposite responses from a dispatcher.
         *
         * NB: comments belong out here, not inside the string — Schema::columns()
         * parses that body line by line and would read a comment as a column. */
        $t['messages'] = "
            id                 $pk,
            customer_id        INTEGER,
            service_request_id INTEGER,
            direction          VARCHAR(8) NOT NULL DEFAULT 'OUT',
            channel            VARCHAR(8) NOT NULL DEFAULT 'sms',
            phone_e164         VARCHAR(16),
            template           VARCHAR(48),
            body               $txt,
            status             VARCHAR(16) NOT NULL DEFAULT 'QUEUED',
            blocked_reason     VARCHAR(120),
            provider_ref       VARCHAR(120),
            sent_at            $ts,
            delivered_at       $ts,
            failure_reason     VARCHAR(120),
            carrier            VARCHAR(64),
            created_at         $ts";

        $t['attachments'] = "
            id          $pk,
            entity_type VARCHAR(24) NOT NULL,
            entity_id   INTEGER NOT NULL,
            kind        VARCHAR(12) NOT NULL DEFAULT 'PHOTO',
            label       VARCHAR(64),
            filename    VARCHAR(200),
            stored_path VARCHAR(255),
            mime        VARCHAR(64),
            bytes       INTEGER,
            created_at  $ts";

        /**
         * A diagnostic report: what the technician found and what they
         * recommend, written for the customer to keep.
         *
         * It hangs off the work order because that is where the technician,
         * the customer and the vehicle already meet. It carries NO line items
         * and NO price of its own — the estimate is the quote. Repair OPTIONS
         * are estimates pointing back here (estimates.diagnostic_report_id),
         * each with its own lines and total; the customer copy prints them
         * side by side, price only.
         *
         * DRAFT is editable. ISSUED is what the customer was handed, and it
         * never changes afterwards; a correction is a new report on the same
         * work order, and the earlier one stays on record with its audit trail.
         * internal_notes never reach the customer copy.
         */
        $t['diagnostic_reports'] = "
            id               $pk,
            doc_number       VARCHAR(32) NOT NULL,
            work_order_id    INTEGER NOT NULL,
            technician_id    INTEGER,
            status           VARCHAR(16) NOT NULL DEFAULT 'DRAFT',
            concern          $txt,
            findings         $txt,
            recommendations  $txt,
            drivability      VARCHAR(16),
            internal_notes   $txt,
            issued_at        $ts,
            issued_by        INTEGER,
            created_at       $ts,
            updated_at       $ts";

        $t['audit_log'] = "
            id          $pk,
            entity_type VARCHAR(24) NOT NULL,
            entity_id   INTEGER NOT NULL,
            action      VARCHAR(48) NOT NULL,
            actor_id    INTEGER,
            actor_name  VARCHAR(120),
            detail      $txt,
            created_at  $ts";

        /* Every call to a third-party service is logged, whatever the driver. */
        $t['api_log'] = "
            id         $pk,
            service    VARCHAR(32) NOT NULL,
            driver     VARCHAR(32) NOT NULL,
            operation  VARCHAR(48) NOT NULL,
            reference  VARCHAR(120),
            ok         INTEGER NOT NULL DEFAULT 1,
            detail     $txt,
            created_at $ts";

        $t['doc_counters'] = "
            prefix   VARCHAR(8) NOT NULL,
            date_key VARCHAR(8) NOT NULL,
            seq      INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (prefix, date_key)";

        /* -----------------------------------------------------------------
         * CORE DEPOSITS — the physical chain of custody
         *
         * A core deposit is a security deposit on a remanufactured part. The
         * money is never revenue and never expense; it is held in 2050 Core
         * Deposits Payable until the old unit stops moving. One row here is
         * one physical core, from the moment it is charged to the moment it
         * is settled or forfeited.
         *
         * WHY THE PHYSICAL CHAIN IS TRACKED AND NOT JUST THE MONEY. A shop
         * has the old alternator in a bin fifteen feet from the parts counter.
         * WKR is mobile: the core rides in a van for days between the job and
         * the jobber, and that gap is where cores are lost. Recording WHO
         * picked the part up is what makes it findable, and the difference
         * between a $22 write-off and a $22 refund.
         *
         *   status  CHARGED    billed on an invoice, old unit not yet in hand
         *           COLLECTED  a technician has the old unit
         *           RETURNED   handed to the supplier
         *           CREDITED   the supplier has refunded us
         *           SETTLED    finished — customer refunded and supplier square
         *           FORFEITED  the customer never brought it back; now earned
         *
         * Nothing is ever deleted; a wrong turn is corrected by moving the
         * status on with a note, and the ledger follows with its own entry.
         * --------------------------------------------------------------- */
        $t['core_records'] = "
            id                  $pk,
            invoice_id          INTEGER,
            doc_line_id         INTEGER,
            catalog_item_id     INTEGER,
            customer_id         INTEGER,
            sku                 VARCHAR(48),
            part_name           VARCHAR(160),
            core_value          $money NOT NULL DEFAULT 0,
            qty                 DECIMAL(10,2) NOT NULL DEFAULT 1,
            status              VARCHAR(16) NOT NULL DEFAULT 'CHARGED',
            charged_at          $ts,
            collected_by_id     INTEGER,
            collected_at        $ts,
            returned_at         $ts,
            supplier_name       VARCHAR(160),
            credited_at         $ts,
            customer_refunded_at $ts,
            forfeited_at        $ts,
            settled_at          $ts,
            due_back_by         VARCHAR(10),
            posted_entry_id     INTEGER,
            notes               $txt,
            created_at          $ts,
            updated_at          $ts";

        /* -----------------------------------------------------------------
         * SQUARE MIRROR
         *
         * A faithful copy of what Square says happened, and NOTHING MORE. This
         * table is not the ledger and must never be treated as one.
         *
         * The account it mirrors carries White Knight work mixed with the
         * owner's personal spending. Posting an imported row to the books
         * before a human has said which it is would put personal money into
         * business income — a tax misstatement, not an untidy report. So every
         * row lands as UNREVIEWED and stays inert until classified.
         *
         *   classification  UNREVIEWED | BUSINESS | PERSONAL | TRANSFER
         *   posted_entry_id NULL until it reaches the ledger, which for now is
         *                   never — import and posting were deliberately
         *                   separated. See docs/ACCOUNTING_PLAN.md.
         *
         * square_id is unique so a re-run of the sync updates rather than
         * duplicates: the whole history can be pulled again at any time and
         * converge on the same rows. raw holds the untouched payload, because
         * the one thing worse than not importing a field is discovering later
         * that it was thrown away.
         *
         * 191 characters, not 64. A refund id is far longer than a payment id
         * — MySQL truncated them silently on the way in, so every later sync
         * looked up the full id, found nothing, inserted, and died on the
         * unique index against the truncated copy. 191 is the largest VARCHAR
         * that indexes cleanly as utf8mb4 under every InnoDB row format.
         * --------------------------------------------------------------- */
        $t['square_transactions'] = "
            id               $pk,
            square_id        VARCHAR(191) NOT NULL,
            object_type      VARCHAR(16) NOT NULL DEFAULT 'PAYMENT',
            location_id      VARCHAR(64),
            order_id         VARCHAR(64),
            status           VARCHAR(24),
            amount           $money NOT NULL DEFAULT 0,
            tip_amount       $money NOT NULL DEFAULT 0,
            fee_amount       $money NOT NULL DEFAULT 0,
            net_amount       $money NOT NULL DEFAULT 0,
            refunded_amount  $money NOT NULL DEFAULT 0,
            currency         VARCHAR(8) NOT NULL DEFAULT 'USD',
            source_type      VARCHAR(24),
            card_brand       VARCHAR(24),
            card_last4       VARCHAR(8),
            entry_method     VARCHAR(24),
            customer_name    VARCHAR(160),
            note             VARCHAR(255),
            reference_id     VARCHAR(120),
            receipt_url      VARCHAR(512),
            occurred_at      $ts,
            square_customer_id VARCHAR(64),
            cardholder_name  VARCHAR(160),
            buyer_email      VARCHAR(160),
            /* IDENTIFYING DETAIL.
             *
             * Six years of charges with no documents behind them are told
             * apart by circumstance, not by amount: which device rang it up,
             * which Square app, what the customer saw on their statement, what
             * the receipt number was. All of it was already arriving in the
             * payload and being dropped on the floor — the first mapping kept
             * eight fields out of sixty.
             *
             * card_fingerprint is the quiet one worth having: Square hashes
             * the physical card, so it groups every charge from the same card
             * across six years even when the name was never captured. */
            receipt_number   VARCHAR(32),
            device_name      VARCHAR(120),
            square_product   VARCHAR(32),
            statement_desc   VARCHAR(80),
            card_fingerprint VARCHAR(96),
            card_exp         VARCHAR(8),
            card_type        VARCHAR(16),
            card_bin         VARCHAR(8),
            avs_status       VARCHAR(16),
            cvv_status       VARCHAR(16),
            decline_code     VARCHAR(48),
            decline_detail   VARCHAR(255),
            team_member_id   VARCHAR(64),
            classification   VARCHAR(16) NOT NULL DEFAULT 'UNREVIEWED',
            classified_by    INTEGER,
            classified_at    $ts,
            invoice_id       INTEGER,
            payment_id       INTEGER,
            posted_entry_id  INTEGER,
            raw              $txt,
            first_seen_at    $ts,
            last_synced_at   $ts";

        /* Square's customer directory, mirrored.
         *
         * This is where the names and phone numbers actually are. Payments
         * carry only a square_customer_id — a field census over six years of
         * imported payloads found a customer id on 40% of them, a cardholder
         * name on 6%, and a shipping address on 0.2%. Reading identity off the
         * payment alone would have thrown away almost all of it.
         *
         * Mirrored rather than written straight into `customers` for the same
         * reason payments are: a directory built up over six years of live
         * work contains duplicates, test entries and blanks, and merging that
         * into the operational customer base is a decision with consequences.
         * `promoted_customer_id` records the link once an operator makes it. */
        $t['square_customers'] = "
            id                   $pk,
            square_customer_id   VARCHAR(64) NOT NULL,
            given_name           VARCHAR(80),
            family_name          VARCHAR(80),
            company_name         VARCHAR(160),
            phone_number         VARCHAR(32),
            phone_e164           VARCHAR(16),
            email_address        VARCHAR(160),
            address_line1        VARCHAR(160),
            city                 VARCHAR(80),
            state                VARCHAR(8),
            postal_code          VARCHAR(12),
            note                 VARCHAR(255),
            reference_id         VARCHAR(120),
            created_at_square    $ts,
            payment_count        INTEGER NOT NULL DEFAULT 0,
            payment_total        $money NOT NULL DEFAULT 0,
            first_seen_job       $ts,
            last_seen_job        $ts,
            promoted_customer_id INTEGER,
            promoted_at          $ts,
            raw                  $txt,
            first_seen_at        $ts,
            last_synced_at       $ts";

        /* SQUARE CAPITAL ADVANCES.
         *
         * Not interest-bearing loans. Square advances a sum and charges ONE
         * FIXED FEE, repaid as a percentage of daily card sales until the
         * total is met. That distinction decides the accounting: there is no
         * amortisation schedule and no per-period interest to accrue — every
         * repayment splits on a single ratio fixed at origination.
         *
         *   fee_ratio = loan_fee / total_owed
         *
         * So a $6,409 obligation on a $5,520 advance means 13.87% of every
         * repayment is financing cost (7030) and the rest reduces the
         * liability (2100).
         *
         * These figures are not in any Square API — they live only in the
         * dashboard, one page per advance, and are recorded here by hand.
         * That is why the table exists rather than being derived. */
        $t['square_loans'] = "
            id             $pk,
            plan_id        VARCHAR(48) NOT NULL,
            loan_amount    $money NOT NULL DEFAULT 0,
            loan_fee       $money NOT NULL DEFAULT 0,
            total_owed     $money NOT NULL DEFAULT 0,
            total_paid     $money NOT NULL DEFAULT 0,
            balance        $money NOT NULL DEFAULT 0,
            repay_pct      DECIMAL(6,2),
            status         VARCHAR(16) NOT NULL DEFAULT 'PAID',
            paid_off_on    VARCHAR(10),
            posted_entry_id INTEGER,
            notes          $txt,
            created_at     $ts,
            updated_at     $ts";

        /* WHAT A PAYOUT WAS ACTUALLY MADE OF.
         *
         * A payout row says "$65.63 landed on Tuesday". That is the leftover,
         * not the story. The entries underneath itemise every movement in the
         * Square balance that produced it, and in this account three types
         * appear on almost every payout:
         *
         *   CHARGE                 the sale, less its processing fee
         *   SQUARE_CAPITAL_PAYMENT a business loan repaid out of takings
         *   CREDIT_CARD_REPAYMENT  a Square credit card repaid the same way
         *
         * Across a sample of 120 payouts those two deductions took 25% of
         * gross before anything reached the bank. Importing payments alone
         * made that money invisible — and left a Square Capital LOAN absent
         * from the books entirely, which is a balance-sheet error, not a
         * reporting one.
         *
         * Unlike payments, these need no human classification: the TYPE
         * determines the accounting treatment. What still needs a human is
         * splitting a loan repayment between principal and interest, which
         * Square does not break out. */
        $t['square_payout_entries'] = "
            id                $pk,
            square_entry_id   VARCHAR(191) NOT NULL,
            payout_square_id  VARCHAR(191) NOT NULL,
            payout_row_id     INTEGER,
            entry_type        VARCHAR(48) NOT NULL,
            effective_at      $ts,
            gross_amount      $money NOT NULL DEFAULT 0,
            fee_amount        $money NOT NULL DEFAULT 0,
            net_amount        $money NOT NULL DEFAULT 0,
            currency          VARCHAR(8) NOT NULL DEFAULT 'USD',
            related_square_id VARCHAR(191),
            posted_entry_id   INTEGER,
            raw               $txt,
            first_seen_at     $ts,
            last_synced_at    $ts";

        /* -----------------------------------------------------------------
         * BANK AND CARD IMPORT
         *
         * The other half of the money. Square knows what came in; the bank and
         * the card know what went out, and until they are here the books show
         * revenue with almost no cost against it — which overstates profit by
         * roughly everything the business actually spends.
         *
         * SAME PHILOSOPHY AS THE SQUARE MIRROR, for the same reason. These
         * tables are a faithful copy of what the institution said happened and
         * nothing more. A row lands inert, carries a PROPOSED account rather
         * than a decided one, and reaches the ledger only when a human accepts
         * it. The difference from Square is that here the mixing is real: this
         * card genuinely does carry personal spending, so the classification
         * gate matters more on this side than it ever did on that one.
         *
         * WHY NOT MERCHANT CATEGORY CODES. They would be the obvious key and
         * they are not available: the MCC is assigned by the acquiring bank and
         * rides the card network, not the statement export. American Express
         * publishes it nowhere; most issuers omit it from CSV downloads
         * entirely. OFX has an SIC element for it and banks populate it
         * inconsistently. So the only field reliably present on every line is
         * the descriptor string, and matching on that is the whole design.
         * See knowledge/EXTERNAL-SOURCES.md section 4.
         * --------------------------------------------------------------- */

        /* One real-world account, and where its money lands in the chart. A
         * checking account funds from 1010 and a credit card from 2010 — the
         * distinction is not cosmetic: card spending creates a LIABILITY and
         * cash spending reduces an ASSET, and posting one as the other makes
         * the balance sheet wrong in both directions at once. */
        $t['bank_sources'] = "
            id              $pk,
            name            VARCHAR(120) NOT NULL,
            institution     VARCHAR(120),
            kind            VARCHAR(16) NOT NULL DEFAULT 'CHECKING',
            account_code    VARCHAR(8) NOT NULL DEFAULT '1010',
            last4           VARCHAR(8),
            is_active       INTEGER NOT NULL DEFAULT 1,
            last_import_at  $ts,
            notes           $txt,
            created_at      $ts";

        /* WHY raw_descriptor AND match_key BOTH EXIST.
         *
         * raw_descriptor is what the bank said, byte for byte, and it is
         * evidence — it is what an auditor would compare against a statement.
         * match_key is the cleaned form the rules run against: upper-cased,
         * processor prefixes and store numbers stripped.
         *
         * The cleaning rules WILL be wrong sometimes. A merchant whose name
         * ends in a number, a city that looks like a reference — the normaliser
         * will eat something it should not. When that happens the original has
         * to still be there, or the mistake is unrecoverable and invisible. So
         * the cleaned form never overwrites the raw one.
         *
         * external_id is the institution's own identifier — FITID in OFX. It is
         * what makes a re-import converge instead of duplicating, exactly as
         * square_id does for the Square mirror. CSV exports often have none, in
         * which case the importer synthesises one from the stable fields and
         * says so. */
        $t['bank_transactions'] = "
            id               $pk,
            source_id        INTEGER NOT NULL,
            external_id      VARCHAR(191) NOT NULL,
            posted_on        VARCHAR(10) NOT NULL,
            amount           $money NOT NULL DEFAULT 0,
            direction        VARCHAR(8) NOT NULL DEFAULT 'DEBIT',
            raw_descriptor   VARCHAR(255) NOT NULL,
            match_key        VARCHAR(255),
            memo             VARCHAR(255),
            check_number     VARCHAR(24),
            /* PROPOSED, not decided. The rule that suggested it and the account
             * it suggested, kept apart from the account a human confirmed, so
             * 'the engine guessed right' and 'a person agreed' are different
             * facts and the hit rate is measurable. */
            suggested_account VARCHAR(8),
            suggested_rule_id INTEGER,
            account_code     VARCHAR(8),
            classification   VARCHAR(16) NOT NULL DEFAULT 'UNREVIEWED',
            reviewed_by_id   INTEGER,
            reviewed_at      $ts,
            expense_id       INTEGER,
            posted_entry_id  INTEGER,
            raw              $txt,
            first_seen_at    $ts,
            last_seen_at     $ts";

        /* The rule set. Ordered, first match wins — the same shape as Rules and
         * Markup, and for the same reason: the logic lives once, in PHP, where
         * it can be tested.
         *
         * hits and last_matched_at are not decoration. A rule that has never
         * matched is either wrong or obsolete, and without a count there is no
         * way to tell a careful rule from a dead one. A rule set nobody can
         * prune becomes a rule set nobody trusts.
         *
         * is_regex defaults to 0 because most rules are a plain substring —
         * 'NAPA' — and a substring cannot be malformed. A regex can, so it is
         * validated before it is saved rather than blowing up mid-import. */
        $t['expense_rules'] = "
            id              $pk,
            pattern         VARCHAR(190) NOT NULL,
            is_regex        INTEGER NOT NULL DEFAULT 0,
            account_code    VARCHAR(8) NOT NULL,
            classification  VARCHAR(16) NOT NULL DEFAULT 'BUSINESS',
            vendor_name     VARCHAR(160),
            priority        INTEGER NOT NULL DEFAULT 100,
            is_active       INTEGER NOT NULL DEFAULT 1,
            hits            INTEGER NOT NULL DEFAULT 0,
            created_by_id   INTEGER,
            source          VARCHAR(16) NOT NULL DEFAULT 'SEED',
            notes           $txt,
            last_matched_at $ts,
            created_at      $ts";

        /* Where the last successful sync got to, per object type, so the next
         * run asks Square only for what it has not seen. A full re-pull is
         * still possible by clearing the cursor — the unique square_id makes
         * that converge rather than duplicate. */
        $t['square_sync_state'] = "
            object_type   VARCHAR(16) NOT NULL,
            cursor_time   $ts,
            last_run_at   $ts,
            last_result   VARCHAR(255),
            imported      INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (object_type)";

        /* -----------------------------------------------------------------
         * THE GENERAL LEDGER
         *
         * Books are ACCRUAL: revenue posts when the invoice is issued, not
         * when the money arrives, so Accounts Receivable is a real balance.
         * Cash-basis is a REPORT derived from these tables by excluding
         * unpaid invoices — not a second set of books. See docs/ACCOUNTING_PLAN.md.
         *
         * A posted entry is never edited and never deleted, the same rule
         * audit_log and document voids already follow. A correction is a
         * reversing entry carrying reverses_entry_id back to its original;
         * both rows survive, and the pair nets to zero.
         *
         * period_key ("2026-08") is denormalised from entry_date so a period
         * lock can be enforced with an index rather than a date function, and
         * so a closed month cannot be reopened by editing a date.
         * --------------------------------------------------------------- */
        $t['journal_entries'] = "
            id                $pk,
            entry_no          VARCHAR(32) NOT NULL,
            entry_date        VARCHAR(10) NOT NULL,
            period_key        VARCHAR(7)  NOT NULL,
            source_type       VARCHAR(8)  NOT NULL,
            source_id         INTEGER,
            source_ref        VARCHAR(48),
            memo              VARCHAR(255),
            total_cents       INTEGER NOT NULL DEFAULT 0,
            is_reversal       INTEGER NOT NULL DEFAULT 0,
            reverses_entry_id INTEGER,
            reversed_by_id    INTEGER,
            posted_by_id      INTEGER,
            posted_by_name    VARCHAR(120),
            posted_at         $ts,
            created_at        $ts";

        /* One row per side. account_number is snapshotted as TEXT for the same
         * reason a document snapshots its prices: retiring or renaming an
         * account must not rewrite what a posted entry says it hit.
         *
         * debit and credit are both present and exactly one is non-zero. A
         * single signed amount column would be shorter and is how this gets
         * built wrong — the sign convention differs by account type, and the
         * balance check (sum of debits equals sum of credits) stops being
         * expressible. Amounts are also mirrored as integer cents so the
         * balance check never touches a DECIMAL or a float. */
        $t['journal_lines'] = "
            id             $pk,
            entry_id       INTEGER NOT NULL,
            line_no        INTEGER NOT NULL DEFAULT 1,
            account_number VARCHAR(8) NOT NULL,
            account_name   VARCHAR(120),
            account_type   VARCHAR(16),
            debit          $money NOT NULL DEFAULT 0,
            credit         $money NOT NULL DEFAULT 0,
            debit_cents    INTEGER NOT NULL DEFAULT 0,
            credit_cents   INTEGER NOT NULL DEFAULT 0,
            memo           VARCHAR(255)";

        /**
         * Indexes, declared once for both engines.
         *
         * They cannot be emitted the same way: SQLite takes
         * CREATE INDEX IF NOT EXISTS as a separate statement, while MySQL has no
         * such form and would fail on a second run — so there they are folded
         * into the CREATE TABLE body instead. Same declaration, two emissions.
         *
         * [name, table, columns, unique]
         */
        $indexes = [
            ['idx_acct_number',  'gl_accounts',      'account_number',          true],
            ['idx_sr_status',    'service_requests', 'status',                  false],
            ['idx_sr_customer',  'service_requests', 'customer_id',             false],
            ['idx_est_sr',       'estimates',        'service_request_id',      false],
            ['idx_est_status',   'estimates',        'status',                  false],
            ['idx_wo_est',       'work_orders',      'estimate_id',             false],
            ['idx_wo_tech',      'work_orders',      'technician_id',           false],
            ['idx_inv_est',      'invoices',         'estimate_id',             false],
            ['idx_inv_status',   'invoices',         'status',                  false],
            ['idx_lines_doc',    'doc_lines',        'doc_type, doc_id',        false],
            ['idx_pay_invoice',  'payments',         'invoice_id',              false],
            ['idx_link_order',   'payment_links',    'order_id',                false],
            ['idx_msg_ref',      'messages',         'provider_ref',            false],
            ['idx_veh_customer', 'vehicles',         'customer_id',             false],
            ['uq_vehcat_ymm',    'vehicle_catalog',  'year, make, model',       true],
            ['idx_audit_entity', 'audit_log',        'entity_type, entity_id',  false],
            ['idx_je_source',    'journal_entries',  'source_type, source_id',  false],
            ['idx_je_period',    'journal_entries',  'period_key',              false],
            ['idx_je_date',      'journal_entries',  'entry_date',              false],
            ['idx_jl_entry',     'journal_lines',    'entry_id',                false],
            ['idx_jl_account',   'journal_lines',    'account_number',          false],

            ['uq_vehicles_vin',  'vehicles',         'vin',        true],
            ['uq_catalog_sku',   'catalog_items',    'sku',        true],
            ['uq_users_email',   'users',            'email',      true],
            ['uq_sr_number',     'service_requests', 'doc_number', true],
            ['uq_est_number',    'estimates',        'doc_number', true],
            ['uq_wo_number',     'work_orders',      'doc_number', true],
            ['uq_inv_number',    'invoices',         'doc_number', true],
            // Payments, receipts and expenses are identified by number the
            // same way invoices are — but until 2026-08-27 these three had no
            // unique index, so a duplicate number (from the DocNumber race,
            // since fixed) would have been written SILENTLY where the other
            // document types would at least have failed loudly.
            ['uq_pay_number',    'payments',         'doc_number', true],
            ['uq_rct_number',    'receipts',         'doc_number', true],
            ['uq_exp_number',    'expenses',         'doc_number', true],
            // A processor reference may only ever appear once. This is what makes
            // a replayed webhook a no-op rather than a second payment — and it is
            // enforced by the engine, because two concurrent callbacks would race
            // straight past a check written in PHP.
            ['uq_pay_ref',       'payments',         'processor_ref', true],
            // The till form's equivalent of processor_ref: the form mints the
            // key at render, so a double-submit is engine-refused (2026-08-27).
            ['uq_pay_idem',      'payments',         'idempotency_key', true],

            ['idx_sigreq_doc',   'signature_requests', 'doc_type, doc_id', false],
            // The token IS the access control, so it must be unique by the
            // engine — not by a PHP check that two concurrent issues could race.
            ['uq_sigreq_token',  'signature_requests', 'token',            true],

            ['idx_locreq_doc',   'location_requests',  'doc_type, doc_id', false],
            ['uq_locreq_token',  'location_requests',  'token',            true],

            // An entry number identifies one posting for the life of the books.
            // Enforced by the engine for the same reason processor_ref is: two
            // concurrent posts would race past a PHP uniqueness check.
            ['uq_je_number',     'journal_entries',    'entry_no',         true],

            // What makes re-running the whole import safe: a second pull of
            // the same Square object updates its row instead of adding one.
            ['uq_sq_id',         'square_transactions', 'square_id',       true],
            ['idx_sq_class',     'square_transactions', 'classification',  false],
            ['idx_sq_order',     'square_transactions', 'order_id',        false],
            ['idx_sq_when',      'square_transactions', 'occurred_at',     false],
            ['idx_sq_cust',      'square_transactions', 'square_customer_id', false],

            /* Hot lookups that were table scans (added 2026-08-27):
             * phones are probed on every duplicate check, inbound SMS and
             * verbal-consent action — the compliance path; the rest are the
             * joins the dashboard, customer page and payments page make. */
            ['idx_cust_phone',   'customers',   'phone_e164',         false],
            ['idx_cust_phone2',  'customers',   'phone2_e164',        false],
            ['idx_api_when',     'api_log',     'created_at',         false],
            ['idx_api_kind',     'api_log',     'service, driver',    false],
            ['idx_msg_sr',       'messages',    'service_request_id', false],
            ['idx_inv_customer', 'invoices',    'customer_id',        false],
            ['idx_rct_payment',  'receipts',    'payment_id',         false],
            ['idx_wo_sr',        'work_orders', 'service_request_id', false],

            ['idx_diag_wo',      'diagnostic_reports', 'work_order_id', false],
            ['idx_est_diag',     'estimates',          'diagnostic_report_id', false],
            ['uq_diag_number',   'diagnostic_reports', 'doc_number',    true],

            ['idx_core_status',  'core_records',     'status',                  false],
            ['idx_core_invoice', 'core_records',     'invoice_id',              false],
            ['idx_core_due',     'core_records',     'due_back_by',             false],

            ['uq_sql_plan',      'square_loans',        'plan_id',           true],
            ['uq_sqe_id',        'square_payout_entries', 'square_entry_id',  true],
            ['idx_sqe_payout',   'square_payout_entries', 'payout_square_id', false],
            ['idx_sqe_type',     'square_payout_entries', 'entry_type',       false],

            ['uq_sqc_id',        'square_customers',    'square_customer_id', true],
            /* One institution can reuse an id another has used, so uniqueness is
             * the PAIR — an external id on its own is not an identity. */
            ['uq_bank_ext',      'bank_transactions',   'source_id, external_id', true],
            ['idx_bank_date',    'bank_transactions',   'posted_on',          false],
            ['idx_bank_class',   'bank_transactions',   'classification',     false],
            ['idx_bank_key',     'bank_transactions',   'match_key',          false],
            ['idx_rule_active',  'expense_rules',       'is_active, priority', false],
            ['idx_sqc_phone',    'square_customers',    'phone_e164',      false],
            ['idx_sqc_promoted', 'square_customers',    'promoted_customer_id', false],
        ];

        return ['tables' => $t, 'indexes' => $indexes, 'mysql' => $mysql, 'suffix' => $suffix];
    }
}
