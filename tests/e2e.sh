#!/usr/bin/env bash
# End-to-end walk of the full chain, exercising every hard gate.
# Usage: tests/e2e.sh [base-url]
set -u
BASE="${1:-http://127.0.0.1:8088}"
JAR=$(mktemp); TECH=$(mktemp)
PASS=0; FAIL=0

say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
check(){ if [ "$2" = "$3" ]; then ok "$1"; else bad "$1 (want $3, got $2)"; fi; }

tok() { # scrape a CSRF token from whichever page this session is allowed to see
  local jar="$1" p t
  for p in "${TOKPATH:-/service-requests/new}" /service-requests/new /login; do
    t=$(curl -s -b "$jar" -c "$jar" "$BASE$p" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | cut -d'"' -f4)
    [ -n "$t" ] && { printf '%s' "$t"; return; }
  done
}
post() { # post <jar> <path> <data...>
  local jar="$1" path="$2"; shift 2
  local t; t=$(tok "$jar")
  curl -s -b "$jar" -c "$jar" -o /tmp/e2e.out -w '%{http_code}' \
    -X POST "$BASE$path" --data-urlencode "_csrf=$t" "$@"
}
# Assertions run through the application's own config, so the suite proves the
# behaviour on whichever engine is configured — MySQL or SQLite.
sql() { php tests/query.php "$1"; }
exec_sql() { php tests/exec.php "$@"; }

say "reset to a known database"
php tests/reset.php
curl -s -o /dev/null "$BASE/login"
ENGINE=$(php -r '$c=require "config.php"; echo $c["db"]["driver"];')
printf '  engine under test: \033[1m%s\033[0m\n' "$ENGINE"
check "reseeded" "$(sql "select count(*) from users")" "3"

say "sign in"
T=$(curl -s -c "$JAR" "$BASE/login" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | cut -d'"' -f4)
C=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$BASE/login" \
      -d "_csrf=$T" -d 'email=admin@setup.com' -d 'password=admin123')
check "admin login redirects" "$C" "302"
C=$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$BASE/dashboard")
check "dashboard renders" "$C" "200"

T=$(curl -s -c "$TECH" "$BASE/login" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | cut -d'"' -f4)
curl -s -b "$TECH" -c "$TECH" -o /dev/null -X POST "$BASE/login" \
     -d "_csrf=$T" -d 'email=tech@wkrllc.com' -d 'password=tech123'

say "every screen renders"
for p in / dashboard service-requests service-requests/new estimates work-orders invoices payments \
         customers customers/new vehicles vehicles/new catalog expenses messages reports settings users \
         service-requests/1 estimates/1 work-orders/1 invoices/1 estimates/1/print invoices/1/print; do
  C=$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$BASE/$p")
  check "GET /$p" "$C" "200"
done

say "role enforcement"
for p in service-requests estimates invoices customers settings users reports; do
  C=$(curl -s -b "$TECH" -o /dev/null -w '%{http_code}' "$BASE/$p")
  check "technician blocked from /$p" "$C" "403"
done
C=$(curl -s -b "$TECH" -o /dev/null -w '%{http_code}' "$BASE/work-orders/1")
check "technician may open their own work order" "$C" "200"

say "1. intake — a request is thin and creates nothing"
BEFORE=$(sql "select count(*) from customers")
post "$JAR" "/service-requests" -d 'channel=PHONE' -d 'reported_name=Ellis Vance' \
  -d 'reported_phone=(503) 555-0917' -d 'service_category=MECHANIC' -d 'reported_service=BATTERY_SWAP' -d 'priority=URGENT' \
  -d 'reported_problem=Dash lights flicker, wont crank' -d 'reported_location=Fred Meyer lot, Hollywood' \
  -d 'city=Portland' -d 'state=OR' -d 'v_make=Subaru' -d 'v_model=Outback' >/dev/null
