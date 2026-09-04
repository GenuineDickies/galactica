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
 * Implementations of the integration contracts.
 *
 * Two of these talk to a real provider — Telnyx for messaging, Square for card
 * payments. The others are offline drivers: manual payments are a legitimate
 * operating mode (cash and cheques are how some jobs actually pay), while the
 * SMS outbox means texting is NOT CONNECTED — it records what would have been
 * sent and sends nothing. Hand-sending from personal phones is not an
 * operating mode of this business.
 *
 * Whichever driver runs, every call is written to api_log, so the audit trail
 * does not change shape when a provider is switched on. See docs/INTEGRATIONS.md.
 */

/* ==================================================================== */
/* SMS                                                                   */
/* ==================================================================== */

/**
 * Records the message and nothing more. This is "texting is not connected":
 * the row on /messages is a log of what WOULD have been texted, kept so the
 * paper trail is identical once a carrier is switched on. Nobody hand-sends
 * these — the owner's explicit call — so staff-facing messaging around a held
 * send always points at the phone call, never at a personal cell.
 */
final class OutboxSmsGateway implements SmsGateway
{
    public function send(string $to, string $body, array $meta = []): IntegrationResult
    {
        ApiLog::write('sms', $this->driverName(), 'queue', $to, true, 'Recorded only — texting is not connected, nothing was sent.');
        return IntegrationResult::ok('', ['queued' => true]);
    }

    public function driverName(): string { return 'outbox'; }
    public function isLive(): bool { return false; }
    public function verifyWebhook(string $rawBody, array $headers): bool { return false; }
    public function parseWebhook(string $rawBody): ?array { return null; }
}

/**
 * Telnyx Messaging API.
 *
 * Sending is gated twice: the consent check in Sms::gate() runs before this is
 * ever called, and a missing API key or sending number degrades to the outbox
 * rather than failing the request.
 *
 * Callbacks are signed with Ed25519. The public key is per-account and comes
 * from the Telnyx portal; without it, callbacks are refused outright rather
 * than trusted.
 */
final class TelnyxSmsGateway implements SmsGateway
{
    private const API = 'https://api.telnyx.com/v2/messages';

    /**
     * Anything in this list opts the sender out. Required by the carriers.
     *
     * The FCC's per-se revocation list is "stop, quit, end, revoke, opt out,
     * cancel, unsubscribe" — and "opt out" is two words. keyword() therefore
     * matches the leading PHRASE of the message against these lists, so
     * multi-word entries belong here as written. The single-word collapsed
     * forms (OPTOUT, STOPALL) stay for people who type them that way.
     * See docs/10DLC_COMPLIANCE_AUDIT.md P1-C.
     */
    private const STOP  = ['STOP', 'STOP ALL', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'CANCEL SUBSCRIPTION',
                           'END', 'QUIT', 'REVOKE', 'OPT OUT', 'OPTOUT'];
    private const START = ['START', 'UNSTOP', 'YES', 'SUBSCRIBE', 'OPT IN', 'OPTIN'];
    private const HELP  = ['HELP', 'INFO'];

    public function __construct(
        private string $apiKey,
        private string $from,
        private string $publicKey = '',
        private string $profileId = '',
    ) {}

    public function send(string $to, string $body, array $meta = []): IntegrationResult
    {
        if (!$this->isLive()) {
            return IntegrationResult::fail('Telnyx is not configured — add the API key and sending number in Settings.');
        }

        $payload = ['from' => $this->from, 'to' => $to, 'text' => $body];
        if ($this->profileId !== '') { $payload['messaging_profile_id'] = $this->profileId; }
        if ($cb = $this->callbackUrl()) { $payload['webhook_url'] = $cb; }

        $res = Http::json('POST', self::API, ['Authorization' => 'Bearer ' . $this->apiKey], $payload);
        $id  = (string) ($res['body']['data']['id'] ?? '');

        if ($res['status'] >= 200 && $res['status'] < 300 && $id !== '') {
            ApiLog::write('sms', $this->driverName(), 'send', $to, true, 'Accepted by Telnyx as ' . $id);
            return IntegrationResult::ok($id, $res['body']);
        }

        $why = $this->errorText($res);
        ApiLog::write('sms', $this->driverName(), 'send', $to, false, $why);
        return IntegrationResult::fail($why, $res['body']);
    }

    public function driverName(): string { return 'telnyx'; }

    public function isLive(): bool { return $this->apiKey !== '' && $this->from !== ''; }

    /**
     * Ed25519 over "timestamp|payload", as Telnyx signs it. A callback older
     * than five minutes is refused even with a good signature, so a captured
     * request cannot be replayed later.
     */
    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        return $this->verifyReason($rawBody, $headers) === '';
    }

    /**
     * Why a callback was refused, or '' when it verified.
     *
     * verifyWebhook() answers yes or no, which is all the HTTP response is
     * allowed to reveal. This says which of several very different problems it
     * was, for the log only — "the public key is not configured", "this PHP has
     * no sodium extension" and "the signature is wrong" all used to surface as
     * the same bare false, and an operator seeing "signature did not verify"
     * would go hunting for a key problem when the real answer was a missing PHP
     * extension. Silent receipts with no explanation is the failure mode this
     * exists to end.
     *
     * @return string '' on success, otherwise a diagnosable reason
     */
    public function verifyReason(string $rawBody, array $headers): string
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return 'this PHP has no sodium extension, so no callback can ever be verified — enable extension=sodium';
        }
        if ($this->publicKey === '') {
            return 'no Telnyx public key is configured in Settings, so every callback is refused';
        }

        $sig = $headers['telnyx-signature-ed25519'] ?? '';
        $ts  = $headers['telnyx-timestamp'] ?? '';
        if ($sig === '' || $ts === '') {
            return 'the request carried no Telnyx signature headers';
        }
        if (abs(time() - (int) $ts) > 300) {
            return 'the signature timestamp is outside the five-minute window (check the server clock)';
        }

        $signature = base64_decode($sig, true);
        $key       = base64_decode($this->publicKey, true);
        if ($signature === false)  { return 'the signature header is not valid base64'; }
        if ($key === false)        { return 'the configured public key is not valid base64'; }
        if (strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return 'the configured public key is the wrong length for Ed25519 — copy it from Telnyx portal, Keys & Credentials';
        }

        try {
            $ok = sodium_crypto_sign_verify_detached($signature, $ts . '|' . $rawBody, $key);
        } catch (Throwable $e) {
            return 'the signature check threw: ' . $e->getMessage();
        }

        return $ok ? '' : 'the signature did not match the body';
    }

    public function parseWebhook(string $rawBody): ?array
    {
        $d = json_decode($rawBody, true);
        if (!is_array($d)) { return null; }
        $event   = (string) ($d['data']['event_type'] ?? '');
        $payload = $d['data']['payload'] ?? [];

        if ($event === 'message.received') {
            return [
                'kind'      => 'inbound',
                'reference' => (string) ($payload['id'] ?? ''),
                'from'      => (string) ($payload['from']['phone_number'] ?? ''),
                'to'        => (string) ($payload['to'][0]['phone_number'] ?? ''),
                'text'      => trim((string) ($payload['text'] ?? '')),
                'status'    => 'RECEIVED',
            ];
        }

        if (str_starts_with($event, 'message.')) {
            $status = strtolower((string) ($payload['to'][0]['status'] ?? 'sent'));

            return [
                'kind'      => 'status',
                'reference' => (string) ($payload['id'] ?? ''),
                'from'      => (string) ($payload['from']['phone_number'] ?? ''),
                'to'        => (string) ($payload['to'][0]['phone_number'] ?? ''),
                'text'      => '',
                /*
                 * delivery_unconfirmed is NOT a failure. Telnyx defines it as
                 * "no delivery confirmation was received from the carrier" —
                 * plenty of carriers simply never return a receipt, and the
                 * message very probably arrived. Recording it as FAILED, which
                 * this used to do, means staring at a red badge and re-texting
                 * a customer who already got it. It gets its own status.
                 */
                'status'    => match ($status) {
                    'delivered'            => 'DELIVERED',
                    'delivery_unconfirmed' => 'UNCONFIRMED',
                    'sending_failed',
                    'delivery_failed'      => 'FAILED',
                    'queued', 'sending'    => 'QUEUED',
                    default                => 'SENT',
                },
                'carrier'   => (string) ($payload['to'][0]['carrier'] ?? ''),
                // Telnyx timestamps the terminal event; prefer it over our clock.
                'occurred'  => self::stamp((string) ($d['data']['occurred_at'] ?? ''))
                            ?: self::stamp((string) ($payload['completed_at'] ?? '')),
                // "Failed" alone is unactionable. 40002 not in service, 40010
                // blocked as spam and 47000 10DLC-not-registered each demand a
                // completely different response, so the code travels with it.
                'reason'    => self::errorSummary($payload['errors'] ?? []),
            ];
        }

        return null;
    }

    /** Telnyx sends ISO 8601; this app stores 'Y-m-d H:i:s'. '' when unparseable. */
    private static function stamp(string $iso): string
    {
        if (trim($iso) === '') { return ''; }
        try {
            return (new DateTimeImmutable($iso))->setTimezone(
                new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return '';
        }
    }

    /** "40010 Message blocked (spam)" — the first error, flattened for storage. */
    private static function errorSummary(mixed $errors): string
    {
        if (!is_array($errors) || $errors === []) { return ''; }
        $first = is_array($errors[0] ?? null) ? $errors[0] : [];
        $code  = trim((string) ($first['code'] ?? ''));
        $title = trim((string) ($first['title'] ?? ($first['detail'] ?? '')));
        $out   = trim($code . ' ' . $title);
        return $out === '' ? '' : substr($out, 0, 120);
    }

    /** @return string one of 'stop' | 'start' | 'help' | '' */
    /**
     * Which compliance keyword, if any, a reply leads with.
     *
     * Punctuation becomes a space (so "OPT-OUT" reads as "OPT OUT"), runs of
     * whitespace collapse, and the first TWO words are tried as a phrase
     * before the first word alone — longest match wins, so "STOP ALL" is not
     * mistaken for a bare STOP with trailing chatter, and "opt out" is
     * honoured at all (it used to fall through as the unknown word "OPT").
     * Leading-word matching is deliberate: "STOP texting me" is a stop.
     */
    public static function keyword(string $text): string
    {
        $norm  = strtoupper(trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Za-z]+/', ' ', $text) ?? '') ?? ''));
        if ($norm === '') { return ''; }
        $words = explode(' ', $norm);
        $tries = [];
        if (count($words) >= 2) { $tries[] = $words[0] . ' ' . $words[1]; }
        $tries[] = $words[0];
        foreach ($tries as $w) {
            if (in_array($w, self::STOP, true))  { return 'stop'; }
            if (in_array($w, self::START, true)) { return 'start'; }
            if (in_array($w, self::HELP, true))  { return 'help'; }
        }
        return '';
    }

    private function callbackUrl(): string
    {
        $base = Http::baseUrl();
        return str_starts_with($base, 'https://') ? $base . '/webhooks/telnyx' : '';
    }

    private function errorText(array $res): string
    {
        $first = $res['body']['errors'][0] ?? null;
        if (is_array($first)) {
            return trim(($first['title'] ?? 'Telnyx rejected the message') . ' — ' . ($first['detail'] ?? ''), ' —');
        }
        return $res['error'] !== '' ? $res['error'] : 'Telnyx returned HTTP ' . $res['status'];
    }
}

