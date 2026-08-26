# Dokumentasi API Pemasok v1

## Ikhtisar

Fitur pemasok menyediakan dua set API:

| Jenis | Cara autentikasi | Prefix | Status |
|------|---------|------|------|
| **API Internal** | Bearer Token pengguna | `/api/supplier/` | Tersedia |
| **API Eksternal** | API Key (`sk_xxx`) | `/api/supplier/external/` | Tersedia |

**Base URL**: `https://api.example.com`

**Versioning**: ditentukan melalui header HTTP `X-Api-Version: v1`. Jika tidak ada, default `v1`, versi yang tidak didukung mengembalikan `400`. Hanya berlaku untuk path `/api/*` dan `/admin/api/*`, ditangani secara terpadu oleh `VersionMiddleware`.

---

## API Internal (saat ini tersedia)

API internal menggunakan autentikasi Bearer Token pengguna yang sama dengan antarmuka platform lainnya, cocok untuk dipanggil oleh pemasok yang sudah login di klien/frontend.

### Autentikasi

```
Authorization: Bearer <user_access_token>
X-Api-Version: v1
```

Pengguna harus login melalui `/api/auth/login` terlebih dahulu untuk mendapatkan Token, dan peran akun harus `supplier` (diatur oleh admin setelah menyetujui aplikasi pemasok).

---

### Format Respons

#### Respons Sukses

```json
{
  "code": 0,
  "message": "ok",
  "data": { ... }
}
```

#### Respons Berpaginasi

```json
{
  "code": 0,
  "message": "ok",
  "data": [ ... ],
  "meta": {
    "page": 1,
    "page_size": 20,
    "total": 45
  }
}
```

#### Respons Kesalahan

```json
{
  "code": 422,
  "message": "You already have a supplier application",
  "data": null
}
```

| code | Keterangan |
|------|------|
| 0 | Sukses |
| 400 | Kesalahan parameter permintaan / versi API tidak didukung |
| 401 | Belum login atau Token kedaluwarsa |
| 403 | Tidak punya hak akses (bukan peran pemasok / konfirmasi kata sandi gagal) |
| 404 | Sumber daya tidak ada |
| 422 | Validasi parameter gagal |
| 429 | Frekuensi permintaan melebihi batas |

---

### Endpoint

#### 1. Pendaftaran Pemasok

```
POST /api/supplier/apply
```

Mengajukan permohonan menjadi pemasok. Setiap pengguna hanya dapat mengajukan satu kali.

**Body permintaan**:

```json
{
  "company_name": "示例科技有限公司",
  "contact_name": "张三",
  "contact_phone": "13800138000",
  "contact_email": "zhangsan@example.com",
  "settlement_method": "bank"
}
```

| Parameter | Tipe | Wajib | Keterangan |
|------|------|------|------|
| company_name | string | Ya | Nama perusahaan |
| contact_name | string | Ya | Nama kontak |
| contact_phone | string | Ya | Telepon kontak |
| contact_email | string | Ya | Email kontak |
| settlement_method | string | Tidak | Metode settlement, default `bank` |

**Respons**: objek pemasok, status `pending`

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "id": "aBc123XyZ",
    "user_id": "UsEr456AbC",
    "company_name": "示例科技有限公司",
    "contact_name": "张三",
    "contact_phone": "138****8000",
    "contact_email": "zha***@example.com",
    "status": "pending",
    "settlement_method": "bank",
    "created_at": "2026-05-20T10:30:00Z"
  }
}
```

> Kolom sensitif (nama kontak, telepon, email) disimpan terenkripsi di database, API mengembalikan dengan desensitisasi sebagian.

**Kesalahan**:

| code | Skenario |
|------|------|
| 422 | Sudah pernah mengajukan aplikasi pemasok |

```bash
curl -X POST "https://api.example.com/api/supplier/apply" \
  -H "Authorization: Bearer <token>" \
  -H "X-Api-Version: v1" \
  -H "Content-Type: application/json" \
  -d '{"company_name":"示例科技","contact_name":"张三","contact_phone":"13800138000","contact_email":"zhangsan@example.com"}'