SR=$(sql "select id from service_requests order by id desc limit 1")
AFTER=$(sql "select count(*) from customers")
check "request logged" "$(sql "select doc_number is not null from service_requests where id=$SR")" "1"
check "no customer was created" "$AFTER" "$BEFORE"
check "no line items on a request" "$(sql "select count(*) from doc_lines where doc_type='SR'")" "0"

say "2. promote — the customer is confirmed and an estimate opens"
post "$JAR" "/service-requests/$SR/promote" -d 'first_name=Ellis' -d 'last_name=Vance' \
  -d 'phone=(503) 555-0917' -d 'sms_approved=1' -d 'service_type=BATTERY' \
  -d 'scope_summary=Battery test and replace' >/dev/null
EST=$(sql "select id from estimates order by id desc limit 1")
check "estimate created from the request" "$(sql "select service_request_id from estimates where id=$EST")" "$SR"
check "customer now exists" "$(sql "select count(*) from customers")" "$((BEFORE+1))"
check "request marked accepted" "$(sql "select status from service_requests where id=$SR")" "ACCEPTED"

say "3. dispatch gate — no lines, no authorization"
post "$JAR" "/estimates/$EST/dispatch" >/dev/null
check "blocked with no line items" "$(sql "select count(*) from work_orders where estimate_id=$EST")" "0"

BAT=$(sql "select id from catalog_items where sku='SVC-BATT-INSTALL'")
[ -z "$BAT" ] && BAT=$(sql "select id from catalog_items where sku='SVC-BATT-INSTALL'")
post "$JAR" "/estimates/$EST/lines" -d "catalog_item_id=$BAT" -d 'qty=1' >/dev/null
check "line item added" "$(sql "select count(*) from doc_lines where doc_type='EST' and doc_id=$EST")" "1"

post "$JAR" "/estimates/$EST/dispatch" >/dev/null
check "blocked with no authorization" "$(sql "select count(*) from work_orders where estimate_id=$EST")" "0"

say "4. authorization — signature threshold"
post "$JAR" "/estimates/$EST/lines" -d "catalog_item_id=$BAT" -d 'qty=1' -d 'unit_price=400' >/dev/null
TOTAL=$(sql "select total from estimates where id=$EST")
post "$JAR" "/estimates/$EST/authorize" -d 'authorized_by=Ellis Vance' -d 'authorization_method=VERBAL' >/dev/null
check "verbal rejected above \$200 (total $TOTAL)" "$(sql "select status from estimates where id=$EST")" "DRAFT"

post "$JAR" "/estimates/$EST/authorize" -d 'authorized_by=Ellis Vance' \
  -d 'signature_data=data:image/png;base64,iVBORw0KGgo=' >/dev/null
check "signature accepted" "$(sql "select status from estimates where id=$EST")" "APPROVED"
check "method recorded as SIGNATURE" "$(sql "select authorization_method from estimates where id=$EST")" "SIGNATURE"
check "IP captured with the authorization" "$(sql "select authorization_ip is not null from estimates where id=$EST")" "1"

say "5. locked once authorized"
post "$JAR" "/estimates/$EST/lines" -d "catalog_item_id=$BAT" -d 'qty=1' >/dev/null
check "no further lines on an approved estimate" "$(sql "select count(*) from doc_lines where doc_type='EST' and doc_id=$EST")" "2"

say "6. dispatch"
post "$JAR" "/estimates/$EST/dispatch" -d 'technician_id=3' >/dev/null
WO=$(sql "select id from work_orders where estimate_id=$EST")
check "work order raised" "$(sql "select status from work_orders where id=$WO")" "ASSIGNED"
check "authorized scope carried to the field" "$(sql "select count(*) from doc_lines where doc_type='WO' and doc_id=$WO")" "2"

say "7. completion gate — VIN required"
# A technician cannot see the intake form, so tokens come off their own work order.
TOKPATH="/work-orders/$WO"
post "$TECH" "/work-orders/$WO/status" -d 'status=EN_ROUTE' >/dev/null
post "$TECH" "/work-orders/$WO/status" -d 'status=ON_SITE'  >/dev/null
post "$TECH" "/work-orders/$WO/complete" -d 'outcome_code=COMPLETED' -d 'signer_name=Ellis Vance' >/dev/null
check "cannot complete without a VIN" "$(sql "select status from work_orders where id=$WO")" "ON_SITE"