/**
 * What Telnyx believes our messaging configuration is, compared against what
 * this install believes.
 *
 * Rules::smsSend() already reports whether the key, sending number and profile
 * id are filled in HERE. Nothing checked the provider's side, and that is where
 * a whole class of silent failure lives: a profile can look perfect locally and
 * still have its callbacks pointed somewhere that does not exist, or be set to
 * webhook API version 1, which is not signed at all — every callback then
 * arrives unsigned, is refused exactly as it should be, and no delivery receipt
 * is ever recorded. Nothing in the application is wrong; nothing in the
 * application can see it either.
 *
 * This returns findings rather than printing them, because two callers render
 * it — the admin page and the CLI script — and a check that reports differently
 * depending on who asked is worse than no check.
 *
 * Read-only. It changes nothing, here or at Telnyx.
 */
final class TelnyxAudit
{
    private const API = 'https://api.telnyx.com/v2/messaging_profiles?page[size]=100';

    /**
     * @return array{
     *   configured:bool, error:string, install:array<string,mixed>,
     *   profiles:list<array<string,mixed>>, problems:int
     * }
     */
    public static function run(string $apiKey = ''): array
    {
        $apiKey    = $apiKey !== '' ? $apiKey : trim((string) App::setting('telnyx_api_key', ''));
        $baseUrl   = Http::baseUrl();
        $expected  = $baseUrl . '/webhooks/telnyx';
        $profileId = trim((string) App::setting('telnyx_profile_id', ''));
        $publicKey = trim((string) App::setting('telnyx_public_key', ''));

        $install = [
            'base_url'      => $baseUrl,
            'expected_hook' => $expected,
            'profile_id'    => $profileId,
            'has_public_key' => $publicKey !== '',
            'has_sodium'    => function_exists('sodium_crypto_sign_verify_detached'),
            'driver'        => (string) App::setting('driver_sms', 'outbox'),
        ];

        $out = ['configured' => $apiKey !== '', 'error' => '', 'install' => $install,
                'profiles' => [], 'problems' => 0];

        if ($apiKey === '') {
            $out['error'] = 'No Telnyx API key is configured, so the provider cannot be asked anything.';
            return $out;
        }

        $res = Http::json('GET', self::API, ['Authorization' => 'Bearer ' . $apiKey]);

        if ($res['status'] === 401) {
            $out['error'] = 'Telnyx rejected the API key (HTTP 401) — it is wrong, revoked, or from another account.';
            $out['problems']++;
            return $out;
        }
        if ($res['status'] < 200 || $res['status'] >= 300) {
            $out['error'] = 'Telnyx returned HTTP ' . $res['status']
                . ($res['error'] !== '' ? ' — ' . $res['error'] : '');
            $out['problems']++;
            return $out;
        }

        $profiles = $res['body']['data'] ?? [];
        if (!$profiles) {
            $out['error'] = 'The Telnyx account has no messaging profiles at all.';
            $out['problems']++;
            return $out;
        }

        foreach ($profiles as $p) {
            $id       = (string) ($p['id'] ?? '');
            $ours     = $profileId !== '' && $id === $profileId;
            $version  = (string) ($p['webhook_api_version'] ?? '');
            $hook     = (string) ($p['webhook_url'] ?? '');
            $failover = (string) ($p['webhook_failover_url'] ?? '');
            $findings = [];

            // Only v2 is signed. v1 and the legacy 2010-04-01 format are not,
            // so every callback from them is refused before it is read.
            if ($version === '2') {
                $findings[] = ['level' => 'ok', 'text' => 'Webhook API version 2 — callbacks are signed.'];
            } else {
                $findings[] = ['level' => 'bad',
                    'text' => 'Webhook API version ' . ($version !== '' ? $version : 'unset')
                            . ' — NOT signed, so every delivery receipt from this profile is refused.',
                    'fix'  => 'Telnyx portal → Messaging → Messaging Profiles → this profile → set Webhook API Version to 2.'];
            }

            if ($hook === '') {
                $findings[] = ['level' => 'warn',
                    'text' => 'No webhook URL on the profile. Messages this app sends still report back — it '
                            . 'passes a per-message webhook_url — but anything sent outside the app has nowhere to report.',
                    'fix'  => 'Set the profile webhook URL to ' . $expected];
            } elseif (rtrim($hook, '/') === rtrim($expected, '/')) {
                $findings[] = ['level' => 'ok', 'text' => 'Webhook URL matches this install.'];
            } else {
                $findings[] = ['level' => $ours ? 'bad' : 'warn',
                    'text' => 'Webhook URL is ' . $hook . ', but this install answers at ' . $expected . '.',
                    'fix'  => 'Point it at ' . $expected . ', or correct the base URL in Settings.'];
            }

            // Failover is where a retried receipt goes when the first attempt
            // fails. Pointed at a URL that does not exist, those receipts are
            // lost silently — the provider never reports back that it gave up.
            if ($failover !== '' && rtrim($failover, '/') !== rtrim($expected, '/')) {
                $findings[] = ['level' => $ours ? 'bad' : 'warn',
                    'text' => 'Failover URL is ' . $failover . '. Retried receipts go there when the first '
                            . 'attempt fails, and are lost if nothing answers.',
                    'fix'  => 'Point the failover URL at ' . $expected . ' too.'];
            }

            foreach ($findings as $f) { if ($f['level'] === 'bad') { $out['problems']++; } }

            $out['profiles'][] = [
                'id' => $id, 'name' => (string) ($p['name'] ?? '(unnamed)'),
                'is_ours' => $ours, 'version' => $version, 'webhook_url' => $hook,
                'failover_url' => $failover, 'findings' => $findings,
            ];
        }

        if ($profileId !== '' && !array_filter($out['profiles'], static fn ($p) => $p['is_ours'])) {
            $out['problems']++;
            $out['error'] = 'The messaging profile id in Settings matches nothing on this account: ' . $profileId;
        }

        return $out;
    }
}

/* ==================================================================== */
/* Payments                                                              */
/* ==================================================================== */

/**
 * Payment taken at the till and recorded by hand — cash, cheque, terminal,
 * remittance. This path never goes away, whatever else is configured.
 *
 * It also issues *local* payment links: a checkout page served by this
 * application rather than by a processor. That is not a placeholder — it is how
 * the whole link flow (issue → text → customer pays → payment recorded →
 * receipt) can be walked end to end before any merchant account exists, and it
 * runs through exactly the same recording path a real callback does.
 */
final class ManualPaymentGateway implements PaymentGateway
{
    public function charge(float $amount, string $idempotencyKey, array $meta = []): IntegrationResult
    {
        ApiLog::write('payment', $this->driverName(), 'charge', $idempotencyKey, true, money($amount) . ' recorded by hand');
        return IntegrationResult::ok($idempotencyKey);
    }

    public function paymentLink(float $amount, string $idempotencyKey, array $meta = []): IntegrationResult
    {
        // Unguessable, because the page it opens is public.
        $token = 'sim_' . bin2hex(random_bytes(16));
        $url   = Http::baseUrl() . '/pay/' . $token;

        ApiLog::write('payment', $this->driverName(), 'link', $token, true,
            money($amount) . ' local checkout page for ' . (string) ($meta['reference'] ?? ''));

        return IntegrationResult::ok($token, ['url' => $url, 'link_id' => $token, 'order_id' => $token]);
    }

    public function refund(string $paymentReference, float $amount, string $idempotencyKey): IntegrationResult
    {
        ApiLog::write('payment', $this->driverName(), 'refund', $paymentReference, true, money($amount) . ' recorded by hand');
        return IntegrationResult::ok($idempotencyKey);
    }

    public function driverName(): string { return 'manual'; }
    public function isLive(): bool { return false; }
    public function verifyWebhook(string $rawBody, array $headers): bool { return false; }
    public function parseWebhook(string $rawBody): ?array { return null; }
}

/**
 * Square.
 *
 * Two paths. paymentLink() creates a hosted checkout page — the normal way a
 * roadside customer pays, since the technician has no terminal and the invoice
 * is often settled after the van has left. charge() takes a tokenised card for
 * the case where one is on file.
 *
 * Money is sent in minor units, as an integer, because Square rejects floats
 * and because rounding a float at the API boundary is how cents go missing.
 */
final class SquarePaymentGateway implements PaymentGateway
{
    public function __construct(
        private string $accessToken,
        private string $locationId,
        private string $environment = 'sandbox',
        private string $signatureKey = '',
    ) {}

    private function base(): string
    {
        return $this->environment === 'production'
            ? 'https://connect.squareup.com'
            : 'https://connect.squareupsandbox.com';
    }

    private function headers(): array
    {
        return [
            'Authorization'  => 'Bearer ' . $this->accessToken,
            'Square-Version' => '2025-01-23',
        ];
    }

    public function charge(float $amount, string $idempotencyKey, array $meta = []): IntegrationResult
    {
        if (!$this->isLive()) {
            return IntegrationResult::fail('Square is not configured — add the access token and location id in Settings.');
        }
        $source = (string) ($meta['source_id'] ?? '');
        if ($source === '') {
            return IntegrationResult::fail('No card token supplied. Use a payment link instead.');
        }

        $res = Http::json('POST', $this->base() . '/v2/payments', $this->headers(), [
            'source_id'       => $source,
            'idempotency_key' => $idempotencyKey,
            'amount_money'    => ['amount' => self::minor($amount), 'currency' => 'USD'],
            'location_id'     => $this->locationId,
            'reference_id'    => (string) ($meta['reference'] ?? ''),
            'note'            => substr((string) ($meta['note'] ?? ''), 0, 500),
        ]);

        $id = (string) ($res['body']['payment']['id'] ?? '');
        if ($res['status'] >= 200 && $res['status'] < 300 && $id !== '') {
            ApiLog::write('payment', $this->driverName(), 'charge', $id, true, money($amount) . ' captured');
            return IntegrationResult::ok($id, $res['body']);
        }

        $why = $this->errorText($res);
        ApiLog::write('payment', $this->driverName(), 'charge', $idempotencyKey, false, $why);
        return IntegrationResult::fail($why, $res['body']);
    }

    public function paymentLink(float $amount, string $idempotencyKey, array $meta = []): IntegrationResult
    {
        if (!$this->isLive()) {
            return IntegrationResult::fail('Square is not configured — add the access token and location id in Settings.');
        }

        $ref  = (string) ($meta['reference'] ?? '');
        $name = (string) ($meta['name'] ?? 'Roadside service');

        $res = Http::json('POST', $this->base() . '/v2/online-checkout/payment-links', $this->headers(), [
            'idempotency_key' => $idempotencyKey,
            'quick_pay'       => [
                'name'        => substr($name, 0, 255),
                'price_money' => ['amount' => self::minor($amount), 'currency' => 'USD'],
                'location_id' => $this->locationId,
            ],
            'checkout_options' => [
                'allow_tipping'          => true,
                'redirect_url'           => (string) ($meta['redirect'] ?? ''),
                'ask_for_shipping_address' => false,
            ],
            'payment_note' => $ref,
        ]);

        $link  = $res['body']['payment_link'] ?? [];
        $url   = (string) ($link['url'] ?? '');
        $order = (string) ($link['order_id'] ?? '');

        if ($res['status'] >= 200 && $res['status'] < 300 && $url !== '') {
            ApiLog::write('payment', $this->driverName(), 'link', $order, true, money($amount) . ' link created for ' . $ref);
            return IntegrationResult::ok($order, ['url' => $url, 'link_id' => (string) ($link['id'] ?? ''), 'order_id' => $order]);
        }

        $why = $this->errorText($res);
        ApiLog::write('payment', $this->driverName(), 'link', $ref, false, $why);
        return IntegrationResult::fail($why, $res['body']);
    }

