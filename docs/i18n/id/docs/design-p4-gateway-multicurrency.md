# Desain P4.1 + P4.2: Gateway API Independen/Batas Frekuensi Terpadu + Konsistensi Jalur Lengkap Multi-Mata Uang

> Versi: 2026-08-17 v1 ｜ Produksi arsitek, untuk implementasi gateway-impl / multicurrency-impl, ditinjau ulang oleh reviewer-gate
> Dasar: docs/team-plan.md v2 Phase 4, docs/architecture.md, pembacaan nyata kode yang ada

---

## P4.1 Gateway API Independen + Batas Frekuensi Terpadu

### Status Saat Ini (dikonfirmasi dengan pembacaan nyata)

| Lapisan | Status Saat Ini |
|----|------|
| Gateway tepi | docker/nginx.conf menangani gateway L7 service: `limit_req_zone api 10r/s` (batas frekuensi global), proxy_pass 8787 (service), 8282 (ws). **admin adalah container terpisah** (Dockerfile admin target, nginx-admin.conf listen 8788 proxy 8788), **tanpa limit_req** |
| Batas frekuensi aplikasi | `service/common/security/RateLimitMiddleware.php` sudah ada: Redis INCR+expire jendela tetap, **hanya per-IP**, memilih aturan berdasarkan `ROUTE_MAP`, dipasang pada **rute eksplisit** (route.php sekitar ~12 titik) |
| Konfigurasi aturan | `config/security.php rate_limits`: default/login/register/password_reset/oauth/captcha/sms/pay/upload/supplier_api/graphql, semuanya berisi rate/burst/per, tetapi **kolom burst saat ini tidak digunakan** |
| Middleware global | kunci `''` `config/middleware.php` sudah mendukung berlaku untuk semua rute (WAF/GeoBlock/Security dll. 10 item di sini) |
| Kesenjangan | `/graphql` (dua rute: public + authenticated) **tanpa batas frekuensi apa pun**; batas per-token tidak ada; respons 429 tanpa header `Retry-After`; webhook tanpa aturan pengecualian/khusus |

### Keputusan

**D1: Tidak membuat proses gateway independen baru.** nginx adalah gateway (tepi jaringan + batas frekuensi + pembagian rute), batas frekuensi terpadu dilakukan di dalam webman.
- Alasan: container gateway independen memerlukan dependensi baru/topologi deployment baru/autentikasi ganda, pada skala instansi tunggal saat ini itu adalah desain berlebihan;
- Trade-off: tidak dapat melakukan batas frekuensi yang berbeda per-token/per-rute di lapisan gateway (nginx hanya memiliki segmen per-IP). Diferensiasi dilengkapi di lapisan aplikasi, lapisan nginx hanya mempertahankan fallback IP kasar (10r/s saat ini dinaikkan ke 100r/s agar tidak melukai bisnis, dikembalikan ke ambang demo saat verifikasi k6).
- Jalur evolusi: jika di masa depan ada multi-instansi/layanan multi, pembatas frekuensi global `config/middleware.php` cukup dipindahkan apa adanya ke layanan gateway independen, middleware tidak peka terhadap bentuk deployment.