```

---

#### 2. Manajemen Produk

##### Mendapatkan Produk yang Ditugaskan

```
GET /api/supplier/products
```

**Parameter Query**:

| Parameter | Tipe | Wajib | Keterangan |
|------|------|------|------|
| page | int | Tidak | Nomor halaman, default 1 |

**Respons**: daftar berpaginasi, setiap item berisi informasi produk dan rasio komisi

```json
{
  "code": 0,
  "data": [{
    "id": "SpAbC123",
    "supplier_id": "aBc123XyZ",
    "product_id": "PrOdEfG456",
    "commission_rate": 0.1,
    "approved_at": "2026-05-20T10:30:00Z",
    "product": {
      "id": "PrOdEfG456",
      "name": "高性能云服务器",
      "status": "active"
    }
  }],
  "meta": { "page": 1, "page_size": 20, "total": 5 }
}
```

##### Menambahkan Produk

```
POST /api/supplier/products
```

Mengaitkan produk yang sudah ada ke pemasok saat ini.

**Body permintaan**:

```json
{
  "product_id": "PrOdEfG456",
  "commission_rate": 0.15
}
```

| Parameter | Tipe | Wajib | Keterangan |
|------|------|------|------|
| product_id | string | Ya | ID produk (Hashid) |
| commission_rate | float | Tidak | Rasio komisi, default 0.1 |

**Respons**: objek SupplierProduct yang dibuat

**Kesalahan**:

| code | Skenario |
|------|------|
| 422 | Produk sudah ditugaskan ke pemasok ini |

##### Menghapus Produk

```
DELETE /api/supplier/products/{id}
```

Membatalkan keterkaitan produk dengan pemasok.

**Respons**:

```json
{
  "code": 0,
  "message": "ok",
  "data": null
}
```

---

#### 3. Manajemen Settlement

##### Mendapatkan Daftar Settlement

```
GET /api/supplier/settlements
```

**Respons**: semua settlement pemasok saat ini, urut berdasarkan waktu pembuatan terbalik

```json
{
  "code": 0,
  "data": [{
    "id": "SeTtLe123",
    "supplier_id": "aBc123XyZ",
    "period_start": "2026-05-01",
    "period_end": "2026-05-31",
    "total_sales": "15000.00",
    "commission": "1500.0000",
    "payable": "13500.0000",
    "status": "pending",
    "created_at": "2026-06-01T02:17:00Z"
  }]
}
```

| Kolom | Keterangan |
|------|------|
| total_sales | Total penjualan pesanan yang selesai dalam periode |
| commission | Total komisi platform |
| payable | Jumlah terutang ke pemasok (total_sales - commission) |
| status | `pending` / `paid` |

---

#### 4. Penarikan

##### Mengajukan Penarikan

```
POST /api/supplier/withdraw
```

> Operasi ini memerlukan konfirmasi ulang kata sandi (kolom `confirm_password`), divalidasi oleh `ConfirmationMiddleware`.
> Setelah 5 kali gagal, terkunci 15 menit.

**Body permintaan**:

```json
{
  "amount": "5000.00",
  "confirm_password": "user_password_here",
  "account_info": {
    "method": "bank_transfer",
    "bank_name": "中国工商银行",
    "account_number": "6222021234567890",
    "account_holder": "张三"
  }
}
```

| Parameter | Tipe | Wajib | Keterangan |
|------|------|------|------|
| amount | string | Ya | Jumlah penarikan (string untuk menghindari masalah presisi float) |
| confirm_password | string | Ya | Kata sandi login pengguna (konfirmasi ulang) |
| account_info | object | Ya | Informasi akun penerima |
| account_info.method | string | Ya | Cara penarikan: `bank_transfer` / `alipay` / `wechat` |

**Perhitungan saldo yang dapat ditarik**: jumlah `payable` semua settlement yang selesai - jumlah `amount` semua penarikan yang sedang diproses

**Respons**:

```json
{
  "code": 0,
  "message": "ok",
  "data": null
}
```

**Kesalahan**:

| code | Skenario |
|------|------|
| 422 | Saldo yang dapat ditarik tidak cukup |
| 403 | Konfirmasi kata sandi gagal |

```bash
curl -X POST "https://api.example.com/api/supplier/withdraw" \
  -H "Authorization: Bearer <token>" \
  -H "X-Api-Version: v1" \
  -H "Content-Type: application/json" \
  -d '{"amount":"5000.00","confirm_password":"mypassword","account_info":{"method":"bank_transfer","bank_name":"ICBC","account_number":"6222021234567890"}}'