    public function refund(string $paymentReference, float $amount, string $idempotencyKey): IntegrationResult
    {
        if (!$this->isLive()) {
            return IntegrationResult::fail('Square is not configured.');
        }
        $res = Http::json('POST', $this->base() . '/v2/refunds', $this->headers(), [
            'idempotency_key' => $idempotencyKey,
            'payment_id'      => $paymentReference,
            'amount_money'    => ['amount' => self::minor($amount), 'currency' => 'USD'],
        ]);

        $id = (string) ($res['body']['refund']['id'] ?? '');
        if ($res['status'] >= 200 && $res['status'] < 300 && $id !== '') {
            ApiLog::write('payment', $this->driverName(), 'refund', $id, true, money($amount) . ' refunded');
            return IntegrationResult::ok($id, $res['body']);
        }

        $why = $this->errorText($res);
        ApiLog::write('payment', $this->driverName(), 'refund', $paymentReference, false, $why);
        return IntegrationResult::fail($why, $res['body']);
    }

    public function driverName(): string { return 'square'; }

    public function isLive(): bool { return $this->accessToken !== '' && $this->locationId !== ''; }

    /**
     * HMAC-SHA256 over the exact notification URL concatenated with the raw
     * body, compared in constant time. The URL is part of the signed material,
     * so a callback captured from one install cannot be replayed against another.
     */
    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        /* CHECKED AGAINST Square\Utils\WebhooksHelper::verifySignature in the
         * official square/square-php-sdk (MIT). The algorithm below is
         * identical to theirs: HMAC-SHA256 over notificationUrl . body, keyed
         * with the signature key, base64-encoded, compared to the header.
         *
         * Two deliberate differences, both in this implementation's favour:
         *
         *   1. hash_equals(), not ===. Square's helper compares the base64
         *      strings with ===, which short-circuits on the first differing
         *      byte and so leaks, in its timing, how much of a forged
         *      signature was correct. hash_equals is constant-time. This is
         *      the whole reason the function exists and it is not worth
         *      matching the SDK to lose it.
         *
         *   2. Empty key returns FALSE rather than throwing. Their helper
         *      raises an exception; in a webhook handler that becomes a 500,
         *      and a 500 tells the provider to retry — forever, for a
         *      misconfiguration no retry can fix. Refusing quietly is right
         *      here, and the caller logs why.
         *
         * Adopted from theirs: the explicit empty-body guard. An empty body
         * cannot be a legitimate Square notification, and rejecting it by name
         * is clearer than letting it fail the hash by accident. */
        if ($this->signatureKey === '') { return false; }
        if ($rawBody === '')            { return false; }

        $given = $headers['x-square-hmacsha256-signature'] ?? '';
        if ($given === '') { return false; }

        $expected = base64_encode(hash_hmac('sha256', self::notificationUrl() . $rawBody, $this->signatureKey, true));
        return hash_equals($expected, $given);
    }

    public function parseWebhook(string $rawBody): ?array
    {
        $d = json_decode($rawBody, true);
        if (!is_array($d)) { return null; }
        $type = (string) ($d['type'] ?? '');

        /* location_id is carried out so the handler can tell OUR traffic from
         * everything else in the account. Square delivers callbacks for the
         * whole merchant account, not for one location. */
        if (str_starts_with($type, 'payment.')) {
            $p = $d['data']['object']['payment'] ?? [];
            return [
                'kind'        => 'payment',
                'reference'   => (string) ($p['id'] ?? ''),
                'order_id'    => (string) ($p['order_id'] ?? ''),
                'location_id' => (string) ($p['location_id'] ?? ($d['data']['object']['location_id'] ?? '')),
                'amount'      => ((int) ($p['amount_money']['amount'] ?? 0)) / 100,
                'status'      => strtoupper((string) ($p['status'] ?? '')),
                'method'      => 'CARD',
            ];
        }

        if (str_starts_with($type, 'refund.')) {
            $r = $d['data']['object']['refund'] ?? [];
            return [
                'kind'        => 'refund',
                'reference'   => (string) ($r['id'] ?? ''),
                'order_id'    => (string) ($r['order_id'] ?? ''),
                'location_id' => (string) ($r['location_id'] ?? ($d['data']['object']['location_id'] ?? '')),
                'amount'      => ((int) ($r['amount_money']['amount'] ?? 0)) / 100,
                'status'      => strtoupper((string) ($r['status'] ?? '')),
                'method'      => 'CARD',
            ];
        }

        return null;
    }

    /**
     * Is this callback about OUR location?
     *
     * Square sends webhooks for every location on the merchant account. Until
     * this existed, the handler ran business logic on all of them and only
     * failed to act on foreign ones because their order id happened not to
     * match a row in payment_links — a boundary made of coincidence rather
     * than intent, in an account that also carries the owner's personal
     * activity.
     *
     * Unknown location is treated as NOT ours. Refusing to act on a payment we
     * cannot place is the safe direction: the cost is a callback logged and
     * ignored, where the cost of the opposite is somebody else's money landing
     * on a customer's invoice.
     *
     * When no location is configured at all the check cannot be made, so it
     * passes — an operator who has not finished setting up should not have
     * their own payments silently dropped.
     *
     * $ours is a parameter rather than a settings read because App::setting()
     * caches for the life of the process; a function that reached for it
     * directly could not be tested against more than one location per run.
     * Production callers pass null and get the configured value.
     */
    public static function isOurLocation(array $evt, ?string $ours = null): bool
    {
        $ours = trim($ours ?? (string) App::setting('square_location_id', ''));
        if ($ours === '') { return true; }

        $theirs = trim((string) ($evt['location_id'] ?? ''));
        if ($theirs === '') { return false; }

        return $ours === $theirs;
    }

    public static function notificationUrl(): string
    {
        return Http::baseUrl() . '/webhooks/square';
    }

    /** Dollars to cents, rounded once, as an integer. */
    private static function minor(float $amount): int
    {
        return (int) round($amount * 100);
    }

    private function errorText(array $res): string
    {
        $first = $res['body']['errors'][0] ?? null;
        if (is_array($first)) {
            return trim(($first['code'] ?? 'SQUARE_ERROR') . ' — ' . ($first['detail'] ?? ''), ' —');
        }
        return $res['error'] !== '' ? $res['error'] : 'Square returned HTTP ' . $res['status'];
    }
}

/* ==================================================================== */
/* VIN + geocoding                                                       */
/* ==================================================================== */

/**
 * Decodes what the VIN itself encodes: world manufacturer identifier, build
 * region and model year. No paid service, no network call, no API key.
 */
final class StructuralVinDecoder implements VinDecoder
{
    private const YEARS = ['A'=>2010,'B'=>2011,'C'=>2012,'D'=>2013,'E'=>2014,'F'=>2015,'G'=>2016,'H'=>2017,
                           'J'=>2018,'K'=>2019,'L'=>2020,'M'=>2021,'N'=>2022,'P'=>2023,'R'=>2024,'S'=>2025,
                           'T'=>2026,'V'=>2027,'W'=>2028,'X'=>2029,'Y'=>2030,'1'=>2001,'2'=>2002,'3'=>2003,
                           '4'=>2004,'5'=>2005,'6'=>2006,'7'=>2007,'8'=>2008,'9'=>2009];

    private const REGIONS = ['1'=>'United States','4'=>'United States','5'=>'United States','2'=>'Canada',
                             '3'=>'Mexico','J'=>'Japan','K'=>'South Korea','L'=>'China','S'=>'United Kingdom',
                             'V'=>'France / Spain','W'=>'Germany','Y'=>'Sweden / Finland','Z'=>'Italy'];

    public function decode(string $vin): array
    {
        $vin = strtoupper(trim($vin));
        if (!vin_is_valid($vin)) { return []; }
        return [
            'wmi'          => substr($vin, 0, 3),
            'country_hint' => self::REGIONS[$vin[0]] ?? 'Unknown',
            'model_year'   => self::YEARS[$vin[9]] ?? null,
            'serial'       => substr($vin, 11),
            'source'       => $this->driverName(),
            'decoded_at'   => now(),
        ];
    }

    public function driverName(): string { return 'structural'; }
}

/** No geocoding service configured — coordinates come from the customer's phone or by hand. */
final class ManualGeocoder implements Geocoder
{
    public function geocode(string $address): array
    {
        return ['lat' => null, 'lng' => null, 'formatted' => $address, 'confidence' => 'manual'];
    }

    public function reverse(float $lat, float $lng): array
    {
        return ['address' => null, 'intersection' => null,
                'city' => null, 'state' => null, 'postal_code' => null, 'confidence' => 'manual'];
    }

    public function driverName(): string { return 'manual'; }
}

/**
 * OpenStreetMap — Nominatim for addresses, Overpass for intersections.
 *
 * The default driver because it needs no account and no key: a fresh install
 * can turn coordinates into "1234 SE Stark St" and "SE Stark St & SE 122nd Ave"
 * on day one. Both services ask for an identifying User-Agent and modest
 * volume — a roadside operation's call rate is far inside their fair-use
 * policy. A lookup that fails, times out, or finds nothing degrades to null;
 * the coordinates were saved before either call was made.
 */
final class OsmGeocoder implements Geocoder
{
    private const NOMINATIM = 'https://nominatim.openstreetmap.org';
    private const OVERPASS  = 'https://overpass-api.de/api/interpreter';

    /** How far to look for cross-streets. Past ~300 m it is no longer "the nearest intersection". */
    private const INTERSECTION_RADIUS_M = 300;

    public function geocode(string $address): array
    {
        if (trim($address) === '') {
            return ['lat' => null, 'lng' => null, 'formatted' => $address, 'confidence' => 'none'];
        }
        $res = Http::json('GET', self::NOMINATIM . '/search?' . http_build_query([
            'q' => $address, 'format' => 'jsonv2', 'limit' => 1, 'countrycodes' => 'us',
        ]), self::headers());
        $top = $res['body'][0] ?? null;

        ApiLog::write('geocode', $this->driverName(), 'geocode', $address,
            is_array($top), is_array($top) ? (string) ($top['display_name'] ?? '') : (string) ($res['error'] ?? 'no result'));

        if (!is_array($top)) {
            return ['lat' => null, 'lng' => null, 'formatted' => $address, 'confidence' => 'none'];
        }
        return [
            'lat'        => isset($top['lat']) ? (float) $top['lat'] : null,
            'lng'        => isset($top['lon']) ? (float) $top['lon'] : null,
            'formatted'  => (string) ($top['display_name'] ?? $address),
            'confidence' => (string) ($top['type'] ?? 'APPROXIMATE'),
        ];
    }

    public function reverse(float $lat, float $lng): array
    {
        $near         = $this->nearestAddress($lat, $lng);
        $address      = $near['line'];
        $intersection = $this->nearestIntersection($lat, $lng);

        ApiLog::write('geocode', $this->driverName(), 'reverse',
            sprintf('%.7F,%.7F', $lat, $lng),
            $address !== null || $intersection !== null,
            trim(($address ?? '—') . ' · ' . ($intersection ?? '—')));

        return [
            'address'      => $address,
            'intersection' => $intersection,
            'city'         => $near['city'],
            'state'        => $near['state'],
            'postal_code'  => $near['postal_code'],
            'confidence'   => $address !== null ? 'osm' : 'none',
        ];
    }

