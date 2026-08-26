# Laporan Audit CloudPlatform (Putaran Kedua, 2026-08-06)

> Cakupan: pemeriksaan ulang setelah semua masalah putaran sebelumnya (audit-report-2026-08-06.md) diperbaiki.
> Garis dasar pengujian: PHPUnit **319/319 lulus (505 asersi)**; `php -l` 253 file PHP **0 kesalahan sintaks**.

---

## 1. Pengujian dan Pemeriksaan Statis

| Item | Hasil |
|------|------|
| PHPUnit penuh | OK (319 tests, 505 assertions) |
| `php -l` (app/common/config) | 253 file semua lulus |
| composer audit | **Tanpa kerentanan keamanan**; 1 paket usang doctrine/annotations (dependensi langsung hg/apidoc, dievaluasi dipertahankan) |
| composer.lock | Sudah masuk kontrol versi (staging A) |

---

## 2. Pemeriksaan Konfigurasi Ekologis

### 2.1 Penggunaan dan Definisi env —— Lengkap ✓

- Semua kunci `getenv()` dalam kode (termasuk pola dinamis `{PROVIDER}_OAUTH_*`) sudah ada definisi di `.env.example` atau konfigurasi opsional bentuk komentar (`#HASHIDS_ALPHABET`, `#POSTER_IMAGE_DRIVER`, `#EXCHANGE_RATE_API_URL`, `#COUNTRY_SEASON_DEFAULT`, `#SECURITY_HSTS_VALUE`)
- Item redundan templat (risiko rendah): `MAIL_FROM_NAME` tidak ada referensi `getenv()` dalam kode, hanya dipertahankan di templat

### 2.2 Penguncian Dependensi ✓

- `service/composer.lock` sudah dikomit; `.gitignore` tidak lagi mengecualikan; `service/.phpunit.cache/` sudah diabaikan

### 2.3 Penjelasan Lingkungan

- Port lokal 8787 masih ditempati erp-php, cloud-php tidak dapat dimulai secara lokal (lingkungan deployment tidak ada konflik)
- `composer validate` fatal karena konflik eval antara Installer plugin vendor `erikwang2013/security-php` dengan composer itu sendiri (masalah paket pihak ketiga, bukan kode proyek ini)

---

## 3. Pemeriksaan Perlindungan Keamanan

### 3.1 Rantai middleware global (11 lapisan, mencakup semua rute) ✓

```
Version → CORS → SecurityHeaders → ClientPlatform → GeoBlock
→ WAF (SQLi/XSS) → SecurityPlugin (31 jenis deteksi serangan)
→ Locale → Metrics → HashidRequest → Maintenance
```

### 3.2 Batas frekuensi rute publik —— putaran ini perbaiki 1 titik

| Rute | Middleware | Aturan batas frekuensi |
|------|--------|---------|
| register / login / refresh | RateLimit | register 3/min, login 5/min |
| **forgot-password / reset-password** | **RateLimit (dipasang putaran ini)** | password_reset 3/5min |
| oauthRedirect / oauthCallback (GET+POST) | RateLimit | oauth 10/60s |
| login/recovery | RateLimit | login 5/min |
| send-sms | RateLimit | sms 5/h |
| captcha/create | RateLimit | captcha 30/60s |

> **Perbaikan**: dua rute `forgot-password`/`reset-password` putaran sebelumnya mendefinisikan aturan `password_reset` tetapi lupa memasang middleware (permukaan bom email / brute force kode verifikasi), putaran ini dipasang.

### 3.3 Paparan file unggah —— putaran ini perbaiki 1 titik (berisiko tinggi)

**Masalah**: konfigurasi nginx di `deployment.md` `location /storage/ { alias .../service/storage/; }` membuat seluruh direktori storage publik:

```
storage/
├── backups/    ← backup basis data (.sql.gz) dapat diunduh publik
├── apple/      ← kunci privat AuthKey.p8 dapat diunduh publik (dapat menerbitkan token Apple)
├── firebase/   ← kredensial akun layanan FCM (berisi kunci privat) dapat diunduh publik
├── geoip/      ← basis data GeoLite2
└── uploads/    ← file unggahan (diharapkan publik)
```

**Perbaikan**: deployment.md dan docker/nginx.conf keduanya diubah menjadi `location ^~ /storage/uploads/`, hanya mengekspos subdirektori uploads.

### 3.4 Pemeriksaan lainnya ✓

- `verify-email`: token acak sekali pakai (dikosongkan setelah verifikasi), tanpa permukaan brute force/enumerasi, tidak perlu batas frekuensi
- Antarmuka unggah: daftar putih type + deteksi konten MIME finfo (putaran sebelumnya sudah diperbaiki); uploads keluar langsung melalui alias statis nginx, tidak mengeksekusi PHP
- JWT: HS256 + daftar hitam Redis (validasi per jti di basis data); TOTP login wajib + 5 kali gagal kunci 15 menit
- OAuth: verifikasi tanda tangan JWKS + iss/aud/exp/nonce + wajib email_verified (putaran sebelumnya sudah diperbaiki)
- Rute manajemen: AuthMiddleware + AdminRoleMiddleware + ConfirmationMiddleware

---

## 4. Saran Tersisa (non-blokir)

| Level | Item | Penjelasan |
|:---:|------|------|
| P3 | Direktori lama redundan `service/service/` (28K) | Berisi salinan Supplier/WebSocket usang, tidak dimuat PSR-4, tidak dilacak, mudah salah diubah; disarankan dihapus setelah konfirmasi manual |
| P3 | `MAIL_FROM_NAME` redundan di templat | Tidak digunakan kode, dapat dipertahankan sebagai konfigurasi cadangan nama pengirim email |
| P3 | doctrine/annotations usang | Dependensi langsung hg/apidoc, penghapusan perlu mengganti skema pembuatan dokumen API secara bersamaan |
| P3 | Penguatan direktori unggah (saran kedua) | Tempatkan `index.html` dalam direktori uploads, pastikan tidak ada eksekusi PHP di lapisan deployment (alias nginx sudah menghindari secara alami, perlu perhatian untuk skenario server bawaan webman) |

---

## 5. Kesimpulan

15 perbaikan putaran sebelumnya semuanya dikonfirmasi efektif setelah pemeriksaan ulang, garis dasar pengujian stabil (319/505). Putaran ini ditemukan baru dan diperbaiki seketika 3 titik: **rute forgot/reset lupa pasang batas frekuensi (P1)** , **konfigurasi nginx deployment.md mengekspos backup dan kunci privat (P0)** , **nginx docker kekurangan konfigurasi statis uploads (P2)** . Setelah perbaikan, pengujian penuh dijalankan ulang lulus.

*Metode pembuatan laporan: PHPUnit penuh, php -l 253 file, audit statis rute/middleware, audit konfigurasi nginx/docker, perbandingan selisih penggunaan dan definisi env, composer audit.*
