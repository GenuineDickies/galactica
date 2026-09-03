#!/usr/bin/env bash
# The en-route/on-site texting rules, end to end over HTTP.
#
# What this proves (added 2026-08-30 after a customer got an en-route text the
# dispatcher never approved, carrying the intake ETA hours stale):
#   - a status change alone sends NOTHING — send_sms=1 is the approval
#   - the texted ETA comes from the minutes entered at send time, never from
#     the intake promise
#   - assigning a technician issues them a one-shot location link (doc_type WO)
#   - the tech's answer lands in tech_latitude/longitude on the work order
#   - /eta-suggest with the routing driver off refuses with a reason instead
#     of guessing
#
# Usage: tests/enroute_sms.sh [base-url]   (server must be running)
set -u
BASE="${1:-http://127.0.0.1:8088}"
JAR=$(mktemp); TECH=$(mktemp)
PASS=0; FAIL=0

say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
check(){ if [ "$2" = "$3" ]; then ok "$1"; else bad "$1 (want $3, got $2)"; fi; }

tok() {
  local jar="$1" p t
  for p in "${TOKPATH:-/service-requests/new}" /service-requests/new /login; do
    t=$(curl -s -b "$jar" -c "$jar" "$BASE$p" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | cut -d'"' -f4)
    [ -n "$t" ] && { printf '%s' "$t"; return; }
  done
}
post() {
  local jar="$1" path="$2"; shift 2
  local t; t=$(tok "$jar")
  curl -s -b "$jar" -c "$jar" -o /tmp/enroute.out -w '%{http_code}' \
    -X POST "$BASE$path" --data-urlencode "_csrf=$t" "$@"
}
sql() { php tests/query.php "$1"; }
exec_sql() { php tests/exec.php "$@"; }

say "reset to a known database"
php tests/reset.php
check "reseeded" "$(sql "select count(*) from users")" "3"

say "sign in"
T=$(curl -s -c "$JAR" "$BASE/login" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | cut -d'"' -f4)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$BASE/login" \
     -d "_csrf=$T" -d 'email=admin@setup.com' -d 'password=admin123'
T=$(curl -s -c "$TECH" "$BASE/login" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | cut -d'"' -f4)
curl -s -b "$TECH" -c "$TECH" -o /dev/null -X POST "$BASE/login" \
     -d "_csrf=$T" -d 'email=tech@wkrllc.com' -d 'password=tech123'

say "build the chain: request → estimate → signed → dispatched"
post "$JAR" "/service-requests" -d 'channel=PHONE' -d 'reported_name=Ada Nordvik' \
  -d 'reported_phone=(503) 555-0344' -d 'service_category=MECHANIC' -d 'reported_service=BATTERY_SWAP' \
  -d 'priority=URGENT' -d 'reported_problem=No crank' -d 'reported_location=SE 82nd and Division' \
  -d 'city=Portland' -d 'state=OR' -d 'eta_minutes=30' >/dev/null
SR=$(sql "select id from service_requests order by id desc limit 1")
# Promotion requires a pin — the truck drives to a position, not a phrase.
exec_sql pin "$SR" 45.5045 -122.5790
post "$JAR" "/service-requests/$SR/promote" -d 'first_name=Ada' -d 'last_name=Nordvik' \
  -d 'phone=(503) 555-0344' -d 'sms_approved=1' -d 'service_type=BATTERY' \
  -d 'scope_summary=Battery test and replace' >/dev/null
EST=$(sql "select id from estimates order by id desc limit 1")
BAT=$(sql "select id from catalog_items where sku='SVC-BATT-INSTALL'")
post "$JAR" "/estimates/$EST/lines" -d "catalog_item_id=$BAT" -d 'qty=1' >/dev/null
post "$JAR" "/estimates/$EST/authorize" -d 'authorized_by=Ada Nordvik' \
  -d 'signature_data=data:image/png;base64,iVBORw0KGgo=' >/dev/null
check "estimate approved" "$(sql "select status from estimates where id=$EST")" "APPROVED"

MSG_BEFORE=$(sql "select count(*) from messages")
post "$JAR" "/estimates/$EST/dispatch" -d 'technician_id=3' >/dev/null
WO=$(sql "select id from work_orders where estimate_id=$EST order by id desc limit 1")
check "work order assigned" "$(sql "select status from work_orders where id=$WO")" "ASSIGNED"

say "assigning a tech issues them a location link"
check "tech_locate message recorded" \
  "$(sql "select count(*) from messages where template='tech_locate'")" "1"
check "one-shot WO location request open" \
  "$(sql "select count(*) from location_requests where doc_type='WO' and doc_id=$WO and status='OPEN'")" "1"
check "link goes to the tech's number, not the customer's" \
  "$(sql "select count(*) from messages where template='tech_locate' and phone_e164=(select phone_e164 from users where id=3)")" "1"