    /** @return array{line:?string,city:?string,state:?string,postal_code:?string} */
    private function nearestAddress(float $lat, float $lng): array
    {
        $none = ['line' => null, 'city' => null, 'state' => null, 'postal_code' => null];

        $res = Http::json('GET', self::NOMINATIM . '/reverse?' . http_build_query([
            'lat' => sprintf('%.7F', $lat), 'lon' => sprintf('%.7F', $lng),
            'format' => 'jsonv2', 'zoom' => 18, 'addressdetails' => 1,
        ]), self::headers());

        $body = $res['body'] ?? [];
        if (!is_array($body) || empty($body['address'])) { return $none; }
        $a = $body['address'];

        // Compose a street-level line rather than Nominatim's full display_name,
        // which leads with the county and country a dispatcher does not need.
        $street = trim(((string) ($a['house_number'] ?? '')) . ' ' . ((string) ($a['road'] ?? '')));
        $city   = (string) ($a['city'] ?? $a['town'] ?? $a['village'] ?? $a['suburb'] ?? '');
        $line   = implode(', ', array_filter([
            $street !== '' ? $street : null,
            $city !== '' ? $city : null,
            trim(((string) ($a['state'] ?? '')) . ' ' . ((string) ($a['postcode'] ?? ''))) ?: null,
        ]));

        // "US-OR" from ISO3166-2-lvl4 when present; the state's name otherwise.
        $iso   = (string) ($a['ISO3166-2-lvl4'] ?? '');
        $state = str_starts_with($iso, 'US-') ? substr($iso, 3) : us_state_abbrev((string) ($a['state'] ?? ''));

        return [
            'line'        => $line !== '' ? $line : ((string) ($body['display_name'] ?? '') ?: null),
            'city'        => $city !== '' ? $city : null,
            'state'       => $state !== '' ? $state : null,
            'postal_code' => ($a['postcode'] ?? '') !== '' ? (string) $a['postcode'] : null,
        ];
    }

    /**
     * Nearest crossing of two differently-named roads. One Overpass call pulls
     * every named highway within the radius plus its node geometry; any node
     * shared by two distinct road names is an intersection. Closest one wins.
     */
    private function nearestIntersection(float $lat, float $lng): ?string
    {
        $q = sprintf(
            '[out:json][timeout:10];way(around:%d,%.7F,%.7F)["highway"]["name"];(._;>;);out body;',
            self::INTERSECTION_RADIUS_M, $lat, $lng
        );
        $res  = Http::json('GET', self::OVERPASS . '?' . http_build_query(['data' => $q]), self::headers());
        $elems = $res['body']['elements'] ?? null;
        if (!is_array($elems)) { return null; }

        $nodes = []; $names = [];   // node id → [lat,lng] · node id → set of road names
        foreach ($elems as $el) {
            if (($el['type'] ?? '') === 'node') {
                $nodes[(int) $el['id']] = [(float) $el['lat'], (float) $el['lon']];
            }
        }
        foreach ($elems as $el) {
            if (($el['type'] ?? '') !== 'way') { continue; }
            $name = trim((string) ($el['tags']['name'] ?? ''));
            if ($name === '') { continue; }
            foreach ($el['nodes'] ?? [] as $nid) { $names[(int) $nid][$name] = true; }
        }

        $best = null; $bestDist = PHP_FLOAT_MAX;
        foreach ($names as $nid => $set) {
            if (count($set) < 2 || !isset($nodes[$nid])) { continue; }
            $d = self::metres($lat, $lng, $nodes[$nid][0], $nodes[$nid][1]);
            if ($d < $bestDist) { $bestDist = $d; $best = array_keys($set); }
        }
        if ($best === null) { return null; }
        sort($best);
        return implode(' & ', array_slice($best, 0, 2));
    }

    /** Haversine, close enough at street scale. */
    private static function metres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000.0;
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2
           + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;
        return 2 * $r * asin(sqrt($a));
    }

    /** @return array<string,string> Nominatim and Overpass both ask callers to identify themselves. */
    private static function headers(): array
    {
        return ['User-Agent' => 'WKR-Admin/1.0 (roadside dispatch; ' . Http::baseUrl() . ')'];
    }

    public function driverName(): string { return 'osm'; }
}

/** Google Maps Geocoding. */
final class GoogleGeocoder implements Geocoder
{
    private const API = 'https://maps.googleapis.com/maps/api/geocode/json';

    public function __construct(private string $apiKey) {}

    public function geocode(string $address): array
    {
        if ($this->apiKey === '' || trim($address) === '') {
            return ['lat' => null, 'lng' => null, 'formatted' => $address, 'confidence' => 'unconfigured'];
        }

        $url = self::API . '?' . http_build_query(['address' => $address, 'key' => $this->apiKey]);
        $res = Http::json('GET', $url);
        $top = $res['body']['results'][0] ?? null;

        if (!is_array($top)) {
            ApiLog::write('geocode', $this->driverName(), 'geocode', $address, false,
                (string) ($res['body']['status'] ?? 'no result'));
            return ['lat' => null, 'lng' => null, 'formatted' => $address, 'confidence' => 'none'];
        }

        ApiLog::write('geocode', $this->driverName(), 'geocode', $address, true, (string) ($top['formatted_address'] ?? ''));
        return [
            'lat'        => isset($top['geometry']['location']['lat']) ? (float) $top['geometry']['location']['lat'] : null,
            'lng'        => isset($top['geometry']['location']['lng']) ? (float) $top['geometry']['location']['lng'] : null,
            'formatted'  => (string) ($top['formatted_address'] ?? $address),
            'confidence' => (string) ($top['geometry']['location_type'] ?? 'APPROXIMATE'),
        ];
    }

    public function reverse(float $lat, float $lng): array
    {
        if ($this->apiKey === '') {
            return ['address' => null, 'intersection' => null,
                    'city' => null, 'state' => null, 'postal_code' => null, 'confidence' => 'unconfigured'];
        }
        $ll = sprintf('%.7F,%.7F', $lat, $lng);

        // Two lookups because they are two questions: the nearest rooftop
        // address, and the nearest crossing Google knows as an intersection.
        $addr = Http::json('GET', self::API . '?' . http_build_query([
            'latlng' => $ll, 'result_type' => 'street_address|premise|route', 'key' => $this->apiKey,
        ]));
        $cross = Http::json('GET', self::API . '?' . http_build_query([
            'latlng' => $ll, 'result_type' => 'intersection', 'key' => $this->apiKey,
        ]));

        $address      = isset($addr['body']['results'][0]['formatted_address'])
                      ? (string) $addr['body']['results'][0]['formatted_address'] : null;
        $intersection = null;
        if (isset($cross['body']['results'][0])) {
            $top = $cross['body']['results'][0];
            foreach (($top['address_components'] ?? []) as $c) {
                if (in_array('intersection', $c['types'] ?? [], true)) { $intersection = (string) $c['long_name']; break; }
            }
            $intersection = $intersection ?? (string) ($top['formatted_address'] ?? '') ?: null;
        }

        // The structured pieces of the same address, straight from Google's
        // components — short_name gives the 2-letter state directly.
        $city = null; $state = null; $zip = null;
        foreach (($addr['body']['results'][0]['address_components'] ?? []) as $c) {
            $types = $c['types'] ?? [];
            if (in_array('locality', $types, true))                    { $city  = (string) $c['long_name']; }
            if (in_array('administrative_area_level_1', $types, true)) { $state = (string) $c['short_name']; }
            if (in_array('postal_code', $types, true))                 { $zip   = (string) $c['long_name']; }
        }

        ApiLog::write('geocode', $this->driverName(), 'reverse', $ll,
            $address !== null || $intersection !== null,
            trim(($address ?? '—') . ' · ' . ($intersection ?? '—')));

        return [
            'address'      => $address,
            'intersection' => $intersection,
            'city'         => $city,
            'state'        => $state,
            'postal_code'  => $zip,
            'confidence'   => $address !== null ? (string) ($addr['body']['results'][0]['geometry']['location_type'] ?? 'APPROXIMATE') : 'none',
        ];
    }

    public function driverName(): string { return 'google'; }
}

/* ==================================================================== */

/**
 * Routing — actual drive miles and minutes from the truck to the job pin.
 *
 * The offline driver answers nothing, on purpose: with no provider connected
 * the ETA is whatever the dispatcher types, exactly as before. The Google
 * driver uses the Routes API — a separate product from Geocoding, which must
 * be enabled on the same google_maps_key in the Google Cloud console.
 */
final class OfflineRoutePlanner implements RoutePlanner
{
    public function route(float $fromLat, float $fromLng, float $toLat, float $toLng): array
    {
        return ['ok' => false, 'miles' => null, 'minutes' => null,
                'reason' => 'No routing provider is connected — enter the ETA by hand, or switch the routing driver in Settings.'];
    }

    public function driverName(): string { return 'offline'; }
}

final class GoogleRoutePlanner implements RoutePlanner
{
    private const API = 'https://routes.googleapis.com/directions/v2:computeRoutes';

    public function __construct(private string $apiKey) {}

    public function route(float $fromLat, float $fromLng, float $toLat, float $toLng): array
    {
        if ($this->apiKey === '') {
            return ['ok' => false, 'miles' => null, 'minutes' => null,
                    'reason' => 'No Google Maps API key is stored in Settings.'];
        }

        $res = Http::json('POST', self::API, [
            'X-Goog-Api-Key'   => $this->apiKey,
            // Routes API bills by response field — ask for exactly what is kept.
            'X-Goog-FieldMask' => 'routes.distanceMeters,routes.duration',
        ], [
            'origin'      => ['location' => ['latLng' => ['latitude' => $fromLat, 'longitude' => $fromLng]]],
            'destination' => ['location' => ['latLng' => ['latitude' => $toLat, 'longitude' => $toLng]]],
            'travelMode'  => 'DRIVE',
            // Live traffic baked into the duration — the point is a real ETA.
            'routingPreference' => 'TRAFFIC_AWARE',
        ]);

        $top = $res['body']['routes'][0] ?? null;
        $ref = sprintf('%.5F,%.5F -> %.5F,%.5F', $fromLat, $fromLng, $toLat, $toLng);

        if (!is_array($top) || !isset($top['distanceMeters'], $top['duration'])) {
            $why = (string) ($res['body']['error']['message'] ?? ($res['error'] !== '' ? $res['error'] : 'no route in the response'));
            ApiLog::write('routes', $this->driverName(), 'route', $ref, false, $why);
            return ['ok' => false, 'miles' => null, 'minutes' => null,
                    'reason' => 'Google could not plot the route: ' . $why];
        }

        // duration arrives as seconds with an "s" suffix, e.g. "1174s".
        $miles   = round(((int) $top['distanceMeters']) / 1609.344, 1);
        $minutes = (int) max(1, ceil(((float) rtrim((string) $top['duration'], 's')) / 60));
        ApiLog::write('routes', $this->driverName(), 'route', $ref, true, $miles . ' mi · ' . $minutes . ' min');
        return ['ok' => true, 'miles' => $miles, 'minutes' => $minutes, 'reason' => ''];
    }

    public function driverName(): string { return 'google'; }
}

/* ==================================================================== */

/** Append-only record of every integration call, whichever driver handled it. */
final class ApiLog
{
    public static function write(string $service, string $driver, string $op, string $ref, bool $ok, string $detail = ''): void
    {
        Db::insert('api_log', [
            'service'    => $service,
            'driver'     => $driver,
            'operation'  => $op,
            'reference'  => substr($ref, 0, 120),
            'ok'         => $ok ? 1 : 0,
            'detail'     => $detail,
            'created_at' => now(),
        ]);
    }
}

/**
 * Chooses a driver per service from config + settings. This is the only place
 * that knows which implementation is in use.
 */
final class Integrations
{
    private static array $made = [];

