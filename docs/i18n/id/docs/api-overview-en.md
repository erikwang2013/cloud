# Ikhtisar API

> Referensi API lengkap (200+ endpoint, contoh permintaan/respons dan kode kesalahan): [Referensi API](api-reference.md)
> Debugging online: [Dokumen API service](http://localhost:8787/apidoc) · [Dokumen API admin](http://localhost:8788/apidoc)

## Endpoint Publik

| Metode | Path | Deskripsi |
|--------|------|-------------|
| GET | `/health` | Pemeriksaan kesehatan |
| POST | `/api/auth/register` | Registrasi (badan dienkripsi AES-256-GCM) |
| POST | `/api/auth/login` | Login (badan dienkripsi AES-256-GCM) |
| POST | `/api/auth/refresh` | Perbarui token (badan dienkripsi AES-256-GCM) |
| POST | `/api/captcha/create` | Buat CAPTCHA klik (diperlukan sebelum login/registrasi) |
| GET | `/api/products` | Daftar produk (dapat difilter berdasarkan kategori/wilayah/kata kunci) |
| GET | `/api/products/{id}` | Detail produk (id adalah string hashid) |
| GET | `/api/regions` | Wilayah yang tersedia |
| GET | `/api/domain/check/{domain}/{tld}` | Pemeriksaan ketersediaan domain |
| GET | `/api/domain/tlds` | TLD yang tersedia |
| POST | `/api/payments/webhook/stripe` | Webhook Stripe (tanda tangan diverifikasi, tanpa enkripsi) |

## Endpoint Terautentikasi (Bearer Token)

| Metode | Path | Deskripsi |
|--------|------|-------------|
| GET | `/api/user/profile` | Ambil profil |
| PUT | `/api/user/profile` | Perbarui profil |
| POST | `/api/user/kyc` | Kirim KYC |
| GET | `/api/user/balance` | Saldo akun |
| GET/POST | `/api/cart` | Keranjang belanja |
| POST/GET | `/api/orders` | Pesanan |
| GET | `/api/orders/{id}/payment-methods` | Metode pembayaran yang tersedia |
| POST | `/api/orders/{id}/pay` | Mulai pembayaran |
| GET/POST | `/api/resources` | Sumber daya saya |
| GET | `/api/resources/{id}/status` | Status sumber daya |
| GET | `/api/resources/{id}/console` | URL konsol VNC |
| GET/POST | `/api/tickets` | Tiket dukungan |
| POST | `/api/tickets/{id}/reply` | Balas tiket |
| GET/POST | `/api/dns/{domain}` | Manajemen DNS |
| POST | `/api/supplier/apply` | Ajukan sebagai pemasok |
| GET | `/api/supplier/settlements` | Riwayat penyelesaian |
| POST | `/api/supplier/withdraw` | Ajukan penarikan |

> **Catatan:** Semua permintaan API harus menyertakan header `X-Api-Version: v1` (default `v1` jika dihilangkan, divalidasi oleh `VersionMiddleware`). Endpoint terautentikasi dan admin diproses oleh `EncryptionMiddleware`. Klien mengatur header `X-Encrypted: 1` dan membungkus badan sebagai `{"payload": "<base64(AES-256-GCM)>"}`. Respons juga dienkripsi dan dibungkus dalam kolom `payload`. ID integer dalam respons API otomatis diubah menjadi string Hashid 12 karakter; string Hashid dalam permintaan didekode kembali menjadi ID integer oleh `HashidRequestMiddleware`.

## Endpoint Admin

| Metode | Path | Deskripsi |
|--------|------|-------------|
| GET | `/admin/api/dashboard` | Dashboard operasional |
| GET/PUT | `/admin/api/users` | Manajemen pengguna |
| GET/POST | `/admin/api/kyc` | Tinjauan KYC |
| GET/POST/PUT/DELETE | `/admin/api/products` | Manajemen produk |
| POST | `/admin/api/products/{productId}/skus` | Buat SKU |
| POST | `/admin/api/skus/{skuId}/region-price` | Atur harga regional |
| GET/POST | `/admin/api/orders` | Manajemen pesanan (termasuk refund) |
| GET | `/admin/api/orders/export` | Ekspor pesanan (.xlsx) |
| GET | `/admin/api/users/export` | Ekspor pengguna (.xlsx) |
| GET | `/admin/api/suppliers/export` | Ekspor pemasok (.xlsx) |
| GET/PUT | `/admin/api/payments/*` | Kanal / transaksi / rekonsiliasi |
| GET/POST | `/admin/api/provisioning/*` | Tugas pengiriman / manajemen host |
| GET/POST | `/admin/api/suppliers/*` | Persetujuan pemasok / penyelesaian / penarikan |
| GET/POST | `/admin/api/tickets` | Penugasan / penutupan tiket |
| GET | `/admin/api/reports/*` | Laporan pendapatan / regional / pemasok |
| GET | `/admin/api/monitor/*` | Dashboard pemantauan / metrik sumber daya |
| GET | `/admin/api/audit-logs` | Log audit |
| PUT | `/admin/api/system/config` | Perbarui konfigurasi sistem |