**D2: Batas frekuensi terpadu = middleware global + ember dua dimensi (per-IP + per-token).**
- Lepas `RateLimitMiddleware` dari rute eksplisit (route.php sebenarnya ~12 titik, ikuti hasil grep), pasang ke daftar global `''` `config/middleware.php` (setelah WAF, sebelum middleware bisnis), **secara alami mencakup semua rute dalam aplikasi (termasuk dua rute /graphql)**.
- **Semantik ember (jelas, cegah memutar):** `ratelimit:ip:{realIp}:{rule}` dan `ratelimit:tok:{sha256(token)}:{rule}` dua ember dihitung independen, **ember mana pun melampaui batas = 429 (OR)**. Dilarang diimplementasikan dengan AND — dengan AND, ganti IP bisa memutar ember per-IP, ganti token bisa memutar ember per-token.
- **Daftar pengecualian:** `/health*` (probe pemantauan) dan `/api/payments/webhook/stripe` (verifikasi tanda tangan adalah garis pertahanan nyata + Stripe 429 backoff retry otomatis + fallback kasar nginx 100r/s tetap berlaku; batas frekuensi tidak menambah keamanan, hanya berisiko kehilangan peristiwa/keterlambatan masuk saldo). Semua rute lainnya wajib dibatasi.
- Respons: `HTTP 429` + header `Retry-After` (sisa jendela dua ember ambil **max**, jendela tetap gunakan `PTTL` Redis untuk sisa presisi) + body `{code:429, message, retry_after}` (selaras dengan `Response::error` yang ada).
- Burst: aktifkan kolom burst — `rate` adalah kuota stabil dalam jendela, `burst` adalah kredit yang dapat dipinjam. Diimplementasikan sebagai batas atas penghitung Redis key `rate + burst` (pinjaman dalam jendela tetap), tanpa perlu sliding window (ponytail: jendela tetap memiliki pembesaran 2x jendela di batas, per-IP cukup untuk penyalahgunaan mesin tunggal; jika ingin lebih ketat baru ganti sliding window).
- Pemetaan rute→aturan: pertahankan `ROUTE_MAP` yang ada, tambahkan `'/graphql' => 'graphql'` (config/security.php:46 sudah memiliki `{rate:30, burst:5, per:60}`); rute tidak dikenal pakai `default` (60/60s).
- Redis tidak tersedia: pertahankan fail-open yang ada (catch Exception lewati) — fallback kasar nginx 100r/s tetap ada.
- **Cakupan:** hanya container service. admin adalah container terpisah (nginx-admin.conf tanpa limit_req, saat ini tanpa batas frekuensi), perubahan service/config dan middleware service tidak menyentuh admin — batas frekuensi admin di luar cakupan P4.1, diputuskan terpisah.

**D3: Batas frekuensi sebelum autentikasi.** Middleware global berada sebelum AuthMiddleware (urutan middleware.php adalah urutan eksekusi), sehingga ember per-token untuk permintaan tanpa token terdegradasi menjadi ember per-IP; permintaan yang sudah membawa token bahkan jika jalur anonim (seperti /api/products) tetap dihitung ke ember token — mencegah penyalahgunaan token bersama.

### Dampak

| Item | Perubahan |
|----|------|
| `service/common/security/RateLimitMiddleware.php` | Dirombak: ember per-token, burst, Retry-After, aturan graphql |
| `service/config/middleware.php` | Daftar `''` ditambahkan RateLimitMiddleware; dihapus dari semua titik pemasangan eksplisit di route.php |
| `service/config/security.php` | Pertahankan `default` {60,10,60} (ambang penerimaan = rate+burst = 70); `graphql` {30,5,60} sudah ada, tidak perlu ditambahkan; kolom burst dipertahankan |
| `service/config/route.php` | Hapus ~12 titik pemasangan eksplisit `RateLimitMiddleware::class` (sesuai hasil grep nyata, grup auth/supplier/admin) |
| `docker/nginx.conf` | `limit_req` rate 10r/s → 100r/s (fallback kasar, hindari middleware global di atas masih menghambat bisnis) |
| Pengujian | Pengujian di suite service yang bergantung pada pemasangan eksplisit middleware batas frekuensi perlu disinkronkan; tambahkan unit test middleware baru |

### Penerimaan (k6)

```
# Pilih satu rute anonim (mis. GET /api/products) dan /graphql, masing-masing kirim 200 permintaan/10 detik:
# Semua di atas ambang batas frekuensi = 429, dan respons membawa Retry-After; di bawah ambang semua 200.
# Asersi: jumlah 429 == total permintaan - ambang; /graphql juga berlaku (kesenjangan asli).
```

