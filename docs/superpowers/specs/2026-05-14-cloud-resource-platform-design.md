# 全球云资源交易平台 — 系统设计

## 项目概述

面向全球用户的云资源交易平台，支持自营 + 第三方供应商混合模式。用户可购买服务器、IP、云盘、域名等云产品。全自动资源开通，多支付通道，多币种，多语言。

### 技术栈

| 层级 | 技术 |
|------|------|
| 用户端 App | Flutter (iOS/Android) + HarmonyOS (ArkTS) |
| 管理后台 | webman-admin |
| 服务端 | PHP webman (模块化单体) |
| 数据库 | MySQL 8.0 (主从) |
| 缓存/队列 | Redis (缓存 + Session + 队列) |
| 存储 | S3/OSS + CDN |
| 监控 | Prometheus + Grafana + Sentry + ELK/Loki |

---

## 一、模块划分 (12 个核心模块)

| 模块 | 职责 |
|------|------|
| **User** | 注册/登录(OAuth+邮箱+手机)、KYC 实名认证、会员等级、余额账户 |
| **Product** | 商品定义(SKU)、多区域定价、库存管理、分类、搜索、评价 |
| **Order** | 购物车、下单、订单生命周期(待付→已付→开通中→已完成→退款)、续费/升级 |
| **Payment** | 支付通道路由、多币种报价、汇率、退款、对账 |
| **Provisioning** | 对接各云厂商 API，自动创建/续费/销毁资源 |
| **Domain** | 域名查询、注册、转移、续费、DNS 管理 |
| **Supplier** | 供应商入驻、审批、商品上架、结算、分成 |
| **Monitor** | 资源状态探活、用量采集、告警规则 |
| **Ticket** | 工单提交、分配、SLA 追踪 |
| **Notification** | 邮件/短信/App Push/站内信，多模板多语言 |
| **Report** | 营收报表、供应商结算报表、销售趋势 |
| **I18n** | 多语言词条、多币种汇率、多时区 |

---

## 二、核心数据模型

### 用户中心 (User)

- **users** — 用户主表 (id, email, phone, password_hash, language, currency, timezone, status)
- **user_profiles** — 用户档案 (user_id, avatar, nickname, country)
- **user_kyc** — 实名认证 (user_id, id_type, id_number, real_name, front/back_image, status, verified_at)
- **user_balance** — 余额账户 (user_id, currency, balance, frozen_balance)
- **user_balance_log** — 余额变动记录 (user_id, type, amount, before, after, order_id, remark)
- **user_addresses** — 用户地址 (user_id, type: billing/shipping, name, phone, country, state, city, address, postcode, is_default)

### 商品中心 (Product)

- **product_categories** — 商品分类 (id, parent_id, name, icon, sort)
- **products** — 商品主表 (id, supplier_id, category_id, name, slug, description, cover, status)
- **product_skus** — SKU (id, product_id, specs: {cpu, ram, disk, bandwidth, os...}, cycle: monthly/quarterly/yearly)
- **product_regions** — 区域定价 (sku_id, region_id, price, original_price, stock, currency)
- **product_images** — 商品图片 (product_id, url, sort)
- **product_attributes** — 自定义属性 (product_id, key, value)
- **product_reviews** — 商品评价 (user_id, product_id, order_id, rating, content)
- **regions** — 区域表 (id, name, continent, country, city, data_center, status)

### 订单中心 (Order)

- **carts** — 购物车 (user_id, sku_id, region_id, quantity, cycle)
- **orders** — 订单主表 (id, order_no, user_id, type: new/renew/upgrade, status, currency, subtotal, discount, tax, total, paid_at)
- **order_items** — 订单明细 (order_id, sku_id, region_id, product_id, quantity, cycle, unit_price, total_price, resource_snapshot)
- **order_timeline** — 订单时间线 (order_id, status, operator, remark, created_at)
- **order_invoices** — 发票 (order_id, user_id, type, title, tax_number, amount, file_url)
- **refunds** — 退款单 (order_id, user_id, amount, reason, status, handled_by)

### 支付中心 (Payment)

- **payment_channels** — 支付通道配置 (id, name, code: stripe/crypto/alipay/wechat, currency_support, fee_config, is_visible, visible_regions, min_amount, max_amount, status)
- **payment_transactions** — 交易记录 (id, order_id, user_id, channel_id, amount, currency, exchange_rate, channel_fee, transaction_no, status, callback_at)
- **payment_reconcile** — 对账表 (date, channel_id, channel_total, system_total, diff, status)

### 资源开通 (Provisioning)

- **resources** — 资源主表 (id, order_item_id, user_id, product_id, type: server/ip/disk/domain, provider, region, status, provisioned_at, expired_at)
- **resource_servers** — 服务器详情 (resource_id, hostname, ip_address, login_user, login_password, os, cpu, ram, disk, bandwidth, panel_url)
- **resource_ips** — IP 详情 (resource_id, ip_address, subnet, gateway, rdns)
- **resource_disks** — 云盘详情 (resource_id, disk_size, disk_type, attach_to_resource_id)
- **resource_domains** — 域名详情 (resource_id, domain_name, registrar, dns_servers, whois_privacy, auto_renew)
- **provision_tasks** — 开通任务 (order_id, resource_id, provider, action: create/renew/upgrade/destroy, status, params, result, retry_count, next_retry_at)
- **provider_apis** — 云厂商 API 配置 (id, name, code, api_key_encrypted, api_secret_encrypted, webhook_secret, status)

### 物理机资源管理 (Host & IP Pool)

自营物理服务器使用 Proxmox VE (社区版，免费) 管理虚拟机，通过 REST API 创建/管理 VM、分配 IP、挂载磁盘。

- **host_machines** — 宿主机 (id, name, region_id, ip_address, proxmox_node, proxmox_api_url, api_token_encrypted, status: online/maintenance/offline, specs: {cpu_total, cpu_allocated, ram_total_gb, ram_allocated_gb, disk_total_gb, disk_allocated_gb}, storage_pool, created_at, updated_at)
- **ip_pools** — IP 池 (id, host_machine_id, region_id, network_cidr, gateway, vlan_id, ip_start, ip_end, total_count, used_count, status)
- **ip_allocations** — IP 分配记录 (id, ip_pool_id, resource_id, ip_address, type: primary/secondary, allocated_at, released_at)
- **disks** — VM 磁盘明细 (id, resource_id, host_machine_id, vm_id, size_gb, disk_type: system/data, storage_pool, device_path, status)
- **disk_resizes** — 磁盘扩容记录 (id, disk_id, old_size_gb, new_size_gb, status, finished_at)

### 供应商 (Supplier)

- **suppliers** — 供应商主表 (id, user_id, company_name, contact, status, settlement_method)
- **supplier_products** — 供应商商品关联 (supplier_id, product_id, approved_at, commission_rate)
- **supplier_settlements** — 结算单 (supplier_id, period_start, period_end, total_sales, commission, payable, status, paid_at)
- **supplier_withdraws** — 提现记录 (supplier_id, amount, method, account_info, status)

### 域名服务 (Domain)

- **domain_tlds** — 支持的 TLD (tld, wholesale_price, retail_price, registrar, promo_price, promo_end_at)
- **domain_transfers** — 域名转移 (domain_name, user_id, auth_code, from_registrar, status)
- **dns_zones** — DNS 区域 (domain_name, user_id, zone_id)
- **dns_records** — DNS 记录 (zone_id, type, name, value, ttl, priority)

### 工单与通知 (Ticket & Notification)

- **tickets** — 工单 (id, ticket_no, user_id, resource_id, category, priority, title, status, assigned_to, sla_deadline)
- **ticket_messages** — 工单消息 (ticket_id, sender_id, sender_type: user/staff, content, attachments)
- **notifications** — 通知记录 (user_id, channel: email/sms/push/in_app, template_code, content, send_status, read_at)
- **notification_templates** — 通知模板 (code, name, channels, title_template, body_template, variables)

---

## 三、API 设计规范

### 版本管理

API 版本通过 HTTP 请求头 `X-Api-Version` 指定，不在 URL 路径中。服务端通过中间件将版本头注入内部路由。

