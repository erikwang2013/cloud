# Cloud Platform — Platform Perdagangan Sumber Daya Cloud Global

Platform perdagangan sumber daya cloud untuk pengguna global, mendukung pembelian online dan pengiriman otomatis untuk produk seperti server (VM), alamat IP, disk cloud, domain. Mesin fisik milik sendiri dikirim melalui virtualisasi Proxmox VE, sekaligus mendukung vendor pihak ketiga untuk bergabung dan berjualan.


## Ikhtisar Edisi

| | Edisi Sederhana Lite | Edisi Standar Standard | Edisi Lengkap Full |
|---|:---:|:---:|:---:|
| **Lisensi** | Open source (MIT) | Lisensi komersial | Lisensi komersial |
| **Kontak** | GitHub | erik@erik.xyz | erik@erik.xyz |
| **Skenario penggunaan** | Proyek pribadi/pembelajaran/toko kecil | Penyedia layanan cloud kecil-menengah | Platform cloud besar/multi-pemasok |

---

## 1. Perbandingan Fungsi

### 1.1 Sistem Pengguna

| Fungsi | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Registrasi/login email/nomor ponsel | ✅ | ✅ | ✅ |
| Autentikasi JWT (Access + Refresh) | ✅ | ✅ | ✅ |
| Reset kata sandi | ✅ | ✅ | ✅ |
| Pengikatan sidik jari perangkat + rotasi Token | ❌ | ✅ | ✅ |
| Penguncian login (5 kali gagal kunci 15 menit) | ❌ | ✅ | ✅ |
| Login Google OAuth | ❌ | ✅ | ✅ |
| Apple Sign In | ❌ | ✅ | ✅ |
| Verifikasi dua langkah TOTP + kode pemulihan | ❌ | ✅ | ✅ |
| Verifikasi email | ❌ | ✅ | ✅ |
| Kode verifikasi SMS | ❌ | ✅ | ✅ |
| Manajemen sesi (lihat/cabut) | ✅ | ✅ | ✅ |
| Penghapusan akun GDPR | ✅ | ✅ | ✅ |
| Manajemen profil pribadi | ✅ | ✅ | ✅ |
| Verifikasi identitas KYC | ❌ | ✅ | ✅ |
| Manajemen alamat | ❌ | ✅ | ✅ |
| Akun saldo | ❌ | ✅ | ✅ |
| Alarm login IP baru | ❌ | ✅ | ✅ |
| Identifikasi platform klien | ❌ | ✅ | ✅ |
| Internasionalisasi multi-bahasa (i18n, 120 entri) | ✅ | ✅ | ✅ |

### 1.2 Sistem Produk

| Fungsi | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Daftar produk (filter kategori/wilayah) | ✅ | ✅ | ✅ |
| Detail produk (termasuk SKU + penetapan harga regional) | ✅ | ✅ | ✅ |
| Pencarian teks lengkap Elasticsearch | ✅ | ✅ | ✅ |
| Ulasan produk (skor + konten) | ✅ | ✅ | ✅ |
| Atribut produk | ❌ | ✅ | ✅ |
| CAPTCHA klik | ❌ | ✅ | ✅ |
| Impor/ekspor massal (CSV) | ❌ | ✅ | ✅ |

### 1.3 Sistem Pesanan

| Fungsi | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Keranjang belanja (tambah/hapus/ubah/lihat) | ✅ | ✅ | ✅ |
| Membuat pesanan | ✅ | ✅ | ✅ |
| Daftar pesanan + detail | ✅ | ✅ | ✅ |
| Kupon | ❌ | ✅ | ✅ |
| Faktur (buat + unduh PDF) | ❌ | ✅ | ✅ |
| Refund | ❌ | ✅ | ✅ |

### 1.4 Sistem Pembayaran

| Fungsi | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Pembayaran Stripe | ❌ | ✅ | ✅ |
| Perutean multi-kanal | ❌ | ✅ | ✅ |
| Verifikasi tanda tangan Webhook | ❌ | ✅ | ✅ |
| Rekonsiliasi harian | ❌ | ✅ | ✅ |
| Nilai tukar multi-mata uang | ❌ | ✅ | ✅ |
| Refund kembali ke jalur asal | ❌ | ✅ | ✅ |

