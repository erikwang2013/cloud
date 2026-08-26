# Platform Perdagangan Sumber Daya Cloud Global — Desain Sistem

## Ikhtisar Proyek

Platform perdagangan sumber daya cloud yang melayani pengguna global, mendukung mode campuran milik sendiri + pemasok pihak ketiga. Pengguna dapat membeli server, IP, cloud disk, domain, dan produk cloud lainnya. Pengaktifan sumber daya sepenuhnya otomatis, banyak saluran pembayaran, banyak mata uang, banyak bahasa.

### Tumpukan Teknologi

| Lapisan | Teknologi |
|------|------|
| Aplikasi Pengguna | Flutter (iOS/Android) + HarmonyOS (ArkTS) |
| Panel Admin | webman-admin |
| Server | PHP webman (modular monolith) |
| Database | MySQL 8.0 (master/slave) |
| Cache/Antrean | Redis (Cache + Session + Queue) |
| Penyimpanan | S3/OSS + CDN |
| Monitoring | Prometheus + Grafana + Sentry + ELK/Loki |

---

## I. Pembagian Modul (12 modul inti)

| Modul | Tanggung jawab |
|------|------|
| **User** | Registrasi/login (OAuth+email+ponsel), verifikasi KYC, tingkat keanggotaan, akun saldo |
| **Product** | Definisi produk (SKU), penetapan harga multi-region, manajemen stok, kategori, pencarian, ulasan |
| **Order** | Keranjang belanja, pembuatan pesanan, siklus hidup pesanan (menunggu bayar→dibayar→pengaktifan→selesai→refund), perpanjangan/upgrade |
| **Payment** | Perutean saluran pembayaran, kuotasi multi-mata uang, nilai tukar, refund, rekonsiliasi |
| **Provisioning** | Integrasi API berbagai vendor cloud, pembuatan/perpanjangan/penghancuran sumber daya otomatis |
| **Domain** | Pencarian domain, registrasi, transfer, perpanjangan, manajemen DNS |
| **Supplier** | Pendaftaran supplier, persetujuan, penayangan produk, settlement, bagi hasil |
| **Monitor** | Pemeriksaan status sumber daya, pengumpulan pemakaian, aturan alarm |
| **Ticket** | Pengajuan tiket, penugasan, pelacakan SLA |
| **Notification** | Email/SMS/Push App/notifikasi situs, multi-template multi-bahasa |
| **Report** | Laporan pendapatan, laporan settlement supplier, tren penjualan |
| **I18n** | Entri multi-bahasa, nilai tukar multi-mata uang, multi-zona waktu |

---

## II. Model Data Inti

### Pusat Pengguna (User)

- **users** — Tabel utama pengguna (id, email, phone, password_hash, language, currency, timezone, status)
- **user_profiles** — Profil pengguna (user_id, avatar, nickname, country)
- **user_kyc** — Verifikasi identitas (user_id, id_type, id_number, real_name, front/back_image, status, verified_at)
- **user_balance** — Akun saldo (user_id, currency, balance, frozen_balance)
- **user_balance_log** — Catatan perubahan saldo (user_id, type, amount, before, after, order_id, remark)
- **user_addresses** — Alamat pengguna (user_id, type: billing/shipping, name, phone, country, state, city, address, postcode, is_default)

### Pusat Produk (Product)

- **product_categories** — Kategori produk (id, parent_id, name, icon, sort)
- **products** — Tabel utama produk (id, supplier_id, category_id, name, slug, description, cover, status)
- **product_skus** — SKU (id, product_id, specs: {cpu, ram, disk, bandwidth, os...}, cycle: monthly/quarterly/yearly)
- **product_regions** — Penetapan harga regional (sku_id, region_id, price, original_price, stock, currency)
- **product_images** — Gambar produk (product_id, url, sort)
- **product_attributes** — Atribut kustom (product_id, key, value)
- **product_reviews** — Ulasan produk (user_id, product_id, order_id, rating, content)
- **regions** — Tabel wilayah (id, name, continent, country, city, data_center, status)

### Pusat Pesanan (Order)

- **carts** — Keranjang belanja (user_id, sku_id, region_id, quantity, cycle)
- **orders** — Tabel utama pesanan (id, order_no, user_id, type: new/renew/upgrade, status, currency, subtotal, discount, tax, total, paid_at)
- **order_items** — Detail pesanan (order_id, sku_id, region_id, product_id, quantity, cycle, unit_price, total_price, resource_snapshot)
- **order_timeline** — Linimasa pesanan (order_id, status, operator, remark, created_at)
- **order_invoices** — Faktur (order_id, user_id, type, title, tax_number, amount, file_url)
- **refunds** — Formulir refund (order_id, user_id, amount, reason, status, handled_by)

### Pusat Pembayaran (Payment)

- **payment_channels** — Konfigurasi saluran pembayaran (id, name, code: stripe/crypto/alipay/wechat, currency_support, fee_config, is_visible, visible_regions, min_amount, max_amount, status)
- **payment_transactions** — Catatan transaksi (id, order_id, user_id, channel_id, amount, currency, exchange_rate, channel_fee, transaction_no, status, callback_at)
- **payment_reconcile** — Tabel rekonsiliasi (date, channel_id, channel_total, system_total, diff, status)

### Pengaktifan Sumber Daya (Provisioning)

- **resources** — Tabel utama sumber daya (id, order_item_id, user_id, product_id, type: server/ip/disk/domain, provider, region, status, provisioned_at, expired_at)
- **resource_servers** — Detail server (resource_id, hostname, ip_address, login_user, login_password, os, cpu, ram, disk, bandwidth, panel_url)
- **resource_ips** — Detail IP (resource_id, ip_address, subnet, gateway, rdns)
- **resource_disks** — Detail cloud disk (resource_id, disk_size, disk_type, attach_to_resource_id)
- **resource_domains** — Detail domain (resource_id, domain_name, registrar, dns_servers, whois_privacy, auto_renew)
- **provision_tasks** — Tugas pengaktifan (order_id, resource_id, provider, action: create/renew/upgrade/destroy, status, params, result, retry_count, next_retry_at)
- **provider_apis** — Konfigurasi API vendor cloud (id, name, code, api_key_encrypted, api_secret_encrypted, webhook_secret, status)

### Manajemen Sumber Daya Server Fisik (Host & IP Pool)

Server fisik milik sendiri menggunakan Proxmox VE (edisi komunitas, gratis) untuk mengelola VM, melalui REST API membuat/mengelola VM, mengalokasikan IP, memasang disk.

- **host_machines** — Host server (id, name, region_id, ip_address, proxmox_node, proxmox_api_url, api_token_encrypted, status: online/maintenance/offline, specs: {cpu_total, cpu_allocated, ram_total_gb, ram_allocated_gb, disk_total_gb, disk_allocated_gb}, storage_pool, created_at, updated_at)
- **ip_pools** — Kumpulan IP (id, host_machine_id, region_id, network_cidr, gateway, vlan_id, ip_start, ip_end, total_count, used_count, status)
- **ip_allocations** — Catatan alokasi IP (id, ip_pool_id, resource_id, ip_address, type: primary/secondary, allocated_at, released_at)
- **disks** — Detail disk VM (id, resource_id, host_machine_id, vm_id, size_gb, disk_type: system/data, storage_pool, device_path, status)
- **disk_resizes** — Catatan perluasan disk (id, disk_id, old_size_gb, new_size_gb, status, finished_at)

### Supplier

- **suppliers** — Tabel utama supplier (id, user_id, company_name, contact, status, settlement_method)
- **supplier_products** — Relasi produk supplier (supplier_id, product_id, approved_at, commission_rate)
- **supplier_settlements** — Formulir settlement (supplier_id, period_start, period_end, total_sales, commission, payable, status, paid_at)
- **supplier_withdraws** — Catatan penarikan (supplier_id, amount, method, account_info, status)

### Layanan Domain (Domain)

- **domain_tlds** — TLD yang didukung (tld, wholesale_price, retail_price, registrar, promo_price, promo_end_at)
- **domain_transfers** — Transfer domain (domain_name, user_id, auth_code, from_registrar, status)
- **dns_zones** — Zona DNS (domain_name, user_id, zone_id)
- **dns_records** — Catatan DNS (zone_id, type, name, value, ttl, priority)

### Tiket dan Notifikasi (Ticket & Notification)

- **tickets** — Tiket (id, ticket_no, user_id, resource_id, category, priority, title, status, assigned_to, sla_deadline)
- **ticket_messages** — Pesan tiket (ticket_id, sender_id, sender_type: user/staff, content, attachments)
- **notifications** — Catatan notifikasi (user_id, channel: email/sms/push/in_app, template_code, content, send_status, read_at)
- **notification_templates** — Template notifikasi (code, name, channels, title_template, body_template, variables)

---

## III. Standar Desain API

### Manajemen Versi

Versi API ditentukan melalui header HTTP `X-Api-Version`, tidak berada di jalur URL. Server menyuntikkan header versi ke routing internal melalui middleware.

```
Permintaan:  GET /api/auth/login
Header: X-Api-Version: v1

Routing internal → /api/auth/login → Controller
Header respons: X-Api-Version: v1
```

