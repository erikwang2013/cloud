# Laporan Audit Menyeluruh CloudPlatform

**Tanggal**: 2026-08-06
**Cakupan audit**: service penuh (app / common / config / tests) + konfigurasi ekologis + perlindungan keamanan
**Metode**: suite pengujian PHPUnit, pemeriksaan sintaks PHP penuh, audit rute/middleware, tinjauan kode fitur baru OAuth, pengecekan konsistensi variabel lingkungan dan konfigurasi, audit keamanan dependensi, uji asap

---

## 1. Kesimpulan Umum

| Dimensi | Kesimpulan |
|------|------|
| Pengujian | **314 item semua lulus** (setelah perbaikan 2 bug, 494 assertions) |
| Sintaks | 287 file PHP 0 kesalahan sintaks |
| Keamanan dependensi | composer audit tanpa kerentanan diketahui; 1 paket usang (doctrine/annotations) |
| Arsitektur keamanan | perlindungan multi-lapis lengkap (WAF mesin ganda, daftar putih CORS, enkripsi transmisi, enkripsi kolom, bcrypt cost=12, daftar hitam JWT, log audit) |
| Masalah serius | **1 P0 (id_token Apple tidak verifikasi tanda tangan → dapat ambil alih akun), 4 P1** |
| Konfigurasi ekologis | **.env.example kurang 31 variabel yang digunakan**, termasuk semua kredensial OAuth; kanal notifikasi implementasi placeholder |

---

## 2. Hasil Pengujian

```
OK (314 tests, 494 assertions)
```

### 2 bug yang diperbaiki putaran ini

| ID | File | Masalah | Perbaikan |
|----|------|------|------|
| B1 | `service/common/captcha/CaptchaService.php:31` | membaca `$result['extra']['targets']`, tetapi pustaka mengembalikan `extra.texts` → `target_count` selalu 0 | Ganti ke `extra.texts` |
| B2 | `vendor/erikwang2013/poster-php/src/Captcha/ClickCaptcha.php:17` | pustaka default `targetCount = 5`, bertentangan dengan kontrak README pustaka itu sendiri (medium=3 target) → 3 test Captcha gagal | nilai default 5 → 3 |

> B2 termasuk bug pustaka vendored (vendor/ sudah dilacak git, perbaikan dapat bertahan). Disarankan sekaligus mengirim perbaikan ke repositori hulu.

---

## 3. Masalah Keamanan Serius (P0 / P1)

### P0-1. `id_token` Apple tidak verifikasi tanda tangan —— dapat langsung ambil alih akun
**File**: `service/app/user/service/OAuthService.php:180-192` (`appleProfile()`)

```php
$parts  = explode('.', $tokenData['id_token']);
$claims = json_decode(base64_decode($parts[1]), true);   // hanya dekode base64, tanpa validasi tanda tangan/iss/aud/exp
```

Penyerang dapat menyusun `id_token` sendiri memalsukan email mana pun untuk menyelesaikan login OAuth. `resolveUser()` akan mencocokkan email dengan pengguna yang ada dan langsung menerbitkan token → **ambil alih akun mana pun**.

**Perbaikan**: gunakan Apple JWKS (`https://appleid.apple.com/auth/keys`) + `Firebase\JWT\JWT::decode($idToken, $keys, ['ES256'])` untuk verifikasi tanda tangan, dan validasi `iss=appleid.apple.com`, `aud=client_id`, `exp`, `nonce`.

### P1-1. Login OAuth tidak memvalidasi `email_verified`
**File**: `OAuthService.php:163-178, 282-303`

Google/Facebook/Microsoft/LinkedIn semuanya mengembalikan kolom `email_verified`, kode sepenuhnya mengabaikannya. Pengguna dengan email belum diverifikasi di penyedia dapat langsung mengikat/mengambil alih akun terdaftar menggunakan email tersebut. Jalur GitHub sudah memvalidasi `verified` (benar), penyedia lainnya perlu divalidasi seragam.