### 1.5 Pengiriman Sumber Daya

| Fungsi | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Virtualisasi Proxmox VE | ❌ | ✅ | ✅ |
| Siklus hidup penuh server (VM) | ❌ | ✅ | ✅ |
| Disk cloud (buat/perluas) | ❌ | ✅ | ✅ |
| Manajemen + alokasi kumpulan IP | ❌ | ✅ | ✅ |
| Strategi pemilihan host (load balancing) | ❌ | ✅ | ✅ |
| Upgrade online CPU/memori/disk | ❌ | ✅ | ✅ |
| Konsol VNC | ❌ | ✅ | ✅ |
| Antrean pengaktifan asinkron | ❌ | ✅ | ✅ |
| Strategi percobaan ulang (6 kali backoff) | ❌ | ✅ | ✅ |
| Arsitektur plugin Provider | ❌ | ✅ | ✅ |
| Pemantauan kedaluwarsa sumber daya | ❌ | ✅ | ✅ |

### 1.6 Domain dan DNS

| Fungsi | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Kueri ketersediaan domain | ❌ | ✅ | ✅ |
| Manajemen harga TLD | ❌ | ✅ | ✅ |
| Manajemen catatan DNS | ❌ | ✅ | ✅ |
| Persetujuan transfer domain | ❌ | ✅ | ✅ |

### 1.7 Sistem Tiket

| Fungsi | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Buat/balas tiket | ❌ | ✅ | ✅ |
| Daftar tiket + detail | ❌ | ✅ | ✅ |
| Penugasan layanan pelanggan | ❌ | ✅ | ✅ |
| Pelacakan SLA | ❌ | ✅ | ✅ |
| Penugasan otomatis (load balancing) | ❌ | ✅ | ✅ |

### 1.8 Sistem Notifikasi

| Fungsi | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Notifikasi email | ❌ | ✅ | ✅ |
| Notifikasi SMS (Twilio) | ❌ | ✅ | ✅ |
| Push App (FCM) | ❌ | ✅ | ✅ |
| Pesan internal situs | ❌ | ✅ | ✅ |
| Manajemen templat notifikasi | ❌ | ✅ | ✅ |
| Preferensi notifikasi pengguna | ❌ | ✅ | ✅ |

### 1.9 Panel Admin

| Fungsi | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Dashboard | ✅ | ✅ | ✅ |
| Manajemen pengguna (daftar/detail/status) | ✅ | ✅ | ✅ |
| Manajemen produk (CRUD) | ✅ | ✅ | ✅ |
| Manajemen pesanan (daftar/detail) | ✅ | ✅ | ✅ |
| Log audit | ✅ | ✅ | ✅ |
| Tinjauan KYC | ❌ | ✅ | ✅ |
| Manajemen SKU + penetapan harga regional | ❌ | ✅ | ✅ |
| Manajemen kanal pembayaran + catatan transaksi | ❌ | ✅ | ✅ |
| Pemantauan tugas pengaktifan sumber daya | ❌ | ✅ | ✅ |
| Manajemen host | ❌ | ✅ | ✅ |
| Penugasan/penutupan tiket | ❌ | ✅ | ✅ |
| Manajemen TLD domain + zona DNS | ❌ | ✅ | ✅ |
| Manajemen templat notifikasi | ❌ | ✅ | ✅ |
| Manajemen kupon | ❌ | ✅ | ✅ |
| Manajemen artikel bantuan | ❌ | ✅ | ✅ |
| Manajemen Webhook | ❌ | ✅ | ✅ |
| Manajemen API penyedia cloud | ❌ | ✅ | ✅ |
| Impor/ekspor produk | ❌ | ✅ | ✅ |
| Ekspor pengguna/pesanan/pemasok | ❌ | ✅ | ✅ |
| Laporan (pendapatan/wilayah) | ❌ | ✅ | ✅ |
| Panel pemantauan + metrik sumber daya | ❌ | ✅ | ✅ |
| Manajemen pemasok | ❌ | ❌ | ✅ |
| Manajemen API Key pemasok | ❌ | ❌ | ✅ |
| Sakelar dinamis Feature Flags | ❌ | ❌ | ✅ |

