# Laporan Audit Keamanan — cloud-php

**Tanggal**: 2026-08-04
**Cakupan**: Seluruh proyek (service + admin)
**Metodologi**: Tinjauan konfigurasi, audit middleware, pemeriksaan kode

---

## Penilaian Keseluruhan: **B+ (Baik, 4 kesenjangan untuk diperbaiki)**

Proyek memiliki arsitektur keamanan multi-lapis yang solid. Plugin erikwang2013/security-php dengan 31 detektor adalah fitur yang menonjol. Berikut rincian selengkapnya.

---

## 1. Pertahanan yang Ada (terverifikasi)

### Transmisi dan Enkripsi
| Mekanisme | Implementasi | Status |
|-----------|---------------|--------|
| Enkripsi transmisi API | AES-256-GCM melalui erikwang2013/encryption | OK |
| Enkripsi kolom DB | AES-128-ECB melalui erikwang2013/encryptable (deterministik, dapat dikueri) | OK |
| Rotasi kunci | ENCRYPTION_PREVIOUS_KEYS kunci lama dipisah koma | OK |
| Obfuskasi ID | Hashids dengan salt yang dapat dikonfigurasi dan panjang min 12 | OK |
| Hashing kata sandi | bcrypt cost=12, panjang min 8 | OK |

### Autentikasi dan Kontrol Akses
| Mekanisme | Implementasi | Status |
|-----------|---------------|--------|
| Autentikasi JWT | erikwang2013/jwt-webman, HS256, access TTL 900s + refresh 30 hari | OK |
| Daftar hitam JWT | Pencabutan token berbasis Redis | OK |
| MFA/TOTP | 6 digit, periode 30s, kompatibel Google/MS Authenticator | OK |
| RBAC | Middleware AccessControl admin + plugin\admin\api\Auth::canAccess() | OK |
| Penyimpanan session | Redis (db2) | OK |
| Captcha | CAPTCHA klik-teks erikwang2013/poster-php untuk login/registrasi | OK |

### Deteksi Serangan (WAF — Lapisan Ganda)
| Lapisan | Cakupan | Status |
|-------|----------|--------|
| WafMiddleware kustom | SQLi, XSS, CMDi, path traversal, injeksi header, SSRF, NoSQLi, redirect terbuka | OK |
| Security Plugin (31 detektor) | Semua di atas + XXE, deserialisasi, LDAP, header email, SSTI, serangan JWT, Host header, request smuggling, GraphQL, XPATH, JNDI/Log4Shell, SSI, injeksi CSV, kebocoran data, prototype pollution, WebSocket, bypass CORS, DNS rebinding | OK |

### Batas Frekuensi (hanya service)
| Rute | Rate | Burst | Per | Status |
|-------|------|-------|-----|--------|
| default | 60 | 10 | 60s | OK |
| login | 5 | 2 | 60s | OK |
| register | 3 | 0 | 60s | OK |
| pay | 10 | 3 | 60s | OK |
| upload | 10 | 2 | 60s | OK |
| supplier_api | 120 | 20 | 60s | OK |
| supplier_withdraw | 10 | 3 | 60s | OK |

### Perlindungan Lainnya
| Mekanisme | Implementasi | Status |
|-----------|---------------|--------|
| Batas ukuran permintaan | body 10MB, URL 2KB | OK |
| Validasi Content-Type | Daftar putih: JSON, multipart, form-urlencoded | OK |
| Prepared statements basis data | PDO::ATTR_EMULATE_PREPARES = false | OK |
| Pemisahan baca/tulis DB | Tulis ke master, Baca ke replika, sesi sticky | OK |
| Pencatatan audit | Basis data audit terpisah, LogSanitizer menyunting kolom sensitif | OK |
| Mode pemeliharaan | IP daftar putih bypass, lainnya mendapat 503 + Retry-After | OK |
| Larangan otomatis IP | 5 pelanggaran dalam 60s lalu larangan 15 menit | OK |
| Mode ketat SQL | Mencegah pemotongan data dan konversi tipe implisit | OK |

---

## 2. Kesenjangan dan Rekomendasi

### Kesenjangan 1 (Sedang): CORS mencerminkan origin mana pun
**File**: `service/common/security/CorsMiddleware.php:12`

```php
'Access-Control-Allow-Origin' => $origin ?: '*',
```

Ini menggema kembali Origin apa pun yang dikirim klien, secara efektif memungkinkan situs web mana pun membuat permintaan lintas-asal terautentikasi. Detektor cors plugin keamanan mungkin menangkap sebagian injeksi header, tetapi middleware itu sendiri tidak menyediakan daftar putih origin.