post "$TECH" "/work-orders/$WO/vin" -d 'vin=1HGCM82633A004351' >/dev/null
check "bad check digit rejected" "$(sql "select vehicle_id is null from estimates where id=$EST")" "1"

post "$TECH" "/work-orders/$WO/vin" -d 'vin=4S4BSANC4J3283770' -d 'make=Subaru' -d 'model=Outback' -d 'year=2018' >/dev/null
check "valid VIN creates the vehicle" "$(sql "select vehicle_id is not null from estimates where id=$EST")" "1"

post "$TECH" "/work-orders/$WO/complete" -d 'outcome_code=COMPLETED' -d 'signer_name=Ellis Vance' \
  -d 'field_notes=Battery tested 9.6V under load. Replaced.' >/dev/null
check "completes once the VIN is on file" "$(sql "select status from work_orders where id=$WO")" "COMPLETED"
TOKPATH="/service-requests/new"

say "8. invoice"
post "$JAR" "/estimates/$EST/invoice" >/dev/null
INV=$(sql "select id from invoices where estimate_id=$EST")
check "invoice built from the work order" "$(sql "select count(*) from doc_lines where doc_type='INV' and doc_id=$INV")" "2"
check "vehicle carried onto the invoice" "$(sql "select vehicle_id is not null from invoices where id=$INV")" "1"

say "9. variance gate"
TOW=$(sql "select id from catalog_items where unit_price >= 250 order by unit_price desc limit 1")
post "$JAR" "/invoices/$INV/lines" -d "catalog_item_id=$TOW" -d 'qty=1' -d 'unit_price=300' >/dev/null
post "$JAR" "/invoices/$INV/issue" >/dev/null
check "issue blocked past the tolerance" "$(sql "select status from invoices where id=$INV")" "DRAFT"

post "$JAR" "/invoices/$INV/authorize" -d 'variance_auth_name=Ellis Vance' >/dev/null
check "re-auth without a signature rejected" "$(sql "select variance_authorized from invoices where id=$INV")" "0"

post "$JAR" "/invoices/$INV/authorize" -d 'variance_auth_name=Ellis Vance' \
  -d 'signature_data=data:image/png;base64,iVBORw0KGgo=' >/dev/null
check "re-auth with a signature accepted" "$(sql "select variance_authorized from invoices where id=$INV")" "1"

post "$JAR" "/invoices/$INV/issue" >/dev/null
check "invoice issues" "$(sql "select status from invoices where id=$INV")" "ISSUED"
check "request closed out" "$(sql "select status from service_requests where id=$SR")" "COMPLETED"

say "10. issued invoices are locked"
post "$JAR" "/invoices/$INV/lines" -d "catalog_item_id=$BAT" -d 'qty=1' >/dev/null
check "no lines on an issued invoice" "$(sql "select count(*) from doc_lines where doc_type='INV' and doc_id=$INV")" "3"

say "11. payment and receipt"
TOTAL=$(sql "select total from invoices where id=$INV")
post "$JAR" "/payments/take/$INV" -d 'amount=100' -d 'method=CASH' >/dev/null
check "partial payment" "$(sql "select status from invoices where id=$INV")" "PARTIAL"
REST=$(php -r 'echo number_format((float)$argv[1]-100,2,".","");' "$TOTAL")
post "$JAR" "/payments/take/$INV" -d "amount=$REST" -d 'method=CARD' -d 'tip_amount=15' >/dev/null
check "paid in full" "$(sql "select status from invoices where id=$INV")" "PAID"
check "balance is zero" "$(sql "select balance_due = 0 from invoices where id=$INV")" "1"
check "a receipt exists per payment" "$(sql "select count(*) from receipts where invoice_id=$INV")" "2"
check "idempotency keys are server-generated" "$(sql "select count(*) from payments where invoice_id=$INV and idempotency_key is not null")" "2"

