#!/usr/bin/env bash
# Hyros API smoke test (Git Bash friendly).
# Usage:
#   HYROS_API_KEY="API_..." bash smoke-hyros.sh

set -u

API_KEY="${HYROS_API_KEY:-}"
BASE="${HYROS_API_BASE:-https://api.hyros.com/v1/api/v1.0}"
CURL_CONNECT_TIMEOUT="${HYROS_CURL_CONNECT_TIMEOUT:-10}"
CURL_MAX_TIME="${HYROS_CURL_MAX_TIME:-30}"
CURL_RETRY_COUNT="${HYROS_CURL_RETRY_COUNT:-0}"

if [[ -z "${API_KEY}" ]]; then
  echo "Missing API key."
  echo "Usage:"
  echo "  HYROS_API_KEY=\"API_...\" bash smoke-hyros.sh"
  exit 1
fi

timestamp_utc="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
suffix="$(date +%s)-${RANDOM}"

test_email="hyros-smoke-${suffix}@example.com"
session_id="hyros-smoke-session-${suffix}"
order_id="hyros-smoke-order-${suffix}"
subscription_id="hyros-smoke-sub-${suffix}"

pass_count=0
fail_count=0

print_header() {
  local title="$1"
  echo
  echo "=== ${title} ==="
}

request_json() {
  local method="$1"
  local endpoint="$2"
  local payload="${3:-}"
  local response
  local body
  local http_code

  if [[ -n "${payload}" ]]; then
    response="$(curl -sS -X "${method}" \
      --connect-timeout "${CURL_CONNECT_TIMEOUT}" \
      --max-time "${CURL_MAX_TIME}" \
      --retry "${CURL_RETRY_COUNT}" \
      -H "API-Key: ${API_KEY}" \
      -H "Content-Type: application/json" \
      --data-raw "${payload}" \
      "${BASE}/${endpoint}" \
      -w $'\nHTTP_CODE:%{http_code}')"
  else
    response="$(curl -sS -X "${method}" \
      --connect-timeout "${CURL_CONNECT_TIMEOUT}" \
      --max-time "${CURL_MAX_TIME}" \
      --retry "${CURL_RETRY_COUNT}" \
      -H "API-Key: ${API_KEY}" \
      "${BASE}/${endpoint}" \
      -w $'\nHTTP_CODE:%{http_code}')"
  fi

  body="${response%$'\n'HTTP_CODE:*}"
  http_code="${response##*$'\n'HTTP_CODE:}"

  echo "${body}"
  echo "HTTP_CODE:${http_code}"

  if [[ "${http_code}" =~ ^2[0-9][0-9]$ ]]; then
    pass_count=$((pass_count + 1))
    return 0
  fi

  fail_count=$((fail_count + 1))
  return 1
}

lead_payload="$(cat <<JSON
{"email":"${test_email}","firstName":"Hyros","lastName":"Smoke","tag":"!smoke-test"}
JSON
)"

click_payload="$(cat <<JSON
{"email":"${test_email}","referrerUrl":"https://example.com","sessionId":"${session_id}","tag":"!clicked","isOrganic":true,"date":"${timestamp_utc}"}
JSON
)"

cart_payload="$(cat <<JSON
{"email":"${test_email}","items":[{"name":"Smoke Product","price":10,"quantity":1,"externalId":"smoke-123"}],"date":"${timestamp_utc}","currency":"USD"}
JSON
)"

order_payload="$(cat <<JSON
{"email":"${test_email}","items":[{"name":"Smoke Product","price":10,"quantity":1,"externalId":"smoke-123"}],"orderId":"${order_id}","date":"${timestamp_utc}","currency":"USD","firstName":"Hyros","lastName":"Smoke"}
JSON
)"

subscription_payload="$(cat <<JSON
{"email":"${test_email}","subscriptionId":"${subscription_id}","name":"Hyros Smoke Subscription","status":"ACTIVE","startDate":"${timestamp_utc}","price":10.0,"periodicity":"MONTH"}
JSON
)"

print_header "GET /user-info"
request_json "GET" "user-info" || true

print_header "POST /leads"
request_json "POST" "leads" "${lead_payload}" || true

print_header "POST /clicks"
request_json "POST" "clicks" "${click_payload}" || true

print_header "POST /carts"
request_json "POST" "carts" "${cart_payload}" || true

print_header "POST /orders"
request_json "POST" "orders" "${order_payload}" || true

print_header "POST /subscriptions"
request_json "POST" "subscriptions" "${subscription_payload}" || true

echo
echo "----- SMOKE SUMMARY -----"
echo "PASS: ${pass_count}"
echo "FAIL: ${fail_count}"
echo "Test email: ${test_email}"
echo "Order ID: ${order_id}"
echo "Subscription ID: ${subscription_id}"

if [[ "${fail_count}" -gt 0 ]]; then
  exit 1
fi

exit 0