### 1.10 Sistem Pemasok

| Fungsi | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Pendaftaran + persetujuan pemasok | ❌ | ❌ | ✅ |
| Penayangan produk + komisi | ❌ | ❌ | ✅ |
| Penyelesaian (mingguan/bulanan) | ❌ | ❌ | ✅ |
| Pengajuan + persetujuan penarikan | ❌ | ❌ | ✅ |
| API eksternal (autentikasi API Key) | ❌ | ❌ | ✅ |
| Isolasi data pemasok | ❌ | ❌ | ✅ |

### 1.11 Komunikasi Real-time

| Fungsi | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Push real-time WebSocket | ❌ | ❌ | ✅ |
| Pemantauan eksepsi Sentry | ❌ | ❌ | ✅ |
| Skrip pengujian beban k6 | ❌ | ✅ | ✅ |


### 1.12 Sertifikat SSL

| Fungsi | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Pembelian sertifikat SSL (DV/OV/EV) | ❌ | ❌ | ✅ |
| Penerbitan otomatis Let's Encrypt | ❌ | ❌ | ✅ |
| Perpanjangan otomatis (14 hari sebelum kedaluwarsa) | ❌ | ❌ | ✅ |
| Unduh sertifikat (PEM/KEY) | ❌ | ❌ | ✅ |
| Manajemen paket SSL (sisi admin) | ❌ | ❌ | ✅ |

### 1.13 Penyimpanan Objek

| Fungsi | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Penyimpanan objek kompatibel S3 | ❌ | ❌ | ✅ |
| Penyimpanan MinIO mandiri | ❌ | ❌ | ✅ |
| URL unggah/unduh presigned | ❌ | ❌ | ✅ |
| Manajemen kuota penyimpanan | ❌ | ❌ | ✅ |

### 1.14 Akselerasi CDN

| Fungsi | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Manajemen domain CDN | ❌ | ❌ | ✅ |
| Pembersihan cache (Purge) | ❌ | ❌ | ✅ |
| Jenis origin (server/penyimpanan) | ❌ | ❌ | ✅ |
| Integrasi Cloudflare | ❌ | ❌ | ✅ |

### 1.15 Penagihan Pemakaian

| Fungsi | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Penagihan per jam/lalu lintas | ❌ | ❌ | ✅ |
| Koleksi dan agregasi pemakaian | ❌ | ❌ | ✅ |
| Pemotongan saldo otomatis | ❌ | ❌ | ✅ |
| Suspend/pulihkan sumber daya tunggakan | ❌ | ❌ | ✅ |

### 1.16 Rating Pemasok

| Fungsi | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Rating empat dimensi (kualitas/dukungan/pengiriman/nilai) | ❌ | ❌ | ✅ |
| Batasan pengguna yang sudah membeli | ❌ | ❌ | ✅ |
| Tinjauan rating (sisi admin) | ❌ | ❌ | ✅ |
| Tampilan rata-rata pemasok | ❌ | ❌ | ✅ |

### 1.17 Distribusi Rekomendasi

| Fungsi | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Pembuatan tautan rekomendasi | ❌ | ❌ | ✅ |
| Atribusi pesanan (parameter ref) | ❌ | ❌ | ✅ |
| Perhitungan dan penarikan komisi | ❌ | ❌ | ✅ |
| Manajemen paket distribusi (sisi admin) | ❌ | ❌ | ✅ |

### 1.18 GraphQL

| Fungsi | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Endpoint GraphQL (publik+terautentikasi) | ❌ | ❌ | ✅ |
| Kueri produk/pesanan/sumber daya | ❌ | ❌ | ✅ |
| Batasan kedalaman kueri | ❌ | ❌ | ✅ |

### 1.19 Observabilitas