```
请求:  GET /api/auth/login
请求头: X-Api-Version: v1

内部路由 → /api/auth/login → 控制器
响应头: X-Api-Version: v1
```

**支持版本**: `v1`（默认，请求头缺失时自动使用）

**版本控制机制**: `VersionMiddleware` 对所有 `/api/*` 和 `/admin/api/*` 路径校验 `X-Api-Version` 请求头，缺失默认 `v1`，不支持的版本返回 `400`。URL 路径中不再包含版本号。

**新增版本步骤**:
1. `VersionMiddleware::SUPPORTED` 数组追加版本号
2. 在 `route.php` 注册新版路由分组
3. 控制器通过 `$request->properties['api_version']` 获取版本号做差异化处理

### RESTful 路由

```
统一前缀: /api
管理后台: /admin/api
```

**路由分组与中间件矩阵:**

| 路由组 | 中间件 | 端点示例 |
|--------|--------|---------|
| 公开 (无前缀) | 全局中间件链 | `/health`, `/api/products`, `/api/help`, `/api/domain/tlds` |
| `/api/auth` | 全局 + Encryption | `/api/auth/register`, `/api/auth/login`, `/api/auth/refresh` |
| `/api` (用户) | 全局 + Encryption + Auth | `/api/user/profile`, `/api/cart`, `/api/orders`, `/api/resources` |
| `/api` (敏感) | 全局 + Encryption + Auth + Confirmation | `/api/orders/{id}/pay`, `/api/supplier/withdraw`, `/api/dns/{domain}/records/{id}` |
| `/admin/api` | 全局 + Encryption + Auth + AdminRole | `/admin/api/dashboard`, `/admin/api/users`, `/admin/api/products` |
| `/admin/api` (敏感) | 全局 + Encryption + Auth + AdminRole + Confirmation | `/admin/api/products/{id}` (delete), `/admin/api/orders/{id}/refund`, `/admin/api/kyc/{id}/approve` |

### 统一响应格式

```json
{
  "code": 0,
  "message": "ok",
  "data": { ... },
  "meta": {
    "page": 1,
    "page_size": 20,
    "total": 1523
  },
  "request_id": "req_abc123"
}
```

### 鉴权方案

| 端 | 方式 |
|----|------|
| 用户端 | JWT (access_token 2h + refresh_token 30d) + TOTP 两步验证 + 恢复码 |
| 管理端 | JWT (access_token 2h + refresh_token 7d) |
| 供应商 API | API Key (sk_ 前缀，SHA256 哈希存储，仅创建时显示一次) |
| 云厂商回调 | 签名验证 (HMAC-SHA256) |

**已实现的认证功能**:
- 邮箱注册 + 邮箱验证链接
- 手机号注册 + Twilio 短信验证码（60s 冷却 + IP 限流 5次/小时）
- Google OAuth 登录 / Apple Sign In
- 忘记密码（邮箱验证码 + Redis 10min TTL）
- TOTP 两步验证（QR 码扫码设置、恢复码兜底）
- 活跃会话管理（查看/撤销登录设备，含 client_platform 信息）
- 账号注销 GDPR（密码确认 + 软删除 + token 全部撤销）
- 登录异常告警（新 IP 登录邮件通知）
- 登录锁定（5 次失败锁定 15 分钟）

**用户认证流程:**

```
注册流程                             登录流程
────────                             ────────
1. POST /captcha/create              1. POST /captcha/create
   ← {key, image(点击位置)}              ← {key, image}
2. POST /auth/register               2. POST /auth/login
   → {email, password, captcha}         → {login, password, captcha}
   → [WAF 扫描]                         → [WAF 扫描]
   → [限流: 3 req/min]                  → [限流: 5 req/min]
   → [密码 bcrypt(cost=12)]             → [Hash::check()]
   → [设备指纹: sha256(UA+IP)]           → [设备指纹: sha256(UA+IP)]
   → [client_platform 记录]              → [client_platform 记录]
   → User::create()                    → [失败 5 次 → 锁 15min]
   → RefreshToken::create()            → [新 IP 检测 → 邮件告警]
     user_id, token_hash,              → RefreshToken::create()
     device_fingerprint,                   user_id, token_hash,
     client_platform,                      device_fingerprint,
     expires_at                            client_platform,
   → NotificationDispatcher::send()           expires_at
     (验证邮件)                          → AuditLogger::record('user_login')
   → AuditLogger::record               ← {access_token, refresh_token}
     ('user_registered')
   ← {access_token, refresh_token}    OAuth (Google/Apple):
                                      ─────────────────────
                                      1. GET /auth/google
                                      2. Google 授权 → code
                                      3. GET /auth/google/callback?code=xxx
                                      4. 验证 Google token
                                      5. 新建或查找用户
                                      6. 签发 token（含 client_platform）
                                      7. AuditLogger::record('user_oauth_login')

TOTP 两步验证                          会话管理
────────────────                      ────────
1. POST /user/totp/setup               GET /user/sessions
   ← {secret, qr_code_url}                ← [{id, fingerprint, client_platform,
2. POST /user/totp/verify                      created_at, expires_at}]
   → {code: 123456}
   ← {recovery_codes: [...]}          DELETE /user/sessions/{id}
3. POST /auth/login                      → RefreshToken::update(revoked=true)
   → {login, password, totp_code}        ← 成功
   或 → /auth/login/recovery
   → {login, password, recovery_code}  DELETE /user/account
                                          → 密码确认 + 软删除 + 全部 token 撤销
登录锁定机制
────────────
Redis: login_failed:{sha1(login)} = count (TTL 900s)
       count >= 5 → login_lock:{userId} (TTL 900s)
```

### 多语言方案

- 请求头: Accept-Language: zh-CN / en-US / ja-JP
- JSON 列存储多语言文案: name: {"zh-CN":"云服务器","en":"Cloud Server"}
- i18n 文件管理静态文本，前端和后端各一套

---

## 四、安全防护体系

### 分层防护模型

```
┌─────────────────────────────────────────────────────┐
│ 第一层: 网络边界防护                                    │
│   DDoS清洗 / WAF / IP黑白名单 / Geo-Blocking          │
├─────────────────────────────────────────────────────┤
│ 第二层: 传输与应用防护                                  │
│   HTTPS+TLS1.3 / CSP / CORS / JWT鉴权 / 限流          │
├─────────────────────────────────────────────────────┤
│ 第三层: 数据与存储安全                                  │
│   加密存储 / 脱敏 / 审计日志 / 备份                     │
├─────────────────────────────────────────────────────┤
│ 第四层: 虚拟化与资源隔离                                 │
│   Proxmox安全加固 / VM间隔离 / 网络隔离                 │
├─────────────────────────────────────────────────────┤
│ 第五层: 运营与风控                                     │
│   操作审计 / 异常检测 / 告警 / 应急响应                  │
└─────────────────────────────────────────────────────┘
```

---

### 4.1 网络边界防护

#### DDoS 防护

```
用户请求 → CDN (Cloudflare / 阿里云CDN)
              │
              ├── JS质询 / 验证码 (可疑流量)
              ├── 速率限制 (每IP每秒请求数)
              ├── 区域封禁 (阻断指定国家/地区)
              │
              ▼
          源站 (Nginx + webman)
```

| 层级 | 措施 | 说明 |
|------|------|------|
| CDN 层 | 自动 DDoS 清洗 | Cloudflare 免费计划即支持 L3/L4 防护 |
| CDN 层 | Bot Management | 识别和拦截恶意爬虫/刷单脚本 |
| Nginx 层 | limit_req_zone | 每 IP 10 req/s，超限返回 429 |
| Nginx 层 | limit_conn | 每 IP 最大 20 并发连接 |
| webman 层 | 令牌桶限流中间件 | 按用户/接口粒度的精确限流 |

#### WAF 规则 (webman 中间件)

WAF 中间件通过 8 类正则规则组扫描请求，规则配置在 `config/security.php` 中热更新无需重启。扫描范围覆盖请求体 JSON、URL 路径+查询字符串、User-Agent、原始请求体（防 JSON 编码逃逸）。

