# Perencanaan Tim CloudPlatform

> Versi: 2026-08-17 (v2) ｜ v1 disusun oleh pipeline multi-agen (PASS_WITH_FIXES); v2 diperbarui oleh Lead berdasarkan hasil eksekusi nyata Phase 0-2
> Dasar: v1 + seluruh komit Phase 0-2 (git 111 commits) + catatan tinjauan dua orang + garis dasar pengujian terukur

## 1. Ikhtisar Status Saat Ini (2026-08-17)

### 1.1 Tingkat Penyelesaian Fase

| Fase | Status | Produk Kunci |
|------|------|----------|
| Phase 0 Penanggulangan | ✅ 4/4 | Rendering faktur nyata, 6 jenis templat notifikasi, rekonsiliasi unverified eksplisit, header CSP/templat lingkungan |
| Phase 1 Jangka Pendek | ✅ 8/8 | Keranjang ubah jumlah, penyatuan status ulasan, rekonsiliasi nyata (laporan Stripe + harian), validasi kondisi refund (72 jam/5 hari + idempoten + indeks TOCTOU), 7 jenis webhook pemasok, perakitan Feature Flags + sisi admin, sinkronisasi dokumentasi, pengujian nyata |
| Phase 2 Jangka Menengah | ✅ 8/8 | 4 item pengaman dana, utang pengujian service/admin, install.sql 31 tabel, pemasangan RbacMiddleware 57 rute, admin masuk image + nginx 8788 + CI dua sisi, regresi audit + login jalur lengkap |
| Phase 3 Jangka Panjang | ✅ 9/9 | Gateway + pembatasan frekuensi terpadu (P4.1), jalur lengkap multi-mata uang (P4.2), rekayasa HarmonyOS + CI (P4.3), penerapan ES (P4.4), penyerapan item observasi (P4.5), 4 item penyimpangan dokumentasi (P3.1), penyempitan izin (P3.2), kunci idempotensi pesanan (P3.3), validasi rating pemasok (P3.4), i18n 7 bahasa (P3.6); tinjauan independen reviewer-gate semua approve |

### 1.2 Garis Dasar Kualitas (terukur, verifikasi serial setelah komit)

- Suite service: **568 tests / 1279 assertions**, 10 skip (semuanya karena kesenjangan lingkungan DB)
- Suite admin: **255 tests / 887 assertions**, 1 skip (jalur tulis DB)
- CI 6 job: PHP Syntax / Admin Tests / Service Tests / Flutter Build / HarmonyOS Project Check / (terkait docker)
- Dana/keamanan semuanya ditinjau dua orang (kesimpulan independen security-auditor + reviewer konsisten); git dikomit berkelompok per tugas, pohon kerja bersih
- Bonus pembayaran: 9 model Encryptable menyembunyikan serialisasi kredensial (penyelidikan menyeluruh P1/P2)

## 2. Daftar Sisa & Risiko (tinjauan 2026-08-17)

### 2.1 Item Pemblokir Deployment (prioritas tinggi)

- **Kesenjangan lingkungan DB_PASSWORD**: service/.env string kosong → semua endpoint DB 500, akar penyebab 9+1 skip test. Bukan masalah kode, perlu diisi nilai oleh tim operasional (templat sudah ada di .env.example root)
- **Kerangka proyek HarmonyOS hilang**: apps/harmonyos hanya 3 file .ets (LoginPage/AuthManager/ApiClient), kurang seluruh konfigurasi proyek hvigor/DevEco → tidak dapat dibangun; CI harmonyos-check sudah jujur melaporkan kesalahan (exit 1)

### 2.2 Penyimpangan Dokumentasi-Kode (4 item P1 belum selesai)

- Filter status GET /api/v1/orders belum diimplementasikan
- Peristiwa push WebSocket hilang (ada klaim dalam dokumentasi terkait websocket_push)
- Cakupan pemicu ticket.updated tidak jelas
- product_attributes skema mati (tidak ada kode yang menggunakannya)

### 2.3 Item Observasi Dana/Keamanan (catatan tinjauan dua orang, level low)

- **Pesanan tanpa kunci idempotensi**: pengajuan berulang cart yang sama dapat menghasilkan pesanan ganda (medium, disarankan dijadwalkan)
- Rating pemasok tidak memvalidasi kepemilikan/status pesanan
- Pemotongan fee bcmath (desimal ke-5, arah kurang tagih <0.0001/transaksi; konsisten dengan perutean tanpa penyimpangan rekonsiliasi)
- WAF multipart body besar masih membaca raw (skenario json dicakup oleh $input, multipart adalah permukaan pertahanan ekstra)
- user_coupons tanpa batasan unik (semantik memungkinkan satu pengguna banyak pesanan banyak baris, observasi)
- nginx-admin tanpa CSP (admin adalah frontend Layui dengan skrip inline, dipertahankan apa adanya)

### 2.4 Inkonsistensi Model Izin (temuan baru P2, menunggu penyempitan)

- 6 penanda izin DB-only / 19 Rbac-only / perbedaan penugasan peran (support/supplier)
- AdminRoleMiddleware mengecualikan finance, tetapi Rbac.php mendefinisikan peran finance