---

## P4.2 Konsistensi Jalur Lengkap Multi-Mata Uang (termasuk strategi pembulatan fee)

### Status Saat Ini (dikonfirmasi dengan pembacaan nyata)

- **Penyimpanan:** semua jumlah di `install.sql` adalah DECIMAL —— saldo/beku `(16,4)`, subtotal/diskon/pajak/total pesanan, unit_price/total_price item `(12,4)`, `exchange_rate DECIMAL(12,6)` sudah ada di `orders`, `payment_transactions`; `user_balances` baris per mata uang (pembukuan per mata uang).
- **Sumber nilai tukar:** `service/app/cron/ExchangeRateSync.php` sudah diimplementasikan — API gratis eksternal (`EXCHANGE_RATE_API_URL` env dapat dikonfigurasi, default exchangerate-api.com) sinkronisasi setiap jam ke Redis `exchange_rate:{CURRENCY}`; `OrderService::getExchangeRate` membaca snapshot Redis saat pemesanan (USD selalu 1.0) dan menulis ke kolom `exchange_rate` pesanan. **Sudah ada dependensi eksternal dan env dapat ganti sumber, tidak perlu tambah baru.**
- **Masalah pemotongan fee:** `PaymentRouter::calculateFee` = `bcadd(bcmul($amount, $rate, 8), $fixed, 4)` — bcmath **memotong** sesuai scale (bukan pembulatan), arah **kurang tagih** <0.0001/pesanan; dan `total_amount = amount + fee` untuk amount dengan 5+ desimal (mis. 10.12345) setelah dipotong mungkin tidak konsisten dengan total pesanan.
- **Pemeriksaan suspend** sudah menilai berdasarkan saldo per mata uang (multi-mata uang), Billing menagih berdasarkan meter (harga satuan usage_rates DECIMAL(12,4)).

### Keputusan

**D4: Invariant jumlah terpadu — satu presisi internal per mata uang, pembulatan hanya terjadi di satu titik.**
- Perhitungan internal terpadu `DECIMAL(12,4)` (granularitas pesanan) dan `DECIMAL(16,4)` (granularitas saldo), semua hasil perkalian wajib melalui `bcround(x, 4, PHP_ROUND_HALF_UP)`, `bcadd/bcsub` hanya melakukan penjumlahan/pengurangan presisi sama (itu sendiri presisi).
- Tambahkan satu-satunya helper jumlah `service/common/money/Money.php` (sekitar 40 baris):
  - `bcround(string $v, int $scale = 4, int $mode = PHP_ROUND_HALF_UP): string` —— idempoten; `round()` berisiko presisi pada float, wajib jalur string: `bcadd($v, '0', $scale+1)` lalu nilai posisi $scale+1 dinilai HALF-UP (perhatikan penanganan negatif dalam implementasi, gunakan bccomp pada abs untuk menilai).
  - Kolom jumlah apa pun wajib melalui `bcround(…, 4)` sebelum ditulis ke DB; **dilarang** menggunakan `(float)`/`round()` di tengah rantai perhitungan (`round((float) bcmul(...))` di StripeChannel yang ada adalah bahaya).
- `calculateFee` yang ada diubah menjadi: `$fee = bcround(bcadd(bcmul(bcround($amount,4), $rate, 8), $fixed, 8), 4)` — pertama selaraskan amount ke 4 digit, lalu kalikan rate, lalu HALF_UP ke 4 digit. **Koreksi arah: kurang tagih → pembulatan setengah standar** (selisih per pesanan ≤0.00005, nilai harapan mendekati 0). **Proteksi clamp fee negatif ke 0 dipertahankan** (perilaku modern code PaymentRouter.php:44 tidak berubah).