**Versi yang didukung**: `v1` (default, otomatis digunakan saat header tidak ada)

**Mekanisme kontrol versi**: `VersionMiddleware` memvalidasi header `X-Api-Version` untuk semua path `/api/*` dan `/admin/api/*`, default `v1` jika tidak ada, versi yang tidak didukung mengembalikan `400`. Nomor versi tidak lagi disertakan di jalur URL.

**Langkah menambahkan versi**:
1. Tambahkan nomor versi ke array `VersionMiddleware::SUPPORTED`
2. Daftarkan grup route versi baru di `route.php`
3. Controller mengambil versi melalui `$request->properties['api_version']` untuk penanganan yang berbeda

### Routing RESTful

```
Prefix terpadu: /api
Panel admin: /admin/api
```

**Grup route dan matriks middleware:**

| Grup route | Middleware | Contoh endpoint |
|--------|--------|---------|
| Publik (tanpa prefix) | Rantai middleware global | `/health`, `/api/products`, `/api/help`, `/api/domain/tlds` |
| `/api/auth` | Global + Encryption | `/api/auth/register`, `/api/auth/login`, `/api/auth/refresh` |
| `/api` (pengguna) | Global + Encryption + Auth | `/api/user/profile`, `/api/cart`, `/api/orders`, `/api/resources` |
| `/api` (sensitif) | Global + Encryption + Auth + Confirmation | `/api/orders/{id}/pay`, `/api/supplier/withdraw`, `/api/dns/{domain}/records/{id}` |
| `/admin/api` | Global + Encryption + Auth + AdminRole | `/admin/api/dashboard`, `/admin/api/users`, `/admin/api/products` |
| `/admin/api` (sensitif) | Global + Encryption + Auth + AdminRole + Confirmation | `/admin/api/products/{id}` (delete), `/admin/api/orders/{id}/refund`, `/admin/api/kyc/{id}/approve` |

### Format Respons Terpadu

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

### Skema Autentikasi

| Sisi | Metode |
|----|------|
| Pengguna | JWT (access_token 2j + refresh_token 30h) + verifikasi dua langkah TOTP + kode pemulihan |
| Admin | JWT (access_token 2j + refresh_token 7h) |
| API Supplier | API Key (prefix sk_, disimpan hash SHA256, hanya ditampilkan sekali saat dibuat) |
| Callback vendor cloud | Verifikasi tanda tangan (HMAC-SHA256) |

**Fitur autentikasi yang sudah diimplementasikan**:
- Registrasi email + tautan verifikasi email
- Registrasi nomor ponsel + kode verifikasi SMS Twilio (cooldown 60s + pembatasan IP 5 kali/jam)
- Login Google OAuth / Sign In dengan Apple
- Lupa kata sandi (kode verifikasi email + TTL Redis 10 menit)
- Verifikasi dua langkah TOTP (pengaturan pemindaian kode QR, fallback kode pemulihan)
- Manajemen sesi aktif (melihat/mencabut perangkat login, termasuk informasi client_platform)
- Penghapusan akun GDPR (konfirmasi kata sandi + soft delete + pencabutan semua token)
- Alarm login tidak normal (notifikasi email untuk login IP baru)
- Kunci login (5 kali gagal → kunci 15 menit)

**Alur autentikasi pengguna:**

```
Alur registrasi                       Alur login
────────                             ────────
1. POST /captcha/create              1. POST /captcha/create
   ← {key, image(posisi klik)}          ← {key, image}
2. POST /auth/register               2. POST /auth/login
   → {email, password, captcha}         → {login, password, captcha}
   → [Pemindaian WAF]                   → [Pemindaian WAF]
   → [Rate limit: 3 req/min]            → [Rate limit: 5 req/min]
   → [bcrypt kata sandi (cost=12)]      → [Hash::check()]
   → [Fingerprint perangkat: sha256(UA+IP)] → [Fingerprint perangkat: sha256(UA+IP)]
   → [Pencatatan client_platform]        → [Pencatatan client_platform]
   → User::create()                     → [Gagal 5 kali → kunci 15 menit]
   → RefreshToken::create()             → [Deteksi IP baru → alarm email]
     user_id, token_hash,               → RefreshToken::create()
     device_fingerprint,                   user_id, token_hash,
     client_platform,                      device_fingerprint,
     expires_at                            client_platform,
   → NotificationDispatcher::send()          expires_at
     (email verifikasi)                 → AuditLogger::record('user_login')
   → AuditLogger::record                ← {access_token, refresh_token}
     ('user_registered')
   ← {access_token, refresh_token}    OAuth (Google/Apple):
                                      ─────────────────────
                                      1. GET /auth/google
                                      2. Otorisasi Google → code
                                      3. GET /auth/google/callback?code=xxx
                                      4. Verifikasi token Google
                                      5. Buat atau cari pengguna
                                      6. Terbitkan token (termasuk client_platform)
                                      7. AuditLogger::record('user_oauth_login')

Verifikasi dua langkah TOTP           Manajemen sesi
────────────────                      ────────
1. POST /user/totp/setup               GET /user/sessions
   ← {secret, qr_code_url}                ← [{id, fingerprint, client_platform,
2. POST /user/totp/verify                      created_at, expires_at}]
   → {code: 123456}
   ← {recovery_codes: [...]}          DELETE /user/sessions/{id}
3. POST /auth/login                      → RefreshToken::update(revoked=true)
   → {login, password, totp_code}        ← sukses
   atau → /auth/login/recovery
   → {login, password, recovery_code}  DELETE /user/account
                                          → konfirmasi kata sandi + soft delete + pencabutan semua token
Mekanisme kunci login
────────────
Redis: login_failed:{sha1(login)} = count (TTL 900s)
       count >= 5 → login_lock:{userId} (TTL 900s)
```

### Skema Multi-Bahasa

- Header permintaan: Accept-Language: zh-CN / en-US / ja-JP
- Kolom JSON menyimpan teks multi-bahasa: name: {"zh-CN":"云服务器","en":"Cloud Server"}
- File i18n mengelola teks statis, satu set untuk frontend dan satu set untuk backend

---

## IV. Sistem Pertahanan Keamanan

### Model Pertahanan Berlapis

```
┌─────────────────────────────────────────────────────┐
│ Lapisan 1: Pertahanan batas jaringan                  │
│   Pembersihan DDoS / WAF / Daftar IP hitam-putih / Geo-Blocking │
├─────────────────────────────────────────────────────┤
│ Lapisan 2: Keamanan transport dan aplikasi            │
│   HTTPS+TLS1.3 / CSP / CORS / Autentikasi JWT / Rate limit │
├─────────────────────────────────────────────────────┤
│ Lapisan 3: Keamanan data dan penyimpanan              │
│   Penyimpanan terenkripsi / desensitisasi / log audit / backup │
├─────────────────────────────────────────────────────┤
│ Lapisan 4: Isolasi virtualisasi dan sumber daya       │
│   Penguatan keamanan Proxmox / isolasi antar-VM / isolasi jaringan │
├─────────────────────────────────────────────────────┤
│ Lapisan 5: Operasi dan kontrol risiko                 │
│   Audit operasi / deteksi anomali / alarm / respons darurat │
└─────────────────────────────────────────────────────┘
```

---

### 4.1 Pertahanan Batas Jaringan

#### Proteksi DDoS

```
Permintaan pengguna → CDN (Cloudflare / Alibaba Cloud CDN)
              │
              ├── Tantangan JS / captcha (lalu lintas mencurigakan)
              ├── Rate limiting (jumlah permintaan per IP per detik)
              ├── Blokir wilayah (blokir negara/kawasan tertentu)
              │
              ▼
           Origin server (Nginx + webman)
```

| Lapisan | Tindakan | Keterangan |
|------|------|------|
| Lapisan CDN | Pembersihan DDoS otomatis | Paket gratis Cloudflare sudah mendukung proteksi L3/L4 |
| Lapisan CDN | Bot Management | Mengenali dan memblokir bot berbahaya/script pembelian otomatis |
| Lapisan Nginx | limit_req_zone | 10 req/s per IP, melebihi batas mengembalikan 429 |
| Lapisan Nginx | limit_conn | Maksimal 20 koneksi konkuren per IP |
| Lapisan webman | Middleware rate limit token bucket | Rate limit presisi per pengguna/endpoint |

#### Aturan WAF (middleware webman)

Middleware WAF memindai permintaan melalui 8 kelompok aturan regex, konfigurasi aturan di `config/security.php` dapat diperbarui panas tanpa restart. Cakupan pemindaian meliputi body JSON permintaan, jalur URL + query string, User-Agent, body permintaan asli (mencegah bypass encoding JSON).

**8 kategori aturan deteksi (45+ aturan):**