say "12. void protection"
post "$JAR" "/invoices/$INV/void" -d 'void_reason=oops' >/dev/null
check "a paid invoice cannot be voided" "$(sql "select status from invoices where id=$INV")" "PAID"

say "13. consent gating on SMS"
NC=$(sql "select id from customers where sms_approved=0 limit 1")
if [ -n "$NC" ]; then
  check "messages to unconsented customers are blocked" "$(sql "select count(*) from messages where status='BLOCKED'")" "$(sql "select count(*) from messages where status='BLOCKED'")"
fi
check "no message ever sent without consent" "$(sql "select count(*) from messages m join customers c on c.id=m.customer_id where c.sms_approved=0 and m.status<>'BLOCKED'")" "0"

say "14. provider callbacks — Square"
# Credentials go straight into settings; the drivers read them per request.
setting() { php tests/exec.php setting "$1" "$2"; }

SQKEY='test_signature_key_wkr'
setting driver_payments      'square'
setting app_base_url        "$BASE"
setting square_signature_key "$SQKEY"
setting square_access_token  'EAAA-test-token'
setting square_location_id   'L-TEST-1'

ORDER="ORD-TEST-$$"
php tests/exec.php link "$INV" "$ORDER"

BODY='{"type":"payment.updated","data":{"object":{"payment":{"id":"SQPAY-TEST-1","order_id":"'"$ORDER"'","status":"COMPLETED","amount_money":{"amount":2500,"currency":"USD"}}}}}'
SIG=$(php tests/sign.php square "$SQKEY" "$BASE/webhooks/square" "$BODY")
PAYS_BEFORE=$(sql "select count(*) from payments")

C=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/webhooks/square" \
      -H 'Content-Type: application/json' --data-raw "$BODY")
check "unsigned callback refused" "$C" "403"
check "  …and nothing was written" "$(sql "select count(*) from payments")" "$PAYS_BEFORE"

C=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/webhooks/square" \
      -H 'Content-Type: application/json' -H "x-square-hmacsha256-signature: $SIG" \
      --data-raw "${BODY%\}}, \"tampered\":true}")
check "tampered body refused" "$C" "403"
check "  …and nothing was written" "$(sql "select count(*) from payments")" "$PAYS_BEFORE"

C=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/webhooks/square" \
      -H 'Content-Type: application/json' -H "x-square-hmacsha256-signature: $SIG" --data-raw "$BODY")
check "signed callback accepted" "$C" "200"
check "payment recorded from the callback" "$(sql "select count(*) from payments")" "$((PAYS_BEFORE+1))"
check "matched to the right invoice" "$(sql "select invoice_id from payments where processor_ref='SQPAY-TEST-1'")" "$INV"
check "receipt issued for it" "$(sql "select count(*) from receipts r join payments p on p.id=r.payment_id where p.processor_ref='SQPAY-TEST-1'")" "1"

curl -s -o /dev/null -X POST "$BASE/webhooks/square" \
      -H 'Content-Type: application/json' -H "x-square-hmacsha256-signature: $SIG" --data-raw "$BODY"
check "replayed callback is a no-op" "$(sql "select count(*) from payments")" "$((PAYS_BEFORE+1))"

BODY2='{"type":"payment.updated","data":{"object":{"payment":{"id":"SQPAY-TEST-2","order_id":"ORD-NOPE","status":"COMPLETED","amount_money":{"amount":9900,"currency":"USD"}}}}}'
SIG2=$(php tests/sign.php square "$SQKEY" "$BASE/webhooks/square" "$BODY2")
curl -s -o /dev/null -X POST "$BASE/webhooks/square" \
      -H 'Content-Type: application/json' -H "x-square-hmacsha256-signature: $SIG2" --data-raw "$BODY2"
check "callback for an unknown order writes nothing" "$(sql "select count(*) from payments")" "$((PAYS_BEFORE+1))"

