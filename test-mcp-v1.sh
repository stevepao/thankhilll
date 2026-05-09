#!/usr/bin/env bash
# Smoke-test MCP JSON-RPC against mcp/v1.php (requires MCP_TOKEN and working DB token).
set -euo pipefail

BASE_URL="${BASE_URL:-http://localhost:8000}"
ENDPOINT="${BASE_URL%/}/mcp/v1.php"

if [[ -z "${MCP_TOKEN:-}" ]]; then
  echo "Set MCP_TOKEN to a valid MCP bearer secret (64-char hex)." >&2
  exit 1
fi

# list_recent_photos signed URLs require MCP_MEDIA_SIGNING_KEY in server .env (optional for other steps).

HDR_TMP="$(mktemp)"
BODY_TMP="$(mktemp)"
trap 'rm -f "$HDR_TMP" "$BODY_TMP"' EXIT

hdr_auth=(-H "Authorization: Bearer ${MCP_TOKEN}")
hdr_json=(-H "Content-Type: application/json")

curl -sS -D "$HDR_TMP" -o "$BODY_TMP" -X POST "$ENDPOINT" "${hdr_auth[@]}" "${hdr_json[@]}" \
  -d '{"jsonrpc":"2.0","id":"init-1","method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"test","version":"0"}}}'

SESSION="$(grep -i '^mcp-session-id:' "$HDR_TMP" | awk '{print $2}' | tr -d '\r')"
if [[ -z "${SESSION}" ]]; then
  echo "FAIL: initialize must return Mcp-Session-Id header" >&2
  exit 1
fi

echo "=== 0. initialize (body) ==="
cat "$BODY_TMP"
echo ""

hdr_sess=(-H "Mcp-Session-Id: ${SESSION}")

NOTIF_CODE="$(curl -sS -o /dev/null -w '%{http_code}' -X POST "$ENDPOINT" "${hdr_auth[@]}" "${hdr_sess[@]}" "${hdr_json[@]}" \
  -d '{"jsonrpc":"2.0","method":"notifications/initialized","params":{}}')"
if [[ "${NOTIF_CODE}" != "202" ]]; then
  echo "FAIL: notifications/initialized should return HTTP 202 (got ${NOTIF_CODE})" >&2
  exit 1
fi
echo "=== 0b. notifications/initialized === HTTP ${NOTIF_CODE}"

MISSING_SESS_CODE="$(curl -sS -o /dev/null -w '%{http_code}' -X POST "$ENDPOINT" "${hdr_auth[@]}" "${hdr_json[@]}" \
  -d '{"jsonrpc":"2.0","id":"no-session","method":"tools/list","params":{}}')"
if [[ "${MISSING_SESS_CODE}" != "400" ]]; then
  echo "FAIL: tools/list without Mcp-Session-Id should return HTTP 400 (got ${MISSING_SESS_CODE})" >&2
  exit 1
fi
echo "=== 0c. tools/list without session === HTTP ${MISSING_SESS_CODE} (expected 400)"

step_jsonrpc() {
  local label="$1"
  local body="$2"
  echo "=== ${label} ==="
  curl -sS -X POST "$ENDPOINT" "${hdr_auth[@]}" "${hdr_sess[@]}" "${hdr_json[@]}" -d "$body"
  echo ""
}

step_jsonrpc "1. tools/list" '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}'

TOOLS_LIST=$(curl -sS -X POST "$ENDPOINT" "${hdr_auth[@]}" "${hdr_sess[@]}" "${hdr_json[@]}" -d '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}')
if ! echo "$TOOLS_LIST" | grep -q 'record_gratitude'; then
  echo "FAIL: tools/list should include record_gratitude" >&2
  exit 1
fi
if ! echo "$TOOLS_LIST" | grep -q 'list_recent_photos'; then
  echo "FAIL: tools/list should include list_recent_photos" >&2
  exit 1
fi

step_jsonrpc "2. tools/call list_recent_photos" '{"jsonrpc":"2.0","id":"photos-1","method":"tools/call","params":{"name":"list_recent_photos","arguments":{"limit":3}}}'

