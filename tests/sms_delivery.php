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
/**
 * SMS delivery receipts: status mapping, out-of-order callbacks, and the
 * reasons a callback is refused.
 *
 *   WKR_DB_PASS=… php tests/sms_delivery.php
 *
 * Drives the real TelnyxSmsGateway::parseWebhook and the real
 * WebhookController::messageStatus against the database, using throwaway
 * message rows that are deleted at the end.
 *
 * The two behaviours that matter most here, because both were live bugs:
 *
 *   1. delivery_unconfirmed is not a failure. Telnyx defines it as "no
 *      delivery confirmation was received from the carrier". It used to be
 *      recorded as FAILED, which would have had a dispatcher re-texting
 *      customers who already had the message.
 *   2. Callbacks arrive out of order — Telnyx says so explicitly. A late
 *      message.sent must not overwrite a DELIVERED that already landed.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$cfg  = require $root . '/config.php';
if (getenv('WKR_DB_DRIVER') === false && getenv('WKR_DB_PASS') === false) {
    $cfg['db']['driver'] = 'sqlite';
}
foreach (['Core', 'Db', 'Schema', 'helpers', 'Domain'] as $f) { require $root . '/app/' . $f . '.php'; }
require $root . '/app/Contracts/Contracts.php';
require $root . '/app/Services/Http.php';
require $root . '/app/Services/Services.php';
foreach (glob($root . '/app/Controllers/*.php') as $f) { require $f; }
App::boot($cfg);
Db::boot($cfg['db']);
if (Db::driver() === 'sqlite') { Db::migrate(); }

$PASS = 0; $FAIL = 0;
function check(string $l, $got, $want): void {
    global $PASS, $FAIL;
    if ($got === $want) { $PASS++; printf("  \033[32mPASS\033[0m %s\n", $l); }
    else { $FAIL++; printf("  \033[31mFAIL\033[0m %s (want %s, got %s)\n", $l, var_export($want, true), var_export($got, true)); }
}
function section(string $s): void { printf("\n\033[1m== %s\033[0m\n", $s); }

$gw = new TelnyxSmsGateway('key', '+15035550100', '', '');

/** A message.finalized / message.sent callback body, as Telnyx sends it. */
function hook(string $event, string $status, array $errors = [], string $occurred = '2026-08-05T18:30:00.000+00:00'): string {
    return json_encode(['data' => [
        'event_type'  => $event,
        'id'          => 'evt-' . bin2hex(random_bytes(4)),
        'occurred_at' => $occurred,
        'payload'     => [
            'id'     => 'MSG-REF',
            'from'   => ['phone_number' => '+15035550100'],
            'to'     => [['phone_number' => '+15035550177', 'status' => $status, 'carrier' => 'T-MOBILE USA, INC.']],
            'errors' => $errors,
        ],
    ]], JSON_THROW_ON_ERROR);
}
$parse = fn(string $event, string $status, array $errors = []) => $gw->parseWebhook(hook($event, $status, $errors));

section('carrier status maps to our status');
check('delivered  -> DELIVERED',   $parse('message.finalized', 'delivered')['status'],   'DELIVERED');
check('delivery_failed -> FAILED', $parse('message.finalized', 'delivery_failed')['status'], 'FAILED');
check('sending_failed -> FAILED',  $parse('message.finalized', 'sending_failed')['status'],  'FAILED');
check('sent       -> SENT',        $parse('message.sent', 'sent')['status'],             'SENT');
check('queued     -> QUEUED',      $parse('message.sent', 'queued')['status'],           'QUEUED');
check('sending    -> QUEUED',      $parse('message.sent', 'sending')['status'],          'QUEUED');
// The bug this test exists for. "No receipt from the carrier" is not a failure.
check('delivery_unconfirmed -> UNCONFIRMED, NOT failed',
    $parse('message.finalized', 'delivery_unconfirmed')['status'], 'UNCONFIRMED');
