# Cloud Platform — Platform Perdagangan Sumber Daya Cloud Global

## Languages

| Language | Docs |
|----------|------|
| 简体中文 | [README.md](../../../README.md) |
| English | [README_EN.md](../../../README_EN.md) |
| English | [en docs](../../en/README.md) |
| 한국어 | [ko docs](../../ko/README.md) |
| Русский | [ru docs](../../ru/README.md) |
| Deutsch | [de docs](../../de/README.md) |
| Français | [fr docs](../../fr/README.md) |
| Español | [es docs](../../es/README.md) |
| Português | [pt docs](../../pt/README.md) |
| हिन्दी | [hi docs](../../hi/README.md) |
| العربية | [ar docs](../../ar/README.md) |
| বাংলা | [bn docs](../../bn/README.md) |
| Bahasa Indonesia | [id docs](../../id/README.md) |
| 日本語 | [ja docs](../../ja/README.md) |

<p align="center">
  <img src="docs/diagrams/c.svg" alt="CloudPlatform 项目宠物" width="220">
</p>

Platform perdagangan sumber daya cloud untuk pengguna global, mendukung pembelian online dan pengiriman otomatis untuk server (VM), alamat IP, disk cloud, domain, sertifikat SSL, penyimpanan objek (S3), akselerasi CDN, dan produk lainnya. Mesin fisik milik sendiri dikirim melalui virtualisasi Proxmox VE, sekaligus mendukung vendor pihak ketiga untuk bergabung dan berjualan. Menyediakan penagihan berbasis pemakaian (pay-as-you-go), distribusi rekomendasi, GraphQL API, dan observabilitas Prometheus/Grafana.

## Tumpukan Teknologi