**8 类检测规则（45+ 条）：**

| 类别 | 覆盖范围 |
|------|---------|
| SQL 注入 | 单引号/注释符、SQL 关键字、十六进制编码、联合查询变形、永真条件(`' OR '1'='1`)、时间盲注(`sleep`/`benchmark`)、堆叠查询、多行注释绕过 |
| XSS | HTML 标签(含编码变形)、Script 标签及变体、13 种 JS 事件处理器、JS 全局对象/危险函数、`javascript:` 伪协议、HTML 实体编码、Data URI 注入、内联事件属性 |
| 命令注入 | 管道符后跟命令(`\| cat`)、分号后跟命令(`; whoami`)、`$(cmd)` 和反引号命令替换、独立命令关键字 |
| 文件包含 | 路径穿越(多编码)、PHP 伪协议(`php://`/`data://`/`phar://`)、绝对路径探测(`/etc/`/`C:\`)、Null byte 注入 |
| HTTP 头注入 | CRLF 换行注入(`%0d%0a`/`\r\n`)、Host/Cookie/Set-Cookie 头注入 |
| **SSRF** | 内网 IPv4 地址(127.x/10.x/172.16-31.x/192.168.x)、localhost 别名、云 metadata 端点(169.254.169.254)、file:// 协议 |
| **NoSQL 注入** | MongoDB 操作符($where/$gt/$regex/$or 等)、$where JS 注入、Redis 危险命令(FLUSHALL/CONFIG SET/SHUTDOWN) |
| **开放重定向** | redirect_uri/return_url/next/callback 等参数的外部 URL 检测、双重编码绕过 |

**请求层面防护:**

| 防护项 | 措施 |
|--------|------|
| 请求体大小限制 | 最大 10MB（超过返回 413） |
| URL 长度限制 | 最大 2KB（超过返回 414，防 ReDoS） |
| Content-Type 白名单 | 仅允许 application/json、multipart/form-data、application/x-www-form-urlencoded |

**WAF 检测流程:**

```
请求进入
  │
  ▼
1. 获取待扫描文本
   ├── json_encode($request->all(), JSON_UNESCAPED_SLASHES)  # 请求体
   │     └── false → serialize() 回退
   ├── mb_substr(path + queryString, 0, 2048)                # URL（防 ReDoS 截断）
   ├── User-Agent 头                                          # UA
   └── file_get_contents('php://input')                      # 原始体（防 JSON 编码逃逸）
  │
  ▼
2. 加载规则（从 config/security.php）
   ├── security.waf.sqli_patterns               (9 条)
   ├── security.waf.xss_patterns                (8 条)
   ├── security.waf.cmd_injection_patterns      (5 条)
   ├── security.waf.file_inclusion_patterns     (4 条)
   ├── security.waf.header_injection_patterns   (2 条)
   ├── security.waf.ssrf_patterns               (6 条)
   ├── security.waf.nosql_injection_patterns    (3 条)
   └── security.waf.open_redirect_patterns      (2 条)
   → array_merge() + array_unique()
  │
  ▼
3. 逐条匹配
   foreach patterns as pattern:
     match($pattern, $input) ───→ 命中 → AuditLogger::threat('waf_blocked')
     match($pattern, $url)   ───→ 命中 → 返回 403 "Request blocked by WAF"
     match($pattern, $ua)    ───→ 命中 →
     match($pattern, $raw)   ───→ 命中 →
  │
  ▼
4. match() 严格检查
   $result = @preg_match($pattern, $subject)
   ├── $result === 1    → 命中 ✓
   ├── $result === 0    → 未命中（安全放行）
   └── $result === false → 模式错误 → error_log() → 作为未命中处理
  │
  ▼
5. 全部未命中 → $next($request) 放行到下一中间件
```

```php
class WafMiddleware
{
    public function process($request, callable $next)
    {
        $input = json_encode($request->all(), JSON_UNESCAPED_SLASHES);
        if ($input === false) {
            $input = serialize($request->all());
        }
        $url = mb_substr($request->path() . '?' . $request->queryString(), 0, 2048);
        $ua  = $request->header('User-Agent', '');
        $raw = file_get_contents('php://input') ?: '';

        // 从 config/security.php 加载 8 类规则
        $patterns = array_unique(array_merge(
            config('security.waf.sqli_patterns'),
            config('security.waf.xss_patterns'),
            config('security.waf.cmd_injection_patterns'),
            config('security.waf.file_inclusion_patterns'),
            config('security.waf.header_injection_patterns'),
        ));

        foreach ($patterns as $pattern) {
            if ($this->match($pattern, $input)
                || $this->match($pattern, $url)
                || $this->match($pattern, $ua)
                || $this->match($pattern, $raw)) {
                AuditLogger::threat('waf_blocked', $request);
                return json(Response::error(403, 'Request blocked by WAF'));
            }
        }
        return $next($request);
    }

    private function match(string $pattern, string $subject): bool
    {
        $result = @preg_match($pattern, $subject);
        if ($result === false) {
            error_log("WAF: invalid regex pattern: $pattern");
            return false;
        }
        return $result === 1;
    }
}
```

#### IP 黑白名单

```
黑名单:
- 已知恶意 IP 库 (定期同步 AbuseIPDB)
- 频繁触发 WAF 规则的 IP (自动加入，Redis TTL 24h)
- 暴力破解登录的 IP (5次失败 → 锁定 30min)

白名单:
- Proxmox 宿主机 IP
- 云厂商回调 IP 段
- 支付网关 webhook IP 段
- 管理员办公网络 IP (可选)
```

#### Geo-Blocking

```php
// GeoIP2 库 (MaxMind)
$country = geoip($request->getRealIp());

// 可配置的阻断列表
$blockedCountries = config('security.geo_block', []);
if (in_array($country, $blockedCountries)) {
    return errorResponse(403, 'Access denied for your region');
}
```

---

### 4.2 传输与应用安全

#### 全局中间件执行链

所有 HTTP 请求按以下顺序经过中间件处理，每个中间件独立可测试：

```
请求 → VersionMiddleware        # X-Api-Version 校验（缺失默认 v1，无效返回 400）
     → CorsMiddleware            # CORS 跨域响应头
     → ClientPlatformMiddleware  # X-Client-Platform 识别（8 种平台），注入 $request->properties
     → WafMiddleware             # 8 类 45+ 规则安全扫描（SQLi/XSS/命令注入/文件包含/头注入/SSRF/NoSQL/开放重定向）
     → LocaleMiddleware          # Accept-Language 解析，设置区域
     → HashidRequestMiddleware   # 请求参数 hashid → 真实 ID 解码
     → MaintenanceMiddleware     # 维护模式（IP 白名单放行）
     ↓
  [路由中间件—按路由组附加]
     → EncryptionMiddleware      # AES-256-GCM 请求/响应体加密
     → Captcha                   # 点击验证码校验（登录/注册前）
     → AuthMiddleware            # JWT Bearer Token 验证 + 角色注入
     → AdminRoleMiddleware       # 管理员 RBAC 权限检查
     → ConfirmationMiddleware    # 敏感操作二次密码确认（5 次失败锁 15min）
     ↓
     控制器
```

#### 各中间件职责

| 中间件 | 注册方式 | 职责 |
|--------|---------|------|
| `VersionMiddleware` | 全局 | 校验 `X-Api-Version` 请求头，缺失默认 `v1`，不支持的版本返回 `400` |
| `CorsMiddleware` | 全局 | 处理 OPTIONS 预检，反射 Origin 到 `Access-Control-Allow-Origin` |
| `ClientPlatformMiddleware` | 全局 | 校验 `X-Client-Platform` 请求头，识别客户端操作系统平台（iPadOS/macOS/Windows/Linux/iOS/Android/HarmonyOS/Web），注入 `$request->properties['client_platform']` |
| `WafMiddleware` | 全局(service) + admin 实例 | 8 类 45+ 规则 + 请求大小限制 + Content-Type 校验，拦截后记录审计日志 |
| `LocaleMiddleware` | 全局 | 解析 `Accept-Language` 头，设置多语言区域 |
| `HashidRequestMiddleware` | 全局 | 自动解码请求中的 hashid 字符串为真实整数 ID |
| `MaintenanceMiddleware` | 全局 | 检查 `MAINTENANCE_MODE` 环境变量，白名单 IP 放行 |
| `EncryptionMiddleware` | 路由组 (/api/auth, /api, /admin/api) | AES-256-GCM 请求/响应体加密，`X-Encrypted: 1` 头触发 |
| `AuthMiddleware` | 路由组 (/api, /admin/api) | JWT HS256 access_token 验证，注入 `$request->userId` 和 `$request->userRole` |
| `AdminRoleMiddleware` | 路由组 (/admin/api) | 管理员 RBAC 权限检查 |
| `ConfirmationMiddleware` | 路由组 (敏感操作) | 二次密码确认，Redis 失败计数器，5 次锁定 15 分钟 |

#### ClientPlatform 中间件细节

```php
class ClientPlatformMiddleware
{
    protected const SUPPORTED = [
        'ipados', 'macos', 'windows', 'linux',
        'ios', 'android', 'harmonyos', 'web',
    ];

    public function process($request, callable $next)
    {
        // 仅对 API 路由生效
        $path = $request->path();
        if (!str_starts_with($path, '/api/') && !str_starts_with($path, '/admin/api/')) {
            return $next($request);
        }

        $platform = strtolower(trim($request->header('X-Client-Platform', '')));

        if ($platform === '') {
            $platform = 'unknown';
        } elseif (!in_array($platform, static::SUPPORTED, true)) {
            return response(json_encode(
                Response::error(400, "Unsupported client platform: {$platform}")
            ), 400, ['X-Client-Platform' => $platform]);
        }

        // 注入请求属性供下游使用（审计日志、会话记录）
        $request->properties['client_platform'] = $platform;

        $response = $next($request);
        $response->header('X-Client-Platform', $platform);
        return $response;
    }
}
```

**数据流**: 中间件注入 → `AuditLogger` 自动记录 → `AuthService::issueTokens()` 写入 `refresh_tokens` → `GET /api/user/sessions` 返回平台信息

#### HTTPS 强制

```nginx
# Nginx 配置
server {
    listen 80;
    server_name api.example.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384;
    ssl_prefer_server_ciphers on;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 10m;
    
    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains; preload";
    add_header X-Content-Type-Options "nosniff";
    add_header X-Frame-Options "DENY";
    add_header X-XSS-Protection "1; mode=block";
    add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'";
    add_header Referrer-Policy "strict-origin-when-cross-origin";
}
```

#### JWT 安全加固

```
- access_token 有效期 2h，refresh_token 有效期 30d
- 密钥使用 RSA256 (非对称)，定期轮换 (90天)
- jti (JWT ID) 存入 Redis 实现主动吊销
- refresh_token 绑定设备指纹 (User-Agent + IP 段)
- 换发 refresh_token 时旧 token 立即失效 (rotation)
- 敏感操作 (支付/销毁资源) 需二次验证

设备指纹:
  device_fingerprint = hash(user_agent + ip_cidr_24 + client_type)
  refresh_token 表记录此指纹，换发时校验
```

#### 密码策略

```
- bcrypt 加密，cost factor = 12
- 最小 8 字符，必须包含大小写字母 + 数字
- 注册/登录连续失败 5 次 → 账号锁定 15 分钟
- 密码修改后，所有已签发 token 立即失效
- 支持 TOTP 两步验证 (用户可选开启)
```

#### CORS 策略

```php
// webman 中间件
class CorsMiddleware
{
    public function process(Request $request, callable $next): Response
    {
        $allowedOrigins = config('cors.allowed_origins', []);
        $origin = $request->header('Origin');

        $response = $next($request);

        if (in_array($origin, $allowedOrigins)) {
            $response->header('Access-Control-Allow-Origin', $origin);
            $response->header('Access-Control-Allow-Methods', 'GET,POST,PUT,DELETE,OPTIONS');
            $response->header('Access-Control-Allow-Headers', 'Content-Type,Authorization,Accept-Language');
            $response->header('Access-Control-Max-Age', '86400');
        }

        return $response;
    }
}
```

#### 文件上传安全

```
- 白名单校验扩展名 (仅允许: jpg, jpeg, png, pdf, gif)
- 校验文件 MIME 类型 (不允许伪造 Content-Type)
- 文件大小限制: 头像 2MB, KYC 证件 5MB, 附件 10MB
- 上传后重命名: {uuid}.{ext}, 不保留原始文件名
- 图片二次处理: GD/Imagick 去除 EXIF + 元数据
- 存储路径在 web 不可访问目录, 通过 PHP 代理读取
- 病毒扫描: ClamAV (KYC 证件/用户上传文件)
```

---

### 4.3 数据与存储安全

#### 敏感数据加密

```
加密算法: AES-256-GCM (带认证的加密，防篡改)
密钥管理: 主密钥存于环境变量，每个字段使用独立派生密钥

需要加密存储的字段:
| 数据类型 | 字段 | 加密方式 |
|----------|------|----------|
| 密码 | users.password_hash | bcrypt (单向) |
| 支付密钥 | payment_channels.api_key | AES-256-GCM |
| 云厂商密钥 | provider_apis.api_key_encrypted, api_secret_encrypted | AES-256-GCM |
| Proxmox Token | host_machines.api_token_encrypted | AES-256-GCM |
| KYC 证件号 | user_kyc.id_number | AES-256-GCM |
| 支付账号 | 提现账号 | AES-256-GCM |
| 登录密码(VNC) | resource_servers.login_password | AES-256-GCM |

密钥派生:
  derived_key = HKDF-SHA256(master_key, salt: table_name + '.' + field_name)
```

#### 日志脱敏

```php
class LogSanitizer
{
    // 自动脱敏的字段名模式
    private array $sensitiveFields = [
        'password', 'password_hash', 'secret', 'api_key',
        'token', 'credit_card', 'cvv', 'ssn', 'id_number',
        'login_password', 'private_key',
    ];

    public function sanitize(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($this->matchSensitive($key)) {
                $data[$key] = '***REDACTED***';
            } elseif (is_array($value)) {
                $data[$key] = $this->sanitize($value);
            }
        }
        return $data;
    }
}