### 2.5 Lainnya

- File bahasa baru i18n masih teks asli Inggris (T6), 7 bahasa belum selesai
- Pemeriksaan struktur CI HarmonyOS akan ditingkatkan menjadi build hvigor nyata setelah kerangka lengkap

## 3. Peta Jalan

Prinsip prioritas (tidak berubah): **dana/keamanan > keandalan pengiriman > penutupan loop bisnis inti > pengalaman & ekstensi**.

### Phase 3 — Penutupan Sisa (1 bulan)

**Tujuan**: menutup semua penyimpangan dan item observasi, deployment dapat direproduksi (pengujian jalur lengkap DB benar-benar hijau).

| Tugas | Terkait | Peran | Dependensi |
|------|------|------|------|
| Penutupan 4 item penyimpangan dokumentasi-kode (implementasi filter orders status / perakitan push WebSocket / koreksi ticket.updated / hapus atau realisasi product_attributes) | Order, WebSocket, Ticket, Product, docs | coder + researcher | Tidak ada |
| Penyempitan model izin (penyelarasan perbedaan DB/Rbac + seed peran + tinjauan ulang AdminRoleMiddleware) | Rbac, install.sql, admin | coder + security-auditor | Tidak ada |
| Kunci idempotensi pesanan (cart→order cegah pesanan ganda) | OrderService | coder | Tidak ada (tinjauan dua orang kategori dana) |
| Validasi kepemilikan/status pesanan rating pemasok | Supplier, Review | coder | Tidak ada |
| Penghubungan operasional DB_PASSWORD + 10 skip test dijalankan nyata | operasional, tests | security-auditor | kerja sama operasional |
| Pelengkapan terjemahan 7 bahasa i18n | file i18n | coder | Tidak ada |

**Penerimaan**: 4 penyimpangan ditutup; matriks izin DB/kode konsisten; test kunci idempotensi; pengujian jalur lengkap DB benar-benar hijau; i18n minimal Cina-Inggris dapat digunakan.

### Phase 4 — Evolusi Arsitektur (1-3 bulan)

**Tujuan**: arsitektur empat lapis terbentuk, mendukung pertumbuhan multi-platform multi-mata uang.

| Tugas | Terkait | Peran | Dependensi |
|------|------|------|------|
| Gateway API independen + pemasangan pembatasan frekuensi terpadu (termasuk kesenjangan graphql) | gateway, route | architect + coder | P3 |
| Konsistensi jalur lengkap multi-mata uang (termasuk strategi pembulatan fee) | Payment, Billing | architect + performance-engineer | sama seperti di atas |
| Rekayasa HarmonyOS: kerangka + build CI nyata + login terhubung | apps/harmonyos | mobile-dev | Tidak ada |
| Penerapan audit ES, ganti solusi memutar | docker, pencarian Product | coder | Tidak ada |
| Penyerapan massal item observasi (WAF multipart / batasan user_coupons / webhook pemasok ujung-ke-ujung) | Security, Order, Supplier | coder + tester | Tidak ada |

**Penerimaan**: k6 memverifikasi pembatasan frekuensi berlaku di semua rute; penghitungan multi-mata uang nol kesalahan; HarmonyOS rilis paket lolos CI; pencarian ES benar-benar dapat digunakan.

## 4. Pembagian Kerja Tim

Inti tetap: Lead(planner) / architect / coder / tester / reviewer / researcher
Ditarik sesuai kebutuhan: mobile-dev / security-architect / security-auditor / performance-engineer

| Fase | Peran yang Ditarik | Deskripsi |
|------|----------|------|
| P3 | coder (utama), researcher, security-auditor | fokus penutupan; izin/idempotensi tinjauan dua orang |
| P4 | architect, coder, mobile-dev, performance-engineer | evolusi arsitektur; security-architect penasihat tetap |

Model kerja sama tidak berubah: pipeline CLAUDE.md (architect→coder→tester→reviewer), fan-out paralel tugas internal P3/P4; **tugas dana/keamanan wajib tinjauan dua orang**; dokumen ini diperbarui di akhir setiap fase (v2 ini disusun langsung oleh Lead, tidak melalui pipeline, dapat ditinjau).

## 5. Cara Pelacakan Risiko

- Daftar ini diperbarui bergulir di akhir setiap fase; temuan baru (seperti inkonsistensi model izin P2, idempotensi pesanan) langsung digabung
- Prioritas rendah yang diketahui (webhook pemasok ujung-ke-ujung, body multipart) sudah masuk batch penyerapan P4, tidak menyebar di luar daftar

## 6. Sumber Bukti Utama

- Komit: git log (111 commits, Phase 0-2 dikelompokkan per tugas)
- Garis dasar pengujian: output terukur suite service/admin
- Catatan tinjauan: pesan tinjauan dua orang P1/P2 (pengaman dana, logout/WAF, RBAC, regresi audit)
- Dokumentasi: v1 (riwayat docs/team-plan.md), docs/audit-report-2026-08-06-v3.md, docs/api-reference.md
