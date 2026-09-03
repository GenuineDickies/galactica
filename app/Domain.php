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
 * Document numbering: PREFIX-YYYYMMDD-###
 * The date and sequence are assigned once and never change.
 */
final class DocNumber
{
    public static function next(string $prefix): string
    {
        $dateKey = date('Ymd');
        if (Db::driver() === 'mysql') {
            /* One atomic statement — two concurrent requests each get their
             * own seq, because the increment happens inside the engine, not
             * as a read-then-write in PHP. The LAST_INSERT_ID(expr) form
             * makes the value this statement produced readable afterwards
             * without a second (raceable) SELECT. Fixed 2026-08-27: the old
             * SELECT-then-UPDATE minted duplicate numbers under concurrency —
             * silently, for the document types with no unique number index. */
            Db::q('INSERT INTO doc_counters (prefix, date_key, seq) VALUES (?,?,LAST_INSERT_ID(1))
                   ON DUPLICATE KEY UPDATE seq = LAST_INSERT_ID(seq + 1)', [$prefix, $dateKey]);
            $seq = (int) Db::pdo()->lastInsertId();
        } else {
            /* SQLite serialises writers at the database level, so this
             * read-then-write pair cannot interleave with another writer. */
            $row = Db::one('SELECT seq FROM doc_counters WHERE prefix = ? AND date_key = ?', [$prefix, $dateKey]);
            if ($row === null) {
                Db::q('INSERT INTO doc_counters (prefix, date_key, seq) VALUES (?,?,1)', [$prefix, $dateKey]);
                $seq = 1;
            } else {
                $seq = (int) $row['seq'] + 1;
                Db::q('UPDATE doc_counters SET seq = ? WHERE prefix = ? AND date_key = ?', [$seq, $prefix, $dateKey]);
            }
        }
        return sprintf('%s-%s-%03d', $prefix, $dateKey, $seq);
    }
}

final class Audit
{
    public static function log(string $entityType, int $entityId, string $action, string $detail = ''): void
    {
        $u = Auth::user();
        Db::insert('audit_log', [
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'action'      => $action,
            'actor_id'    => $u['id'] ?? null,
            'actor_name'  => $u ? ($u['first_name'] . ' ' . $u['last_name']) : 'system',
            'detail'      => $detail,
            'created_at'  => now(),
        ]);
    }

    public static function for(string $entityType, int $entityId): array
    {
        return Db::all(
            'SELECT * FROM audit_log WHERE entity_type = ? AND entity_id = ? ORDER BY id DESC LIMIT 100',
            [$entityType, $entityId]
        );
    }
}

/**
 * Line items are ALWAYS snapshotted from the catalog. No free-typed items.
 */
final class Lines
{
    public static function forDoc(string $docType, int $docId): array
    {
        return Db::all('SELECT * FROM doc_lines WHERE doc_type = ? AND doc_id = ? ORDER BY line_no, id', [$docType, $docId]);
    }

    /**
     * Add a line, snapshotting cost, the applied markup %, the price the matrix
     * suggested, the final price, and whether that final price was an override.
     * The matrix is consulted HERE, once — the snapshot is what makes historical
     * documents immutable when the matrix or the catalog later change.
     *
     * @param ?float  $costOverride a per-job cost different from the catalog's
     * @param ?bool   $overridden   caller-known override flag; when null it is
     *                              inferred from whether the final price differs
     *                              from the matrix suggestion
     * @param ?string $nameOverride the typed description of a MISC charge. Only
     *                              an is_misc catalog item accepts one; for
     *                              every other item the catalog name is the
     *                              name, which is what keeps a real product
     *                              from being relabelled per document.
     */
    public static function add(
        string $docType, int $docId, int $catalogItemId, float $qty,
        ?float $priceOverride = null, string $notes = '',
        ?float $costOverride = null, ?bool $overridden = null,
        ?string $nameOverride = null
    ): int {
        $item = Db::one('SELECT * FROM catalog_items WHERE id = ?', [$catalogItemId]);
        if (!$item) { throw new RuntimeException('Catalog item not found. Line items must come from the catalog.'); }

        $isMisc = (int) ($item['is_misc'] ?? 0) === 1;
        if ($isMisc && trim((string) $nameOverride) === '') {
            throw new RuntimeException('A miscellaneous charge needs a description saying what it is for.');
        }
        /* The slot itself carries no price, so a blank one would fall through
         * to the catalog's 0.00 and put a free line on a customer invoice. The
         * price must be a decision. An explicit 0.00 is allowed — that is a
         * deliberate no-charge line — but leaving the box empty is not. */
        if ($isMisc && $priceOverride === null) {
            throw new RuntimeException('Set a price for this miscellaneous charge. The catalog has none to fall back on.');
        }

        $lineNo = (int) Db::val('SELECT COALESCE(MAX(line_no),0) FROM doc_lines WHERE doc_type = ? AND doc_id = ?', [$docType, $docId]) + 1;
        $qty    = max(0.0, $qty);

        /* A core charge is held per physical unit, and the ledger multiplies
         * it by a WHOLE-number qty — a fractional qty here would bill one
         * amount and refund or forfeit another (2026-08-27). There is no such
         * thing as returning half an alternator. */
        if (Markup::toCents($item['core_charge'] ?? 0) > 0 && $qty !== floor($qty)) {
            throw new RuntimeException('Quantity must be a whole number on a part that carries a core charge.');
        }

        // Resolve the cost for this line, then ask the one pricing service what
        // it would suggest. Cost is snapshotted regardless of markup outcome.
        $cost = $costOverride !== null ? $costOverride : (float) $item['unit_cost'];

        /* A misc charge is priced by judgement, not by matrix. Skipping the
         * suggestion is the whole of "markup exempt": there is no suggested
         * price to depart from, so markup_pct and suggested_price stay null and
         * the override flag stays 0 — the entered price is not an override of
         * anything, it is simply the price. A cost may still be recorded
         * against it (a sublet, a part off the truck), and profit reports read
         * that cost the same way they read any other line's. */
        $suggestion = $isMisc
            ? ['needs_pricing' => false, 'price' => null, 'price_cents' => null, 'markup_pct' => null, 'tier_id' => null]
            : Markup::suggest($cost);
        $suggested  = $suggestion['price'];          // string|null

        // Final price: an explicit override wins; otherwise the suggestion; and
        // if there is nothing to mark up, the catalog's own price.
        if ($priceOverride !== null) {
            $price = $priceOverride;
        } elseif ($suggested !== null) {
            $price = (float) $suggested;
        } else {
            $price = (float) $item['unit_price'];
        }

        /* Same doctrine as the misc slot: THE PRICE MUST BE A DECISION.
         * A "quote required" item has no cost (so the matrix says needs-
         * pricing) and a 0.00 catalog price — before 2026-08-27 the fallback
         * chain above silently put it on the document at $0.00, and a customer
         * can AUTHORIZE an estimate: a signed commitment to do the work for
         * free. An explicit 0.00 override remains allowed (a deliberate
         * no-charge line); falling through to zero with no decision is not. */
        if (!$isMisc && $priceOverride === null
            && !empty($suggestion['needs_pricing']) && $price == 0.0) {
            throw new RuntimeException(
                'This item needs pricing — it has no cost for the markup matrix and no catalog price. '
                . 'Set a price for it on this document.');
        }

        // Override flag: trust the caller if it told us, else compare the final
        // price to the suggestion (a cent's tolerance for float display).
        // A misc line is never an override — see the pricing note above.
        if ($isMisc) {
            $overridden = false;
        } elseif ($overridden === null) {
            $overridden = $suggested !== null && abs($price - (float) $suggested) > 0.001;
        }

        return Db::insert('doc_lines', [
            'doc_type'             => $docType,
            'doc_id'               => $docId,
            'line_no'              => $lineNo,
            'catalog_item_id'      => (int) $item['id'],
            'sku'                  => $item['sku'],
            'item_type'            => $item['item_type'],
            /* The typed description IS the misc line's name, so the customer
             * document reads "Off-road recovery — extra cable pull" rather than
             * a generic slot label. That specificity is what defends the line
             * in a dispute, which is the entire reason the field exists. */
            'name'                 => $isMisc ? trim((string) $nameOverride) : $item['name'],
            'description'          => $item['description'],
            'qty'                  => $qty,
            'uom'                  => $item['uom'],
            'unit_price'           => $price,
            'unit_cost'            => $cost,
            'markup_pct'           => $suggestion['markup_pct'],   // null when no markup applied
            'suggested_price'      => $suggested,                  // null when needs pricing
            'price_overridden'     => $overridden ? 1 : 0,
            'taxable'              => (int) $item['taxable'],
            'vehicle_not_required' => (int) $item['vehicle_not_required'],
            'discount_amount'      => 0,
            'line_total'           => round($price * $qty, 2),
            'warranty_months'      => (int) $item['warranty_months'],
            'mfr_warranty'         => $item['mfr_warranty'] ?? null,
            /* Snapshotted for the ledger, which posts from the LINE and never
             * from the catalog item behind it. Re-pointing an item at a
             * different revenue account, or changing a part's core value, must
             * not rewrite what an issued invoice already posted — the same
             * reason unit_price and markup_pct are snapshotted here. */
            'core_charge'          => (float) ($item['core_charge'] ?? 0),
            'revenue_account'      => ($item['revenue_account'] ?? '') ?: null,
            'cogs_account'         => ($item['cogs_account'] ?? '') ?: null,
            'notes'                => $notes,
        ]);
    }

    public static function copy(string $fromType, int $fromId, string $toType, int $toId): void
    {
        foreach (self::forDoc($fromType, $fromId) as $l) {
            unset($l['id']);
            $l['doc_type'] = $toType;
            $l['doc_id']   = $toId;
            Db::insert('doc_lines', $l);
        }
    }

    public static function remove(string $docType, int $docId, int $lineId): void
    {
        Db::q('DELETE FROM doc_lines WHERE id = ? AND doc_type = ? AND doc_id = ?', [$lineId, $docType, $docId]);
    }

    /** @return array{subtotal:float,discount:float,taxable:float,tax:float,total:float} */
    public static function totals(string $docType, int $docId, float $taxRate, float $docDiscount = 0.0): array
    {
        $subtotal = 0.0; $discount = $docDiscount; $taxable = 0.0; $taxBases = [];
        foreach (self::forDoc($docType, $docId) as $l) {
            $lt = (float) $l['line_total'] - (float) $l['discount_amount'];
            $subtotal += (float) $l['line_total'];
            $discount += (float) $l['discount_amount'];
            if ((int) $l['taxable'] === 1) {
                $taxable    += $lt;
                $taxBases[] = Markup::toCents($l['line_total']) - Markup::toCents($l['discount_amount']);
            }
        }
        $taxable = max(0.0, $taxable - $docDiscount);
        $tax     = self::taxCents($taxBases, $taxRate, Markup::toCents($docDiscount)) / 100;
        $total   = round($subtotal - $discount + $tax, 2);
        return [
            'subtotal' => round($subtotal, 2),
            'discount' => round($discount, 2),
            'taxable'  => round($taxable, 2),
            'tax'      => $tax,
            'total'    => $total,
        ];
    }

    /**
     * Per-line tax, in integer cents: each taxable line's tax is rounded to
     * the cent, then the rounded amounts are summed — never taxable-subtotal
     * × rate rounded once. Decided 2026-08-27: tenant accounts can be
     * anywhere in the US, and per-line is the treatment that survives mixed
     * jurisdictions, line-level receipt rules, and crediting a single line
     * off a taxed document. At a 0% rate the two methods are identical.
     *
     * A document-level discount is allocated across the taxable bases pro
     * rata before tax, so the discount reduces the taxed base the same way
     * it reduces the total. The allocation walks a cumulative (prefix)
     * split, which distributes exactly the discount with no line's base
     * driven negative and no float in the split.
     *
     * @param int[] $taxableBaseCents per-line taxable bases in cents
     *                                (line total minus line discount)
     */
    public static function taxCents(array $taxableBaseCents, float $taxRate, int $docDiscountCents = 0): int
    {
        $bases = [];
        foreach ($taxableBaseCents as $c) { if ((int) $c > 0) { $bases[] = (int) $c; } }
        if ($bases === [] || $taxRate <= 0) { return 0; }

        $sum  = array_sum($bases);
        $disc = min(max(0, $docDiscountCents), $sum);

        $tax = 0; $prefix = 0; $allocPrev = 0;
        foreach ($bases as $b) {
            $prefix   += $b;
            $alloc     = intdiv($disc * $prefix, $sum);
            $base      = $b - ($alloc - $allocPrev);
            $allocPrev = $alloc;
            $tax      += (int) round($base * $taxRate);
        }
        return $tax;
    }
}

/**
 * The operational category a job belongs to — the axis the business is run on,
 * as distinct from the revenue account, which is the axis it is booked on.
 *
 * The dividing line is CAPABILITY, not urgency: what does the job need on the
 * truck?
 *
 * For tire work the test is whether the TIRE comes off the WHEEL — the bead off
 * the rim — and NOT whether the wheel comes off the vehicle. Those are two
 * different operations with two different kits, and conflating them is the easy
 * mistake. Pulling the wheel is a jack and a lug wrench, which every roadside
 * truck already carries; a plug is routinely done with the wheel off the
 * vehicle and the tire still on it, and that is still roadside. Breaking the
 * bead needs a bead breaker and a tire machine, and that is Mobile Tire.
 *
 * So a spare swap and a plug ride with the roadside kit. An internal patch and
 * a tire delivery do not, even though the customer is stranded in exactly the
 * same way.
 *
 * The same question sorts the other three. Mobile Repair is where a part comes
 * off and a part goes on — parts installation, a battery replacement, a
 * diagnostic that precedes one. A battery swap is a light repair rather than a
 * roadside errand: it is a part sale with labour attached, it is sold
 * standalone by every other provider in the trade, and grouping it with jump
 * starts hid that. Towing is where the VEHICLE moves rather than the
 * technician's hands — a winch-out is recovery, which is the tow trade's work
 * and not a soft service, whatever the customer thinks they are asking for.
 *
 * Towing is offered because the platform is multi-tenant and other operators
 * run tow trucks. White Knight itself does not tow; an operator that does not
 * offer a category simply never rolls it.
 *
 * This is deliberately NOT the revenue account. A battery job posts labour to
 * 4000 and the battery to 4010 no matter which category it sits in; the
 * category is what tells you whether to buy more tire ads or a second bead
 * breaker. Keeping the two apart is why there is no revenue account per
 * service — see docs/BUSINESS_RULES.md.
 */
final class ServiceCategory
{
    public const ROADSIDE = 'ROADSIDE';
    public const TIRE     = 'TIRE';
    public const MECHANIC = 'MECHANIC';
    public const TOWING   = 'TOWING';
    public const OTHER    = 'OTHER';

    /** The whole set, in the order they are offered. */
    public const ALL = [
        self::ROADSIDE => 'Roadside Services',
        self::TIRE     => 'Advanced Tire Services',
        self::MECHANIC => 'Mobile Repair Services',
        self::TOWING   => 'Towing Services',
        self::OTHER    => 'Other',
    ];

    /**
     * Which category each service type belongs to. Every type now belongs to
     * exactly one, and the order here is the order the types are offered.
     *
     * READ THIS MAP IN BOTH DIRECTIONS. Forwards it still answers "what should
     * this roll as" for a type that arrives from somewhere else — an old row, a
     * provider feed, an estimate. Backwards — which is how intake now uses it —
     * it answers "what can this kit do", and that is the question that actually
     * gets asked on the phone. Dispatch picks the truck first and the job from
     * what the truck can do, rather than naming a job and being asked to
     * classify it afterwards, which is the same decision made in the order
     * nobody makes it in.
     */
    public const FROM_SERVICE_TYPE = [
        /* Roadside — hand tools, nothing comes apart. */
        'JUMPSTART'     => self::ROADSIDE,
        'LOCKOUT'       => self::ROADSIDE,
        'FUEL'          => self::ROADSIDE,
        'TIRE_SWAP'     => self::ROADSIDE,
        'TIRE_PLUG'     => self::ROADSIDE,

        /* Advanced Tire — the bead comes off the rim. */
        'TIRE_PATCH'    => self::TIRE,
        'TIRE_DELIVERY' => self::TIRE,

        /* Mobile Repair — a part is removed and a part goes on. */
        'PARTS_INSTALL' => self::MECHANIC,
        'BATTERY_SWAP'  => self::MECHANIC,
        'DIAGNOSTIC'    => self::MECHANIC,

        /* Towing — the vehicle moves, under a winch or on a deck. */
        'WINCH_OUT'     => self::TOWING,
        'FLATBED_TOW'   => self::TOWING,
        'STANDARD_TOW'  => self::TOWING,

        'OTHER'         => self::OTHER,

        /* Retired — never offered, still classified. See RETIRED. */
        'TIRE'          => self::ROADSIDE,
        'BATTERY'       => self::MECHANIC,
        'RECOVERY'      => self::TOWING,
        'MECHANIC'      => self::MECHANIC,
    ];

    /**
     * Types that exist only to keep old rows readable and classifiable. They
     * are accepted on the way in and never offered on the way out.
     *
     * 'TIRE' is the pre-split "Tire Service" — it defaulted to Roadside
     * because that was the commoner case, which is exactly the guess the split
     * exists to stop making. 'BATTERY' and 'RECOVERY' are the pre-split
     * "Battery Service" and "Winch / Recovery", now Mobile Repair and Towing
     * respectively. 'MECHANIC' is the old undifferentiated "Mobile Mechanic".
     *
     * Note what re-pointing these does and does not do. A row that already has
     * a service_category keeps it — the column is what dispatch decided and
     * nothing here rewrites it. These defaults only fire for a row that never
     * got one, which after the split means a very old row or an inbound feed.
     */
    public const RETIRED = ['TIRE', 'BATTERY', 'RECOVERY', 'MECHANIC'];

    /**
     * RETIRED. Nothing is ambiguous any more: the category is chosen first and
     * each type sits under exactly one. Kept as an empty set so callers that
     * still ask get a straight "no" rather than an error.
     */
    public const AMBIGUOUS = [];

    public static function isValid(?string $c): bool
    {
        return $c !== null && isset(self::ALL[$c]);
    }

    public static function label(?string $c): string
    {
        return self::ALL[$c] ?? '—';
    }

    /** The dispatch default for a reported service type. Never returns null. */
    public static function fromServiceType(?string $serviceType): string
    {
        return self::FROM_SERVICE_TYPE[(string) $serviceType] ?? self::OTHER;
    }

    /** True when the reported type leaves the category genuinely undecided. */
    public static function needsDispatchDecision(?string $serviceType): bool
    {
        return in_array((string) $serviceType, self::AMBIGUOUS, true);
    }

    /**
     * The service types a category can roll — the OFFER list. Retired types are
     * excluded here and only here: they may be read and re-saved, never picked
     * afresh. An unknown category offers nothing rather than everything, so a
     * junk value cannot quietly widen the menu.
     */
    public static function serviceTypes(?string $category): array
    {
        if (!self::isValid($category)) { return []; }
        $out = [];
        foreach (self::FROM_SERVICE_TYPE as $type => $cat) {
            if ($cat === $category && !in_array($type, self::RETIRED, true)) {
                $out[] = $type;
            }
        }
        return $out;
    }

    /**
     * Whether a category may carry a service type — the ACCEPT list, which is
     * wider than the offer list by exactly the retired types. Editing a
     * pre-split request must not force a new job name onto it.
     */
    public static function allows(?string $category, ?string $serviceType): bool
    {
        return self::isValid($category)
            && (self::FROM_SERVICE_TYPE[(string) $serviceType] ?? null) === $category;
    }

    /**
     * Coerce a submitted service type to one the category can actually roll.
     *
     * The category is authoritative: it is what dispatch chose and what gets
     * loaded on the truck. A type that does not belong to it did not come from
     * the form as rendered, so it is replaced rather than trusted — and the
     * replacement is the category's first offered type, never a constant that
     * might belong to a different category.
     */
    public static function coerceServiceType(?string $category, ?string $serviceType): string
    {
        if (self::allows($category, $serviceType)) { return (string) $serviceType; }
        return self::serviceTypes($category)[0] ?? 'OTHER';
    }

    /**
     * Coerce anything arriving from a form to a valid category, falling back to
     * the default for the service type rather than to a hardcoded value.
     */
    public static function coerce(?string $c, ?string $serviceType = null): string
    {
        return self::isValid($c) ? (string) $c : self::fromServiceType($serviceType);
    }

    /**
     * What the job turned out to be, versus what was dispatched. Returns null
     * when the work order has not been categorised yet — an uncategorised work
     * order is not a reclassification, it is simply unanswered.
     */
    public static function reclassification(array $sr, array $wo): ?array
    {
        $dispatched = $sr['service_category'] ?? null;
        $actual     = $wo['service_category'] ?? null;
        if (!self::isValid($dispatched) || !self::isValid($actual)) { return null; }
        if ($dispatched === $actual) { return null; }
        return ['from' => $dispatched, 'to' => $actual];
    }
}

/**
 * A physical mailing address, and the line between one and a description.
 *
 * THE MODEL. The coordinates are the truth. A pin dropped on the map, or the
 * position a customer's phone reported, is where the vehicle physically is,
 * and that is what a truck routes to. The **nearest physical address** is a
 * derived approximation of that point — reverse-geocoded from it, or typed by
 * a dispatcher from what the customer said — and it exists so a human has
 * something readable to put on an estimate and to price a call-out against.
 * It is never the routing target. Where the two disagree, the pin wins.
 *
 * WHAT AN ADDRESS IS HERE. A street number, a street, a city and a state. ZIP
 * is not required — the geocoders often cannot supply one for a rural road,
 * and refusing the address over a missing ZIP would reject a location we can
 * actually drive to.
 *
 * WHAT IT IS NOT. "I-84 EB near Exit 9, blue sedan on the shoulder" is a
 * description of a place. It is exactly what a stranded caller says, it is
 * genuinely useful, and it is captured verbatim as `reported_location` — but
 * it is not an address and must never be written into an address field. The
 * difference matters because addresses get printed on documents a customer
 * signs and get handed to routing; descriptions get read by a dispatcher.
 *
 * WHY A BLACKLIST AT ALL. "5 miles north of Sandy" begins with a number and a
 * word, so a purely structural test passes it. The locative phrases below are
 * the ones that turn a line into a description. They are matched as phrases,
 * not bare words, on purpose: Portland has an NW Front Ave and a Marine Dr,
 * and rejecting a real street because it contains "front" would be worse than
 * letting one odd description through. When this is wrong it fails toward
 * "confirm it with a pin", which is safe.
 */
final class Address
{
    /**
     * Phrases that mark a line as describing a place rather than naming one.
     * Matched case-insensitively against the line padded with spaces, so
     * ' near ' cannot fire inside "Nearcrest Ln".
     */
    private const LOCATIVE = [
        ' near ', ' nearest ', ' mile ', ' miles ', ' milepost ', ' mile marker ',
        ' mp ', ' marker ', ' exit ', ' shoulder ', ' median ', ' overpass ', ' underpass ',
        ' between ', ' across from ', ' in front of ', ' behind ', ' past ',
        ' off ramp ', ' on ramp ', ' offramp ', ' onramp ',
        ' northbound ', ' southbound ', ' eastbound ', ' westbound ',
        ' nb ', ' sb ', ' eb ', ' wb ',
        ' parking lot ', ' rest area ', ' weigh station ', ' turnout ', ' pullout ',
    ];

    /** A street number: digits, an optional letter, an optional halves fraction. */
    private const NUMBER = '/^\d+\s*[A-Za-z]?(?:\s+1\/2)?\s+\S/';

    /**
     * Is this line shaped like a street address rather than a description?
     * Structure only — it says nothing about whether the place exists.
     */
    public static function looksPhysical(?string $line): bool
    {
        $line = trim((string) $line);
        if ($line === '' || !preg_match(self::NUMBER, $line)) { return false; }

        $padded = ' ' . strtolower(preg_replace('/\s+/', ' ', $line)) . ' ';
        foreach (self::LOCATIVE as $phrase) {
            if (str_contains($padded, $phrase)) { return false; }
        }
        return true;
    }

    /**
     * Split a geocoder's one-line result into its pieces. Drivers compose
     * "street, city, ST ZIP", which comes apart cleanly; anything that does
     * not is returned with the whole string as the street line so nothing is
     * silently discarded.
     *
     * @return array{line:string,city:string,state:string,postal:string}
     */
    public static function split(?string $formatted): array
    {
        $out = ['line' => '', 'city' => '', 'state' => '', 'postal' => ''];
        $s   = trim((string) $formatted);
        if ($s === '') { return $out; }

        $parts = array_values(array_filter(array_map('trim', explode(',', $s)), static fn($p) => $p !== ''));
        if (count($parts) < 3) { $out['line'] = $s; return $out; }

        $tail = array_pop($parts);              // "OR 97209" | "Oregon" | "OR"
        if (preg_match('/^([A-Za-z. ]+?)\s*(\d{5})?(?:-\d{4})?$/', $tail, $m)) {
            $out['state']  = us_state_abbrev(trim($m[1]));
            $out['postal'] = $m[2] ?? '';
        }
        $out['city'] = (string) array_pop($parts);
        $out['line'] = implode(', ', $parts);
        return $out;
    }

    /**
     * The gate. Given an address line and whatever city/state are held
     * separately, is there a usable nearest physical address?
     *
     * City and state may arrive either inside the line or in their own
     * columns; both shapes are accepted, because the geocoder produces the
     * first and the intake form produces the second.
     *
     * @return array{ok:bool,reason:string,line:string,city:string,state:string,postal:string}
     */
    public static function check(?string $line, ?string $city = null, ?string $state = null, ?string $postal = null): array
    {
        $raw   = trim((string) $line);
        $inline = self::split($raw);

        /* Prefer what was passed explicitly; fall back to what the line carried. */
        $useLine  = $inline['line'] !== '' ? $inline['line'] : $raw;
        $useCity  = trim((string) $city)  !== '' ? trim((string) $city)  : $inline['city'];
        $useState = us_state_abbrev(trim((string) $state) !== '' ? (string) $state : $inline['state']);
        $usePost  = trim((string) $postal) !== '' ? trim((string) $postal) : $inline['postal'];

        $res = ['ok' => false, 'reason' => '', 'line' => $useLine,
                'city' => $useCity, 'state' => $useState, 'postal' => $usePost];

        if ($raw === '') {
            $res['reason'] = 'No address yet. Drop a pin on the map or type the nearest street address.';
            return $res;
        }
        if (!preg_match(self::NUMBER, $useLine)) {
            $res['reason'] = 'A nearest address needs a street number and street name — "'
                . $useLine . '" has no street number.';
            return $res;
        }
        if (!self::looksPhysical($useLine)) {
            $res['reason'] = 'That reads as a description of a place, not an address. '
                . 'Keep it in the reported location, and drop a pin to get the nearest street address.';
            return $res;
        }
        if ($useCity === '') {
            $res['reason'] = 'A nearest address needs a city.';
            return $res;
        }
        if ($useState === '') {
            $res['reason'] = 'A nearest address needs a state.';
            return $res;
        }

        $res['ok'] = true;
        return $res;
    }