check('an unknown status degrades to SENT, never to FAILED',
    $parse('message.finalized', 'something_new_telnyx_invented')['status'], 'SENT');

section('the carrier reason travels with a failure');
$f = $parse('message.finalized', 'delivery_failed', [
    ['code' => '40010', 'title' => 'Message blocked', 'detail' => 'Spam filter'],
]);
check('code and title captured', $f['reason'], '40010 Message blocked');
check('no errors -> empty reason', $parse('message.finalized', 'delivered')['reason'], '');
$d = $parse('message.finalized', 'delivery_failed', [['code' => '40002', 'detail' => 'Not in service']]);
check('falls back to detail when title is absent', $d['reason'], '40002 Not in service');

section('timestamps come from the provider, not our clock');
$e = $gw->parseWebhook(hook('message.finalized', 'delivered', [], '2026-08-05T18:30:00.000+00:00'));
check('occurred_at parsed to storage format', preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $e['occurred']) === 1, true);
$bad = $gw->parseWebhook(hook('message.finalized', 'delivered', [], 'not-a-date'));
check('an unparseable timestamp is empty, not a crash', $bad['occurred'], '');
check('carrier captured', $e['carrier'], 'T-MOBILE USA, INC.');

section('inbound is still recognised');
$in = $gw->parseWebhook(json_encode(['data' => ['event_type' => 'message.received', 'payload' => [
    'id' => 'x', 'from' => ['phone_number' => '+15035550177'],
    'to' => [['phone_number' => '+15035550100']], 'text' => 'STOP']]], JSON_THROW_ON_ERROR));
check('inbound kind', $in['kind'], 'inbound');
check('inbound text', $in['text'], 'STOP');
check('garbage body -> null', $gw->parseWebhook('not json'), null);

section('a refusal says WHICH problem it was');
$noKey = new TelnyxSmsGateway('key', '+15035550100', '', '');
check('missing public key is named',
    str_contains($noKey->verifyReason('{}', []), 'no Telnyx public key'), true);
$badLen = new TelnyxSmsGateway('key', '+15035550100', base64_encode('too-short'), '');
check('wrong-length key is named',
    str_contains($badLen->verifyReason('{}', ['telnyx-signature-ed25519' => base64_encode('s'), 'telnyx-timestamp' => (string) time()]), 'wrong length'), true);
check('absent signature headers are named',
    str_contains($badLen->verifyReason('{}', []), 'no Telnyx signature headers'), true);
$keypair = sodium_crypto_sign_keypair();
$pub     = base64_encode(sodium_crypto_sign_publickey($keypair));
$sec     = sodium_crypto_sign_secretkey($keypair);
$live    = new TelnyxSmsGateway('key', '+15035550100', $pub, '');
$body    = hook('message.finalized', 'delivered');
$ts      = (string) time();
$sig     = base64_encode(sodium_crypto_sign_detached($ts . '|' . $body, $sec));
check('a correctly signed callback verifies', $live->verifyReason($body, [
    'telnyx-signature-ed25519' => $sig, 'telnyx-timestamp' => $ts]), '');
check('verifyWebhook agrees', $live->verifyWebhook($body, [
    'telnyx-signature-ed25519' => $sig, 'telnyx-timestamp' => $ts]), true);
check('a stale timestamp is refused even with a good signature',
    str_contains($live->verifyReason($body, [
        'telnyx-signature-ed25519' => $sig, 'telnyx-timestamp' => (string) (time() - 4000)]), 'five-minute'), true);
check('a tampered body is refused',
    str_contains($live->verifyReason($body . ' ', [
        'telnyx-signature-ed25519' => $sig, 'telnyx-timestamp' => $ts]), 'did not match'), true);

