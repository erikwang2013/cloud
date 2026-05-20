#!/bin/bash
# API endpoint smoke test — run against a running instance
# Usage: BASE_URL=https://api.example.com bash docs/api-test.sh

set -euo pipefail
BASE="${BASE_URL:-http://localhost:8787}"
V='v1'
H="X-Api-Version: $V"
PASS=0; FAIL=0

check() {
  local method="$1" path="$2" expected="${3:-200}" data="${4:-}"
  local url="${BASE}${path}"
  local code
  if [ -z "$data" ]; then
    code=$(curl -s -o /dev/null -w '%{http_code}' -X "$method" "$url" -H "$H" -H 'Content-Type: application/json')
  else
    code=$(curl -s -o /dev/null -w '%{http_code}' -X "$method" "$url" -H "$H" -H 'Content-Type: application/json' -d "$data")
  fi
  if [ "$code" = "$expected" ]; then
    echo "  PASS $method $path → $code"
    ((PASS++))
  else
    echo "  FAIL $method $path → $code (expected $expected)"
    ((FAIL++))
  fi
}

check_json() {
  local method="$1" path="$2" expected="${3:-200}" data="${4:-}"
  local url="${BASE}${path}"
  local resp code body
  if [ -z "$data" ]; then
    resp=$(curl -s -w '\n%{http_code}' -X "$method" "$url" -H "$H" -H 'Content-Type: application/json')
  else
    resp=$(curl -s -w '\n%{http_code}' -X "$method" "$url" -H "$H" -H 'Content-Type: application/json' -d "$data")
  fi
  code=$(echo "$resp" | tail -1)
  body=$(echo "$resp" | sed '$d')
  if [ "$code" = "$expected" ]; then
    if echo "$body" | python3 -c "import sys,json; json.load(sys.stdin)" 2>/dev/null; then
      echo "  PASS $method $path → $code (valid JSON)"
      ((PASS++))
    else
      echo "  WARN $method $path → $code (invalid JSON)"
      ((PASS++))
    fi
  else
    echo "  FAIL $method $path → $code (expected $expected)"
    ((FAIL++))
  fi
}

echo "=== Public endpoints ==="
check GET /health 200
check_json GET /api/products 200
check_json GET "/api/products/search?q=vps" 200
check_json GET /api/regions 200
check_json GET /api/domain/tlds 200
check_json GET /api/help 200
check_json GET /api/status 200

echo ""
echo "=== Auth endpoints ==="
check POST /api/auth/forgot-password 422 '{"email":"test@test.com"}'
check POST /api/auth/reset-password 422 '{"email":"test@test.com","code":"123456","password":"short"}'
check POST /api/auth/send-sms 422 '{"phone":""}'

echo ""
echo "=== Authenticated endpoints (no token → 401) ==="
check GET /api/user/profile 401
check GET /api/user/balance 401
check GET /api/cart 401
check GET /api/orders 401
check GET /api/resources 401
check GET /api/tickets 401
check GET /api/invoices 401
check GET /api/user/sessions 401
check GET /api/user/notifications 401

echo ""
echo "=== Admin endpoints (no token → 401) ==="
check GET /admin/api/dashboard 401
check GET /admin/api/users 401
check GET /admin/api/orders 401

echo ""
echo "=== Version header ==="
# Missing X-Api-Version on API path: should still work (default v1)
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/products" -H 'Content-Type: application/json')
[ "$code" = "200" ] && echo "  PASS default version → $code" && ((PASS++)) || echo "  FAIL default version → $code" && ((FAIL++))

# Invalid version
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/products" -H 'X-Api-Version: v99' -H 'Content-Type: application/json')
[ "$code" = "400" ] && echo "  PASS invalid version v99 → $code" && ((PASS++)) || echo "  FAIL invalid version v99 → $code" && ((FAIL++))

echo ""
echo "========================================="
echo "Results: $PASS passed, $FAIL failed"
echo "========================================="