| Kategori | Cakupan |
|------|---------|
| SQL Injection | Tanda kutip/komentar, kata kunci SQL, encoding heksadesimal, variasi union query, kondisi selalu benar (`' OR '1'='1`), blind injection waktu (`sleep`/`benchmark`), stacked query, bypass komentar multi-baris |
| XSS | Tag HTML (termasuk variasi encoding), tag Script dan variannya, 13 jenis handler event JS, objek global/fungsi berbahaya JS, protokol palsu `javascript:`, encoding entity HTML, injeksi Data URI, atribut event inline |
| Command Injection | Pipe diikuti perintah (`\| cat`), titik koma diikuti perintah (`; whoami`), substitusi perintah `$(cmd)` dan backtick, kata kunci perintah mandiri |
| File Inclusion | Path traversal (multi-encoding), protokol palsu PHP (`php://`/`data://`/`phar://`), probing path absolut (`/etc/`/`C:\`), injeksi Null byte |
| HTTP Header Injection | Injeksi baris baru CRLF (`%0d%0a`/`\r\n`), injeksi header Host/Cookie/Set-Cookie |
| **SSRF** | Alamat IPv4 internal (127.x/10.x/172.16-31.x/192.168.x), alias localhost, endpoint metadata cloud (169.254.169.254), protokol file:// |
| **NoSQL Injection** | Operator MongoDB ($where/$gt/$regex/$or dll.), injeksi JS $where, perintah berbahaya Redis (FLUSHALL/CONFIG SET/SHUTDOWN) |
| **Open Redirect** | Deteksi URL eksternal pada parameter redirect_uri/return_url/next/callback dll., bypass double encoding |

**Perlindungan tingkat permintaan:**

| Item perlindungan | Tindakan |
|--------|------|
| Batas ukuran body | Maksimal 10MB (lebih dari itu mengembalikan 413) |
| Batas panjang URL | Maksimal 2KB (lebih dari itu mengembalikan 414, mencegah ReDoS) |
| Whitelist Content-Type | Hanya mengizinkan application/json, multipart/form-data, application/x-www-form-urlencoded |

**Alur deteksi WAF:**

```
Permintaan masuk
  │
  ▼
1. Ambil teks yang akan dipindai
   ├── json_encode($request->all(), JSON_UNESCAPED_SLASHES)  # body permintaan
   │     └── false → fallback serialize()
   ├── mb_substr(path + queryString, 0, 2048)                # URL (pemotongan anti-ReDoS)
   ├── Header User-Agent                                      # UA
   └── file_get_contents('php://input')                      # body asli (mencegah bypass encoding JSON)
  │
  ▼
2. Muat aturan (dari config/security.php)
   ├── security.waf.sqli_patterns               (9 aturan)
   ├── security.waf.xss_patterns                (8 aturan)
   ├── security.waf.cmd_injection_patterns      (5 aturan)
   ├── security.waf.file_inclusion_patterns     (4 aturan)
   ├── security.waf.header_injection_patterns   (2 aturan)
   ├── security.waf.ssrf_patterns               (6 aturan)
   ├── security.waf.nosql_injection_patterns    (3 aturan)
   └── security.waf.open_redirect_patterns      (2 aturan)
   → array_merge() + array_unique()
  │
  ▼
3. Pencocokan satu per satu
   foreach patterns as pattern:
     match($pattern, $input) ───→ cocok → AuditLogger::threat('waf_blocked')
     match($pattern, $url)   ───→ cocok → kembalikan 403 "Request blocked by WAF"
     match($pattern, $ua)    ───→ cocok →
     match($pattern, $raw)   ───→ cocok →
  │
  ▼
4. Pemeriksaan ketat match()
   $result = @preg_match($pattern, $subject)
   ├── $result === 1    → cocok ✓
   ├── $result === 0    → tidak cocok (dilepas aman)
   └── $result === false → kesalahan pola → error_log() → diperlakukan sebagai tidak cocok
  │
  ▼
5. Semua tidak cocok → $next($request) lanjut ke middleware berikutnya
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

        // Muat 8 kategori aturan dari config/security.php
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

#### Daftar IP Hitam/Putih

```
Daftar hitam:
- Database IP jahat yang dikenal (sinkronisasi berkala AbuseIPDB)
- IP yang sering memicu aturan WAF (ditambahkan otomatis, TTL Redis 24j)
- IP brute-force login (5 kali gagal → kunci 30 menit)

Daftar putih:
- IP host server Proxmox
- Rentang IP callback vendor cloud
- Rentang IP webhook gateway pembayaran
- Jaringan kantor admin (opsional)
```

#### Geo-Blocking

```php
// Pustaka GeoIP2 (MaxMind)
$country = geoip($request->getRealIp());

// Daftar blokir yang dapat dikonfigurasi
$blockedCountries = config('security.geo_block', []);
if (in_array($country, $blockedCountries)) {
    return errorResponse(403, 'Access denied for your region');
}
```

---

### 4.2 Keamanan Transport dan Aplikasi

#### Rantai Eksekusi Middleware Global

Semua permintaan HTTP diproses melalui middleware sesuai urutan berikut, setiap middleware dapat diuji secara independen:

```
Permintaan → VersionMiddleware        # Validasi X-Api-Version (default v1 jika tidak ada, 400 jika tidak valid)
     → CorsMiddleware            # Header respons CORS lintas domain
     → ClientPlatformMiddleware  # Identifikasi X-Client-Platform (8 platform), injeksi ke $request->properties
     → WafMiddleware             # Pemindaian keamanan 8 kategori 45+ aturan (SQLi/XSS/command injection/file inclusion/header injection/SSRF/NoSQL/open redirect)
     → LocaleMiddleware          # Parsing Accept-Language, setel wilayah
     → HashidRequestMiddleware   # Dekode hashid parameter permintaan → ID asli
     → MaintenanceMiddleware     # Mode pemeliharaan (IP whitelist dilewatkan)
     ↓
  [Middleware route—ditambahkan per grup route]
     → EncryptionMiddleware      # Enkripsi body permintaan/respons AES-256-GCM
     → Captcha                   # Validasi captcha klik (sebelum login/registrasi)
     → AuthMiddleware            # Verifikasi JWT Bearer Token + injeksi peran
     → AdminRoleMiddleware       # Pemeriksaan izin RBAC admin
     → ConfirmationMiddleware    # Konfirmasi ulang kata sandi untuk operasi sensitif (5 kali gagal kunci 15 menit)
     ↓
     Controller
```

#### Tanggung Jawab Setiap Middleware

| Middleware | Cara registrasi | Tanggung jawab |
|--------|---------|------|
| `VersionMiddleware` | Global | Validasi header `X-Api-Version`, default `v1` jika tidak ada, versi tidak didukung mengembalikan `400` |
| `CorsMiddleware` | Global | Menangani preflight OPTIONS, memantulkan Origin ke `Access-Control-Allow-Origin` |
| `ClientPlatformMiddleware` | Global | Validasi header `X-Client-Platform`, mengenali platform OS klien (iPadOS/macOS/Windows/Linux/iOS/Android/HarmonyOS/Web), injeksi ke `$request->properties['client_platform']` |
| `WafMiddleware` | Global (service) + instance admin | 8 kategori 45+ aturan + batas ukuran permintaan + validasi Content-Type, mencatat log audit setelah diblokir |
| `LocaleMiddleware` | Global | Parsing header `Accept-Language`, setel wilayah multi-bahasa |
| `HashidRequestMiddleware` | Global | Mendekode string hashid di permintaan menjadi ID integer asli secara otomatis |
| `MaintenanceMiddleware` | Global | Memeriksa variabel lingkungan `MAINTENANCE_MODE`, IP whitelist dilewatkan |
| `EncryptionMiddleware` | Grup route (/api/auth, /api, /admin/api) | Enkripsi body permintaan/respons AES-256-GCM, dipicu header `X-Encrypted: 1` |
| `AuthMiddleware` | Grup route (/api, /admin/api) | Verifikasi JWT HS256 access_token, injeksi `$request->userId` dan `$request->userRole` |
| `AdminRoleMiddleware` | Grup route (/admin/api) | Pemeriksaan izin RBAC admin |
| `ConfirmationMiddleware` | Grup route (operasi sensitif) | Konfirmasi ulang kata sandi, penghitung kegagalan Redis, 5 kali kunci 15 menit |

#### Detail Middleware ClientPlatform

```php
class ClientPlatformMiddleware
{
    protected const SUPPORTED = [
        'ipados', 'macos', 'windows', 'linux',
        'ios', 'android', 'harmonyos', 'web',
    ];

    public function process($request, callable $next)
    {
        // Hanya berlaku untuk route API
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

        // Injeksi properti permintaan untuk penggunaan downstream (log audit, catatan sesi)
        $request->properties['client_platform'] = $platform;

        $response = $next($request);
        $response->header('X-Client-Platform', $platform);
        return $response;
    }
}
```

**Alur data**: injeksi middleware → `AuditLogger` mencatat otomatis → `AuthService::issueTokens()` menulis ke `refresh_tokens` → `GET /api/user/sessions` mengembalikan informasi platform

#### Penerapan HTTPS Wajib

```nginx
# Konfigurasi Nginx
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

#### Penguatan Keamanan JWT

```
- Masa berlaku access_token 2j, refresh_token 30h
- Kunci menggunakan RSA256 (asimetris), rotasi berkala (90 hari)
- jti (JWT ID) disimpan di Redis untuk pencabutan aktif
- refresh_token terikat fingerprint perangkat (User-Agent + rentang IP)
- Saat menerbitkan ulang refresh_token, token lama langsung tidak valid (rotation)
- Operasi sensitif (pembayaran/penghancuran sumber daya) memerlukan verifikasi kedua

Fingerprint perangkat:
  device_fingerprint = hash(user_agent + ip_cidr_24 + client_type)
  Tabel refresh_token mencatat fingerprint ini, diverifikasi saat penerbitan ulang
```

#### Kebijakan Kata Sandi

```
- Enkripsi bcrypt, cost factor = 12
- Minimal 8 karakter, harus mengandung huruf besar + huruf kecil + angka
- Gagal registrasi/login berturut-turut 5 kali → kunci akun 15 menit
- Setelah perubahan kata sandi, semua token yang sudah diterbitkan langsung tidak valid
- Mendukung verifikasi dua langkah TOTP (opsional diaktifkan pengguna)
```

#### Kebijakan CORS

```php
// Middleware webman
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

#### Keamanan Upload File

```
- Validasi whitelist ekstensi (hanya izinkan: jpg, jpeg, png, pdf, gif)
- Validasi tipe MIME file (tidak mengizinkan Content-Type palsu)
- Batas ukuran file: avatar 2MB, dokumen KYC 5MB, lampiran 10MB
- Rename setelah upload: {uuid}.{ext}, tidak menyimpan nama file asli
- Pemrosesan ulang gambar: GD/Imagick menghapus EXIF + metadata
- Path penyimpanan di direktori yang tidak dapat diakses web, dibaca melalui proxy PHP
- Pemindaian virus: ClamAV (dokumen KYC/file yang diunggah pengguna)
```

---

### 4.3 Keamanan Data dan Penyimpanan

#### Enkripsi Data Sensitif

```
Algoritma enkripsi: AES-256-GCM (enkripsi terautentikasi, anti-perubahan)
Manajemen kunci: kunci utama disimpan di variabel lingkungan, setiap field menggunakan kunci turunan independen

Field yang perlu disimpan terenkripsi:
| Tipe data | Field | Cara enkripsi |
|----------|------|----------|
| Kata sandi | users.password_hash | bcrypt (satu arah) |
| Kunci pembayaran | payment_channels.api_key | AES-256-GCM |
| Kunci vendor cloud | provider_apis.api_key_encrypted, api_secret_encrypted | AES-256-GCM |
| Token Proxmox | host_machines.api_token_encrypted | AES-256-GCM |
| Nomor dokumen KYC | user_kyc.id_number | AES-256-GCM |
| Akun pembayaran | akun penarikan | AES-256-GCM |
| Kata sandi login (VNC) | resource_servers.login_password | AES-256-GCM |

Derivasi kunci:
  derived_key = HKDF-SHA256(master_key, salt: table_name + '.' + field_name)
```

#### Desensitisasi Log

```php
class LogSanitizer
{
    // Pola nama field yang otomatis didesensitisasi
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

// Monolog Processor dipanggil otomatis sebelum menulis log
```

#### Keamanan Database

```
- MySQL menggunakan prepared statement (ditangani otomatis oleh Eloquent)
- Prinsip hak akses minimum untuk akun database:
  - app_user: SELECT, INSERT, UPDATE, DELETE (tanpa DDL)
  - migration_user: hak DDL (hanya digunakan saat migrasi, dibatasi IP)
  - read_user: SELECT read-only (untuk laporan/analisis data)
- Koneksi menggunakan SSL/TLS (opsi SSL PDO PHP)
- Port database tidak dibuka ke internet (hanya dapat diakses intranet)
- Backup berkala: backup penuh 1 hari, sinkronisasi binlog real-time
```

#### Backup dan Pemulihan Data

```
Strategi backup:
- MySQL: backup penuh harian + incremental binlog real-time
- Redis: RDB setiap jam + persistensi AOF real-time
- File yang diunggah pengguna: S3/OSS multi-replika otomatis + replikasi lintas wilayah
- Snapshot VM Proxmox: seminggu sekali (disimpan 4 minggu)
- Enkripsi backup: disimpan setelah enkripsi AES-256

Latihan pemulihan:
- Latihan pemulihan bencana dijalankan setiap kuartal
- Target waktu pemulihan (RTO): < 4 jam
- Target titik pemulihan (RPO): < 1 jam
```

---

### 4.4 Isolasi Virtualisasi dan Sumber Daya

#### Penguatan Keamanan Proxmox

```
1. Kontrol akses API:
   - API Proxmox hanya mendengarkan IP intranet (tidak terikat internet)
   - Minimalisasi izin token: setiap role hanya diberikan izin yang diperlukan
   - Port API (8006) hanya mengizinkan akses IP server aplikasi PHP (iptables)

2. Penguatan SSH:
   - Nonaktifkan login kata sandi, hanya izinkan autentikasi kunci
   - Nonaktifkan login root, gunakan akun administrasi khusus
   - Ubah port SSH ke port non-standar (mengurangi pemindaian)
   - Fail2ban: 5 kali gagal kunci 1 jam

3. Pembaruan sistem:
   - Berlangganan milis pembaruan keamanan Proxmox
   - apt update && apt upgrade berkala
   - Kernel livepatch (Canonical Livepatch Service)

4. Firewall (iptables/nftables):
   - Tolak semua inbound secara default
   - Hanya buka: 8006 (hanya IP server aplikasi), port SSH (hanya IP manajemen)
   - Isolasi jembatan VM dengan jaringan manajemen host
```

#### Isolasi Antar-VM

```
- Setiap VM menggunakan VLAN jembatan virtual independen
- Larang komunikasi antar-VM (aturan firewall Proxmox + isolasi VLAN)
- Pengguna hanya dapat mengakses VM miliknya melalui IP publik
- Batas sumber daya VM (cgroup): mencegah satu VM menghabiskan sumber daya host
  - Batas CPU: sesuai jumlah core yang dibeli
  - Batas RAM: sesuai kapasitas yang dibeli
  - Batas Disk IOPS: mencegah persaingan disk
  - Batas bandwidth jaringan: sesuai bandwidth yang dibeli
```

#### Keamanan Alokasi IP

```
- Catatan alokasi IP diaudit lengkap (siapa, kapan, IP apa yang dialokasikan)
- Periode pendinginan 24 jam setelah IP dilepas (mencegah penyalahgunaan IP yang langsung dialokasikan ke orang lain)
- Daftar hitam IP: IP yang dikomplain/disalahgunakan ditandai tidak dapat dialokasikan
- Monitoring penggunaan IP: periksa berkala apakah IP yang dialokasikan digunakan normal
```

---

### 4.5 Keamanan Pembayaran

```
1. Kepatuhan PCI DSS:
   - Data kartu kredit tidak melewati server sendiri (Stripe Elements / Checkout)
   - card_token dibuat langsung oleh frontend Stripe, backend hanya menerima token
   - Tidak menyimpan CVV/nomor kartu lengkap di log/database

2. Kripto:
   - Kunci privat penerimaan disimpan dingin (signing offline)
   - Dompet panas hanya menyimpan kuota operasional harian
   - Verifikasi checksum setelah alamat penerimaan dibuat
   - Transaksi besar ( > $10000) ditinjau manual dan dikonfirmasi manual

3. Anti-penipuan pembayaran:
   - Pembayaran frekuensi tinggi dalam waktu singkat oleh pengguna/IP yang sama → pembekuan kontrol risiko
   - Pembayaran besar oleh pengguna baru → tinjauan manual
   - Jumlah pembayaran tidak normal (tidak cocok dengan harga produk) → blokir
   - Pengguna dengan rasio refund terlalu tinggi → tandai kontrol risiko

4. Verifikasi tanda tangan callback:
   - Stripe: verifikasi tanda tangan webhook (header stripe-signature)
   - Coinbase: verifikasi tanda tangan webhook (header X-CC-Webhook-Signature)
   - Alipay: verifikasi notify_id, konfirmasi kedua ke server Alipay
   - Semua callback: verifikasi IP termasuk dalam rentang IP gateway pembayaran yang dikenal
```

#### Keamanan Refund

```
- Refund harus melalui persetujuan dua tingkat (CS membuat → admin mengonfirmasi)
- Validasi sebelum refund: status pesanan, batas waktu refund, jumlah refund
- Jumlah refund tidak boleh melebihi jumlah yang dibayarkan pesanan asli
- Pengembalian ke sumber asal: antarmuka refund saluran pembayaran + pengembalian saldo
- Kunci mutex refund (Redis): mencegah refund ganda konkuren
```

---

### 4.6 Kontrol Akses dan Izin

#### Model RBAC

```
Hierarki peran:
  super_admin    (Super admin — semua izin)
  admin          (Admin — semua kecuali konfigurasi sistem)
  finance        (Keuangan — pembayaran/rekonsiliasi/refund/settlement)
  support        (CS — manajemen pengguna/pesanan/tiket)
  supplier       (Supplier — produk/pesanan/settlement miliknya sendiri)
  user           (Pengguna biasa — sumber daya/pesanan/tiket miliknya sendiri)

Definisi izin:
  {modul}.{aksi}
  Contoh: order.view, order.create, order.refund, resource.destroy

Middleware pemeriksaan izin:
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

#### Rate Limit API

```php
// Middleware rate limit webman (token bucket Redis)
class RateLimitMiddleware
{
    // Default: 60 req/menit per pengguna
    private array $limits = [
        'default'     => ['rate' => 60,   'burst' => 10, 'per' => 60],
        'login'       => ['rate' => 5,    'burst' => 2,  'per' => 60],  // anti brute-force
        'register'    => ['rate' => 3,    'burst' => 0,  'per' => 60],  // anti registrasi massal
        'pay'         => ['rate' => 10,   'burst' => 3,  'per' => 60],  // pembatasan pembayaran
        'api'         => ['rate' => 120,  'burst' => 20, 'per' => 60],  // pemanggilan API
        'upload'      => ['rate' => 10,   'burst' => 2,  'per' => 60],  // pembatasan upload
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

#### Isolasi Data Supplier

```
Prinsip isolasi data:
- Supplier hanya dapat menanyakan dan mengoperasikan sumber daya miliknya
- Semua query yang melibatkan supplier_id otomatis ditambahkan WHERE supplier_id = auth()->supplier_id

Cara implementasi:
  // Scope Global
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
  
  // Didaftarkan pada Model Product/Order dll.
  protected static function booted()
  {
      static::addGlobalScope(new SupplierScope);
  }
```

---

### 4.7 Audit Operasional

```
Isi catatan log audit:
- ID operator, IP, User-Agent
- Waktu operasi
- Modul operasi (menu/endpoint mana)
- Tipe operasi: buat/ubah/hapus/ekspor/approval
- Objek operasi: field mana dari sumber daya mana
- Nilai sebelum operasi / nilai setelah operasi (perubahan tingkat field)
- Hasil operasi: sukses/gagal
- ID permintaan (pelacakan lintas rantai)

Cakupan pencatatan:
- Semua operasi panel admin (100% dicatat)
- Operasi sensitif sisi pengguna: pembayaran/penghancuran sumber daya/pengajuan KYC/perubahan kata sandi (100% dicatat)
- Login/logout (100% dicatat)
- Pembuatan/pencabutan API Key (100% dicatat)

Penyimpanan dan retensi:
- Log audit ditulis ke database independen (audit_db), terpisah dari database aplikasi
- Disimpan minimal 1 tahun, terkait keuangan disimpan 3 tahun
- Mendukung ekspor CSV/JSON untuk tinjauan kepatuhan

Middleware log audit:
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

### 4.8 Aturan Kontrol Risiko

```
Mesin kontrol risiko real-time:

Aturan 1: Perilaku tidak normal akun baru
  Kondisi: waktu registrasi < 24j DAN (total pembayaran > $500 ATAU tiket dibuat > 5)
  Aksi: tandai akun sebagai "dalam pengamatan", notifikasi admin kontrol risiko

Aturan 2: Deteksi registrasi massal
  Kondisi: IP yang sama meregistrasi > 3 akun dalam 24j
  Aksi: tolak registrasi baru, bekukan akun baru di bawah IP tersebut

Aturan 3: Anomali pembayaran
  Kondisi: pengguna yang sama gagal membayar > 5 kali dalam 1 jam
  Aksi: bekukan fungsi pembayaran 2 jam, buat tiket kontrol risiko

Aturan 4: Penyalahgunaan refund
  Kondisi: pengguna yang sama melakukan refund > 3 transaksi dalam 30 hari ATAU rasio refund > 20%
  Aksi: batasi izin refund akun tersebut, pesanan baru ditandai tinjauan kontrol risiko

Aturan 5: Penyalahgunaan API
  Kondisi: satu token memanggil API > 10000 kali dalam 1 jam
  Aksi: turunkan token tersebut (kurangi ambang rate limit), notifikasi admin

Aturan 6: Penyalahgunaan sumber daya
  Kondisi: VM dikomplain spam/DDoS/mining (menerima notifikasi Abuse)
  Aksi: matikan otomatis, bekukan sumber daya, buat tiket prioritas tinggi

Aksi kontrol risiko:
- Tandai (flag): hanya mencatat, tidak memengaruhi penggunaan
- Turunkan (throttle): menurunkan ambang rate limit
- Bekukan (freeze): menonaktifkan sementara fungsi tertentu
- Blokir (ban): pemblokiran permanen akun
```

---

### 4.9 Respons Darurat

```
Klasifikasi insiden keamanan:

P0 (Darurat) — Kebocoran data, kerugian dana, downtime platform
  → Notifikasi segera CTO + tim keamanan
  → Mulai respons darurat dalam 30 menit
  → Turunkan layanan upstream yang terdampak, simpan bukti
  → Terbitkan laporan insiden dalam 24 jam setelah perbaikan

P1 (Serius) — Pencurian akun tunggal, penipuan pembayaran, lonjakan abnormal pemicu WAF
  → Notifikasi penanggung jawab keamanan
  → Tangani dalam 2 jam
  → Bekukan akun/sumber daya yang terdampak

P2 (Umum) — Pemindaian kerentanan menemukan kerentanan sedang/rendah, alarm login tidak normal
  → Masukkan ke sistem tiket
  → Perbaiki pada iterasi berikutnya

Kontak darurat:
- Notifikasi otomatis setelah alarm P0/P1 (email + SMS + telepon)
- Endpoint health check webman: GET /health (mengembalikan 200 atau alarm)
- Jadwal jaga: rotasi 7×24, minimal 2 orang siaga
```

---

## V. Mesin Pengaktifan Sumber Daya

### Arsitektur Plugin Provider

Setiap kombinasi tipe produk cloud × vendor cloud mengimplementasikan antarmuka terpadu:

```php
interface ResourceProvider
{
    public function create(ProvisionTask $task): ProvisionResult;
    public function renew(Resource $resource, int $months): ProvisionResult;
    public function upgrade(Resource $resource, array $newSpecs): ProvisionResult;
    public function destroy(Resource $resource): ProvisionResult;
    public function status(Resource $resource): ResourceStatus;
    public function consoleUrl(Resource $resource): string;
    // Khusus server fisik milik sendiri
    public function resizeDisk(Resource $resource, int $newSizeGb): ProvisionResult;
    public function createDisk(ProvisionTask $task): ProvisionResult;
    public function createIp(ProvisionTask $task): ProvisionResult;
}
```

ProviderFactory merutekan ke implementasi spesifik berdasarkan (product_type, provider):
- ProxmoxProvider (server fisik milik sendiri: server/disk data/IP)
- AwsServerProvider / AliyunServerProvider (server cloud pihak ketiga)
- GcpIpProvider (IP pihak ketiga)
- AzureDiskProvider (cloud disk pihak ketiga)
- NamecheapDomainProvider / GoDaddyDomainProvider (domain)

### Jaminan Tugas Asinkron

- Provisioning Worker melakukan polling tabel provision_tasks
- Kontrol konkurensi dikelompokkan per provider (maksimal 5 konkuren per provider)
- Strategi retry: 1 menit → 5 menit → 15 menit → 1 jam → 6 jam → 24 jam (maksimal 6 kali)
- Gagal yang tidak dapat di-retry → alarm + pembuatan tiket otomatis

### Rantai Lengkap Pesanan ke Pengaktifan Sumber Daya

```
Pengguna membuat pesanan                 Pembayaran                      Pengaktifan sumber daya
────────                               ────                             ────────
1. POST /cart                          5. POST /orders/{id}/pay         9. Event OrderPaid
   → addToCart(sku, region, qty)          → konfirmasi ulang kata sandi      → ProvisioningService
                                             (Confirmation)                     .handleOrderPaid()
2. POST /orders                           → PaymentRouter::route()
   → createOrder()                           memilih saluran pembayaran   10. Setiap OrderItem:
   ← {order, order_items}                                                      → ProvisionTask::create()
                                        6. StripeChannel::                      status=pending
3. Terapkan kupon                          createPaymentIntent()
   POST /coupons/validate                   → Stripe API                 11. Redis Queue Worker
   → validate('CODE', order_total)          ← {client_secret}                → ProviderFactory
   ← {discount, coupon_id}                                                     .create(task)
                                        7. Frontend confirmCardPayment()
4. GET /orders/{id}/payment-methods     8. Callback webhook Stripe        12. Provider->create()
   → ambil saluran yang tersedia            → verifikasi tanda tangan +       ├→ HostSelector::select()
   ← [{channel, fee, total}]                  pemeriksaan idempotensi          ├→ ProxmoxApi::create()
                                             → transaction=success              │  createVM(CPU,RAM,Disk)
                                             → memicu event OrderPaid           │  allocateIP()
                                                                                │  startVM()
                                        Strategi retry (saat gagal)              ├→ Buat catatan Resource
                                        ────────────────                         └→ Perbarui host_machine
                                        1min → 5min → 15min                         jumlah sumber daya teralokasi
                                        → 1j → 6j → 24j
                                        (setelah 6 kali tandai gagal + alarm) 13. Order::status = completed
                                                                               → NotificationDispatcher
                                        Alur refund                                ::send('resource_ready')
                                        ────────
                                        pengguna mengajukan → tinjauan CS → konfirmasi admin
                                        → provider.destroy()
                                        → payment.refund()
                                        → pengembalian ke sumber asal
```

### Solusi Server Fisik Milik Sendiri: Proxmox VE (Edisi Komunitas)

Server milik sendiri menggunakan Proxmox VE (open source gratis, AGPL v3), PHP memanggil REST API Proxmox melalui HTTP untuk mengelola siklus hidup VM KVM dan alokasi sumber daya.

Arsitektur:
```
PHP (webman) ──HTTPS──> API Proxmox VE (port 8006)
                               │
                               └──> KVM/QEMU ──> VM (dialokasikan ke pengguna)
```

#### Pembungkusan Klien ProxmoxApi

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

#### Operasi Sumber Daya

**Buat VM (server):**
1. HostSelector memilih host server dengan sumber daya cukup (diurutkan berdasarkan sisa cpu/ram/disk + load balancing)
2. Alokasikan satu IP dari ip_pool host tersebut
3. ProxmoxApi.post("/nodes/{node}/qemu") membuat VM (mengatur vmid, name, cores, memory, net0, ipconfig0)
4. ProxmoxApi.post("/nodes/{node}/qemu/{vmid}/config") memasang disk sistem (scsi0: storagePool:sizeG)
5. ProxmoxApi.post("/nodes/{node}/qemu/{vmid}/status/start") menyalakan VM
6. Perbarui jumlah teralokasi host_machine.specs (cpu_allocated / ram_allocated_gb / disk_allocated_gb)

**Upgrade CPU (online):**
```php
$api->put("/nodes/{node}/qemu/{vmid}/config", ['cores' => $newCpu]);
$host->recalculate(); // Perbarui statistik sumber daya host
```

**Upgrade memori (online):**
```php
$api->put("/nodes/{node}/qemu/{vmid}/config", ['memory' => $newRamGb * 1024]);
$host->recalculate();
```

**Perluasan disk sistem:**
```php
$api->put("/nodes/{node}/qemu/{vmid}/resize", ['disk' => 'scsi0', 'size' => "{$newSizeGb}G"]);
```

**Membuat disk data secara terpisah:**
```php
$diskSlot = nextDiskSlot($vmid); // scsi1, scsi2...
$api->post("/nodes/{node}/qemu/{vmid}/config", [$diskSlot => "{$pool}:{$sizeGb}G"]);
```

**Membuat IP secara terpisah:**
Alokasi dari kumpulan IP → tambahkan NIC virtual + konfigurasi IP melalui API Proxmox, atau simpan sebagai sumber daya independen yang dialokasikan ke NIC tambahan VM yang ada.

**Hancurkan VM:**
```php
$api->post("/nodes/{node}/qemu/{vmid}/status/stop");  // matikan
$api->delete("/nodes/{node}/qemu/{vmid}");             // hapus VM
releaseIp($resourceId);                                // lepaskan IP kembali ke kumpulan
$host->deallocate($specs);                             // pulihkan sumber daya host
```

#### Strategi Pemilihan Host Server

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

#### Ringkasan Operasi Pemisahan Sumber Daya

| Operasi | Cara implementasi | Hot operation |
|------|----------|--------|
| Buat VM (CPU+memori+disk sistem+IP) | Proxmox create qemu | — |
| Upgrade CPU terpisah | PUT config cores | Online |
| Upgrade memori terpisah | PUT config memory | Online |
| Perluasan disk sistem | PUT resize disk | Online (perlu dukungan VM) |
| Buat disk data terpisah | POST config tambahkan disk | Online |
| Buat IP terpisah | Alokasi dari kumpulan IP + tambahkan NIC ke VM | Online |

### Siklus Hidup Sumber Daya

```
pending → active → destroyed (disimpan 30 hari) → purged (tidak dapat dipulihkan)
```

Perpanjangan: active → (renew) → active (perpanjang expired_at)
Upgrade: active → (upgrade) → upgrading → active

### Sumber Sumber Daya

| Sumber | Virtualisasi/API | Tipe produk | Keterangan |
|------|-----------|----------|------|
| Server fisik milik sendiri | Proxmox VE (edisi komunitas) | Server, disk data, IP | Di-hosting di pusat data sendiri, PHP memanggil API Proxmox |
| Vendor cloud pihak ketiga | AWS/GCP/Alibaba Cloud/Huawei Cloud/Azure SDK | Server, IP, cloud disk | Penjualan kembali sumber daya cloud pihak ketiga |
| Registrar domain | API Namecheap/GoDaddy/Alibaba Cloud Wanwang | Registrasi/transfer domain | Layanan domain |

### Integrasi Fase Pertama

| Wilayah | Server | IP | Cloud disk | Domain |
|------|--------|----|------|------|
| Asia Pasifik | Alibaba Cloud, Huawei Cloud, AWS | Alibaba Cloud, GCP | Alibaba Cloud, Huawei Cloud | Alibaba Cloud Wanwang, Namecheap |
| Eropa | AWS, GCP, Hetzner | GCP, OVH | AWS, GCP | Namecheap, Gandi |
| Amerika Utara | AWS, GCP, Azure | AWS, GCP | AWS, Azure | GoDaddy, Namecheap |

---

## VI. Sistem Pembayaran

### Perutean Multi-Saluran

PaymentRouter menanyakan saluran yang tersedia berdasarkan preferensi mata uang pengguna, menghitung jumlah yang harus dibayar setiap saluran (termasuk biaya saluran), dan mengembalikan daftar opsi pembayaran.

### Alur Pembayaran (Stripe)

```
Frontend (Flutter)               Server (webman)                Stripe API
───────────────               ──────────────                ──────────
1. Pilih pembayaran Stripe
    → POST /orders/{id}/pay ──→ 2. StripeChannel
    ← client_secret               createPaymentIntent() ──→ 3. paymentIntents.create
                                                              ← pi_xxx, client_secret
                               4. Buat payment_transaction
                                  (status=pending)
                                  ← client_secret
5. confirmCardPayment()
    → Stripe SDK ──────────────────────────────────────────→ 6. Pengguna mengonfirmasi pembayaran
                                                              ← payment_intent.succeeded
                               7. POST /payments/webhook/stripe ←
                                  Webhook::constructEvent()
                                  verifikasi tanda tangan (stripe-signature)
                                  pemeriksaan idempotensi (transaction_no)
                               8. Perbarui transaction=success
                               9. Memicu event OrderPaid
                                  ├→ AuditLogger::record()
                                  ├→ NotificationDispatcher::send()
                                  └→ ProvisioningService::handleOrderPaid()

      ← halaman sukses pembayaran               ← mengembalikan status pesanan
```

### Pembayaran Kripto

1. Pengguna memilih mata uang (mis. USDT-TRC20)
2. Backend membuat alamat penerimaan melalui API Coinbase Commerce / BitPay
3. Worker mengecek konfirmasi blockchain setiap 30 detik (atau webhook)
4. Konfirmasi dana masuk → memicu event OrderPaid

### Nilai Tukar dan Multi-Mata Uang

- Sumber nilai tukar ditarik berkala dari exchangerate-api dan disimpan di Redis
- Harga produk berbasis USD, mata uang lain dikonversi real-time
- Kunci nilai tukar saat membuat pesanan, refund dikembalikan sesuai nilai tukar asli

### Kontrol Visibilitas Saluran Pembayaran

Kolom tabel payment_channels:
- is_visible: apakah ditampilkan ke frontend
- visible_regions: batasi wilayah yang terlihat, kosong berarti semua
- min_amount / max_amount: batasan rentang jumlah pesanan

### Rekonsiliasi

Setiap dini hari menarik laporan settlement setiap saluran, mencocokkan transaksi dengan sistem transaksi satu per satu, selisih > $0.01 memicu alarm.

### Kebijakan Refund

- Server/VPS: refund penuh dalam 72 jam setelah pembelian
- Domain: dapat direfund dalam 5 hari setelah registrasi (aturan ICANN)
- IP: tidak dapat direfund setelah pembelian
- Cloud disk: mengikuti kebijakan server
- Produk promosi khusus: tidak dapat direfund

Alur refund: pengguna mengajukan → tiket dibuat → tinjauan CS → konfirmasi admin → provider.destroy() → payment.refund() → pengembalian ke sumber asal

---

## VII. Struktur Halaman Klien

### Flutter / HarmonyOS Sisi Pengguna

- **Autentikasi**: login/registrasi (email+kata sandi, Google OAuth, Apple ID, nomor ponsel), lupa kata sandi, verifikasi dua langkah
- **Beranda**: pemilih wilayah, entri kategori produk, Banner/promosi, produk rekomendasi
- **Produk**: daftar (filter multi-kondisi), detail (konfigurasi/wilayah/kalkulator harga), ulasan
- **Belanja & pembayaran**: keranjang belanja, konfirmasi pesanan (metode pembayaran/alamat tagihan/saldo/kode promo), kasir, hasil pembayaran
- **Sumber daya saya**: daftar sumber daya (filter status), operasi detail (restart/matikan/perpanjang/upgrade/hancurkan), SSO konsol, grafik pemakaian
- **Pesanan**: daftar (belum dibayar/dibayar/selesai/refund), detail, faktur
- **Tiket**: daftar, buat baru, percakapan
- **Pusat pribadi**: profil/KYC, saldo & top-up, notifikasi, manajemen alamat, pengaturan bahasa/mata uang/keamanan
- **Umum**: pusat bantuan, syarat layanan, tentang

### Panel Admin webman-admin

- **Dashboard**: ikhtisar + grafik tren
- **Manajemen pengguna**: daftar/detail/tinjauan KYC
- **Manajemen produk**: kategori/daftar/penetapan harga (SKU×wilayah)/stok/ulasan
- **Manajemen pesanan**: daftar/detail/tinjauan refund/faktur
- **Manajemen pembayaran**: konfigurasi saluran/catatan transaksi/laporan rekonsiliasi
- **Manajemen sumber daya**: daftar/monitoring tugas pengaktifan/konfigurasi API vendor cloud
- **Manajemen supplier**: tinjauan pendaftaran/daftar/alokasi produk/settlement/penarikan
- **Manajemen tiket**: antrean/tiket saya/monitoring SLA
- **Manajemen domain**: penetapan harga TLD/API registrar/manajemen transfer
- **Notifikasi pesan**: manajemen template/catatan pengiriman
- **Pengaturan sistem**: admin & peran/log operasi/multi-bahasa/nilai tukar/wilayah/parameter sistem
- **Laporan**: pendapatan/settlement supplier/analisis penjualan produk/analisis wilayah

---

## VIII. Sistem Notifikasi Pesan

### Empat Saluran

Email (SMTP/SendGrid) / SMS (Twilio/Alibaba SMS) / Push (FCM/HMS) / Pesan situs

### Alur

Pemicu event → Notification Dispatcher → pencocokan template (kode event + preferensi bahasa) → distribusi ke setiap saluran sesuai preferensi pengguna → pengiriman asinkron Redis Queue

### Tipe Notifikasi

Kode verifikasi registrasi, keberhasilan pembayaran pesanan, pengaktifan sumber daya selesai, pengingat kedaluwarsa sumber daya (7h/3h/1h), balasan tiket, refund selesai, alarm keamanan, kegiatan promosi

### Retry Gagal

3 kali backoff, dikelola melalui webman redis-queue.

---

## IX. Sistem Supplier

### Alur Pendaftaran

Registrasi → kirim informasi perusahaan + kontak + metode settlement → tinjauan admin → setelah disetujui naikkan produk → admin meninjau produk → pengguna membeli → bagi hasil otomatis → supplier mengajukan penarikan → admin membayar

### Isolasi Izin

Supplier hanya dapat melihat produk/pesanan/formulir settlement/tiket/catatan penarikan miliknya sendiri. Tidak dapat melihat pendapatan platform, data supplier lain, konfigurasi saluran pembayaran.

### Aturan Bagi Hasil

- Produk milik sendiri: commission_rate = 100% (semua ke platform)
- Produk pihak ketiga: commission_rate = 5%~20% (potongan platform)
- Rumus settlement: jumlah produk pesanan - potongan platform - biaya saluran = tagihan supplier
- Periode settlement: mingguan / bulanan

### Alur Bisnis Lengkap Supplier

```
Pendaftaran supplier                     Persetujuan admin
──────────                             ──────────
POST /supplier/apply                   GET /admin/api/suppliers?status=pending
  → {company_name, contact_name,         → tinjau informasi supplier
     contact_phone, contact_email,       POST /admin/api/suppliers/{id}/approve
     settlement_method}                    → konfirmasi kata sandi
  → SupplierService::apply()               → SupplierService::approve()
  ← {supplier, status:pending}               → User::role = 'supplier'
                                              ← sukses
Penayangan produk
────────
POST /supplier/products                Tinjauan admin
  → {product_id, commission_rate}        → kaitkan produk supplier + atur rasio komisi
  ← {supplier_product}                    → status produk: published

Pengguna membuat pesanan ──→ pembayaran selesai ──→ pengaktifan sumber daya ──→ pesanan selesai

Settlement terjadwal (setiap Senin 04:17)         Penarikan
───────────────────────                 ──────
Cron: SupplierSettlement               POST /supplier/withdraw
  → hitung pesanan selesai pada periode    → konfirmasi ulang kata sandi (ConfirmationMiddleware)
  → hitung total_sales - commission        → SupplierService::requestWithdraw()
  → = payable                              → periksa saldo yang dapat ditarik
  → buat SupplierSettlement                 → buat SupplierWithdraw (status:pending)
  → Webhook: settlement.created          ← sukses

Admin membayar                          Manajemen API Key admin
───────────                             ──────────────────
POST /admin/api/suppliers/              POST /admin/api/suppliers/{id}/api-keys
  withdraws/{id}/approve                  → buat sk_xxx (disimpan SHA256)
  → konfirmasi kata sandi                 ← {api_key} (hanya ditampilkan sekali)
  → SupplierWithdraw: status=completed  DELETE /admin/api/suppliers/api-keys/{id}
  → Webhook: withdrawal.approved           → revoked=true
```

---

## X. Monitoring dan Operasi

### Monitoring Sumber Daya

- Metrik yang dikumpulkan: penggunaan CPU/memori/disk/bandwidth, konektivitas IP, IOPS cloud disk, resolusi DNS, kedaluwarsa sertifikat SSL
- Cara pengumpulan: pelaporan Agent / SNMP (milik sendiri) + API monitoring vendor cloud (pihak ketiga) + polling WHOIS/DNS (domain)
- Periode pengumpulan: 5 menit, disimpan di Prometheus + VictoriaMetrics

### Aturan Alarm

| Event alarm | Severity | Kondisi pemicu |
|----------|--------|----------|
| Server down | Serius | 3 kali Ping tidak dapat dijangkau berturut-turut |
| CPU/memori > 90% | Info | Berlangsung 10 menit |
| Disk > 90% | Peringatan | Berlangsung 5 menit |
| Bandwidth > 80% | Info | Berlangsung 30 menit |
| Sertifikat SSL < 30 hari kedaluwarsa | Peringatan | Pemeriksaan harian |
| Domain < 30 hari kedaluwarsa | Peringatan | Pemeriksaan harian |
| Gagal tugas pengaktifan | Serius | Gagal 2 kali berturut-turut |
| Selisih rekonsiliasi pembayaran | Serius | Per transaksi > $0.01 |

---

## XI. Arsitektur Deployment

### Lingkungan Produksi

- Server aplikasi × 2: webman (multi-proses) + Nginx + Supervisor
- Database: MySQL 8.0 master/slave (1 master 2 slave) + Redis Cluster
- Antrean: webman redis-queue (callback pembayaran/notifikasi/tugas pengaktifan)
- Tugas terjadwal: Crontab (rekonsiliasi/settlement/pemeriksaan domain/pengingat perpanjangan)
- Penyimpanan: S3/OSS + CDN
- Monitoring log: ELK/Loki + Prometheus + Grafana + Sentry

### Struktur Direktori

```
cloud-php/
├── apps/
│   ├── flutter/           # Klien Flutter
│   └── harmonyos/         # Klien HarmonyOS (ArkTS)
├── service/               # Server webman
│   ├── app/
│   │   ├── controller/    # Controller (per modul)
│   │   ├── service/       # Logika bisnis (per modul)
│   │   ├── model/         # Model data
│   │   ├── middleware/     # Middleware
│   │   ├── event/         # Definisi event
│   │   ├── listener/      # Listener event
│   │   ├── queue/         # Tugas antrean
│   │   ├── provider/      # Adaptor vendor cloud
│   │   └── cron/          # Tugas terjadwal
│   ├── common/            # Pustaka umum (auth/payment/i18n/notification/helper)
│   ├── config/            # File konfigurasi
│   ├── database/
│   │   └── migrations/    # Migrasi database
│   └── storage/           # Log/cache/upload
├── admin/                 # webman-admin
├── docs/                  # Dokumentasi
└── docker/                # Konfigurasi Docker
```

### Dependensi Composer Utama

workerman/webman-framework, webman/admin, webman/redis-queue, illuminate/database, firebase/php-jwt, stripe/stripe-php, phpseclib/phpseclib, monolog/monolog

### Optimasi Konkurensi Tinggi

#### 1. Pemisahan Baca/Tulis MySQL

Eloquent secara otomatis merutekan SELECT ke koneksi read, INSERT/UPDATE/DELETE ke koneksi write.

```
Konfigurasi (config/database.php):
  connections.mysql.write → DB_WRITE_HOST (master)
  connections.mysql.read  → DB_READ_HOST  (slave, dapat dikonfigurasi banyak untuk load balancing)
  sticky = true           → dalam periode permintaan yang sama, baca setelah tulis menggunakan master (mencegah lag master-slave)

Variabel lingkungan:
  DB_HOST=10.0.1.1          # Master (tulis)
  DB_READ_HOST=10.0.2.1     # Slave (baca), dapat di-deploy banyak
```

**Aturan perutean baca/tulis:**

| Tipe operasi | Target rute | Contoh |
|---------|---------|------|
| SELECT | koneksi read | `Product::where(...)->get()` |
| INSERT/UPDATE/DELETE | koneksi write | `Order::create(...)` |
| Semua operasi dalam transaksi | koneksi write | `DB::transaction(...)` |
| Baca setelah tulis (sticky) | koneksi write | dalam periode permintaan yang sama |

#### 2. Strategi Cache Multi-Level Redis

Menggunakan `CacheService` untuk meng-cache data baca frekuensi tinggi, saat Redis tidak tersedia otomatis degradasi menjadi query database langsung.

```
Lapisan cache:
  L1: Redis (dibagi antar proses, milidetik)
  L2: MySQL (persisten, fallback)

Strategi cache:
  Daftar produk        TTL 5 menit   key terpisah per region_id + category_id + keyword
  Detail produk        TTL 10 menit  key terpisah per product_id, invalidasi aktif saat konten berubah
  Daftar wilayah       TTL 1 jam     data wilayah jarang berubah
  Nilai tukar          TTL 30 menit  refresh tugas terjadwal + pembaruan aktif
  Harga TLD            TTL 1 jam     frekuensi perubahan harga TLD rendah
  Artikel bantuan      TTL 10 menit  invalidasi aktif saat terbit/diubah
  Kategori produk      TTL 10 menit  invalidasi aktif saat pohon kategori berubah

Pemanasan cache (setelah deploy):
  CacheService::warmUp(['products:all', 'regions', 'tlds', 'exchange_rates'])

Invalidasi aktif (saat data berubah):
  ProductController::update() → CacheService::forgetPattern('products:*')
  Crontab::ExchangeRateSync → CacheService::put('exchange_rates', $rates, TTL)
```

```php
// Contoh penggunaan
$products = CacheService::remember(
    "products:list:{$regionId}:{$categoryId}",
    CacheService::TTL_PRODUCT_LIST,
    fn() => Product::where('region_id', $regionId)->where('category_id', $categoryId)->get()
);
```

#### 3. Kompresi Respons Nginx + Rate Limit

```
Kompresi gzip:
  gzip on, comp_level=6
  gzip_types: application/json, text/plain, text/javascript, image/svg+xml
  Efek: rasio kompresi respons JSON 70-85%, menghemat bandwidth

Optimasi proxy:
  proxy_buffering on           # buffering respons upstream, klien lambat tidak mengikat worker
  proxy_http_version 1.1       # reuse koneksi panjang HTTP/1.1
  keep-alive ke upstream       # mengurangi handshake TCP

Rate limit:
  limit_req: 10 req/s per IP (burst 20)
  limit_conn: 20 concurrent per IP
  Endpoint /health tanpa rate limit (matikan access_log untuk mengurangi I/O)
```

#### 4. Saran Indeks Database

Berdasarkan analisis pola query, indeks berikut secara signifikan mengurangi baris yang dipindai dalam skenario konkurensi tinggi:

| Tabel | Indeks yang disarankan | Query yang dicakup |
|----|---------|---------|
| `orders` | `(user_id, status, created_at)` | Daftar pesanan pengguna + filter status |
| `orders` | `(order_no)` (unik) | Query presisi nomor pesanan |
| `products` | `(status, category_id, sort)` | Daftar produk frontend + filter kategori + pengurutan |
| `product_skus` | `(product_id, status)` | Daftar SKU + filter status |
| `product_regions` | `(sku_id, region_id)` (unik) | Pencarian harga regional |
| `resources` | `(user_id, status)` | Daftar sumber daya saya |
| `resources` | `(expired_at, status)` | Tugas terjadwal pemeriksaan kedaluwarsa |
| `provision_tasks` | `(status, next_retry_at)` | Worker polling tugas yang menunggu |
| `refresh_tokens` | `(user_id, revoked)` | Query manajemen sesi |
| `payment_transactions` | `(order_id)` | Query transaksi per pesanan |
| `payment_transactions` | `(transaction_no)` (unik) | Pemeriksaan idempotensi Webhook |
| `tickets` | `(user_id, status)` | Daftar tiket pengguna |
| `notifications` | `(user_id, read_at, created_at)` | Daftar notifikasi pengguna |

#### 5. Estimasi Koneksi Konkuren

```
webman multi-proses:
  jumlah core CPU × jumlah proses = jumlah worker
  Contoh: 4 core × 8 worker = 32 proses worker
  
Jumlah koneksi MySQL:
  setiap worker mempertahankan 1 koneksi persisten
  32 worker × 2 instance (service + admin) = 64 koneksi
  master 32 + slave 32, saran konservatif MySQL max_connections ≥ 200

Jumlah koneksi Nginx:
  worker_connections 1024 × worker_processes auto
  puncak konkuren ≈ worker_connections × worker_processes / 2
  server 4 core ≈ 2048 koneksi konkuren
```

---

## XII. Tabel Ringkasan Status Implementasi

### Modul Inti

| Modul | Status | Keterangan |
|------|------|------|
| **User** | ✅ Selesai | Registrasi/login/verifikasi email/OAuth/TOTP/manajemen sesi/penghapusan GDPR/CRUD alamat |
| **Product** | ✅ Selesai | Harga SKU×wilayah, kategori, pencarian (ES), ulasan, atribut, impor/ekspor massal |
| **Order** | ✅ Selesai | Keranjang belanja, pembuatan pesanan, siklus hidup, refund, faktur (PDF), kupon |
| **Payment** | ✅ Selesai | Saluran Stripe, perutean multi-saluran, verifikasi tanda tangan webhook, rekonsiliasi |
| **Provisioning** | ✅ Selesai | Proxmox + AWS EC2 + arsitektur ekstensibel ProviderFactory |
| **Domain** | ✅ Selesai | Harga TLD, catatan DNS, persetujuan transfer domain |
| **Supplier** | ✅ Selesai | Persetujuan pendaftaran, penayangan produk, settlement, penarikan, manajemen API Key |
| **Monitor** | ✅ Selesai | Probe sumber daya, mesin alarm, monitoring sertifikat SSL |
| **Ticket** | ✅ Selesai | Buat/balas/tugaskan/tutup/pelacakan SLA |
| **Notification** | ✅ Selesai | Empat saluran email/SMS/Push/pesan situs + manajemen preferensi pengguna |
| **Report** | ✅ Selesai | Laporan pendapatan/supplier/wilayah |
| **I18n** | ✅ Selesai | Multi-bahasa, multi-mata uang, multi-zona waktu |

### Sistem Keamanan

| Fitur | Status |
|------|------|
| WAF (8 kategori 45+ aturan: SQL injection/XSS/command injection/file inclusion/header injection/SSRF/NoSQL injection/open redirect) | ✅ |
| Middleware CORS | ✅ |
| Middleware identifikasi platform ClientPlatform (8 platform) | ✅ |
| Rate limit API (token bucket Redis) | ✅ |
| Geo-Blocking (MaxMind GeoIP2) | ✅ |
| Mode pemeliharaan (saklar variabel lingkungan + IP whitelist) | ✅ |
| Enkripsi permintaan/respons (AES-256-GCM) | ✅ |
| Log audit (database independen, termasuk pelacakan client_platform) | ✅ |
| Desensitisasi data (penanganan otomatis log/respons) | ✅ |
| Pengikatan fingerprint perangkat JWT + rotasi token + pencatatan client_platform | ✅ |
| Kata sandi bcrypt (cost=12) + enkripsi kedua Encryptable | ✅ |
| Konfirmasi ulang kata sandi (ConfirmationMiddleware, 5 kali gagal kunci 15 menit) | ✅ |
| Middleware WAF panel Admin | ✅ |
| Monitoring pengecualian Sentry (SentryBootstrap + desensitisasi before_send) | ✅ |
| Saklar fitur Feature Flags (override dinamis Redis + API panel admin) | ✅ |

### Fitur Baru (2026-05-21)

| Fitur | Status |
|------|------|
| API eksternal supplier (autentikasi API Key + endpoint pesanan/sumber daya/settlement/penarikan) | ✅ |
| Push real-time WebSocket (WebSocket asli Workerman + listener event) | ✅ |
| Script uji beban k6 (smoke/produk/konkuren) | ✅ |

### Statistik Backend

| Metrik | Jumlah |
|------|------|
| Endpoint API | 135 |
| Model data | 50+ |
| Tabel database | 50+ |
| Middleware | 15 (global 7 + route 6 + API eksternal 1 + admin WebSocket) |
| Tugas terjadwal | 7 |
| File migrasi | 22 |
| Pengujian | 362 tests / 579 assertions (Service 295 + Admin 67) |
| File pengujian | 22 |
| Script uji beban k6 | 3 (smoke / products / concurrent) |

### Dokumentasi

| Dokumen | Path |
|------|------|
| Spesifikasi desain sistem | `docs/superpowers/specs/2026-05-14-cloud-resource-platform-design.md` |
| Desain panel admin | `docs/admin-design.md` |
| Dokumentasi API supplier | `docs/supplier-api.md` |
| Checklist deployment | `docs/deployment.md` |
| Script uji smoke API | `docs/api-test.sh` |

### Status Frontend

| End | Status | Keterangan |
|----|------|------|
| Flutter | 🟡 Berjalan | ApiClient sudah terhubung header versi + lapisan data terpadu ApiService; login/daftar produk/keranjang belanja/daftar sumber daya sudah terhubung API; riwayat pesanan/pusat notifikasi perlu verifikasi lingkungan kompilasi |
| HarmonyOS | 🔴 Tahap awal | Hanya halaman login dan ApiClient |
| Admin Panel | ✅ Selesai | Dashboard/pengguna/produk/pesanan/pembayaran/sumber daya/supplier/tiket/domain/notifikasi/sistem/laporan/Webhook/import-export semua fungsi lengkap |