// Monolog Processor 在写入日志前自动调用
```

#### 数据库安全

```
- MySQL 使用 prepared statement (Eloquent 自动处理)
- 数据库访问账号最小权限原则:
  - app_user: SELECT, INSERT, UPDATE, DELETE (无 DDL)
  - migration_user: DDL 权限 (仅迁移时使用，IP 限制)
  - read_user: SELECT 只读 (报表/数据分析使用)
- 连接使用 SSL/TLS (PHP PDO SSL options)
- 数据库端口不对公网开放 (仅内网可访问)
- 定期备份: 全量备份 1天, binlog 实时同步
```

#### 数据备份与恢复

```
备份策略:
- MySQL: 每日全量 + binlog 实时增量
- Redis: RDB 每小时 + AOF 实时持久化
- 用户上传文件: S3/OSS 自动多副本 + 跨区域复制
- Proxmox VM 快照: 每周一次 (保留 4 周)
- 备份加密: AES-256 加密后存储

恢复演练:
- 每季度执行一次灾难恢复演练
- 恢复时间目标 (RTO): < 4 小时
- 恢复点目标 (RPO): < 1 小时
```

---

### 4.4 虚拟化与资源隔离

#### Proxmox 安全加固

```
1. API 访问控制:
   - Proxmox API 仅监听内网 IP (不绑定公网)
   - Token 权限最小化: 每个 role 仅授予必要权限
   - API 端口 (8006) 仅允许 PHP 应用服务器 IP 访问 (iptables)

2. SSH 加固:
   - 禁用密码登录，仅允许密钥认证
   - 禁用 root 登录，使用专用管理账户
   - SSH 端口改为非标准端口 (减少扫描)
   - Fail2ban: 5 次失败锁定 1 小时

3. 系统更新:
   - Proxmox 订阅安全更新邮件列表
   - 定期 apt update && apt upgrade
   - 内核 livepatch (Canonical Livepatch Service)