| Fungsi | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Ekspor metrik Prometheus | ❌ | ❌ | ✅ |
| Dashboard Grafana siap pakai | ❌ | ❌ | ✅ |
| Aturan alarm (antrean/tingkat kesalahan/latensi) | ❌ | ❌ | ✅ |
| Pemeriksaan kesehatan (live/ready/deps) | ❌ | ❌ | ✅ |
| i18n 7 bahasa (550+ entri) | ❌ | ❌ | ✅ |

### 1.12 Klien

| Fungsi | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Klien Flutter | ❌ | ❌ | ✅ |
| Klien HarmonyOS | ❌ | ❌ | ✅ |

---

## 2. Perbandingan Desain Arsitektur

### 2.1 Middleware

| Middleware | Lite | Standard | Full |
|--------|:---:|:---:|:---:|
| CorsMiddleware (CORS) | ✅ | ✅ | ✅ |
| LocaleMiddleware (multi-bahasa) | ✅ | ✅ | ✅ |
| HashidRequestMiddleware (dekode ID) | ✅ | ✅ | ✅ |
| AuthMiddleware (autentikasi JWT) | ✅ | ✅ | ✅ |
| RateLimitMiddleware (batas frekuensi) | ✅ | ✅ | ✅ |
| WafMiddleware dasar (SQLi/XSS) | ✅ | ✅ | ✅ |
| WafMiddleware lengkap (8 kategori 45+ aturan) | ❌ | ✅ | ✅ |
| AdminRoleMiddleware (RBAC) | ❌ | ✅ | ✅ |
| EncryptionMiddleware (AES-256-GCM) | ❌ | ✅ | ✅ |
| VersionMiddleware (versi API) | ❌ | ✅ | ✅ |
| ClientPlatformMiddleware (identifikasi platform) | ❌ | ✅ | ✅ |
| ConfirmationMiddleware (konfirmasi kata sandi) | ❌ | ✅ | ✅ |
| GeoBlockMiddleware (pemblokiran wilayah) | ❌ | ✅ | ✅ |
| MaintenanceMiddleware (mode pemeliharaan) | ❌ | ✅ | ✅ |
| SupplierApiKeyMiddleware | ❌ | ❌ | ✅ |
| FeatureFlags | ❌ | ❌ | ✅ |
| RbacMiddleware | ❌ | ✅ | ✅ |

### 2.2 Arsitektur Data

| Fitur | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Kunci utama terdistribusi Snowflake | ✅ | ✅ | ✅ |
| Obfuskasi ID Hashids | ✅ | ✅ | ✅ |
| MySQL basis data tunggal | ✅ | ❌ | ❌ |
| Pemisahan baca/tulis utama-budak MySQL | ❌ | ✅ | ✅ |
| Basis data audit terpisah | ❌ | ✅ | ✅ |
| Enkripsi transmisi AES-256-GCM | ❌ | ✅ | ✅ |
| Enkripsi kolom AES-128-ECB | ❌ | ✅ | ✅ |
| Cache multi-level Redis | ❌ | ✅ | ✅ |
| Pencarian teks lengkap Elasticsearch | ✅ | ✅ | ✅ |
| Optimasi indeks basis data (13) | ❌ | ✅ | ✅ |

### 2.3 Perlindungan Keamanan

| Fitur | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Deteksi SQL injection (2 aturan) | ✅ | ✅ | ✅ |
| Deteksi XSS (3 aturan) | ✅ | ✅ | ✅ |
| Deteksi injeksi perintah | ❌ | ✅ | ✅ |
| Deteksi inklusi file | ❌ | ✅ | ✅ |
| Deteksi injeksi header HTTP | ❌ | ✅ | ✅ |
| Deteksi SSRF | ❌ | ✅ | ✅ |
| Deteksi injeksi NoSQL | ❌ | ✅ | ✅ |
| Deteksi redirect terbuka | ❌ | ✅ | ✅ |
| Batas ukuran badan permintaan | ❌ | ✅ | ✅ |
| Daftar putih Content-Type | ❌ | ✅ | ✅ |