### P1-2. Middleware pembatas frekuensi ada tetapi tidak pernah dipasang —— dokumentasi tidak sesuai implementasi
**File**: `common/Security/RateLimitMiddleware.php` + `config/security.php` + `config/route.php`

- Aturan batas frekuensi seperti login=5/min, register=3/min sudah dikonfigurasi di `security.php`
- `RateLimitMiddleware` **tidak dirujuk rute mana pun** (grep seluruh pustaka hanya mengenai kelas itu sendiri)
- `docs/features.md` mengklaim login "dibatasi 5 req/min", registrasi "dibatasi 3 req/min" —— kenyataannya tidak ada
- Laporan audit historis (`security-audit-2026-08-04.md`) menandai item ini OK, hanya melihat konfigurasi tanpa memverifikasi pemasangan, putaran ini dikoreksi

**Dampak**: endpoint publik seperti login/registrasi/lupa kata sandi/reset kata sandi/kode pemulihan/CAPTCHA semuanya dapat di-brute force tanpa batas (login hanya mengandalkan penguncian per-akun, tidak mencegah serangan credential stuffing dan pembanjiran level IP).

**Perbaikan**: pasang `RateLimitMiddleware` ke rute publik seperti `/api/auth/*`, `/api/captcha/*` (dapat dipasang grup global `''`, dibedakan dengan parameter `route`).

### P1-3. TOTP 2FA tidak dipaksakan dalam alur login
**File**: `AuthService.php:64-97` (`login()`) + `AuthController.php` + `config/features.php`

`user->totp_enabled` hanya diperiksa di `totpVerify/totpDisable/totpRecoveryCodes`, **`login()` tidak pernah memvalidasinya**. Pengguna yang mengaktifkan 2FA masih mendapatkan access token valid hanya dengan kata sandi —— 2FA sia-sia (`FEATURE_TOTP` default aktif).

**Perbaikan**: saat login, jika `totp_enabled`, terbitkan token sementara dan minta validasi TOTP lulus baru tukar token resmi (atau minta parameter totp code).

### P1-4. Kanal notifikasi implementasi placeholder —— verifikasi email/reset kata sandi tidak berfungsi di produksi
**File**: `app/Notification/Queue/EmailSender.php`, `SmsSender.php`, `PushSender.php`

Ketiga konsumen hanya `error_log()` mensimulasikan pengiriman, dan mencatat `send_status` sebagai `sent`. Konsekuensinya:
- **Alur lupa kata sandi putus**: `AuthController::forgotPassword()` membuat kode verifikasi dan "mengirim" email, tetapi email tidak pernah sampai → pengguna tidak dapat mengatur ulang kata sandi secara mandiri
- Verifikasi email registrasi, peringatan login IP baru juga tidak berfungsi
- 7 variabel `SMTP_*`/`MAIL_FROM_*` di `.env.example` tidak dibaca kode mana pun (konfigurasi mati)

**Perbaikan**: sambungkan pengiriman email nyata (SDK PHPMailer/SendGrid), hapus penandaan status `sent` yang menyesatkan; atau tandai eksplisit sebagai fitur belum selesai dan hapus janji terkait dari dokumentasi.

---

## 4. Masalah Keamanan (P2)

