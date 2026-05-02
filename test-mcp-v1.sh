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

hdr_auth=(-H "Authorization: Bearer ${MCP_TOKEN}")
hdr_json=(-H "Content-Type: application/json")

step_jsonrpc() {
  local label="$1"
  local body="$2"
  echo "=== ${label} ==="
  curl -sS -X POST "$ENDPOINT" "${hdr_auth[@]}" "${hdr_json[@]}" -d "$body"
  echo ""
}

step_jsonrpc "1. initialize" '{"jsonrpc":"2.0","id":"init-1","method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"test","version":"0"}}}'

step_jsonrpc "2. tools/list" '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}'

TOOLS_LIST=$(curl -sS -X POST "$ENDPOINT" "${hdr_auth[@]}" "${hdr_json[@]}" -d '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}')
if ! echo "$TOOLS_LIST" | grep -q 'record_gratitude'; then
  echo "FAIL: tools/list should include record_gratitude" >&2
  exit 1
fi
if ! echo "$TOOLS_LIST" | grep -q 'list_recent_photos'; then
  echo "FAIL: tools/list should include list_recent_photos" >&2
  exit 1
fi

step_jsonrpc "3. tools/call list_recent_photos" '{"jsonrpc":"2.0","id":"photos-1","method":"tools/call","params":{"name":"list_recent_photos","arguments":{"limit":3}}}'

LIST_PHOTOS=$(curl -sS -X POST "$ENDPOINT" "${hdr_auth[@]}" "${hdr_json[@]}" -d '{"jsonrpc":"2.0","id":"photos-1","method":"tools/call","params":{"name":"list_recent_photos","arguments":{"limit":3}}}')
if echo "$LIST_PHOTOS" | grep -q '"isError":true'; then
  echo "SKIP/WARN: list_recent_photos returned isError (configure MCP_MEDIA_SIGNING_KEY on server)" >&2
else
  if ! echo "$LIST_PHOTOS" | grep -q 'photo_id' && ! echo "$LIST_PHOTOS" | grep -q '"text":"[]"'; then
    echo "FAIL: list_recent_photos success should include photo_id entries or an empty JSON array" >&2
    exit 1
  fi
fi

step_jsonrpc "4. tools/call record_gratitude" '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"record_gratitude","arguments":{"text":"Test gratitude"}}}'

CALL_RESULT=$(curl -sS -X POST "$ENDPOINT" "${hdr_auth[@]}" "${hdr_json[@]}" -d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"record_gratitude","arguments":{"text":"Test gratitude"}}}')
if echo "$CALL_RESULT" | grep -q '"isError":true'; then
  echo "FAIL: tools/call should report isError false for record_gratitude" >&2
  echo "$CALL_RESULT" >&2
  exit 1
fi

step_jsonrpc "5. unknown JSON-RPC method" '{"jsonrpc":"2.0","id":4,"method":"bogus/method","params":{}}'

UNKNOWN=$(curl -sS -X POST "$ENDPOINT" "${hdr_auth[@]}" "${hdr_json[@]}" -d '{"jsonrpc":"2.0","id":4,"method":"bogus/method","params":{}}')
if ! echo "$UNKNOWN" | grep -q '"code":-32601'; then
  echo "FAIL: unknown method should return JSON-RPC -32601" >&2
  exit 1
fi

step_jsonrpc "6. tools/call unknown tool" '{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"no_such_tool","arguments":{}}}'

BAD_TOOL=$(curl -sS -X POST "$ENDPOINT" "${hdr_auth[@]}" "${hdr_json[@]}" -d '{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"no_such_tool","arguments":{}}}')
if ! echo "$BAD_TOOL" | grep -q '"isError":true'; then
  echo "FAIL: unknown tool should set isError true" >&2
  exit 1
fi

echo "OK — MCP v1 checks passed."