/* ------------------------------------------------------------------ */
/* Who a refused callback came from.                                   */
/*                                                                     */
/* "The request carried no Telnyx signature headers" has two causes    */
/* that demand opposite responses: a real callback from a profile set  */
/* to webhook API v1 (unsigned — an outage), or a stranger probing the */
/* endpoint (background noise). The refusal is correct either way, so  */
/* only the log can tell them apart.                                   */
/* ------------------------------------------------------------------ */
section('a refused callback records who sent it');
$origin = new ReflectionMethod(WebhookController::class, 'origin');
$origin->setAccessible(true);
$saved  = $_SERVER;
$describe = static function (array $server) use ($origin): string {
    $_SERVER = $server;
    return (string) $origin->invoke(null);
};

$scanner = $describe(['REMOTE_ADDR' => '45.147.230.11', 'HTTP_USER_AGENT' => 'zgrab/0.x']);
check('the peer address is recorded',   str_contains($scanner, '45.147.230.11'), true);
check('the user agent is recorded',     str_contains($scanner, 'zgrab/0.x'), true);
check('a bare probe is called out as such', str_contains($scanner, 'no telnyx-* headers at all'), true);

// A v1 callback DOES carry telnyx-* headers — just not an ed25519 signature.
// That is the single fact separating it from a scanner.
$v1 = $describe(['REMOTE_ADDR' => '192.76.120.10', 'HTTP_USER_AGENT' => 'telnyx-webhooks/2.0',
    'HTTP_X_TELNYX_SIGNATURE' => 'abc', 'HTTP_TELNYX_TIMESTAMP' => '1770000000']);
check('provider headers are named when present', str_contains($v1, 'x-telnyx-signature'), true);
check('an unsigned v1 callback is NOT mistaken for a scanner',
    str_contains($v1, 'no telnyx-* headers at all'), false);

// X-Forwarded-For is the sender's claim, not a fact. It must never be
// presented as the origin.
$proxied = $describe(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
    'HTTP_USER_AGENT' => 'telnyx-webhooks/2.0']);
check('the real peer is the one reported',   str_contains($proxied, 'from 10.0.0.5'), true);
check('a forwarded-for is recorded as a claim', str_contains($proxied, 'claiming X-Forwarded-For'), true);

// Header VALUES are attacker-controlled and teach nothing; only names travel.
$noisy = $describe(['REMOTE_ADDR' => '1.1.1.1',
    'HTTP_TELNYX_SIGNATURE_ED25519' => 'SUPERSECRETLOOKINGVALUE', 'HTTP_TELNYX_TIMESTAMP' => '1770000000']);
check('signature values are never logged', str_contains($noisy, 'SUPERSECRETLOOKINGVALUE'), false);
check('a missing user agent is stated, not blank', str_contains($noisy, 'UA none'), true);

$_SERVER = $saved;

/* ------------------------------------------------------------------ */
/* Against the database: the ordering rule.                            */
/* ------------------------------------------------------------------ */
$apply = static function (array $evt): void {
    $m = new ReflectionMethod(WebhookController::class, 'messageStatus');
    $m->setAccessible(true);
    $m->invoke(null, $evt);
};
$ref = 'TEST-REF-' . bin2hex(random_bytes(4));
$mk  = static function (string $status) use ($ref): int {
    Db::q('DELETE FROM messages WHERE provider_ref = ?', [$ref]);
    return Db::insert('messages', ['direction' => 'OUT', 'channel' => 'sms',
        'phone_e164' => '+15035550177', 'template' => 'test', 'body' => 'x',
        'status' => $status, 'provider_ref' => $ref, 'created_at' => now()]);
};
$get = static fn(int $id): array => Db::one('SELECT * FROM messages WHERE id = ?', [$id]);
$evt = static fn(string $status, string $reason = '') => [
    'kind' => 'status', 'reference' => $ref, 'from' => '', 'to' => '', 'text' => '',
    'status' => $status, 'carrier' => 'T-MOBILE', 'occurred' => '2026-08-05 11:30:00', 'reason' => $reason];

