# Laporan Audit CloudPlatform (Putaran Ketiga, 2026-08-06)

> Cakupan: pengujian nyata menyeluruh (mulai layanan + uji asap) + pemeriksaan kode mendalam + pemeriksaan kelengkapan konfigurasi ekologis/keamanan.
> Putaran ini maju dari "dapat dibaca statis" ke "**dapat dijalankan**": perbaiki 5 P0 level startup dan 3 P0/P1 level runtime, layanan lolos uji asap dengan rantai middleware lengkap.
> Garis dasar pengujian: service **316/316 lulus (502 asersi)**; admin **67/67 lulus (124 asersi)**.

---

## 1. Daftar Perbaikan Putaran Ini (semua sudah diverifikasi nyata)

### P0 — Level startup (worker crash / seluruh situs tidak tersedia)

| # | Masalah | Akar Penyebab | Perbaikan |
|---|------|------|------|
| 1 | `A facade root has not been set` → crash startup | bootstrap tidak mengatur container untuk Illuminate Facade | `Facade::setFacadeApplication($capsule->getContainer())` (bootstrap.php:149) |
| 2 | `Target class [events] does not exist` | pendengar peristiwa menggunakan Event Facade, tetapi container tidak memiliki layanan events | Ganti ke instansi `Dispatcher`: `$capsule->setEventDispatcher($dispatcher)` + `$dispatcher->listen()` (3 pendengar) |
| 3 | `Class support\SentryBootstrap not found` | composer.json psr-4 kurang pemetaan `support\` | Tambah `"support\\": "support/"` + dump-autoload |
| 4 | `ENCRYPTION_MASTER_KEY` kosong → layanan enkripsi crash | nilai .env kosong (phpdotenv createUnsafeMutable menimpa injeksi) | Buat kunci base64 32 byte dan tulis ke .env |
| 5 | Semua rute `/api/*` 404 | `ApiRequest::path()` menulis ulang `/api/xxx` menjadi `/api/v1/xxx`, sedangkan registrasi rute tanpa prefiks versi | Hapus logika penulisan ulang, path dipertahankan apa adanya (validasi versi dilakukan VersionMiddleware berdasarkan header X-Api-Version) |
| 6 | `Class "ErikJwt\JWTFactory" not found` | menggunakan namespace `ErikJwt\` yang tidak ada | Ganti ke namespace nyata dalam paket `Erikwang2013\Jwt\*` |
| 7 | `config('plugin.erikwang2013.jwt.jwt')` mengembalikan null → kesalahan tipe `createFromConfig()` | webman `Config::loadFromDir` mensyaratkan direktori plugin memiliki `app.php` (jika tidak seluruh direktori dilewati); direktori plugin jwt hilang | Tambah `config/plugin/erikwang2013/jwt/app.php` (`'enable' => true`, konsisten dengan templat vendor) |

### P0 — Level runtime (permintaan pertama langsung 500)

| # | Masalah | Akar Penyebab | Perbaikan |
|---|------|------|------|
| 8 | `Non-static method Redis::get() called statically` | RateLimitMiddleware memanggil statis ekstensi redis `\Redis::get()` | Ganti ke `\support\Redis::get/setex/incr` |
| 9 | `Class support\Redis not found` | `support\Redis` milik lapisan kerangka webman (paket webman/webman), proyek ini hanya memasang framework sehingga hilang | Buat baru `support/Redis.php` (bawahnya menggunakan illuminate/redis yang ada + config/redis.php) |
| 10 | `Illuminate\Support\Facades\Redis::*` di AuthController diselesaikan menjadi **instansi phpredis telanjang** (tidak terhubung) → "server went away" | container tanpa binding `redis`, autowiring fallback ke kelas `Redis` | bootstrap mendaftar `$container->singleton('redis', fn() => support\Redis::manager())` |
| 11 | `Call to undefined function storage_path()` | `storage_path()` milik helper kerangka, proyek ini hilang | bootstrap menambah helper (`base_path()/storage`, guard function_exists) |

### P1 — Validasi batas

| # | Masalah | Perbaikan |
|---|------|------|
| 12 | `/api/auth/refresh` tanpa refresh_token → TypeError 500 | AuthController::refresh tambah validasi `is_string` → 422 |

### Pemulihan status sementara

- `config/server.php` (8787), `config/process.php` (9100/8282), `config/middleware.php` (rantai 11 lapisan lengkap) sudah dipulihkan dari git
- error_log debug `[AUDIT]` di bootstrap.php sudah dihapus

---

## 2. Hasil Uji Asap (rantai middleware lengkap, port 8787)

| Endpoint | Hasil | Keterangan |
|------|------|------|
| GET /health | 200 | healthy + version |
| GET /health/live | 200 | alive |
| POST /api/captcha/create | 200 | mengembalikan gambar CAPTCHA klik |
| POST /api/auth/login (tanpa captcha) | 422 | validasi captcha berlaku |
| POST /api/auth/register (param kosong) | 422 | validasi kolom berlaku |
| POST /api/auth/refresh (tanpa token) | 422 | item perbaikan putaran ini |
| POST /api/auth/forgot-password | 500 (DB menolak koneksi) | **kesenjangan lingkungan**: .env kurang DB_PASSWORD, lihat §4 |
| GET dengan X-Api-Version: v99 | 400 | VersionMiddleware berlaku |
| GET /api/nonexistent | 404 | halaman 404 normal |

Jalur Redis (CAPTCHA, batas frekuensi, penyimpanan daftar hitam JWT) semuanya teruji dapat digunakan.

---

## 3. Pemeriksaan Perlindungan Keamanan

### Sudah Memenuhi ✓

- **Manajemen kunci**: seluruh proyek tanpa kunci/sandi yang di-hardcode (scan grep); semua kunci melalui `getenv()`; .env sudah gitignore
- **SQL injection**: tanpa SQL rangkaian string; semuanya melalui query builder Eloquent
- **Validasi input**: daftar putih type unggah + sniffing konten finfo + batas ukuran per jenis; validasi level kolom endpoint auth
- **Batas frekuensi**: endpoint sensitif publik tercakup penuh (login 5/min, register 3/min, sms 5/h, captcha 30/60s, oauth 10/60s, password_reset 3/5min), default 60/min
- **JWT**: HS256 + kunci 32 byte; access/refresh terpisah; validasi type; daftar hitam Redis (validasi per jti di basis data); TOTP wajib + penguncian kegagalan
- **CORS**: daftar putih Origin (`CORS_ALLOWED_ORIGINS`), tanpa wildcard, tanpa header kredensial
- **Header keamanan**: nosniff / X-Frame-Options / Referrer-Policy / Permissions-Policy / HSTS (sakelar env)
- **Anti-enumerasi**: forgot-password mengembalikan pesan sukses konsisten untuk pengguna yang tidak ada

### Saran (prioritas rendah, tidak diubah)

| Item | Keterangan |
|----|------|
| Kurang header CSP | Seluruh situs belum mengonfigurasi Content-Security-Policy; risiko skenario JSON API rendah, disarankan tambah strategi level `default-src 'none'` di SecurityHeadersMiddleware |
| Performa WAF | WafMiddleware setiap permintaan `file_get_contents('php://input')` membaca body penuh untuk scan (31 pola), ada overhead memori/CPU pada lalu lintas tinggi, disarankan hanya baca body saat POST/PUT dan Content-Type cocok |
| HealthController `shell_exec('git rev-parse')` | setiap permintaan health memunculkan subproses; produksi disarankan hanya pakai env `APP_VERSION`, shell hanya fallback pengembangan lokal |
| ~~TOCTOU RateLimit~~ | ~~check-then-set tidak atomik~~ **sudah diperbaiki (2026-08-07):** diganti `INCR` atomik + `EXPIRE` pertama, lihat §7-6 |
| X-XSS-Protection | header usang, dipertahankan tidak berbahaya; dapat dihapus setelah CSP selesai |

---

## 4. Kesenjangan Lingkungan (bukan masalah kode, perlu dilengkapi tim operasional)

1. **`.env` kurang `DB_PASSWORD`** (satu-satunya item pemblokir): docker-compose membuat app_user dengan `${DB_PASSWORD}`, kunci ini hilang di .env lokal → semua endpoint DB 500. `DB_PASSWORD` sudah didefinisikan di `.env.example`, merupakan kredensial deployment, perlu dimasukkan pengguna ke `.env`.
2. **9100 ditempati proses dart lokal**: kegagalan bind port default proses metrics akan **memblokir start seluruh grup** (webman melakukan pemeriksaan awal semua port sebelum mulai). Sudah ada solusi memutar persisten: `.env` ditulis `METRICS_PORT=9199` (2026-08-07). Setelah dart melepas 9100 dapat dikembalikan ke default.
3. **composer validate fatal** (pihak ketiga): plugin composer `erikwang2013/security-php` konflik eval dengan composer sendiri (`isLaravel()` deklarasi ganda), tidak terkait kode proyek ini; langkah `composer validate --strict` di CI dapat gagal karenanya, disarankan langkah CI tersebut tambah continue-on-error atau lewati paket service.
4. 8787 yang tercatat putaran sebelumnya ditempati erp-php sudah teratasi (putaran ini teruji dapat bind).

---

## 5. Pemeriksaan Konfigurasi Ekologis

| Item | Hasil |
|----|------|
| CI (.github/workflows/ci.yml) | Lengkap: pemeriksaan sintaks PHP + pengujian admin/service (matriks PHP 8.2/8.3) + composer validate |
| Migrasi | 30 file migration |
| Docker | compose (MySQL+Redis+app), Dockerfile, nginx.conf, prometheus, grafana, supervisor (nginx+webman) |
| Pemantauan | MetricsServer (port Prometheus terpisah) + proses websocket (process.php) |
| Uji beban | tests/k6 (smoke/products/concurrent) |
| .env.example | kunci lebih lengkap dari .env (OAuth/sakelar Feature dll. tercakup); .env tanpa kunci superset |
| composer audit | tanpa kerentanan keamanan; 1 paket usang doctrine/annotations (dependensi hg/apidoc, dievaluasi dipertahankan) |
| Antrean/asinkron | webman/redis-queue sudah terpasang; notifikasi melalui NotificationDispatcher |

---

## 6. Saran Tersisa (iterasi berikutnya)

1. **Header CSP** (lihat §3)
2. **Optimasi pembacaan body WAF** (lihat §3)
3. **Uji ulang jalur lengkap DB setelah DB_PASSWORD dilengkapi** (alur nyata register→login→refresh→logout + verifikasi invalidasi daftar hitam JWT)
4. ~~**supervisor tanpa proses cron**: tugas terjadwal seperti Billing\Cron\SuspendCheck tidak memiliki entri daemon~~ **sudah diselesaikan (2026-08-07):** tambah proses `App\Cron\CronRunner` (mengevaluasi ekspresi 5 kolom config/cron.php setiap menit), dan daftarkan proses `queue_consumer` untuk mengonsumsi antrean provisioning/notification; dua registrasi tidak valid di cron.php yang menunjuk file skrip diganti metode callable `ResourceMonitor`
5. **Langkah CI composer-validate**: karena konflik plugin pihak ketiga, disarankan tambah toleransi kesalahan (lihat §4-3)

---

## 7. Perbaikan Tambahan Putaran Keempat (2026-08-07)

1. **Atomisitas penagihan (P0 finansial)**: `BillingEngine::runDaily()` membungkus transaksi per sumber daya, pengurangan saldo/suspend/penandaan peristiwa dikomit dalam transaksi yang sama; `StripeChannel::confirmPayment()` menggunakan `UPDATE ... WHERE status='pending'` preemptif atomik + kunci baris pesanan, mencegah pencatatan ganda webhook.
2. **Idempotensi konkurensi (P0/P1)**: `AffiliateService::requestPayout()` kunci baris + penarikan pending yang sudah ada langsung dikembalikan; `SupplierSettlement` (cron dan `generateSettlement`) cek duplikat per pemasok+periode.
3. **Kebenaran data (P1)**: `MeterCollector` perbaiki `$resource->first()` kueri seluruh tabel tak terduga; `ExchangeRateSync` tambah timeout 10 detik.
4. **Performa (P2)**: 30 kueri SUM Dashboard digabung menjadi satu GROUP BY; `CacheService::forgetPattern()` KEYS→kursor SCAN; paket bahasa `I18n` di-cache per proses per locale; `ImportExport` impor seluruh transaksi; `BillingEngine` prefetch pemetaan tarif menghilangkan N+1.
5. **Keamanan (P1)**: `InternalTokenMiddleware` menggunakan `getRemoteIp()` mencegah pemalsuan XFF; registrasi Webhook menolak alamat jaringan privat (SSRF); `JwtAuth` kunci kosong fail-fast; `DbBackupCommand` kata sandi ganti `MYSQL_PWD` mencegah kebocoran `ps`; ekspor CSV/Excel cegah injeksi formula; API eksternal pemasok dipasang batas frekuensi `supplier_api`.
6. **Infrastruktur (P2)**: `RateLimitMiddleware` INCR atomik (hilangkan TOCTOU); `MetricsServer` perbaiki loop crash tipe `onMessage`; `HealthController` pool koneksi Redis; pasang `symfony/mailer ^6.4` (EmailSender semula ranjau tersembunyi); koreksi namespace `EncryptableBootstrap` sisi admin.

---

## 8. Perbaikan Tambahan Putaran Kelima (2026-08-07)

1. **Pengiriman otomatis tersambung (P0)**: `ProvisioningService::handleOrderPaid` setelah membuat tugas pengiriman mengirim ke antrean `provisioning`; `process.php` mendaftarkan proses `queue_consumer` (memindai semua implementasi `Webman\RedisQueue\Consumer` di app/).
2. **Tugas terjadwal dapat dieksekusi (P0)**: tambah proses `App\Cron\CronRunner` (mengevaluasi ekspresi 5 kolom config/cron.php setiap menit, mendukung sintaks `*/n`/`,`/`-`); dua registrasi tidak valid di cron.php yang menunjuk file skrip (bukan kelas) diganti metode callable `ResourceMonitor::collectAllMetrics`/`checkSslCertificates`, dan hapus registrasi checkExpirations yang duplikat dengan ExpirationCheck.
3. **Kelas notifikasi tidak ada (P0)**: 4 tempat `\Common\Notification\NotificationDispatcher::send()` (kelas tidak ada) di AuthService/AuthController/ExpirationCheck diseragamkan menjadi `\App\Notification\Service\NotificationDispatcher::dispatch($userId, ...)`.
4. **Penyatuan tiga sistem nama tabel (P0)**: 39 tabel bisnis `erik_*` di install.sql diubah tanpa prefiks (konsisten dengan penamaan default Eloquent dan migrations), tabel manajemen `wa_*` dipertahankan; panduan instalasi (install/index.php) diubah menjadi "tulis .env → subproses menjalankan service migrations (30 file migration) → install.sql (IF NOT EXISTS melewati tabel yang sudah dibuat)", setelah instalasi tabel basis data lengkap.
5. **Grup P1/P2 (diselesaikan subagen, 316 test terverifikasi lulus)**: perakitan peristiwa, penulisan nilai tukar per mata uang, `Response::error` parameter tunggal ditambah 400 (10 tempat), eksekutor refund (RefundService baru), idempotensi persetujuan, audit operasi sensitif admin, penghapusan noNeedAuth, batas frekuensi API manajemen, WebSocket ganti Redis Pub/Sub, bug kueri SSL, mata uang/tunggakan, desensitisasi kredensial, penerapan kupon, validasi jumlah, toleransi kesalahan CI, penerusan ES_HOST.

**Garis dasar pengujian**: service 316/316 (502 asersi), admin 67/67 (124 asersi) semua hijau; semua file yang diubah lulus `php -l`.

## Kesimpulan

Putaran ini maju dari "kode dapat dibaca" ke "**dapat dimulai, dapat dijalankan**": 8 kegagalan level P0 semuanya diperbaiki dan teruji nyata, 316 test semua hijau, uji asap rantai middleware lengkap lulus. Pemblokir tersisa hanya satu kesenjangan lingkungan (DB_PASSWORD), setelah dilengkapi dapat diverifikasi jalur lengkap. Putaran keempat (2026-08-07) lebih lanjut menyelesaikan atomisitas penagihan, idempotensi konkurensi, batas frekuensi/perlindungan injeksi dan 20+ penguatan lainnya; putaran kelima (2026-08-07) menyelesaikan pengiriman otomatis, penjadwalan cron, kelas notifikasi, sistem nama tabel 4 P0 dan seluruh grup P1/P2, pengujian tetap semua hijau.