    /** The address on one line, the way it prints. ZIP included when known. */
    public static function oneLine(?string $line, ?string $city, ?string $state, ?string $postal = null): string
    {
        $bits = [];
        if (trim((string) $line) !== '')  { $bits[] = trim((string) $line); }
        $tail = trim(trim((string) $city) . ' ' . trim((string) $state) . ' ' . trim((string) $postal));
        $tail = trim(preg_replace('/\s+/', ' ', $tail) ?? '');
        if (trim((string) $city) !== '' && trim((string) $state) !== '') {
            $tail = trim((string) $city) . ', ' . trim((string) $state)
                  . (trim((string) $postal) !== '' ? ' ' . trim((string) $postal) : '');
        }
        if ($tail !== '') { $bits[] = $tail; }
        return implode(', ', $bits);
    }
}

/**
 * The hard business rules. Every gate in the app routes through here so the
 * rules live in exactly one place.
 */
final class Rules
{
    /** Valid forward transitions for the field status workflow. */
    public static function workOrderTransitions(string $status): array
    {
        return match ($status) {
            'PENDING'     => ['ASSIGNED', 'CANCELLED', 'NO_SHOW'],
            'ASSIGNED'    => ['EN_ROUTE', 'CANCELLED', 'NO_SHOW'],
            'EN_ROUTE'    => ['ON_SITE', 'CANCELLED', 'NO_SHOW'],
            'ON_SITE'     => ['IN_PROGRESS', 'CANCELLED', 'NO_SHOW'],
            'IN_PROGRESS' => ['CANCELLED', 'NO_SHOW'],
            default       => [],
        };
    }

    public static function cfg(string $key): mixed
    {
        return App::config('rules')[$key] ?? null;
    }

    /** Estimates above the threshold need a captured signature, not just a verbal note. */
    public static function signatureRequired(float $estimateTotal): bool
    {
        return $estimateTotal > (float) self::cfg('authorization_threshold');
    }

    /* -----------------------------------------------------------------
     * The temporary setup admin.
     *
     * An install with no real admin configured still has to be usable on
     * first boot, so it seeds ONE throwaway login at a throwaway address —
     * SETUP_EMAIL, with a published password. That account is temporary: the
     * moment a real admin exists it deactivates itself and stays that way.
     * Nothing is deleted — the row remains for the audit trail.
     *
     * A REAL admin is one the operator configured: install.admin with a
     * password_hash generated by `php data/setup.php`. It is never flagged,
     * never retired, and its address is never assumed by this code. That
     * distinction is the whole point of the flag — an earlier version keyed
     * off install.admin.email instead, which meant the owner's own login was
     * marked temporary and was deactivated the first time a second admin was
     * added.
     * ----------------------------------------------------------------- */

    /**
     * The address the installer seeds when no real admin is configured.
     * Deliberately not a live domain: nobody's actual mailbox can collide
     * with it, so nothing real can ever inherit the temporary flag.
     */
    public const SETUP_EMAIL = 'admin@setup.com';

    /**
     * Reconcile the is_setup flag with what the config actually says.
     * Adds the column on databases created before it existed, flags the
     * throwaway login, and clears the flag from the configured real admin —
     * which is what repairs a database seeded by the earlier logic.
     * Idempotent; called from the admin Users screen.
     */
    public static function setupAdminHeal(): void
    {
        try {
            Db::val('SELECT is_setup FROM users LIMIT 1');
        } catch (Throwable) {
            Db::pdo()->exec('ALTER TABLE users ADD COLUMN is_setup INTEGER NOT NULL DEFAULT 0');
        }

        // The temporary login is identified by its address and nothing else.
        Db::q('UPDATE users SET is_setup = 1 WHERE LOWER(email) = ?', [self::SETUP_EMAIL]);

        // The configured admin is a real one. Clearing this is the repair for
        // any database that was seeded with the owner's own login flagged.
        $email = strtolower(trim((string) (App::config('install', [])['admin']['email'] ?? '')));
        if ($email !== '' && $email !== self::SETUP_EMAIL) {
            Db::q('UPDATE users SET is_setup = 0 WHERE LOWER(email) = ?', [$email]);
        }
    }

    /**
     * Deactivate the setup login once a real admin exists. Returns the rows
     * it deactivated so the caller can tell the user. Idempotent: does
     * nothing when no real admin exists yet (the setup login must keep
     * working until then) and nothing on later calls.
     *
     * It refuses to touch the account making the request. This path writes
     * straight to the row and so bypasses the guard on the Users screen; the
     * cost of getting that wrong is being locked out of a live site, so the
     * guard is repeated here rather than assumed.
     */
    public static function retireSetupAdmins(): array
    {
        $real = (int) Db::val(
            "SELECT COUNT(*) FROM users WHERE role = 'ADMIN' AND is_active = 1 AND is_setup = 0"
        );
        if ($real === 0) { return []; }

        $me   = (int) (Auth::user()['id'] ?? 0);
        $rows = Db::all('SELECT * FROM users WHERE is_setup = 1 AND is_active = 1');
        $done = [];
        foreach ($rows as $u) {
            if ((int) $u['id'] === $me) { continue; }
            Db::update('users', (int) $u['id'], ['is_active' => 0]);
            Audit::log('user', (int) $u['id'], 'deactivated',
                'temporary setup admin retired — a real admin account exists');
            $done[] = $u;
        }
        return $done;
    }

    /**
     * Has the customer signed authorization for this work?
     *
     * The ESTIMATE only ever needs verbal approval — that is what releases the
     * technician. The signature lives on the WORK ORDER, and it is what
     * releases the work itself. It may be captured on the technician's device
     * with the customer standing there, or through a link texted to them when
     * they are not.
     */
    public static function workAuthSigned(array $wo): bool
    {
        return trim((string) ($wo['auth_signature'] ?? '')) !== '';
    }

    /** Does this job need a signature at all? Driven by the estimate's total. */
    public static function workAuthRequired(array $est): bool
    {
        return self::signatureRequired((float) $est['total']);
    }

    /** True while a job that needs a signature has not got one yet. */
    public static function signaturePending(array $est, array $wo): bool
    {
        return self::workAuthRequired($est) && !self::workAuthSigned($wo);
    }

    /**
     * An estimate may be dispatched once it is priced and the customer has
     * authorized it. Verbal authorization is enough to send a technician —
     * a signature is not required to roll, because it cannot be collected
     * before someone is standing in front of the customer.
     * @return array{ok:bool,reason:string}
     */
    public static function dispatchGate(array $est): array
    {
        if (!Lines::forDoc('EST', (int) $est['id'])) {
            return ['ok' => false, 'reason' => 'Add at least one catalog line item before dispatching.'];
        }
        if (empty($est['authorized_at'])) {
            return ['ok' => false, 'reason' => 'No technician is dispatched without customer authorization on the estimate. Verbal is enough to roll.'];
        }
        return ['ok' => true, 'reason' => ''];
    }

    /**
     * The hard one: no work is performed on the vehicle without the customer's
     * signature on the work order, whenever the estimate is over the threshold.
     * Dispatch only needs verbal; touching the vehicle needs ink. This holds
     * however the signature arrives — in person or by texted link.
     * @return array{ok:bool,reason:string}
     */
    public static function workBeginsGate(array $est, array $wo): array
    {
        if (!self::signaturePending($est, $wo)) { return ['ok' => true, 'reason' => '']; }
        return ['ok' => false, 'reason' => 'No work may begin on the vehicle until the customer signs this work order. Show it to them on your device, or text them the link.'];
    }

    /**
     * Did the signature actually precede the work?
     *
     * Both timestamps are recorded, so this is answerable rather than assumed.
     * Returns true when the order cannot be faulted — including when there is
     * nothing to check (no signature required, or a work order from before
     * these timestamps existed), because absence of evidence is not a breach.
     */
    public static function signatureprecededWork(array $wo): bool
    {
        $started = (string) ($wo['work_started_at'] ?? '');
        $signed  = (string) ($wo['auth_signed_at'] ?? '');
        if ($started === '' || $signed === '') { return true; }
        return strtotime($signed) <= strtotime($started);
    }

    /**
     * Invoice vs approved estimate. If the delta exceeds
     * min($200, 10% of the estimate), the customer must re-authorize.
     */
    public static function varianceThreshold(float $estimateTotal): float
    {
        return min((float) self::cfg('variance_abs'), $estimateTotal * (float) self::cfg('variance_pct'));
    }

    public static function varianceNeedsAuth(float $estimateTotal, float $invoiceTotal): bool
    {
        if ($estimateTotal <= 0) { return false; }
        /* Compared in integer cents — this is the statutory ORS 646A.486
         * boundary, and float subtraction can flip the comparison at exactly
         * the threshold (a drift of exactly $19.50 evaluating as
         * 19.500000000000004 > 19.5). Cents make the boundary exact. */
        return abs(Markup::toCents($invoiceTotal) - Markup::toCents($estimateTotal))
            > Markup::toCents(self::varianceThreshold($estimateTotal));
    }

    /**
     * Invoice may only be issued with a linked vehicle (VIN required),
     * UNLESS every line on the invoice is flagged vehicle_not_required.
     * @return array{ok:bool, reason:string}
     */
    public static function invoiceVehicleGate(array $invoice): array
    {
        $lines = Lines::forDoc('INV', (int) $invoice['id']);
        if (!$lines) {
            return ['ok' => false, 'reason' => 'Add at least one catalog line item before issuing.'];
        }
        $allNoVehicle = true;
        foreach ($lines as $l) {
            if ((int) $l['vehicle_not_required'] !== 1) { $allNoVehicle = false; break; }
        }
        if ($invoice['vehicle_id']) {
            $v = Db::one('SELECT vin FROM vehicles WHERE id = ?', [(int) $invoice['vehicle_id']]);
            if ($v && vin_is_valid($v['vin'])) { return ['ok' => true, 'reason' => '']; }
            return ['ok' => false, 'reason' => 'The linked vehicle has an invalid VIN.'];
        }
        if ($allNoVehicle && !empty($invoice['no_vehicle_reason'])) {
            return ['ok' => true, 'reason' => ''];
        }
        if ($allNoVehicle) {
            return ['ok' => false, 'reason' => 'No vehicle attached. Record a "no vehicle serviced" reason to proceed.'];
        }
        return ['ok' => false, 'reason' => 'Attach a vehicle record to issue this invoice. The driver must capture the VIN.'];
    }

    /**
     * Core deposits: what is BILLED must equal what is OWED BACK.
     *
     * Two mechanisms exist and are both correct on their own — the deposit is
     * billed by a fee line whose revenue account is 2050, and custody records
     * are raised from each part line's snapshotted core_charge × qty. Nothing
     * used to check them against each other (fixed 2026-08-27), which allowed
     * two silent failures: deposits billed into 2050 with no custody record
     * (the forfeiture sweep never sees them), and custody records for
     * deposits never billed — settling one of those pays a real cash refund
     * for money that was never collected.
     *
     * Compared in integer cents. Zero on both sides passes — most invoices
     * carry no cores at all.
     */
    public static function coreDepositGate(array $invoice): array
    {
        $billedCents = 0;   // deposit fee lines pointing at 2050
        $owedCents   = 0;   // part lines carrying a core_charge snapshot
        foreach (Lines::forDoc('INV', (int) $invoice['id']) as $l) {
            if ((string) ($l['revenue_account'] ?? '') === Posting::CORE_PAYABLE_ACCT) {
                $billedCents += Markup::toCents($l['line_total']);
            }
            $coreCents = Markup::toCents($l['core_charge'] ?? 0);
            if ($coreCents > 0) {
                $owedCents += $coreCents * (int) max(1, (float) $l['qty']);
            }
        }
        if ($billedCents === $owedCents) { return ['ok' => true, 'reason' => '']; }
        if ($billedCents > $owedCents) {
            return ['ok' => false, 'reason' => 'Core deposit mismatch: '
                . money(Markup::centsToStr($billedCents)) . ' is billed as core deposits but the part lines '
                . 'only carry ' . money(Markup::centsToStr($owedCents)) . ' of core charges. The deposit being '
                . 'billed must match a core charge on a part line, or it can never be refunded or forfeited properly.'];
        }
        return ['ok' => false, 'reason' => 'Core deposit mismatch: the part lines carry '
            . money(Markup::centsToStr($owedCents)) . ' of core charges but only '
            . money(Markup::centsToStr($billedCents)) . ' is billed as a core deposit (a line on account '
            . Posting::CORE_PAYABLE_ACCT . '). Add the deposit line — otherwise the customer is owed a refund '
            . 'for money that was never collected.'];
    }

    /* ---- Business accounts & payment terms --------------------------- */

    /**
     * Terms label → days of credit. Absent from this map means COD / due on
     * receipt — the default for EVERY account, business included. Granting
     * net terms is a deliberate per-account setting, never implied by the
     * customer type.
     */
    public const TERMS_DAYS = ['NET_15' => 15, 'NET_30' => 30];

    public static function termsDays(?string $terms): ?int
    {
        return self::TERMS_DAYS[strtoupper(trim((string) $terms))] ?? null;
    }

    /**
     * Invoice due date, computed from the terms SNAPSHOTTED on the invoice at
     * creation — mirroring the Markup rule: editing the account later never
     * changes existing documents. COD → due the moment it is issued.
     */
    public static function invoiceDueAt(string $issuedAt, ?string $terms): string
    {
        $days = self::termsDays($terms);
        if ($days === null) { return $issuedAt; }
        try {
            return (new DateTimeImmutable($issuedAt))
                ->add(new DateInterval('P' . $days . 'D'))
                ->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return $issuedAt;
        }
    }

    /**
     * The customer is either a PERSON or a BUSINESS — a hard distinction.
     *
     * INDIVIDUAL: a human is the customer. Their name is required; they never
     * carry a company name (see accountCompany()).
     *
     * COMMERCIAL / FLEET: the business entity is the customer — the company
     * name is the legal name on every document, so it is required. The person
     * fields become an optional billing contact. FLEET means the customer's
     * business IS vehicles (couriers, trucking, delivery); a commercial
     * customer that merely owns several vehicles is COMMERCIAL.
     *
     * @return array{ok:bool,reason:string}
     */
    public static function customerGate(string $type, string $company, string $first = '', string $last = ''): array
    {
        if (self::isBusinessType($type)) {
            if (trim($company) === '') {
                return ['ok' => false, 'reason' => 'A company name is required — for a business account the company is the customer.'];
            }
            return ['ok' => true, 'reason' => ''];
        }
        if (trim($first) === '' && trim($last) === '') {
            return ['ok' => false, 'reason' => "A person's name is required for an individual customer."];
        }
        return ['ok' => true, 'reason' => ''];
    }

    public static function isBusinessType(string $type): bool
    {
        return in_array(strtoupper(trim($type)), ['COMMERCIAL', 'FLEET'], true);
    }

    /** Hard separation: only a business account may carry a company name. */
    public static function accountCompany(string $type, string $company): string
    {
        return self::isBusinessType($type) ? trim($company) : '';
    }

    /**
     * The same hard separation for the provider link: only a PROVIDER job may
     * carry a provider account or a claim reference.
     *
     * The intake form hides both fields for a retail job, and hiding a field
     * does not empty it — a dispatcher who picks Provider, types a claim
     * number, then corrects the source to Retail still POSTS that number. It
     * would save, and the job would read as retail everywhere except the one
     * column that says whose claim it is. Presentation cannot be the rule, so
     * the rule is here and both intake and edit go through it.
     *
     * The inverse is deliberately NOT enforced. A provider job with no account
     * chosen yet is a normal thing to log — intake is hearsay, the claim number
     * often arrives minutes later by email — and refusing it would block a call
     * that is already happening.
     *
     * @return array{provider_id:?int, provider_ref:string}
     */
    public static function providerLink(string $jobSource, ?int $providerId, string $providerRef): array
    {
        return strtoupper(trim($jobSource)) === 'PROVIDER'
            ? ['provider_id' => $providerId, 'provider_ref' => trim($providerRef)]
            : ['provider_id' => null,        'provider_ref' => ''];
    }

    /**
     * A provider job may not become a contract until we know whose contract it
     * is. The provider is the customer of record on this kind of work — they
     * dispatched it and they pay the invoice — so promoting without an account
     * chosen would open an estimate billed to the stranded motorist, and the
     * error would only surface at the invoice, after the work was done.
     *
     * This is a gate on the PROMOTION, not on the save. Intake stays forgiving:
     * a provider call is logged the moment it arrives, account or not, because
     * the claim number routinely lands by email minutes later. What it cannot
     * do is turn into a priced document with the wrong party's name on it.
     *
     * @return array{ok:bool,reason:string}
     */
    public static function providerJobGate(array $sr): array
    {
        if (strtoupper(trim((string) ($sr['job_source'] ?? 'RETAIL'))) !== 'PROVIDER') {
            return ['ok' => true, 'reason' => ''];
        }
        if (empty($sr['provider_id'])) {
            return ['ok' => false, 'reason' => 'This is a provider job, so the provider is the customer of record — '
                . 'they are who the invoice goes to. Choose the provider account on the request (Edit details → Source) '
                . 'before promoting it, or change the source to Retail if the motorist is paying.'];
        }
        return ['ok' => true, 'reason' => ''];
    }

    /**
     * Duplicate detection — runs before ANY customer record is created, from
     * every place a customer can be born (SR promotion, /customers/new).
     *
     * 'exact'    — the phone is on file under the same identity (person name /
     *              company). This IS the existing customer: bind it, never
     *              create a second row.
     * 'possible' — the phone is on file under a different identity (numbers
     *              are shared — a hint, never an identity), or the identity is
     *              on file under a different number. Creation then needs the
     *              dispatcher's explicit override — a human decides, once.
     *
     * All comparisons are case-insensitive; both phone columns count.
     *
     * @return array{level:'exact'|'possible'|null, match:?array}
     */
    public static function duplicateCustomer(string $type, string $company, string $first, string $last, ?string $phone): array
    {
        $isBiz   = self::isBusinessType($type);
        $company = trim($company);
        $first   = trim($first);
        $last    = trim($last);

        if ($phone) {
            $m = $isBiz
                ? ($company === '' ? null : Db::one(
                    'SELECT * FROM customers WHERE (phone_e164 = ? OR phone2_e164 = ?) AND lower(company) = lower(?)',
                    [$phone, $phone, $company]))
                : Db::one(
                    'SELECT * FROM customers WHERE (phone_e164 = ? OR phone2_e164 = ?)
                     AND lower(first_name) = lower(?) AND lower(last_name) = lower(?)',
                    [$phone, $phone, $first, $last]);
            if ($m) { return ['level' => 'exact', 'match' => $m]; }

            $m = Db::one('SELECT * FROM customers WHERE phone_e164 = ? OR phone2_e164 = ?', [$phone, $phone]);
            if ($m) { return ['level' => 'possible', 'match' => $m]; }
        }

        if ($isBiz && $company !== '') {
            $m = Db::one('SELECT * FROM customers WHERE lower(company) = lower(?)', [$company]);
            if ($m) { return ['level' => 'possible', 'match' => $m]; }
        }
        if (!$isBiz && ($first !== '' || $last !== '')) {
            $m = Db::one(
                'SELECT * FROM customers WHERE lower(first_name) = lower(?) AND lower(last_name) = lower(?)',
                [$first, $last]);
            if ($m) { return ['level' => 'possible', 'match' => $m]; }
        }
        return ['level' => null, 'match' => null];
    }

    /**
     * Work order may not be completed without the VIN captured (unless it is a
     * no-vehicle job), and never without the signed estimate the work required.
     */
    public static function workOrderCompletionGate(array $wo, array $est): array
    {
        // The authorization signature is a hard requirement. The COMPLETION
        // sign-off deliberately is not: a customer cannot be compelled to
        // agree the job was done well, so it is asked for insistently and
        // recorded as refused when it does not come. See unsigned_reason.
        $sig = self::workBeginsGate($est, $wo);
        if (!$sig['ok']) {
            return ['ok' => false, 'reason' => 'This work order cannot be closed: the customer never authorized the work. ' . $sig['reason']];
        }
        if (!self::signatureprecededWork($wo)) {
            return ['ok' => false, 'reason' => 'The authorization was signed after work had already started. That cannot be closed out as authorized work — record what happened in the field notes and escalate it.'];
        }
        if (!empty($est['vehicle_id'])) { return ['ok' => true, 'reason' => '']; }
        $lines = Lines::forDoc('WO', (int) $wo['id']);
        foreach ($lines as $l) {
            if ((int) $l['vehicle_not_required'] !== 1) {
                return ['ok' => false, 'reason' => 'VIN required: capture the VIN before completing this work order.'];
            }
        }
        return ['ok' => true, 'reason' => ''];
    }

    /**
     * A diagnostic report may be issued to the customer only when it says
     * something: findings and a recommendation, both. An issued report is
     * frozen; this gate is also what refuses a second issue. Options attached
     * to it are estimates and stay live on their own terms.
     */
    public static function diagnosticIssueGate(array $r): array
    {
        if (($r['status'] ?? '') === 'ISSUED') {
            return ['ok' => false, 'reason' => 'This report was already issued and cannot change. Start a new report for a correction.'];
        }
        if (trim((string) ($r['findings'] ?? '')) === '') {
            return ['ok' => false, 'reason' => 'Findings are required before a report can go to the customer.'];
        }
        if (trim((string) ($r['recommendations'] ?? '')) === '') {
            return ['ok' => false, 'reason' => 'A recommendation is required — the customer needs to know what to do next.'];
        }
        return ['ok' => true, 'reason' => ''];
    }

    /**
     * Repair options on one diagnostic report are mutually exclusive: the
     * customer picks ONE. Authorizing an option is refused while a sibling
     * is already authorized; on success the caller declines the rest as
     * superseded (EstimateController::authorize). Returns the siblings that
     * are still open, so the caller does not run a second query.
     */
    public static function optionAuthorizeGate(array $est): array
    {
        $rid = (int) ($est['diagnostic_report_id'] ?? 0);
        if ($rid === 0) { return ['ok' => true, 'reason' => '', 'siblings' => []]; }
        $sibs = Db::all('SELECT * FROM estimates WHERE diagnostic_report_id = ? AND id != ?', [$rid, (int) $est['id']]);
        foreach ($sibs as $s) {
            if (!empty($s['authorized_at'])) {
                return ['ok' => false, 'siblings' => [],
                        'reason' => 'The customer already chose ' . ($s['option_label'] ?: $s['doc_number'])
                                  . ' on this diagnostic report. One option per report — decline that one first if they changed their mind.'];
            }
        }
        $open = array_values(array_filter($sibs, fn($s) => $s['status'] !== 'DECLINED'));
        return ['ok' => true, 'reason' => '', 'siblings' => $open];
    }
}

/**
 * SMS gateway. Writes to an outbox table so the whole app works before
 * Telnyx 10DLC registration clears. Swap sendNow() for the API call later.
 */
/**
 * Configuration health — known BEFORE anything is promised to a customer.
 *
 * The person this class protects is not the admin; it is the stranded caller.
 * Callers are stressed, and a dispatcher who says "I've texted you a link"
 * has made a promise. If the SMS driver is missing its API key, that promise
 * was never keepable, and the customer stands on a shoulder watching a phone
 * that will not buzz. So misconfiguration is surfaced two ways:
 *
 *   - these checks feed a banner on every admin page and the Messages page,
 *     so the problem is seen before a promise is made, not diagnosed after;
 *   - SettingsController refuses to ACTIVATE a driver whose required
 *     configuration is incomplete — a driver is either fully configured or
 *     not on. There is no half-connected state to discover in the field.
 *
 * Sms::queue() is the last line: it reports send failure in its return value,
 * so even a state that slipped past both gates cannot produce a "texted to
 * the customer" flash for a text that did not go.
 */
final class Health
{
    /** What stops OUTBOUND texts right now. Empty when texts can send. */
    public static function smsSend(): array
    {
        if (App::setting('driver_sms', 'outbox') !== 'telnyx') { return []; }
        $i = [];
        if (trim((string) App::setting('telnyx_api_key', '')) === '') {
            $i[] = ['what' => 'Telnyx is the active SMS driver but no API key is configured — no text can be sent.',
                    'fix'  => 'Add the API key in Settings, or switch the SMS driver back to Outbox.'];
        }
        if (trim((string) App::setting('telnyx_from', '')) === '') {
            $i[] = ['what' => 'Telnyx has no sending number configured — no text can be sent.',
                    'fix'  => 'Add the sending number in Settings.'];
        }
        if (trim((string) App::setting('telnyx_profile_id', '')) === '') {
            $i[] = ['what' => 'No messaging profile ID is configured, so a text would be sent outside the registered 10DLC campaign. Sending is SUSPENDED.',
                    'fix'  => 'Copy the messaging profile ID from the Telnyx portal into Settings.'];
        }
        return $i;
    }

    /**
     * What stops delivery receipts and inbound replies — STOP included.
     *
     * These are not cosmetic: honouring STOP is a 10DLC carrier requirement,
     * so any condition here also SUSPENDS outbound sending (stopSendBlock).
     * A system that can text but cannot hear "STOP" is not a working SMS
     * system; it is a compliance violation with a send button.
     */
    public static function smsReceipts(): array
    {
        if (App::setting('driver_sms', 'outbox') !== 'telnyx') { return []; }
        $i = [];
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            $i[] = ['what' => 'This server\'s PHP has no sodium extension, so no customer reply can be verified — STOP replies included. Outbound texting is SUSPENDED until this is fixed (10DLC).',
                    'fix'  => 'Enable extension=sodium in php.ini (SiteGround: Site Tools → Devs → PHP Manager).'];
        }
        if (trim((string) App::setting('telnyx_public_key', '')) === '') {
            $i[] = ['what' => 'No Telnyx public key is configured, so every customer reply is refused — STOP replies included. Outbound texting is SUSPENDED until this is fixed (10DLC).',
                    'fix'  => 'Copy it from the Telnyx portal (Keys & Credentials → Public Key) into Settings.'];
        }
        if (!str_starts_with(Http::baseUrl(), 'https://')) {
            $i[] = ['what' => 'The app base URL is not https, so STOP replies and receipts cannot reach this install. Outbound texting is SUSPENDED until this is fixed (10DLC).',
                    'fix'  => 'Set the base URL in Settings to the https:// address of this install.'];
        }
        return $i;
    }