**Perbaikan**: Tambahkan pemeriksaan daftar putih. Jika origin tidak ada dalam daftar yang diizinkan, balas dengan `Access-Control-Allow-Origin: null` atau hilangkan header sepenuhnya.

### Kesenjangan 2 (Sedang): Header respons keamanan hilang
Baik service maupun admin tidak mengatur header keamanan HTTP penting:

| Header | Direkomendasikan | Saat Ini |
|--------|-------------|---------|
| Strict-Transport-Security | max-age=31536000; includeSubDomains | Hilang |
| X-Content-Type-Options | nosniff | Hilang |
| X-Frame-Options | DENY atau SAMEORIGIN | Hilang |
| Content-Security-Policy | Kebijakan dengan nonce/hash | Hilang |
| X-XSS-Protection | 1; mode=block | Hilang |
| Referrer-Policy | strict-origin-when-cross-origin | Hilang |
| Permissions-Policy | Batasi kamera/mikro/geolokasi | Hilang |

**Rekomendasi**: Tambahkan SecurityHeadersMiddleware ke tumpukan middleware service dan admin. Perbaikan berdampak tinggi, usaha rendah.

### Kesenjangan 3 (Rendah): admin/config/security.php tidak memiliki batas frekuensi
**File**: `admin/config/security.php`

Panel admin tidak memiliki konfigurasi rate_limits. Middleware WAF admin hanya memeriksa batas ukuran permintaan/Content-Type. Serangan brute-force pada login admin tidak dibatasi frekuensinya di lapisan aplikasi.

**Rekomendasi**: Tambahkan rate_limits ke admin/config/security.php atau terapkan RateLimitMiddleware ke rute admin.

### Kesenjangan 4 (Rendah): GeoBlockMiddleware didefinisikan tetapi tidak diaktifkan
**File**: `service/common/security/GeoBlockMiddleware.php`

Middleware ada dan berfungsi, tetapi tidak terdaftar di `service/config/middleware.php`. Jika pemblokiran geo diperlukan, tambahkan ke tumpukan.

### Kesenjangan 5 (Info): Overhead WAF ganda
Baik WafMiddleware (kustom, 40+ pola regex) maupun SecurityMiddleware (plugin, 31 detektor) berjalan pada setiap permintaan. Cakupan polanya tumpang tindih secara signifikan untuk SQLi, XSS, injeksi perintah, path traversal, injeksi header, SSRF, NoSQLi, dan redirect terbuka.

**Rekomendasi**: Plugin keamanan lebih komprehensif (31 detektor vs 8 kategori) dan memiliki daftar hitam IP, daftar putih kolom, dan dedup log. Pertimbangkan menghapus WafMiddleware kustom dan hanya mengandalkan plugin, atau setidaknya hapus pola yang tumpang tindih dari WafMiddleware.

### Kesenjangan 6 (Info): Kelas Validator minimal
**File**: `service/common/helper/Validator.php`

Hanya memiliki required(), email(), minLength(). Kurang: panjang maksimal, validasi numerik, sanitasi string, validasi URL, pencocokan pola. Controller yang tidak menggunakan validasi tingkat framework berisiko menerima input cacat.

---

## 3. Plugin Keamanan — Status 31 Detektor

| # | Detektor | Mode | Catatan |
|---|----------|------|-------|
| 1 | xss | block | |
| 2 | sql_injection | block | |
| 3 | command_injection | block | |
| 4 | path_traversal | block | |
| 5 | upload | block | |
| 6 | ssrf | block | |
| 7 | xxe | block | |
| 8 | header_injection | **log** | CRLF cocok dengan konten textarea, harus tetap log |
| 9 | deserialization | block | |
| 10 | ldap_injection | block | |
| 11 | mail_header | block | |
| 12 | ssti | **log** | {{ }} cocok dengan templat Vue/Angular |
| 13 | nosql_injection | **log** | $ne/$gt cocok dengan variabel shell/LaTeX |
| 14 | open_redirect | block | |
| 15 | jwt_attack | block | |
| 16 | host_header | block | |
| 17 | request_smuggling | block | |
| 18 | graphql_injection | block | |
| 19 | xpath_injection | block | |
| 20 | jndi_injection | block | |
| 21 | ssi_injection | block | |
| 22 | csv_injection | block | |
| 23 | data_leak | block | |
| 24 | prototype_pollution | block | |
| 25 | websocket | block | |
| 26 | cors | block | |
| 27 | dns_rebinding | **log** | Host loopback (127.0.0.1/localhost) tidak lagi 403 (normal untuk pengembangan/pengujian, hanya dicatat) |
| 28 | http_method | block | |
| 29 | body_size | block | |
| 30 | content_type | block | |
| 31 | csrf_origin | block | |