4. 防火墙 (iptables/nftables):
   - 默认拒绝所有入站
   - 仅开放: 8006 (仅应用服务器IP), SSH端口 (仅管理IP)
   - VM 网桥与宿主机管理网络的隔离
```

#### VM 间隔离

```
- 每个 VM 使用独立的虚拟网桥 VLAN
- 禁止 VM 间通信 (Proxmox 防火墙规则 + VLAN 隔离)
- 用户仅能通过公网 IP 访问自己的 VM
- VM 资源限制 (cgroup): 防止单个 VM 耗尽宿主机资源
  - CPU limit: 购买的核数上限
  - RAM limit: 购买的容量上限
  - Disk IOPS limit: 防止磁盘争用
  - Network bandwidth limit: 购买的带宽上限
```

#### IP 分配安全

```
- IP 分配记录完整审计 (谁、何时、分配了什么 IP)
- IP 释放后冷却期 24h (防止 IP 被立即分配给其他人导致的误用)
- IP 黑名单: 被投诉/滥用的 IP 标记为不可分配
- IP 使用监控: 定期检查分配的 IP 是否正常使用中
```

---

### 4.5 支付安全

```
1. PCI DSS 合规:
   - 信用卡数据不经过自有服务器 (Stripe Elements / Checkout)
   - card_token 由 Stripe 前端直接生成，后端仅接收 token
   - 不在日志/数据库中存储任何 CVV/完整卡号

2. 加密货币:
   - 收款私钥冷存储 (离线签名)
   - 热钱包仅保留日常周转额度
   - 收款地址生成后验证校验和
   - 大额交易 ( > $10000) 人工审核后手动确认

3. 支付防欺诈:
   - 同一用户/IP 短时间内高频支付 → 风控冻结
   - 新注册用户大额支付 → 人工审核
   - 支付金额异常 (与商品价格不匹配) → 阻断
   - 退款率过高的用户 → 标记风控

4. 回调验签:
   - Stripe: 验证 webhook signature (stripe-signature header)
   - Coinbase: 验证 webhook signature (X-CC-Webhook-Signature header)
   - 支付宝: 验证 notify_id 回调支付宝服务器二次确认
   - 所有回调: 验证 IP 是否为已知支付网关 IP 段
```

#### 退款安全

```
- 退款必须经过二级审批 (客服发起 → 管理员确认)
- 退款前校验: 订单状态、退款时限、退款次数
- 退款金额不能超过原订单实付金额
- 原路退回: 支付通道退款接口 + 余额退回
- 退款互斥锁 (Redis): 防止并发重复退款
```

---

### 4.6 访问控制与权限

#### RBAC 模型

```
角色层级:
  super_admin    (超级管理员 — 全部权限)
  admin          (管理员 — 除系统配置外全部)
  finance        (财务 — 支付/对账/退款/结算)
  support        (客服 — 用户/订单/工单管理)
  supplier       (供应商 — 自己的商品/订单/结算)
  user           (普通用户 — 自己的资源/订单/工单)

权限定义:
  {module}.{action}
  例: order.view, order.create, order.refund, resource.destroy

权限检查中间件:
  class RbacMiddleware
  {
      public function process(Request $request, callable $next): Response
      {
          $user = Auth::user();
          $requiredPermission = $request->route->get('permission');
          
          if (!$user || !$user->hasPermission($requiredPermission)) {
              AuditLog::unauthorized($user, $requiredPermission, $request);
              return errorResponse(403, 'Forbidden');
          }
          return $next($request);
      }
  }
```

#### API 速率限制

```php
// webman 限流中间件 (Redis 令牌桶)
class RateLimitMiddleware
{
    // 默认: 60 req/min 每用户
    private array $limits = [
        'default'     => ['rate' => 60,   'burst' => 10, 'per' => 60],
        'login'       => ['rate' => 5,    'burst' => 2,  'per' => 60],  // 防暴力破解
        'register'    => ['rate' => 3,    'burst' => 0,  'per' => 60],  // 防批量注册
        'pay'         => ['rate' => 10,   'burst' => 3,  'per' => 60],  // 支付限速
        'api'         => ['rate' => 120,  'burst' => 20, 'per' => 60],  // API 调用
        'upload'      => ['rate' => 10,   'burst' => 2,  'per' => 60],  // 上传限速
    ];
    
    public function process(Request $request, callable $next): Response
    {
        $route = $request->route->getName();
        $limit = $this->limits[$route] ?? $this->limits['default'];
        $key = "ratelimit:{$request->getRealIp()}:{$route}";
        
        if (!$this->checkLimit($key, $limit)) {
            return errorResponse(429, 'Too Many Requests', [
                'retry_after' => $limit['per'],
            ]);
        }
        return $next($request);
    }
}
```

#### 供应商数据隔离

```
数据隔离原则:
- 供应商只能查询和操作自己的资源
- 所有涉及 supplier_id 的查询自动追加 WHERE supplier_id = auth()->supplier_id

实现方式:
  // 全局 Scope
  class SupplierScope implements Scope
  {
      public function apply(Builder $builder, Model $model)
      {
          if ($user = Auth::user()) {
              if ($user->role === 'supplier') {
                  $builder->where('supplier_id', $user->supplier_id);
              }
          }
      }
  }
  
  // 在 Product/Order 等 Model 上注册
  protected static function booted()
  {
      static::addGlobalScope(new SupplierScope);
  }
```

---

### 4.7 操作审计

```
审计日志记录内容:
- 操作者 ID、IP、User-Agent
- 操作时间
- 操作模块 (哪个菜单/接口)
- 操作类型: 创建/修改/删除/导出/审批
- 操作对象: 哪个资源的哪个字段
- 操作前值 / 操作后值 (字段级变更)
- 操作结果: 成功/失败
- 请求 ID (全链路追踪)

记录范围:
- 所有管理端操作 (100% 记录)
- 用户端敏感操作: 支付/销毁资源/KYC提交/修改密码 (100% 记录)
- 登录/登出 (100% 记录)
- API Key 创建/撤销 (100% 记录)

存储与保留:
- 审计日志写入独立数据库 (audit_db)，与应用库分离
- 至少保留 1 年，金融相关保留 3 年
- 支持导出为 CSV/JSON 供合规审查

审计日志中间件:
  class AuditMiddleware
  {
      public function process(Request $request, callable $next): Response
      {
          $startTime = microtime(true);
          $response = $next($request);
          $duration = microtime(true) - $startTime;
          
          if ($this->shouldAudit($request)) {
              AuditLog::record([
                  'user_id'    => Auth::id(),
                  'ip'         => $request->getRealIp(),
                  'method'     => $request->method(),
                  'path'       => $request->path(),
                  'input'      => LogSanitizer::sanitize($request->all()),
                  'status'     => $response->getStatusCode(),
                  'duration'   => $duration,
                  'request_id' => $request->header('X-Request-Id'),
                  'user_agent' => $request->header('User-Agent'),
              ]);
          }
          return $response;
      }
  }
```

---

### 4.8 风控规则

```
实时风控引擎:

规则 1: 新账号异常行为
  条件: 注册时间 < 24h AND (支付总额 > $500 OR 创建工单 > 5)
  动作: 标记账号为"观察中"，通知风控管理员

规则 2: 批量注册检测
  条件: 同一 IP 24h 内注册 > 3 个账号
  动作: 拒绝新注册，冻结该 IP 下新账号

规则 3: 支付异常
  条件: 同一用户 1h 内支付失败 > 5 次
  动作: 冻结支付功能 2h，生成风控工单

规则 4: 退款滥用
  条件: 同一用户 30 天内退款 > 3 笔 OR 退款率 > 20%
  动作: 限制该账号退款权限，新订单标记风控审查

规则 5: API 滥用
  条件: 单 token 1h 内 API 调用 > 10000 次
  动作: 该 token 降级 (降低限流阈值)，通知管理员

规则 6: 资源滥用
  条件: VM 被投诉 spam/DDoS/挖矿 (接收 Abuse 通知)
  动作: 自动关机，冻结资源，生成高优先级工单