```

---

### Ringkasan Endpoint API Internal

| Metode | Path | Autentikasi | Konfirmasi kata sandi | Keterangan |
|------|------|------|---------|------|
| POST | `/api/supplier/apply` | Token | - | Mengajukan menjadi pemasok |
| GET | `/api/supplier/products` | Token | - | Melihat produk yang ditugaskan |
| POST | `/api/supplier/products` | Token | - | Menambahkan keterkaitan produk |
| DELETE | `/api/supplier/products/{id}` | Token | - | Menghapus keterkaitan produk |
| GET | `/api/supplier/settlements` | Token | - | Melihat settlement |
| POST | `/api/supplier/withdraw` | Token | Diperlukan | Mengajukan penarikan |

---

## API Eksternal (spesifikasi desain, menunggu implementasi)

API eksternal memungkinkan pemasok mengelola pesanan, sumber daya, dan settlement secara terprogram. Semua permintaan memerlukan autentikasi API Key.

**Base URL**: `https://api.example.com/api`

### Autentikasi

```
Authorization: Bearer sk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
X-Api-Version: v1
```

API Key dibuat oleh admin platform di panel admin `Manajemen Pemasok → API Keys`.

**Persyaratan keamanan**:
- Hanya akses melalui HTTPS
- API Key hanya ditampilkan satu kali saat dibuat, simpan dengan baik
- Disarankan menambahkan IP server ke whitelist

---

### Format Respons

Sama dengan API internal, dengan tambahan `request_id` untuk pelacakan:

```json
{
  "code": 0,
  "message": "ok",
  "data": { ... },
  "request_id": "req_abc123"
}
```

---

### Endpoint

#### 1. Manajemen Pesanan

##### Mendapatkan Daftar Pesanan

```
GET /api/supplier/orders
```

**Parameter Query**:

| Parameter | Tipe | Wajib | Keterangan |
|------|------|------|------|
| page | int | Tidak | Nomor halaman, default 1 |
| page_size | int | Tidak | Jumlah per halaman, default 20, maksimal 50 |
| status | string | Tidak | Filter status: pending/paid/completed/refunded |
| from | date | Tidak | Tanggal mulai YYYY-MM-DD |
| to | date | Tidak | Tanggal akhir YYYY-MM-DD |

##### Mendapatkan Detail Pesanan

```
GET /api/supplier/orders/{id}
```

---

#### 2. Manajemen Sumber Daya

##### Mendapatkan Daftar Sumber Daya

```
GET /api/supplier/resources
```

**Parameter Query**: page, status (active/provisioning/stopped/destroyed), type (server/ip/disk/domain)

##### Mendapatkan Status Sumber Daya

```
GET /api/supplier/resources/{id}/status
```

---

#### 3. Manajemen Settlement

##### Mendapatkan Daftar Settlement

```
GET /api/supplier/settlements
```

##### Mendapatkan Detail Settlement

```
GET /api/supplier/settlements/{id}
```

---

#### 4. Penarikan

##### Mengajukan Penarikan

```
POST /api/supplier/withdraw
```

##### Catatan Penarikan

```
GET /api/supplier/withdraws
```

---

#### 5. Manajemen Produk

##### Mendapatkan Produk Saya

```
GET /api/supplier/products
```

##### Mengajukan Permohonan Penayangan Produk

```
POST /api/supplier/products
```

---

### Ringkasan Endpoint API Eksternal

| Metode | Path | Keterangan |
|------|------|------|
| GET | `/api/supplier/orders` | Daftar pesanan |
| GET | `/api/supplier/orders/{id}` | Detail pesanan |
| GET | `/api/supplier/resources` | Daftar sumber daya |
| GET | `/api/supplier/resources/{id}/status` | Status sumber daya |
| GET | `/api/supplier/settlements` | Daftar settlement |
| GET | `/api/supplier/settlements/{id}` | Detail settlement |
| POST | `/api/supplier/withdraw` | Mengajukan penarikan |
| GET | `/api/supplier/withdraws` | Catatan penarikan |
| GET | `/api/supplier/products` | Daftar produk |
| POST | `/api/supplier/products` | Mengirimkan produk |

---

## Webhook (menerima event platform)

Pemasok dapat mendaftarkan URL Webhook untuk menerima event real-time. Dikonfigurasi di panel admin.

### Jenis Event

| Event | Waktu pemicu |
|------|----------|
| `order.paid` | Pengguna menyelesaikan pembayaran |
| `order.refunded` | Pesanan telah di-refund |
| `resource.provisioned` | Pengaktifan sumber daya selesai |
| `resource.expiring` | Sumber daya akan segera kedaluwarsa (dalam 7 hari) |
| `resource.destroyed` | Sumber daya telah dihancurkan |
| `settlement.created` | Settlement dibuat |
| `withdrawal.approved` | Penarikan telah disetujui |

### Format Permintaan Webhook