| ID | File | Masalah |
|----|------|------|
| P2-1 | `app/Controller/UploadController.php:23` | parameter `type` tidak divalidasi daftar putih langsung digabung ke path `uploads/{$type}/...` → **path traversal** dapat menulis keluar direktori unggah (nama file acak, tidak dapat menimpa, tetapi dapat mencemari sistem file); disarankan batasi type ke daftar putih enumerasi, dan tambahkan perlindungan `index.php`/`.htaccess` pada direktori penyimpanan |
| P2-2 | sama seperti di atas | hanya memvalidasi ekstensi, tanpa sniffing konten MIME (file polyglot dapat dieksploitasi oleh cache/forward); disarankan validasi MIME asli dengan `finfo` |
| P2-3 | `AuthController.php:131-158` | kode verifikasi 6 digit reset kata sandi berlaku 600 detik, tanpa batas percobaan → dalam 10 menit dapat brute force 1 juta kombinasi; `forgotPassword` tanpa batas frekuensi → bom email |
| P2-4 | `AuthController.php:333-348` | pembuatan/penampilan kode pemulihan `totpRecoveryCodes` hanya butuh login, tanpa konfirmasi kata sandi; harus dipasang `ConfirmationMiddleware` |
| P2-5 | `common/Auth/Middleware/AuthMiddleware.php:31` | pemeriksaan manual daftar hitam dengan kunci `jwt_blacklist:{sha256(token)}`, tidak sesuai format `jwt_blacklist:{jti}` pustaka → kode mati (perlindungan aktual dilakukan `decode()` dalam pustaka, berlaku tetapi redundan), disarankan hapus atau ganti antarmuka pustaka |
| P2-6 | `OAuthService.php:67-94` | parameter `redirect` dari `authorizeUrl` disimpan ke state tetapi tidak pernah digunakan (parameter mati); state tidak terikat provider; seluruh alur OAuth tanpa nonce (penyedia OIDC, kekurangan pertahanan berlapis, diperbaiki bersama P0-1) |
| P2-7 | `OAuthService.php:31-37, 236-238` | API v2 X (Twitter) `userinfo` tidak mengembalikan email → login X pasti gagal "Email not provided", cacat fungsi, perlu penjelasan dokumentasi atau ganti hubungkan ke endpoint `/2/email` |
| P2-8 | `AuthController.php:436-442` | `deviceFingerprint` menggunakan `strrpos($ip, '.')` memotong segmen IPv4, klien IPv6 terdegradasi menjadi string kosong → sidik jari lemah; disarankan gunakan 64 bit pertama atau hash seluruh IP |

---

## 5. Kelengkapan Konfigurasi Ekologis

### 5.1 Variabel yang hilang di .env.example (dirujuk `getenv()` dalam kode tetapi tidak didefinisikan) —— 31