    /**
     * Why outbound texting is suspended, or '' when it may proceed.
     *
     * ANY broken link in the 10DLC chain suspends sending — the registered
     * campaign (messaging profile), the STOP-handling path (sodium, public
     * key, https callback), all of it. The reasoning runs one way only:
     * compliance broken → no sends. Never "the customer probably won't reply
     * STOP to an ETA text" — the carrier rules don't have that carve-out, and
     * neither does this.
     */
    public static function stopSendBlock(): string
    {
        if (App::setting('driver_sms', 'outbox') !== 'telnyx') { return ''; }
        if (trim((string) App::setting('telnyx_profile_id', '')) === '') {
            return '10DLC: no messaging profile ID is configured — a text would go outside the registered campaign. Sending is suspended.';
        }
        $broken = self::smsReceipts();
        return $broken === [] ? '' : '10DLC: sending is suspended — ' . $broken[0]['what'];
    }

    /** What stops card payments. */
    public static function payments(): array
    {
        if (App::setting('driver_payments', 'manual') !== 'square') { return []; }
        $i = [];
        if (trim((string) App::setting('square_access_token', '')) === '') {
            $i[] = ['what' => 'Square is the active payment driver but no access token is configured — no payment link can be created.',
                    'fix'  => 'Add the access token in Settings, or switch payments back to Manual.'];
        }
        if (trim((string) App::setting('square_location_id', '')) === '') {
            $i[] = ['what' => 'Square has no location ID configured — no payment link can be created.',
                    'fix'  => 'Add the location ID in Settings.'];
        }
        if (trim((string) App::setting('square_signature_key', '')) === '') {
            $i[] = ['what' => 'Square has no webhook signature key, so payment confirmations cannot be verified — a paid invoice will not mark itself paid.',
                    'fix'  => 'Add the webhook signature key in Settings.'];
        }
        return $i;
    }

    /** Every current problem, grouped for the banner. Empty means healthy. */
    public static function all(): array
    {
        return array_filter([
            'Text messaging'    => array_merge(self::smsSend(), self::smsReceipts()),
            'Card payments'     => self::payments(),
        ]);
    }

    /**
     * What a driver still needs before it may be turned ON. $get resolves a
     * setting to its candidate value — the one arriving in the save, or the
     * stored one — so the check judges the state the save would produce.
     * Returns human-readable names of the missing pieces; empty = activatable.
     */
    public static function missingFor(string $driver, callable $get): array
    {
        $need = match ($driver) {
            'telnyx' => ['telnyx_api_key' => 'API key', 'telnyx_from' => 'sending number',
                         'telnyx_public_key' => 'public key (delivery receipts and STOP replies)',
                         'telnyx_profile_id' => 'messaging profile ID (the registered 10DLC campaign)'],
            'square' => ['square_access_token' => 'access token', 'square_location_id' => 'location ID',
                         'square_signature_key' => 'webhook signature key'],
            default  => [],
        };
        $missing = [];
        foreach ($need as $key => $label) {
            if (trim((string) $get($key)) === '') { $missing[] = $label; }
        }
        return $missing;
    }
}

/**
 * Consent changes, from any channel, through one door.
 *
 * A customer can revoke consent by texting STOP — but also by saying "stop
 * texting me" on a phone call, and the carrier has no way to see that. The
 * obligation to comply is identical either way, so the flag-flipping lives
 * here once and both paths call it: the Telnyx webhook for texted keywords,
 * and the manual form on /messages for everything the carrier cannot hear.
 *
 * What differs between the paths is the EVIDENCE, and the record must say
 * which kind it is. A texted STOP is an inbound message row plus an audit
 * line quoting the text. A verbal one is an audit line naming who took the
 * call and what was said — and deliberately NO message row, because writing
 * "customer texted STOP" about a text that never existed is a fabricated
 * record, exactly what this system never does.
 */
final class Consent
{
    /** Revoke. $how is the evidence line: quoted text, or who-heard-what. */
    public static function optOut(array $cust, string $source, string $how): void
    {
        Db::update('customers', (int) $cust['id'], [
            'sms_approved'       => 0,
            'do_not_contact'     => 1,
            'sms_consent_source' => $source,
            'updated_at'         => now(),
        ]);
        Audit::log('customer', (int) $cust['id'], 'sms:opted_out', $how);
    }

    /** Restore. Only ever from an affirmative act by the customer. */
    public static function optIn(array $cust, string $source, string $how): void
    {
        Db::update('customers', (int) $cust['id'], [
            'sms_approved'       => 1,
            'do_not_contact'     => 0,
            'sms_consent_at'     => now(),
            'sms_consent_source' => $source,
            'updated_at'         => now(),
        ]);
        Audit::log('customer', (int) $cust['id'], 'sms:opted_in', $how);
    }
}

final class Sms
{
    public const TEMPLATES = [
        'dispatch'  => '{co}: Your technician is en route. ETA {eta}. Reply STOP to opt out.',
        // 10DLC: a message may only solicit replies the campaign actually
        // handles — the inbound handler knows STOP/START/HELP and nothing
        // else, so the old "Reply APPROVE" / "Reply PAY" phrases were
        // removed (2026-08-27). Authorization and payment travel as links
        // (sign_auth, pay_link), which do what those keywords promised.
        'estimate'  => '{co}: Your estimate is ready: {total}. Reply STOP to opt out.',
        'on_site'   => '{co}: Your technician has arrived on scene. Reply STOP to opt out.',
        'invoice'   => '{co}: Service complete. Your invoice total is {total}. Reply STOP to opt out.',
        'receipt'   => '{co}: Payment received — thank you. Receipt {doc}. Reply STOP to opt out.',
        'pay_link'  => '{co}: Invoice {doc} — {total} due. Pay securely here: {link} Reply STOP to opt out.',
        // 10DLC shape: brand name first, one clear purpose, the link, and the
        // opt-out. No abbreviations or link shorteners — public shorteners are
        // a common carrier-filtering trigger.
        'locate'    => '{co}: We need your exact location to send help to you. Tap this link and allow location access: {link} Reply STOP to opt out.',
        // To the technician's own phone at dispatch — same one-shot link
        // mechanism as the customer's, so routing can start from where the
        // truck actually is instead of a guess.
        'tech_locate' => '{co} dispatch: Work order {doc} is assigned to you. Tap to share your location for routing: {link} Reply STOP to opt out.',
        'sign_auth' => '{co}: Please review and sign to authorize work order {doc} ({total}): {link} Reply STOP to opt out.',
        'sign_done' => '{co}: Your service is complete. Please review and sign off on work order {doc}: {link} Reply STOP to opt out.',
        'help'      => '{co}: Roadside assistance and service updates. Call (503) 764-3154 for help. Msg&data rates may apply. Reply STOP to opt out.',
        'optin'     => '{co}: Thanks for subscribing to roadside assistance and service updates! Reply HELP for help. Msg frequency may vary. Msg&data rates may apply. Consent is not a condition of purchase. Reply STOP to opt out.',
    ];

    /** No SMS may be sent unless the customer's consent flags allow it. */
    public static function gate(array $customer): array
    {
        if ((int) $customer['do_not_contact'] === 1) { return ['ok' => false, 'reason' => 'Customer is marked do-not-contact.']; }
        if ((int) $customer['sms_approved'] !== 1)   { return ['ok' => false, 'reason' => 'No SMS consent on file for this customer.']; }
        if (!$customer['phone_e164'])                { return ['ok' => false, 'reason' => 'No valid E.164 phone number on file.']; }
        return ['ok' => true, 'reason' => ''];
    }

    /**
     * The return value is the truth about what happened, not just about
     * consent. Callers used to read 'ok' as "the text went out" while it only
     * meant "consent allowed us to try" — so a dispatcher was told "texted to
     * the customer" when Telnyx had refused the send, and a stressed caller
     * waited on a message that was never coming. Now:
     *
     *   ok     consent allowed it and nothing went wrong recording it
     *   sent   a live carrier accepted it just now — the ONLY state in which
     *          anyone may tell a customer a text is coming
     *   held   recorded only: texting is not connected, and NOTHING reached
     *          the customer. Not an instruction to send it some other way —
     *          personal phones are outside this application's scope. The
     *          working fallback is always the phone call.
     *   reason why, whenever ok is false
     *
     * A caller that says "texted" must check 'sent', not 'ok'.
     */
    public static function queue(array $customer, string $template, array $vars = [], ?int $srId = null): array
    {
        $co   = App::config('company')['short'];
        $body = strtr(self::TEMPLATES[$template] ?? '{co}: Update on your service request.', ['{co}' => $co] + $vars);
        $gate = self::gate($customer);

        $suspend = $gate['ok'] ? self::complianceStop($body) : '';
        $send = ($gate['ok'] && $suspend === '')
            ? Integrations::sms()->send((string) $customer['phone_e164'], $body, ['template' => $template])
            : null;
        return self::record($gate, $send, [
            'customer_id'        => (int) $customer['id'],
            'service_request_id' => $srId,
            'phone_e164'         => $customer['phone_e164'],
            'template'           => $template,
            'body'               => $body,
        ], $suspend);
    }

    /**
     * The 10DLC stop, checked BEFORE the carrier is contacted. '' = clear.
     *
     * If any part of compliance is broken, no message goes: a suspended
     * campaign path, an unverifiable STOP reply, or a body with no opt-out
     * language. This runs on every send rather than only at configuration
     * time, because an environment can degrade after settings were saved —
     * a PHP upgrade that drops sodium suspends sending the moment it lands,
     * not the next time somebody visits Settings.
     */
    private static function complianceStop(string $body): string
    {
        if (!Integrations::sms()->isLive()) { return ''; }   // outbox: a human sends, from a phone that receives replies
        $block = Health::stopSendBlock();
        if ($block !== '') { return $block; }
        if (stripos($body, 'STOP') === false) {
            return '10DLC: this message has no opt-out language ("Reply STOP to opt out") — sending refused.';
        }
        return '';
    }

    /** Shared outcome bookkeeping for both queue paths. See queue() for the shape. */
    private static function record(array $gate, ?IntegrationResult $send, array $row, string $suspend = ''): array
    {
        $sent    = $gate['ok'] && $send && $send->ok && Integrations::sms()->isLive();
        $failed  = $gate['ok'] && $send && !$send->ok;
        $stopped = $gate['ok'] && $suspend !== '';
        $held    = $gate['ok'] && !$failed && !$stopped && !$sent;

        Db::insert('messages', $row + [
            'direction'      => 'OUT',
            'channel'        => 'sms',
            'status'         => $gate['ok'] ? ($sent ? 'SENT' : 'QUEUED') : 'BLOCKED',
            'blocked_reason' => $gate['ok'] ? null : $gate['reason'],
            'failure_reason' => $stopped ? substr($suspend, 0, 120)
                              : ($failed ? substr((string) $send->message, 0, 120) : null),
            'provider_ref'   => $send?->reference ?: null,
            'sent_at'        => $sent ? now() : null,
            'created_at'     => now(),
        ]);

        if (!$gate['ok']) {
            return ['ok' => false, 'sent' => false, 'held' => false, 'reason' => $gate['reason']];
        }
        if ($stopped) {
            return ['ok' => false, 'sent' => false, 'held' => false, 'reason' => $suspend];
        }
        if ($failed) {
            return ['ok' => false, 'sent' => false, 'held' => false,
                    'reason' => 'The text did NOT go out — ' . (string) $send->message];
        }
        return ['ok' => true, 'sent' => $sent, 'held' => $held, 'reason' => ''];
    }

    /**
     * ON-SCENE MESSAGES FOLLOW THE VEHICLE, NOT THE BILL.
     *
     * On a provider job the customer of record is the provider: they
     * dispatched the work and the invoice goes to them. They are emphatically
     * NOT the person standing on the shoulder waiting for a truck. "Your
     * technician has arrived" sent to a broker's dispatch line is useless to
     * everyone and tells the motorist nothing.
     *
     * So the money messages — estimate, invoice, receipt, pay link — keep
     * going to the customer of record, and the operational ones come through
     * here, which sends to the caller's own number under the intake consent
     * the dispatcher took on the call.
     *
     * A retail job is unaffected: the customer of record IS the caller, so
     * this falls through to the ordinary customer gate and the durable,
     * sourced consent on their record.
     */
    public static function queueOnScene(array $sr, ?array $customer, string $template, array $vars = []): array
    {
        return ((int) ($customer['is_provider'] ?? 0) === 1 || $customer === null)
            ? self::queueForRequest($sr, $template, $vars)
            : self::queue($customer, $template, $vars, (int) $sr['id']);
    }

    /**
     * The intake-stage variant. At intake there is no customer record yet —
     * only a reported phone number and the dispatcher's checkbox that verbal
     * consent was given on the call. That checkbox is the gate here; it also
     * carries the on-scene traffic for a provider job, where a customer record
     * exists but belongs to the payer rather than the caller — see
     * queueOnScene(). Otherwise the customer-record gate above takes over.
     * Blocked sends are recorded exactly like blocked customer sends:
     * nothing sends silently, and nothing fails silently either.
     */
    /**
     * To a technician's own phone — an internal user, not a customer, so the
     * customer consent machinery does not apply. The gates that remain are
     * real: an active account, a valid number on the user record, and the
     * same 10DLC compliance stop as every other send — one campaign, one set
     * of carrier rules, whoever the recipient is. Recorded in messages like
     * everything else; customer_id stays null so tech traffic never appears
     * in a customer's thread.
     */
    public static function queueToTech(array $user, string $template, array $vars = [], ?int $srId = null): array
    {
        $co    = App::config('company')['short'];
        $body  = strtr(self::TEMPLATES[$template] ?? '{co}: Update.', ['{co}' => $co] + $vars);
        $phone = phone_to_e164((string) ($user['phone_e164'] ?? '')) ?: '';

        $gate = ['ok' => true, 'reason' => ''];
        if ((int) ($user['is_active'] ?? 0) !== 1) {
            $gate = ['ok' => false, 'reason' => 'The technician\'s account is deactivated.'];
        } elseif ($phone === '') {
            $gate = ['ok' => false, 'reason' => 'The technician has no valid phone number on their user record — add one under Admin → Users.'];
        }

        $suspend = $gate['ok'] ? self::complianceStop($body) : '';
        $send = ($gate['ok'] && $suspend === '')
            ? Integrations::sms()->send($phone, $body, ['template' => $template])
            : null;
        return self::record($gate, $send, [
            'customer_id'        => null,
            'service_request_id' => $srId,
            'phone_e164'         => $phone !== '' ? $phone : (string) ($user['phone_e164'] ?? ''),
            'template'           => $template,
            'body'               => $body,
        ], $suspend);
    }

    public static function queueForRequest(array $sr, string $template, array $vars = []): array
    {
        $co    = App::config('company')['short'];
        $body  = strtr(self::TEMPLATES[$template] ?? '{co}: Update on your service request.', ['{co}' => $co] + $vars);
        $phone = phone_to_e164((string) $sr['reported_phone']) ?: '';

        $gate = ['ok' => true, 'reason' => ''];
        if ((int) $sr['comms_consent'] !== 1) {
            $gate = ['ok' => false, 'reason' => 'No SMS consent was recorded at intake. Tick the consent box only if the caller verbally agreed.'];
        } elseif ($phone === '') {
            $gate = ['ok' => false, 'reason' => 'The callback number is not a valid 10-digit phone number.'];
        }

        $suspend = $gate['ok'] ? self::complianceStop($body) : '';
        $send = ($gate['ok'] && $suspend === '')
            ? Integrations::sms()->send($phone, $body, ['template' => $template])
            : null;
        return self::record($gate, $send, [
            'customer_id'        => $sr['customer_id'] ? (int) $sr['customer_id'] : null,
            'service_request_id' => (int) $sr['id'],
            'phone_e164'         => $phone !== '' ? $phone : (string) $sr['reported_phone'],
            'template'           => $template,
            'body'               => $body,
        ], $suspend);
    }
}

/**
 * Customer signature requests.
 *
 * A signature can be taken two ways, and both end up in the same place:
 *
 *   IN_PERSON — the technician turns their device around, the customer reads
 *               the document and signs on it. No token, no link, no waiting.
 *   SMS       — a link is texted to the customer, who opens the document on
 *               their own phone and signs there. For when they are not on
 *               scene: keys left, fleet vehicle, customer went home.
 *
 * The token is the entire access control for the link, so it is long, random,
 * and unique by database index rather than by a PHP check two concurrent
 * issues could race past. Requests are single-use: signing closes them.
 */
final class SignatureRequest
{
    public const PURPOSES = ['AUTH' => 'Authorization to begin work', 'COMPLETION' => 'Sign-off that work is complete'];

    /** Issue a request and return it. Supersedes any open one for the same document and purpose. */
    public static function issue(string $docType, int $docId, string $purpose, array $customer, float $amount, string $channel = 'SMS'): array
    {
        // Only one live link per document per purpose, so an older text cannot
        // be used to sign something that has since been re-issued.
        Db::q(
            "UPDATE signature_requests SET status = 'VOID', void_reason = 'superseded by a newer request'
             WHERE doc_type = ? AND doc_id = ? AND purpose = ? AND status = 'OPEN'",
            [$docType, $docId, $purpose]
        );

        $id = Db::insert('signature_requests', [
            'token'       => bin2hex(random_bytes(24)),
            'doc_type'    => $docType,
            'doc_id'      => $docId,
            'purpose'     => $purpose,
            'customer_id' => (int) $customer['id'],
            'channel'     => $channel,
            'phone_e164'  => $customer['phone_e164'] ?? null,
            'status'      => 'OPEN',
            'amount'      => $amount,
            'created_by'  => Auth::id(),
            'created_at'  => now(),
        ]);
        return Db::one('SELECT * FROM signature_requests WHERE id = ?', [$id]) ?? [];
    }

    public static function byToken(string $token): ?array
    {
        return Db::one('SELECT * FROM signature_requests WHERE token = ?', [$token]);
    }

    public static function url(array $req): string
    {
        return Http::baseUrl() . '/sign/' . $req['token'];
    }

    /** First time the customer opens the link. Recorded once — later views do not overwrite it. */
    public static function markViewed(array $req): void
    {
        if (!empty($req['viewed_at'])) { return; }
        Db::update('signature_requests', (int) $req['id'], ['viewed_at' => now()]);
        Audit::log('signature_request', (int) $req['id'], 'viewed',
            $req['doc_type'] . ' #' . $req['doc_id'] . ' opened by the customer · IP ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    }

    public static function markSigned(array $req, string $signerName): bool
    {
        $st = Db::q(
            "UPDATE signature_requests SET status = 'SIGNED', signed_at = ?, signer_name = ?,
             signed_ip = ?, signed_agent = ? WHERE id = ? AND status = 'OPEN'",
            [
                now(), $signerName, $_SERVER['REMOTE_ADDR'] ?? null,
                substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250), (int) $req['id'],
            ]
        );
        return $st->rowCount() === 1;
    }

    /** Void older open links after a signature was captured through another path. */
    public static function voidOpenFor(string $docType, int $docId, string $purpose, ?int $exceptId = null): void
    {
        $sql  = "SELECT id FROM signature_requests
                 WHERE doc_type = ? AND doc_id = ? AND purpose = ? AND status = 'OPEN'";
        $args = [$docType, $docId, $purpose];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $args[] = $exceptId;
        }

        foreach (Db::all($sql, $args) as $row) {
            Db::update('signature_requests', (int) $row['id'], [
                'status' => 'VOID', 'void_reason' => 'superseded by a signature captured elsewhere',
            ]);
            Audit::log('signature_request', (int) $row['id'], 'voided',
                'superseded by a signature captured elsewhere for ' . $docType . ' #' . $docId);
        }
    }

    /** The open request for a document, if one is outstanding. */
    public static function openFor(string $docType, int $docId, string $purpose): ?array
    {
        return Db::one(
            "SELECT * FROM signature_requests
             WHERE doc_type = ? AND doc_id = ? AND purpose = ? AND status = 'OPEN'
             ORDER BY id DESC",
            [$docType, $docId, $purpose]
        );
    }
}

/**
 * Customer location requests.
 *
 * "Capture GPS" never means the dispatcher's GPS — a dispatcher at a desk is
 * not the stranded caller. It means texting the caller a link that asks THEIR
 * phone for its position, because callers often cannot describe where they
 * are (see the SMS location-capture flow in the workbook, §17).
 *
 * Same access model as a signature request: the unguessable token in the URL
 * is the entire control. Stricter lifetime, though — the answer to "where are
 * you right now" goes stale fast, so links are single-use and die after
 * EXPIRY_HOURS. What comes back is snapshotted onto the requesting document;
 * this table keeps the evidence trail.
 */
final class LocationRequest
{
    /** One-shot, 4-hour max expiry — the 10DLC workbook's rule, verbatim. */
    public const EXPIRY_HOURS = 4;

    /** Issue a request and return it. Supersedes any open one for the same document. */
    public static function issue(string $docType, int $docId, string $phoneE164, ?int $srId = null, ?int $customerId = null): array
    {
        Db::q(
            "UPDATE location_requests SET status = 'VOID', void_reason = 'superseded by a newer request'
             WHERE doc_type = ? AND doc_id = ? AND status = 'OPEN'",
            [$docType, $docId]
        );

        $id = Db::insert('location_requests', [
            'token'              => bin2hex(random_bytes(24)),
            'doc_type'           => $docType,
            'doc_id'             => $docId,
            'service_request_id' => $srId,
            'customer_id'        => $customerId,
            'phone_e164'         => $phoneE164,
            'status'             => 'OPEN',
            'expires_at'         => date('Y-m-d H:i:s', time() + self::EXPIRY_HOURS * 3600),
            'created_by'         => Auth::id(),
            'created_at'         => now(),
        ]);
        return Db::one('SELECT * FROM location_requests WHERE id = ?', [$id]) ?? [];
    }

    public static function byToken(string $token): ?array
    {
        return Db::one('SELECT * FROM location_requests WHERE token = ?', [$token]);
    }

    public static function url(array $req): string
    {
        return Http::baseUrl() . '/locate/' . $req['token'];
    }

    /**
     * Expiry is decided at read time, not by a cron this stack does not have.
     * The first touch after the deadline flips the row so the trail is honest.
     */
    public static function checkExpiry(array $req): array
    {
        if ($req['status'] === 'OPEN' && $req['expires_at'] && strtotime((string) $req['expires_at']) < time()) {
            Db::update('location_requests', (int) $req['id'], ['status' => 'EXPIRED']);
            $req['status'] = 'EXPIRED';
        }
        return $req;
    }

    /** First time the customer opens the link. Recorded once — later views do not overwrite it. */
    public static function markViewed(array $req): void
    {
        if (!empty($req['viewed_at'])) { return; }
        Db::update('location_requests', (int) $req['id'], ['viewed_at' => now()]);
        Audit::log('location_request', (int) $req['id'], 'viewed',
            $req['doc_type'] . ' #' . $req['doc_id'] . ' location link opened · IP ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    }

    public static function markReceived(array $req, float $lat, float $lng, ?float $accuracyM, ?string $address, ?string $intersection, string $driver): bool
    {
        $st = Db::q(
            "UPDATE location_requests SET status = 'RECEIVED', received_at = ?, latitude = ?,
             longitude = ?, accuracy_m = ?, nearest_address = ?, nearest_intersection = ?,
             geo_driver = ?, received_ip = ?, received_agent = ?
             WHERE id = ? AND status = 'OPEN'",
            [
                now(), sprintf('%.7F', $lat), sprintf('%.7F', $lng),
                $accuracyM !== null ? sprintf('%.1F', $accuracyM) : null,
                $address, $intersection, $driver, $_SERVER['REMOTE_ADDR'] ?? null,
                substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250), (int) $req['id'],
            ]
        );
        return $st->rowCount() === 1;
    }

    /** The open request for a document, if one is outstanding. */
    public static function openFor(string $docType, int $docId): ?array
    {
        $req = Db::one(
            "SELECT * FROM location_requests
             WHERE doc_type = ? AND doc_id = ? AND status = 'OPEN'
             ORDER BY id DESC",
            [$docType, $docId]
        );
        if (!$req) { return null; }
        $req = self::checkExpiry($req);
        return $req['status'] === 'OPEN' ? $req : null;
    }

    /** The most recent answered request for a document. */
    public static function receivedFor(string $docType, int $docId): ?array
    {
        return Db::one(
            "SELECT * FROM location_requests
             WHERE doc_type = ? AND doc_id = ? AND status = 'RECEIVED'
             ORDER BY received_at DESC",
            [$docType, $docId]
        );
    }
}

/**
 * Parts markup pricing — the single source of truth for the markup matrix.
 *
 * Nothing else in the application computes a marked-up price; every surface
 * (catalog form, estimate/work-order/invoice lines) asks this class. The
 * formula is:  customer_price = cost + cost × tier_markup%  where the tier is
 * chosen from the cost's band in the matrix.
 *
 * All money math here is done in INTEGER CENTS — never floats — and rounded
 * once, commercially (half away from zero), at the end. Percentages are held
 * as integer hundredths-of-a-percent (75.00% → 7500) so the arithmetic stays
 * exact for any cost, including engines and transmissions.
 *
 * Boundary rule (defined here and covered by tests): a cost lands in the first
 * tier, scanning cheapest-first, whose max_cost it does not exceed. So a cost
 * sitting exactly on a boundary belongs to the LOWER tier (that tier's upper
 * bound is inclusive). The top tier has no max and catches everything above.
 */
final class Markup
{
    /** Seeded on install. [min, max|null, pct] in dollars/percent. */
    public const DEFAULTS = [
        [0.00,      10.00,   200.0],
        [10.01,     50.00,   100.0],
        [50.01,     200.00,   75.0],
        [200.01,    500.00,   50.0],
        [500.01,    1500.00,  35.0],
        [1500.01,   null,     25.0],
    ];

    /* ---- money helpers: dollars ⇄ integer cents, no float math ------- */

    /** Parse a user/DB money value ("$1,234.50") to integer cents. */
    public static function toCents(mixed $v): int
    {
        if ($v === null || $v === '') { return 0; }
        $s = str_replace([',', '$', ' '], '', (string) $v);
        $neg = str_starts_with($s, '-');
        $s = ltrim($s, '-');
        $parts = explode('.', $s, 2);
        $whole = (int) ($parts[0] === '' ? '0' : $parts[0]);
        $frac  = isset($parts[1]) ? substr($parts[1] . '00', 0, 2) : '00';
        $cents = $whole * 100 + (int) $frac;
        return $neg ? -$cents : $cents;
    }