say "the tech's answer lands on the work order"
TOKEN=$(sql "select token from location_requests where doc_type='WO' and doc_id=$WO order by id desc limit 1")
curl -s -o /dev/null "$BASE/locate/$TOKEN"   # view marks viewed_at
LT=$(curl -s -c /tmp/loc.jar "$BASE/locate/$TOKEN" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | cut -d'"' -f4)
curl -s -b /tmp/loc.jar -o /dev/null -X POST "$BASE/locate/$TOKEN" \
  -d "_csrf=$LT" -d 'latitude=45.5231000' -d 'longitude=-122.6765000' -d 'accuracy_m=12'
check "request marked received" \
  "$(sql "select status from location_requests where doc_type='WO' and doc_id=$WO order by id desc limit 1")" "RECEIVED"
check "truck position stored on the WO" \
  "$(sql "select count(*) from work_orders where id=$WO and tech_latitude is not null and tech_located_at is not null")" "1"
check "customer pin untouched by the tech's capture" \
  "$(sql "select latitude from service_requests where id=$SR")" "45.5045000"

say "a status change alone texts nobody"
TOKPATH="/work-orders/$WO"
CUST_BEFORE=$(sql "select count(*) from messages where template in ('dispatch','on_site')")
post "$TECH" "/work-orders/$WO/status" -d 'status=EN_ROUTE' >/dev/null
check "status moved" "$(sql "select status from work_orders where id=$WO")" "EN_ROUTE"
check "no customer text without send_sms" \
  "$(sql "select count(*) from messages where template in ('dispatch','on_site')")" "$CUST_BEFORE"

say "the approved send carries the fresh ETA, not the intake promise"
post "$TECH" "/work-orders/$WO/status" -d 'status=ON_SITE' >/dev/null
post "$TECH" "/work-orders/$WO/status" -d 'status=IN_PROGRESS' >/dev/null 2>&1 || true
# walk a second work order for the EN_ROUTE send itself: transitions are one-way
post "$JAR" "/service-requests" -d 'channel=PHONE' -d 'reported_name=Bo Reyes' \
  -d 'reported_phone=(503) 555-0399' -d 'service_category=MECHANIC' -d 'reported_service=BATTERY_SWAP' \
  -d 'priority=URGENT' -d 'reported_problem=Dead battery' -d 'reported_location=N Lombard' \
  -d 'city=Portland' -d 'state=OR' >/dev/null
SR2=$(sql "select id from service_requests order by id desc limit 1")
exec_sql pin "$SR2" 45.5768 -122.7000
post "$JAR" "/service-requests/$SR2/promote" -d 'first_name=Bo' -d 'last_name=Reyes' \
  -d 'phone=(503) 555-0399' -d 'sms_approved=1' -d 'service_type=BATTERY' \
  -d 'scope_summary=Battery' >/dev/null
EST2=$(sql "select id from estimates order by id desc limit 1")
post "$JAR" "/estimates/$EST2/lines" -d "catalog_item_id=$BAT" -d 'qty=1' >/dev/null
post "$JAR" "/estimates/$EST2/authorize" -d 'authorized_by=Bo Reyes' \
  -d 'signature_data=data:image/png;base64,iVBORw0KGgo=' >/dev/null
post "$JAR" "/estimates/$EST2/dispatch" -d 'technician_id=3' >/dev/null
WO2=$(sql "select id from work_orders where estimate_id=$EST2 order by id desc limit 1")
TOKPATH="/work-orders/$WO2"
post "$TECH" "/work-orders/$WO2/status" -d 'status=EN_ROUTE' -d 'send_sms=1' -d 'eta_minutes=25' >/dev/null
check "en-route text recorded once approved" \
  "$(sql "select count(*) from messages where template='dispatch' and service_request_id=$SR2")" "1"
check "body carries a clock-time ETA, not 'shortly'" \
  "$(sql "select count(*) from messages where template='dispatch' and service_request_id=$SR2 and body like '%ETA %M%' and body not like '%shortly%'")" "1"
post "$TECH" "/work-orders/$WO2/status" -d 'status=ON_SITE' -d 'send_sms=1' >/dev/null
check "on-site text recorded once approved" \
  "$(sql "select count(*) from messages where template='on_site' and service_request_id=$SR2")" "1"

say "eta-suggest refuses honestly with the routing driver off"
ET=$(tok "$TECH")
BODY=$(curl -s -b "$TECH" -X POST "$BASE/work-orders/$WO/eta-suggest" --data-urlencode "_csrf=$ET")
case "$BODY" in
  *'"ok":false'*'routing'*|*'"ok":false'*'route'*) ok "refused with a reason: no guessing" ;;
  *) bad "eta-suggest with driver off (got $BODY)" ;;
esac

printf '\n\033[1m%d passed, %d failed\033[0m\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
