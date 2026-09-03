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
 * Provider callbacks.
 *
 * These are the only routes in the application reachable without a session, so
 * they are the only ones an attacker can reach at all. Three rules hold here:
 *
 *   1. The signature is verified before the body is parsed. An unsigned or
 *      badly-signed callback is logged and dropped — it never reaches business
 *      logic, and the response says nothing about why.
 *   2. Every handler is idempotent. Providers retry, and retries must be
 *      no-ops rather than second payments or second opt-outs.
 *   3. A 200 is returned for anything understood, so the provider stops
 *      retrying; only genuine server faults return 5xx.
 */
final class WebhookController
{
    /** Telnyx — delivery receipts, and inbound STOP / START / HELP. */
    public static function telnyx(): void
    {
        self::guard(fn () => self::handleTelnyx());
    }

    /** Square — a customer paid, or a refund settled. */
    public static function square(): void
    {
        self::guard(fn () => self::handleSquare());
    }

    /**
     * A callback that crashes must answer 5xx, never 200 — a provider that gets
     * a 200 stops retrying, and a payment we failed to write is then lost for
     * good. The error is logged where an operator will find it; the response
     * says nothing useful to a stranger.
     */
    private static function guard(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $ex) {
            error_log('[webhook] ' . $ex);
            self::respond(500, 'error');
        }
        self::respond(200, 'ok');
    }

    private static function handleTelnyx(): void
    {
        $raw     = self::body();
        $gateway = Integrations::sms();

        // The response says only "rejected"; the LOG says which of several very
        // different problems it was, because "no delivery receipts are arriving"
        // is otherwise indistinguishable from "the extension is missing".
        $why = $gateway instanceof TelnyxSmsGateway
            ? $gateway->verifyReason($raw, Http::headers())
            : ($gateway->verifyWebhook($raw, Http::headers()) ? '' : 'the driver does not accept callbacks');

        if ($why !== '') {
            ApiLog::write('sms', $gateway->driverName(), 'webhook:rejected', self::callerIp(), false,
                'Refused — ' . $why . ' · ' . self::origin());
            self::respond(403, 'rejected');
        }

        $evt = $gateway->parseWebhook($raw);
        if ($evt === null) {
            // It carried a good signature, so it genuinely came from Telnyx —
            // we simply could not read it. Either an event type this app does
            // not handle, or a payload shape that has changed. Worth one line;
            // a verified callback we cannot understand should never be silent.
            $head = trim(substr(preg_replace('/\s+/', ' ', $raw) ?? '', 0, 180));
            ApiLog::write('sms', $gateway->driverName(), 'webhook:unreadable', '', false,
                'A verified callback could not be parsed: ' . ($head !== '' ? $head : '(empty body)'));
            self::respond(200, 'ignored');
        }

        if ($evt['kind'] === 'status') {
            self::messageStatus($evt, $gateway->driverName());
            self::respond(200, 'ok');
        }

        if ($evt['kind'] === 'inbound') {
            self::inboundSms($evt, $gateway);
            self::respond(200, 'ok');
        }

        self::respond(200, 'ignored');
    }

    private static function handleSquare(): void
    {
        $raw     = self::body();
        $gateway = Integrations::payments();

        if (!$gateway->verifyWebhook($raw, Http::headers())) {
            ApiLog::write('payment', $gateway->driverName(), 'webhook:rejected', self::callerIp(), false,
                'Signature did not verify · ' . self::origin('square'));
            self::respond(403, 'rejected');
        }

        $evt = $gateway->parseWebhook($raw);
        if ($evt === null) { self::respond(200, 'ignored'); }

        /* IS THIS EVEN OURS?
         *
         * Square delivers callbacks for the whole merchant account, and this
         * account carries the owner's personal activity alongside White Knight
         * work. Until this check existed the handler ran business logic on
         * every event and only avoided acting on foreign ones because their
         * order id failed to match a row in payment_links — which is an
         * accident, not a boundary.
         *
         * Checked before status, before the invoice lookup, before anything.
         * A 200 is returned because the callback was understood and correctly
         * ignored; a 4xx would make Square retry something that will never
         * become ours. */
        if (!SquarePaymentGateway::isOurLocation($evt)) {
            ApiLog::write('payment', $gateway->driverName(), 'webhook:not-ours', $evt['reference'], true,
                'Location ' . ($evt['location_id'] ?: '(none given)') . ' is not this business — ignored.');
            self::respond(200, 'not-ours');
        }

        // Only a settled payment moves money. Anything else is noise.
        if ($evt['kind'] !== 'payment' || !in_array($evt['status'], ['COMPLETED', 'APPROVED'], true)) {
            ApiLog::write('payment', $gateway->driverName(), 'webhook:' . $evt['kind'], $evt['reference'], true,
                'Status ' . $evt['status'] . ' — no action taken.');
            self::respond(200, 'ignored');
        }

        $inv = self::invoiceForOrder($evt['order_id']);
        if (!$inv) {
            ApiLog::write('payment', $gateway->driverName(), 'webhook:orphan', $evt['reference'], false,
                'No invoice matches order ' . $evt['order_id']);
            self::respond(200, 'unmatched');
        }

        // Record what the processor says was taken, not what we expected. If the
        // customer paid a different amount, or added a tip, the invoice balance
        // is recalculated from the payments — the provider is the source of truth.
        $amount  = (float) $evt['amount'];
        $written = PaymentController::record($inv, $amount, 'CARD', [
            'processor'     => $gateway->driverName(),
            'processor_ref' => $evt['reference'],
            'reference'     => $evt['order_id'],
            'note'          => 'Paid online',
        ]);

        ApiLog::write('payment', $gateway->driverName(), 'webhook:payment', $evt['reference'], true,
            $written ? money($amount) . ' recorded against ' . $inv['doc_number']
                     : 'Duplicate callback for ' . $inv['doc_number'] . ' — ignored.');

        self::respond(200, $written ? 'recorded' : 'duplicate');
    }

    /* ---------------------------------------------------------------- */

    /**
     * Consent changes arriving by text. STOP is honoured immediately and
     * unconditionally — it is the one instruction that must never be queued,
     * reviewed or second-guessed.
     */
    public static function inboundSms(array $evt, SmsGateway $gateway): void
    {
        $from = (string) $evt['from'];
        $word = TelnyxSmsGateway::keyword((string) $evt['text']);
        $cust = $from !== '' ? Db::one('SELECT * FROM customers WHERE phone_e164 = ?', [$from]) : null;

        Db::insert('messages', [
            'customer_id'  => $cust['id'] ?? null,
            'direction'    => 'IN',
            'channel'      => 'sms',
            'phone_e164'   => $from,
            'template'     => $word ?: 'reply',
            'body'         => (string) $evt['text'],
            'status'       => 'RECEIVED',
            'provider_ref' => (string) $evt['reference'],
            'created_at'   => now(),
        ]);

        if (!$cust) {
            ApiLog::write('sms', $gateway->driverName(), 'webhook:inbound', $from, true, 'No customer on that number.');
            return;
        }

        if ($word === 'stop') {
            Consent::optOut($cust, 'revoked_by_sms', 'Replied "' . $evt['text'] . '" from ' . $from);
            return;
        }

        if ($word === 'start') {
            Consent::optIn($cust, 'reply_start_by_sms', 'Replied "' . $evt['text'] . '" from ' . $from);
            Sms::queue($cust, 'optin', ['{eta}' => '', '{total}' => '', '{doc}' => '']);
            return;
        }

        if ($word === 'help') {
            Sms::queue($cust, 'help', ['{eta}' => '', '{total}' => '', '{doc}' => '']);
            return;
        }

        Audit::log('customer', (int) $cust['id'], 'sms:reply', (string) $evt['text']);
    }

    /** Delivery receipts. Matched on the provider's own message id. */
    /**
     * How far along a delivery status is. A callback may only move a message
     * FORWARD along this scale.
     *
     * Telnyx states plainly that it does not guarantee webhook ordering, and
     * that message.finalized may arrive before message.sent. The previous
     * version wrote whatever landed last, so a late "sent" callback silently
     * downgraded a message that the carrier had already confirmed as delivered
     * — the record then said SENT for something the customer demonstrably
     * received. Ranking the states and refusing to go backwards makes arrival
     * order irrelevant.
     *
     * DELIVERED and FAILED are terminal and rank equal: whichever the carrier
     * reports first is the truth, and neither is overwritten by the other.
     */
    private const STATUS_RANK = [
        'QUEUED'      => 0,
        'SENT'        => 1,
        'UNCONFIRMED' => 2,   // carrier returned no receipt — unknown, not failed
        'DELIVERED'   => 3,
        'FAILED'      => 3,
    ];

    private static function messageStatus(array $evt, string $driver = 'telnyx'): void
    {
        $ref = (string) $evt['reference'];

        /*
         * Both of the next two returns used to be silent, and that silence was
         * indistinguishable from the receipt never arriving at all: a message
         * sat at SENT, api_log held nothing, and there was no way to tell
         * "Telnyx sent no receipt" apart from "Telnyx sent one we threw away".
         * Those two have completely different causes and completely different
         * fixes, so the difference is worth a row in the log.
         *
         * A receipt for a message this install did not send is not necessarily
         * wrong — another system can share the messaging profile — but it is
         * never uninteresting, because the expected case is that every receipt
         * matches something.
         */
        if ($ref === '') {
            ApiLog::write('sms', $driver, 'webhook:status-noref', '', false,
                'A delivery receipt arrived carrying no message id, so it could not be matched to anything.');
            return;
        }

        $msg = Db::one('SELECT * FROM messages WHERE provider_ref = ?', [$ref]);
        if (!$msg) {
            ApiLog::write('sms', $driver, 'webhook:status-unmatched', $ref, false,
                'A delivery receipt reported ' . (string) $evt['status'] . ' for message ' . $ref
                . ', which matches no row in messages. The receipt was discarded.');
            return;
        }

        $now  = (string) $evt['status'];
        $was  = (string) $msg['status'];
        $rNow = self::STATUS_RANK[$now] ?? 1;
        $rWas = self::STATUS_RANK[$was] ?? 0;

        // Already terminal, and this is not news. Retries land here too, which
        // is what makes the handler idempotent.
        if ($rNow <= $rWas && $was !== 'QUEUED') { return; }

        $stamp = ($evt['occurred'] ?? '') !== '' ? (string) $evt['occurred'] : now();

        $set = ['status' => $now, 'sent_at' => $msg['sent_at'] ?: $stamp];
        if ($now === 'DELIVERED') { $set['delivered_at']   = $stamp; }
        if ($now === 'FAILED')    { $set['failure_reason'] = (string) ($evt['reason'] ?? '') ?: 'no reason given'; }
        if (($evt['carrier'] ?? '') !== '' && ($msg['carrier'] ?? '') === '') {
            $set['carrier'] = (string) $evt['carrier'];
        }

        Db::update('messages', (int) $msg['id'], $set);
    }

    private static function invoiceForOrder(string $orderId): ?array
    {
        if ($orderId === '') { return null; }
        $link = Db::one('SELECT * FROM payment_links WHERE order_id = ?', [$orderId]);
        if (!$link) { return null; }
        return Db::one('SELECT * FROM invoices WHERE id = ?', [(int) $link['invoice_id']]);
    }

    /**
     * Who sent a callback we refused.
     *
     * REMOTE_ADDR is the only address here that cannot be forged: it is the
     * peer the TCP connection actually came from. X-Forwarded-For is a claim
     * made by the sender and is trivially spoofed, so it is recorded as a
     * claim, never as the answer — on shared hosting the real client does sit
     * behind a proxy, which is why it is recorded at all.
     */
    private static function callerIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }

    /**
     * The identifying marks of a refused callback, for the log only.
     *
     * A refusal reading "no Telnyx signature headers" has two completely
     * different causes and no way to tell them apart: either a real Telnyx
     * callback arrived unsigned, because the messaging profile is set to
     * webhook API v1 (v1 is not signed at all), or a stranger probed the
     * endpoint — these routes are the only ones reachable without a session,
     * so they get scanned. The first is an outage; the second is background
     * noise on the internet. Same log line, opposite responses.
     *
     * Three facts separate them: the peer address, the user agent, and WHICH
     * telnyx-* headers did arrive. A v1 callback carries telnyx-* headers but
     * no signature; a scanner carries none at all. Header NAMES only — the
     * values are the untrusted part and there is nothing to learn from
     * recording an attacker's idea of a signature.
     */
    /**
     * Who sent this, and did it even look like the provider it claims to be?
     *
     * MUST BE TOLD WHICH PROVIDER. This used to look for telnyx-* headers
     * unconditionally while being called from both handlers, so every rejected
     * SQUARE callback was logged as having "no telnyx-* headers at all
     * (consistent with a scanner, not with Telnyx)" — a true statement about
     * the wrong provider, printed at exactly the moment somebody is trying to
     * work out why their Square webhook will not verify. It sent the reader to
     * check the wrong integration.
     *
     * The distinction earns its keep: headers present but the signature bad
     * means a real provider call with the wrong key, which is a settings
     * problem. No provider headers at all means the request never came from
     * them, which is a scanner and needs no action.
     */
    private static function origin(string $provider = 'telnyx'): string
    {
        $prefixes = match ($provider) {
            'square' => ['x-square-'],
            default  => ['telnyx-', 'x-telnyx-'],
        };
        $label = match ($provider) {
            'square' => 'Square',
            default  => 'Telnyx',
        };

        $h    = Http::headers();
        $ua   = trim((string) ($h['user-agent'] ?? ''));
        $fwd  = trim((string) ($h['x-forwarded-for'] ?? ''));
        $seen = array_values(array_filter(
            array_keys($h),
            static function (string $k) use ($prefixes): bool {
                foreach ($prefixes as $p) { if (str_starts_with($k, $p)) { return true; } }
                return false;
            }
        ));
        sort($seen);

        $parts = ['from ' . self::callerIp()];
        if ($fwd !== '') { $parts[] = 'claiming X-Forwarded-For ' . substr($fwd, 0, 100); }
        $parts[] = 'UA ' . ($ua !== '' ? '"' . substr($ua, 0, 120) . '"' : 'none');
        $parts[] = $seen
            ? $label . ' headers present (' . implode(', ', $seen)
              . ') — so this looks like a real ' . $label
              . ' call and the signature key is the thing to check'
            : 'no ' . $prefixes[0] . '* headers at all — consistent with a scanner rather than with ' . $label;

        return implode(' · ', $parts);
    }

    private static function body(): string
    {
        return (string) file_get_contents('php://input');
    }

    private static function respond(int $code, string $status): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['status' => $status]);
        exit;
    }
}