    /**
     * config.php sets the default; a setting overrides it.
     *
     * The override exists because the production host has no SSH — changing a
     * driver by editing config.php there means an FTP round trip, and an
     * operator switching a provider on should not need one.
     */
    public static function driver(string $slot, string $fallback): string
    {
        $chosen = trim((string) App::setting('driver_' . $slot, ''));
        return $chosen !== '' ? $chosen : (string) (App::config('integrations')[$slot] ?? $fallback);
    }

    public static function sms(): SmsGateway
    {
        return self::$made['sms'] ??= match (self::driver('sms', 'outbox')) {
            'telnyx' => new TelnyxSmsGateway(
                (string) App::setting('telnyx_api_key', ''),
                (string) App::setting('telnyx_from', ''),
                (string) App::setting('telnyx_public_key', ''),
                (string) App::setting('telnyx_profile_id', ''),
            ),
            default  => new OutboxSmsGateway(),
        };
    }

    public static function payments(): PaymentGateway
    {
        return self::$made['pay'] ??= match (self::driver('payments', 'manual')) {
            'square' => new SquarePaymentGateway(
                (string) App::setting('square_access_token', ''),
                (string) App::setting('square_location_id', ''),
                (string) App::setting('square_environment', 'sandbox'),
                (string) App::setting('square_signature_key', ''),
            ),
            default  => new ManualPaymentGateway(),
        };
    }

    public static function vin(): VinDecoder
    {
        return self::$made['vin'] ??= new StructuralVinDecoder();
    }

    public static function geocoder(): Geocoder
    {
        return self::$made['geo'] ??= match (self::driver('geocoder', 'osm')) {
            'google' => new GoogleGeocoder((string) App::setting('google_maps_key', '')),
            'manual' => new ManualGeocoder(),
            default  => new OsmGeocoder(),
        };
    }

    public static function routes(): RoutePlanner
    {
        return self::$made['routes'] ??= match (self::driver('routes', 'offline')) {
            'google' => new GoogleRoutePlanner((string) App::setting('google_maps_key', '')),
            default  => new OfflineRoutePlanner(),
        };
    }

    /** Lets tests and the webhook controller substitute a driver. */
    public static function swap(string $slot, object $driver): void
    {
        self::$made[$slot] = $driver;
    }

    /**
     * For the Settings screen.
     *
     * Three states, not two. A driver that talks to nobody by design — the
     * outbox, manual payments, offline VIN decoding — is *working*, and saying
     * so matters: an operator who reads "needs credentials" against a driver
     * that needs none will go looking for a key that does not exist.
     */
    public static function status(): array
    {
        $needs = fn (bool $live) => $live ? ['Live', 'success'] : ['Needs credentials', 'warn'];
        $local = fn () => ['Working offline', 'slate'];

        $sms  = self::sms();
        $pay  = self::payments();
        $geo  = self::geocoder();

        return [
            ['service' => 'SMS', 'driver' => $sms->driverName(),
             'state'   => $sms->driverName() === 'outbox' ? ['Held in outbox', 'slate'] : $needs($sms->isLive()),
             'hook'    => $sms->driverName() === 'telnyx' ? Http::baseUrl() . '/webhooks/telnyx' : ''],

            ['service' => 'Payments', 'driver' => $pay->driverName(),
             'state'   => $pay->driverName() === 'manual' ? ['Taken at the till', 'slate'] : $needs($pay->isLive()),
             'hook'    => $pay->driverName() === 'square' ? Http::baseUrl() . '/webhooks/square' : ''],

            ['service' => 'VIN decode', 'driver' => self::vin()->driverName(),
             'state'   => $local(), 'hook' => ''],

            ['service' => 'Geocoding', 'driver' => $geo->driverName(),
             'state'   => match ($geo->driverName()) {
                 'manual' => ['By hand', 'slate'],
                 'osm'    => ['OpenStreetMap — no key needed', 'slate'],
                 default  => $needs(true),
             },
             'hook'    => ''],
        ];
    }
}

/* ====================================================================== *
 *  Part numbering — automated catalog SKUs, assigned by Claude.
 *
 *  When a new Product or Service is added, the house numbering rules and the
 *  full list of existing SKUs are sent to Claude, which returns a code in the
 *  same style — mnemonic, consistent, and unlike anything already on file.
 *
 *  It has a real offline mode. With no API key, a local generator derives a
 *  code from the same rules (prefix by type + a keyword abbreviated from the
 *  name + a numeric variant). So the feature works on day one, and turning on
 *  Claude is a key in Settings — the collision check and audit trail are the
 *  same either way.
 * ====================================================================== */

interface PartNumberGenerator
{
    /**
     * @param array{item_type:string,name:string,category:string,description:string} $item
     * @param string[] $existing   every SKU already in the catalog
     * @return array{sku:string, source:string, note:string}
     */
    public function suggest(array $item, array $existing, string $rules): array;

    public function driverName(): string;
}

/** House rules, editable in Settings. This is the default the field is seeded with. */
final class PartNumbers
{
    public const DEFAULT_RULES =
        "Part numbers follow the pattern PREFIX-KEYWORD[-VARIANT]:\n" .
        "- PREFIX is the item type: SVC for a service, PART for a part, FEE for a fee.\n" .
        "- KEYWORD is 3 to 6 uppercase letters abbreviating the item (BATT, WINCH, TOW, JUMP, TIRE, LOCK).\n" .
        "- VARIANT is an optional short qualifier (STD, HD, INSTALL, TEST, MOUNT).\n" .
        "- Uppercase A-Z and digits only, single hyphens between words, no spaces.\n" .
        "- Must be unique and must never reuse or closely shadow an existing code.\n" .
        "- Keep it short and readable at a glance.";

    public static function rules(): string
    {
        $r = trim((string) App::setting('partnum_rules', ''));
        return $r !== '' ? $r : self::DEFAULT_RULES;
    }

    public static function generator(): PartNumberGenerator
    {
        $driver = Integrations::driver('partnum', 'rules');
        if ($driver === 'claude' && trim((string) App::setting('anthropic_api_key', '')) !== '') {
            return new ClaudePartNumberGenerator();
        }
        return new RulesPartNumberGenerator();
    }

    /**
     * The public entry point. Always returns a unique, valid SKU — falling back
     * to the local generator if Claude is unreachable, so a catalog save is
     * never blocked by an outside service.
     *
     * @return array{sku:string, source:string, note:string}
     */
    public static function suggest(array $item): array
    {
        $existing = array_map(
            static fn ($r) => (string) $r['sku'],
            Db::all('SELECT sku FROM catalog_items ORDER BY sku')
        );
        $rules = self::rules();

        $gen = self::generator();
        try {
            $out = $gen->suggest($item, $existing, $rules);
        } catch (Throwable $e) {
            $out = (new RulesPartNumberGenerator())->suggest($item, $existing, $rules);
            $out['note'] = 'Claude was unreachable — used the local rules instead.';
        }

        $out['sku'] = self::unique(self::clean($out['sku']), $existing, $item);
        return $out;
    }

    /** Normalise to the allowed character set. */
    public static function clean(string $sku): string
    {
        $sku = strtoupper(trim($sku));
        $sku = preg_replace('/[^A-Z0-9\- ]/', '', $sku) ?? '';
        $sku = preg_replace('/\s+/', '-', trim($sku)) ?? '';
        $sku = preg_replace('/-+/', '-', $sku) ?? '';
        // catalog_items.sku is VARCHAR(48); a runaway suggestion must not 500 the insert.
        return substr(trim($sku, '-'), 0, 40);
    }

    /** Guarantee uniqueness, whatever the source proposed. */
    private static function unique(string $sku, array $existing, array $item): string
    {
        if ($sku === '') {
            $sku = (new RulesPartNumberGenerator())->suggest($item, $existing, self::rules())['sku'];
        }
        $set = array_flip(array_map('strtoupper', $existing));
        if (!isset($set[$sku])) { return $sku; }
        for ($n = 2; $n < 999; $n++) {
            $try = $sku . '-' . $n;
            if (!isset($set[$try])) { return $try; }
        }
        return $sku . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
    }
}

/** Local, deterministic fallback. Encodes the same rules in code. */
final class RulesPartNumberGenerator implements PartNumberGenerator
{
    public function driverName(): string { return 'rules'; }

    public function suggest(array $item, array $existing, string $rules): array
    {
        $prefix = match (strtoupper((string) ($item['item_type'] ?? 'SERVICE'))) {
            'PART'  => 'PART',
            'FEE'   => 'FEE',
            default => 'SVC',
        };

        // Keyword: first meaningful word of the name, 3–6 letters.
        $words = preg_split('/[^A-Za-z0-9]+/', (string) ($item['name'] ?? '')) ?: [];
        $stop  = ['THE','AND','FOR','WITH','PER','OF','A','AN','TO','SERVICE','FEE','PART'];
        $keyword = '';
        foreach ($words as $w) {
            $w = strtoupper($w);
            if ($w === '' || in_array($w, $stop, true)) { continue; }
            $keyword = substr(preg_replace('/[^A-Z]/', '', $w) ?: $w, 0, 6);
            if (strlen($keyword) >= 3) { break; }
        }
        if (strlen($keyword) < 3) {
            $keyword = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string) ($item['name'] ?? 'ITEM')) ?: 'ITEM', 0, 4));
            $keyword = str_pad($keyword, 3, 'X');
        }

        $base = $prefix . '-' . $keyword;

        // Variant: a two-digit sequence within this prefix+keyword family.
        $set = array_flip(array_map('strtoupper', $existing));
        if (!isset($set[$base])) {
            $sku = $base;
        } else {
            $n = 1;
            do { $sku = $base . '-' . str_pad((string) $n, 2, '0', STR_PAD_LEFT); $n++; }
            while (isset($set[$sku]) && $n < 100);
        }

        return ['sku' => $sku, 'source' => 'rules', 'note' => 'Generated from the numbering rules.'];
    }
}

/** Claude-backed generator. Sends the rules + existing numbers to the API. */
final class ClaudePartNumberGenerator implements PartNumberGenerator
{
    public function driverName(): string { return 'claude'; }

