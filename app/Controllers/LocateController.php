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
 * The customer-facing location page.
 *
 * Public, because the person opening it is not a user of this system — they
 * are stranded somewhere they cannot describe, holding a link from a text
 * message. Access is the unguessable token in the URL, and the page asks for
 * exactly one thing: permission to read the phone's position.
 *
 * The browser cannot be forced to hand over a location — tapping the button
 * is what triggers the permission prompt, and a denial gets clear instructions
 * plus the dispatch number as the human fallback. Coordinates are stored the
 * moment they arrive; the reverse lookup that turns them into a nearest
 * address and intersection is best-effort on top, never a condition.
 *
 * Nothing here can read the job, the price, or the customer. The only write
 * is the position, onto the document that asked for it.
 */
final class LocateController
{
    public static function show(array $a): void
    {
        [$req, ] = self::resolve((string) $a['token']);

        LocationRequest::markViewed($req);

        View::render('pages/locate', [
            'title'  => 'Share your location',
            '__bare' => true,
            'req'    => $req,
        ]);
    }

    public static function capture(array $a): void
    {
        [$req, $doc] = self::resolve((string) $a['token']);

        if ($req['status'] !== 'OPEN') {
            flash('This location link has already been used or has expired — each one works once, for a few hours. Reply to our text or call us and we\'ll send a new one.', 'warn');
            redirect('/locate/' . $a['token']);
        }

        $lat = (float) input('latitude', '');
        $lng = (float) input('longitude', '');
        $acc = input('accuracy_m') !== '' ? (float) input('accuracy_m') : null;

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat === 0.0 && $lng === 0.0)) {
            flash('We couldn\'t read a location from your phone. Check that your browser is allowed to use location (a prompt or a crossed-out pin icon near the address bar), then tap the link again.', 'err');
            redirect('/locate/' . $a['token']);
        }

        // The coordinates are the prize; the lookup is decoration. Claim the
        // one-shot request and store the pin before asking an external service
        // for an address, so a geocoder timeout cannot lose the customer's
        // location. The address is enriched in a second best-effort update.
        $geo = Integrations::geocoder();
        $received = Db::tx(function () use ($req, $doc, $lat, $lng, $acc, $geo): bool {
            if (!LocationRequest::markReceived($req, $lat, $lng, $acc, null, null, $geo->driverName())) {
                return false;
            }

            /* A WO link is the technician's, not the customer's: the position
             * is the truck, stored in its own columns so it can never be
             * mistaken for where the job is. */
            if ($req['doc_type'] === 'WO') {
                Db::update('work_orders', (int) $doc['id'], [
                    'tech_latitude'   => sprintf('%.7F', $lat),
                    'tech_longitude'  => sprintf('%.7F', $lng),
                    'tech_located_at' => now(),
                    'updated_at'      => now(),
                ]);
                Audit::log('work_order', (int) $doc['id'], 'location:received',
                    sprintf('%.7F, %.7F', $lat, $lng)
                    . ($acc !== null ? ' · ±' . round($acc) . ' m' : '')
                    . ' · shared by the technician via texted link');
                return true;
            }

            $table  = $req['doc_type'] === 'EST' ? 'estimates' : 'service_requests';
            $entity = $req['doc_type'] === 'EST' ? 'estimate' : 'service_request';
            Db::update($table, (int) $doc['id'], [
                'latitude'             => sprintf('%.7F', $lat),
                'longitude'            => sprintf('%.7F', $lng),
                'location_captured_at' => now(),
                'updated_at'            => now(),
            ]);
            Audit::log($entity, (int) $doc['id'], 'location:received',
                sprintf('%.7F, %.7F', $lat, $lng)
                . ($acc !== null ? ' · ±' . round($acc) . ' m' : '')
                . ' · shared by the customer via texted link');
            return true;
        });

        if (!$received) {
            flash('This location link has already been used or has expired — each one works once, for a few hours. Reply to our text or call us and we\'ll send a new one.', 'warn');
            redirect('/locate/' . $a['token']);
        }

        // The address enrichment is for finding a stranded customer; a truck's
        // position only needs to feed the route calculation.
        if ($req['doc_type'] === 'WO') {
            flash('Thanks — dispatch has your position and can route you to the job.', 'ok');
            redirect('/locate/' . $a['token']);
        }

        $near = [];
        try {
            $near = $geo->reverse($lat, $lng);
        } catch (Throwable $e) {
            error_log('[geocode] reverse lookup failed: ' . $e->getMessage());
        }
        $addr  = isset($near['address']) && $near['address'] !== null ? substr((string) $near['address'], 0, 250) : null;
        $cross = isset($near['intersection']) && $near['intersection'] !== null ? substr((string) $near['intersection'], 0, 155) : null;

        if ($addr !== null || $cross !== null || ($near['city'] ?? null) !== null || ($near['state'] ?? null) !== null || ($near['postal_code'] ?? null) !== null) {
            $table = $req['doc_type'] === 'EST' ? 'estimates' : 'service_requests';
            $set = [
                'nearest_address'      => $addr,
                'nearest_intersection' => $cross,
                'updated_at'           => now(),
            ];
            if ($table === 'service_requests' && (string) $doc['city'] === '') {
                if (($near['city'] ?? null) !== null)        { $set['city'] = (string) $near['city']; }
                if (($near['state'] ?? null) !== null)       { $set['state'] = (string) $near['state']; }
                if (($near['postal_code'] ?? null) !== null && (string) $doc['postal_code'] === '') {
                    $set['postal_code'] = (string) $near['postal_code'];
                }
            }
            Db::update($table, (int) $doc['id'], $set);

            /* The answered link is evidence — it should carry the same derived
             * address the document got, not the nulls markReceived held while
             * the lookup was still pending (fixed 2026-08-31). */
            Db::update('location_requests', (int) $req['id'], [
                'nearest_address'      => $addr,
                'nearest_intersection' => $cross,
            ]);
        }

        flash('Thank you — we have your location. Help is on the way.', 'ok');
        redirect('/locate/' . $a['token']);
    }

    /**
     * A token resolves to a request and its document, or to a 404. A bad token
     * is indistinguishable from a missing one, deliberately: probing for valid
     * links should learn nothing. Expiry is decided here, at read time.
     *
     * @return array{0:array,1:array}
     */
    private static function resolve(string $token): array
    {
        $req = LocationRequest::byToken($token);
        if (!$req) { self::gone(); }
        $req = LocationRequest::checkExpiry($req);

        $table = match ((string) $req['doc_type']) {
            'EST'   => 'estimates',
            'WO'    => 'work_orders',   // the technician's link, not the customer's
            default => 'service_requests',
        };
        $doc   = Db::one("SELECT * FROM $table WHERE id = ?", [(int) $req['doc_id']]);
        if (!$doc) { self::gone(); }

        return [$req, $doc];
    }

    private static function gone(): never
    {
        http_response_code(404);
        View::render('pages/404', ['title' => 'Link not found', '__bare' => true]);
        exit;
    }
}