**D5: Identitas pesanan dan pemisahan biaya kanal (rekonsiliasi nol drift).** Dua fakta independen:
- **Identitas item pesanan** `total − subtotal − tax + discount == 0` (presisi hingga 0.0000): jalur pembuatan pesanan (OrderService::createFromCart) item `bcround(bcmul(price, qty, 8), 4)` (perkalian presisi tinggi dulu baru bulatkan, hindari pemotongan ganda) → subtotal = jumlah item (presisi) → total = subtotal + tax − discount (penjumlahan/pengurangan presisi sama, presisi). **tax saat ini selalu 0** (createFromCart tidak mengatur tax, install.sql:345 DEFAULT 0.0000) — tidak menambahkan perhitungan pajak (di luar cakupan P4.2, ada dampak kepatuhan), asersi diimplementasikan sesuai nilai sekarang tax=0 tetapi formula mempertahankan item tax.
- **Biaya kanal:** channel_fee independen `bcround(…,4)`, jumlah kanal pembayaran = total + channel_fee presisi sama hingga 4 desimal.
- Validasi: `PaymentController::reconcile*` dan laporan (Report) berbasis total yang disimpan pesanan, tidak menghitung ulang.

**D6: Snapshot nilai tukar dan titik konversi.**
- Sumber nilai tukar pertahankan cron ExchangeRateSync + Redis (sudah ada, tidak diubah). Kolom `exchange_rate` sudah snapshot bersama pesanan/transaksi (DECIMAL(12,6)), **titik konversi = saat penyelesaian (penulisan DB)**, tidak melakukan konversi waktu nyata saat tampilan (harga real-time tampilan hanya perkalian UI dengan rate Redis saat ini, tidak memengaruhi pembukuan).
- Aturan: **segala yang menyangkut pembukuan/saldo, wajib menggunakan rate snapshot pesanan; segala yang menyangkut harga/tampilan, boleh menggunakan rate saat ini.** Dilarang mencampur dua rate dalam rantai penyelesaian.
- Lapisan saldo sudah buku besar per mata uang (user_balances baris per currency), tidak melakukan konversi mata uang dasar terpadu; saat laporan membutuhkan mata uang dasar (mis. USD) gunakan rate snapshot pesanan untuk agregasi, hasil agregasi tetap melalui `bcround(…,4)` (ponytail: kesalahan pembulatan agregasi lintas mata uang ada di digit total, jika audit selanjutnya meminta total per mata uang baru dipecah).

**D7: Daftar perubahan (termasuk titik tinjau ulang kode multi-mata uang yang ada).**
- Ubah: `PaymentRouter::calculateFee`, `StripeChannel` (penyelarasan parameter jumlah + hapus float round, termasuk convertToSmallest diubah ke bcround($total,2)), `OrderService::createFromCart` (pembulatan berurutan item/subtotal/total), **`Order/Model/Coupon.php::calculateDiscount` (:31-44 saat ini float+round, ubah ke jalur string bcround)** , `PaymentController::reconcile*` (asersi identitas D5), `Report/*` (agregasi terpadu bcround).
- Tinjau ulang tanpa ubah: meter Billing (harga satuan sudah DECIMAL(12,4), penagihan selaras dengan bcround saja), pemeriksaan suspend (penilaian saldo per mata uang, sudah benar), `Cron/ExchangeRateSync.php` (tulis Redis pertahankan 6 digit asli, tidak diubah).
- Tambah: `service/common/money/Money.php` + unit test (batas HALF_UP: 0.00005 → 0.0001, 0.00004 → 0.0000, **-0.00005 → -0.0001 (negatif menjauh dari nol)** , idempotensi).
- Migrasi: `install.sql` tanpa perubahan struktur (kolom exchange_rate sudah ada); jika selisih ekor <0.0001 akibat pemotongan fee pesanan historis, itu adalah perbedaan pembukuan yang tidak dapat dibalik, **hanya dicatat tidak diperbaiki** (memperbaiki satu entri akan mengubah rekonsiliasi historis), tambahkan kueri audit baru `fee_drift` untuk mencantumkan pesanan dengan |total−subtotal−tax+discount|>0 untuk pemeriksaan manual.