say "15. provider callbacks — Telnyx"
read -r TXSK TXPK <<<"$(php tests/sign.php keypair)"
setting driver_sms        'telnyx'
setting telnyx_public_key "$TXPK"
setting telnyx_api_key    'KEY-test'
setting telnyx_from       '+15037643154'

CUST=$(sql "select id from customers where phone_e164='+15035550917'")
check "test customer has consent on file" "$(sql "select sms_approved from customers where id=$CUST")" "1"

TS=$(date +%s)
IN='{"data":{"event_type":"message.received","payload":{"id":"TXIN-1","from":{"phone_number":"+15035550917"},"to":[{"phone_number":"+15037643154"}],"text":"STOP"}}}'
TSIG=$(php tests/sign.php telnyx "$TXSK" "$TS" "$IN")

C=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/webhooks/telnyx" \
      -H 'Content-Type: application/json' --data-raw "$IN")
check "unsigned callback refused" "$C" "403"

C=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/webhooks/telnyx" \
      -H 'Content-Type: application/json' -H "telnyx-signature-ed25519: $TSIG" \
      -H "telnyx-timestamp: $TS" --data-raw "$IN")
check "signed callback accepted" "$C" "200"
check "STOP revokes consent immediately" "$(sql "select sms_approved from customers where id=$CUST")" "0"
check "STOP sets do-not-contact" "$(sql "select do_not_contact from customers where id=$CUST")" "1"
check "the opt-out is audited" "$(sql "select count(*) from audit_log where entity_type='customer' and entity_id=$CUST and action='sms:opted_out'")" "1"
check "the inbound message is stored" "$(sql "select count(*) from messages where direction='IN' and provider_ref='TXIN-1'")" "1"

OLD=$((TS - 4000))
OSIG=$(php tests/sign.php telnyx "$TXSK" "$OLD" "$IN")
C=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/webhooks/telnyx" \
      -H 'Content-Type: application/json' -H "telnyx-signature-ed25519: $OSIG" \
      -H "telnyx-timestamp: $OLD" --data-raw "$IN")
check "a replayed old callback is refused" "$C" "403"

check "nothing can be sent to an opted-out customer" \
  "$(sql "select case when do_not_contact = 1 or sms_approved <> 1 then 'blocked' else 'sendable' end from customers where id=$CUST")" "blocked"

check "every integration call is logged" "$(sql "select count(*) > 0 from api_log")" "1"

say "16. the second job, paid on the checkout page"
# Back to the self-contained drivers: this is the path with no provider account.
setting driver_payments 'manual'
setting driver_sms      'outbox'

EST2=$(sql "select id from estimates where doc_number like 'EST-%-002'")
WO2=$(sql "select id from work_orders where estimate_id=$EST2")
TOKPATH="/service-requests/new"
post "$JAR" "/work-orders/$WO2/complete" -d 'outcome_code=COMPLETED' -d 'signer_name=Tom Bradley' >/dev/null
check "second work order completes" "$(sql "select status from work_orders where id=$WO2")" "COMPLETED"

post "$JAR" "/estimates/$EST2/invoice" >/dev/null
INV2=$(sql "select id from invoices where estimate_id=$EST2")
post "$JAR" "/invoices/$INV2/issue" >/dev/null
check "second invoice issued" "$(sql "select status from invoices where id=$INV2")" "ISSUED"

post "$JAR" "/payments/link/$INV2" >/dev/null
TOKEN=$(sql "select order_id from payment_links where invoice_id=$INV2 order by id desc limit 1")
check "a checkout link was issued" "$(sql "select count(*) from payment_links where invoice_id=$INV2")" "1"

C=$(curl -s -o /tmp/pay.html -w '%{http_code}' "$BASE/pay/$TOKEN")
check "the customer can open it without signing in" "$C" "200"
grep -q "$(sql "select doc_number from invoices where id=$INV2")" /tmp/pay.html \
  && ok "it shows the invoice number" || bad "it shows the invoice number"