风控动作:
- 标记 (flag): 仅记录，不影响使用
- 降级 (throttle): 降低限流阈值
- 冻结 (freeze): 暂时禁用特定功能
- 封禁 (ban): 账号永久封禁
```

---

### 4.9 应急响应

```
安全事件分级:

P0 (紧急) — 数据泄露、资金损失、平台宕机
  → 立即通知 CTO + 安全团队
  → 30 分钟内启动应急响应
  → 下线上游受影响服务，保留证据
  → 修复后 24h 内发布事件报告

P1 (严重) — 单账号被盗、支付欺诈、WAF 触发异常上升
  → 通知安全负责人
  → 2h 内处理
  → 冻结受影响账号/资源

P2 (一般) — 漏洞扫描发现中低危漏洞、异常登录告警
  → 录入工单系统
  → 下一个迭代修复

应急联系:
- 触发 P0/P1 告警后自动通知 (邮件 + 短信 + 电话)
- webman 健康检查端点: GET /health (返回 200 或告警)
- 值班表: 7×24 轮值，至少 2 人备岗
```

---

## 五、资源开通引擎

### Provider 插件架构

每种云产品类型 × 云厂商的组合，实现统一接口:

```php
interface ResourceProvider
{
    public function create(ProvisionTask $task): ProvisionResult;
    public function renew(Resource $resource, int $months): ProvisionResult;
    public function upgrade(Resource $resource, array $newSpecs): ProvisionResult;
    public function destroy(Resource $resource): ProvisionResult;
    public function status(Resource $resource): ResourceStatus;
    public function consoleUrl(Resource $resource): string;
    // 物理机自营专用
    public function resizeDisk(Resource $resource, int $newSizeGb): ProvisionResult;
    public function createDisk(ProvisionTask $task): ProvisionResult;
    public function createIp(ProvisionTask $task): ProvisionResult;
}
```

ProviderFactory 根据 (product_type, provider) 路由到具体实现:
- ProxmoxProvider (自营物理机: 服务器/数据盘/IP)
- AwsServerProvider / AliyunServerProvider (第三方云服务器)
- GcpIpProvider (第三方 IP)
- AzureDiskProvider (第三方云盘)
- NamecheapDomainProvider / GoDaddyDomainProvider (域名)

### 异步任务保障

- Provisioning Worker 轮询 provision_tasks 表
- 按 provider 分组控制并发 (每个 provider 最多 5 并发)
- 重试策略: 1min → 5min → 15min → 1h → 6h → 24h (最多 6 次)
- 不可重试失败 → 告警 + 自动生成工单

### 订单到资源开通完整链路

```
用户下单                               支付                             资源开通
────────                               ────                             ────────
1. POST /cart                          5. POST /orders/{id}/pay         9. OrderPaid 事件
   → addToCart(sku, region, qty)          → 密码二次确认 (Confirmation)      → ProvisioningService
                                                                             .handleOrderPaid()
2. POST /orders                           → PaymentRouter::route()
   → createOrder()                          选择支付通道                   10. 每个 OrderItem:
   ← {order, order_items}                                                    → ProvisionTask::create()
                                        6. StripeChannel::                     status=pending
3. 应用优惠券                               createPaymentIntent()
   POST /coupons/validate                   → Stripe API                 11. Redis Queue Worker
   → validate('CODE', order_total)          ← {client_secret}                → ProviderFactory
   ← {discount, coupon_id}                                                     .create(task)
                                        7. 前端 confirmCardPayment()
4. GET /orders/{id}/payment-methods     8. Stripe webhook 回调            12. Provider->create()
   → 获取可用支付通道                       → 验签 + 幂等检查                   ├→ HostSelector::select()
   ← [{channel, fee, total}]               → transaction=success              ├→ ProxmoxApi::create()
                                            → 触发 OrderPaid 事件               │  createVM(CPU,RAM,Disk)
                                                                              │  allocateIP()
                                                                              │  startVM()
                                        重试策略 (失败时)                      ├→ 创建 Resource 记录
                                        ────────────────                     └→ 更新 host_machine
                                        1min → 5min → 15min                      已分配资源量
                                        → 1h → 6h → 24h
                                        (6 次后标记失败 + 告警)           13. Order::status = completed
                                                                           → NotificationDispatcher
                                        退款流程                                ::send('resource_ready')
                                        ────────
                                        用户申请 → 客服审核 → admin 确认
                                        → provider.destroy()
                                        → payment.refund()
                                        → 原路退回
```

### 自营物理机方案：Proxmox VE (社区版)

自营服务器采用 Proxmox VE (开源免费，AGPL v3)，PHP 通过 HTTP 调用 Proxmox REST API 管理 KVM 虚拟机生命周期和资源分配。

架构:
```
PHP (webman) ──HTTPS──> Proxmox VE API (port 8006)
                               │
                               └──> KVM/QEMU ──> VM (分配给用户)
```

#### ProxmoxApi 客户端封装

```php
class ProxmoxApi
{
    private string $baseUrl;
    private string $token;

    public function __construct(HostMachine $host)
    {
        $this->baseUrl = "https://{$host->ip_address}:8006/api2/json";
        $this->token   = $host->api_token_encrypted;
    }