    public function suggest(array $item, array $existing, string $rules): array
    {
        $key   = trim((string) App::setting('anthropic_api_key', ''));
        $model = trim((string) App::setting('anthropic_model', '')) ?: 'claude-haiku-4-5-20251001';
        if ($key === '') { throw new RuntimeException('No Anthropic API key configured.'); }

        // Cap the list so a huge catalog can't blow the token budget; the model
        // only needs enough to see the pattern and avoid collisions.
        $sample = array_slice($existing, 0, 400);

        $system =
            "You assign part numbers (SKUs) for an auto and roadside-service catalog.\n" .
            "Follow the house rules EXACTLY. The code must be unique — it must not match, " .
            "or be a trivial variant of, any existing code.\n" .
            "Reply with ONLY the part number: no quotes, no explanation, no code fence.\n\n" .
            "HOUSE RULES:\n" . $rules;

        $user =
            "New catalog item:\n" .
            "  Type: "        . (string) ($item['item_type'] ?? '') . "\n" .
            "  Name: "        . (string) ($item['name'] ?? '') . "\n" .
            "  Category: "    . (string) ($item['category'] ?? '') . "\n" .
            "  Description: " . (string) ($item['description'] ?? '') . "\n\n" .
            "Existing part numbers (" . count($existing) . " total" .
            (count($existing) > count($sample) ? ", first " . count($sample) . " shown" : "") . "):\n" .
            (($sample === []) ? "  (none yet)" : "  " . implode("\n  ", $sample)) . "\n\n" .
            "Return one new part number that fits the rules and does not collide.";

        $res = Http::json('POST', 'https://api.anthropic.com/v1/messages', [
            'x-api-key'         => $key,
            'anthropic-version' => '2023-06-01',
        ], [
            'model'      => $model,
            'max_tokens' => 40,
            'system'     => $system,
            'messages'   => [['role' => 'user', 'content' => $user]],
        ]);

        if ($res['status'] !== 200) {
            $msg = (string) ($res['body']['error']['message'] ?? $res['error'] ?? ('HTTP ' . $res['status']));
            ApiLog::write('partnum', 'claude', 'suggest', '', false, $msg);
            throw new RuntimeException('Anthropic API error: ' . $msg);
        }

        // Response content is a list of blocks; the text is in the first one.
        $text = '';
        foreach (($res['body']['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') { $text .= (string) ($block['text'] ?? ''); }
        }
        $sku = PartNumbers::clean($text);

        ApiLog::write('partnum', 'claude', 'suggest', $sku, $sku !== '',
            $sku !== '' ? 'Assigned ' . $sku . ' for "' . (string) ($item['name'] ?? '') . '"'
                        : 'Claude returned no usable code: ' . substr(trim($text), 0, 60));

        if ($sku === '') { throw new RuntimeException('Claude returned no usable part number.'); }

        return ['sku' => $sku, 'source' => 'claude', 'note' => 'Assigned by Claude (' . $model . ').'];
    }
}

/* ====================================================================== */
/* SQUARE MIRROR — importing history, not posting it                       */
/* ====================================================================== */

/**
 * Pulls Square's own record of what happened into square_transactions.
 *
 * THIS CLASS NEVER TOUCHES THE LEDGER. It writes to the mirror table and
 * stops. That separation is the whole design, and it exists because the
 * account being mirrored carries White Knight work mixed with the owner's
 * personal spending: posting an imported row before a human has said which it
 * is would put personal money into business income. Every row arrives
 * UNREVIEWED and inert.
 *
 * Re-running is safe and is the intended way to repair a partial import. Rows
 * are keyed on Square's own id with a unique index behind it, so a second pull
 * of the same object updates rather than duplicates, and a full history re-pull
 * converges on exactly the same table.
 *
 * Paging is cursor-based, as Square's API requires. The page loop is bounded:
 * an API that keeps handing back a cursor forever must not become an infinite
 * loop inside a web request or an SSH session nobody is watching.
 */
final class SquareSync
{
    /** Square's own page size ceiling for these endpoints. */
    private const PAGE_LIMIT = 100;

    /** Safety stop. 200 pages x 100 = 20,000 objects in one run. */
    private const MAX_PAGES = 200;

    /**
     * Stop cleanly after this long and let the next run continue.
     *
     * Square documents a FIVE MINUTE cursor lifetime, and pages go stale when
     * records change underneath them. A job that holds a cursor for half an
     * hour is therefore wrong by construction — which is what happened: a
     * re-pull of six years took 26 minutes on shared hosting and the host
     * killed it, leaving no summary and no progress.
     *
     * So the run is bounded and CHECKPOINTED. Progress is written after every
     * page as a timestamp, not a cursor, so resuming never depends on a
     * cursor surviving. Being killed costs at most one page.
     */
    private const MAX_SECONDS = 210;

    /** Called after each page, so a long run reports progress as it goes. */
    private $onPage = null;

    public function __construct(
        private string $accessToken,
        private string $environment = 'sandbox',
    ) {}

    /** fn(string $type, int $page, int $imported, int $updated, int $skipped) */
    public function onPage(callable $fn): void { $this->onPage = $fn; }

    public static function fromSettings(): self
    {
        return new self(
            (string) App::setting('square_access_token', ''),
            (string) App::setting('square_environment', 'sandbox'),
        );
    }

    public function isLive(): bool { return trim($this->accessToken) !== ''; }

    /**
     * Why this import must not run — or '' if it may.
     *
     * THE SANDBOX RULE. square_transactions holds six years of real charges
     * and has no column saying which environment a row came from. A sandbox
     * row and a real one are indistinguishable once written, so there is no
     * clean-up afterwards — and square_sync_state's cursors are keyed only by
     * object_type, so a sandbox run also rewinds the real history's resume
     * point. One accidental run in sandbox mode corrupts the only copy of a
     * record that cannot be reconstructed from anywhere else.
     *
     * The refusal is here rather than in the CLI scripts because it has to
     * hold for every caller, including the web UI and anything added later.
     * If sandbox data ever genuinely needs importing, the fix is an
     * environment column and a separate cursor — not removing this.
     */
    public function refusalReason(): string
    {
        if (!$this->isLive()) {
            return 'Square is not configured — add the access token in Settings.';
        }
        if ($this->environment !== 'production') {
            return 'Refusing to import: Square is set to ' . $this->environment
                 . '. These tables hold the real charge history and cannot tell a '
                 . 'sandbox row from a real one. Switch Settings to production first.';
        }
        return '';
    }

    private function base(): string
    {
        return $this->environment === 'production'
            ? 'https://connect.squareup.com'
            : 'https://connect.squareupsandbox.com';
    }

    private function headers(): array
    {
        return [
            'Authorization'  => 'Bearer ' . $this->accessToken,
            'Square-Version' => '2025-01-23',
        ];
    }

    /**
     * Every Location on the account, so an operator can see what the account
     * actually contains before deciding how to treat it. Read-only.
     *
     * @return array<int,array{id:string,name:string,status:string,currency:string}>
     */
    public function locations(): array
    {
        if (!$this->isLive()) { return []; }
        $res = Http::json('GET', $this->base() . '/v2/locations', $this->headers());
        $out = [];
        foreach (($res['body']['locations'] ?? []) as $l) {
            $out[] = [
                'id'       => (string) ($l['id'] ?? ''),
                'name'     => (string) ($l['name'] ?? ''),
                'status'   => (string) ($l['status'] ?? ''),
                'currency' => (string) ($l['currency'] ?? 'USD'),
            ];
        }
        return $out;
    }

    /**
     * Import payments, refunds and payouts.
     *
     * @param ?string $since  RFC3339 lower bound. Null resumes from the stored
     *                        cursor; pass a date to re-pull a window, or the
     *                        epoch to re-pull everything.
     * @return array{imported:int,updated:int,pages:int,errors:array<int,string>}
     */
    public function importAll(?string $since = null): array
    {
        $total = ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'pages' => 0,
                  'errors' => [], 'stopped_early' => false];

        /* Checked here as well as in importType so a refusal is reported once
         * rather than repeated for each of the three object types. */
        if (($why = $this->refusalReason()) !== '') {
            $total['errors'][] = $why;
            return $total;
        }

        foreach (['PAYMENT', 'REFUND', 'PAYOUT'] as $type) {
            $one = $this->importType($type, $since);
            $total['imported'] += $one['imported'];
            $total['updated']  += $one['updated'];
            $total['skipped']  += $one['skipped'];
            $total['pages']    += $one['pages'];
            $total['errors']    = array_merge($total['errors'], $one['errors']);
            $total['stopped_early'] = $total['stopped_early'] || !empty($one['stopped_early']);
        }
        return $total;
    }

    /** @return array{imported:int,updated:int,pages:int,errors:array<int,string>} */
    public function importType(string $type, ?string $since = null): array
    {
        $out = ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'pages' => 0,
                'errors' => [], 'stopped_early' => false];
        if (($why = $this->refusalReason()) !== '') {
            $out['errors'][] = $why;
            return $out;
        }

        $begin = $since ?? $this->cursorFor($type);
        [$path, $key] = match ($type) {
            'REFUND' => ['/v2/refunds', 'refunds'],
            'PAYOUT' => ['/v2/payouts', 'payouts'],
            default  => ['/v2/payments', 'payments'],
        };

        $cursor  = null;
        $newest  = $begin;
        $started = microtime(true);
        $seen    = [];

        do {
            $q = ['limit' => self::PAGE_LIMIT];
            if ($begin !== null && $begin !== '') { $q['begin_time'] = $begin; }
            if ($cursor !== null)                 { $q['cursor']     = $cursor; }
            /* Oldest first, so a checkpointed timestamp is a safe place to
             * resume from. Newest-first would strand the middle. */
            if ($type !== 'PAYOUT') { $q['sort_order'] = 'ASC'; }

            $res = Http::json('GET', $this->base() . $path . '?' . http_build_query($q), $this->headers());
            $out['pages']++;

            if ($res['status'] < 200 || $res['status'] >= 300) {
                $why = (string) ($res['body']['errors'][0]['detail'] ?? $res['error'] ?: 'HTTP ' . $res['status']);
                $out['errors'][] = $type . ': ' . $why;
                ApiLog::write('square', 'square', 'sync:' . strtolower($type), '', false, $why);
                break;
            }

            $rows = $res['body'][$key] ?? [];
            foreach ($rows as $obj) {
                $shaped = self::shape($type, $obj);
                if ($shaped === null) { continue; }
                $this->upsert($shaped, $out);
                if (($shaped['occurred_at'] ?? '') > (string) $newest) { $newest = $shaped['occurred_at']; }
            }

            /* Checkpoint after EVERY page. Being killed mid-run then costs one
             * page, not the whole job — which is the difference between a sync
             * that recovers by itself and one that has to be watched. */
            $this->rememberCursor($type, $newest, $out, true);

            if (is_callable($this->onPage)) {
                ($this->onPage)($type, $out['pages'], $out['imported'], $out['updated'], $out['skipped']);
            }

            $cursor = (string) ($res['body']['cursor'] ?? '') ?: null;

            /* A cursor that repeats is a loop. Square hands back a fresh
             * opaque string per page; the same one twice means we would ask
             * for the same page forever. */
            if ($cursor !== null && isset($seen[$cursor])) {
                $out['errors'][] = $type . ': the cursor stopped advancing — stopped to avoid looping.';
                break;
            }
            if ($cursor !== null) { $seen[$cursor] = true; }

            if ((microtime(true) - $started) > self::MAX_SECONDS) {
                $out['stopped_early'] = true;
                break;
            }
        } while ($cursor !== null && $out['pages'] < self::MAX_PAGES);

        $this->rememberCursor($type, $newest, $out);
        ApiLog::write('square', 'square', 'sync:' . strtolower($type), (string) $newest, $out['errors'] === [],
            sprintf('%d imported, %d updated, %d pages', $out['imported'], $out['updated'], $out['pages']));

