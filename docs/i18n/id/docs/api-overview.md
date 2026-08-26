# Ikhtisar API

> Referensi antarmuka lengkap (200+ endpoint, termasuk contoh permintaan/respons dan kode kesalahan): [Dokumen Referensi API](api-reference.md)
> Debugging online: [Dokumen API service](http://localhost:8787/apidoc) · [Dokumen API admin](http://localhost:8788/apidoc)

## Antarmuka Publik

| Metode | Path | Deskripsi |
|------|------|------|
| GET | `/health` | Pemeriksaan kesehatan |
| POST | `/api/auth/register` | Registrasi pengguna (badan permintaan perlu dienkripsi AES-256-GCM) |
| POST | `/api/auth/login` | Login pengguna (badan permintaan perlu dienkripsi AES-256-GCM) |
| POST | `/api/auth/refresh` | Perbarui Token (badan permintaan perlu dienkripsi AES-256-GCM) |
| POST | `/api/captcha/create` | Buat CAPTCHA klik (diperoleh sebelum login/registrasi) |
| GET | `/api/products` | Daftar produk (mendukung filter kategori/wilayah/kata kunci) |
| GET | `/api/products/{id}` | Detail produk (id adalah string hashid) |
| GET | `/api/regions` | Wilayah yang tersedia |
| GET | `/api/domain/check/{domain}/{tld}` | Pemeriksaan ketersediaan domain |
| GET | `/api/domain/tlds` | Daftar sufiks yang dapat didaftarkan |
| POST | `/api/payments/webhook/stripe` | Callback Stripe (verifikasi tanda tangan, tanpa enkripsi) |

## Antarmuka Terautentikasi (Bearer Token)

| Metode | Path | Deskripsi |
|------|------|------|
| GET | `/api/user/profile` | Informasi pribadi |
| PUT | `/api/user/profile` | Perbarui informasi |
| POST | `/api/user/kyc` | Kirim verifikasi identitas |
| GET | `/api/user/balance` | Saldo akun |
| GET/POST | `/api/cart` | Keranjang belanja |
| POST/GET | `/api/orders` | Pesanan |
| GET | `/api/orders/{id}/payment-methods` | Metode pembayaran yang tersedia |
| POST | `/api/orders/{id}/pay` | Mulai pembayaran |
| GET/POST | `/api/resources` | Sumber daya saya |
| GET | `/api/resources/{id}/status` | Status sumber daya |
| GET | `/api/resources/{id}/console` | Tautan konsol VNC |
| GET/POST | `/api/tickets` | Tiket |
| POST | `/api/tickets/{id}/reply` | Balas tiket |
| GET/POST | `/api/dns/{domain}` | Manajemen DNS |
| POST | `/api/supplier/apply` | Pengajuan pemasok |
| GET | `/api/supplier/settlements` | Catatan penyelesaian pemasok |
| POST | `/api/supplier/withdraw` | Penarikan pemasok |

> **Catatan:** Semua permintaan API harus menyertakan header `X-Api-Version: v1` (default `v1` jika tidak ada, divalidasi oleh `VersionMiddleware`). Permintaan/respons antarmuka terautentikasi dan antarmuka admin diproses oleh `EncryptionMiddleware`. Klien mengatur header `X-Encrypted: 1`, format badan permintaan adalah `{"payload": "<base64(AES-256-GCM)>"}`, dan badan respons juga dienkripsi lalu dibungkus dalam kolom `payload`. Semua ID integer di respons API otomatis diubah menjadi string Hashid 12 karakter; string Hashid dalam permintaan didekode otomatis kembali menjadi ID integer oleh `HashidRequestMiddleware`.

## Antarmuka Admin

| Metode | Path | Deskripsi |
|------|------|------|
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
| GET/PUT | `/admin/api/payments/*` | Kanal pembayaran / transaksi / rekonsiliasi |
| GET/POST | `/admin/api/provisioning/*` | Tugas pengiriman / manajemen host |
| GET/POST | `/admin/api/suppliers/*` | Persetujuan pemasok / penyelesaian / penarikan |
| GET/POST | `/admin/api/tickets` | Penugasan / penutupan tiket |
| GET | `/admin/api/reports/*` | Laporan pendapatan / regional / pemasok |
| GET | `/admin/api/monitor/*` | Panel pemantauan / metrik sumber daya |
| GET | `/admin/api/audit-logs` | Log audit |
| PUT | `/admin/api/system/config` | Konfigurasi sistem |