LIST_PHOTOS=$(curl -sS -X POST "$ENDPOINT" "${hdr_auth[@]}" "${hdr_sess[@]}" "${hdr_json[@]}" -d '{"jsonrpc":"2.0","id":"photos-1","method":"tools/call","params":{"name":"list_recent_photos","arguments":{"limit":3}}}')
if echo "$LIST_PHOTOS" | grep -q '"isError":true'; then
  echo "SKIP/WARN: list_recent_photos returned isError (configure MCP_MEDIA_SIGNING_KEY on server)" >&2
else
  if ! echo "$LIST_PHOTOS" | grep -q 'photo_id' && ! echo "$LIST_PHOTOS" | grep -q '"text":"[]"'; then
    echo "FAIL: list_recent_photos success should include photo_id entries or an empty JSON array" >&2
    exit 1
  fi
fi

step_jsonrpc "3. tools/call record_gratitude" '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"record_gratitude","arguments":{"text":"Test gratitude"}}}'

CALL_RESULT=$(curl -sS -X POST "$ENDPOINT" "${hdr_auth[@]}" "${hdr_sess[@]}" "${hdr_json[@]}" -d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"record_gratitude","arguments":{"text":"Test gratitude"}}}')
if echo "$CALL_RESULT" | grep -q '"isError":true'; then
  echo "FAIL: tools/call should report isError false for record_gratitude" >&2
  echo "$CALL_RESULT" >&2
  exit 1
fi

step_jsonrpc "4. unknown JSON-RPC method" '{"jsonrpc":"2.0","id":4,"method":"bogus/method","params":{}}'

UNKNOWN=$(curl -sS -X POST "$ENDPOINT" "${hdr_auth[@]}" "${hdr_sess[@]}" "${hdr_json[@]}" -d '{"jsonrpc":"2.0","id":4,"method":"bogus/method","params":{}}')
if ! echo "$UNKNOWN" | grep -q '"code":-32601'; then
  echo "FAIL: unknown method should return JSON-RPC -32601" >&2
  exit 1
fi

step_jsonrpc "5. tools/call unknown tool" '{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"no_such_tool","arguments":{}}}'

BAD_TOOL=$(curl -sS -X POST "$ENDPOINT" "${hdr_auth[@]}" "${hdr_sess[@]}" "${hdr_json[@]}" -d '{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"no_such_tool","arguments":{}}}')
if ! echo "$BAD_TOOL" | grep -q '"isError":true'; then
  echo "FAIL: unknown tool should set isError true" >&2
  exit 1
fi

DEL_CODE="$(curl -sS -o /dev/null -w '%{http_code}' -X DELETE "$ENDPOINT" "${hdr_auth[@]}" "${hdr_sess[@]}")"
if [[ "${DEL_CODE}" != "200" ]]; then
  echo "FAIL: DELETE with valid session should return HTTP 200 (got ${DEL_CODE})" >&2
  exit 1
fi
echo "=== 6. DELETE session === HTTP ${DEL_CODE}"

AFTER_DEL="$(curl -sS -w '\n%{http_code}' -X POST "$ENDPOINT" "${hdr_auth[@]}" "${hdr_sess[@]}" "${hdr_json[@]}" \
  -d '{"jsonrpc":"2.0","id":"after-del","method":"tools/list","params":{}}')"
AFTER_DEL_BODY="$(echo "$AFTER_DEL" | sed '$d')"
AFTER_DEL_CODE="$(echo "$AFTER_DEL" | tail -n1)"
if [[ "${AFTER_DEL_CODE}" != "400" ]]; then
  echo "FAIL: tools/list after DELETE should return HTTP 400 (got ${AFTER_DEL_CODE})" >&2
  echo "$AFTER_DEL_BODY" >&2
  exit 1
fi
if ! echo "$AFTER_DEL_BODY" | grep -q 'Session not found or expired'; then
  echo "FAIL: stale session error body should mention Session not found or expired" >&2
  echo "$AFTER_DEL_BODY" >&2
  exit 1
fi
echo "=== 7. tools/list after DELETE === HTTP ${AFTER_DEL_CODE}"

echo "OK — MCP v1 checks passed."
