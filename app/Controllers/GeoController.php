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
 * Turning a point into an address, and an address into a point.
 *
 * Both directions already existed on the Geocoder contract; nothing here
 * invents geocoding, it just gives the browser a way to ask. The formula for
 * what counts as an address stays in `Address` and is applied server-side, so
 * the map cannot talk a bad line into an address field by being clever in
 * JavaScript — the same reason the markup formula never ships to the browser.
 *
 * WHAT THE PIN MEANS. The coordinates are the answer. The address is a label
 * for them. `reverse` therefore always returns the coordinates it was given,
 * even when the geocoder can say nothing about them — a highway shoulder has
 * no street number, and losing the position because of that would throw away
 * the only thing that actually routes a truck.
 */
final class GeoController
{
    /** Roughly the contiguous US plus Alaska and Hawaii — a typo filter, not a service area. */
    private const LAT_MIN = 15.0,  LAT_MAX = 72.0;
    private const LNG_MIN = -180.0, LNG_MAX = -60.0;

    private static function fail(string $why, int $code = 422): void
    {
        http_response_code($code);
        echo json_encode(['ok' => false, 'error' => $why]);
    }

    /**
     * A dropped pin → the nearest physical address.
     * The point is echoed back verbatim so the caller never has to trust that
     * a round trip preserved it.
     */
    public static function reverse(): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        header('Content-Type: application/json');
        csrf_check();

        $lat = (float) input('lat', 0);
        $lng = (float) input('lng', 0);
        if ($lat < self::LAT_MIN || $lat > self::LAT_MAX || $lng < self::LNG_MIN || $lng > self::LNG_MAX) {
            self::fail('That point is outside the mapped area. Drop the pin on the map.');
            return;
        }

        $geo  = Integrations::geocoder();
        $near = $geo->reverse($lat, $lng);

        $chk = Address::check(
            $near['address'] ?? null,
            $near['city'] ?? null,
            $near['state'] ?? null,
            $near['postal_code'] ?? null
        );

        /* No api_log write here: every Geocoder driver logs its own call.
         * Logging again at the controller would double-count outside calls. */
        echo json_encode([
            'ok'           => true,
            'lat'          => $lat,
            'lng'          => $lng,
            'line'         => $chk['line'],
            'city'         => $chk['city'],
            'state'        => $chk['state'],
            'postal'       => $chk['postal'],
            'one_line'     => Address::oneLine($chk['line'], $chk['city'], $chk['state'], $chk['postal']),
            'intersection' => $near['intersection'] ?? null,
            'usable'       => $chk['ok'],
            /* Present only when the derived address will not pass the gate, so
             * the dispatcher is told before they promote rather than after. */
            'reason'       => $chk['ok'] ? '' : $chk['reason'],
            'driver'       => $geo->driverName(),
        ]);
    }

    /**
     * A typed address → the point it names, so the map can move its pin to
     * what the dispatcher wrote. The address is validated first: geocoding a
     * description wastes a call and invites a confident wrong answer.
     */
    public static function forward(): void
    {
        Auth::requireRole('ADMIN', 'DISPATCH');
        header('Content-Type: application/json');
        csrf_check();

        /* ONE ACCURATE LINE IS ENOUGH. This used to run the full address gate
         * first, so "842 SE Morrison St" typed without a city was refused
         * before the geocoder ever saw it — and the dispatcher was left
         * filling in fields a geocoder exists to fill. Now only the SHAPE is
         * demanded up front (a street number and name, not a description);
         * city, state and ZIP are taken as typed when present and derived
         * from the geocoder's answer when not (2026-08-31). */
        $chk = Address::check(
            (string) input('line', ''),
            (string) input('city', ''),
            (string) input('state', ''),
            (string) input('postal', '')
        );
        if (!$chk['ok'] && !Address::looksPhysical($chk['line'])) {
            echo json_encode(['ok' => false, 'usable' => false, 'error' => $chk['reason']]);
            return;
        }

        $one  = Address::oneLine($chk['line'], $chk['city'], $chk['state'], $chk['postal']);
        $geo  = Integrations::geocoder();
        $hit  = $geo->geocode($one);   // the driver writes its own api_log row

        if (($hit['lat'] ?? null) === null || ($hit['lng'] ?? null) === null) {
            /* The line is well-shaped but could not be placed. It may still be
             * usable as typed — the dispatcher can keep it and drop the pin by
             * hand, which is exactly the case the map exists for. */
            echo json_encode([
                'ok' => true, 'usable' => $chk['ok'], 'located' => false,
                'line' => $chk['line'], 'city' => $chk['city'], 'state' => $chk['state'],
                'postal' => $chk['postal'], 'one_line' => $one,
                'reason' => 'That address could not be placed on the map. '
                          . 'Drop the pin where the vehicle actually is.',
            ]);
            return;
        }

        /* The typed pieces win; whatever was left blank is derived, so a bare
         * street line comes back with its city, state and ZIP instead of a
         * scolding. Derived via the driver's structured reverse lookup at the
         * located point — parsing the formatted one-liner misreads OSM's,
         * which carries neighbourhood and county in the middle. */
        $city = $chk['city']; $state = $chk['state']; $postal = $chk['postal'];
        if ($city === '' || $state === '' || $postal === '') {
            $near   = $geo->reverse((float) $hit['lat'], (float) $hit['lng']);
            $city   = $city   !== '' ? $city   : (string) ($near['city'] ?? '');
            $state  = $state  !== '' ? $state  : (string) ($near['state'] ?? '');
            $postal = $postal !== '' ? $postal : (string) ($near['postal_code'] ?? '');
        }

        echo json_encode([
            'ok' => true, 'usable' => true, 'located' => true,
            'lat' => (float) $hit['lat'], 'lng' => (float) $hit['lng'],
            'line' => $chk['line'], 'city' => $city, 'state' => $state,
            'postal' => $postal, 'one_line' => Address::oneLine($chk['line'], $city, $state, $postal),
            'confidence' => $hit['confidence'] ?? 'unknown',
            'driver' => $geo->driverName(),
        ]);
    }
}