    /** Integer cents → a "12.34" string suitable for a DECIMAL column. */
    public static function centsToStr(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        return $sign . intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Percent ("75.00") → integer hundredths of a percent (7500). */
    public static function pctToHundredths(mixed $pct): int
    {
        return self::toCents($pct); // same fixed-point parse: 2 decimal places
    }

    /* ---- the matrix -------------------------------------------------- */

    /** Tiers ascending by min_cost. */
    public static function tiers(): array
    {
        return Db::all('SELECT * FROM markup_tiers ORDER BY sort_order, min_cost, id');
    }

    /**
     * The tier a given cost (in cents) falls into, or null if the matrix has a
     * gap that leaves the cost uncovered. Cheapest-first scan; upper bound of
     * each tier is inclusive (the boundary rule).
     *
     * @param array<int,array<string,mixed>>|null $tiers  defaults to the stored matrix
     */
    public static function tierFor(int $costCents, ?array $tiers = null): ?array
    {
        $tiers ??= self::tiers();
        foreach ($tiers as $t) {
            $min = self::toCents($t['min_cost']);
            if ($costCents < $min) { continue; }
            if ($t['max_cost'] === null || $t['max_cost'] === '') { return $t; }
            if ($costCents <= self::toCents($t['max_cost'])) { return $t; }
        }
        return null;
    }

    /**
     * Suggest a customer price for a cost.
     *
     * @return array{needs_pricing:bool, price:?string, price_cents:?int,
     *               markup_pct:?string, tier_id:?int}
     *   needs_pricing is true when there is no cost to mark up — the caller
     *   should ask for a manual price rather than quoting $0.
     *
     * @param array<int,array<string,mixed>>|null $tiers
     */
    public static function suggest(mixed $cost, ?array $tiers = null): array
    {
        $costCents = self::toCents($cost);
        if ($costCents <= 0) {
            return ['needs_pricing' => true, 'price' => null, 'price_cents' => null, 'markup_pct' => null, 'tier_id' => null];
        }

        $tier = self::tierFor($costCents, $tiers);
        if ($tier === null) {
            return ['needs_pricing' => true, 'price' => null, 'price_cents' => null, 'markup_pct' => null, 'tier_id' => null];
        }

        $pctH  = self::pctToHundredths($tier['markup_pct']);          // 7500 = 75.00%
        // price = cost × (100% + pct). In integer cents:
        //   priceCents = round( costCents × (1000000 + pctH×100) / 1000000 )
        // pctH is hundredths-of-percent; ×100 lifts it to the 1e6 base that
        // 100% (=1000000) uses. Commercial rounding: add half the divisor.
        $mult      = 1000000 + $pctH * 100;
        $num       = $costCents * $mult;
        $priceCents = intdiv($num + 500000, 1000000);

        return [
            'needs_pricing' => false,
            'price'         => self::centsToStr($priceCents),
            'price_cents'   => $priceCents,
            'markup_pct'    => self::centsToStr($pctH),  // "75.00"
            'tier_id'       => (int) $tier['id'],
        ];
    }

    /** Profit in cents for a sold price against a cost. */
    public static function profitCents(mixed $price, mixed $cost): int
    {
        return self::toCents($price) - self::toCents($cost);
    }

    /** Margin as a percentage string ("42.9"), or null when price is 0. */
    public static function marginPct(mixed $price, mixed $cost): ?string
    {
        $p = self::toCents($price);
        if ($p <= 0) { return null; }
        $profit = $p - self::toCents($cost);
        // Tenths of a percent = round(profit / price × 1000), integer math with
        // round-half-away-from-zero. Divisor is doubled so the +den rounds half.
        $num = $profit * 1000;
        $tenths = $num >= 0
            ? intdiv(2 * $num + $p, 2 * $p)
            : -intdiv(-2 * $num + $p, 2 * $p);
        return number_format($tenths / 10, 1);
    }

    /**
     * Validate a proposed matrix. Returns a list of human-readable errors;
     * empty means valid. Rules: percentages ≥ 0; ascending, contiguous bands
     * with no overlap and no gap (each tier's min is one cent above the
     * previous tier's max); exactly one open-ended top tier; min ≥ 0.
     *
     * @param array<int,array{min_cost:mixed,max_cost:mixed,markup_pct:mixed}> $tiers  already sorted by min
     */
    public static function validate(array $tiers): array
    {
        $errors = [];
        if (!$tiers) { return ['Add at least one tier.']; }

        $n = count($tiers);
        $prevMax = null;
        foreach (array_values($tiers) as $i => $t) {
            $min = self::toCents($t['min_cost']);
            $isOpen = ($t['max_cost'] === null || $t['max_cost'] === '');
            $max = $isOpen ? null : self::toCents($t['max_cost']);
            $pct = self::pctToHundredths($t['markup_pct']);
            $row = 'Tier ' . ($i + 1);

            if ($pct < 0)          { $errors[] = "$row: markup percent cannot be negative."; }
            if ($i === 0 && $min !== 0) { $errors[] = "$row: the first tier must start at \$0.00."; }
            if (!$isOpen && $max < $min) { $errors[] = "$row: max cost is below its min cost."; }
            if ($isOpen && $i !== $n - 1) { $errors[] = "$row: only the last tier may be open-ended."; }
            if (!$isOpen && $i === $n - 1) { $errors[] = "$row: the top tier must be open-ended (no max)."; }

            if ($prevMax !== null && $min !== $prevMax + 1) {
                if ($min <= $prevMax) { $errors[] = "$row: overlaps the tier below it."; }
                else                  { $errors[] = "$row: leaves a gap above the tier below it."; }
            }
            $prevMax = $isOpen ? null : $max;
        }
        return $errors;
    }
}

/* ---------------------------------------------------------------------------
 * Chart of accounts.
 *
 * The gl_accounts table backs the account pickers on the catalog form (and,
 * eventually, the accounting views). Catalog items store the account NUMBER
 * as text — the number is the accounting identity, so the reference survives
 * renames and never dangles. Accounts are retired, never deleted.
 *
 * Numbering follows the settled small-business convention from the accounting
 * notes: 1xxx assets · 2xxx liabilities · 3xxx equity · 4xxx revenue ·
 * 5xxx COGS · 6xxx+ expenses. Assets, liabilities and equity were added with
 * the ledger build; before there was a journal, only the accounts the catalog
 * tagged had anywhere to be used.
 * ------------------------------------------------------------------------- */
final class Accounts
{
    public const TYPES = ['ASSET', 'LIABILITY', 'EQUITY', 'REVENUE', 'COGS', 'EXPENSE'];

    /** Seeded when the table is empty. [number, name, type] — the settled set. */
    public const DEFAULTS = [
        /* ASSETS, LIABILITIES, EQUITY and EXPENSES were added with the ledger
         * build. Before there was a journal there was nothing to post a debit
         * to, so only the accounts the catalog tagged were seeded — and an
         * entry cannot be written until BOTH of its sides exist.
         *
         * Numbers and names are taken from the reconciled chart in
         * knowledge/WKR-KNOWLEDGE.md, which is the authority for these ranges.
         * The 4xxx REVENUE block below is the exception: the knowledge chart's
         * per-service revenue tree is explicitly recorded as superseded and
         * never implemented, and the five accounts already live here are the
         * settled set. Reporting by service is done with service_category.
         *
         * Some are template accounts for an operator this application is later
         * sold to rather than for WKR itself — 2020 Sales Tax Payable (Oregon
         * has none), 1200 Parts Inventory (parts are catalog items with a
         * cost, not tracked stock), the 6050–6070 payroll block (WKR is
         * owner-only; owner pay is 3200 Owner Draw, never a wage expense).
         * They seed active and are retired from /accounts if unwanted, because
         * retiring is an operator decision and this list is not the place to
         * make it for them. */
        ['1000', 'Cash',                               'ASSET'],
        ['1010', 'Checking',                           'ASSET'],
        ['1015', 'Undeposited Funds',                  'ASSET'],
        ['1050', 'Square Clearing',                    'ASSET'],
        /* PREPAID CARDS ARE A CASH ACCOUNT, NOT AN EXPENSE.
         *
         * Money loaded onto a prepaid card has not been spent — it has moved
         * between two places the business owns, exactly like petty cash. The
         * expense happens when the card buys something, and until the card's
         * own history says what that was, the amount is neither a deduction
         * nor a draw.
         *
         * This account exists because $27,805 left Square Checking across 2023
         * and 2024 onto four prepaid cards whose statements are not in hand.
         * Posting it as an expense would claim deductions with no substantiation
         * behind them; posting it as owner draw would assert personal use that
         * was never claimed. Parking it here asserts only what is known: the
         * money moved, and what it became is not yet established.
         *
         * The balance is meant to be uncomfortable. A suspense account that
         * nobody looks at is just a hiding place, and this one must eventually
         * drain — into real expense accounts as card histories arrive, or into
         * 3200 Owner Draw for whatever can never be documented. That last step
         * is a decision for an accountant, not for this list. */
        ['1030', 'Prepaid Cards',                      'ASSET'],
        ['1100', 'Accounts Receivable',                'ASSET'],
        ['1120', 'Business Savings',                   'ASSET'],
        ['1200', 'Parts Inventory',                    'ASSET'],
        ['1300', 'Prepaid Expenses',                   'ASSET'],
        ['1500', 'Service Vehicle',                    'ASSET'],
        ['1510', 'Tools and Equipment',                'ASSET'],
        ['1590', 'Accumulated Depreciation',           'ASSET'],

        // A refundable core charge is money held, not money earned. It has to
        // exist here because the catalog's core-deposit item points at it, and
        // a catalog item pointing at an account that does not exist is how the
        // seeded price book silently lost its account codes.
        ['2000', 'Accounts Payable',                   'LIABILITY'],
        ['2010', 'Credit Card Payable',                'LIABILITY'],
        ['2020', 'Sales Tax Payable',                  'LIABILITY'],
        ['2050', 'Core Deposits Payable',              'LIABILITY'],
        ['2060', 'Customer Refunds Payable',           'LIABILITY'],
        ['2300', 'Customer Deposits',                  'LIABILITY'],
        /* Square Capital lends against future card sales and takes repayment
         * out of every payout before the money reaches the bank. It is a real
         * debt and it was missing from this chart entirely, which meant the
         * balance sheet showed no borrowing at all. */
        ['2100', 'Square Capital Loan',                'LIABILITY'],

        /* 3200 Owner Draw is where a sole owner's pay belongs. A false wage
         * expense distorts both profit and tax, which is why 6050 exists for
         * an employer shop and is NOT what WKR itself should use. */
        ['3000', 'Owner Equity',                       'EQUITY'],
        ['3100', 'Owner Contributions',                'EQUITY'],
        ['3200', 'Owner Draw',                         'EQUITY'],
        ['3300', 'Retained Earnings',                  'EQUITY'],

        ['4000', 'Service Labor Revenue',              'REVENUE'],
        ['4010', 'Parts Sales Revenue',                'REVENUE'],
        ['4020', 'Fuel Delivery Revenue',              'REVENUE'],
        ['4030', 'Fees & Surcharges Revenue',          'REVENUE'],
        ['4040', 'Discounts / Adjustments',            'REVENUE'],
        /* Tips are earned money with no service line behind them, so they need
         * somewhere to land that is not one of the four service buckets.
         * Taken from the reconciled chart; this is NOT a reopening of the
         * per-service revenue tree. */
        ['4300', 'Other Revenue',                      'REVENUE'],
        /* Six years of card sales that arrived before this application existed
         * and have no invoice, no work order and no line items behind them —
         * only a Square payment and the amount. They are real revenue and must
         * be on the books, but crediting them to 4000 would assert they were
         * labour, which nothing knows. Kept apart so that when the Orders
         * import finally says what each job actually was, the reclassification
         * is a clean reversal out of one account rather than an attempt to
         * unpick documented revenue from guessed revenue. A shrinking balance
         * here is the measure of that work. */
        ['4050', 'Historical Card Sales (unattributed)', 'REVENUE'],
        ['5000', 'COGS — Parts & Materials Sold',      'COGS'],
        ['5010', 'COGS — Sublet / Outside Services',   'COGS'],
        ['5020', 'COGS — Consumables Used on Jobs',    'COGS'],
        ['5030', 'COGS — Vehicle Fuel Used for Jobs',  'COGS'],
        ['5040', 'COGS — Merchant Processing Fees',    'COGS'],
        ['5050', 'COGS — Warranty / Rework Costs',     'COGS'],
        ['5060', 'COGS — Disposal / Environmental Fees','COGS'],
        ['5070', 'COGS — Direct Labor',                'COGS'],
        ['5080', 'COGS — Roadside Equipment Usage',    'COGS'],
        ['5090', 'COGS — Fuel Sold / Delivered Fuel',  'COGS'],

        /* OPERATING EXPENSES. Kept out of the 5xxx block on purpose: direct
         * job costs stay in COGS so gross profit per job stays readable.
         *
         * 6150 is not new — data/seed.php has been writing an expense against
         * it since the demo data was written, with no such account existing.
         * 6600 is named Small Tools EXPENSE so an expensed tool cannot be
         * confused with capitalised asset 1510. Merchant processing lives in
         * 7000/7010, not 6850, which was struck from the draft chart as a
         * duplicate. */
        ['6010', 'Vehicle Maintenance & Repairs',      'EXPENSE'],
        ['6050', 'Employee Wages',                     'EXPENSE'],
        ['6060', 'Payroll Taxes',                      'EXPENSE'],
        ['6070', 'Employee Benefits',                  'EXPENSE'],
        ['6080', 'Contractor Labour',                  'EXPENSE'],
        ['6100', 'Marketing & Advertising',            'EXPENSE'],
        ['6110', 'Google Ads',                         'EXPENSE'],
        ['6120', 'Software Subscriptions',             'EXPENSE'],
        ['6130', 'Phone & Communications',             'EXPENSE'],
        ['6150', 'SMS Messaging (Telnyx)',             'EXPENSE'],
        /* A mobile business still rents something — a storage unit for parts
         * and equipment, a yard, occasionally a bay. This was missing from the
         * chart entirely and only surfaced when a real statement showed $207 a
         * month going to Public Storage with nowhere to put it. It maps to
         * Schedule C line 20b, rent or lease of other business property, which
         * is its own line on the form and should not be buried in 6900. */
        ['6200', 'Rent & Storage',                     'EXPENSE'],
        ['6250', 'Vehicle Insurance',                  'EXPENSE'],
        ['6300', 'General Insurance',                  'EXPENSE'],
        ['6400', 'Supplies',                           'EXPENSE'],
        ['6500', 'Licensing & Permits',                'EXPENSE'],
        ['6600', 'Small Tools Expense',                'EXPENSE'],
        ['6800', 'Office Expenses',                    'EXPENSE'],
        ['6900', 'Other Expenses',                     'EXPENSE'],
        ['7000', 'Merchant Processing Fees',           'EXPENSE'],
        ['7010', 'Square Fees',                        'EXPENSE'],
        ['7020', 'Chargebacks',                        'EXPENSE'],
        /* Kept in the 70xx fee block rather than the 6730 the draft chart
         * suggested, so every cost of taking and financing money sits
         * together. Square does not break a repayment into principal and
         * interest, so this is filled by hand from the loan statement. */
        ['7030', 'Financing Interest & Fees',          'EXPENSE'],
    ];

    /**
     * Seed the defaults into the table. Runs lazily on first read rather than
     * only at install time, because existing databases (local and production)
     * predate the table — Db::migrate() creates it empty, and the first page
     * that needs accounts fills it.
     *
     * Additive, in the same sense as Db::addMissingColumns: a default that is
     * not present is inserted, and nothing already there is touched, renamed,
     * retired or renumbered. An earlier version returned early the moment the
     * table held a single row, which meant a default added later could never
     * reach a database that had already been through here once — and a catalog
     * item pointing at an account that was never seeded is exactly how the
     * price book lost its account codes. Operator-created accounts and
     * operator edits to a default's name are both left alone.
     */
    public static function ensureSeeded(): void
    {
        try {
            $have = Db::all('SELECT account_number FROM gl_accounts');
        } catch (Throwable) {
            // The table postdates this database, and production has no SSH to
            // run a CLI migrate. Db::migrate() is additive and documented safe
            // against a live database — build the missing table, then seed.
            Db::migrate();
            $have = Db::all('SELECT account_number FROM gl_accounts');
        }
        $known = array_column($have, 'account_number');
        $gone  = self::tombstoned();
        $i     = count($known);
        foreach (self::DEFAULTS as [$number, $name, $type]) {
            if (in_array($number, $known, true)) { continue; }
            // Deleted on purpose. Seeding is additive, but "additive" must not
            // mean "undoes a deletion on the next page load".
            if (in_array($number, $gone, true))  { continue; }
            Db::insert('gl_accounts', [
                'account_number' => $number,
                'name'           => $name,
                'account_type'   => $type,
                'is_active'      => 1,
                'sort_order'     => $i++,
                'created_at'     => now(),
            ]);
        }
    }

    /** Account numbers that were seeded defaults and have been deleted. */
    public static function tombstoned(): array
    {
        try {
            return array_column(
                Db::all('SELECT account_number FROM gl_account_tombstones'), 'account_number');
        } catch (Throwable) {
            // Table postdates this database; nothing has been deleted yet.
            return [];
        }
    }

    /** Is this number one of the seeded defaults? */
    public static function isDefault(string $number): bool
    {
        foreach (self::DEFAULTS as [$n]) { if ($n === $number) { return true; } }
        return false;
    }

    /**
     * What still points at this account number, by table. Reported so a
     * deletion is an informed choice — never used to refuse one.
     *
     * catalog_items and expenses reference the number so the picker can show
     * it; journal_lines SNAPSHOT it, along with the name and type, which is
     * why deleting an account does not corrupt posted history. The line keeps
     * its own copy of all three.
     *
     * @return array<string,int> non-empty tables only
     */
    public static function usage(string $number): array
    {
        $counts = [];
        $probe = static function (string $table, string $sql, array $bind) use (&$counts): void {
            try {
                $n = (int) Db::val("SELECT COUNT(*) FROM $table WHERE $sql", $bind, 0);
                if ($n > 0) { $counts[$table] = $n; }
            } catch (Throwable) { /* table may not exist on an older database */ }
        };
        $probe('catalog_items', 'revenue_account = ? OR cogs_account = ?', [$number, $number]);
        $probe('journal_lines', 'account_number = ?',                      [$number]);
        $probe('expenses',      'account_code = ?',                        [$number]);
        return $counts;
    }

    /**
     * Delete an account outright.
     *
     * Retiring keeps a wrong account visible forever, which is right for books
     * that are real and wrong for a chart still being designed. Both are
     * offered; this is the one that actually removes the row.
     *
     * Posted history is unaffected — journal_lines carry their own copy of the
     * number, name and type, so a deleted account leaves every entry that used
     * it fully readable. Catalog items keep the number as text and simply show
     * it as unmatched in the picker until repointed.
     *
     * Returns human-readable errors; empty means it was deleted. Mirrors
     * create()'s contract.
     */
    public static function delete(int $id): array
    {
        $acct = Db::one('SELECT * FROM gl_accounts WHERE id = ?', [$id]);
        if (!$acct) { return ['That account is not in the chart of accounts — it may have been deleted. Refresh the page and pick again.']; }

        $number = (string) $acct['account_number'];

        Db::tx(static function () use ($id, $number, $acct): void {
            Db::q('DELETE FROM gl_accounts WHERE id = ?', [$id]);

            /* A default would be re-seeded on the next read, so record that it
             * was removed deliberately. Operator-created accounts need no
             * tombstone — nothing would put them back. */
            if (self::isDefault($number) && !in_array($number, self::tombstoned(), true)) {
                Db::insert('gl_account_tombstones', [
                    'account_number' => $number,
                    'deleted_by'     => Auth::id(),
                    'created_at'     => now(),
                ]);
            }
            Audit::log('gl_account', $id, 'deleted', $number . ' ' . (string) $acct['name']);
        });

        return [];
    }

    /** Every account, retired included, in numbering order — the admin page. */
    public static function all(): array
    {
        self::ensureSeeded();
        return Db::all('SELECT * FROM gl_accounts ORDER BY account_number');
    }

    /** Active accounts of one type, in numbering order. */
    public static function forType(string $type): array
    {
        self::ensureSeeded();
        return Db::all(
            'SELECT * FROM gl_accounts WHERE account_type = ? AND is_active = 1
             ORDER BY account_number',
            [$type]
        );
    }

    /**
     * Validate and create an account. Returns human-readable errors; empty
     * means it was created. Mirrors Markup::validate's contract.
     */
    public static function create(string $number, string $name, string $type): array
    {
        $number = trim($number);
        $name   = trim($name);
        $errors = [];

        if (!preg_match('/^\d{3,8}$/', $number)) { $errors[] = 'Account number must be 3–8 digits.'; }
        if ($name === '')                        { $errors[] = 'The account needs a name that says what the money in it means.'; }
        if (!in_array($type, self::TYPES, true)) { $errors[] = 'Unknown account type.'; }
        if ($errors) { return $errors; }

        if (Db::one('SELECT id FROM gl_accounts WHERE account_number = ?', [$number])) {
            return ["Account $number already exists."];
        }

        $id = Db::insert('gl_accounts', [
            'account_number' => $number,
            'name'           => $name,
            'account_type'   => $type,
            'is_active'      => 1,
            'sort_order'     => 0,
            'created_at'     => now(),
        ]);

        /* Adding a number back by hand is an unambiguous "I want this one
         * after all", so it clears any tombstone. Without this the account
         * would exist but the seeder would still consider it deleted, which
         * is a difference nobody can see and everybody would trip over. */
        try {
            Db::q('DELETE FROM gl_account_tombstones WHERE account_number = ?', [$number]);
        } catch (Throwable) { /* table postdates this database */ }

        Audit::log('gl_account', $id, 'created', $number . ' ' . $name);
        return [];
    }
}

/* ---------------------------------------------------------------------------
 * THE GENERAL LEDGER
 *
 * The only thing in the application that writes journal_entries or
 * journal_lines. Everything else calls post(); nothing else touches those
 * tables. Same rule as Markup owning the price formula and Rules owning the
 * hard rules — one place, so there is one thing to be right.
 *
 * Books are ACCRUAL. Revenue posts when an invoice is issued, not when the
 * money arrives. Cash-basis is a report derived from these rows by excluding
 * unpaid invoices, not a second set of books. See docs/ACCOUNTING_PLAN.md.
 *
 * FOUR RULES, none of them negotiable:
 *
 *   1. Debits equal credits, checked in integer cents. Not "close enough" —
 *      a one-cent difference is refused, never rounded away. Money math here
 *      follows the same no-float discipline as Markup, and reuses its parser.
 *
 *   2. A line carries a debit or a credit, never both, never neither. A
 *      single signed column would be shorter and is how this gets built
 *      wrong: the sign convention differs by account type, and the balance
 *      check stops being expressible.
 *
 *   3. Nothing is edited and nothing is deleted, ever. A correction is a
 *      reversing entry linked to its original. Both rows survive; the pair
 *      nets to zero. This is the same treatment voids and credits already get.
 *
 *   4. An entry posts inside one transaction with its lines, or not at all.
 *      A header with no lines is an unbalanced ledger that looks balanced.
 * ------------------------------------------------------------------------- */
final class Ledger
{
    /**
     * What produced an entry. ADJ is a manual journal; REV a reversal.
     *
     * The three SQ sources exist so that idempotency has an unambiguous key.
     * A Square settlement entry is identified by (source_type, source_id),
     * where source_id is a row id — and a charge entry, a payout header and a
     * payment row are three different tables whose ids overlap freely. One
     * shared 'SQ' source would make row 5 of one indistinguishable from row 5
     * of another, and the second posting would be silently skipped as already
     * done. Separate sources make forSource() answer the question it is being
     * asked.
     */
    public const SOURCES = ['INV', 'PAY', 'REF', 'VOID', 'EXP', 'CORE', 'ADJ', 'REV',
                            'SQCHG', 'SQDED', 'SQPAY'];

    /** Account types whose balance increases on the DEBIT side. */
    public const DEBIT_POSITIVE = ['ASSET', 'COGS', 'EXPENSE'];

    /** Account types whose balance increases on the CREDIT side. */
    public const CREDIT_POSITIVE = ['LIABILITY', 'EQUITY', 'REVENUE'];

    /* ---- pure helpers: no database, unit-testable ---------------------- */

    /**
     * Normalise a caller's line into the shape post() stores.
     *
     * Accepts dollars in either 'debit'/'credit' or the integer-cents forms
     * 'debit_cents'/'credit_cents', so a caller that has already done exact
     * arithmetic never has to round-trip through a decimal string.
     *
     * The account may be keyed 'account' (what a caller writes by hand) or
     * 'account_number' (what a journal_lines row already calls it), because
     * reverse() feeds stored rows straight back through here. Without the
     * alias every reversal silently normalised to a blank account and was
     * refused — which is what the integration test caught.
     *
     * @return array{account:string, debit_cents:int, credit_cents:int, memo:string}
     */
    public static function normalizeLine(array $line): array
    {
        $debit  = array_key_exists('debit_cents', $line)
            ? (int) $line['debit_cents']
            : Markup::toCents($line['debit'] ?? 0);
        $credit = array_key_exists('credit_cents', $line)
            ? (int) $line['credit_cents']
            : Markup::toCents($line['credit'] ?? 0);

        return [
            'account'      => trim((string) ($line['account'] ?? $line['account_number'] ?? '')),
            'debit_cents'  => $debit,
            'credit_cents' => $credit,
            'memo'         => trim((string) ($line['memo'] ?? '')),
        ];
    }