section('status moves forward and records when');
$id = $mk('QUEUED');
$apply($evt('SENT'));      check('QUEUED -> SENT',      $get($id)['status'], 'SENT');
$apply($evt('DELIVERED')); check('SENT -> DELIVERED',   $get($id)['status'], 'DELIVERED');
check('delivered_at recorded',      substr((string) $get($id)['delivered_at'], 0, 16), '2026-08-05 11:30');
check('sent_at kept from earlier',  $get($id)['sent_at'] !== null, true);
check('carrier recorded',           $get($id)['carrier'], 'T-MOBILE');

section('out-of-order callbacks cannot move it backwards');
// Telnyx: "message.finalized may arrive before message.sent."
$id = $mk('QUEUED');
$apply($evt('DELIVERED'));
$apply($evt('SENT'));
check('a late SENT does not undo DELIVERED', $get($id)['status'], 'DELIVERED');
check('delivered_at survives the late callback', $get($id)['delivered_at'] !== null, true);
$apply($evt('QUEUED'));
check('a late QUEUED does not undo DELIVERED', $get($id)['status'], 'DELIVERED');
$apply($evt('UNCONFIRMED'));
check('UNCONFIRMED does not downgrade DELIVERED', $get($id)['status'], 'DELIVERED');

section('terminal states do not overwrite each other');
$id = $mk('QUEUED');
$apply($evt('DELIVERED'));
$apply($evt('FAILED', '40010 blocked'));
check('FAILED does not overwrite DELIVERED', $get($id)['status'], 'DELIVERED');
$id = $mk('QUEUED');
$apply($evt('FAILED', '40002 not in service'));
check('FAILED lands when it is first',   $get($id)['status'], 'FAILED');
check('failure reason stored',           $get($id)['failure_reason'], '40002 not in service');
$apply($evt('DELIVERED'));
check('DELIVERED does not overwrite FAILED', $get($id)['status'], 'FAILED');

section('unconfirmed sits between sent and delivered');
$id = $mk('QUEUED');
$apply($evt('SENT'));
$apply($evt('UNCONFIRMED'));
check('SENT -> UNCONFIRMED',            $get($id)['status'], 'UNCONFIRMED');
check('unconfirmed sets no delivered_at', $get($id)['delivered_at'], null);
$apply($evt('DELIVERED'));
check('a later real receipt still wins', $get($id)['status'], 'DELIVERED');

section('retries are no-ops');
$id = $mk('QUEUED');
$apply($evt('DELIVERED'));
$first = $get($id)['delivered_at'];
$apply($evt('DELIVERED'));
$apply($evt('DELIVERED'));
check('repeated DELIVERED is idempotent', $get($id)['delivered_at'], $first);

section('an unknown reference is ignored, not created');
$before = (int) Db::val('SELECT COUNT(*) FROM messages');
$apply(['kind' => 'status', 'reference' => 'NOPE-' . bin2hex(random_bytes(4)),
        'from' => '', 'to' => '', 'text' => '', 'status' => 'DELIVERED',
        'carrier' => '', 'occurred' => '', 'reason' => '']);
check('no row invented', (int) Db::val('SELECT COUNT(*) FROM messages'), $before);
$apply(['kind' => 'status', 'reference' => '', 'from' => '', 'to' => '', 'text' => '',
        'status' => 'DELIVERED', 'carrier' => '', 'occurred' => '', 'reason' => '']);
check('empty reference is ignored', (int) Db::val('SELECT COUNT(*) FROM messages'), $before);

section('every status the app can store has a badge tone');
foreach (['QUEUED', 'SENT', 'DELIVERED', 'UNCONFIRMED', 'FAILED', 'RECEIVED', 'BLOCKED'] as $s) {
    check("$s has a tone", isset(STATUS_TONE[$s]), true);
}
check('UNCONFIRMED is amber, not red', STATUS_TONE['UNCONFIRMED'], 'warn');
check('DELIVERED is green',            STATUS_TONE['DELIVERED'],   'success');

