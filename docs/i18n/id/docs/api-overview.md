# Ikhtisar API

> Referensi antarmuka lengkap (200+ endpoint, termasuk contoh permintaan/respons dan kode kesalahan): [Dokumen Referensi API](api-reference.md)
> Debugging online: [Dokumen API service](http://localhost:8787/apidoc) · [Dokumen API admin](http://localhost:8788/apidoc)

## Antarmuka Publik

| Metode | Path | Deskripsi |
|------|------|------|
| GET | `/health` | Pemeriksaan kesehatan |
| POST | `/api/v1/auth/register` | Registrasi pengguna (badan permintaan perlu dienkripsi AES-256-GCM) |
| POST | `/api/v1/auth/login` | Login pengguna (badan permintaan perlu dienkripsi AES-256-GCM) |
| POST | `/api/v1/auth/refresh` | Perbarui Token (badan permintaan perlu dienkripsi AES-256-GCM) |
| POST | `/api/v1/captcha/create` | Buat CAPTCHA klik (diperoleh sebelum login/registrasi) |
| GET | `/api/v1/products` | Daftar produk (mendukung filter kategori/wilayah/kata kunci) |
| GET | `/api/v1/products/{id}` | Detail produk (id adalah string hashid) |
| GET | `/api/v1/regions` | Wilayah yang tersedia |
| GET | `/api/v1/domain/check/{domain}/{tld}` | Pemeriksaan ketersediaan domain |
| GET | `/api/v1/domain/tlds` | Daftar sufiks yang dapat didaftarkan |
| POST | `/api/v1/payments/webhook/stripe` | Callback Stripe (verifikasi tanda tangan, tanpa enkripsi) |

## Antarmuka Terautentikasi (Bearer Token)

| Metode | Path | Deskripsi |
|------|------|------|
| GET | `/api/v1/user/profile` | Informasi pribadi |
| PUT | `/api/v1/user/profile` | Perbarui informasi |
| POST | `/api/v1/user/kyc` | Kirim verifikasi identitas |
| GET | `/api/v1/user/balance` | Saldo akun |
| GET/POST | `/api/v1/cart` | Keranjang belanja |
| POST/GET | `/api/v1/orders` | Pesanan |
| GET | `/api/v1/orders/{id}/payment-methods` | Metode pembayaran yang tersedia |
| POST | `/api/v1/orders/{id}/pay` | Mulai pembayaran |
| GET/POST | `/api/v1/resources` | Sumber daya saya |
| GET | `/api/v1/resources/{id}/status` | Status sumber daya |
| GET | `/api/v1/resources/{id}/console` | Tautan konsol VNC |
| GET/POST | `/api/v1/cdn/domains` | Daftar domain CDN / buat (cloudflare \| cloudfront \| aliyun \| tencent) |
| GET/DELETE | `/api/v1/cdn/domains/{id}` | Detail domain CDN / hapus |
| POST | `/api/v1/cdn/domains/{id}/purge` | Bersihkan cache (idempoten, maksimal 100 URL) |
| GET/POST | `/api/v1/tickets` | Tiket |
| POST | `/api/v1/tickets/{id}/reply` | Balas tiket |
| GET/POST | `/api/v1/dns/{domain}` | Manajemen DNS |
| POST | `/api/v1/supplier/apply` | Pengajuan pemasok |
| GET | `/api/v1/supplier/settlements` | Catatan penyelesaian pemasok |
| POST | `/api/v1/supplier/withdraw` | Penarikan pemasok |

> **Catatan:** Versi API ada di path URL (contoh: `/api/v1/...`). Permintaan/respons antarmuka terautentikasi dan antarmuka admin diproses oleh `EncryptionMiddleware`. Klien mengatur header `X-Encrypted: 1`, format badan permintaan adalah `{"payload": "<base64(AES-256-GCM)>"}`, dan badan respons juga dienkripsi lalu dibungkus dalam kolom `payload`. Semua ID integer di respons API otomatis diubah menjadi string Hashid 12 karakter; string Hashid dalam permintaan didekode otomatis kembali menjadi ID integer oleh `HashidRequestMiddleware`.

## Antarmuka Admin

| Metode | Path | Deskripsi |
|------|------|------|
| GET | `/admin/api/v1/dashboard` | Dashboard operasional |
| GET/PUT | `/admin/api/v1/users` | Manajemen pengguna |
| GET/POST | `/admin/api/v1/kyc` | Tinjauan KYC |
| GET/POST/PUT/DELETE | `/admin/api/v1/products` | Manajemen produk |
| POST | `/admin/api/v1/products/{productId}/skus` | Buat SKU |
| POST | `/admin/api/v1/skus/{skuId}/region-price` | Atur harga regional |
| GET/POST | `/admin/api/v1/orders` | Manajemen pesanan (termasuk refund) |
| GET | `/admin/api/v1/orders/export` | Ekspor pesanan (.xlsx) |
| GET | `/admin/api/v1/users/export` | Ekspor pengguna (.xlsx) |
| GET | `/admin/api/v1/suppliers/export` | Ekspor pemasok (.xlsx) |
| GET/PUT | `/admin/api/v1/payments/*` | Kanal pembayaran / transaksi / rekonsiliasi |
| GET/POST | `/admin/api/v1/provisioning/*` | Tugas pengiriman / manajemen host |
| GET/PUT | `/admin/api/v1/cdn/domains` | Manajemen domain CDN (perubahan paket) |
| GET/POST/PUT/DELETE | `/admin/api/v1/providers` | Manajemen kredensial akun penyedia (dipakai CDN/delivery, dienkripsi Encryptable) |
| GET/POST | `/admin/api/v1/suppliers/*` | Persetujuan pemasok / penyelesaian / penarikan |
| GET/POST | `/admin/api/v1/tickets` | Penugasan / penutupan tiket |
| GET | `/admin/api/v1/reports/*` | Laporan pendapatan / regional / pemasok |
| GET | `/admin/api/v1/monitor/*` | Panel pemantauan / metrik sumber daya |
| GET | `/admin/api/v1/audit-logs` | Log audit |
| PUT | `/admin/api/v1/system/config` | Konfigurasi sistem |
