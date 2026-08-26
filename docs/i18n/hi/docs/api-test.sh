#!/bin/bash
# API endpoint smoke test — run against a running instance
# Usage: BASE_URL=https://api.example.com bash docs/api-test.sh

set -uo pipefail
BASE="${BASE_URL:-http://localhost:8787}"
V='v1'
H="X-Api-Version: $V"
PASS=0; FAIL=0

# 契约以 JSON code 为准（api-reference.md：{code:401} 业务码，webman json() 默认 HTTP 200）；
# 非 JSON 响应（HTML/纯文本）退回 HTTP 状态码比较。
body_code() {
  local body="$1" http="$2"
  echo "$body" | python3 -c 'import sys,json; print(json.load(sys.stdin)["code"])' 2>/dev/null || echo "$http"
}

check() {
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
  code=$(body_code "$body" "$code")
  if [ "$code" = "$expected" ]; then
    echo "  PASS $method $path → $code"
    ((PASS+=1))
  else
    echo "  FAIL $method $path → $code (expected $expected)"
    ((FAIL+=1))
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
  code=$(body_code "$body" "$code")
  if [ "$code" = "$expected" ]; then
    echo "  PASS $method $path → $code"
    ((PASS+=1))
  else
    echo "  FAIL $method $path → $code (expected $expected)"
    ((FAIL+=1))
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
# 契约未定义 422（api-reference 仅描述成功行为）；forgot-password 防枚举恒成功 code 0
check POST /api/auth/forgot-password 0 '{"email":"test@test.com"}'

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

# admin 端（SPA /app/admin/*）冒烟请单独跑：BASE_URL=http://localhost:8788 bash docs/api-test.sh --admin
if [ "${1:-}" = "--admin" ]; then
  echo ""
  echo "=== Admin endpoints (no token → redirect to login) ==="
  check GET /app/admin/index 200
fi

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