    /**
     * Everything wrong with a set of lines, in plain language. Empty means it
     * will post. Pure: no database, so the arithmetic is testable on its own.
     *
     * Mirrors the contract of Markup::validate and Accounts::create — a list
     * of human-readable strings, not exceptions, because these are shown to
     * an operator on a form.
     *
     * @param array<int,array<string,mixed>> $lines
     */
    public static function validate(array $lines): array
    {
        $errors = [];
        if (count($lines) < 2) {
            return ['An entry needs at least two lines — one side is not an entry.'];
        }

        $debits = 0;
        $credits = 0;
        foreach (array_values($lines) as $i => $raw) {
            $l   = self::normalizeLine($raw);
            $row = 'Line ' . ($i + 1);

            if ($l['account'] === '') {
                $errors[] = "$row: no account number.";
            } elseif (!preg_match('/^\d{3,8}$/', $l['account'])) {
                $errors[] = "$row: '{$l['account']}' is not an account number.";
            }

            if ($l['debit_cents'] < 0 || $l['credit_cents'] < 0) {
                $errors[] = "$row: amounts cannot be negative — use the other side instead.";
            }
            if ($l['debit_cents'] !== 0 && $l['credit_cents'] !== 0) {
                $errors[] = "$row: carries both a debit and a credit. A line is one side or the other.";
            }
            if ($l['debit_cents'] === 0 && $l['credit_cents'] === 0) {
                $errors[] = "$row: has no amount.";
            }

            $debits  += $l['debit_cents'];
            $credits += $l['credit_cents'];
        }

        if ($debits !== $credits) {
            /* Formatted here rather than through money(): validate() is pure
             * so it can be unit-tested against Domain.php alone, the way
             * Markup::validate is, and money() lives in helpers.php. */
            $errors[] = sprintf(
                'Entry does not balance: debits $%s, credits $%s, off by $%s.',
                Markup::centsToStr($debits),
                Markup::centsToStr($credits),
                Markup::centsToStr(abs($debits - $credits))
            );
        }
        return $errors;
    }

    /** Total of one side, in cents. Both sides are equal in a valid entry. */
    public static function totalCents(array $lines): int
    {
        $t = 0;
        foreach ($lines as $raw) { $t += self::normalizeLine($raw)['debit_cents']; }
        return $t;
    }

    /** "2026-08" from "2026-08-16". The lockable unit. */
    public static function periodKey(string $date): string
    {
        return substr($date, 0, 7);
    }

    /**
     * Flip a set of lines. Debits become credits and credits become debits;
     * amounts and accounts are untouched. This is what makes a reversal exact
     * rather than a re-keyed guess at the original.
     */
    public static function flip(array $lines): array
    {
        $out = [];
        foreach ($lines as $raw) {
            $l = self::normalizeLine($raw);
            $out[] = [
                'account'      => $l['account'],
                'debit_cents'  => $l['credit_cents'],
                'credit_cents' => $l['debit_cents'],
                'memo'         => $l['memo'],
            ];
        }
        return $out;
    }

    /* ---- the write path ------------------------------------------------ */

    /**
     * Post an entry. Returns the new entry id.
     *
     * Refuses anything validate() rejects, so a caller cannot write an
     * unbalanced entry by skipping the check. Header and lines go in one
     * transaction: a header without its lines is worse than no entry at all,
     * because it looks like bookkeeping.
     *
     * @param array<int,array<string,mixed>> $lines
     * @throws RuntimeException when the entry does not balance or a period is closed
     */
    public static function post(
        string $sourceType,
        array $lines,
        string $memo = '',
        ?int $sourceId = null,
        string $sourceRef = '',
        ?string $entryDate = null
    ): int {
        if (!in_array($sourceType, self::SOURCES, true)) {
            throw new RuntimeException("Unknown journal source '$sourceType'.");
        }
        $errors = self::validate($lines);
        if ($errors) {
            throw new RuntimeException('Refusing to post: ' . implode(' ', $errors));
        }

        $date   = $entryDate ?: date('Y-m-d');
        $period = self::periodKey($date);
        if (self::periodIsClosed($period)) {
            throw new RuntimeException(
                "Period $period is closed. Post a current-dated correcting entry instead."
            );
        }

        $accounts = self::accountIndex();
        $user     = Auth::user();

        return (int) Db::tx(static function () use ($lines, $sourceType, $sourceId, $sourceRef, $memo, $date, $period, $accounts, $user) {
            $entryId = Db::insert('journal_entries', [
                'entry_no'       => DocNumber::next('JE'),
                'entry_date'     => $date,
                'period_key'     => $period,
                'source_type'    => $sourceType,
                'source_id'      => $sourceId,
                'source_ref'     => $sourceRef,
                'memo'           => $memo,
                'total_cents'    => Ledger::totalCents($lines),
                'is_reversal'    => $sourceType === 'REV' ? 1 : 0,
                'posted_by_id'   => $user['id'] ?? null,
                'posted_by_name' => $user ? ($user['first_name'] . ' ' . $user['last_name']) : 'system',
                'posted_at'      => now(),
                'created_at'     => now(),
            ]);

            $n = 0;
            foreach ($lines as $raw) {
                $l   = Ledger::normalizeLine($raw);
                $acc = $accounts[$l['account']] ?? null;
                Db::insert('journal_lines', [
                    'entry_id'       => $entryId,
                    'line_no'        => ++$n,
                    'account_number' => $l['account'],
                    'account_name'   => $acc['name'] ?? null,
                    'account_type'   => $acc['account_type'] ?? null,
                    'debit'          => Markup::centsToStr($l['debit_cents']),
                    'credit'         => Markup::centsToStr($l['credit_cents']),
                    'debit_cents'    => $l['debit_cents'],
                    'credit_cents'   => $l['credit_cents'],
                    'memo'           => $l['memo'],
                ]);
            }

            Audit::log('journal_entry', $entryId, 'posted', $sourceType . ' ' . $sourceRef);
            return $entryId;
        });
    }

    /**
     * Reverse a posted entry. Returns the new entry's id.
     *
     * The original is never touched beyond recording which entry reversed it,
     * so both halves stay readable and the pair nets to zero. An entry may be
     * reversed once; reversing a reversal is legal (it re-instates), but
     * reversing the same entry twice is a double correction and is refused.
     */
    public static function reverse(int $entryId, string $reason = ''): int
    {
        /* One transaction around read-check, posting, and both link updates
         * (fixed 2026-08-27: the check was an unlocked read and the links
         * landed after post()'s own commit, so two concurrent voids — or a
         * crash between the writes — could produce a second REV against the
         * same original). The claim is the reversed_by_id stamp itself:
         * written first, guarded on "still unset", exactly the single-use
         * pattern the signing tokens use. Db::tx is re-entrant, so an outer
         * transaction (an invoice void) simply absorbs this one. */
        return Db::tx(function () use ($entryId, $reason): int {
            $entry = Db::one('SELECT * FROM journal_entries WHERE id = ?', [$entryId]);
            if ($entry === null) {
                throw new RuntimeException("Journal entry $entryId does not exist.");
            }
            if (!empty($entry['reversed_by_id'])) {
                throw new RuntimeException(
                    "Entry {$entry['entry_no']} was already reversed by entry #{$entry['reversed_by_id']}."
                );
            }

            $lines = Db::all('SELECT * FROM journal_lines WHERE entry_id = ? ORDER BY line_no', [$entryId]);
            if (!$lines) {
                throw new RuntimeException("Entry {$entry['entry_no']} has no lines to reverse.");
            }

            /* Claim before posting: -1 is a placeholder no real entry id can
             * be, replaced with the REV entry's id below, inside the same
             * transaction. A concurrent reverse() finds the claim and stops. */
            $claimed = Db::q(
                'UPDATE journal_entries SET reversed_by_id = -1 WHERE id = ? AND (reversed_by_id IS NULL OR reversed_by_id = 0)',
                [$entryId]
            );
            if ($claimed->rowCount() !== 1) {
                throw new RuntimeException("Entry {$entry['entry_no']} was already reversed.");
            }

            $memo = 'Reversal of ' . $entry['entry_no'] . ($reason !== '' ? ' — ' . $reason : '');
            $newId = self::post(
                'REV',
                self::flip($lines),
                $memo,
                (int) $entry['id'],
                (string) $entry['entry_no']
            );

            Db::update('journal_entries', $newId,   ['reverses_entry_id' => $entryId]);
            Db::update('journal_entries', $entryId, ['reversed_by_id'    => $newId]);
            Audit::log('journal_entry', $entryId, 'reversed', $reason);
            return $newId;
        });
    }

    /* ---- reading ------------------------------------------------------- */

    /** account_number => the gl_accounts row, for snapshotting names onto lines. */
    private static function accountIndex(): array
    {
        Accounts::ensureSeeded();
        $out = [];
        foreach (Db::all('SELECT * FROM gl_accounts') as $a) {
            $out[$a['account_number']] = $a;
        }
        return $out;
    }

    /**
     * Balance of one account in integer cents, expressed in its NATURAL
     * direction — a positive number always means "more of what this account
     * is". Cash of 500 means five dollars on hand; Accounts Payable of 500
     * means five dollars owed. Callers should not have to remember which
     * types are debit-positive.
     */
    public static function balanceCents(string $account, ?string $throughDate = null): int
    {
        $sql  = 'SELECT COALESCE(SUM(l.debit_cents),0) d, COALESCE(SUM(l.credit_cents),0) c
                 FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id
                 WHERE l.account_number = ?';
        $args = [$account];
        if ($throughDate !== null) { $sql .= ' AND e.entry_date <= ?'; $args[] = $throughDate; }

        $row  = Db::one($sql, $args) ?? ['d' => 0, 'c' => 0];
        $net  = (int) $row['d'] - (int) $row['c'];
        /* Direction comes from the type SNAPSHOTTED on the journal lines —
         * that snapshot exists precisely because gl_accounts is deletable by
         * design, and a deleted credit-positive account (2050, 1100…) must
         * not flip sign in every report just because its live row is gone
         * (fixed 2026-08-27). The live table is only the fallback for an
         * account with no activity, where net is zero anyway. */
        $type = (string) (Db::val(
                'SELECT account_type FROM journal_lines WHERE account_number = ? AND account_type IS NOT NULL LIMIT 1',
                [$account])
            ?? Db::val('SELECT account_type FROM gl_accounts WHERE account_number = ?', [$account])
            ?? '');

        return in_array($type, self::CREDIT_POSITIVE, true) ? -$net : $net;
    }

    /**
     * Every account with activity, with its debit and credit totals. The sum
     * of the two columns must match — that equality is the whole point, and
     * trialBalanceIsSquare() is what a test or an admin page asserts on.
     */
    public static function trialBalance(?string $throughDate = null): array
    {
        $sql  = 'SELECT l.account_number, l.account_name, l.account_type,
                        SUM(l.debit_cents) d, SUM(l.credit_cents) c
                 FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id';
        $args = [];
        if ($throughDate !== null) { $sql .= ' WHERE e.entry_date <= ?'; $args[] = $throughDate; }
        $sql .= ' GROUP BY l.account_number, l.account_name, l.account_type
                  ORDER BY l.account_number';

        return Db::all($sql, $args);
    }

    /** True when total debits equal total credits across the whole ledger. */
    public static function trialBalanceIsSquare(?string $throughDate = null): bool
    {
        $d = 0; $c = 0;
        foreach (self::trialBalance($throughDate) as $r) {
            $d += (int) $r['d'];
            $c += (int) $r['c'];
        }
        return $d === $c;
    }

    /** An entry with its lines attached, for a detail view. */
    public static function entry(int $id): ?array
    {
        $e = Db::one('SELECT * FROM journal_entries WHERE id = ?', [$id]);
        if ($e === null) { return null; }
        $e['lines'] = Db::all('SELECT * FROM journal_lines WHERE entry_id = ? ORDER BY line_no', [$id]);
        return $e;
    }

    /** Every entry raised by one source document, oldest first. */
    public static function forSource(string $sourceType, int $sourceId): array
    {
        return Db::all(
            'SELECT * FROM journal_entries WHERE source_type = ? AND source_id = ? ORDER BY id',
            [$sourceType, $sourceId]
        );
    }

    /* ---- period locking ------------------------------------------------ */

    /**
     * Closed periods, as a list of "YYYY-MM" keys.
     *
     * Stored in settings rather than its own table: it is one short list, it
     * is read on every post, and it is edited by hand a few times a year.
     *
     * Read straight from the table rather than through App::setting, which
     * caches for the life of the request. A lock answered from a stale cache
     * is not a lock — close a period and the very next post in the same
     * request would still be allowed through. Costs one indexed primary-key
     * lookup per post, which is the correct price for the guarantee.
     */
    public static function closedPeriods(): array
    {
        $raw = (string) (Db::val('SELECT svalue FROM settings WHERE skey = ?', ['closed_periods']) ?? '');
        if (trim($raw) === '') { return []; }
        $out = array_filter(array_map('trim', explode(',', $raw)));
        sort($out);
        return array_values($out);
    }

    public static function periodIsClosed(string $periodKey): bool
    {
        return in_array($periodKey, self::closedPeriods(), true);
    }
}

/* ---------------------------------------------------------------------------
 * THE POSTING MATRIX
 *
 * Which accounts each document touches, and in which direction. Ledger owns
 * HOW an entry is written; this owns WHAT to write. One rule per event, each
 * living exactly once — the same discipline as Rules and Markup.
 *
 * Every method here is IDEMPOTENT. It checks whether the source document has
 * already raised an entry and returns the existing id rather than posting a
 * second one. A replayed webhook, a double-clicked button and a retried
 * request must not be able to double the books.
 *
 * Every method is also safe to call INSIDE an existing Db::tx — that is why
 * Db::tx is re-entrant. Posting commits with the document or not at all. A
 * failure to post rolls back the issue/payment that raised it, deliberately:
 * an issued invoice with no entry behind it is the silent hole this whole
 * build exists to close, and it is better to fail loudly at the button.
 *
 * WHAT IS NOT HERE YET. Refunds and credit memos have no implementation to
 * hook — InvoiceController::void tells the operator to "record a refund
 * instead" and no such flow exists. Square settlement (gross to fees and net)
 * needs the transfers report, which is not built. Both are Phase 2 leftovers
 * and are listed in docs/ACCOUNTING_PLAN.md rather than half-written here.
 * ------------------------------------------------------------------------- */
final class Posting
{
    public const AR              = '1100';   // Accounts Receivable
    public const CASH_ON_HAND    = '1000';
    public const CHECKING        = '1010';
    public const SQUARE_CLEARING = '1050';
    public const CARD_PAYABLE    = '2010';   // what the business owes its own card
    public const TAX_PAYABLE     = '2020';
    public const TIPS            = '4300';   // Other Revenue
    public const CUSTOMER_REFUNDS = '2060';  // unverified overpayments held here
    public const EXPENSE_DEFAULT = '6900';   // Other Expenses

    /** Core deposits held. Named so Cores does not repeat the literal. */
    public const CORE_PAYABLE_ACCT = '2050';

    /** Square Capital: the debt, and the fixed fee charged for taking it. */
    public const CAPITAL_LOAN = '2100';
    public const CAPITAL_FEE  = '7030';

    /** Square settlement: what the processor takes, and what it disputes. */
    public const SQUARE_FEES = '7010';
    public const CHARGEBACK  = '7020';

    /**
     * Six years of card sales with no document behind them. Kept out of the
     * four service revenue accounts because nothing knows what was sold —
     * see the account's comment in Accounts::DEFAULTS.
     */
    public const HISTORIC_REVENUE = '4050';

    /** Where a personal charge on the business account lands. */
    public const OWNER_CONTRIB = '3100';

    /* Where a forfeited core lands. 4030 Fees & Surcharges rather than a
     * parts-sales account: the customer bought a part AND separately failed to
     * return a deposit, and those are different events. Keeping forfeitures in
     * their own bucket is also what makes them countable — a rising number
     * here means cores are not coming back, which is an operations problem
     * before it is an accounting one. */
    public const CORE_FORFEIT_REVENUE = '4030';

    /**
     * Revenue account for a line that carries none.
     *
     * A catalog item may legitimately have no account set — the form offers
     * "— none —" — and every line written before the ledger existed has NULL
     * here. Falling back by item type keeps those postable and matches the
     * assignment data/seed.php already uses, rather than dropping the amount
     * or refusing to issue the invoice.
     */
    private const REVENUE_BY_TYPE = [
        'PART'    => '4010',
        'SERVICE' => '4000',
        'FEE'     => '4030',
    ];

    /**
     * Where the money landed, by how it was taken.
     *
     * A card payment does NOT go to Checking. It goes to Square Clearing and
     * sits there until the processor transfers it, minus fees, days later.
     * Debiting Checking on the day of the card swipe would show money that is
     * not in the bank — and would make the account impossible to reconcile
     * against a statement. This is the hinge of the whole payment model.
     */
    public static function cashAccountFor(string $method, ?string $processor = null): string
    {
        return match (strtoupper($method)) {
            'CASH'  => self::CASH_ON_HAND,
            'CARD'  => self::SQUARE_CLEARING,
            default => self::CHECKING,      // CHECK, ACH, PROVIDER remit
        };
    }

    /** Where an expense's money came from, by how it was paid. */
    public static function fundingAccountFor(string $method): string
    {
        return match (strtoupper($method)) {
            'CARD'  => self::CARD_PAYABLE,
            'CASH'  => self::CASH_ON_HAND,
            default => self::CHECKING,      // CHECK, ACH
        };
    }

    public static function revenueAccountFor(array $line): string
    {
        $set = trim((string) ($line['revenue_account'] ?? ''));
        if ($set !== '') { return $set; }
        return self::REVENUE_BY_TYPE[strtoupper((string) ($line['item_type'] ?? ''))] ?? self::REVENUE_BY_TYPE['SERVICE'];
    }

    /**
     * Revenue side of an invoice, netted per account and in integer cents.
     *
     * Signed on purpose. A line whose discount exceeds its own total is a net
     * debit to revenue, and emitting it as a negative credit would be refused
     * by Ledger::validate — correctly, since a negative credit is just a debit
     * wearing the wrong label.
     *
     * @return array<string,int> account => signed cents, positive = credit
     */
    public static function revenueSplit(array $lines): array
    {
        $out = [];
        foreach ($lines as $l) {
            $net = Markup::toCents($l['line_total']) - Markup::toCents($l['discount_amount']);
            if ($net === 0) { continue; }
            $acct = self::revenueAccountFor($l);
            $out[$acct] = ($out[$acct] ?? 0) + $net;
        }
        return array_filter($out, static fn($c) => $c !== 0);
    }

    /* ---- the events ---------------------------------------------------- */

    /**
     * An invoice was issued. Accrual: revenue is earned now, whether or not
     * anybody has paid.
     *
     *   Debit  Accounts Receivable   the whole total
     *   Credit each revenue account  its share of the lines, net of discount
     *   Credit Sales Tax Payable     the tax
     *
     * Returns the entry id, or null when there is nothing to post — a
     * zero-total invoice is legal (a goodwill call-out written off to nothing)
     * and an entry with no amounts is not.
     */
    public static function invoiceIssued(array $inv): ?int
    {
        $invId = (int) $inv['id'];
        if ($existing = Ledger::forSource('INV', $invId)) {
            return (int) $existing[0]['id'];
        }

        $totalCents = Markup::toCents($inv['total']);
        $taxCents   = Markup::toCents($inv['tax_total']);
        $split      = self::revenueSplit(Lines::forDoc('INV', $invId));

        if ($totalCents === 0 && $split === [] && $taxCents === 0) { return null; }

        $lines = [];
        $lines[] = self::side(self::AR, $totalCents, 'Invoice ' . ($inv['doc_number'] ?? ''));
        foreach ($split as $acct => $cents) {
            /* Cast: PHP silently turns a numeric-string array key into an int,
             * so '4010' comes back out of revenueSplit as 4010. Every account
             * number in this system is text — the number is the identity and
             * leading zeros would matter for a 3-digit code. */
            $lines[] = self::side((string) $acct, -$cents, 'Revenue');
        }
        if ($taxCents !== 0) {
            $lines[] = self::side(self::TAX_PAYABLE, -$taxCents, 'Sales tax collected');
        }
        $lines = array_values(array_filter($lines));

        return Ledger::post(
            'INV', $lines,
            'Invoice ' . ($inv['doc_number'] ?? $invId) . ' issued',
            $invId, (string) ($inv['doc_number'] ?? '')
        );
    }

    /**
     * An invoice was voided. Reverses whatever it posted; both entries stay on
     * the books and the pair nets to zero, so the correction is visible rather
     * than tidied away.
     *
     * Null when the invoice never posted — voiding a draft, or any invoice
     * predating the ledger, is not an error.
     */
    public static function invoiceVoided(array $inv, string $reason = ''): ?int
    {
        $entries = Ledger::forSource('INV', (int) $inv['id']);
        if (!$entries) { return null; }

        $entry = $entries[0];
        if (!empty($entry['reversed_by_id'])) { return (int) $entry['reversed_by_id']; }

        return Ledger::reverse((int) $entry['id'], $reason !== '' ? $reason : 'invoice voided');
    }

    /**
     * A payment was recorded.
     *
     *   Debit  cash / clearing        what was taken, tip included
     *   Credit Accounts Receivable    the part that settles the invoice
     *   Credit Other Revenue          the tip, which settles nothing
     *
     * A tip is not part of the invoice total and must not reduce the balance
     * due, so it cannot be credited to Accounts Receivable — doing so would
     * mark an invoice paid that is not.
     */
    public static function paymentRecorded(array $payment, ?array $inv = null): ?int
    {
        $payId = (int) $payment['id'];
        if ($existing = Ledger::forSource('PAY', $payId)) {
            return (int) $existing[0]['id'];
        }

        $amountCents = Markup::toCents($payment['amount']);
        $tipCents    = Markup::toCents($payment['tip_amount'] ?? 0);
        $overCents   = Markup::toCents($payment['overpayment_amount'] ?? 0);
        if ($amountCents === 0 && $tipCents === 0 && $overCents === 0) { return null; }

        $cash = self::cashAccountFor((string) $payment['method'], $payment['processor'] ?? null);

        $lines = [self::side($cash, $amountCents + $tipCents + $overCents, 'Payment received')];
        if ($amountCents !== 0) {
            $lines[] = self::side(self::AR, -$amountCents, 'Applied to invoice');
        }
        if ($tipCents !== 0) {
            $lines[] = self::side(self::TIPS, -$tipCents, 'Tip');
        }
        /* Extra money that arrived without a tip label is a LIABILITY until a
         * person says otherwise — it may be a "keep the change", it may be a
         * fat-fingered amount the customer is owed back. It sits on 2060 and
         * is resolved by overpaymentTip() or overpaymentRefunded(), never by
         * guessing it into income at record time (2026-08-27). */
        if ($overCents !== 0) {
            $lines[] = self::side(self::CUSTOMER_REFUNDS, -$overCents, 'Unverified overpayment — held');
        }

        return Ledger::post(
            'PAY', array_values(array_filter($lines)),
            'Payment ' . ($payment['doc_number'] ?? $payId)
                . ($inv ? ' on invoice ' . $inv['doc_number'] : ''),
            $payId, (string) ($payment['doc_number'] ?? '')
        );
    }

    /**
     * A held overpayment turned out to be a tip: move it off the refund
     * liability and into tip revenue. The caller (resolveOverpayment) claims
     * the HELD status inside the same transaction, which is what makes this
     * single-fire — two admins clicking at once cannot post it twice.
     */
    public static function overpaymentTip(array $payment): ?int
    {
        $cents = Markup::toCents($payment['overpayment_amount'] ?? 0);
        if ($cents <= 0) { return null; }
        return Ledger::post('ADJ', [
            self::side(self::CUSTOMER_REFUNDS,  $cents, 'Overpayment confirmed as tip'),
            self::side(self::TIPS,             -$cents, 'Tip'),
        ], 'Overpayment on ' . ($payment['doc_number'] ?? ('payment ' . $payment['id'])) . ' confirmed as tip',
           (int) $payment['id'], (string) ($payment['doc_number'] ?? ''));
    }

    /**
     * A held overpayment is being paid back. Money goes out the way it came
     * in — the cash account is the one the original payment landed on.
     */
    public static function overpaymentRefunded(array $payment): ?int
    {
        $cents = Markup::toCents($payment['overpayment_amount'] ?? 0);
        if ($cents <= 0) { return null; }
        $cash = self::cashAccountFor((string) $payment['method'], $payment['processor'] ?? null);
        return Ledger::post('ADJ', [
            self::side(self::CUSTOMER_REFUNDS,  $cents, 'Overpayment refunded to customer'),
            self::side($cash,                  -$cents, 'Refund paid out'),
        ], 'Overpayment on ' . ($payment['doc_number'] ?? ('payment ' . $payment['id'])) . ' refunded',
           (int) $payment['id'], (string) ($payment['doc_number'] ?? ''));
    }

    /**
     * An expense was recorded.
     *
     *   Debit  the expense or COGS account   what it cost
     *   Credit cash, checking or the card    where it came from
     *
     * tax_amount is folded into the debit rather than posted separately: tax
     * PAID on a purchase is part of the cost of the thing bought, not the
     * Sales Tax Payable liability, which is tax COLLECTED and owed onward.
     * Confusing the two is a common and expensive mistake. Oregon charges no
     * sales tax, so this is normally zero regardless.
     */
    public static function expenseRecorded(array $expense): ?int
    {
        $expId = (int) $expense['id'];
        if ($existing = Ledger::forSource('EXP', $expId)) {
            return (int) $existing[0]['id'];
        }

        $cents = Markup::toCents($expense['amount']) + Markup::toCents($expense['tax_amount'] ?? 0);
        if ($cents === 0) { return null; }

        $account = trim((string) ($expense['account_code'] ?? '')) ?: self::EXPENSE_DEFAULT;
        $funding = self::fundingAccountFor((string) ($expense['payment_method'] ?? ''));

        return Ledger::post(
            'EXP',
            [
                self::side($account, $cents, (string) ($expense['description'] ?? '')),
                self::side($funding, -$cents, (string) ($expense['vendor_name'] ?? '')),
            ],
            'Expense ' . ($expense['doc_number'] ?? $expId)
                . (($expense['vendor_name'] ?? '') !== '' ? ' — ' . $expense['vendor_name'] : ''),
            $expId, (string) ($expense['doc_number'] ?? '')
        );
    }