### 2.4 Konkurensi Tinggi

| Fitur | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Multi-proses webman | ✅ | ✅ | ✅ |
| Kompresi gzip Nginx | ❌ | ✅ | ✅ |
| Proxy buffering Nginx | ❌ | ✅ | ✅ |
| Nginx limit_req/limit_conn | ❌ | ✅ | ✅ |
| Lapisan cache Redis | ❌ | ✅ | ✅ |
| Invalidasi cache aktif | ❌ | ✅ | ✅ |
| Pemisahan baca/tulis MySQL | ❌ | ✅ | ✅ |
| Indeks komposit basis data | ❌ | ✅ | ✅ |
| Push WebSocket | ❌ | ❌ | ✅ |

---

## 3. Deployment dan Operasional

| Fitur | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Deployment Docker Compose | ✅ | ✅ | ✅ |
| Reverse proxy Nginx | ✅ | ✅ | ✅ |
| CI/CD (GitHub Actions) | ✅ | ✅ | ✅ |
| Pengujian PHPUnit | 95 tests | 295 tests | 295 tests |
| Tugas terjadwal (7) | ❌ | ✅ | ✅ |
| Pemrosesan asinkron Redis Queue | ❌ | ✅ | ✅ |
| Perintah migrasi basis data | ✅ | ✅ | ✅ |
| Perintah backup basis data | ❌ | ✅ | ✅ |
| Endpoint pemeriksaan kesehatan | ✅ | ✅ | ✅ |
| Endpoint status layanan | ✅ | ✅ | ✅ |
| Pemantauan eksepsi Sentry | ❌ | ❌ | ✅ |
| Rilis bertahap Feature Flags | ❌ | ❌ | ✅ |
| Pengujian beban k6 | ❌ | ❌ | ✅ |

---

## 4. Angka Statistik

| Metrik | Lite | Standard | Full |
|------|:---:|:---:|:---:|
| Endpoint API | ~35 | ~130 | 200+ |
| Model data | 15 | 50+ | 70+ |
| Tabel basis data | 15 | 50+ | 60+ |
| Middleware global | 3 | 7 | 9 |
| Middleware rute | 1 | 5 | 6 |
| Tugas terjadwal | 0 | 7 | 10 |
| File migrasi | 5 | 20 | 27 |
| Jumlah pengujian | 95 | 295 | 295 |
| Jumlah aturan WAF | 5 | 45+ | 45+ |
| Jumlah dokumen | 2 | 6 | 8 |
| Dokumen online hg/apidoc | ✅ | ✅ | ✅ |
| Endpoint API GraphQL | ❌ | ❌ | ✅ |
| Metrik Prometheus | ❌ | ❌ | ✅ |
| Sistem rating Supplier | ❌ | ❌ | ✅ |
| Sistem rekomendasi Affiliate | ❌ | ❌ | ✅ |

---

## 5. Jalur Upgrade

```
Edisi Sederhana (Lite)
  │
  │  + pembayaran + pengiriman + domain + tiket + notifikasi
  │  + panel admin lengkap + rangkaian keamanan penuh + optimasi konkurensi tinggi
  ▼
Edisi Standar (Standard)
  │
  │  + sistem pemasok + API eksternal + WebSocket
  │  + Sentry + Feature Flags + klien Flutter
  ▼
Edisi Lengkap (Full)
```

**Kompatibilitas data:** struktur basis data edisi Sederhana kompatibel dengan tabel inti edisi Standar, dapat langsung dimigrasi dan diupgrade. Dari Standar ke Full murni inkremental (menambahkan tabel terkait pemasok), tanpa perlu migrasi data.

---

## 6. Cara Mendapatkan

| Edisi | Cara mendapatkan |
|------|---------|
| **Edisi Sederhana Lite** | Open source GitHub, lisensi MIT |
| **Edisi Standar Standard** | Lisensi komersial, hubungi **erik@erik.xyz** |
| **Edisi Lengkap Full** | Lisensi komersial, hubungi **erik@erik.xyz** |