| Lapisan | Teknologi |
|------|------|
| Kerangka Backend | PHP 8.2 + [webman](https://github.com/walkor/webman) (workerman) |
| Panel Admin | [webman-admin](https://www.workerman.net/plugin/1) (Layui) |
| ORM | Illuminate/Eloquent 10.x |
| Autentikasi | JWT HS256 ([erikwang2013/jwt-webman](https://github.com/erikwang2013/jwt-webman)) |
| Kunci Utama Terdistribusi | Snowflake ID ([erikwang2013/snowflake-php](https://github.com/erikwang2013/snowflake-php)) |
| Obfuskasi ID | Hashids ([erikwang2013/hashids](https://github.com/erikwang2013/hashids)) |
| Enkripsi Transmisi | AES-256-GCM ([erikwang2013/encryption](https://github.com/erikwang2013/encryption)) |
| Enkripsi Kolom | AES-256-CBC ([erikwang2013/encryptable](https://github.com/erikwang2013/encryptable)) |
| Pencarian Teks Lengkap | Elasticsearch ([erikwang2013/webman-scout](https://github.com/erikwang2013/webman-scout)) |
| Bendera Negara | Unicode Flag Emoji ([erikwang2013/season](https://github.com/erikwang2013/season)) |
| CAPTCHA Klik | Click CAPTCHA ([erikwang2013/poster-php](https://github.com/erikwang2013/poster-php)) |
| Perlindungan Keamanan | 31 jenis deteksi serangan ([erikwang2013/security-php](https://github.com/erikwang2013/security-php)) |
| Ekspor Tabel | PhpSpreadsheet ^2.0 |
| SDK Pembayaran | Stripe PHP ^15.0 |
| SDK SMS | Twilio PHP ^8.0 |
| SDK Push | Firebase PHP ^7.0 |
| Antrean | webman redis-queue |
| Basis Data | MySQL 8.0 (koneksi ganda: basis data utama + basis data audit) |
| Mesin Pencari | Elasticsearch 8.x |
| Virtualisasi | Proxmox VE (kanal gRPC kvm-server Rust, registrasi e-cat/etcd) |
| Klien | Flutter (iOS/Android/Web/Linux/macOS/Windows) + HarmonyOS ArkTS |
| GraphQL | webonyx/graphql-php ^15.0 |
| Penyimpanan Objek | AWS S3 SDK PHP ^3.300 |
| Observabilitas | Prometheus + Grafana (dashboard siap pakai) |
| Multi-bahasa | i18n 7 bahasa (CN/EN/JP/KR/DE/FR/ES) |
| Deployment | Docker Compose satu klik |

## Arsitektur Sistem

![Arsitektur Sistem](docs/diagrams/system-architecture-zh.svg)

## Alur Bisnis Inti

Alur bisnis ujung-ke-ujung yang lengkap dari pendaftaran pengguna hingga pengiriman sumber daya, termasuk pemilihan produk, pemesanan, pembayaran, pengiriman otomatis, manajemen purna jual, dan siklus perpanjangan.

![Alur Bisnis Inti](docs/diagrams/business-flowchart-zh.svg)

## Penyelesaian Multi-Mata Uang

Sistem secara native mendukung penetapan harga, pembayaran, dan penyelesaian multi-mata uang, mencakup seluruh rantai dari pengaturan mata uang pengguna, penetapan harga regional, snapshot nilai tukar hingga penerimaan pembayaran, pencatatan saldo, dan penyelesaian pemasok.

![Diagram Alur Penyelesaian Multi-Mata Uang](docs/diagrams/currency-settlement-zh.svg)

**1. Akun Saldo Multi-Mata Uang**

`user_balances` mencatat berdasarkan mata uang dengan kunci `(user_id, currency)` (indeks unik `uk_user_currency`). Saat pendaftaran, akun dua mata uang USD + CNY dibuat secara default; saldo dan saldo beku dikelola secara independen per mata uang, dan dapat diperluas ke mata uang apa pun yang didukung Stripe.

**2. Penetapan Harga Regional Multi-Mata Uang**

`product_regions` mendukung penetapan harga SKU yang sama di wilayah yang sama dalam beberapa mata uang (indeks unik `uk_sku_region_currency`). Frontend menampilkan harga sesuai mata uang pilihan pengguna; saat pemesanan, `OrderService` mengambil harga secara tepat berdasarkan `(sku_id, region_id, currency)`.

**3. Sistem Nilai Tukar**

Tugas terjadwal `ExchangeRateSync` menyinkronkan nilai tukar dari exchangerate-api dan menuliskannya ke Redis (cache TTL 30 menit). Setiap pesanan mencatat snapshot nilai tukar `exchange_rate` saat pemesanan, memastikan penyelesaian selanjutnya dapat dilacak.

**4. Pembayaran Multi-Mata Uang**

`payment_channels.currency_support` mendeklarasikan daftar putih mata uang yang didukung oleh setiap kanal pembayaran; `PaymentRouter` memfilter kanal yang tersedia secara dinamis berdasarkan mata uang / rentang jumlah / wilayah yang terlihat. Stripe PaymentIntent langsung menerima pembayaran dalam mata uang pesanan, dengan penanganan desimal bawaan untuk 16 mata uang tanpa desimal (JPY / KRW / VND, dll.), dan callback Webhook memverifikasi konsistensi jumlah dan mata uang.

**5. Penyelesaian dan Laporan**

Transaksi pembayaran (`payment_transactions`), penyelesaian pemasok (`supplier_settlements`), dan laporan pendapatan semuanya menyimpan kolom mata uang dan nilai tukar, dengan statistik ringkasan per mata uang.

## Ikhtisar Modul Fungsional

Sistem diorganisir dalam empat lapisan arsitektur: lapisan klien (integrasi 6 platform), lapisan gateway API (12 middleware), lapisan layanan bisnis (20+ modul fungsional), lapisan infrastruktur (8 komponen inti).

![Ikhtisar Modul Fungsional](docs/diagrams/module-overview-zh.svg)

## Siklus Hidup Sumber Daya

Sumber daya melalui total 6 status dari pembuatan hingga penghentian, digerakkan oleh 8 peristiwa siklus hidup, mendukung pengiriman otomatis, penangguhan dan pemulihan, pengingat kedaluwarsa, dan pembersihan penghancuran.

![Siklus Hidup Sumber Daya](docs/diagrams/resource-lifecycle-zh.svg)

## Navigasi Dokumen

| Dokumen | Deskripsi |
|------|------|
| [Dokumen Desain Arsitektur](docs/architecture.md) | Arsitektur sistem, hubungan komponen, pipeline middleware, lapisan keamanan, arsitektur data, topologi deployment |
| [Dokumen Desain Fitur](docs/features.md) | Desain fungsional terperinci untuk 21 modul, termasuk diagram alur, model data, penjelasan interaksi |
| [Dokumen Referensi API](docs/api-reference.md) | Referensi lengkap 200+ endpoint, dikelompokkan per modul, dengan contoh permintaan/respons, kode kesalahan |
| [Dokumen API Online (service)](http://localhost:8787/apidoc) | Dihasilkan otomatis oleh hg/apidoc, dikelompokkan per fungsi, mendukung debugging online |
| [Dokumen API Online (admin)](http://localhost:8788/apidoc) | Dihasilkan otomatis oleh hg/apidoc, 54 controller dalam 13 grup fungsi |
| [Desain Panel Admin](docs/admin-design.md) | Arsitektur panel Admin, integrasi paket, izin ACL, suite pengujian |
| [Dokumen API Pemasok](docs/supplier-api.md) | Referensi API pemasok (internal + eksternal), contoh SDK |
| [Daftar Deployment](docs/deployment.md) | Konfigurasi server, variabel lingkungan, Nginx, HTTPS, tugas terjadwal |
| [Laporan Tinjauan](docs/review-report-2026-08-04.md) | Laporan tinjauan ekstensi ekosistem, termasuk data statistik, pelacakan masalah, saran ekstensi |
| [Perbandingan Edisi](docs/editions.md) | Perbandingan fungsi, desain, dan arsitektur Edisi Sederhana/Standar/Lengkap |

## Struktur Direktori

```
cloud-php/
├── .claude/                    # Konfigurasi Claude Code (settings / skills)
├── .github/workflows/          # Pipeline CI/CD (cek sintaks + PHPUnit dua sisi)
├── admin/                      # Panel admin (instansi webman terpisah)
│   ├── app/                    # Kode sumber plugin (PSR-4: app\)
│   │   ├── bootstrap/          # Bootstrap proses startup (Snowflake / Encryptable / Encryption)
│   │   ├── command/            # Perintah konsol (Migrate / Rollback / Status)
│   │   ├── common/             # Kelas utilitas (Auth / Tree / Layui / Util / ExcelExport / Migration)
│   │   ├── controller/         # 54 file controller (Base / Crud base + CRUD bisnis)
│   │   ├── exception/          # Penanganan eksepsi
│   │   ├── middleware/          # Middleware kontrol akses (WafMiddleware + AccessControl)
│   │   ├── model/              # 46 model Eloquent (Base berisi Snowflake PK + Encryptable)
│   │   ├── view/               # Template tampilan (panel admin Layui)
│   │   └── functions.php       # Fungsi bantu global (hashids / encrypt / decrypt)
│   ├── api/                    # Antarmuka eksternal (PSR-4: plugin\admin\api)
│   │   ├── Auth.php            # Antarmuka autentikasi
│   │   ├── Menu.php            # Antarmuka menu
│   │   ├── Install.php         # Antarmuka instalasi
│   │   └── Middleware.php      # Antarmuka middleware
│   ├── config/                 # Konfigurasi aplikasi
│   │   ├── plugin/erikwang2013/ # Konfigurasi 6 paket erikwang2013
│   │   │   ├── snowflake-php/  # Generasi Snowflake ID
│   │   │   ├── hashids/        # Obfuskasi ID
│   │   │   ├── encryptable/    # Enkripsi tingkat kolom
│   │   │   ├── encryption/     # Enkripsi transmisi
│   │   │   ├── webman-scout/   # Sinkronisasi Elasticsearch
│   │   │   └── season/         # Bendera negara
│   │   ├── route.php           # Definisi rute
│   │   ├── middleware.php       # Konfigurasi middleware
│   │   ├── database.php        # Koneksi basis data
│   │   └── ...                 # 18 file konfigurasi
│   ├── database/migrations/    # File migrasi basis data
│   ├── tests/                  # Pengujian unit (PHPUnit 11, 286 tests / 962 assertions)
│   │   ├── HashidsTest.php     # Enkode/dekode hashids (21 tests)
│   │   ├── BaseJsonTest.php    # Enkode ID Base::json() (13 tests)
│   │   ├── CrudHashidsTest.php # Dekode input Crud (14 tests)
│   │   ├── TreeTest.php        # Struktur pohon (19 tests)
│   │   ├── AccessControlMiddlewareTest.php # Kontrol akses RBAC
│   │   ├── AdminControllersTest.php        # Regresi controller
│   │   └── support/            # Kelas bantu pengujian
│   ├── public/                 # Direktori root dokumen (aset statis)
│   ├── vendor/                 # Dependensi Composer
│   ├── .env.example            # Templat variabel lingkungan
│   ├── composer.json           # Deklarasi dependensi
│   ├── generate.php            # Generator kode
│   ├── phpunit.xml             # Konfigurasi PHPUnit
│   └── start.php               # Titik masuk startup
├── service/                    # Layanan backend (instansi webman terpisah)
│   ├── app/                    # Modul bisnis (PSR-4: App\), setiap modul berisi Controller / Model / Service dan lapisan lainnya
│   │   ├── admin/controller/   # API panel admin (15 controller: Dashboard / User / Product / Order / Payment / Supplier / Coupon / Invoice / Domain / Webhook, dll.)
│   │   ├── affiliate/          # Komisi afiliasi / bagi hasil promosi (Controller / Listener / Model / Service)
│   │   ├── billing/            # Penagihan pemakaian / tagihan (Cron / Service)
│   │   ├── captcha/controller/ # CAPTCHA klik
│   │   ├── cdn/                # Hosting sumber daya CDN (Controller / Model / Provider / Service)
│   │   ├── command/            # Perintah konsol (Migrate / Rollback / Status / DbBackup)
│   │   ├── controller/         # Controller publik (Health / Status / Help / Upload)
│   │   ├── cron/               # Tugas terjadwal (penjadwal CronRunner + ExchangeRateSync / PaymentReconcile / SupplierSettlement / ExpirationCheck / SslCertificateCheck)
│   │   ├── domain/             # Registrasi domain / manajemen DNS (Controller / Model / Service)
│   │   ├── graphql/            # API GraphQL (Mutation / Query / Schema)
│   │   ├── grpc/               # Klien gRPC kvm-server + registrasi etcd (KvmClient / EtcdRegistry)
│   │   ├── model/              # Model umum (HelpArticle / Role / Permission)
│   │   ├── monitor/            # Pemantauan sumber daya / alarm (Controller / Cron / Model / Service)
│   │   ├── notification/       # Notifikasi pesan (Controller / Model / Queue / Service)
│   │   ├── order/              # Keranjang / pesanan / kupon / faktur (Controller / Model / Service)
│   │   ├── payment/            # Perutean pembayaran / kanal Stripe (Controller / Event / Model / Service)
│   │   ├── product/            # Produk / SKU / penetapan harga regional / ulasan (Controller / Model / Service)
│   │   ├── provisioning/       # Mesin pengiriman sumber daya (Controller / Event / Listener / Model / Provider / Queue / Service)
│   │   ├── report/             # Laporan pendapatan / pemasok / regional (Controller / Service)
│   │   ├── ssl/                # Penerbitan / manajemen sertifikat SSL (Controller / Model / Service)
│   │   ├── storage/            # Sumber daya penyimpanan objek (Controller / Model / Provider / Service)
│   │   ├── supplier/           # Pendaftaran pemasok / penyelesaian / penarikan + API eksternal (Controller / Model / Service)
│   │   ├── ticket/             # Sistem tiket (Controller / Event / Listener / Model / Service)
│   │   ├── user/               # Pengguna / autentikasi / KYC / saldo / alamat (Controller / Model / Service)
│   │   ├── webhook/            # Antrean pesan Webhook (Queue)
│   │   └── websocket/          # Server WebSocket + pendengar peristiwa
│   ├── common/                 # Pustaka umum (PSR-4: Common\)
│   │   ├── auth/middleware/     # AuthMiddleware / AdminRoleMiddleware / RbacMiddleware / RoleMiddleware / SupplierApiKeyMiddleware
│   │   ├── captcha/            # Layanan CAPTCHA klik
│   │   ├── confirmation/       # Middleware konfirmasi kedua (verifikasi ulang kata sandi)
│   │   ├── encryption/middleware/ # Middleware enkripsi transmisi AES-256-GCM
│   │   ├── hashid/middleware/   # Middleware dekode otomatis permintaan Hashids + layanan enkode/dekode
│   │   ├── helper/             # Format Response (enkode hashid otomatis)
│   │   ├── http/               # Alat klien HTTP (ApiRequest)
│   │   ├── i18n/middleware/     # Middleware multi-bahasa (Locale)
│   │   ├── security/           # CORS / WAF / pembatasan frekuensi / pemblokiran geografis / mode pemeliharaan / log audit
│   │   ├── snowflake/          # Layanan generasi Snowflake ID / Eloquent HasSnowflakeId Trait
│   │   ├── version/middleware/  # Middleware versi API (validasi versi pada URL path)
│   │   ├── clientplatform/middleware/  # Middleware platform klien (identifikasi header X-Client-Platform)
│   │   ├── feature/            # Layanan sakelar fitur Feature Flags
│   │   └── webhook/            # Pendistribusi peristiwa Webhook
│   ├── config/                 # 17 file konfigurasi (route / middleware / database / redis / cron / auth / security / i18n / ...)
│   │   └── plugin/             # Konfigurasi plugin
│   │       ├── erikwang2013/   # encryptable / hashids / jwt / poster / season / webman-scout
│   │       └── webman/         # event / redis-queue
│   ├── database/migrations/    # File migrasi basis data (37 migrasi)
│   ├── i18n/                   # Sumber daya multi-bahasa (en-US / zh-CN)
│   ├── support/                # Bootstrap (Eloquent / Redis / Event / enkripsi / Snowflake ID / Hashids / Scout / MigrationRunner)
│   ├── tests/                  # Pengujian unit (PHPUnit 10, 672 tests / 1632 assertions)
│   │   ├── admin/              # ImportExport / SupplierWithdrawApprove
│   │   ├── affiliate/          # AffiliateService
│   │   ├── auth/               # JwtAuth / RbacSeed / Rbac
│   │   ├── billing/            # MeterCollector / UsageAggregator / SuspendCheck
│   │   ├── captcha/            # CaptchaService
│   │   ├── cdn/                # ResourceCdn
│   │   ├── clientplatform/     # ClientPlatformMiddleware
│   │   ├── common/             # Response / Hashid / Snowflake / Validator / LogSanitizer / Totp / ApiRequest
│   │   ├── confirmation/       # ConfirmationMiddleware
│   │   ├── cron/               # SupplierSettlement
│   │   ├── domain/             # DomainService / DomainTransfer
│   │   ├── graphql/            # Schema
│   │   ├── grpc/               # KvmClient / EtcdRegistry
│   │   ├── monitor/            # AlertEngine
│   │   ├── notification/       # NotificationDispatcher
│   │   ├── order/              # Coupon / Invoice
│   │   ├── payment/            # StripeChannel / PaymentRouter
│   │   ├── product/            # ProductService / Search / ReviewStatus
│   │   ├── provisioning/       # ProviderFactory / RetryLogic
│   │   ├── report/             # ReportService
│   │   ├── security/           # RateLimit / Maintenance / UploadSecurity
│   │   ├── ssl/                # SslCertificate
│   │   ├── storage/            # StorageBucket
│   │   ├── supplier/           # SupplierService / Settlement / Rating / Webhook
│   │   ├── ticket/             # TicketUpdatedWiring
│   │   ├── user/               # AddressController
│   │   ├── version/            # VersionMiddleware
│   │   ├── webhook/            # WebhookDispatcher / WebhookE2E
│   │   ├── websocket/          # WebSocketAuth / EventsWiring
│   │   ├── support/            # RequestMock
│   │   ├── bootstrap.php       # Bootstrap pengujian
│   │   └── TestCase.php        # Kelas dasar pengujian
│   ├── runtime/                # File runtime (log / cache)
│   ├── vendor/                 # Dependensi Composer
│   ├── .env.example            # Templat variabel lingkungan
│   ├── .env                    # Variabel lingkungan lokal (gitignore)
│   ├── composer.json           # Deklarasi dependensi
│   ├── phpunit.xml             # Konfigurasi PHPUnit
│   └── start.php               # Titik masuk startup
├── apps/
│   ├── flutter/                # Klien Flutter (iOS / macOS / Windows / Linux / Web)
│   │   ├── lib/                # Kode sumber Dart (core / features)
│   │   ├── ios/                # Proyek iOS
│   │   ├── macos/              # Proyek macOS
│   │   ├── windows/            # Proyek Windows
│   │   ├── linux/              # Proyek Linux
│   │   ├── web/                # Proyek Web
│   │   ├── test/               # Pengujian Flutter
│   │   ├── pubspec.yaml        # Deklarasi dependensi
│   │   └── analysis_options.yaml # Konfigurasi analisis statis Dart
│   └── harmonyos/              # Kerangka klien HarmonyOS
│       └── entry/src/          # Kode sumber ArkTS
├── docker/                     # Deployment Docker
│   ├── Dockerfile              # Image PHP 8.2
│   ├── docker-compose.yml      # Orkestrasi layanan
│   ├── nginx.conf              # Konfigurasi Nginx
│   └── supervisor.conf         # Proses supervisor daemon
├── infrastructure/             # Infrastruktur Rust (workspace e-cat)
│   ├── kvm-server/             # Layanan cloud milik sendiri: layanan gRPC penyediaan VM (:50051, registrasi etcd)
│   │   ├── src/                # main / grpc / driver (driver simulasi, libvirt untuk Phase 2)
│   │   ├── tests/              # Pengujian integrasi
│   │   └── Cargo.toml          # Deklarasi anggota workspace e-cat
│   └── ecat-*/                 # Crate infrastruktur e-cat (transport-grpc / registry-etcd / protos / config / data, dll.)
├── docs/                       # Dokumentasi
│   ├── admin-design.md         # Dokumen desain panel admin
│   ├── supplier-api.md         # Dokumen API pemasok
│   ├── deployment.md           # Daftar deployment
│   ├── api-test.sh             # Skrip uji asap API
│   ├── database.sql            # DDL basis data
│   ├── alipay.png / weixinpay.png  # Kode QR donasi
│   ├── diagrams/               # 18 diagram SVG arsitektur (arsitektur sistem / pipeline keamanan / diagram ER / alur bisnis / penyelesaian multi-mata uang, dll.)
│   ├── test-reports/           # Laporan pengujian (PHPUnit / Rust / API / UI + tangkapan layar)
│   └── superpowers/            # Spesifikasi desain dan rencana implementasi
│       ├── specs/              # Dokumen spesifikasi desain sistem
│       └── plans/              # Rencana implementasi bertahap Phase 0~3
├── scripts/                     # Skrip operasional (push-release.sh aturan rilis push: kenaikan versi + tag)
├── tests/k6/                    # Skrip pengujian beban k6 (asap/produk/konkurensi)
├── install.php                 # Titik masuk panduan instalasi satu klik
├── install/                    # Halaman panduan instalasi
│   └── index.php               # Aplikasi web panduan
├── install.sql                 # DDL basis data terpadu (46 tabel)
├── .gitignore
├── README.md                   # Dokumentasi proyek (Cina)
└── README_EN.md                # Dokumentasi proyek (Inggris)
```

## Memulai Cepat

### Persyaratan Lingkungan

- PHP 8.2+ (ext-json, ext-pdo, ext-pdo_mysql, ext-redis, ext-openssl)
- MySQL 8.0
- Redis 7

### Instalasi Satu Klik (Direkomendasikan)

Proyek menyediakan panduan instalasi Web; semua konfigurasi dapat diselesaikan di browser:

```bash
# 1. Instal dependensi
cd service && composer install && cd ../admin && composer install && cd ..

# 2. Mulai panduan instalasi
php install.php
# Buka browser dan akses http://localhost:8888

# 3. Selesaikan sesuai petunjuk panduan:
#    - Pemeriksaan lingkungan
#    - Konfigurasi basis data (host, port, nama database, nama pengguna, kata sandi)
#    - Pengaturan akun administrator backend (nama pengguna, kata sandi, email)
#    - Jalankan instalasi satu klik (buat tabel + tulis konfigurasi)
```

Setelah instalasi selesai, panduan secara otomatis akan:
- Membuat seluruh 46 tabel basis data (tabel manajemen `wa_*` + tabel bisnis tanpa prefiks)
- Membuat peran dan akun super administrator
- Menghasilkan file konfigurasi `service/.env` dan `admin/.env` (termasuk kunci JWT/enkripsi yang dibuat otomatis)

### Instalasi Manual

```bash
cd service

# 1. Instal dependensi
composer install

# 2. Konfigurasi variabel lingkungan
cp .env.example .env
# Edit .env untuk mengisi kata sandi basis data, kunci JWT, kunci enkripsi, dll.
# Pembuatan ENCRYPTION_MASTER_KEY: openssl rand -base64 32
# Pembuatan ENCRYPTION_KEY: echo -n "$(openssl rand -base64 16)" | base64 -w0
# Pembuatan JWT_SECRET_KEY: openssl rand -base64 32

# 3. Buat basis data dan impor
mysql -u root -p -e "CREATE DATABASE cloud_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p cloud_platform < ../install.sql

# 4. Mulai layanan (mode pengembangan)
php start.php start
# Akses http://localhost:8787
```

### Deployment Docker

```bash
# Dari direktori root proyek
cp service/.env.example .env
# Edit .env untuk mengisi berbagai kunci

docker compose -f docker/docker-compose.yml up -d
# API: http://localhost
```

### Panel Admin

```bash
cd admin

# 1. Instal dependensi
composer install

# 2. Konfigurasi variabel lingkungan
cp .env.example .env
# Jika menggunakan panduan instalasi satu klik, file ini telah dibuat otomatis

# 3. Mulai layanan (mode pengembangan)
php start.php start
# Akses http://localhost:8787/app/admin
```

### Mode Proses Daemon

```bash
php start.php start -d          # Mulai
php start.php status            # Lihat status
php start.php restart           # Mulai ulang
php start.php stop              # Hentikan
```

## Panduan Penggunaan

### Masuk

- **Portal pengguna**: buka layanan API (default `http://localhost:8787`), daftar akun lalu masuk. OAuth Google / Apple dan autentikasi dua langkah TOTP didukung
- **Panel admin**: buka `http://localhost:8787/app/admin` di browser (panel adalah instance terpisah, port 8788) dan masuk dengan akun administrator yang dibuat oleh wizard instalasi

### Fitur Umum Admin

- **Dasbor**: statistik pesanan / pendapatan / pengguna baru / sumber daya aktif hari ini, grafik tren pendapatan 30 hari, ekspor PDF
- **Pusat laporan**: laporan pesanan, peringkat produk, statistik saluran, pertumbuhan pengguna, ekspor Excel
- **Manajemen harian**: pengguna / produk / pesanan / pemasok / tiket / domain / CDN, tinjauan KYC, refund, persetujuan settlement dan penarikan
- **Konfigurasi sistem**: saluran pembayaran, akun CDN, webhook, template notifikasi, artikel bantuan, log audit

### Membangun Klien

- **Klien Flutter** (`apps/flutter/`): iOS / Android / Web / Linux / macOS / Windows. `flutter pub get` untuk dependensi, `flutter run` untuk debugging, `flutter build apk` / `flutter build ios` / `flutter build web` untuk pengemasan
- **Klien HarmonyOS** (`apps/harmonyos/`): aplikasi native ArkTS — buka proyek `entry` dengan DevEco Studio untuk build dan menjalankan

## Ikhtisar API

Antarmuka dikelompokkan per modul, termasuk contoh permintaan/respons dan kode kesalahan: [Ikhtisar API](docs/api-overview.md) (pilihan) · [Dokumen Referensi API](docs/api-reference.md) (referensi lengkap 200+ endpoint) · [Debugging online](http://localhost:8787/apidoc)

## Arsitektur Panel Admin

### Integrasi Teknologi

Panel admin adalah instansi webman terpisah yang mengintegrasikan 7 paket erikwang2013:

| Paket | Kegunaan | Cara Implementasi |
|---|------|---------|
| snowflake-php | Kunci utama terdistribusi 64-bit | Dihasilkan otomatis melalui peristiwa `Base::boot()` creating |
| hashids | Obfuskasi ID API | Enkode respons `Base::json()`, dekode permintaan `Crud::selectInput/updateInput/deleteInput` |
| encryptable | Enkripsi kolom basis data | Cast Eloquent `Encryptable`, enkripsi/dekripsi transparan untuk Admin (password/email/mobile), User (6 kolom) |
| encryption | Enkripsi transmisi API | Fungsi bantu `encrypt_data()`/`decrypt_data()` yang disediakan |
| webman-scout | Pencarian teks lengkap ES | Trait `Searchable` pada model User, sinkronisasi indeks otomatis |
| season | Emoji bendera negara | Fungsi bantu global `country_season_flag()` |
| poster-php | CAPTCHA klik | Bootstrap `CaptchaPlugin`, fungsi global `captcha_create()`/`captcha_verify()` |

### Lapisan Keamanan

```
Permintaan → Dekode Hashids (Crud::selectInput/updateInput/deleteInput)
  → Otorisasi ACL (api/Auth.php, noNeedLogin/noNeedAuth controller)
  → Pemrosesan bisnis (CRUD / peristiwa model)
  → Enkripsi kolom Encryptable (Eloquent casts set)
  → Penulisan basis data
Respons ← Enkode Hashids (Base::json → hashids_encode_ids)

Login/Registrasi: Verifikasi Captcha → Auth → Pemrosesan bisnis
```

### Aliran Data

- **Jalur tulis**: ID permintaan (hashid) → dekode menjadi int → operasi CRUD → Snowflake menghasilkan ID baru → Encryptable mengenkripsi kolom sensitif → DB
- **Jalur baca**: DB → dekripsi Encryptable → enkode ID Hashids → respons JSON

### Cakupan Pengujian

```
phpunit.xml (PHPUnit 11, 286 tests / 962 assertions)
├── HashidsTest              (21 tests) encode/decode/encode_ids
├── BaseJsonTest             (13 tests) enkode Base::json/success/fail
├── CrudHashidsTest          (14 tests) dekode input Crud (select/update/delete)
├── TreeTest                 (19 tests) struktur pohon / turunan / leluhur / simpul yatim
├── AccessControlMiddlewareTest (7 tests) belum login 401 / halaman 403 / izin
├── AdminControllersTest     (data provider) perakitan 48 controller / permukaan CRUD / jalur tampilan GET
├── UtilTest                 (17 tests) kata sandi / waktu / byte / filter input / properti kontrol
├── DictTest                 (5 tests) konversi nama kamus↔option / save/get/delete
├── ExcelExportTest          (4 tests) header / perataan JSON / nomor baris / sel kosong
└── LayuiTest                (5 tests) input / inputNumber / escape label / switch / html
```

## Pemikiran Desain

### 1. Monolit Modular

Modul dipotong secara vertikal berdasarkan domain bisnis (User / Product / Order / Payment / Provisioning / Ticket / Notification, dll.), setiap modul mengikuti lapisan MVC secara internal:

- **Controller** — lapisan HTTP, validasi parameter, memanggil Service, mengembalikan Response
- **Service** — logika bisnis, tanpa ketergantungan HTTP, dapat digunakan kembali oleh Controller dan Queue Worker
- **Model** — model data Eloquent, mendefinisikan relasi dan query scope

Modul-modul digabungkan secara longgar melalui **peristiwa** dan **antarmuka**, tidak langsung memanggil Service satu sama lain. Misalnya pembayaran selesai → peristiwa `OrderPaid` → `ProvisioningService` mengaktifkan sumber daya secara otomatis; pembuatan Ticket → peristiwa `TicketCreated` → penugasan layanan pelanggan otomatis.

### 2. Pengiriman Berbasis Peristiwa

```
Pengguna memesan → pembayaran sukses → peristiwa OrderPaid
  → ProvisioningService.handleOrderPaid()
    → buat ProvisionTask untuk setiap OrderItem (status=pending)
    → konsumen Redis Queue ProvisionWorker
      → ProviderFactory.create(task) menyelesaikan Provider
      → ProxmoxProvider.create()
        → HostSelector memilih mesin fisik paling kosong
        → ProxmoxApi membuat VM / memasang disk / mengalokasikan IP
          (Layanan penyediaan gRPC kvm-server Rust telah masuk: registrasi penemuan e-cat/etcd,
           KvmClient PHP sudah dihubungkan; driver simulasi, driver nyata libvirt untuk Phase 2)
        → buat catatan Resource / Disk
      → perbarui status Order menjadi completed
```

Kegagalan pengiriman dicoba ulang secara otomatis, strategi backoff: 1 menit → 5 menit → 15 menit → 1 jam → 6 jam → 24 jam; lebih dari 6 kali ditandai gagal dan memicu alarm.

### 3. Arsitektur Plugin Provider

Pengiriman sumber daya diabstraksikan melalui `ProviderInterface`; infrastruktur yang berbeda mengimplementasikan antarmuka yang sama:

```
ProviderInterface
  ├── ProxmoxProvider    (Proxmox VE milik sendiri)
  ├── AliyunProvider     (masa depan: Alibaba Cloud)
  ├── AwsProvider        (masa depan: AWS EC2)
  └── DomainProvider     (masa depan: registrar domain)
```

`ProviderFactory` mendaftarkan fungsi pabrik berdasarkan kunci `productType:provider`, dan menyelesaikannya secara dinamis saat runtime sesuai ProvisionTask.

### 4. Perutean Pembayaran Ganda

`PaymentRouter` secara dinamis mengembalikan kanal pembayaran yang tersedia berdasarkan jumlah pesanan / mata uang / wilayah; frontend cukup mengganti kanal untuk memulai pembayaran. Kanal pembayaran dikonfigurasi melalui tabel `PaymentChannel` (tarif, jumlah min/maks, wilayah yang terlihat), dapat naik/turun tanpa mengubah kode.

### 5. Arsitektur Keamanan

Rantai middleware global: `Version → CORS → SecurityHeaders → ClientPlatform → GeoBlock → WAF → SecurityPlugin → RateLimit → Locale → Metrics → HashidRequest → Maintenance → [Rute: Encryption → Captcha → Auth → Confirmation]`

![Pipeline Middleware Keamanan](docs/diagrams/security-middleware-zh.svg)

- **CORS** — penanganan header permintaan lintas-asal (mode daftar putih, mendukung wildcard *.example.com)
- **SecurityHeaders** — header respons keamanan (HSTS / X-Frame-Options / X-Content-Type-Options / Referrer-Policy / Permissions-Policy)
- **GeoBlock** — pemblokiran geografis (memblokir akses dari negara tertentu sesuai GEO_BLOCKED_COUNTRIES, berbasis GeoIP2)
- **WAF** — 8 kategori 45+ aturan (SQL injection/XSS/injeksi perintah/inklusi file/injeksi header/SSRF/injeksi NoSQL/redirect terbuka) + batas ukuran permintaan + validasi Content-Type (pemindaian injeksi nilai pada query/body/UA, path hanya memeriksa traversal path)
- **Security Plugin** — 31 jenis deteksi serangan (XSS/SQL injection/injeksi perintah/SSRF/deserialisasi/serangan JWT/serangan Host header/smuggling permintaan/injeksi GraphQL/kebocoran data sensitif, dll.), daftar putih IP + pemblokiran otomatis daftar hitam IP
- **Locale** — mengurai Accept-Language, mengatur bahasa
- **HashidRequest** — otomatis mendekode string hashid dalam permintaan menjadi ID integer asli
- **Version** — memvalidasi segmen versi pada URL path (mis. `/api/v1/`); versi tidak didukung mengembalikan `400`
- **ClientPlatform** — memvalidasi header `X-Client-Platform`, mengidentifikasi platform sistem operasi klien (iPadOS/macOS/Windows/Linux/iOS/Android/HarmonyOS/Web)
- **Encryption** — enkripsi transmisi AES-256-GCM (antarmuka autentikasi dan panel admin), mencegah penyadapan dan manipulasi man-in-the-middle
- **Captcha** — CAPTCHA klik, diverifikasi sebelum login/registrasi (gambar GD + penyimpanan Redis, kunci sekali pakai, masa berlaku 300 detik, batas 3 kali percobaan)
- **Auth** — autentikasi JWT HS256, Access Token 15 menit, Refresh Token 30 hari, daftar hitam Redis
- **Confirmation** — operasi sensitif (pembayaran/penghapusan/refund/persetujuan, dll.) memerlukan verifikasi ulang kata sandi; 5 kali gagal mengunci selama 15 menit
- **Pembatasan frekuensi** — default 60 kali/menit, login 5 kali/menit, registrasi 3 kali/menit, pembayaran 10 kali/menit
- **Log audit** — semua operasi sensitif ditulis ke basis data audit terpisah

### 6. Keamanan Data

**Strategi enkripsi berlapis:**

| Lapisan | Teknologi | Deskripsi |
|------|------|------|
| Lapisan transmisi | AES-256-GCM | Enkripsi badan permintaan/respons API, enkripsi otentikasi GCM mencegah manipulasi |
| Lapisan kolom | AES-256-CBC | Enkripsi/dekripsi otomatis kolom sensitif model, IV acak CBC tidak membocorkan pola nilai yang sama |
| Lapisan kunci utama | Hashids | Obfuskasi ID eksternal menjadi string 12 karakter, menyembunyikan skala data sebenarnya |

**Enkripsi kolom sensitif:** 14 kolom dari 7 model menggunakan `Encryptable::class` untuk enkripsi/dekripsi otomatis —— `User(email, phone, password_hash)`, `UserKyc(id_number, real_name)`, `UserAddress(phone, address)`, `Supplier(contact_name, phone, email)`, `HostMachine(api_token)`, `PaymentChannel(api_key, webhook_secret)`, `RefreshToken(token_hash, device_fingerprint)`.

**Manajemen kunci:** Enkripsi transmisi dan enkripsi kolom menggunakan kunci independen yang berbeda (`ENCRYPTION_MASTER_KEY` vs `ENCRYPTION_KEY`), mendukung daftar kunci lama (`ENCRYPTION_PREVIOUS_KEYS`) untuk rotasi kunci tanpa waktu henti.

### 7. Generasi ID Terdistribusi

Menggunakan algoritma Twitter Snowflake untuk menghasilkan ID unik global 64-bit: `timestamp(41b) | datacenter(5b) | worker(5b) | sequence(12b)`. Semua 46 model Eloquent secara otomatis menghasilkan Snowflake ID dalam peristiwa `creating`, tanpa ketergantungan auto-increment basis data, dan secara native mendukung pemecahan tabel/database.

### 8. Multi-bahasa (i18n)

**Penguraian otomatis oleh middleware global:**
- `LocaleMiddleware` membaca header `Accept-Language`, otomatis mengatur bahasa saat ini
- Mendukung fallback bahasa: bahasa yang tidak didukung → `fallback_locale` (en-US)

**Terjemahan teks statis:**
- `I18n::trans('auth.login_success')` → `登录成功` / `Login successful`
- File terjemahan: `i18n/{locale}/messages.php`, 120 entri mencakup seluruh 15 modul
- Mendukung penggantian parameter: `I18n::trans('validation.required', ['field' => '邮箱'])`

**Kolom JSON multi-bahasa:**
- Nama / deskripsi produk disimpan sebagai `{"zh-CN":"云服务器","en-US":"Cloud Server"}`
- `I18n::translateField($json)` otomatis mengambil nilai sesuai bahasa saat ini
- Templat notifikasi juga mendukung multi-bahasa, dikirim sesuai bahasa pilihan pengguna

### 9. Pencarian Teks Lengkap

4 model — produk, pengguna, pesanan, tiket — terhubung ke pencarian melalui Trait `Erikwang2013\WebmanScout\Searchable`. Driver default `database` (penulisan no-op, pencarian menggunakan SQL LIKE downgrade, tanpa ketergantungan ES); setelah mengonfigurasi driver Elasticsearch, indeks disinkronkan otomatis, mendukung:

- **Segmentasi kata multi-bahasa** — IK Analyzer (ik_max_word / ik_smart)
- **Pencarian teks lengkap Cina** — nama produk, deskripsi, judul tiket
- **Pemfilteran presisi** — filter berdasarkan status, kategori, rentang harga, rentang waktu
- **Sinkronisasi batch** — `php webman scout:import "App\Product\Model\Product"`
- **Contoh pencarian** — `Product::search('VPS服务器')->where('status', 'published')->get()`

### 10. Bendera Negara

Menyediakan dukungan emoji bendera negara global melalui `erikwang2013/season`:

- `country_season_flag('CN')` → 🇨🇳, `country_season_flag('JP')` → 🇯🇵
- Otomatis mengenali belahan bumi utara/selatan, mengembalikan musim yang sesuai (Cina/Inggris)
- Mendukung nama musim terlokalisasi dalam 30+ bahasa
- Pemilihan wilayah frontend, tampilan kewarganegaraan pengguna, dan skenario lain dapat langsung dipanggil

## Daftar Tugas

- [x] DDL basis data (`install.sql`, 46 tabel, tabel manajemen wa_* + tabel bisnis tanpa prefiks, kunci utama non-auto-increment BigInt)
- [x] Generasi Snowflake ID (`erikwang2013/snowflake-php`)
- [x] Autentikasi JWT (`erikwang2013/jwt-webman`, HS256 + daftar hitam Redis)
- [x] Obfuskasi ID API (`erikwang2013/hashids`, dekode otomatis permintaan + enkode otomatis respons)
- [x] Enkripsi transmisi (`erikwang2013/encryption`, middleware AES-256-GCM)
- [x] Enkripsi tingkat kolom (`erikwang2013/encryptable`, enkripsi/dekripsi otomatis kolom sensitif)
- [x] Pencarian teks lengkap (`erikwang2013/webman-scout`, driver database default SQL LIKE downgrade, opsional Elasticsearch + segmentasi IK)
- [x] Bendera negara (`erikwang2013/season`, Unicode flag emoji)
- [x] Panel admin (`admin/`, webman-admin + integrasi 7 paket, 286 pengujian unit)
- [x] Tinjauan kode (2 perbaikan kritis + 4 perbaikan penting telah diterapkan)
- [x] Ekspor Excel (PhpSpreadsheet ^2.0, Crud/Table panel admin + API manajemen sisi server)
- [x] Visualisasi dashboard (grafik ECharts + kartu statistik animasi + panel informasi sistem)
- [x] Ekspor PDF (html2canvas + jsPDF, ekspor tangkapan layar dashboard)
- [x] Skrip migrasi basis data (`install.sql` DDL terpadu, perintah `php webman migrate`)
- [x] Integrasi Stripe nyata (SDK stripe-php, PaymentIntent + verifikasi tanda tangan Webhook)
- [x] Integrasi SMS Twilio nyata (twilio/sdk, termasuk penanganan kegagalan pengiriman)
- [x] Integrasi push FCM nyata (kreait/firebase-php, termasuk pembersihan token tidak valid)
- [x] CAPTCHA klik (erikwang2013/poster-php, verifikasi operasi sensitif login/registrasi)
- [x] Konfirmasi kedua (ConfirmationMiddleware, verifikasi ulang kata sandi operasi sensitif, 5 kali gagal mengunci 15 menit)
- [x] Pengujian unit sisi server (672 tests / 1632 assertions, 15 skipped)
- [x] Identifikasi platform klien (ClientPlatformMiddleware, header X-Client-Platform mendukung 8 platform)
- [x] Peningkatan keamanan WAF (8 kategori 45+ aturan: SQL injection/XSS/injeksi perintah/inklusi file/injeksi header/SSRF/injeksi NoSQL/redirect terbuka + batas ukuran permintaan + validasi Content-Type)
- [x] Security Plugin (erikwang2013/security-php, 31 jenis deteksi serangan + pemblokiran otomatis daftar hitam IP + rotasi log)
- [x] Middleware WAF panel Admin
- [x] Pemisahan baca/tulis MySQL (koneksi read/write Eloquent + sticky)
- [x] Lapisan cache multi-level Redis (CacheService: produk/wilayah/nilai tukar/TLD/pengguna, TTL + invalidasi aktif + pemanasan)
- [x] Kompresi respons Nginx + optimasi koneksi (gzip/proxy_buffering/keep-alive/limit_req+limit_conn)
- [x] Saran indeks basis data (13 indeks komposit/penutup yang direkomendasikan)
- [x] Pemantauan eksepsi Sentry (SentryBootstrap + callback desensitisasi before_send)
- [x] Sakelar fitur Feature Flags (override dinamis Redis + API panel admin)
- [x] API eksternal pemasok (autentikasi API Key + endpoint pesanan/sumber daya/penyelesaian/penarikan)
- [x] Push real-time WebSocket (WebSocket native Workerman + pendengar peristiwa pesanan/tiket)
- [x] Skrip pengujian beban k6 (uji asap/produk/konkurensi)
- [x] Pipeline CI/CD (GitHub Actions, cek sintaks + PHPUnit dua sisi + validasi Composer)
- [x] Panduan instalasi satu klik (UI Web, pemeriksaan lingkungan + konfigurasi basis data + pembuatan admin + pembuatan .env otomatis)

## Proyek Open Source Butuh Dukungan

| WeChat | Alipay |
|:---:|:---:|
| ![WeChat](./docs/weixinpay.png "微信") | ![Alipay](./docs/alipay.png "支付宝") |

### Transfer Global (Transfer Bank)

**Informasi Penerima**

- Nama penerima: WANG KEXUN
- Nomor akun penerima: 881015918251

**Bank Penerima (ZA Bank)**

- SWIFT Code: AABLHKHHXXX
- Nama bank: ZA Bank Limited
- Nomor bank: 387
- Alamat bank: Core F, Cyberport 3, 100 Cyberport Road, Hong Kong

**Bank Agen Transfer Lintas Batas (jika diperlukan)**

> Perlu diperhatikan, ini adalah informasi bank agen transfer lintas batas (bank perantara), bukan informasi bank penerima. Silakan tanyakan ke bank pengirim apakah informasi bank agen transfer lintas batas diperlukan.

- Bank agen untuk transfer masuk HKD, CNY, dan USD adalah **Citibank**:
  - Nama bank: Citibank N.A. Hong Kong
  - SWIFT Code: CITIHKHXXXX
  - Nomor bank: 006
  - Nama cabang: Hong Kong Branch
  - Nomor cabang: 391
  - Alamat bank: Citibank Tower, Citibank Plaza, 3 Garden Road, Central, Hong Kong
- Bank agen untuk transfer masuk mata uang lainnya adalah **BNY Mellon**:
  - Nama bank: THE BANK OF NEW YORK MELLON
  - SWIFT Code: IRVTUS3NXXX
  - Alamat bank: THE BANK OF NEW YORK MELLON, 240 GREENWICH STREET, NEW YORK, United States

### Donasi Kripto (Crypto Donation)

Jika proyek ini membantu Anda, silakan pindai kode QR untuk berdonasi, terima kasih!

| <img src="../../coin/1.jpg" width="200" alt="BNB Smart Chain (BEP20)"><br>**BNB Smart Chain (BEP20)**<br>`0x355d429f97511897ccb4e271ec888205f9ab6629` | <img src="../../coin/2.jpg" width="200" alt="Tron (TRC20)"><br>**Tron (TRC20)**<br>`TEdDHWLajt1XvqtPDWmQctdrJaC3pzZZzz` |
| <img src="../../coin/3.jpg" width="200" alt="Ethereum (ERC20)"><br>**Ethereum (ERC20)**<br>`0x355d429f97511897ccb4e271ec888205f9ab6629` | <img src="../../coin/4.jpg" width="200" alt="Aptos"><br>**Aptos**<br>`0x836e3780edfc3f7b2372b39e2a1a3a5d7adfaccd96c726f21cfde1b50dd68030` |
| <img src="../../coin/5.jpg" width="200" alt="Plasma"><br>**Plasma**<br>`0x355d429f97511897ccb4e271ec888205f9ab6629` | <img src="../../coin/6.jpg" width="200" alt="Polygon POS"><br>**Polygon POS**<br>`0x355d429f97511897ccb4e271ec888205f9ab6629` |
| <img src="../../coin/7.jpg" width="200" alt="Solana"><br>**Solana**<br>`2hfhboHdmdrYsY25XfQSsEWxq5ip4EQsR7f4AzSRMUyr` | <img src="../../coin/8.jpg" width="200" alt="The Open Network (TON)"><br>**The Open Network (TON)**<br>`UQB9kFQohzmXUir9QSSZq01iwl9aQZIDdBpNmDklljRtCoGK` |
| <img src="../../coin/9.jpg" width="200" alt="Arbitrum One"><br>**Arbitrum One**<br>`0x355d429f97511897ccb4e271ec888205f9ab6629` | <img src="../../coin/10.jpg" width="200" alt="AVAX C-Chain"><br>**AVAX C-Chain**<br>`0x355d429f97511897ccb4e271ec888205f9ab6629` |

---

## License

Edisi Sederhana — MIT License | Edisi Standar/Lengkap — Proprietary