    /**
     * A Square Capital advance, brought onto the books.
     *
     * NOT an interest-bearing loan. Square advances a sum, charges ONE FIXED
     * FEE, and takes repayment as a share of card sales until the total is
     * met. There is no amortisation schedule and no interest to accrue.
     *
     * TWO ENTRIES PER ADVANCE, and the reason is a finding rather than a
     * preference. Square's payout entries itemise only the repayments taken
     * out of card settlements. Reconciliation across six years showed
     * $6,052.87 of repayment that never appears there — the 60-day minimum,
     * payments taken straight off the Square balance, and manual payments made
     * from the dashboard. 2022 matched the payout entries to the cent while
     * later years fell short, which rules out missing historical data.
     *
     * So posting from payout entries alone would leave account 2100 claiming a
     * debt roughly $6,000 larger than Square says is outstanding. The LOAN is
     * the authority; the entries are supporting detail for the slice they
     * cover. Each advance therefore posts as:
     *
     *   ORIGINATION   Dr 1010 Checking       the amount advanced
     *                 Dr 7030 Financing      the whole fee
     *                 Cr 2100 Capital Loan   the total owed
     *
     *   REPAYMENT     Dr 2100 Capital Loan   everything repaid to date
     *                 Cr 1010 Checking       the same
     *
     * which leaves 2100 holding exactly the balance the dashboard shows,
     * by construction rather than by hope.
     *
     * The fee is expensed at origination rather than spread across the
     * repayments. Chosen deliberately: these advances run 8–12 months and the
     * fees are $82–$889, so the timing difference is immaterial, and one entry
     * beats a rounding decision on each of 1,643 repayments.
     *
     * @return array{origination:?int, repayment:?int}
     */
    public static function capitalAdvance(array $loan): array
    {
        $planId = (string) $loan['plan_id'];
        $out    = ['origination' => null, 'repayment' => null];

        if ($existing = Ledger::forSource('ADJ', (int) $loan['id'])) {
            foreach ($existing as $e) {
                if (str_contains((string) $e['memo'], 'advance')) { $out['origination'] = (int) $e['id']; }
                if (str_contains((string) $e['memo'], 'repaid'))  { $out['repayment']   = (int) $e['id']; }
            }
            if ($out['origination'] !== null) { return $out; }
        }

        $advanced = Markup::toCents($loan['loan_amount']);
        $fee      = Markup::toCents($loan['loan_fee']);
        $owed     = Markup::toCents($loan['total_owed']);
        $balance  = Markup::toCents($loan['balance']);
        $repaid   = $owed - $balance;

        if ($owed === 0) { return $out; }

        /* The fee must be exactly the difference, or the entry will not
         * balance — and a rounding slip here lands in a deductible expense. */
        if ($advanced + $fee !== $owed) {
            throw new RuntimeException(
                "Advance $planId does not add up: advanced + fee != total owed."
            );
        }

        $out['origination'] = Ledger::post('ADJ', [
            self::side(self::CHECKING, $advanced, 'Advance received'),
            self::side(self::CAPITAL_FEE, $fee, 'Fixed fee on the advance'),
            self::side(self::CAPITAL_LOAN, -$owed, 'Total owed to Square Capital'),
        ], 'Square Capital advance ' . $planId, (int) $loan['id'], $planId);

        if ($repaid > 0) {
            $out['repayment'] = Ledger::post('ADJ', [
                self::side(self::CAPITAL_LOAN, $repaid, 'Repaid from card sales and direct payments'),
                self::side(self::CHECKING, -$repaid, 'Repayments'),
            ], 'Square Capital repaid to date ' . $planId, (int) $loan['id'], $planId);
        }

        Audit::log('square_loan', (int) $loan['id'], 'posted',
            $planId . ' advance ' . money($loan['total_owed']) . ', repaid ' . money(Markup::centsToStr($repaid)));

        return $out;
    }

    /* ---- Square settlement --------------------------------------------
     *
     * THE CLEARING ACCOUNT IS THE WHOLE POINT. A card sale does not put money
     * in the bank; it puts money at Square. Square then takes its fee, takes
     * any loan repayment it is owed, and days later transfers what is left.
     * 1050 holds that money in between, and the test of whether this engine is
     * right is simple and total: post every row and 1050 must land on ZERO.
     *
     * That is provable in advance, and was proved before any of this was
     * written. Across 1,960 payouts the payout entries sum to $153,923.48 and
     * the payout headers sum to the same figure to the cent. So the entries
     * account for every movement and nothing else has to be invented.
     *
     * WHAT IS AUTHORITATIVE FOR WHAT. The payout entry is authoritative for
     * the MONEY — it is Square's own statement of what moved and when. The
     * payment row is authoritative for whether the money is REVENUE, because
     * only it carries the classification a human made. Payout entries have no
     * such flag, so posting revenue from them alone would put personal
     * spending into business income on an account known to hold both. Every
     * charge therefore reaches its payment row first, and an UNREVIEWED
     * payment posts nothing at all.
     *
     * PRE-HISTORY. Square's payout entries begin 2020-12-30, and everything
     * earlier settled to the bank leaving no payout record the API will admit
     * to. That was going to force a single invented aggregate for the year —
     * until the dashboard CSV export turned out to carry Deposit ID and
     * Deposit Date on every row, which is the same fact by another route.
     * data/square_import_deposits.php rebuilds those payouts into the mirror,
     * and from here they are indistinguishable from fetched ones. There is
     * deliberately no special case for them in any rule below.
     */

    /** entry_type => the account facing 1050. CHARGE and REFUND are special. */
    private const SQUARE_DEDUCTION_ACCOUNTS = [
        'SQUARE_CAPITAL_PAYMENT'          => self::CAPITAL_LOAN,
        'SQUARE_CAPITAL_REVERSED_PAYMENT' => self::CAPITAL_LOAN,
        'CREDIT_CARD_REPAYMENT'           => self::CARD_PAYABLE,
        'CREDIT_CARD_REPAYMENT_REVERSED'  => self::CARD_PAYABLE,
        'DISPUTE'                         => self::CHARGEBACK,
        'OPEN_DISPUTE'                    => self::CHARGEBACK,
        /* NOT a bank movement, despite the name. The four RETURNED_PAYOUT rows
         * sit inside PAID payouts and carry the components of the two FAILED
         * payouts — a payout attempt that a dispute pulled negative, re-issued
         * piece by piece into later payouts. Facing them to Checking makes
         * them cancel the negative payout headers exactly: the headers total
         * -$171.45 and these total -$171.45, so both accounts net to zero and
         * a failed-then-reissued payout leaves no trace beyond the successful
         * payouts already counted. ADJUSTMENT behaves the same way and its 14
         * rows sum to exactly $0.00. */
        'RETURNED_PAYOUT'                 => self::CHECKING,
        'ADJUSTMENT'                      => self::CHECKING,
    ];

    /**
     * Where a mirrored Square charge credits, by what a human decided it was.
     *
     * Null means "not decided yet, post nothing". That null is the safeguard
     * the whole import was built around and it must stay a refusal, never a
     * default — a guess here is a tax misstatement, not an untidy report.
     */
    public static function squareRevenueAccount(string $classification): ?string
    {
        return match (strtoupper(trim($classification))) {
            'BUSINESS'          => self::HISTORIC_REVENUE,
            /* Personal money arriving in a business account is the owner
             * putting money in, whatever the card thought it was buying.
             * TRANSFER lands here too: it is the owner moving their own funds,
             * which is the same event wearing a different label. */
            'PERSONAL',
            'TRANSFER'          => self::OWNER_CONTRIB,
            default             => null,
        };
    }

    /**
     * A card sale that settled through a payout.
     *
     *   Debit  1050 Square Clearing   the net that reached the Square balance
     *   Debit  7010 Square Fees       what Square kept
     *   Credit revenue                the gross the customer was charged
     *
     * Dated at the SALE, not the settlement: the books are accrual and the
     * money was earned when the card was taken. The clearing account is
     * exactly what carries the days between that and the bank.
     *
     * @param array $entry   a square_payout_entries row of type CHARGE
     * @param array $payment the square_transactions PAYMENT row it points at
     */
    public static function squareCharge(array $entry, array $payment): ?int
    {
        $entryId = (int) $entry['id'];
        if (!empty($entry['posted_entry_id'])) { return (int) $entry['posted_entry_id']; }
        if ($existing = Ledger::forSource('SQCHG', $entryId)) { return (int) $existing[0]['id']; }

        $revenue = self::squareRevenueAccount((string) ($payment['classification'] ?? ''));
        if ($revenue === null) { return null; }

        $gross = Markup::toCents($entry['gross_amount']);
        $fee   = Markup::toCents($entry['fee_amount']);
        $net   = Markup::toCents($entry['net_amount']);
        if ($gross === 0 && $fee === 0 && $net === 0) { return null; }

        /* Square's own arithmetic, checked rather than trusted. It holds on all
         * 1,948 rows in this account; if it ever does not, the entry would post
         * unbalanced and Ledger::validate would refuse it with a message about
         * cents that would send someone hunting in the wrong place. */
        if ($gross !== $net + $fee) {
            throw new RuntimeException(
                'Square charge ' . $entry['square_entry_id'] . ' does not add up: '
                . 'net + fee != gross.'
            );
        }

        $date = substr((string) ($payment['occurred_at'] ?: $entry['effective_at']), 0, 10);
        $ref  = (string) ($payment['square_id'] ?? $entry['related_square_id']);

        /* A zero fee is legal — Square waived it on some rows — and side()
         * returns null for a zero amount, which validate() would then reject
         * as a line with no account. Filtered here, as invoiceIssued does. */
        $lines = array_values(array_filter([
            self::side(self::SQUARE_CLEARING, $net, 'Net to Square balance'),
            self::side(self::SQUARE_FEES, $fee, 'Square processing fee'),
            self::side($revenue, -$gross, 'Card sale'),
        ]));

        return (int) Db::tx(static function () use ($entry, $entryId, $lines, $date, $ref) {
            $id = Ledger::post('SQCHG', $lines, 'Square card sale ' . $ref, $entryId, $ref, $date);

            Db::update('square_payout_entries', $entryId, ['posted_entry_id' => $id]);
            if (!empty($entry['related_square_id'])) {
                Db::q('UPDATE square_transactions SET posted_entry_id = ? WHERE square_id = ?',
                    [$id, $entry['related_square_id']]);
            }
            return $id;
        });
    }

    /**
     * A refund taken back out of the Square balance.
     *
     *   Debit  revenue              the gross returned to the customer
     *   Credit 7010 Square Fees     the processing fee Square gave back, if any
     *   Credit 1050 Square Clearing the net that left the balance
     *
     * Square returned the fee on the first three of these and kept it on the
     * rest, which is why the fee leg is conditional rather than assumed.
     */
    public static function squareRefund(array $entry): ?int
    {
        $entryId = (int) $entry['id'];
        if (!empty($entry['posted_entry_id'])) { return (int) $entry['posted_entry_id']; }
        if ($existing = Ledger::forSource('SQDED', $entryId)) { return (int) $existing[0]['id']; }

        /* Signs arrive negative from Square — a refund is a negative charge.
         * Flipped once, here, so the lines below read the way they post. */
        $gross = -Markup::toCents($entry['gross_amount']);
        $fee   = -Markup::toCents($entry['fee_amount']);
        $net   = -Markup::toCents($entry['net_amount']);
        if ($gross === 0 && $net === 0) { return null; }

        if ($gross !== $net + $fee) {
            throw new RuntimeException(
                'Square refund ' . $entry['square_entry_id'] . ' does not add up.'
            );
        }

        $date = substr((string) $entry['effective_at'], 0, 10);
        $ref  = (string) $entry['square_entry_id'];

        $lines = array_values(array_filter([
            self::side(self::HISTORIC_REVENUE, $gross, 'Refunded to customer'),
            self::side(self::SQUARE_FEES, -$fee, 'Processing fee returned'),
            self::side(self::SQUARE_CLEARING, -$net, 'Out of the Square balance'),
        ]));

        return (int) Db::tx(static function () use ($entryId, $lines, $date, $ref) {
            $id = Ledger::post('SQDED', $lines, 'Square refund ' . $ref, $entryId, $ref, $date);
            Db::update('square_payout_entries', $entryId, ['posted_entry_id' => $id]);
            return $id;
        });
    }

    /**
     * Anything else Square took out of, or put into, the balance before paying
     * out: loan repayments, credit card repayments, disputes, and the internal
     * re-issue rows. Two lines — 1050 and whatever faces it.
     *
     * The sign comes straight from Square. A negative net is money leaving the
     * balance, which credits 1050 and debits the other account; a positive net
     * is the reverse. side() turns that into the correct pair without any
     * caller having to remember the convention.
     */
    public static function squareDeduction(array $entry): ?int
    {
        $type = strtoupper(trim((string) $entry['entry_type']));
        if ($type === 'CHARGE') {
            throw new RuntimeException('A CHARGE is not a deduction — use squareCharge().');
        }
        if ($type === 'REFUND') { return self::squareRefund($entry); }

        $facing = self::SQUARE_DEDUCTION_ACCOUNTS[$type] ?? null;
        if ($facing === null) {
            throw new RuntimeException("No posting rule for Square entry type '$type'.");
        }

        $entryId = (int) $entry['id'];
        if (!empty($entry['posted_entry_id'])) { return (int) $entry['posted_entry_id']; }
        if ($existing = Ledger::forSource('SQDED', $entryId)) { return (int) $existing[0]['id']; }

        $net = Markup::toCents($entry['net_amount']);
        if ($net === 0) { return null; }   // the paired ADJUSTMENT rows

        $date  = substr((string) $entry['effective_at'], 0, 10);
        $ref   = (string) $entry['square_entry_id'];
        $label = self::squareEntryLabel($type);

        $lines = array_values(array_filter([
            self::side(self::SQUARE_CLEARING, $net, $label),
            self::side($facing, -$net, $label),
        ]));

        return (int) Db::tx(static function () use ($entryId, $lines, $date, $ref, $label) {
            $id = Ledger::post('SQDED', $lines, 'Square ' . $label . ' ' . $ref, $entryId, $ref, $date);
            Db::update('square_payout_entries', $entryId, ['posted_entry_id' => $id]);
            return $id;
        });
    }

    /** Plain language for an entry type, used in the memo an operator reads. */
    public static function squareEntryLabel(string $type): string
    {
        return match (strtoupper(trim($type))) {
            'CHARGE'                          => 'card sale',
            'REFUND'                          => 'refund',
            'SQUARE_CAPITAL_PAYMENT'          => 'Capital repayment',
            'SQUARE_CAPITAL_REVERSED_PAYMENT' => 'Capital repayment reversed',
            'CREDIT_CARD_REPAYMENT'           => 'card repayment',
            'CREDIT_CARD_REPAYMENT_REVERSED'  => 'card repayment reversed',
            'DISPUTE'                         => 'dispute resolved',
            'OPEN_DISPUTE'                    => 'dispute held',
            'RETURNED_PAYOUT'                 => 'payout re-issued',
            'ADJUSTMENT'                      => 'balance adjustment',
            default                           => strtolower(str_replace('_', ' ', $type)),
        };
    }

    /**
     * The transfer itself: what Square actually sent to the bank.
     *
     *   Debit  1010 Checking          the net that landed
     *   Credit 1050 Square Clearing   the same
     *
     * A FAILED payout carries a negative amount and posts the other way round,
     * which is correct — it is the reversal of an attempt, and its components
     * come back as RETURNED_PAYOUT rows in later payouts.
     *
     * @param array $payout a square_transactions row of object_type PAYOUT
     */
    public static function squarePayout(array $payout): ?int
    {
        $rowId = (int) $payout['id'];
        if (!empty($payout['posted_entry_id'])) { return (int) $payout['posted_entry_id']; }
        if ($existing = Ledger::forSource('SQPAY', $rowId)) { return (int) $existing[0]['id']; }

        $amount = Markup::toCents($payout['amount']);
        if ($amount === 0) { return null; }

        $date = substr((string) $payout['occurred_at'], 0, 10);
        $ref  = (string) $payout['square_id'];

        $lines = array_values(array_filter([
            self::side(self::CHECKING, $amount, 'Square payout to bank'),
            self::side(self::SQUARE_CLEARING, -$amount, 'Released from Square'),
        ]));

        return (int) Db::tx(static function () use ($rowId, $lines, $date, $ref) {
            $id = Ledger::post('SQPAY', $lines, 'Square payout ' . $ref, $rowId, $ref, $date);
            Db::update('square_transactions', $rowId, ['posted_entry_id' => $id]);
            return $id;
        });
    }

    /**
     * A payment recorded in Square that never moved THROUGH Square — cash
     * taken on the roadside, or an external tender keyed in afterwards. Zero
     * fee, no payout, no settlement, and it must never touch 1050.
     *
     *   Debit  1000 Cash on hand  (CASH)   or 1010 Checking (EXTERNAL)
     *   Credit revenue                     the amount
     */
    public static function squareUnsettled(array $payment): ?int
    {
        $rowId = (int) $payment['id'];
        if (!empty($payment['posted_entry_id'])) { return (int) $payment['posted_entry_id']; }
        if ($existing = Ledger::forSource('SQCHG', -$rowId)) { return (int) $existing[0]['id']; }

        $revenue = self::squareRevenueAccount((string) ($payment['classification'] ?? ''));
        if ($revenue === null) { return null; }

        $amount = Markup::toCents($payment['amount']);
        if ($amount === 0) { return null; }

        $source = strtoupper(trim((string) ($payment['source_type'] ?? '')));
        $cash   = $source === 'CASH' ? self::CASH_ON_HAND : self::CHECKING;
        $date   = substr((string) $payment['occurred_at'], 0, 10);
        $ref    = (string) $payment['square_id'];

        /* Negated source id. These share the SQCHG source with settled charges
         * but are keyed on square_transactions rather than square_payout_entries,
         * and the two tables number from 1 independently — without the sign a
         * payment row would claim to be already posted because a charge entry
         * with the same id was. */
        $lines = array_values(array_filter([
            self::side($cash, $amount, $source === 'CASH' ? 'Cash taken' : 'Paid outside Square'),
            self::side($revenue, -$amount, 'Sale'),
        ]));

        return (int) Db::tx(static function () use ($rowId, $lines, $date, $ref, $source) {
            $id = Ledger::post('SQCHG', $lines,
                'Square ' . strtolower($source) . ' payment ' . $ref, -$rowId, $ref, $date);
            Db::update('square_transactions', $rowId, ['posted_entry_id' => $id]);
            return $id;
        });
    }

    /**
     * Correct the Square Capital repayment entries so they can coexist with
     * settlement postings.
     *
     * WHAT WAS WRONG, AND WHY IT WAS NOT WRONG AT THE TIME. capitalAdvance()
     * posts each advance's repayment as Dr 2100 / Cr 1010 Checking. When it was
     * written that was the only defensible reading: the loan statement was the
     * authority, nothing else was on the books, and where the money physically
     * came from could not be told apart.
     *
     * It becomes wrong the moment payouts post. Square deducts a repayment from
     * the BALANCE before transferring what is left, so the payout figure landing
     * in Checking is already net of it. Credit Checking again for the same
     * repayment and the bank account is reduced twice.
     *
     * The split is not a guess. Repayments visible in payout entries total
     * $26,751.34; the loan statements say $32,804.21 was repaid in all. The
     * $6,052.87 difference is the gap capitalAdvance() documented and could not
     * explain — the 60-day minimum, repayments taken straight off the Square
     * balance, and manual payments from the dashboard. So:
     *
     *   through payouts   Dr 2100 / Cr 1050   posted per payout entry
     *   everything else   Dr 2100 / Cr 1010   what really did leave the bank
     *
     * Account 2100 ends on exactly the balance it held before. Nothing about
     * the debt changes; only where the money came from.
     *
     * The old entries are REVERSED, not edited — both halves stay on the books
     * and the pair nets to zero, which is the same rule every other correction
     * in this system follows.
     *
     * @return array{reversed:int, residual:?int, residual_cents:int}
     */
    public static function capitalRepaymentCorrection(int $throughPayoutsCents): array
    {
        $out = ['reversed' => 0, 'residual' => null, 'residual_cents' => 0];

        /* The repayment entries, told from the origination entries by their
         * memo — the same discriminator capitalAdvance() already relies on. */
        $entries = Db::all(
            "SELECT * FROM journal_entries
             WHERE source_type = 'ADJ' AND reversed_by_id IS NULL AND is_reversal = 0
             ORDER BY id"
        );

        $repaidTotal = 0;
        foreach ($entries as $e) {
            /* Exact prefix from capitalAdvance(), not a bare substring — a
             * manual adjustment that happens to say "repaid" in its memo must
             * never be swept up and reversed by a re-run (2026-08-27). */
            if (!str_starts_with((string) $e['memo'], 'Square Capital repaid to date')) { continue; }
            $repaidTotal += (int) $e['total_cents'];
            Ledger::reverse((int) $e['id'],
                'repayment re-posted against Square Clearing — see capitalRepaymentCorrection');
            $out['reversed']++;
        }

        if ($out['reversed'] === 0) { return $out; }

        /* Whatever the loan statements say was repaid but the payout entries
         * never showed. Posted against Checking because that is the only place
         * left it could have come from. */
        $residual = $repaidTotal - $throughPayoutsCents;
        $out['residual_cents'] = $residual;

        if ($residual !== 0) {
            $lines = array_values(array_filter([
                self::side(self::CAPITAL_LOAN, $residual, 'Repaid outside the payout stream'),
                self::side(self::CHECKING, -$residual, 'Direct and minimum payments'),
            ]));
            $out['residual'] = Ledger::post('ADJ', $lines,
                'Square Capital repaid outside payouts — 60-day minimums, balance and manual payments',
                0, 'SQ-CAPITAL-RESIDUAL');
        }

        Audit::log('system', 0, 'square:capital-corrected',
            $out['reversed'] . ' repayment entries reversed, residual '
            . money(Markup::centsToStr($residual)) . ' re-posted to Checking');

        return $out;
    }

    /**
     * One line, from a signed amount: positive debits, negative credits.
     *
     * Keeping the sign convention in a single helper is what lets the callers
     * above net amounts with ordinary arithmetic and stay readable, without
     * any of them being able to emit the negative-credit line that
     * Ledger::validate would reject.
     */
    private static function side(string $account, int $signedCents, string $memo = ''): ?array
    {
        if ($signedCents === 0) { return null; }
        return $signedCents > 0
            ? ['account' => $account, 'debit_cents'  => $signedCents,  'memo' => $memo]
            : ['account' => $account, 'credit_cents' => -$signedCents, 'memo' => $memo];
    }
}

/* ---------------------------------------------------------------------------
 * BANK DESCRIPTORS
 *
 * Turning "SQ *NAPA AUTO PARTS #4471 MEDFORD OR 08/14" into "NAPA AUTO PARTS",
 * which is the only thing a rule can usefully match on.
 *
 * WHY THIS IS HARDER THAN IT LOOKS. Legacy banking systems cap the descriptor
 * at roughly 20–25 characters, so merchant name, location and reference are
 * compressed into one field and then truncated — often mid-word. Payment
 * intermediaries prepend their own identifiers, so the merchant a payment ran
 * THROUGH hides the merchant it was WITH: an oil filter bought through Square
 * arrives as "SQ *" and the actual vendor follows. Store numbers, city, state
 * and dates trail the name in no fixed order.
 *
 * THE OUTPUT IS A MATCH KEY, NOT A CORRECTED NAME. It is not shown to anyone
 * and it is not stored in place of the original. The raw descriptor is evidence
 * — it is what an auditor compares against a statement — and these rules will
 * sometimes eat something they should not: a merchant whose name genuinely ends
 * in a number, a city that looks like a reference. When that happens the
 * original has to still be there. So both columns exist and only one of them
 * is ever authoritative.
 *
 * EVERYTHING HERE IS PURE. No database, no configuration, no clock. That is
 * what lets the whole set be tested against real descriptor shapes without an
 * import, and it is why the noise lists are constants rather than settings —
 * a normaliser an operator can edit is a normaliser whose output changes
 * underneath the match keys already stored.
 * ------------------------------------------------------------------------- */
final class Descriptor
{
    /**
     * Payment intermediaries that prepend themselves to the real merchant.
     *
     * Stripped rather than matched: a rule on "SQ *" would file every Square
     * purchase to one account regardless of what was bought, which is precisely
     * the mistake this list exists to prevent.
     */
    public const PROCESSOR_PREFIXES = [
        'SQ *', 'SQ*', 'TST*', 'TST *', 'PAYPAL *', 'PAYPAL*', 'PP*', 'PP *',
        'SP *', 'SP*', 'SQC*', 'IC*', 'WPY*', 'CKO*', 'EIG*', 'DD *', 'DD*',
        'AMZN MKTP', 'AMZ*', 'TOAST*', 'CLOVER*', 'SUMUP *', 'STRIPE*',
        'VENMO *', 'CASH APP*', 'ZEL*',
    ];

    /**
     * Bank bookkeeping words that describe the MOVEMENT, not the merchant.
     *
     * "POS DEBIT NAPA" and "NAPA" are the same purchase, and a rule set that
     * had to know which phrasing a given bank used would be a rule set that
     * broke on changing banks.
     */
    public const NOISE_WORDS = [
        'POS DEBIT', 'POS PURCHASE', 'DEBIT CARD PURCHASE', 'CARD PURCHASE',
        'PURCHASE AUTHORIZED ON', 'RECURRING PAYMENT', 'CHECKCARD', 'CHECK CARD',
        'ACH DEBIT', 'ACH CREDIT', 'ELECTRONIC PAYMENT', 'ONLINE PAYMENT',
        'PREAUTHORIZED', 'WITHDRAWAL', 'DEBIT FOR', 'PAYMENT TO', 'PMT',
        'MERCHANT PURCHASE', 'VISA PURCHASE', 'MASTERCARD PURCHASE',
    ];

    /** US state codes, so a trailing "MEDFORD OR" can be recognised as a place. */
    private const STATES = [
        'AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA','HI','ID','IL','IN','IA',
        'KS','KY','LA','ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ',
        'NM','NY','NC','ND','OH','OK','OR','PA','RI','SC','SD','TN','TX','UT','VT',
        'VA','WA','WV','WI','WY','DC',
    ];

    /**
     * A raw bank descriptor to the key the rules match against.
     *
     * Order matters and is not arbitrary. Prefixes come off before noise words,
     * because "SQ *POS DEBIT" exists; trailing location comes off before
     * trailing digits, because a state code protects the city word in front of
     * it from being read as a reference.
     */
    public static function normalize(string $raw): string
    {
        $s = strtoupper(trim($raw));
        if ($s === '') { return ''; }

        /* Collapse separators first so every later rule sees single spaces and
         * none of them has to spell out \s+ again. */
        $s = str_replace(["\t", "\r", "\n"], ' ', $s);
        $s = (string) preg_replace('/\s+/', ' ', $s);

        foreach (self::PROCESSOR_PREFIXES as $p) {
            if (str_starts_with($s, $p)) {
                $s = trim(substr($s, strlen($p)));
                break;   // one processor, not a chain
            }
        }

        foreach (self::NOISE_WORDS as $w) {
            if (str_starts_with($s, $w . ' ')) { $s = trim(substr($s, strlen($w))); }
        }

        $s = self::stripTrailingDate($s);
        $s = self::stripTrailingLocation($s);
        $s = self::stripStoreNumber($s);
        $s = self::stripTrailingReference($s);

        /* Punctuation last: it is load-bearing for the rules above ("SQ *",
         * "#4471", "08/14") and only becomes noise once they have run. */
        $s = (string) preg_replace('/[^A-Z0-9 &\'-]+/', ' ', $s);
        $s = (string) preg_replace('/\s+/', ' ', $s);

        return trim($s);
    }

