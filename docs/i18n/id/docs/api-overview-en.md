# Ikhtisar API

> Referensi API lengkap (200+ endpoint, contoh permintaan/respons dan kode kesalahan): [Referensi API](api-reference.md)
> Debugging online: [Dokumen API service](http://localhost:8787/apidoc) · [Dokumen API admin](http://localhost:8788/apidoc)

## Endpoint Publik

| Metode | Path | Deskripsi |
|--------|------|-------------|
| GET | `/health` | Pemeriksaan kesehatan |
| POST | `/api/v1/auth/register` | Registrasi (badan dienkripsi AES-256-GCM) |
| POST | `/api/v1/auth/login` | Login (badan dienkripsi AES-256-GCM) |
| POST | `/api/v1/auth/refresh` | Perbarui token (badan dienkripsi AES-256-GCM) |
| POST | `/api/v1/captcha/create` | Buat CAPTCHA klik (diperlukan sebelum login/registrasi) |
| GET | `/api/v1/products` | Daftar produk (dapat difilter berdasarkan kategori/wilayah/kata kunci) |
| GET | `/api/v1/products/{id}` | Detail produk (id adalah string hashid) |
| GET | `/api/v1/regions` | Wilayah yang tersedia |
| GET | `/api/v1/domain/check/{domain}/{tld}` | Pemeriksaan ketersediaan domain |
| GET | `/api/v1/domain/tlds` | TLD yang tersedia |
| POST | `/api/v1/payments/webhook/stripe` | Webhook Stripe (tanda tangan diverifikasi, tanpa enkripsi) |

## Endpoint Terautentikasi (Bearer Token)

| Metode | Path | Deskripsi |
|--------|------|-------------|
| GET | `/api/v1/user/profile` | Ambil profil |
| PUT | `/api/v1/user/profile` | Perbarui profil |
| POST | `/api/v1/user/kyc` | Kirim KYC |
| GET | `/api/v1/user/balance` | Saldo akun |
| GET/POST | `/api/v1/cart` | Keranjang belanja |
| POST/GET | `/api/v1/orders` | Pesanan |
| GET | `/api/v1/orders/{id}/payment-methods` | Metode pembayaran yang tersedia |
| POST | `/api/v1/orders/{id}/pay` | Mulai pembayaran |
| GET/POST | `/api/v1/resources` | Sumber daya saya |
| GET | `/api/v1/resources/{id}/status` | Status sumber daya |
| GET | `/api/v1/resources/{id}/console` | URL konsol VNC |
| GET/POST | `/api/v1/tickets` | Tiket dukungan |
| POST | `/api/v1/tickets/{id}/reply` | Balas tiket |
| GET/POST | `/api/v1/dns/{domain}` | Manajemen DNS |
| POST | `/api/v1/supplier/apply` | Ajukan sebagai pemasok |
| GET | `/api/v1/supplier/settlements` | Riwayat penyelesaian |
| POST | `/api/v1/supplier/withdraw` | Ajukan penarikan |

> **Catatan:** Versi API ada di path URL (contoh: `/api/v1/...`). Endpoint terautentikasi dan admin diproses oleh `EncryptionMiddleware`. Klien mengatur header `X-Encrypted: 1` dan membungkus badan sebagai `{"payload": "<base64(AES-256-GCM)>"}`. Respons juga dienkripsi dan dibungkus dalam kolom `payload`. ID integer dalam respons API otomatis diubah menjadi string Hashid 12 karakter; string Hashid dalam permintaan didekode kembali menjadi ID integer oleh `HashidRequestMiddleware`.

## Endpoint Admin

| Metode | Path | Deskripsi |
|--------|------|-------------|
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
| GET/PUT | `/admin/api/v1/payments/*` | Kanal / transaksi / rekonsiliasi |
| GET/POST | `/admin/api/v1/provisioning/*` | Tugas pengiriman / manajemen host |
| GET/POST | `/admin/api/v1/suppliers/*` | Persetujuan pemasok / penyelesaian / penarikan |
| GET/POST | `/admin/api/v1/tickets` | Penugasan / penutupan tiket |
| GET | `/admin/api/v1/reports/*` | Laporan pendapatan / regional / pemasok |
| GET | `/admin/api/v1/monitor/*` | Dashboard pemantauan / metrik sumber daya |
| GET | `/admin/api/v1/audit-logs` | Log audit |
| PUT | `/admin/api/v1/system/config` | Perbarui konfigurasi sistem |