/* ------------------------------------------------------------------ */
/* The queue result tells the truth about what happened.               */
/* ------------------------------------------------------------------ */
section('queue() distinguishes sent, held and blocked');
// Local driver is the outbox: consented sends are HELD, not sent — and the
// caller must be able to see the difference, because "texted to the customer"
// may only ever be said about a live carrier-accepted send.
$cust = ['id' => 0, 'do_not_contact' => 0, 'sms_approved' => 1, 'phone_e164' => '+15035550166'];
$out  = Sms::queue($cust, 'dispatch', ['{eta}' => '5:00 PM', '{total}' => '', '{doc}' => '']);
check('outbox send is ok',        $out['ok'],   true);
check('outbox send is HELD',      $out['held'], true);
check('outbox send is not SENT',  $out['sent'], false);
$row = Db::one("SELECT * FROM messages WHERE phone_e164 = '+15035550166' ORDER BY id DESC LIMIT 1");
check('held row is QUEUED',       $row['status'], 'QUEUED');
check('held row has no failure',  $row['failure_reason'], null);

$noConsent = ['id' => 0, 'do_not_contact' => 0, 'sms_approved' => 0, 'phone_e164' => '+15035550166'];
$out = Sms::queue($noConsent, 'dispatch', ['{eta}' => '', '{total}' => '', '{doc}' => '']);
check('no consent is not ok',     $out['ok'],   false);
check('no consent is not held',   $out['held'], false);
check('no consent names why',     str_contains($out['reason'], 'consent'), true);
Db::q("DELETE FROM messages WHERE phone_e164 = '+15035550166'");

section('a misconfigured live driver cannot produce a "texted" result');
// Force the telnyx driver with no credentials: send() fails, and the result
// must say NOT SENT — the state this guards against is a dispatcher telling a
// stranded caller a text is coming that the carrier never accepted.
$rc = new ReflectionClass(Integrations::class);
$saved = $rc->getStaticPropertyValue('made');
$rc->setStaticPropertyValue('made', ['sms' => new TelnyxSmsGateway('', '', '', '')]);
$out = Sms::queue($cust, 'dispatch', ['{eta}' => '', '{total}' => '', '{doc}' => '']);
check('broken live driver is not ok',   $out['ok'],   false);
check('broken live driver is not held', $out['held'], false);
check('broken live driver is not sent', $out['sent'], false);
check('reason says it did NOT go out',  str_contains($out['reason'], 'NOT go out'), true);
$row = Db::one("SELECT * FROM messages WHERE phone_e164 = '+15035550166' ORDER BY id DESC LIMIT 1");
check('failed row records why', $row['failure_reason'] !== null && $row['failure_reason'] !== '', true);
$rc->setStaticPropertyValue('made', $saved);
Db::q("DELETE FROM messages WHERE phone_e164 = '+15035550166'");

section('Health::missingFor gates driver activation');
$none = fn(string $k): string => '';
$all  = fn(string $k): string => 'value';
// Four pieces, and the fourth is the registered 10DLC campaign: a Telnyx
// account can send without a profile ID, but then the traffic is outside the
// campaign — which is exactly the send this gate exists to prevent.
check('telnyx with nothing lists four pieces',  count(Health::missingFor('telnyx', $none)), 4);
check('telnyx fully configured passes',         Health::missingFor('telnyx', $all), []);
check('square with nothing lists three pieces', count(Health::missingFor('square', $none)), 3);
$partial = fn(string $k): string => $k === 'telnyx_api_key' ? 'key' : '';
$m = Health::missingFor('telnyx', $partial);
check('partial telnyx names what is left',      in_array('sending number', $m, true), true);
check('campaign profile is a required piece',
    (bool) array_filter(Health::missingFor('telnyx', $none), fn($s) => str_contains($s, '10DLC')), true);
check('unknown driver needs nothing',           Health::missingFor('outbox', $none), []);

section('Health checks are quiet when the offline drivers are active');
// Local settings run outbox/manual, so nothing should be flagged: the offline
// modes are legitimate, fully-working configurations, not misconfigurations.
check('smsSend quiet on outbox',    Health::smsSend(), []);
check('smsReceipts quiet on outbox', Health::smsReceipts(), []);
check('all() empty means no banner', Health::all(), []);
check('no send suspension on outbox', Health::stopSendBlock(), '');