    /** "NAPA 08/14" and "NAPA 08/14/25" — the posting date, repeated. */
    private static function stripTrailingDate(string $s): string
    {
        return trim((string) preg_replace('#\s+\d{1,2}/\d{1,2}(/\d{2,4})?$#', '', $s));
    }

    /**
     * "NAPA AUTO PARTS MEDFORD OR" to "NAPA AUTO PARTS".
     *
     * Only fires when the last token is a real state code, so "TOOLS AND" keeps
     * its "AND" — a two-letter word that happens to look like a state is the
     * obvious way to get this wrong.
     */
    private static function stripTrailingLocation(string $s): string
    {
        $parts = explode(' ', $s);
        $n     = count($parts);
        if ($n < 3) { return $s; }

        if (!in_array($parts[$n - 1], self::STATES, true)) { return $s; }
        array_pop($parts);

        /* The city in front of it, if what remains still names something. Left
         * alone when stripping it would take the descriptor down to one word,
         * because at that point the "city" is probably the merchant. */
        if (count($parts) >= 3) { array_pop($parts); }

        return trim(implode(' ', $parts));
    }

    /** "MOTEL FIVE EXPRSS #34" and "STORE 0417" to the name in front of it. */
    private static function stripStoreNumber(string $s): string
    {
        $s = (string) preg_replace('/\s+#\s?\d+\b/', ' ', $s);
        $s = (string) preg_replace('/\s+(STORE|STR|LOC|UNIT)\s+\d+\b/', ' ', $s);
        return trim((string) preg_replace('/\s+/', ' ', $s));
    }

    /**
     * A long digit run at the end is an authorisation or reference number.
     *
     * Six or more, and only when something is left in front of it. Shorter runs
     * are left alone on purpose: "76" is a petrol station and "4 WHEEL PARTS"
     * starts with a number, and losing either would be worse than carrying a
     * reference through into a match key that no rule was going to match.
     */
    private static function stripTrailingReference(string $s): string
    {
        $out = (string) preg_replace('/\s+[A-Z]?\d{6,}$/', '', $s);
        return trim($out) === '' ? $s : trim($out);
    }

    /**
     * Is the key too thin to match on?
     *
     * A two-character key would match half the rule set. Better to import the
     * row, propose nothing and let a person look at it than to file it against
     * whatever rule happened to be listed first.
     */
    public static function tooThin(string $key): bool
    {
        return strlen(str_replace(' ', '', $key)) < 3;
    }
}

/* ---------------------------------------------------------------------------
 * EXPENSE RULES
 *
 * Pattern to account, ordered, first match wins. The same shape as Rules and
 * Markup and for the same reason: the decision lives once, in PHP, where it can
 * be tested — never duplicated into a view or a script.
 *
 * A RULE PROPOSES. IT DOES NOT DECIDE. Matching writes suggested_account on the
 * imported row and stops there. Nothing reaches the ledger until a human
 * accepts it, and the two facts are kept in separate columns so "the engine
 * guessed" and "a person agreed" never blur into each other. That separation is
 * what makes the hit rate measurable, and the hit rate is the only honest
 * answer to "is this thing actually working".
 *
 * THE VALUE IS IN THE CORRECTIONS, NOT THE SEED. The starter set below covers
 * the vendors a mobile mechanic sees most, and it will be wrong about this
 * particular business in ways nobody can predict. What makes the engine worth
 * having is the loop: when the operator recategorises a line, offer to write
 * the rule, so the second invoice from that vendor files itself. After a few
 * months the learned rules outnumber the seeded ones and are worth more,
 * because they came from this business rather than from a guess about it.
 *
 * There is deliberately no downloadable merchant-to-category dataset behind
 * this. None with a usable licence exists — see knowledge/EXTERNAL-SOURCES.md
 * section 4, which also records why merchant category codes cannot be the key.
 * ------------------------------------------------------------------------- */
final class ExpenseRules
{
    /**
     * The starter set:
     *   [pattern, account, vendor label, priority, is_regex?, classification?]
     *
     * classification defaults to BUSINESS. TRANSFER marks a rule that moves
     * money between accounts the business owns rather than spending it — a
     * prepaid card load is the case that forced the distinction, and calling
     * it an expense would have claimed a deduction for money that had not yet
     * bought anything.
     *
     * The optional fifth element makes a rule an anchored regex, and it exists
     * because of a trap worth naming. Square renders Google Ads as the bare
     * word "Ads" on some rows. A substring rule for ADS also matches ROADSIDE
     * — the business's own name — so every line mentioning it would file to
     * advertising. Short words need anchors, and anchors need a regex.
     *
     * Priority orders the match — lower runs first — and exists for the case
     * where one descriptor legitimately contains two patterns. "COSTCO GAS"
     * is fuel and "COSTCO" is supplies, so the fuel rule has to be asked first
     * or every tank fills the wrong account. Specific before general is the
     * whole convention.
     *
     * Accounts are the ones already in the chart. 5xxx where the cost attaches
     * to a job and belongs in gross profit, 6xxx where it is overhead — parts
     * bought for a customer's car are COGS, a tool that stays in the van is
     * not, and putting either in the other makes per-job margin meaningless.
     */
    public const SEED = [
        /* MONEY MOVING, NOT MONEY SPENT. Asked first, because nothing below
         * should ever get the chance to treat a card load as a purchase.
         *
         * The four-digit form catches cards not yet seen — 4282, 2173, 1721 and
         * 7329 are the ones in the statements to hand, and naming each would
         * mean editing code the next time one is issued. */
        ['^VISA \d{3,4}$',    '1030', 'Prepaid card load',   1, 1, 'TRANSFER'],
        ['^VISA DIRECT',      '1030', 'Prepaid card load',   1, 1, 'TRANSFER'],

        /* Fuel, asked before the general merchants that also sell it. */
        ['COSTCO GAS',        '5030', 'Costco Fuel',        10],
        ['COSTCO WHSE GAS',   '5030', 'Costco Fuel',        10],
        ['CHEVRON',           '5030', 'Chevron',            20],
        /* Was 'SHELL OIL' and 'SHELL SERVICE'. Seventy-five real transactions
         * worth $1,773.38 came through as plain 'SHELL' and matched neither —
         * the cost of writing rules against an imagined descriptor instead of
         * a real statement. */
        ['SHELL',             '5030', 'Shell',              20],
        ['PILOT',             '5030', 'Pilot',              20],
        ['FLYING J',          '5030', 'Flying J',           20],
        /* Anchored so it cannot be swallowed by GLOVES, and optional-apostrophe
         * because the statements spell it both ways. */
        ["\\bLOVE'?S\\b",     '5030', "Love's",             20, 1],
        ['\bARCO\b',          '5030', 'ARCO',               20, 1],
        ['76 ',               '5030', '76',                 20],
        ['CIRCLE K',          '5030', 'Circle K',           20],
        ['SPACE AGE',         '5030', 'Space Age Fuel',     20],
        ['FRED MEYER FUEL',   '5030', 'Fred Meyer Fuel',    15],
        ['SAFEWAY FUEL',      '5030', 'Safeway Fuel',       15],
        /* Added from the 2026 Square Checking statements — real vendors, not
         * guesses about what a mobile mechanic might use. */
        ['JUBITZ',            '5030', 'Jubitz',             20],
        ['ASTRO',             '5030', 'Astro',              20],
        ['SPEEDWAY',          '5030', 'Speedway',           20],
        ['FASTRAK',           '5030', 'Fastrak Fuel Mart',  20],

        /* AUTO PARTS STORES SELL BOTH, AND THE DESCRIPTOR CANNOT TELL YOU WHICH.
         *
         * "NAPA AUTO PARTS #4471" does not say whether the basket held a
         * customer's alternator or a socket set for the van. The first is COGS,
         * the second is overhead, and no amount of name matching will separate
         * them — the information is on the receipt, not the statement.
         *
         * These file to 5000 anyway, deliberately and uniformly, because:
         *
         *   - The baskets are genuinely mixed and mostly unsplittable. The
         *     receipts for the first four years no longer exist, so any split
         *     across that period would be invented. An estimated allocation
         *     that cannot be supported is precision theatre: it reads as
         *     accurate and is not, which is worse than a stated policy.
         *   - It costs nothing in tax. COGS and operating expenses are both
         *     fully deductible and net profit is identical either way. Nothing
         *     here changes what is owed.
         *   - It runs gross margin slightly PESSIMISTIC — a tool counted as job
         *     cost makes jobs look a little less profitable than they were.
         *     That is the safe direction to be wrong in when pricing work.
         *
         * The consistency is the point. A uniform, disclosed treatment is
         * defensible to a CPA and to an auditor in a way that a per-transaction
         * guess is not. If line-item accuracy is ever wanted, it comes from
         * attaching receipts and job numbers going forward, never from
         * re-litigating six years of statements. */
        ['\bNAPA\b',          '5000', 'NAPA Auto Parts',    30, 1],
        ['O REILLY',          '5000', "O'Reilly",           30],
        ["O'REILLY",          '5000', "O'Reilly",           30],
        ['OREILLY',           '5000', "O'Reilly",           30],
        ['AUTOZONE',          '5000', 'AutoZone',           30],
        ['AUTO ZONE',         '5000', 'AutoZone',           30],
        ['ADVANCE AUTO',      '5000', 'Advance Auto Parts', 30],
        ['ROCKAUTO',          '5000', 'RockAuto',           30],
        ['ROCK AUTO',         '5000', 'RockAuto',           30],
        ['CARQUEST',          '5000', 'CarQuest',           30],
        ['INTERSTATE BATTER', '5000', 'Interstate Batteries', 30],
        ['BATTERIES PLUS',    '5000', 'Batteries Plus',     30],
        ['LES SCHWAB',        '5000', 'Les Schwab',         30],
        ['DISCOUNT TIRE',     '5000', 'Discount Tire',      30],
        ['GENUINE PARTS',     '5000', 'Genuine Parts',      30],
        /* From the real statements. APRO is the auto-parts co-op; the tyre
         * shops are where used and take-off tyres come from, which for this
         * business is stock for a job rather than overhead. */
        ['CF UNITED APRO',    '5000', 'United APRO',        30],
        ['\bAPRO\b',          '5000', 'United APRO',        35, 1],
        ['\bTIRES?\b',        '5000', 'Tyres',              38, 1],
        
        ['TOYOTA OF',         '5000', 'Toyota dealer parts', 30],
        /* A salvage yard is a parts supplier by another name, and for used
         * components it is often the only one. */
        ['PICK N PULL',       '5000', 'Pick-n-Pull',        30],
        ['PICK-N-PULL',       '5000', 'Pick-n-Pull',        30],
        /* "Premier Used And N" — the statement truncates "Premier Used And New
         * Tires" before the word the TIRE rule needs, so it needs its own. */
        ['PREMIER USED',      '5000', 'Premier Used Tires', 30],

        /* Trade software and reference. Real Time Labor Guide is a labour-time
         * lookup — a tool of the trade, not general software, but 6120 is the
         * only account that fits and splitting hairs here buys nothing. */
        ['REAL TIME LABOR',   '6120', 'Real Time Labor Guide', 45],
        ['GITHUB',            '6120', 'GitHub',             50],
        ['AIRTABLE',          '6120', 'Airtable',           50],
        ['CLAUDE',            '6120', 'Claude',             50],
        ['VENICE.AI',         '6120', 'Venice.ai',          50],
        ['VENICE AI',         '6120', 'Venice.ai',          50],
        ['ANLATAN',           '6120', 'Anlatan',            50],
        ['CROWNBILL',         '6120', 'Crownbill',          50],
        /* NOT 'WORKSPACE_'. The normaliser strips punctuation, so the
         * underscore never survives to be matched — a rule written against the
         * raw descriptor instead of against the match key it will actually be
         * compared to. Patterns belong in normalised form. */
        ['WORKSPACE',         '6120', 'Google Workspace',   45],
        ['OPENAI',            '6120', 'OpenAI',             50],
        ['OPENROUTER',        '6120', 'OpenRouter',         50],
        ['\bNDAI\b',          '6120', 'ndai.cc',            50, 1],
        ['ADVANTAGEUP',       '6100', 'AdvantageUp',        50],
        ['NOW WIFI',          '6130', 'Wi-Fi pass',         50],

        /* Rent and storage. The descriptor arrives mangled and truncated —
         * "Py Secpublicstgportla" is Public Storage Portland with the payment
         * prefix, the abbreviation and the truncation all working against it.
         * Matched on the fragment that actually survives. */
        ['READYSPACES',       '6200', 'ReadySpaces',        40],
        ['EXTRA SPACE',       '6200', 'Extra Space Storage', 40],
        ['U HAUL',            '6200', 'U-Haul storage',     40],
        ['UHI U HAUL',        '6200', 'U-Haul storage',     40],
        ['PUBLIC STG',        '6200', 'Public Storage',     40],
        ['PUBLICSTG',         '6200', 'Public Storage',     40],
        ['PUBLIC STORAGE',    '6200', 'Public Storage',     40],
        ['SECPUBLICSTG',      '6200', 'Public Storage',     40],
        ['STORAGE',           '6200', 'Storage',            60],

        /* Van upkeep, as it actually appears. */
        ['KHAN OIL',          '6010', 'Khan Oil & Wash',    50],

        /* Insurance, as the statements actually spell it. */
        ['ARTISAN & TRUCK',   '6250', 'Artisan & Truck',    45],
        ['PROG UNIVERSAL',    '6250', 'Progressive',        45],
        ['PROGRESSIVE INS',   '6250', 'Progressive',        45],

        /* Sublet and outside services. */
        ['\bTOW(ING)?\b',     '5010', 'Towing',             40, 1],
        ['WRECKER',           '5010', 'Towing',             40],

        /* Consumables and shop supplies used on jobs. */
        ['FASTENAL',          '5020', 'Fastenal',           45],
        ['GRAINGER',          '5020', 'Grainger',           45],

        /* Tools that stay in the van — overhead, not job cost. */
        ['HARBOR FREIGHT',    '6600', 'Harbor Freight',     50],
        ['SNAP-ON',           '6600', 'Snap-on',            50],
        ['SNAP ON',           '6600', 'Snap-on',            50],
        ['MATCO TOOLS',       '6600', 'Matco Tools',        50],
        ['NORTHERN TOOL',     '6600', 'Northern Tool',      50],
        ['HOME DEPOT',        '6600', 'Home Depot',         55],
        /* Same apostrophe trap as Love's: the bank writes "Lowe's" and the
         * normaliser keeps the apostrophe, so a bare LOWES never matched. */
        ["\\bLOWE'?S\\b",     '6600', "Lowe's",             55, 1],
        ['HARDWARE',          '6600', 'Hardware',           58],

        /* Vehicle upkeep on the service van itself. */
        ['JIFFY LUBE',        '6010', 'Jiffy Lube',         50],
        ['OIL CHANGE',        '6010', 'Oil change',         50],
        ['CAR WASH',          '6010', 'Car wash',           50],
        ['\bDMV\b',           '6500', 'DMV',                50, 1],
        ['DEPT OF MOTOR',     '6500', 'DMV',                50],

        /* Insurance. */
        ['PROGRESSIVE',       '6250', 'Progressive',        50],
        ['STATE FARM',        '6250', 'State Farm',         50],
        ['GEICO',             '6250', 'GEICO',              50],
        ['ALLSTATE',          '6250', 'Allstate',           50],
        ['HISCOX',            '6300', 'Hiscox',             50],
        ['NEXT INSURANCE',    '6300', 'Next Insurance',     50],
        ['THIMBLE',           '6300', 'Thimble',            50],

        /* Phone and communications. */
        ['VERIZON',           '6130', 'Verizon',            50],
        ['T-MOBILE',          '6130', 'T-Mobile',           50],
        ['TMOBILE',           '6130', 'T-Mobile',           50],
        ['\bATT?&?T\b',       '6130', 'AT&T',               50, 1],
        ['TELNYX',            '6150', 'Telnyx',             40],

        /* Software and subscriptions. */
        ['GOOGLE ADS',        '6110', 'Google Ads',         40],
        ['GOOGLE *ADS',       '6110', 'Google Ads',         40],
        ['GOOGLE LLC',        '6110', 'Google Ads',         40],
        ['^ADS$',             '6110', 'Google Ads',         40, 1],
        ['\bZOHO\b',          '6120', 'Zoho',               50, 1],
        ['SITEGROUND',        '6120', 'SiteGround',         50],
        ['GODADDY',           '6120', 'GoDaddy',            50],
        ['ANTHROPIC',         '6120', 'Anthropic',          50],
        ['MICROSOFT',         '6120', 'Microsoft',          55],
        ['ADOBE',             '6120', 'Adobe',              55],
        ['INTUIT',            '6120', 'Intuit',             55],
        ['QUICKBOOKS',        '6120', 'QuickBooks',         55],

        /* Merchant processing, when the card is billed directly. */
        ['SQUARE INC',        '7010', 'Square',             40],
        ['SQUAREUP',          '7010', 'Square',             40],

        /* General retail — last, so anything more specific wins first. */
        ['COSTCO',            '6400', 'Costco',             90],
        ['WALMART',           '6400', 'Walmart',            90],
        ['AMAZON',            '6400', 'Amazon',             90],
        ['STAPLES',           '6400', 'Staples',            90],
        ['OFFICE DEPOT',      '6800', 'Office Depot',       90],
        ['\bUSPS\b',          '6800', 'USPS',               90, 1],
        ['UPS STORE',         '6800', 'UPS Store',          90],

        /* -------------------------------------------------------------------
         * PROBABLY NOT BUSINESS AT ALL.
         *
         * Fast food, coffee and convenience stores, which in seven months of
         * real statements run to about $1,900 across 160 transactions. For a
         * sole operator these are ordinarily personal — a lunch bought alone
         * between jobs is not deductible, whatever it says on the receipt, and
         * IRC §162 asks whether an expense is ordinary AND necessary to the
         * trade, not merely whether it happened during the working day.
         *
         * They suggest 3200 Owner Draw, which is the CONSERVATIVE direction:
         * being wrong this way understates deductions and costs money rather
         * than creating exposure. A genuine client meal is reclassified in one
         * click, and that correction writes its own rule.
         *
         * This is a SUGGESTION, like every other rule here. Nothing posts until
         * a person agrees with it.
         * ----------------------------------------------------------------- */
        ['MCDONALD',          '3200', 'Personal — food',    80],
        ['TACO BELL',         '3200', 'Personal — food',    80],
        ['JACK IN THE BOX',   '3200', 'Personal — food',    80],
        ['BURGER KING',       '3200', 'Personal — food',    80],
        ['WENDY',             '3200', 'Personal — food',    80],
        ['SUBWAY',            '3200', 'Personal — food',    80],
        ['DUTCH BROS',        '3200', 'Personal — coffee',  80],
        ['STARBUCKS',         '3200', 'Personal — coffee',  80],
        ['PASADITA',          '3200', 'Personal — food',    80],
        ['7-ELEVEN',          '3200', 'Personal — convenience', 80],
        ['PLAID PANTRY',      '3200', 'Personal — convenience', 80],
        ['AP MARKET',         '3200', 'Personal — convenience', 80],
        ['GOOGLE PLAY',       '3200', 'Personal — apps',    80],
        ['STEAM',             '3200', 'Personal — games',   80],
        ['YOUTUBE',           '3200', 'Personal — subscription', 80],
        ['SAFEWAY',           '3200', 'Personal — groceries', 85],
        ['FRED MEYER',        '3200', 'Personal — groceries', 85],
        ['CORBETT COUNTRY',   '3200', 'Personal — groceries', 85],
        ['MINI MART',         '3200', 'Personal — convenience', 85],
        /* Confirmed personal by Jason 2026-08-21. Recurring $85 a month through
         * 2022, which reads exactly like contract labour from the statement
         * alone — and would have raised a 1099 question that does not exist.
         * A reminder that the descriptor cannot tell you whose money it was. */
        ['KATHY DOUGL',       '3200', 'Personal',           75],
    ];

    /** Seeded once, additively, in the manner of Accounts::ensureSeeded. */
    public static function ensureSeeded(): void
    {
        if (!Db::tableExists('expense_rules')) { return; }

        $have = [];
        foreach (Db::all('SELECT pattern FROM expense_rules') as $r) {
            $have[strtoupper((string) $r['pattern'])] = true;
        }

        foreach (self::SEED as $row) {
            [$pattern, $account, $vendor, $priority] = $row;
            $isRegex = (int) ($row[4] ?? 0);
            if (isset($have[strtoupper($pattern)])) { continue; }
            Db::insert('expense_rules', [
                'pattern'        => $pattern,
                'is_regex'       => $isRegex,
                'account_code'   => $account,
                'classification' => (string) ($row[5] ?? 'BUSINESS'),
                'vendor_name'    => $vendor,
                'priority'       => $priority,
                'is_active'      => 1,
                'source'         => 'SEED',
                'created_at'     => now(),
            ]);
        }
    }

    /**
     * Everything wrong with a proposed rule, in plain language. Empty means it
     * will save. Same contract as Markup::validate and Ledger::validate.
     *
     * The regex check is the one that earns its place: an invalid pattern saved
     * here would throw inside the import loop, halfway through a statement,
     * with an error naming preg_match rather than the rule that broke it.
     */
    public static function validate(array $rule, array $accountNumbers): array
    {
        $errors  = [];
        $pattern = trim((string) ($rule['pattern'] ?? ''));
        $account = trim((string) ($rule['account_code'] ?? ''));

        if ($pattern === '') {
            $errors[] = 'A rule needs something to match on.';
        } elseif (strlen($pattern) < 3) {
            $errors[] = "'$pattern' is too short — it would match almost everything.";
        }

        if (!empty($rule['is_regex']) && $pattern !== '') {
            /* Checked with the error handler suppressed and the result read,
             * because preg_match warns rather than throws on a bad pattern. */
            if (@preg_match(self::wrap($pattern), '') === false) {
                $errors[] = "That is not a valid pattern: " . (preg_last_error_msg() ?: 'syntax error');
            }
        }

        if ($account === '') {
            $errors[] = 'A rule needs an account to file against.';
        } elseif ($accountNumbers !== [] && !in_array($account, $accountNumbers, true)) {
            /* A rule pointing at an account that does not exist is the silent
             * dangling-tag failure the chart comment warns about — it does not
             * break until something tries to post, and then it breaks in the
             * ledger rather than here. */
            $errors[] = "Account $account is not in the chart.";
        }

        $class = strtoupper(trim((string) ($rule['classification'] ?? 'BUSINESS')));
        if (!in_array($class, ['BUSINESS', 'PERSONAL', 'TRANSFER'], true)) {
            $errors[] = "'$class' is not a classification.";
        }

        return $errors;
    }

    /** A stored pattern to a usable regex, delimiters and all. */
    private static function wrap(string $pattern): string
    {
        return '/' . str_replace('/', '\/', $pattern) . '/i';
    }

    /**
     * The first rule that matches, or null.
     *
     * NULL IS A REAL ANSWER AND MUST STAY ONE. An unrecognised merchant gets no
     * suggestion, appears in the review queue, and waits for a person. The
     * alternative — falling back to 6900 Other Expenses — would look like the
     * engine was working while quietly filing the whole statement into a
     * bucket nobody reads.
     *
     * @param array<int,array<string,mixed>> $rules already ordered by priority
     */
    public static function match(string $matchKey, array $rules): ?array
    {
        $key = strtoupper(trim($matchKey));
        if ($key === '' || Descriptor::tooThin($key)) { return null; }

        foreach ($rules as $r) {
            if (!($r['is_active'] ?? 1)) { continue; }
            $pattern = (string) $r['pattern'];
            if ($pattern === '') { continue; }

            $hit = !empty($r['is_regex'])
                ? @preg_match(self::wrap($pattern), $key) === 1
                : str_contains($key, strtoupper($pattern));

            if ($hit) { return $r; }
        }
        return null;
    }

    /** Active rules in the order match() expects: specific first, then by id. */
    public static function active(): array
    {
        return Db::all(
            'SELECT * FROM expense_rules WHERE is_active = 1 ORDER BY priority, id'
        );
    }

    /** Record that a rule fired, so a dead rule can be told from a careful one. */
    public static function recordHit(int $ruleId): void
    {
        Db::q('UPDATE expense_rules SET hits = hits + 1, last_matched_at = ? WHERE id = ?',
            [now(), $ruleId]);
    }
}

/* ---------------------------------------------------------------------------
 * LEDGER REPORTS
 *
 * Reading the books. Distinct from ReportController, which reports on
 * OPERATIONS from the documents — jobs, sources, methods, per-service margin.
 * That answers "how is the business doing". This answers "what do the books
 * say", and the two must not be conflated: an operational figure that
 * disagrees with the ledger is a signal, and merging them would hide it.
 *
 * EVERY REPORT RETURNS THE SAME SHAPE, so the view renders any of them without
 * knowing which it has, and adding one is a method plus a line in REPORTS
 * rather than a new page:
 *
 *   key       machine name, used in the URL
 *   title     what it is
 *   subtitle  what it means, in a sentence
 *   columns   [['label' => …, 'align' => 'left'|'right', 'strong' => bool], …]
 *   rows      [[cell, cell, …], …]  — already formatted for display
 *   totals    a final row, or null
 *   note      a caveat worth reading before believing the numbers
 *   ok        false when the report itself found something wrong
 * ------------------------------------------------------------------------- */
final class LedgerReports
{
    /** key => label. Adding a report means adding here and one method. */
    public const REPORTS = [
        'trial-balance'  => 'Trial balance',
        'account'        => 'Account detail',
        'receivables'    => 'Receivables aging',
        'cores'          => 'Core deposits outstanding',
        'cash-basis'     => 'Cash basis vs accrual',
        'square-clearing'=> 'Square Clearing reconciliation',
    ];

    public static function run(string $key, array $opt = []): array
    {
        return match ($key) {
            'account'         => self::accountDetail($opt),
            'receivables'     => self::receivables($opt),
            'cores'           => self::cores($opt),
            'cash-basis'      => self::cashBasis($opt),
            'square-clearing' => self::squareClearing($opt),
            default           => self::trialBalance($opt),
        };
    }

