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
 * The customer-facing signing page.
 *
 * Public, because the person opening it is not a user of this system — they
 * have a link from a text message and nothing else. Access is the unguessable
 * token in the URL, and the page shows only what someone being asked to sign
 * needs to see: who is asking, for what work, at what price, on which vehicle.
 *
 * It is the same signature, in the same column, as the one a technician takes
 * on their own device. Only the route differs — which is why the record stores
 * how it arrived (auth_method) alongside the IP, the user agent, and the
 * moments the link was sent, first opened, and signed.
 *
 * Nothing here can change scope or price. The only write is the signature.
 */
final class SignController
{
    public static function show(array $a): void
    {
        [$req, $wo, $est] = self::resolve((string) $a['token']);

        // Stamped on first open only, so "when did they see it" survives
        // later revisits.
        SignatureRequest::markViewed($req);

        $customer = Db::one('SELECT * FROM customers WHERE id = ?', [(int) $req['customer_id']]);
        $vehicle  = $est['vehicle_id'] ? Db::one('SELECT * FROM vehicles WHERE id = ?', [(int) $est['vehicle_id']]) : null;
        $sr       = Db::one('SELECT * FROM service_requests WHERE id = ?', [(int) $wo['service_request_id']]);

        View::render('pages/sign', [
            'title'    => 'Sign ' . $wo['doc_number'],
            '__bare'   => true,
            'req'      => $req,
            'wo'       => $wo,
            'est'      => $est,
            'sr'       => $sr,
            'customer' => $customer,
            'vehicle'  => $vehicle,
            'lines'    => Lines::forDoc('WO', (int) $wo['id']),
            'totals'   => Lines::totals('WO', (int) $wo['id'], (float) $est['tax_rate']),
            'signed'   => $req['status'] === 'SIGNED',
        ]);
    }

    public static function sign(array $a): void
    {
        [$req, $wo, $est] = self::resolve((string) $a['token']);

        if ($req['status'] !== 'OPEN') {
            flash('That link has already been used.', 'warn');
            redirect('/sign/' . $a['token']);
        }

        $sig  = (string) input('signature_data', '');
        $name = trim((string) input('signer_name', ''));
        if ($sig === '' || $name === '') {
            flash('Please enter your name and sign before submitting.', 'err');
            redirect('/sign/' . $a['token']);
        }
        if (!signature_is_image($sig)) {
            flash('Your signature could not be read. Please sign again.', 'err');
            redirect('/sign/' . $a['token']);
        }
        $name = mb_substr($name, 0, 120);

        $claimed = Db::tx(function () use ($req, $wo, $sig, $name): bool {
            if (!SignatureRequest::markSigned($req, $name)) {
                return false;
            }
            if ($req['purpose'] === 'AUTH') {
                WorkOrderController::recordAuthSignature($wo, $sig, $name, 'SMS');
            } else {
                Db::update('work_orders', (int) $wo['id'], [
                    'signer_name'     => $name,
                    'signature_data'  => $sig,
                    'signed_at'       => now(),
                    'signed_method'   => 'SMS',
                    'unsigned_reason' => null,
                    'updated_at'      => now(),
                ]);
                Audit::log('work_order', (int) $wo['id'], 'completion:signed',
                    $name . ' · signed remotely via texted link · IP ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
            }
            return true;
        });

        if (!$claimed) {
            flash('That link has already been used.', 'warn');
            redirect('/sign/' . $a['token']);
        }

        flash('Thank you — your signature has been recorded.', 'ok');
        redirect('/sign/' . $a['token']);
    }

    /**
     * A token resolves to a request, its work order, and the estimate behind
     * it — or to a 404. A bad token is indistinguishable from a missing one,
     * deliberately: probing for valid links should learn nothing.
     *
     * @return array{0:array,1:array,2:array}
     */
    private static function resolve(string $token): array
    {
        $req = SignatureRequest::byToken($token);
        if (!$req || $req['doc_type'] !== 'WO') { self::gone(); }
        // A superseded link stops showing the document, not just stops signing it.
        if ($req['status'] === 'VOID') { self::gone(); }

        $wo = Db::one('SELECT * FROM work_orders WHERE id = ?', [(int) $req['doc_id']]);
        if (!$wo) { self::gone(); }

        $est = Db::one('SELECT * FROM estimates WHERE id = ?', [(int) $wo['estimate_id']]);
        if (!$est) { self::gone(); }

        return [$req, $wo, $est];
    }

    private static function gone(): never
    {
        http_response_code(404);
        View::render('pages/404', ['title' => 'Link not found', '__bare' => true]);
        exit;
    }
}