    // GET  /api2/json/nodes/{node}/...
    public function get(string $path, array $params = []): array;
    // POST /api2/json/nodes/{node}/...
    public function post(string $path, array $data = []): array;
    // PUT  /api2/json/nodes/{node}/...
    public function put(string $path, array $data = []): array;
    // DELETE /api2/json/nodes/{node}/...
    public function delete(string $path): array;
}
```

#### 资源操作

**创建 VM (服务器):**
1. HostSelector 选择一台资源够用的宿主机 (按 cpu/ram/disk 余量 + 负载均衡排序)
2. 从该宿主机的 ip_pool 分配一个 IP
3. ProxmoxApi.post("/nodes/{node}/qemu") 创建 VM (设定 vmid、name、cores、memory、net0、ipconfig0)
4. ProxmoxApi.post("/nodes/{node}/qemu/{vmid}/config") 挂载系统盘 (scsi0: storagePool:sizeG)
5. ProxmoxApi.post("/nodes/{node}/qemu/{vmid}/status/start") 启动 VM
6. 更新 host_machine.specs 已分配量 (cpu_allocated / ram_allocated_gb / disk_allocated_gb)

**升级 CPU (在线):**
```php
$api->put("/nodes/{node}/qemu/{vmid}/config", ['cores' => $newCpu]);
$host->recalculate(); // 更新宿主机资源统计
```

**升级内存 (在线):**
```php
$api->put("/nodes/{node}/qemu/{vmid}/config", ['memory' => $newRamGb * 1024]);
$host->recalculate();
```

**扩容系统盘:**
```php
$api->put("/nodes/{node}/qemu/{vmid}/resize", ['disk' => 'scsi0', 'size' => "{$newSizeGb}G"]);
```

**单独创建数据盘:**
```php
$diskSlot = nextDiskSlot($vmid); // scsi1, scsi2...
$api->post("/nodes/{node}/qemu/{vmid}/config", [$diskSlot => "{$pool}:{$sizeGb}G"]);
```

**单独创建 IP:**
从 IP 池分配 → 通过 Proxmox API 添加虚拟网卡 + 配置 IP，或保留为独立资源分配给已有 VM 的额外网卡。

**销毁 VM:**
```php
$api->post("/nodes/{node}/qemu/{vmid}/status/stop");  // 关机
$api->delete("/nodes/{node}/qemu/{vmid}");             // 删除 VM
releaseIp($resourceId);                                // 释放 IP 回池
$host->deallocate($specs);                             // 回收宿主机资源
```

#### 宿主机选择策略

```php
class HostSelector
{
    public function select(int $regionId, array $specs): HostMachine
    {
        return HostMachine::where('region_id', $regionId)
            ->where('status', 'online')
            ->whereRaw('JSON_EXTRACT(specs, "$.cpu_total") - JSON_EXTRACT(specs, "$.cpu_allocated") >= ?', [$specs['cpu']])
            ->whereRaw('JSON_EXTRACT(specs, "$.ram_total_gb") - JSON_EXTRACT(specs, "$.ram_allocated_gb") >= ?', [$specs['ram']])
            ->whereRaw('JSON_EXTRACT(specs, "$.disk_total_gb") - JSON_EXTRACT(specs, "$.disk_allocated_gb") >= ?', [$specs['system_disk']])
            ->orderByRaw('JSON_EXTRACT(specs, "$.cpu_allocated") / JSON_EXTRACT(specs, "$.cpu_total") ASC')
            ->firstOrFail();
    }
}
```

#### 资源拆分操作汇总

| 操作 | 实现方式 | 热操作 |
|------|----------|--------|
| 创建 VM (CPU+内存+系统盘+IP) | Proxmox create qemu | — |
| 单独升级 CPU | PUT config cores | 在线 |
| 单独升级内存 | PUT config memory | 在线 |
| 扩容系统盘 | PUT resize disk | 在线 (需 VM 支持) |
| 单独创建数据盘 | POST config 添加磁盘 | 在线 |
| 单独创建 IP | 从 IP 池分配 + VM 添加网卡 | 在线 |

### 资源生命周期

```
pending → active → destroyed (保留 30 天) → purged (不可恢复)
```

续费: active → (renew) → active (延长 expired_at)
升级: active → (upgrade) → upgrading → active

### 资源来源

| 来源 | 虚拟化/API | 产品类型 | 说明 |
|------|-----------|----------|------|
| 自营物理机 | Proxmox VE (社区版) | 服务器、数据盘、IP | 自有数据中心托管，PHP 调 Proxmox API |
| 第三方云厂商 | AWS/GCP/阿里云/华为云/Azure SDK | 服务器、IP、云盘 | 转售第三方云资源 |
| 域名注册商 | Namecheap/GoDaddy/阿里云万网 API | 域名注册/转移 | 域名服务 |

### 首期对接

| 区域 | 服务器 | IP | 云盘 | 域名 |
|------|--------|----|------|------|
| 亚太 | 阿里云、华为云、AWS | 阿里云、GCP | 阿里云、华为云 | 阿里云万网、Namecheap |
| 欧洲 | AWS、GCP、Hetzner | GCP、OVH | AWS、GCP | Namecheap、Gandi |
| 北美 | AWS、GCP、Azure | AWS、GCP | AWS、Azure | GoDaddy、Namecheap |

---

## 六、支付系统

### 多通道路由

PaymentRouter 根据用户币种偏好查询可用通道，计算各通道实付金额 (含通道手续费)，返回支付选项列表。

### 支付流程 (Stripe)

```
用户端 (Flutter)               服务端 (webman)                Stripe API
───────────────               ──────────────                ──────────
1. 选择 Stripe 支付
    → POST /orders/{id}/pay ──→ 2. StripeChannel
    ← client_secret               createPaymentIntent() ──→ 3. paymentIntents.create
                                                              ← pi_xxx, client_secret
                               4. 创建 payment_transaction
                                  (status=pending)
                                  ← client_secret
5. confirmCardPayment()
    → Stripe SDK ──────────────────────────────────────────→ 6. 用户确认支付
                                                              ← payment_intent.succeeded
                               7. POST /payments/webhook/stripe ←
                                  Webhook::constructEvent()
                                  验签 (stripe-signature)
                                  幂等检查 (transaction_no)
                               8. 更新 transaction=success
                               9. 触发 OrderPaid 事件
                                  ├→ AuditLogger::record()
                                  ├→ NotificationDispatcher::send()
                                  └→ ProvisioningService::handleOrderPaid()

      ← 支付成功页面               ← 返回订单状态
```

### 加密货币支付

1. 用户选择币种 (如 USDT-TRC20)
2. 后端通过 Coinbase Commerce / BitPay API 生成收款地址
3. Worker 每 30s 查区块链确认 (或 webhook)
4. 确认到账 → 触发 OrderPaid 事件

### 汇率与多币种

- 汇率源定时从 exchangerate-api 拉取存入 Redis
- 商品定价以 USD 为基准，其他币种实时换算
- 下单时锁定汇率，退款时按原汇率退回

### 支付通道可见性控制

payment_channels 表字段:
- is_visible: 是否对用户端展示
- visible_regions: 限定可见地区，空表示全部
- min_amount / max_amount: 订单金额区间限制

### 对账

每日凌晨拉取各通道结算报表，与系统 transaction 逐笔对账，差异 > $0.01 告警。

### 退款策略

- 服务器/VPS: 购买后 72h 内全额退款
- 域名: 注册后 5 天内可退款 (ICANN 规范)
- IP: 购买后不可退款
- 云盘: 同服务器策略
- 特殊促销商品: 不可退款

退款流程: 用户申请 → Ticket 生成 → 客服审核 → admin 确认 → provider.destroy() → payment.refund() → 原路退回

---

## 七、客户端页面结构

### Flutter / HarmonyOS 用户端

- **认证**: 登录/注册 (邮箱+密码、Google OAuth、Apple ID、手机号)、忘记密码、两步验证
- **首页**: 区域选择器、产品分类入口、Banner/促销、推荐商品
- **产品**: 列表 (多条件筛选)、详情 (配置/区域/价格计算器)、评价
- **购物&支付**: 购物车、订单确认 (支付方式/账单地址/余额/优惠码)、收银台、支付结果
- **我的资源**: 资源列表 (按状态筛选)、详情操作 (重启/关机/续费/升级/销毁)、控制台 SSO、用量图表
- **订单**: 列表 (待付/已付/已完成/已退款)、详情、发票
- **工单**: 列表、新建、对话
- **个人中心**: 资料/KYC、余额&充值、通知、地址管理、语言/货币/安全设置
- **公共**: 帮助中心、服务条款、关于

### webman-admin 管理后台

- **仪表盘**: 总览 + 趋势图
- **用户管理**: 列表/详情/KYC 审核
- **商品管理**: 分类/列表/定价(SKU×区域)/库存/评价
- **订单管理**: 列表/详情/退款审核/发票
- **支付管理**: 通道配置/交易记录/对账报表
- **资源管理**: 列表/开通任务监控/云厂商 API 配置
- **供应商管理**: 入驻审核/列表/商品分配/结算/提现
- **工单管理**: 队列/我的工单/SLA 监控
- **域名管理**: TLD 定价/注册商 API/转移管理
- **消息通知**: 模板管理/发送记录
- **系统设置**: 管理员&角色/操作日志/多语言/汇率/区域/系统参数
- **报表**: 营收/供应商结算/产品销售分析/区域分析

---

## 八、消息通知系统

### 四通道

Email (SMTP/SendGrid) / SMS (Twilio/阿里短信) / Push (FCM/HMS) / 站内信

### 流程

事件触发 → Notification Dispatcher → 匹配模板 (事件码+语言偏好) → 按用户偏好分发各通道 → Redis Queue 异步发送

### 通知类型

注册验证码、订单支付成功、资源开通完成、资源到期提醒 (7d/3d/1d)、工单回复、退款完成、安全告警、促销活动

### 失败重试

3 次退避，通过 webman redis-queue 管理。

---

## 九、供应商系统

### 入驻流程

注册 → 提交公司信息+联系人+结算方式 → 管理员审核 → 通过后上架商品 → admin 审核商品 → 用户购买 → 自动分账 → 供应商申请提现 → admin 打款

### 权限隔离

供应商只能看自己的商品/订单/结算单/工单/提现记录。不能看平台营收、其他供应商数据、支付通道配置。

### 分账规则

- 自营商品: commission_rate = 100% (全归平台)
- 第三方商品: commission_rate = 5%~20% (平台抽成)
- 结算公式: 订单商品金额 - 平台抽成 - 通道手续费 = 供应商应收
- 结算周期: 周结 / 月结

### 供应商完整业务流程

```
供应商入驻                              管理员审批
──────────                             ──────────
POST /supplier/apply                   GET /admin/api/suppliers?status=pending
  → {company_name, contact_name,         → 审核供应商信息
     contact_phone, contact_email,       POST /admin/api/suppliers/{id}/approve
     settlement_method}                    → 确认密码
  → SupplierService::apply()               → SupplierService::approve()
  ← {supplier, status:pending}               → User::role = 'supplier'
                                              ← 成功