Semua 31 detektor aktif. 4 dalam mode hanya-log (risiko positif palsu terdokumentasi). Konfigurasi benar.

---

## 4. Urutan Eksekusi Middleware (service)

```
1. VersionMiddleware          — penguraian header versi API
2. CorsMiddleware              — header CORS (terlalu permisif, lihat Kesenjangan 1)
3. ClientPlatformMiddleware    — deteksi OS/platform
4. WafMiddleware               — WAF kustom (40+ pola regex)
5. SecurityMiddleware           — WAF plugin (31 detektor)
6. LocaleMiddleware            — i18n
7. HashidRequestMiddleware     — dekode ID
8. MaintenanceMiddleware       — pemeriksaan mode pemeliharaan
```

---

## 5. Ringkasan

| Kategori | Nilai | Masalah Kunci |
|----------|-------|------------|
| Deteksi Serangan | **A** | 31 detektor, lapisan WAF ganda (redundan tetapi menyeluruh) |
| Autentikasi | **A-** | bcrypt+MFA+daftar hitam JWT, batas frekuensi admin hilang |
| Keamanan Transmisi | **B+** | AES-256-GCM baik, header HSTS/CSP hilang |
| Validasi Input | **B** | WAF menangkap serangan, validasi tingkat aplikasi tipis |
| Kontrol Akses | **A-** | RBAC + pemeriksaan session, CORS terlalu permisif |
| Audit/Pencatatan | **A** | Basis data audit terpisah, penyuntingan kolom sensitif |
| Batas Frekuensi | **B+** | Dikonfigurasi baik untuk service, hilang untuk admin |

**Urutan prioritas perbaikan:**
1. Tambahkan header respons keamanan (HSTS, CSP, X-Frame-Options, dll.)
2. Batasi CORS ke daftar putih alih-alih mencerminkan origin mana pun
3. Tambahkan batas frekuensi ke panel admin
4. Aktifkan GeoBlockMiddleware jika pemblokiran geo diperlukan
5. Pertimbangkan menggabungkan lapisan WAF untuk mengurangi overhead regex per permintaan

---

## 6. Perbaikan yang Diterapkan (2026-08-04)

### Diperbaiki
| Kesenjangan | Perbaikan | File yang Diubah |
|-----|-----|---------------|
| CORS mencerminkan origin mana pun | Mode daftar putih dengan variabel env `CORS_ALLOWED_ORIGINS`, mendukung wildcard `*.example.com` dan `*` untuk semua | `service/common/security/CorsMiddleware.php` |
| Header keamanan hilang | `SecurityHeadersMiddleware` baru ditambahkan ke tumpukan service dan admin: X-Content-Type-Options, X-Frame-Options, Referrer-Policy, X-XSS-Protection, Permissions-Policy, HSTS (opt-in via env) | `service/common/security/SecurityHeadersMiddleware.php`, `admin/app/middleware/SecurityHeadersMiddleware.php` |
| Admin tanpa batas frekuensi | Ditambahkan konfigurasi `rate_limits` + `RateLimitMiddleware` ke panel admin (default 60/menit, login 5/menit) | `admin/config/security.php`, `admin/app/middleware/RateLimitMiddleware.php` |
| GeoBlock tidak diaktifkan | `GeoBlockMiddleware` didaftarkan di tumpukan middleware service | `service/config/middleware.php` |

### Variabel Env Baru
| Variabel | Tujuan | Default |
|----------|---------|---------|
| `CORS_ALLOWED_ORIGINS` | Origin yang diizinkan dipisah koma | (kosong = tolak semua) |
| `SECURITY_HSTS_ENABLE` | Aktifkan header HSTS | false |
| `SECURITY_HSTS_VALUE` | Nilai header HSTS | max-age=31536000; includeSubDomains |
| `SECURITY_X_FRAME_OPTIONS` | Nilai X-Frame-Options | SAMEORIGIN |
| `GEO_BLOCKED_COUNTRIES` | Kode negara yang diblokir (ISO 3166-1) | (kosong = dinonaktifkan) |
| `GEOIP_DB_PATH` | Jalur GeoLite2 .mmdb | storage_path('geoip/GeoLite2-Country.mmdb') |

### Pipeline Middleware yang Diperbarui

**Service:**
```
Version → CORS → SecurityHeaders → ClientPlatform → GeoBlock → WAF → SecurityPlugin → Locale → HashidRequest → Maintenance
```

**Admin:**
```
SecurityHeaders → RateLimit → WAF → SecurityPlugin → AccessControl
```