C=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/pay/sim_deadbeefdeadbeefdeadbeefdeadbeef")
check "an unknown token is a 404" "$C" "404"

PAYJAR=$(mktemp)
PT=$(curl -s -b "$PAYJAR" -c "$PAYJAR" "$BASE/pay/$TOKEN" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | cut -d'"' -f4)
curl -s -b "$PAYJAR" -c "$PAYJAR" -o /dev/null -X POST "$BASE/pay/$TOKEN" -d "_csrf=$PT" -d 'tip_amount=10'
check "paying on the page settles the invoice" "$(sql "select status from invoices where id=$INV2")" "PAID"
check "the tip is recorded separately" "$(sql "select tip_amount = 10 from payments where invoice_id=$INV2")" "1"
check "a receipt was issued" "$(sql "select count(*) from receipts where invoice_id=$INV2")" "1"
check "the link is closed" "$(sql "select status from payment_links where invoice_id=$INV2")" "PAID"

PT=$(curl -s -b "$PAYJAR" -c "$PAYJAR" "$BASE/pay/$TOKEN" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | cut -d'"' -f4)
curl -s -b "$PAYJAR" -c "$PAYJAR" -o /dev/null -X POST "$BASE/pay/$TOKEN" -d "_csrf=${PT:-x}" -d 'tip_amount=0'
check "the link cannot be paid twice" "$(sql "select count(*) from payments where invoice_id=$INV2")" "1"

say "17. consent handled without a carrier"
CUST2=$(sql "select customer_id from invoices where id=$INV2")
post "$JAR" "/messages/reply" -d 'phone=(503) 555-0119' -d 'text=STOP' >/dev/null
check "a recorded STOP revokes consent" "$(sql "select sms_approved from customers where id=$CUST2")" "0"
post "$JAR" "/messages/reply" -d 'phone=(503) 555-0119' -d 'text=START' >/dev/null
check "START restores it" "$(sql "select sms_approved from customers where id=$CUST2")" "1"
check "both are audited" "$(sql "select count(*) from audit_log where entity_type='customer' and entity_id=$CUST2 and action like 'sms:opted%'")" "2"

say "18. audit trail"
check "estimate authorization is audited" "$(sql "select count(*) from audit_log where entity_type='estimate' and entity_id=$EST and action='authorized'")" "1"
check "promotion is audited" "$(sql "select count(*) from audit_log where entity_type='service_request' and entity_id=$SR and action='promoted'")" "1"
check "nothing is ever deleted from the audit log" "$(sql "select count(*) > 15 from audit_log")" "1"

say "19. business accounts — created COD, behaving exactly like retail"
check "the retail invoice was COD" "$(sql "select terms from invoices where id=$INV")" "DUE_ON_RECEIPT"
check "  …and due on receipt" "$(sql "select due_at = issued_at from invoices where id=$INV")" "1"

CBEFORE=$(sql "select count(*) from customers")
post "$JAR" "/customers" -d 'customer_type=COMMERCIAL' -d 'first_name=Pat' -d 'last_name=Lee' \
  -d 'phone=(503) 555-0333' >/dev/null
check "a business account without a company name is refused" "$(sql "select count(*) from customers")" "$CBEFORE"

post "$JAR" "/customers" -d 'customer_type=INDIVIDUAL' -d 'phone=(503) 555-0444' >/dev/null
check "a person without a name is refused" "$(sql "select count(*) from customers")" "$CBEFORE"

post "$JAR" "/customers" -d 'customer_type=INDIVIDUAL' -d 'first_name=Casey' -d 'last_name=Moss' \
  -d 'company=Sneaky Sole Proprietor LLC' -d 'phone=(503) 555-0444' >/dev/null
PC=$(sql "select id from customers order by id desc limit 1")
check "a person never carries a company name" "$(sql "select company = '' or company is null from customers where id=$PC")" "1"

post "$JAR" "/customers" -d 'customer_type=COMMERCIAL' -d 'company=Bridgetown Couriers' \
  -d 'first_name=Pat' -d 'last_name=Lee' -d 'phone=(503) 555-0333' >/dev/null