```json
POST {your_webhook_url}
Content-Type: application/json
X-Webhook-Signature: sha256=abc123...
X-Webhook-Event: order.paid

{
  "event": "order.paid",
  "timestamp": "2026-05-20T14:30:00Z",
  "data": {
    "order_id": "abc123",
    "amount": "49.99",
    "currency": "USD"
  }
}
```

**Verifikasi tanda tangan**: `HMAC-SHA256(payload, webhook_secret)`

---

## Pembatasan Frekuensi

| Endpoint | Batas |
|------|------|
| API Internal | 60 req/menit per pengguna (default) |
| Login API Internal | 5 req/menit |
| API Eksternal | 120 req/menit per API Key (aturan `supplier_api`, berlaku melalui `RateLimitMiddleware`) |
| Penarikan API Eksternal | 10 req/menit (nilai yang disarankan, dapat diatur di `config/security.php`) |

> Aturan pembatasan API eksternal didefinisikan di `rate_limits.supplier_api` pada `config/security.php`,
> dieksekusi secara terpadu oleh `RateLimitMiddleware` untuk path `/api/supplier/external/*` (penghitungan INCR atomik,
> jika Redis tidak tersedia maka dibiarkan lewat).

Header pembatasan:

```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 58
X-RateLimit-Reset: 1680000000
```

---

## Contoh SDK

### PHP

```php
$token = 'user_access_token_here';
$client = new GuzzleHttp\Client([
    'base_uri' => 'https://api.example.com/api/',
    'headers' => [
        'Authorization' => "Bearer {$token}",
        'X-Api-Version' => 'v1',
        'Accept'        => 'application/json',
    ],
]);

// 申请成为供应商
$response = $client->post('supplier/apply', [
    'json' => [
        'company_name'  => '示例科技有限公司',
        'contact_name'  => '张三',
        'contact_phone' => '13800138000',
        'contact_email' => 'zhangsan@example.com',
    ],
]);
$result = json_decode($response->getBody(), true);

// 获取结算单
$response = $client->get('supplier/settlements');
$settlements = json_decode($response->getBody(), true);

// 申请提现
$response = $client->post('supplier/withdraw', [
    'json' => [
        'amount'           => '5000.00',
        'confirm_password' => 'mypassword',
        'account_info'     => [
            'method'          => 'bank_transfer',
            'bank_name'       => '中国工商银行',
            'account_number'  => '6222021234567890',
        ],
    ],
]);
```

### Python

```python
import requests

headers = {
    'Authorization': 'Bearer <user_access_token>',
    'X-Api-Version': 'v1',
}

# 获取已分配商品
resp = requests.get('https://api.example.com/api/supplier/products',
                     headers=headers)
products = resp.json()

# 申请提现
resp = requests.post('https://api.example.com/api/supplier/withdraw',
                      headers=headers,
                      json={
                          'amount': '5000.00',
                          'confirm_password': 'mypassword',
                          'account_info': {
                              'method': 'bank_transfer',
                              'bank_name': 'ICBC',
                              'account_number': '6222021234567890',
                          },
                      })
print(resp.json())
```

---

## Saran Penanganan Kesalahan

1. **429 terbatas**: tunggu `Retry-After` detik lalu coba lagi
2. **401 tidak terotorisasi**: periksa apakah Token valid, apakah sudah kedaluwarsa
3. **403 dilarang**: periksa apakah peran akun `supplier`; kegagalan konfirmasi kata sandi harus menunggu pembukaan kunci
4. **422 gagal validasi**: perbaiki parameter permintaan berdasarkan kolom `message`
5. **5xx kesalahan server**: retry exponential backoff (1s -> 5s -> 25s)

---

## Referensi Endpoint Panel Admin

Berikut adalah endpoint terkait pemasok untuk admin (hanya untuk penggunaan backend, memerlukan peran Admin):

| Metode | Path | Keterangan |
|------|------|------|
| GET | `/admin/api/suppliers` | Daftar pemasok (mendukung filter status) |
| GET | `/admin/api/suppliers/export` | Ekspor pemasok ke Excel |
| POST | `/admin/api/suppliers/{id}/approve` | Menyetujui pemasok |
| POST | `/admin/api/suppliers/{id}/settle` | Membuat settlement |
| POST | `/admin/api/suppliers/withdraws/{id}/approve` | Menyetujui penarikan |
| GET | `/admin/api/suppliers/{id}/api-keys` | Melihat daftar API Key pemasok |
| POST | `/admin/api/suppliers/{id}/api-keys` | Membuat API Key (hanya mengembalikan Key mentah satu kali) |
| DELETE | `/admin/api/suppliers/api-keys/{id}` | Mencabut API Key |