    /**
     * Money at Square: what came in, what Square took, what reached the bank.
     *
     * THE RESIDUAL IS THE REPORT. Every other line is working. A clearing
     * account exists to be emptied, so if 1050 does not read zero once every
     * payout has settled, either a charge is missing its payout or a payout is
     * carrying money no charge accounted for — and both are findable from here
     * rather than by reading six years of journal.
     *
     * A non-zero residual is not automatically an error: charges taken in the
     * last few days genuinely have not been paid out yet, and that money is
     * correctly still sitting at Square. The note says which case it is.
     */
    public static function squareClearing(array $opt = []): array
    {
        $to = (string) ($opt['to'] ?? '');

        /* Read from the journal rather than from the mirror. The mirror says
         * what Square reported; this report has to say what the BOOKS did with
         * it, and the difference between those two is the thing worth seeing. */
        $bySource = static function (string $source) use ($to): array {
            $sql = "SELECT COALESCE(SUM(l.debit_cents),0) d, COALESCE(SUM(l.credit_cents),0) c,
                           COUNT(DISTINCT e.id) n
                    FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id
                    WHERE l.account_number = ? AND e.source_type = ?";
            $args = [Posting::SQUARE_CLEARING, $source];
            if ($to !== '') { $sql .= ' AND e.entry_date <= ?'; $args[] = $to; }
            $r = Db::one($sql, $args) ?? ['d' => 0, 'c' => 0, 'n' => 0];
            return ['in' => (int) $r['d'], 'out' => (int) $r['c'], 'n' => (int) $r['n']];
        };

        $charges    = $bySource('SQCHG');
        $deductions = $bySource('SQDED');
        $payouts    = $bySource('SQPAY');

        $fees = (int) (Db::val(
            "SELECT COALESCE(SUM(l.debit_cents),0) - COALESCE(SUM(l.credit_cents),0)
             FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id
             WHERE l.account_number = ?" . ($to !== '' ? ' AND e.entry_date <= ?' : ''),
            $to !== '' ? [Posting::SQUARE_FEES, $to] : [Posting::SQUARE_FEES], 0) ?? 0);

        $balance = Ledger::balanceCents(Posting::SQUARE_CLEARING, $to ?: null);

        $m = static fn(int $c): string => money(Markup::centsToStr($c));

        /* Refunds post as SQDED, not SQCHG — they are money leaving the balance,
         * which is what a deduction is. An earlier version of this report gave
         * them their own row fed from the charges bucket, where nothing ever
         * credits 1050, so it read $0.00 for ever. A row that cannot be
         * anything but zero is worse than no row: it invites the reader to
         * conclude there were no refunds. */
        $rows = [
            ['Card sales settled into the balance',  (string) $charges['n'],    $m($charges['in']),    $m($charges['out'])],
            ['Deductions, refunds and disputes',     (string) $deductions['n'], $m($deductions['in']), $m($deductions['out'])],
            ['Payouts to Checking',                  (string) $payouts['n'],    $m($payouts['in']),    $m($payouts['out'])],
        ];

        $ok = $balance === 0;
        return [
            'key'      => 'square-clearing',
            'title'    => 'Square Clearing reconciliation',
            'subtitle' => 'Money that has reached Square but not yet the bank. This account exists '
                        . 'to be emptied — the number that matters is the one at the bottom.',
            'columns'  => [
                ['label' => 'Movement', 'align' => 'left'],
                ['label' => 'Entries', 'align' => 'right'],
                ['label' => 'Into 1050', 'align' => 'right'],
                ['label' => 'Out of 1050', 'align' => 'right'],
            ],
            'rows'   => $rows,
            'totals' => ['Still sitting at Square', '', '', $m($balance)],
            'ok'     => $ok,
            'note'   => ($ok
                    ? 'Square Clearing reads zero. Every card sale on the books has been followed '
                      . 'all the way to the bank.'
                    : 'Square Clearing holds ' . $m($balance) . '. That is correct only if it is '
                      . 'money from the last few days that Square has not paid out yet — anything '
                      . 'older means a charge is missing its payout, or a payout is carrying money '
                      . 'no charge accounted for.')
                . ' Square fees charged to 7010 so far: ' . $m($fees) . '.'
                . ' Account 2010 Credit Card Payable is expected to sit in DEBIT while Square card '
                . 'repayments are on the books and the card spending behind them is not — that is '
                . 'the bank and card import, not an error here.',
        ];
    }

    /**
     * Every account with activity, and the proof that the books balance.
     *
     * The equality of the two columns is the whole point. A trial balance that
     * does not square means an entry got in without both sides, and every
     * figure downstream of it is suspect.
     */
    public static function trialBalance(array $opt = []): array
    {
        $to   = (string) ($opt['to'] ?? '');
        $rows = [];
        $dTot = 0; $cTot = 0;

        foreach (Ledger::trialBalance($to ?: null) as $r) {
            $d = (int) $r['d']; $c = (int) $r['c'];
            $dTot += $d; $cTot += $c;
            $rows[] = [
                (string) $r['account_number'],
                (string) ($r['account_name'] ?? ''),
                (string) ($r['account_type'] ?? ''),
                $d !== 0 ? money(Markup::centsToStr($d)) : '',
                $c !== 0 ? money(Markup::centsToStr($c)) : '',
            ];
        }

        $square = $dTot === $cTot;
        return [
            'key'      => 'trial-balance',
            'title'    => 'Trial balance',
            'subtitle' => 'Every account the ledger has touched' . ($to ? ', through ' . $to : '') . '.',
            'columns'  => [
                ['label' => 'Account', 'align' => 'left'],
                ['label' => 'Name', 'align' => 'left'],
                ['label' => 'Type', 'align' => 'left'],
                ['label' => 'Debit', 'align' => 'right'],
                ['label' => 'Credit', 'align' => 'right'],
            ],
            'rows'   => $rows,
            'totals' => ['', '', 'Total', money(Markup::centsToStr($dTot)), money(Markup::centsToStr($cTot))],
            'ok'     => $square,
            'note'   => $square
                ? 'Debits equal credits. The books balance.'
                : 'THE BOOKS DO NOT BALANCE. Debits and credits differ by '
                  . money(Markup::centsToStr(abs($dTot - $cTot)))
                  . '. An entry was written with only one side — every figure below is suspect '
                  . 'until that is found.',
        ];
    }

    /** One account, entry by entry, with a running balance. */
    public static function accountDetail(array $opt = []): array
    {
        $acct = trim((string) ($opt['account'] ?? ''));
        if ($acct === '') {
            return self::empty('account', 'Account detail', 'Pick an account to see its entries.');
        }

        $a    = Db::one('SELECT * FROM gl_accounts WHERE account_number = ?', [$acct]);
        $name = $a['name'] ?? $acct;
        $type = (string) ($a['account_type'] ?? '');
        $up   = in_array($type, Ledger::CREDIT_POSITIVE, true) ? -1 : 1;

        $lines = Db::all(
            'SELECT l.*, e.entry_no, e.entry_date, e.source_type, e.source_ref, e.memo
             FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id
             WHERE l.account_number = ?
             ORDER BY e.entry_date, e.id, l.line_no',
            [$acct]
        );

        $run = 0; $rows = [];
        foreach ($lines as $l) {
            $run += $up * ((int) $l['debit_cents'] - (int) $l['credit_cents']);
            $rows[] = [
                (string) $l['entry_date'],
                (string) $l['entry_no'],
                (string) $l['source_type'],
                (string) ($l['memo'] ?: $l['source_ref']),
                (int) $l['debit_cents']  !== 0 ? money($l['debit'])  : '',
                (int) $l['credit_cents'] !== 0 ? money($l['credit']) : '',
                money(Markup::centsToStr($run)),
            ];
        }

        return [
            'key'      => 'account',
            'title'    => $acct . ' · ' . $name,
            'subtitle' => $type . ' — running balance in its natural direction, so a positive number '
                        . 'always means more of what this account is.',
            'columns'  => [
                ['label' => 'Date', 'align' => 'left'],
                ['label' => 'Entry', 'align' => 'left'],
                ['label' => 'Source', 'align' => 'left'],
                ['label' => 'Memo', 'align' => 'left'],
                ['label' => 'Debit', 'align' => 'right'],
                ['label' => 'Credit', 'align' => 'right'],
                ['label' => 'Balance', 'align' => 'right', 'strong' => true],
            ],
            'rows'   => $rows,
            'totals' => ['', '', '', 'Balance', '', '', money(Markup::centsToStr($run))],
            'ok'     => true,
            'note'   => $rows === [] ? 'Nothing has posted to this account yet.' : '',
        ];
    }

    /**
     * Who owes you, and for how long.
     *
     * Built from the INVOICES, not from account 1100, because a balance cannot
     * be aged — and then reconciled against 1100, because two numbers that
     * should agree and do not is the most useful thing a report can tell you.
     */
    public static function receivables(array $opt = []): array
    {
        $rows = [];
        $buckets = [0, 0, 0, 0];
        $total = 0;

        $open = Db::all(
            "SELECT i.*, c.first_name, c.last_name, c.company
             FROM invoices i LEFT JOIN customers c ON c.id = i.customer_id
             WHERE i.status IN ('ISSUED','PARTIAL')
             ORDER BY i.due_at, i.id"
        );

        foreach ($open as $i) {
            $due  = (string) ($i['due_at'] ?: $i['issued_at']);
            $days = $due ? (int) floor((time() - strtotime($due)) / 86400) : 0;
            $bal  = Markup::toCents($i['balance_due']);
            if ($bal === 0) { continue; }
            $total += $bal;

            $b = $days <= 0 ? 0 : ($days <= 30 ? 1 : ($days <= 60 ? 2 : 3));
            $buckets[$b] += $bal;

            $rows[] = [
                (string) $i['doc_number'],
                trim(((string) $i['company']) ?: (((string) $i['first_name']) . ' ' . ((string) $i['last_name']))) ?: '—',
                substr($due, 0, 10),
                $days > 0 ? $days . ' days' : 'not yet due',
                money($i['balance_due']),
            ];
        }

        $ledgerAr = Ledger::balanceCents(Posting::AR);
        $agrees   = $ledgerAr === $total;

        return [
            'key'      => 'receivables',
            'title'    => 'Receivables aging',
            'subtitle' => 'Money invoiced and not yet collected. ' .
                sprintf('Current %s · 1–30 %s · 31–60 %s · 60+ %s',
                    money(Markup::centsToStr($buckets[0])), money(Markup::centsToStr($buckets[1])),
                    money(Markup::centsToStr($buckets[2])), money(Markup::centsToStr($buckets[3]))),
            'columns'  => [
                ['label' => 'Invoice', 'align' => 'left'],
                ['label' => 'Customer', 'align' => 'left'],
                ['label' => 'Due', 'align' => 'left'],
                ['label' => 'Age', 'align' => 'left'],
                ['label' => 'Balance', 'align' => 'right'],
            ],
            'rows'   => $rows,
            'totals' => ['', '', '', 'Total outstanding', money(Markup::centsToStr($total))],
            'ok'     => $agrees,
            'note'   => $agrees
                ? 'Agrees with account 1100 in the ledger.'
                : 'The invoices total ' . money(Markup::centsToStr($total)) . ' but account 1100 says '
                  . money(Markup::centsToStr($ledgerAr)) . '. They should match. The usual cause is '
                  . 'invoices issued before the ledger existed, which were never posted — those are '
                  . 'expected to differ and are not an error.',
        ];
    }

    /** Cores still owed, oldest first, reconciled against 2050. */
    public static function cores(array $opt = []): array
    {
        if (!Db::tableExists('core_records')) {
            return self::empty('cores', 'Core deposits outstanding',
                'The core deposits table has not been created yet — apply the pending schema change.');
        }

        $in   = implode(',', array_map(static fn($s) => "'" . $s . "'", Cores::OPEN));
        $open = Db::all("SELECT * FROM core_records WHERE status IN ($in) ORDER BY charged_at, id");

        $rows = []; $total = 0;
        foreach ($open as $c) {
            $cents = Markup::toCents($c['core_value']) * (int) max(1, (float) $c['qty']);
            $total += $cents;
            $age    = $c['charged_at'] ? (int) floor((time() - strtotime((string) $c['charged_at'])) / 86400) : 0;
            $rows[] = [
                (string) $c['sku'],
                (string) $c['part_name'],
                Cores::label((string) $c['status']),
                $age . ' days',
                (string) ($c['due_back_by'] ?: '—'),
                money(Markup::centsToStr($cents)),
            ];
        }

        $ledger = Ledger::balanceCents(Posting::CORE_PAYABLE_ACCT);

        return [
            'key'      => 'cores',
            'title'    => 'Core deposits outstanding',
            'subtitle' => 'Deposits held against old units not yet settled. This is money you would '
                        . 'hand back if every one of them walked in tomorrow.',
            'columns'  => [
                ['label' => 'SKU', 'align' => 'left'],
                ['label' => 'Part', 'align' => 'left'],
                ['label' => 'Where it is', 'align' => 'left'],
                ['label' => 'Age', 'align' => 'left'],
                ['label' => 'Due back', 'align' => 'left'],
                ['label' => 'Value', 'align' => 'right'],
            ],
            'rows'   => $rows,
            'totals' => ['', '', '', '', 'Held', money(Markup::centsToStr($total))],
            'ok'     => true,
            'note'   => 'Account 2050 currently reads ' . money(Markup::centsToStr($ledger))
                      . '. It will not equal the figure above unless the supplier side has also '
                      . 'settled — a core has four legs, two with the customer and two with the '
                      . 'supplier, and only the customer side is listed here.',
        ];
    }

    /**
     * The same books, read two ways.
     *
     * Accrual is what the ledger holds. Cash basis strips anything not yet
     * paid — which is how most sole proprietors file. Neither is wrong; they
     * answer different questions, and the gap between them is exactly the
     * money you have earned but not yet been given.
     */
    public static function cashBasis(array $opt = []): array
    {
        $from = (string) ($opt['from'] ?? date('Y-01-01'));
        $to   = (string) ($opt['to']   ?? date('Y-m-d'));

        $accrual = (int) Db::val(
            "SELECT COALESCE(SUM(l.credit_cents - l.debit_cents),0)
             FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id
             WHERE l.account_type = 'REVENUE' AND e.entry_date BETWEEN ? AND ?",
            [$from, $to], 0
        );

        /* The unpaid deduction is the REVENUE on the unpaid portion — never
         * balance_due, which also carries the tax and core-deposit legs that
         * were never counted as revenue in the first place. Subtracting
         * balance_due understated cash-basis revenue on every unpaid invoice
         * with tax or a core deposit on it (fixed 2026-08-27, per
         * ACCOUNTING_PLAN: "with their tax and core legs excluded"). A
         * partially paid invoice deducts the unpaid fraction of its revenue
         * legs, computed in integer cents. */
        $unpaid = 0;
        foreach (Db::all(
            "SELECT i.total, i.balance_due,
                    COALESCE(SUM(l.credit_cents - l.debit_cents),0) AS rev_cents
             FROM invoices i
             JOIN journal_entries e ON e.source_type = 'INV' AND e.source_id = i.id
             JOIN journal_lines  l ON l.entry_id = e.id AND l.account_type = 'REVENUE'
             WHERE i.status IN ('ISSUED','PARTIAL')
               AND substr(i.issued_at,1,10) BETWEEN ? AND ?
             GROUP BY i.id, i.total, i.balance_due",
            [$from, $to]
        ) as $r) {
            $totalCents = Markup::toCents($r['total']);
            $balCents   = min(Markup::toCents($r['balance_due']), max($totalCents, 0));
            $revCents   = (int) $r['rev_cents'];
            if ($totalCents <= 0 || $revCents <= 0 || $balCents <= 0) { continue; }
            $unpaid += (int) round($revCents * $balCents / $totalCents);
        }

        $cogs = (int) Db::val(
            "SELECT COALESCE(SUM(l.debit_cents - l.credit_cents),0)
             FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id
             WHERE l.account_type IN ('COGS','EXPENSE') AND e.entry_date BETWEEN ? AND ?",
            [$from, $to], 0
        );

        $cash = $accrual - $unpaid;

        return [
            'key'      => 'cash-basis',
            'title'    => 'Cash basis vs accrual',
            'subtitle' => $from . ' to ' . $to,
            'columns'  => [
                ['label' => 'Measure', 'align' => 'left'],
                ['label' => 'Accrual — what you earned', 'align' => 'right'],
                ['label' => 'Cash — what you were paid', 'align' => 'right'],
            ],
            'rows' => [
                ['Revenue', money(Markup::centsToStr($accrual)), money(Markup::centsToStr($cash))],
                ['Costs and expenses', money(Markup::centsToStr($cogs)), money(Markup::centsToStr($cogs))],
                ['Difference — invoiced, not yet paid', money(Markup::centsToStr($unpaid)), ''],
            ],
            'totals' => ['Net', money(Markup::centsToStr($accrual - $cogs)), money(Markup::centsToStr($cash - $cogs))],
            'ok'     => true,
            'note'   => 'Your books are kept on ACCRUAL — revenue counts when you invoice it. The cash '
                      . 'column strips invoices that have not been paid, which is how most sole '
                      . 'proprietors file. Costs are shown unchanged in both columns because expenses '
                      . 'here are recorded when paid; a proper cash-basis conversion of the cost side '
                      . 'needs accounts payable, which is not built yet. Take this to an accountant '
                      . 'rather than to a tax form.',
        ];
    }

    private static function empty(string $key, string $title, string $note): array
    {
        return ['key' => $key, 'title' => $title, 'subtitle' => '', 'columns' => [],
                'rows' => [], 'totals' => null, 'ok' => true, 'note' => $note];
    }
}

/* ---------------------------------------------------------------------------
 * CORE DEPOSITS
 *
 * A core deposit is a security deposit on a remanufactured part. It is never
 * revenue and never expense until the moment it is forfeited — it is somebody
 * else's money, held. Overstating revenue by treating cores as sales is one of
 * the most common independent-shop mistakes and this class is the guard.
 *
 * Two things are tracked, and they are not the same thing:
 *
 *   THE MONEY   lives in 2050 Core Deposits Payable and is moved by Ledger.
 *   THE PART    lives in core_records and is moved by a person.
 *
 * The money is easy; the part is the one that gets lost. WKR is mobile, so a
 * core rides in a van for days between the job and the parts counter, and the
 * only thing that makes it findable later is knowing who picked it up.
 * ------------------------------------------------------------------------- */
final class Cores
{
    public const CHARGED   = 'CHARGED';
    public const COLLECTED = 'COLLECTED';
    public const RETURNED  = 'RETURNED';
    public const CREDITED  = 'CREDITED';
    public const SETTLED   = 'SETTLED';
    public const FORFEITED = 'FORFEITED';

    /** Where a core can go from where it is. Anything else is refused. */
    public const NEXT = [
        self::CHARGED   => [self::COLLECTED, self::FORFEITED],
        self::COLLECTED => [self::RETURNED, self::SETTLED, self::FORFEITED],
        self::RETURNED  => [self::CREDITED, self::SETTLED],
        self::CREDITED  => [self::SETTLED],
        self::SETTLED   => [],
        self::FORFEITED => [],
    ];

    /** Open means the money is still held and the part still owed. */
    public const OPEN = [self::CHARGED, self::COLLECTED, self::RETURNED, self::CREDITED];

    /** Days a customer has to bring the old unit back, before forfeit. */
    public static function windowDays(): int
    {
        $d = (int) App::setting('core_forfeit_days', 30);
        return $d > 0 ? $d : 30;
    }

    public static function label(string $status): string
    {
        return match ($status) {
            self::CHARGED   => 'Charged — old unit not yet in hand',
            self::COLLECTED => 'Collected — in the van',
            self::RETURNED  => 'Returned to supplier',
            self::CREDITED  => 'Supplier credited',
            self::SETTLED   => 'Settled',
            self::FORFEITED => 'Forfeited — kept',
            default         => $status,
        };
    }

    public static function canMove(string $from, string $to): bool
    {
        return in_array($to, self::NEXT[$from] ?? [], true);
    }

    /**
     * Raise the core records for an invoice as it is issued.
     *
     * Driven off the LINE's snapshotted core_charge, never the catalog item —
     * editing a part's core value must not change what an issued invoice
     * charged. Idempotent: an invoice already carrying core records is left
     * alone, so a re-issue or a double click cannot double the cores.
     *
     * @return int how many core records were created
     */
    public static function openForInvoice(array $inv): int
    {
        $invId = (int) $inv['id'];
        if (Db::val('SELECT COUNT(*) FROM core_records WHERE invoice_id = ?', [$invId], 0) > 0) {
            return 0;
        }

        $made = 0;
        foreach (Lines::forDoc('INV', $invId) as $l) {
            $cents = Markup::toCents($l['core_charge'] ?? 0);
            if ($cents <= 0) { continue; }

            $qty = max(1.0, (float) $l['qty']);
            Db::insert('core_records', [
                'invoice_id'      => $invId,
                'doc_line_id'     => (int) $l['id'],
                'catalog_item_id' => (int) $l['catalog_item_id'],
                'customer_id'     => (int) ($inv['customer_id'] ?? 0) ?: null,
                'sku'             => $l['sku'],
                'part_name'       => $l['name'],
                'core_value'      => Markup::centsToStr($cents),
                'qty'             => $qty,
                'status'          => self::CHARGED,
                'charged_at'      => now(),
                'due_back_by'     => date('Y-m-d', strtotime('+' . self::windowDays() . ' days')),
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
            $made++;
        }

        if ($made > 0) {
            Audit::log('invoice', $invId, 'cores:opened', $made . ' core' . ($made === 1 ? '' : 's'));
        }
        return $made;
    }

    /**
     * Move a core along its chain, posting whatever the move implies.
     *
     * The ledger entry is raised INSIDE the same transaction as the status
     * change: a core recorded as refunded with no matching entry, or an entry
     * with no record behind it, are both worse than the move not happening.
     *
     * @param array<string,mixed> $meta who collected it, which supplier, a note
     * @throws RuntimeException on an illegal transition
     */
    public static function move(int $coreId, string $to, array $meta = []): void
    {
        $c = Db::one('SELECT * FROM core_records WHERE id = ?', [$coreId]);
        if ($c === null) { throw new RuntimeException("Core record $coreId does not exist."); }

        $from = (string) $c['status'];
        if ($from === $to) { return; }
        if (!self::canMove($from, $to)) {
            throw new RuntimeException(
                'A core cannot go from ' . self::label($from) . ' to ' . self::label($to) . '.'
            );
        }

        $cents = Markup::toCents($c['core_value']) * (int) max(1, (float) $c['qty']);
        $ref   = trim(((string) $c['sku']) . ' ' . ((string) $c['part_name']));

        Db::tx(static function () use ($c, $coreId, $from, $to, $meta, $cents, $ref) {
            $set = ['status' => $to, 'updated_at' => now()];

            if (($meta['notes'] ?? '') !== '') {
                $set['notes'] = trim(((string) $c['notes']) . "\n" . (string) $meta['notes']);
            }

            switch ($to) {
                case Cores::COLLECTED:
                    /* The technician has the old unit. No money moves — the
                     * deposit is still held, the part has simply been found. */
                    $set['collected_at']    = now();
                    $set['collected_by_id'] = (int) ($meta['collected_by_id'] ?? (Auth::user()['id'] ?? 0)) ?: null;
                    break;

                case Cores::RETURNED:
                    $set['returned_at']   = now();
                    $set['supplier_name'] = trim((string) ($meta['supplier_name'] ?? '')) ?: $c['supplier_name'];
                    break;

                case Cores::CREDITED:
                    /* The supplier refunded us. Cash in — and 2050 goes UP,
                     * not down: this credit undoes leg 1 (what WE paid the
                     * supplier buying the part, an expense coded to 2050),
                     * not leg 2 (what the customer paid us). All four legs
                     * together net 2050 to zero — see ACCOUNTING_PLAN's core
                     * table and tests/ledger_integration.php. The old comment
                     * here said "liability down", the intuitive misreading
                     * the test file warns about (fixed 2026-08-27). */
                    $set['credited_at'] = now();
                    $set['posted_entry_id'] = Ledger::post('CORE', [
                        ['account' => Posting::CHECKING,      'debit_cents'  => $cents, 'memo' => 'Supplier core credit'],
                        ['account' => Posting::CORE_PAYABLE_ACCT, 'credit_cents' => $cents, 'memo' => $ref],
                    ], 'Core credited by supplier — ' . $ref, $coreId, (string) $c['sku']);
                    break;

                case Cores::SETTLED:
                    /* The customer got their deposit back. Liability down,
                     * cash out. Skipped when the refund was already recorded,
                     * so settling twice cannot pay twice. */
                    if (empty($c['customer_refunded_at'])) {
                        $set['customer_refunded_at'] = now();
                        $set['posted_entry_id'] = Ledger::post('CORE', [
                            ['account' => Posting::CORE_PAYABLE_ACCT, 'debit_cents'  => $cents, 'memo' => $ref],
                            ['account' => Posting::CHECKING,      'credit_cents' => $cents, 'memo' => 'Core refunded to customer'],
                        ], 'Core refunded to customer — ' . $ref, $coreId, (string) $c['sku']);
                    }
                    $set['settled_at'] = now();
                    break;

                case Cores::FORFEITED:
                    /* The old unit never came back. NOW it is earned, and only
                     * now — this is the single point at which a core deposit
                     * becomes revenue. */
                    $set['forfeited_at'] = now();
                    $set['settled_at']   = now();
                    $set['posted_entry_id'] = Ledger::post('CORE', [
                        ['account' => Posting::CORE_PAYABLE_ACCT, 'debit_cents'  => $cents, 'memo' => $ref],
                        ['account' => Posting::CORE_FORFEIT_REVENUE, 'credit_cents' => $cents, 'memo' => 'Core forfeited'],
                    ], 'Core forfeited — ' . $ref, $coreId, (string) $c['sku']);
                    break;
            }

            Db::update('core_records', $coreId, $set);
            Audit::log('core_record', $coreId, 'moved', $from . ' -> ' . $to . ' · ' . $ref);
        });
    }

    /** Cores whose window has expired and which are still open. */
    public static function overdue(): array
    {
        $in = implode(',', array_map(static fn($s) => "'" . $s . "'", self::OPEN));
        return Db::all(
            "SELECT * FROM core_records
             WHERE status IN ($in) AND due_back_by IS NOT NULL AND due_back_by < ?
             ORDER BY due_back_by, id",
            [date('Y-m-d')]
        );
    }

    /** Everything still owed, and what it is worth. */
    public static function openSummary(): array
    {
        $in  = implode(',', array_map(static fn($s) => "'" . $s . "'", self::OPEN));
        $row = Db::one(
            "SELECT COUNT(*) n, COALESCE(SUM(core_value * qty),0) total FROM core_records WHERE status IN ($in)"
        ) ?? ['n' => 0, 'total' => 0];
        return ['count' => (int) $row['n'], 'total' => (string) $row['total']];
    }
}