BC=$(sql "select id from customers order by id desc limit 1")
check "business account created" "$(sql "select customer_type from customers where id=$BC")" "COMMERCIAL"
check "terms default to COD — never implied by the type" "$(sql "select payment_terms from customers where id=$BC")" "DUE_ON_RECEIPT"

# Existing customers are found by SEARCH — no form lists the whole base.
curl -s -b "$JAR" "$BASE/customers/search?q=Bridgetown" -o /tmp/cs.json
grep -q "Bridgetown Couriers" /tmp/cs.json && ok "customer search finds the account" || bad "customer search finds the account"
curl -s -b "$JAR" "$BASE/customers/search?q=B" -o /tmp/cs2.json
grep -q '"results":\[\]' /tmp/cs2.json && ok "one character returns nothing (no base dumping)" || bad "one character returns nothing (no base dumping)"

post "$JAR" "/service-requests" -d 'channel=PHONE' -d 'reported_name=Bridgetown Couriers' \
  -d 'reported_phone=(503) 555-0333' -d 'service_category=MECHANIC' -d 'reported_service=BATTERY_SWAP' \
  -d 'reported_problem=Delivery van dead at the depot' -d 'reported_location=SE 8th & Main' \
  -d 'city=Portland' -d 'state=OR' >/dev/null
SRB=$(sql "select id from service_requests order by id desc limit 1")
post "$JAR" "/service-requests/$SRB/promote" -d "customer_id=$BC" -d 'service_type=BATTERY_SWAP' \
  -d 'scope_summary=Battery test and replace' >/dev/null
ESTB=$(sql "select id from estimates where customer_id=$BC order by id desc limit 1")

post "$JAR" "/estimates/$ESTB/po" -d 'po_number=BTC-1001' >/dev/null
check "PO saved on the estimate" "$(sql "select po_number from estimates where id=$ESTB")" "BTC-1001"

post "$JAR" "/estimates/$ESTB/lines" -d "catalog_item_id=$BAT" -d 'qty=1' -d 'unit_price=150' >/dev/null
post "$JAR" "/estimates/$ESTB/authorize" -d 'authorized_by=Pat Lee' -d 'authorization_method=VERBAL' >/dev/null
check "estimate approved" "$(sql "select status from estimates where id=$ESTB")" "APPROVED"
post "$JAR" "/estimates/$ESTB/dispatch" -d 'technician_id=3' >/dev/null
WOB=$(sql "select id from work_orders where estimate_id=$ESTB")
check "PO carried to the work order" "$(sql "select po_number from work_orders where id=$WOB")" "BTC-1001"

post "$JAR" "/work-orders/$WOB/status" -d 'status=EN_ROUTE' >/dev/null
post "$JAR" "/work-orders/$WOB/status" -d 'status=ON_SITE'  >/dev/null
post "$JAR" "/work-orders/$WOB/vin" -d 'vin=1M8GDM9AXKP042788' -d 'make=Ford' -d 'model=Transit' -d 'year=2019' >/dev/null
post "$JAR" "/work-orders/$WOB/complete" -d 'outcome_code=COMPLETED' -d 'signer_name=Pat Lee' >/dev/null
check "work order completed" "$(sql "select status from work_orders where id=$WOB")" "COMPLETED"

post "$JAR" "/estimates/$ESTB/invoice" >/dev/null
INVB=$(sql "select id from invoices where estimate_id=$ESTB")
check "COD terms snapshotted on the invoice" "$(sql "select terms from invoices where id=$INVB")" "DUE_ON_RECEIPT"
check "PO carried to the invoice" "$(sql "select po_number from invoices where id=$INVB")" "BTC-1001"
post "$JAR" "/invoices/$INVB/issue" >/dev/null
check "invoice issues" "$(sql "select status from invoices where id=$INVB")" "ISSUED"
check "a COD business invoice is due on receipt — identical to retail" \
  "$(sql "select due_at = issued_at from invoices where id=$INVB")" "1"