| Kategori | Variabel |
|------|------|
| **Kredensial OAuth (fitur baru, sama sekali tidak didokumentasikan)** | `GOOGLE/APPLE/FACEBOOK/X/MICROSOFT/LINKEDIN/GITHUB_OAUTH_CLIENT_ID`, `_CLIENT_SECRET`, `_REDIRECT_URI` (21) |
| **Khusus Apple** | `APPLE_TEAM_ID`, `APPLE_KEY_ID`, `APPLE_PRIVATE_KEY_PATH` |
| **Fungsi kunci** | `APP_URL` (tautan email verifikasi bergantung, hilang menyebabkan tautan email salah), `APP_ENV`, `APP_VERSION` |
| **Keamanan** | `INTERNAL_MONITOR_TOKEN` (proteksi endpoint /health/*), `MAINTENANCE_MODE`, `MAINTENANCE_ALLOWED_IPS`, `WEBHOOK_SECRET`, `JWT_LEEWAY` |
| **Cloud/penyimpanan** | `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `BACKUP_S3_BUCKET`, `BACKUP_S3_REGION`, `DB_READ_HOST` |
| **Feature flags (8)** | `FEATURE_SSL_PRODUCT`, `FEATURE_OBJECT_STORAGE`, `FEATURE_USAGE_BILLING`, `FEATURE_PROMETHEUS`, `FEATURE_CDN_PRODUCT`, `FEATURE_SUPPLIER_RATING`, `FEATURE_AFFILIATE`, `FEATURE_GRAPHQL` |
| **Lainnya** | `METRICS_PORT`, `WS_PORT`, `GEOIP_DB_PATH` (hanya komentar di .env.example), `SSL_STAGING`, `HASHIDS_ALPHABET`, `POSTER_IMAGE_DRIVER`, `EXCHANGE_RATE_API_URL`, `COUNTRY_SEASON_DEFAULT` |

### 5.2 Didefinisikan di .env.example tetapi tidak digunakan kode —— 7

`SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`, `SMTP_ENCRYPTION`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` (pengiriman email belum diimplementasikan, lihat P1-4)

### 5.3 Cakupan i18n tidak konsisten

| Bahasa | messages.php | billing | health | storage |
|------|:---:|:---:|:---:|:---:|
| en-US / zh-CN | 129 / 129 | 10 / 16 | 8 / 16 | 9 / 16 |
| ja-JP | 63 | 10 | 8 | 9 |
| ko-KR | 51 | 10 | 8 | 9 |
| fr-FR / de-DE / es-ES | 44 | 10 | 8 | 9 |

- Bahasa non-Cina-Inggris kekurangan lebih dari setengah kunci terjemahan; zh-CN billing/health/storage lebih banyak 6-8 kunci dari en-US (arah sinkronisasi terbalik)
- **Kunci terjemahan terkait OAuth semuanya hilang** (pesan kesalahan hardcode bahasa Inggris)

### 5.4 Masalah ekologis lainnya

| ID | Masalah |
|----|------|
| E1 | `service/composer.lock` diabaikan `.gitignore` dan tidak dikomit —— dependensi aplikasi tidak terkunci versi, deployment tidak dapat direproduksi (risiko deployment) |
| E2 | `service/.phpunit.cache/` muncul di git status (tidak diabaikan) |
| E3 | port 8787 konflik dengan proyek lain erp-php di mesin lokal, cloud-php tidak dapat dimulai di mesin lokal (dikonfirmasi 8787 ditempati WorkerMan erp-php) |
| E4 | klaim batas frekuensi/email di `docs/features.md` tidak sesuai kenyataan (lihat P1-2 / P1-4), dokumentasi perlu dikoreksi serentak |
| E5 | dependensi `doctrine/annotations` sudah usang (peringatan composer audit), disarankan evaluasi penghapusan |

---

## 6. Saran Optimasi (non-blokir)

1. **DI pembuatan layanan**: konstruktor `AuthController` langsung `new AuthService()/OAuthService()`, disarankan hubungkan ke container (didukung native webman), memudahkan pengujian dan penggantian.
2. **Penguatan direktori unggah**: tempatkan `index.html` dalam direktori, nonaktifkan eksekusi PHP (nginx `location ~ \.php { deny all; }`).
3. **Penyempitan regex WAF**: `sqli_patterns` di `security.php` berisi pola luas seperti `\b(select|update|delete|...)\b`, di bawah batas frekuensi global kemunculan kata-kata ini di tiket/ulasan pengguna akan kena 403; disarankan hanya berlaku untuk parameter sensitif atau perketat regex.
4. **Log audit**: `AuditLogger::record('user_registered', ['user_id' => null])` tidak mencatat ID pengguna baru, disarankan catat ID aktual.
5. **Cakupan pengujian OAuth**: `OAuthServiceTest` mencakup konstruksi URL dan pertukaran code, tetapi `resolveUser()` (jalur DB) dan jalur verifikasi tanda tangan Apple tanpa pengujian; setelah perbaikan P0 wajib tambah kasus uji kegagalan verifikasi tanda tangan.
6. **Integrasi CI**: proyek memiliki direktori `.github`, disarankan tambah GitHub Actions: `composer install && phpunit` + `composer audit`, mencegah regresi.
7. **Batasan metode HTTP**: rute OAuth mendaftarkan callback GET/POST sekaligus masuk akal (dibutuhkan Apple), operasi tulis publik lainnya sudah POST eksplisit, OK.

---

## 7. Daftar Prioritas Perbaikan

| Prioritas | Item | Beban Kerja |
|:---:|------|:---:|
| P0 | Verifikasi tanda tangan id_token Apple (JWKS + iss/aud/exp/nonce) | Sedang |
| P1 | OAuth semua penyedia memvalidasi `email_verified` | Kecil |
| P1 | Pasang RateLimitMiddleware ke rute publik | Kecil |
| P1 | Paksakan TOTP dalam alur login | Sedang |
| P1 | Implementasikan pengiriman email nyata (atau tandai belum selesai) | Sedang |
| P1 | Lengkapi 31 variabel hilang di .env.example + dokumentasi konfigurasi OAuth | Kecil |
| P2 | Daftar putih type unggah + validasi MIME | Kecil |
| P2 | Batas frekuensi kode reset/lupa kata sandi | Kecil |
| P2 | Antarmuka kode pemulihan pasang konfirmasi kata sandi | Kecil |
| P2 | Komit composer.lock, gitignore .phpunit.cache | Sangat kecil |
| P3 | Bersihkan kode mati daftar hitam, penyempitan regex WAF, lengkapi i18n | Sedang |

---

## 8. Status Perbaikan (2026-08-06)

| Prioritas | Item | Status |
|:---:|------|:---:|
| P0 | Verifikasi tanda tangan id_token Apple (JWKS + iss/aud/exp/nonce) | ✅ Sudah diperbaiki |
| P1 | OAuth semua penyedia memvalidasi `email_verified` (X tambah fallback /2/email) | ✅ Sudah diperbaiki |
| P1 | Pasang RateLimitMiddleware (rute auth/oauth/password/sms/captcha + 4 aturan baru) | ✅ Sudah diperbaiki |
| P1 | Paksakan TOTP dalam alur login (5 kesalahan kunci 15 menit, penghitungan independen cegah DoS) | ✅ Sudah diperbaiki |
| P1 | Pengiriman email nyata (symfony/mailer SMTP; status dev-stub saat belum dikonfigurasi) | ✅ Sudah diperbaiki |
| P1 | Lengkapi 31 variabel hilang di .env.example + dokumentasi konfigurasi OAuth | ✅ Sudah diperbaiki |
| P2 | Daftar putih type unggah + sniffing konten MIME finfo | ✅ Sudah diperbaiki |
| P2 | Batas frekuensi kode reset/lupa kata sandi (5 kesalahan → 429 10 menit) | ✅ Sudah diperbaiki |
| P2 | Antarmuka kode pemulihan pasang konfirmasi kata sandi | ✅ Sudah diperbaiki |
| P2 | composer.lock lepas dari ignore dan di-staging, gitignore .phpunit.cache | ✅ Sudah diperbaiki |
| P3 | Pembersihan kode mati daftar hitam, penyempitan regex WAF (3 struktural), pelengkapan i18n (konten salah zh-CN billing/health/storage ditulis ulang, trans() implementasi fallback_locale) | ✅ Sudah diperbaiki |
| E3 | Port 8787 ditempati erp-php, tidak dapat dimulai di mesin lokal | ⚠️ Masalah lingkungan, lingkungan deployment tanpa konflik |
| E5 | doctrine/annotations sudah usang | ⚠️ Dipertahankan setelah evaluasi (dependensi langsung hg/apidoc, penghapusan merusak pembuatan dokumen API) |

Pengujian tambahan: OAuth 12 item (termasuk parameter nonce, verifikasi tanda tangan, penolakan email_verified, fallback email X), 2 item setelah WAF diperketat. Garis dasar penuh: **319/319 lulus (505 asersi)**.

*Metode pembuatan laporan: pengujian penuh PHPUnit, `php -l` 287 file, audit statis rute/middleware, perbandingan selisih himpunan penggunaan dan definisi env, composer audit, penyelidikan port dan proses. Garis dasar pengujian: 314/314 lulus.*