### Penerimaan

```
# k6 (P4.1): IP tunggal tetap. GET /api/products dan /graphql masing-masing 200 permintaan/10 detik:
#   ambang aturan default = rate+burst = 70/jendela 60s → harapkan 429 ≈ 200−70 = 130 (±batas jendela 1-2)
#   ambang aturan graphql = 35 → harapkan 429 ≈ 165; keduanya membawa header Retry-After; lalu lintas rendah semua 200
# Unit test (P4.2): batas Money::bcround (0.00005→0.0001, 0.00004→0.0000, -0.00005→-0.0001, idempoten)
# Tes identitas: buat pesanan multi-item (termasuk harga satuan 5 desimal + kupon), asersi total−subtotal−tax+discount == 0 selalu berlaku
# Regresi: 491 test service yang ada semuanya hijau (termasuk asersi jumlah)
```

---

## Risiko dan Tinjauan

- **Risiko pembatas frekuensi global D2 (sedang):** pemasangan global memengaruhi semua endpoint service (**tidak termasuk admin** — container terpisah, perubahan service/config tidak menyentuhnya), webhook sudah dikecualikan; ambang tidak tepat akan melukai bisnis, perlu tinjauan ulang security-auditor untuk ambang default dan strategi fail-open. **Container admin saat ini tanpa batas frekuensi** (nginx-admin.conf tanpa limit_req), tidak termasuk P4.1, diputuskan terpisah.
- **D4/D5 jalur dana (tinggi):** perubahan arah pembulatan memengaruhi jumlah setiap pesanan (kurang tagih → pembulatan setengah standar), perlu evaluasi security-auditor + tinjauan dua orang; data historis hanya dicatat tidak diperbaiki.
- **Dependensi:** tanpa dependensi composer baru; tanpa tabel baru; perubahan konfigurasi nginx perlu reload.

```yaml
design:
  objective: "P4.1 batas frekuensi terpadu berlaku di semua rute (termasuk graphql) + P4.2 penyelarasan strategi pembulatan multi-mata uang, identitas pembukuan nol drift"
  files_affected:
    - service/common/security/RateLimitMiddleware.php
    - service/config/middleware.php
    - service/config/route.php
    - service/config/security.php
    - docker/nginx.conf
    - service/common/money/Money.php (new)
    - service/app/payment/service/PaymentRouter.php
    - service/app/payment/service/channels/StripeChannel.php
    - service/app/order/service/OrderService.php
    - service/app/order/model/Coupon.php
    - service/app/payment/controller/PaymentController.php
    - service/app/report/controller/ReportController.php
    - tests/ (middleware + money + identitas)
  modules_touched: ["Gateway/Route", "Security", "Payment", "Order", "Billing", "Report"]
  api_changes: [{method: "ALL", path: "/graphql", error_codes: ["429"]}, {method: "ALL", path: "ALL", error_codes: ["429 + Retry-After"]}]
  data_changes: []   # tanpa perubahan struktur; kolom exchange_rate sudah ada; tax dipertahankan 0 tidak ditambah
  client_impact: ["flutter", "harmonyos"]  # 429 perlu penanganan elegan di klien; container admin tidak terpengaruh
  risk: "high"       # jalur dana D4/D5
  review_needed: ["security-auditor"]
  testing_points: ["429+Retry-After semua rute (k6 IP tunggal, 429≈130/165)", "kesenjangan batas frekuensi graphql ditutup", "webhook dikecualikan tidak 429", "semantik dua ember OR (ganti token/ganti IP tidak dapat memutar)", "batas fee HALF_UP termasuk negatif", "Coupon bcround stringisasi", "identitas total−subtotal−tax+discount==0", "kueri audit fee_drift pesanan historis"]
  dependencies: []
```