        return $out;
    }

    /**
     * One Square object, flattened to the mirror's columns.
     *
     * Money arrives from Square in minor units as an integer. It is converted
     * once, here, with integer arithmetic — never by multiplying a float.
     *
     * Public and static because it is a pure transform with no network in it,
     * which is what lets the whole payload-reading half of this class be
     * tested against captured fixtures rather than against a live account.
     */
    public static function shape(string $type, array $o): ?array
    {
        $id = (string) ($o['id'] ?? '');
        if ($id === '') { return null; }

        $card = $o['card_details']['card'] ?? [];

        return [
            'square_id'       => $id,
            'object_type'     => $type,
            'location_id'     => (string) ($o['location_id'] ?? ''),
            'order_id'        => (string) ($o['order_id'] ?? ''),
            'status'          => strtoupper((string) ($o['status'] ?? '')),
            'amount'          => self::money($o['amount_money'] ?? $o['total_money'] ?? null),
            'tip_amount'      => self::money($o['tip_money'] ?? null),
            'fee_amount'      => self::feeTotal($o),
            'net_amount'      => self::netTotal($o),
            'refunded_amount' => self::money($o['refunded_money'] ?? null),
            'currency'        => (string) ($o['amount_money']['currency'] ?? 'USD'),
            'source_type'     => (string) ($o['source_type'] ?? ''),
            'card_brand'      => (string) ($card['card_brand'] ?? ''),
            'card_last4'      => (string) ($card['last_4'] ?? ''),
            'entry_method'    => (string) ($o['card_details']['entry_method'] ?? ''),
            /* Identity, in the order it actually survives in this account. A
             * field census over six years of payloads found: customer_id on
             * 40%, cardholder_name on 6%, billing_address name on 1%,
             * shipping_address on 0.2%. An earlier version read only shipping
             * and captured almost nothing. The customer id is the valuable
             * one — it points into the directory where the phone numbers are. */
            'square_customer_id' => (string) ($o['customer_id'] ?? ''),
            'cardholder_name' => substr((string) ($o['card_details']['card']['cardholder_name'] ?? ''), 0, 160),
            'buyer_email'     => substr((string) ($o['buyer_email_address'] ?? ''), 0, 160),
            'customer_name'   => substr(self::nameFrom($o), 0, 160),

            /* The circumstantial detail that actually identifies a charge when
             * there is no document behind it. See the column comments in
             * Schema.php for why each one earns its place. */
            'receipt_number'  => substr((string) ($o['receipt_number'] ?? ''), 0, 32),
            'device_name'     => substr((string) (
                $o['device_details']['device_name']
                ?? $o['card_details']['device_details']['device_name'] ?? ''), 0, 120),
            'square_product'  => substr((string) ($o['application_details']['square_product'] ?? ''), 0, 32),
            'statement_desc'  => substr((string) ($o['card_details']['statement_description'] ?? ''), 0, 80),
            'card_fingerprint'=> substr((string) ($card['fingerprint'] ?? ''), 0, 96),
            'card_exp'        => self::cardExp($card),
            'card_type'       => substr((string) ($card['card_type'] ?? ''), 0, 16),
            'card_bin'        => substr((string) ($card['bin'] ?? ''), 0, 8),
            'avs_status'      => substr((string) ($o['card_details']['avs_status'] ?? ''), 0, 16),
            'cvv_status'      => substr((string) ($o['card_details']['cvv_status'] ?? ''), 0, 16),
            /* Why a card was refused. 990 declines in this account and not one
             * of them could say why until these two columns existed. */
            'decline_code'    => substr((string) ($o['card_details']['errors'][0]['code'] ?? ''), 0, 48),
            'decline_detail'  => substr((string) ($o['card_details']['errors'][0]['detail'] ?? ''), 0, 255),
            'team_member_id'  => substr((string) ($o['team_member_id'] ?? $o['employee_id'] ?? ''), 0, 64),
            'note'            => substr((string) ($o['note'] ?? ''), 0, 255),
            'reference_id'    => substr((string) ($o['reference_id'] ?? ''), 0, 120),
            'receipt_url'     => substr((string) ($o['receipt_url'] ?? ''), 0, 512),
            /* Converted to the application's timezone, NOT stored as Square's
             * UTC. Every other timestamp in this database — created_at,
             * issued_at, paid_at, everything from now() — is local time,
             * because Core.php pins the clock deliberately. Storing one column
             * in UTC put a seven-hour skew between it and its neighbours:
             * evening jobs landed on the following day, and near 31 December
             * on the following YEAR, quietly moving money between annual
             * totals. TelnyxSmsGateway::stamp already established this
             * pattern for an inbound provider timestamp; this now follows it. */
            'occurred_at'     => self::localStamp((string) ($o['created_at'] ?? ($o['payout_date'] ?? ''))),
            'raw'             => json_encode($o, JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * Insert, or update what Square may have changed since last time — status,
     * refunds, fees, the net once a payout settles.
     *
     * classification is NOT in the update set. A row a human has already
     * reviewed must never be dragged back to UNREVIEWED by a later sync, and
     * an automated import must never overwrite a human's judgement about what
     * is business and what is personal.
     */
    private function upsert(array $row, array &$tally): void
    {
        $existing = Db::one(
            'SELECT id, raw FROM square_transactions WHERE square_id = ?',
            [$row['square_id']]
        );

        /* REPAIR A TRUNCATED ID RATHER THAN DUPLICATE IT.
         *
         * square_id was VARCHAR(64) and Square's refund ids are longer, so
         * MySQL cut them short on the way in without complaining. After the
         * column was widened, an exact lookup on the full id misses those old
         * rows — and inserting would leave the mirror holding the same refund
         * twice, once truncated and once whole.
         *
         * So a miss falls back to the 64-character prefix. A hit is the same
         * object under its old clipped name: it gets its full id back and
         * carries on. Nothing is deleted and no history is lost. */
        if ($existing === null && strlen($row['square_id']) > 64) {
            $legacy = Db::one(
                'SELECT id, raw FROM square_transactions WHERE square_id = ?',
                [substr($row['square_id'], 0, 64)]
            );
            if ($legacy !== null) {
                Db::update('square_transactions', (int) $legacy['id'], ['square_id' => $row['square_id']]);
                $existing = $legacy;
            }
        }

        if ($existing === null) {
            $row['classification'] = 'UNREVIEWED';
            $row['first_seen_at']  = now();
            $row['last_synced_at'] = now();
            $row['invoice_id']     = $this->matchInvoice($row);
            Db::insert('square_transactions', $row);
            $tally['imported']++;
            return;
        }

        /* SKIP WHAT HAS NOT CHANGED.
         *
         * A six-year re-pull previously rewrote all 3,886 rows every time,
         * even though a payment from 2021 is never going to change again. On
         * shared hosting that took 26 minutes and got the process killed.
         * Comparing the stored payload against the fetched one turns a re-pull
         * into a read, and makes --all cheap enough to run whenever. */
        if ((string) ($existing['raw'] ?? '') === (string) $row['raw']) {
            $tally['skipped']++;
            return;
        }

        unset($row['square_id']);
        $row['last_synced_at'] = now();
        Db::update('square_transactions', (int) $existing['id'], $row);
        $tally['updated']++;
    }

    /**
     * The invoice this payment settled, when the app itself created the link.
     *
     * Matching is by Square's order id against a checkout this application
     * issued — an identity, not a guess. Nothing is matched on amount or date:
     * in an account carrying personal spending alongside the business, a
     * same-amount coincidence would attach the owner's grocery run to a
     * customer's invoice, and that error is invisible once made.
     */
    private function matchInvoice(array $row): ?int
    {
        $order = (string) ($row['order_id'] ?? '');
        if ($order === '') { return null; }
        $id = Db::val('SELECT invoice_id FROM payment_links WHERE order_id = ?', [$order]);
        return $id === null ? null : (int) $id;
    }

    private function cursorFor(string $type): ?string
    {
        $r = Db::one('SELECT cursor_time FROM square_sync_state WHERE object_type = ?', [$type]);
        return $r === null ? null : (self::rfc3339((string) ($r['cursor_time'] ?? '')) ?: null);
    }

    /**
     * A timestamp Square will accept as begin_time.
     *
     * THE BUG THIS FIXES. Square requires RFC 3339 — "2026-08-16T18:12:42Z".
     * The checkpoint is kept in a DATETIME column, and MySQL stores that value
     * as "2026-08-16 18:12:42": space instead of T, no zone. Reading it back
     * and handing it straight to the API produced
     * "begin_time must be in RFC 3339 format" on every incremental run, so
     * only a full --all ever worked — and that passes a hand-written literal,
     * which is why the fault stayed hidden.
     *
     * Normalising on READ rather than changing the column keeps the fix in one
     * place and works against databases already carrying the bad shape.
     */
    public static function rfc3339(string $v): string
    {
        $v = trim($v);
        if ($v === '') { return ''; }
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})$/', $v)) {
            return $v;
        }
        /* A naive value is one this application stored, and this application
         * stores LOCAL time — Core.php pins the timezone and now() writes in
         * it. So it is parsed in the default zone and converted to UTC for
         * Square, rather than being relabelled as UTC where it stands. Getting
         * this backwards moves the checkpoint by the offset on every run,
         * which either re-fetches or skips whatever falls inside that window. */
        $ts = strtotime($v);
        return $ts === false ? '' : gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    /**
     * A provider's ISO 8601 timestamp, in this application's timezone and
     * storage format. Mirrors TelnyxSmsGateway::stamp — the same job, and the
     * pattern this class should have followed from the start.
     */
    public static function localStamp(string $iso): string
    {
        $iso = trim($iso);
        if ($iso === '') { return ''; }
        try {
            return (new DateTimeImmutable($iso))
                ->setTimezone(new DateTimeZone(date_default_timezone_get()))
                ->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return '';
        }
    }

    private function rememberCursor(string $type, ?string $newest, array $out, bool $partial = false): void
    {
        $result = $out['errors'] === []
            ? sprintf('%d imported, %d updated, %d unchanged%s',
                $out['imported'], $out['updated'], $out['skipped'], $partial ? ' (in progress)' : '')
            : substr(implode(' · ', $out['errors']), 0, 255);

        $have = Db::one('SELECT object_type FROM square_sync_state WHERE object_type = ?', [$type]);
        $data = [
            'cursor_time' => $newest ?: null,
            'last_run_at' => now(),
            'last_result' => $result,
            'imported'    => (int) $out['imported'],
        ];

        if ($have === null) {
            Db::insert('square_sync_state', $data + ['object_type' => $type]);
            return;
        }
        $set = implode(',', array_map(static fn($c) => "$c = :$c", array_keys($data)));
        Db::q("UPDATE square_sync_state SET $set WHERE object_type = :__t", $data + ['__t' => $type]);
    }

    /* ---- money: minor units in, decimal string out --------------------- */

    /** "08/2027" from Square's separate month and year, or empty. */
    public static function cardExp(array $card): string
    {
        $m = (int) ($card['exp_month'] ?? 0);
        $y = (int) ($card['exp_year'] ?? 0);
        return ($m > 0 && $y > 0) ? sprintf('%02d/%04d', $m, $y) : '';
    }

    /**
     * The best name a payment payload carries, in descending reliability.
     * Empty when it carries none, which for this account is most of them —
     * the directory is where the names are.
     */
    public static function nameFrom(array $o): string
    {
        $card = trim((string) ($o['card_details']['card']['cardholder_name'] ?? ''));
        if ($card !== '') { return $card; }

        foreach (['billing_address', 'shipping_address'] as $blk) {
            $n = trim(((string) ($o[$blk]['first_name'] ?? '')) . ' ' . ((string) ($o[$blk]['last_name'] ?? '')));
            if ($n !== '') { return $n; }
            $whole = trim((string) ($o[$blk]['name'] ?? ''));
            if ($whole !== '') { return $whole; }
        }
        return trim((string) ($o['buyer_email_address'] ?? ''));
    }

    /**
     * Mirror Square's customer directory — the names and phone numbers.
     *
     * Separate from importType because the endpoint is shaped differently:
     * customers are not time-ordered events and have no begin_time, so the
     * whole directory is walked every run. It is small relative to six years
     * of payments and converges on the same rows either way.
     *
     * @return array{imported:int,updated:int,pages:int,errors:array<int,string>}
     */
    public function importCustomers(): array
    {
        $out = ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'pages' => 0,
                'errors' => [], 'stopped_early' => false];
        if (($why = $this->refusalReason()) !== '') {
            $out['errors'][] = $why;
            return $out;
        }

        $cursor = null;
        do {
            $q = ['limit' => self::PAGE_LIMIT];
            if ($cursor !== null) { $q['cursor'] = $cursor; }

            $res = Http::json('GET', $this->base() . '/v2/customers?' . http_build_query($q), $this->headers());
            $out['pages']++;

            if ($res['status'] < 200 || $res['status'] >= 300) {
                $why = (string) ($res['body']['errors'][0]['detail'] ?? $res['error'] ?: 'HTTP ' . $res['status']);
                $out['errors'][] = 'CUSTOMER: ' . $why;
                ApiLog::write('square', 'square', 'sync:customers', '', false, $why);
                break;
            }

            foreach (($res['body']['customers'] ?? []) as $c) {
                $row = self::shapeCustomer($c);
                if ($row === null) { continue; }
                $this->upsertCustomer($row, $out);
            }

            $cursor = (string) ($res['body']['cursor'] ?? '') ?: null;
        } while ($cursor !== null && $out['pages'] < self::MAX_PAGES);

        $this->linkCustomerTotals();

        ApiLog::write('square', 'square', 'sync:customers', '', $out['errors'] === [],
            sprintf('%d imported, %d updated, %d pages', $out['imported'], $out['updated'], $out['pages']));
        return $out;
    }

    /**
     * What each payout was actually made of.
     *
     * A payout header is the leftover after Square has taken what it is owed.
     * The entries are the itemisation, and in this account they carry a
     * business loan repayment and a credit card repayment on nearly every
     * payout — a quarter of gross, invisible to the payments import.
     *
     * One request per payout, so this is the slow one. It walks oldest-first
     * and marks each payout done as it goes, which makes it resumable: being
     * killed costs the payout in flight, not the run.
     *
     * @return array{imported:int,updated:int,pages:int,errors:array<int,string>,stopped_early:bool}
     */
    public function importPayoutEntries(bool $all = false): array
    {
        $out = ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'pages' => 0,
                'errors' => [], 'stopped_early' => false];
        if (($why = $this->refusalReason()) !== '') {
            $out['errors'][] = $why;
            return $out;
        }

        /* Payouts with no entries fetched yet, oldest first. A re-run picks up
         * exactly where the last one stopped without being told to. */
        $sql = "SELECT t.id, t.square_id, t.occurred_at
                FROM square_transactions t
                WHERE t.object_type = 'PAYOUT'";
        if (!$all) {
            $sql .= " AND NOT EXISTS (SELECT 1 FROM square_payout_entries e
                                      WHERE e.payout_square_id = t.square_id)";
        }
        $sql .= ' ORDER BY t.occurred_at ASC';

        $payouts = Db::all($sql);
        $started = microtime(true);

        foreach ($payouts as $p) {
            $cursor = null;
            do {
                $q = ['limit' => self::PAGE_LIMIT];
                if ($cursor !== null) { $q['cursor'] = $cursor; }

                $res = Http::json('GET', $this->base() . '/v2/payouts/'
                    . rawurlencode((string) $p['square_id']) . '/payout-entries?' . http_build_query($q),
                    $this->headers());
                $out['pages']++;

                if ($res['status'] < 200 || $res['status'] >= 300) {
                    $why = (string) ($res['body']['errors'][0]['detail'] ?? $res['error'] ?: 'HTTP ' . $res['status']);
                    $out['errors'][] = 'ENTRIES ' . substr((string) $p['square_id'], 0, 12) . ': ' . $why;
                    break;
                }

                foreach (($res['body']['payout_entries'] ?? []) as $e) {
                    $row = self::shapeEntry($e, (string) $p['square_id'], (int) $p['id']);
                    if ($row === null) { continue; }
                    $this->upsertEntry($row, $out);
                }

                $cursor = (string) ($res['body']['cursor'] ?? '') ?: null;
            } while ($cursor !== null && $out['pages'] < self::MAX_PAGES * 20);

            if (is_callable($this->onPage) && $out['pages'] % 25 === 0) {
                ($this->onPage)('ENTRIES', $out['pages'], $out['imported'], $out['updated'], $out['skipped']);
            }

            if ((microtime(true) - $started) > self::MAX_SECONDS) {
                $out['stopped_early'] = true;
                break;
            }
        }

        ApiLog::write('square', 'square', 'sync:entries', '', $out['errors'] === [],
            sprintf('%d imported, %d updated, %d payouts remaining',
                $out['imported'], $out['updated'], count($payouts) - $out['pages']));
        return $out;
    }

    /** One payout entry, flattened. Null when it carries no id. */
    public static function shapeEntry(array $e, string $payoutId, int $payoutRowId): ?array
    {
        $id = (string) ($e['id'] ?? '');
        if ($id === '') { return null; }

        /* Square nests the thing an entry refers to under a per-type key —
         * type_charge_details, type_refund_details and so on. Whichever it is,
         * the id inside links back to the payment or refund already imported. */
        $related = '';
        foreach ($e as $k => $v) {
            if (str_starts_with($k, 'type_') && is_array($v)) {
                $related = (string) ($v['payment_id'] ?? $v['refund_id'] ?? $v['dispute_id'] ?? '');
                if ($related !== '') { break; }
            }
        }

        return [
            'square_entry_id'   => $id,
            'payout_square_id'  => $payoutId,
            'payout_row_id'     => $payoutRowId,
            'entry_type'        => strtoupper((string) ($e['type'] ?? 'UNKNOWN')),
            'effective_at'      => self::localStamp((string) ($e['effective_at'] ?? '')),
            'gross_amount'      => self::money($e['gross_amount_money'] ?? null),
            'fee_amount'        => self::money($e['fee_amount_money'] ?? null),
            'net_amount'        => self::money($e['net_amount_money'] ?? null),
            'currency'          => (string) ($e['gross_amount_money']['currency'] ?? 'USD'),
            'related_square_id' => substr($related, 0, 191),
            'raw'               => json_encode($e, JSON_UNESCAPED_SLASHES),
        ];
    }

    private function upsertEntry(array $row, array &$tally): void
    {
        $existing = Db::one(
            'SELECT id, raw FROM square_payout_entries WHERE square_entry_id = ?',
            [$row['square_entry_id']]
        );

        if ($existing === null) {
            $row['first_seen_at']  = now();
            $row['last_synced_at'] = now();
            Db::insert('square_payout_entries', $row);
            $tally['imported']++;
            return;
        }
        if ((string) ($existing['raw'] ?? '') === (string) $row['raw']) {
            $tally['skipped']++;
            return;
        }
        unset($row['square_entry_id']);
        $row['last_synced_at'] = now();
        Db::update('square_payout_entries', (int) $existing['id'], $row);
        $tally['updated']++;
    }

    /** One Square customer, flattened. Null when it carries no id. */
    public static function shapeCustomer(array $c): ?array
    {
        $id = (string) ($c['id'] ?? '');
        if ($id === '') { return null; }

        $phone = (string) ($c['phone_number'] ?? '');
        $addr  = $c['address'] ?? [];

        return [
            'square_customer_id' => $id,
            'given_name'         => substr((string) ($c['given_name'] ?? ''), 0, 80),
            'family_name'        => substr((string) ($c['family_name'] ?? ''), 0, 80),
            'company_name'       => substr((string) ($c['company_name'] ?? ''), 0, 160),
            'phone_number'       => substr($phone, 0, 32),
            'phone_e164'         => self::e164($phone),
            'email_address'      => substr((string) ($c['email_address'] ?? ''), 0, 160),
            'address_line1'      => substr((string) ($addr['address_line_1'] ?? ''), 0, 160),
            'city'               => substr((string) ($addr['locality'] ?? ''), 0, 80),
            'state'              => substr((string) ($addr['administrative_district_level_1'] ?? ''), 0, 8),
            'postal_code'        => substr((string) ($addr['postal_code'] ?? ''), 0, 12),
            'note'               => substr((string) ($c['note'] ?? ''), 0, 255),
            'reference_id'       => substr((string) ($c['reference_id'] ?? ''), 0, 120),
            'created_at_square'  => (string) ($c['created_at'] ?? ''),
            'raw'                => json_encode($c, JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * A phone number reduced to E.164, or empty when it cannot be.
     *
     * Ten digits are assumed North American and get a +1. Eleven starting
     * with 1 are the same number written differently. Anything else is left
     * empty rather than guessed at: a wrong number in a dispatch system texts
     * a stranger, and this application texts customers.
     */
    public static function e164(string $raw): string
    {
        $d = preg_replace('/\D+/', '', $raw) ?? '';
        if (strlen($d) === 10)                                 { return '+1' . $d; }
        if (strlen($d) === 11 && str_starts_with($d, '1'))     { return '+' . $d; }
        if ($raw !== '' && str_starts_with(trim($raw), '+'))   { return '+' . $d; }
        return '';
    }

    /** promoted_customer_id is never touched here — that link is a human's. */
    private function upsertCustomer(array $row, array &$tally): void
    {
        $existing = Db::one('SELECT id FROM square_customers WHERE square_customer_id = ?', [$row['square_customer_id']]);

        if ($existing === null) {
            $row['first_seen_at']  = now();
            $row['last_synced_at'] = now();
            Db::insert('square_customers', $row);
            $tally['imported']++;
            return;
        }

        unset($row['square_customer_id']);
        $row['last_synced_at'] = now();
        Db::update('square_customers', (int) $existing['id'], $row);
        $tally['updated']++;
    }

    /**
     * Roll each customer's completed payments back onto their directory row —
     * how many jobs, how much, first and last.
     *
     * This is what turns a name and a number into something worth having: an
     * inbound call from a number you can recognise, with six years of history
     * behind it. Only COMPLETED payments count; a declined card is not a job.
     */
    public function linkCustomerTotals(): void
    {
        Db::q(
            "UPDATE square_customers sc SET
               payment_count = (SELECT COUNT(*) FROM square_transactions t
                                WHERE t.square_customer_id = sc.square_customer_id
                                  AND t.object_type = 'PAYMENT' AND t.status = 'COMPLETED'),
               payment_total = (SELECT COALESCE(SUM(t.amount),0) FROM square_transactions t
                                WHERE t.square_customer_id = sc.square_customer_id
                                  AND t.object_type = 'PAYMENT' AND t.status = 'COMPLETED'),
               first_seen_job = (SELECT MIN(t.occurred_at) FROM square_transactions t
                                WHERE t.square_customer_id = sc.square_customer_id
                                  AND t.object_type = 'PAYMENT' AND t.status = 'COMPLETED'),
               last_seen_job = (SELECT MAX(t.occurred_at) FROM square_transactions t
                                WHERE t.square_customer_id = sc.square_customer_id
                                  AND t.object_type = 'PAYMENT' AND t.status = 'COMPLETED')"
        );
    }

    /** Square's {amount, currency} object to a "12.34" string. */
    public static function money(?array $m): string
    {
        return Markup::centsToStr((int) ($m['amount'] ?? 0));
    }

    /** Processing fees, which Square reports as a list of withholdings. */
    public static function feeTotal(array $o): string
    {
        $cents = 0;
        foreach (($o['processing_fee'] ?? []) as $f) {
            $cents += (int) ($f['amount_money']['amount'] ?? 0);
        }
        return Markup::centsToStr($cents);
    }

    /** What actually reaches the bank: gross plus tip, less fees. */
    public static function netTotal(array $o): string
    {
        $gross = (int) ($o['amount_money']['amount'] ?? $o['total_money']['amount'] ?? 0);
        $tip   = (int) ($o['tip_money']['amount'] ?? 0);
        $fee   = 0;
        foreach (($o['processing_fee'] ?? []) as $f) {
            $fee += (int) ($f['amount_money']['amount'] ?? 0);
        }
        return Markup::centsToStr($gross + $tip - $fee);
    }
}