/* ------------------------------------------------------------------ */
/* The 10DLC stop: a live driver with broken compliance sends NOTHING. */
/* App::setting caches per-process, so this runs in a child process    */
/* whose settings rows say telnyx-with-no-public-key. The carrier is   */
/* never contacted: the refusal happens before any HTTP.               */
/* ------------------------------------------------------------------ */
section('broken 10DLC compliance suspends every send');
$keys  = ['driver_sms', 'telnyx_api_key', 'telnyx_from', 'telnyx_profile_id', 'telnyx_public_key', 'app_base_url'];
$saved = [];
foreach ($keys as $k) {
    $saved[$k] = Db::one('SELECT svalue FROM settings WHERE skey = ?', [$k])['svalue'] ?? null;
}
$put = static function (string $k, ?string $v): void {
    Db::q('DELETE FROM settings WHERE skey = ?', [$k]);
    if ($v !== null) { Db::insert('settings', ['skey' => $k, 'svalue' => $v]); }
};

// telnyx active, credentialed, registered — but the STOP path is unverifiable.
foreach (['driver_sms' => 'telnyx', 'telnyx_api_key' => 'KEYtest', 'telnyx_from' => '+15035550100',
          'telnyx_profile_id' => 'profile-123', 'telnyx_public_key' => '',
          'app_base_url' => 'https://test.example'] as $k => $v) { $put($k, $v); }

$child = <<<'PHP'
<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$cfg  = require $root . '/config.php';
if (getenv('WKR_DB_DRIVER') === false && getenv('WKR_DB_PASS') === false) {
    $cfg['db']['driver'] = 'sqlite';
}
foreach (['Core', 'Db', 'Schema', 'helpers', 'Domain'] as $f) { require $root . '/app/' . $f . '.php'; }
require $root . '/app/Contracts/Contracts.php';
require $root . '/app/Services/Http.php';
require $root . '/app/Services/Services.php';
App::boot($cfg); Db::boot($cfg['db']);
if (Db::driver() === 'sqlite') { Db::migrate(); }
$cust = ['id' => 0, 'do_not_contact' => 0, 'sms_approved' => 1, 'phone_e164' => '+15035550199'];
$out  = Sms::queue($cust, (string) ($argv[1] ?? 'dispatch'), ['{eta}' => '5:00 PM', '{total}' => '', '{doc}' => '']);
$row  = Db::one("SELECT * FROM messages WHERE phone_e164 = '+15035550199' ORDER BY id DESC LIMIT 1");
echo json_encode(['out' => $out, 'row' => $row, 'block' => Health::stopSendBlock()]);
PHP;
$childFile = __DIR__ . '/_compliance_child.php';
file_put_contents($childFile, $child);
$run = static function (string $template) use ($childFile): array {
    $json = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($childFile)
        . ' ' . escapeshellarg($template));
    return json_decode((string) $json, true) ?: [];
};

// Leg 1: STOP path unverifiable (no public key) — every send is suspended.
$r = $run('dispatch');
check('child ran',                         $r !== [], true);
check('suspension reason is 10DLC',        str_contains((string) ($r['block'] ?? ''), '10DLC'), true);
check('send refused, not ok',              $r['out']['ok'] ?? null, false);
check('refusal names 10DLC to the caller', str_contains((string) ($r['out']['reason'] ?? ''), '10DLC'), true);
check('nothing reached the carrier',
    array_key_exists('provider_ref', $r['row'] ?? []) ? $r['row']['provider_ref'] : 'missing', null);
check('row holds the suspension reason',   str_contains((string) ($r['row']['failure_reason'] ?? ''), '10DLC'), true);
check('row is QUEUED, not lost',           $r['row']['status'] ?? null, 'QUEUED');