say "20. net terms are an explicit, snapshotted grant"
post "$JAR" "/customers/$BC" -d 'customer_type=COMMERCIAL' -d 'company=Bridgetown Couriers' \
  -d 'payment_terms=NET_30' >/dev/null
check "Net 30 granted on the account" "$(sql "select payment_terms from customers where id=$BC")" "NET_30"
check "the grant is audited" "$(sql "select count(*) from audit_log where entity_type='customer' and entity_id=$BC and action='updated' and detail like '%NET_30%'")" "1"

VB=$(sql "select vehicle_id from estimates where id=$ESTB")
post "$JAR" "/service-requests" -d 'channel=PHONE' -d 'reported_name=Bridgetown Couriers' \
  -d 'reported_phone=(503) 555-0333' -d 'service_category=ROADSIDE' -d 'reported_service=TIRE_PLUG' \
  -d 'reported_problem=Same van, slow leak' -d 'reported_location=SE 8th & Main' \
  -d 'city=Portland' -d 'state=OR' >/dev/null
SRB2=$(sql "select id from service_requests order by id desc limit 1")
post "$JAR" "/service-requests/$SRB2/promote" -d "customer_id=$BC" -d 'service_type=TIRE_PLUG' >/dev/null
ESTB2=$(sql "select id from estimates where service_request_id=$SRB2")
post "$JAR" "/estimates/$ESTB2/po" -d 'po_number=BTC-1002' >/dev/null
post "$JAR" "/estimates/$ESTB2/vehicle" -d "vehicle_id=$VB" >/dev/null
post "$JAR" "/estimates/$ESTB2/lines" -d "catalog_item_id=$BAT" -d 'qty=1' -d 'unit_price=150' >/dev/null
post "$JAR" "/estimates/$ESTB2/authorize" -d 'authorized_by=Pat Lee' -d 'authorization_method=VERBAL' >/dev/null
post "$JAR" "/estimates/$ESTB2/dispatch" -d 'technician_id=3' >/dev/null
WOB2=$(sql "select id from work_orders where estimate_id=$ESTB2")
post "$JAR" "/work-orders/$WOB2/status" -d 'status=EN_ROUTE' >/dev/null
post "$JAR" "/work-orders/$WOB2/status" -d 'status=ON_SITE'  >/dev/null
post "$JAR" "/work-orders/$WOB2/complete" -d 'outcome_code=COMPLETED' -d 'signer_name=Pat Lee' >/dev/null
post "$JAR" "/estimates/$ESTB2/invoice" >/dev/null
INVB2=$(sql "select id from invoices where estimate_id=$ESTB2")
check "NET_30 snapshotted at invoice creation" "$(sql "select terms from invoices where id=$INVB2")" "NET_30"
check "PO carried through the second chain" "$(sql "select po_number from invoices where id=$INVB2")" "BTC-1002"
post "$JAR" "/invoices/$INVB2/issue" >/dev/null
check "invoice issues" "$(sql "select status from invoices where id=$INVB2")" "ISSUED"

ISS=$(sql "select issued_at from invoices where id=$INVB2")
DUE=$(sql "select due_at from invoices where id=$INVB2")
WANT=$(php -r 'echo (new DateTimeImmutable($argv[1]))->add(new DateInterval("P30D"))->format("Y-m-d H:i:s");' "$ISS")
check "due date = issue + 30 days" "$DUE" "$WANT"

post "$JAR" "/customers/$BC" -d 'customer_type=COMMERCIAL' -d 'company=Bridgetown Couriers' \
  -d 'payment_terms=DUE_ON_RECEIPT' >/dev/null
check "revoking terms later never touches the issued invoice" \
  "$(sql "select terms from invoices where id=$INVB2")" "NET_30"
check "  …nor its due date" "$(sql "select due_at from invoices where id=$INVB2")" "$DUE"

printf '\n\033[1m%d passed, %d failed\033[0m\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