商品上架
────────
POST /supplier/products               管理员审核
  → {product_id, commission_rate}        → 关联供应商商品 + 设置佣金比例
  ← {supplier_product}                    → 商品状态: published

用户下单 ──→ 支付完成 ──→ 资源开通 ──→ 订单完成

定时结算 (每周一 04:17)                   提现
───────────────────────                 ──────
Cron: SupplierSettlement               POST /supplier/withdraw
  → 统计周期内已完成订单                    → 密码二次确认 (ConfirmationMiddleware)
  → 计算 total_sales - commission        → SupplierService::requestWithdraw()
  → = payable                              → 检查可提现余额
  → 创建 SupplierSettlement                 → 创建 SupplierWithdraw (status:pending)
  → Webhook: settlement.created          ← 成功

管理员打款                              管理员 API Key 管理
───────────                             ──────────────────
POST /admin/api/suppliers/              POST /admin/api/suppliers/{id}/api-keys
  withdraws/{id}/approve                  → 生成 sk_xxx (SHA256 存储)
  → 确认密码                               ← {api_key} (仅显示一次)
  → SupplierWithdraw: status=completed  DELETE /admin/api/suppliers/api-keys/{id}
  → Webhook: withdrawal.approved           → revoked=true
```

---

## 十、监控与运维

### 资源监控

- 采集指标: CPU/内存/磁盘/带宽使用率、IP 连通性、云盘 IOPS、DNS 解析、SSL 证书到期
- 采集方式: Agent 上报 / SNMP (自有) + 云厂商监控 API (第三方) + WHOIS/DNS 轮询 (域名)
- 采集周期: 5 分钟，Prometheus + VictoriaMetrics 存储

### 告警规则

| 告警事件 | 严重度 | 触发条件 |
|----------|--------|----------|
| 服务器宕机 | 严重 | 连续 3 次 Ping 不可达 |
| CPU/内存 > 90% | 提示 | 持续 10 分钟 |
| 磁盘 > 90% | 警告 | 持续 5 分钟 |
| 带宽 > 80% | 提示 | 持续 30 分钟 |
| SSL 证书 < 30 天到期 | 警告 | 每日检查 |
| 域名 < 30 天到期 | 警告 | 每日检查 |
| 开通任务失败 | 严重 | 连续失败 2 次 |
| 支付对账差异 | 严重 | 单笔 > $0.01 |

---

## 十一、部署架构

### 生产环境

- 应用服务器 × 2: webman (多进程) + Nginx + Supervisor
- 数据库: MySQL 8.0 主从 (1 主 2 从) + Redis Cluster
- 队列: webman redis-queue (支付回调/通知/开通任务)
- 定时任务: Crontab (对账/结算/域名检查/续费提醒)
- 存储: S3/OSS + CDN
- 日志监控: ELK/Loki + Prometheus + Grafana + Sentry

### 目录结构

```
cloud-php/
├── apps/
│   ├── flutter/           # Flutter 客户端
│   └── harmonyos/         # HarmonyOS 客户端 (ArkTS)
├── service/               # webman 服务端
│   ├── app/
│   │   ├── controller/    # 控制器 (按模块)
│   │   ├── service/       # 业务逻辑 (按模块)
│   │   ├── model/         # 数据模型
│   │   ├── middleware/     # 中间件
│   │   ├── event/         # 事件定义
│   │   ├── listener/      # 事件监听器
│   │   ├── queue/         # 队列任务
│   │   ├── provider/      # 云厂商适配器
│   │   └── cron/          # 定时任务
│   ├── common/            # 公共库 (auth/payment/i18n/notification/helper)
│   ├── config/            # 配置文件
│   ├── database/
│   │   └── migrations/    # 数据库迁移
│   └── storage/           # 日志/缓存/上传
├── admin/                 # webman-admin
├── docs/                  # 文档
└── docker/                # Docker 配置
```

### 关键 Composer 依赖

workerman/webman-framework、webman/admin、webman/redis-queue、illuminate/database、firebase/php-jwt、stripe/stripe-php、phpseclib/phpseclib、monolog/monolog

---

## 十二、实现状态总表

### 核心模块

| 模块 | 状态 | 说明 |
|------|------|------|
| **User** | ✅ 完成 | 注册/登录/邮箱验证/OAuth/TOTP/会话管理/GDPR注销/地址CRUD |
| **Product** | ✅ 完成 | SKU×区域定价、分类、搜索(ES)、评价、属性、批量导入导出 |
| **Order** | ✅ 完成 | 购物车、下单、生命周期、退款、发票(PDF)、优惠券 |
| **Payment** | ✅ 完成 | Stripe通道、多通道路由、webhook验签、对账 |
| **Provisioning** | ✅ 完成 | Proxmox + AWS EC2 + ProviderFactory可扩展架构 |
| **Domain** | ✅ 完成 | TLD定价、DNS记录、域名转移审批 |
| **Supplier** | ✅ 完成 | 入驻审批、商品上架、结算、提现、API Key管理 |
| **Monitor** | ✅ 完成 | 资源探活、告警引擎、SSL证书监控 |
| **Ticket** | ✅ 完成 | 创建/回复/分配/关闭/SLA追踪 |
| **Notification** | ✅ 完成 | 邮件/SMS/Push/站内信四通道 + 用户偏好管理 |
| **Report** | ✅ 完成 | 营收/供应商/区域报表 |
| **I18n** | ✅ 完成 | 多语言、多币种、多时区 |

### 安全体系

| 功能 | 状态 |
|------|------|
| WAF (8 类 45+ 规则: SQL注入/XSS/命令注入/文件包含/头注入/SSRF/NoSQL注入/开放重定向) | ✅ |
| CORS 中间件 | ✅ |
| ClientPlatform 平台识别中间件 (8 种平台) | ✅ |
| API 限流 (Redis令牌桶) | ✅ |
| Geo-Blocking (MaxMind GeoIP2) | ✅ |
| 维护模式 (环境变量开关 + IP白名单) | ✅ |
| 请求/响应加密 (AES-256-GCM) | ✅ |
| 审计日志 (独立库，含 client_platform 追踪) | ✅ |
| 数据脱敏 (日志/响应自动处理) | ✅ |
| JWT 设备指纹绑定 + token 轮换 + client_platform 记录 | ✅ |
| bcrypt 密码 (cost=12) + Encryptable 二次加密 | ✅ |
| 二次密码确认 (ConfirmationMiddleware，5 次失败锁 15min) | ✅ |
| Admin 面板 WAF 中间件 | ✅ |

### 后端统计

| 指标 | 数量 |
|------|------|
| API 端点 | 127 |
| 数据模型 | 50+ |
| 数据库表 | 50+ |
| 中间件 | 14 个（全局 7 + 路由 5） |
| 定时任务 | 7 个 |
| 迁移文件 | 21 个 |
| 测试 | 362 tests / 579 assertions（Service 295 + Admin 67） |
| 测试文件 | 22 个 |

### 文档

| 文档 | 路径 |
|------|------|
| 系统设计规范 | `docs/superpowers/specs/2026-05-14-cloud-resource-platform-design.md` |
| 管理后台设计 | `docs/admin-design.md` |
| 供应商 API 文档 | `docs/supplier-api.md` |
| 部署清单 | `docs/deployment.md` |
| API 冒烟测试脚本 | `docs/api-test.sh` |

### 前端状态

| 端 | 状态 | 说明 |
|----|------|------|
| Flutter | 🟡 进行中 | ApiClient 已接入 header 版本号 + ApiService 统一数据层；登录/商品列表/购物车/资源列表已对接 API；订单历史/通知中心需编译环境验证 |
| HarmonyOS | 🔴 初期 | 仅登录页和 ApiClient |
| Admin Panel | ✅ 完成 | 仪表盘/用户/商品/订单/支付/资源/供应商/工单/域名/通知/系统/报表/Webhook/导入导出 全功能 |