// Leg 2: compliance whole, but the body itself has no opt-out language.
// (An unknown template falls back to the generic body, which carries none.)
$put('telnyx_public_key', 'cGstdGVzdA==');
$r = $run('not_a_template');
check('compliance whole clears the block', $r['block'] ?? 'x', '');
check('missing opt-out language refused',  $r['out']['ok'] ?? null, false);
check('opt-out refusal names the cause',   str_contains((string) ($r['out']['reason'] ?? ''), 'opt-out'), true);
check('opt-out refusal never sent',
    array_key_exists('provider_ref', $r['row'] ?? []) ? $r['row']['provider_ref'] : 'missing', null);

@unlink($childFile);
foreach ($saved as $k => $v) { $put($k, $v); }
Db::q("DELETE FROM messages WHERE phone_e164 = '+15035550199'");

/* ------------------------------------------------------------------ */
/* Verbal consent changes: recorded as what they are, then enforced.   */
/* ------------------------------------------------------------------ */
section('a verbal STOP is recorded as verbal and enforced like a texted one');
$custId = Db::insert('customers', [
    'customer_type' => 'INDIVIDUAL', 'first_name' => 'Verbal', 'last_name' => 'Test',
    'phone_e164' => '+15035550155', 'sms_approved' => 1, 'do_not_contact' => 0,
    'created_at' => now(), 'updated_at' => now(),
]);
$cust = Db::one('SELECT * FROM customers WHERE id = ?', [$custId]);
$msgsBefore = (int) Db::val('SELECT COUNT(*) FROM messages');

Consent::optOut($cust, 'revoked_verbal', 'VERBAL opt-out recorded by Test Runner: said stop texting on call');
$c = Db::one('SELECT * FROM customers WHERE id = ?', [$custId]);
check('consent cleared',            (int) $c['sms_approved'], 0);
check('do-not-contact set',         (int) $c['do_not_contact'], 1);
check('source says verbal',         $c['sms_consent_source'], 'revoked_verbal');
// The core of the rule: what happened was a phone call, so NO message row.
check('no fabricated message row',  (int) Db::val('SELECT COUNT(*) FROM messages'), $msgsBefore);
$aud = Db::one("SELECT * FROM audit_log WHERE entity_type='customer' AND entity_id=? AND action='sms:opted_out'
                ORDER BY id DESC LIMIT 1", [$custId]);
check('audit says VERBAL',          str_contains((string) ($aud['detail'] ?? ''), 'VERBAL'), true);
check('audit names the recorder',   str_contains((string) ($aud['detail'] ?? ''), 'Test Runner'), true);

section('the opted-out customer cannot be texted, whatever the driver');
$gate = Sms::gate($c);
check('gate refuses after verbal stop', $gate['ok'], false);
$out = Sms::queue($c, 'dispatch', ['{eta}' => '', '{total}' => '', '{doc}' => '']);
check('queue blocks after verbal stop', $out['ok'], false);
$blocked = Db::one("SELECT * FROM messages WHERE phone_e164 = '+15035550155' ORDER BY id DESC LIMIT 1");
check('the blocked attempt IS recorded', $blocked['status'] ?? null, 'BLOCKED');

section('verbal opt-in restores, with its own evidence');
Consent::optIn($cust, 'granted_verbal', 'VERBAL opt-in recorded by Test Runner: asked for texts at counter');
$c = Db::one('SELECT * FROM customers WHERE id = ?', [$custId]);
check('consent restored',    (int) $c['sms_approved'], 1);
check('contactable again',   (int) $c['do_not_contact'], 0);
check('fresh consent stamp', $c['sms_consent_at'] !== null, true);
check('source says verbal',  $c['sms_consent_source'], 'granted_verbal');

Db::q("DELETE FROM audit_log WHERE entity_type='customer' AND entity_id=?", [$custId]);
Db::q("DELETE FROM messages WHERE phone_e164 = '+15035550155'");
Db::q('DELETE FROM customers WHERE id = ?', [$custId]);

Db::q('DELETE FROM messages WHERE provider_ref = ?', [$ref]);
printf("\n\033[1m%d passed, %d failed\033[0m\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);
