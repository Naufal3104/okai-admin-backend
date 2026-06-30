# Dokumentasi Sistem Aplikasi OKAI (Backend & Frontend)

Dokumentasi ini menjelaskan arsitektur sistem, struktur proyek, alur bisnis utama (*core business flows*), serta integrasi pihak ketiga pada platform OKAI.

---

## 1. Arsitektur Proyek & Struktur Direktori

Platform OKAI terdiri dari tiga aplikasi utama:

| Direktori | Teknologi | Fungsi |
|-----------|-----------|--------|
| `okai-admin(BE)` | Laravel 11 / PHP REST API | Pusat data, logika bisnis, stok, transaksi, afiliasi, integrasi API pihak ketiga |
| `okai-admin(FE)` | React / Vite / TailwindCSS | Dashboard manajemen Super Admin dan Admin Gudang |
| `okai-store` | Next.js 15 / React 19 / TailwindCSS | E-Commerce publik untuk Customer dan Affiliate |

---

## 2. Hubungan Database & Relasi Entitas Utama

```
                  +-------------------+
                  |       Users       |
                  +---------+---------+
                            | 1
                            | 1..* (Mengelola)
                  +---------v---------+
                  |    Warehouses     |
                  +---------+---------+
                            | 1
                            | 1..* (Stok di Gudang)
+--------------+  |  +------v--------------+
|   Products   <--+--+ ProductWarehouses   |
+-------+------+     +---------------------+
        ^ 1
        | 1..*
+-------+------+     +---------------------+
|  OrderItems  +----->       Orders        |
+--------------+ 1..*+----------+----------+
                                | 1
                                | 1 (Opsional)
                     +----------v----------+
                     |     Affiliates      |
                     +----------+----------+
                                | 1
                                | 1..*
                     +----------v----------+
                     | AffiliateCommissions|
                     +---------------------+
```

**Penjelasan entitas kunci:**
- **`Users`**: Memiliki role `super_admin`, `admin` (admin gudang), atau `customer`. Setiap gudang memiliki constraint `unique` pada `user_id` — satu admin hanya bisa mengelola satu gudang.
- **`Warehouses`**: Setiap gudang dikelola oleh satu `User` ber-role `admin`.
- **`ProductWarehouses`**: Tabel pivot penyimpan kuantitas stok (`stock`) produk per gudang.
- **`Orders`**: Menyimpan data pembelian dengan kolom `warehouse_id`, `courier_company`, `courier_type`, `payment_url`, dan status (`pending`, `paid`, `shipped`, `delivered`, `cancelled`).
- **`Affiliates`**: Data mitra pemasaran dengan kode referal unik (`affiliate_code`) dan rekening bank pencairan komisi.
- **`AffiliateCommissions`**: Rekaman komisi per order dengan siklus status `pending → approved → paid`.

---

## 3. Alur Bisnis Utama (Core System Flows)

### A. Alur Checkout & Split Order (Pemisahan Gudang)

Jika pelanggan membeli produk dari gudang yang berbeda, sistem memecah checkout menjadi order terpisah per gudang.

```
Pelanggan klik Checkout
  |
  +-- Sistem evaluasi stok per produk di semua gudang
  |
  +-- Pilih gudang terbaik per produk (prioritas: kota sama > stok terbanyak)
  |
  +-- Kelompokkan produk per gudang
  |
  +-- Hitung ongkir per gudang (Binderbyte → Biteship → Flat Rp25.000)
  |
  +-- Buat Order terpisah per gudang di database
  |
  +-- [COD] Kurangi stok langsung + catat komisi affiliate
  |
  +-- [Non-COD] Buat Invoice Xendit per order → simpan payment_url
  |
  +-- Response 201 { orders: [...], payment_url: "..." }
```

**Aturan diskon pada split order:** Diskon (`discount_amount`) hanya diterapkan pada order pertama. Order kedua dan seterusnya tidak mendapat diskon.

---

### B. Alur Pengurangan Stok Gudang (Stock Control)

Untuk menghindari *overselling*, pengurangan stok dikelola dengan aturan ketat:

| Skenario | Waktu Pemotongan Stok |
|----------|----------------------|
| **COD** | Langsung saat order dibuat (reservasi instan) |
| **Non-COD (Transfer/Virtual Account)** | Saat webhook Xendit `PAID` diterima |
| **Simulasi Kirim / Biteship webhook `delivered`** | Saat status berubah ke `delivered` (jika belum dipotong) |

**Double-Deduction Guard:** Sistem selalu memeriksa tipe pembayaran sebelum memotong stok. Order COD yang ditandai `paid` tidak akan dipotong stoknya lagi.

**Sinkronisasi Stok Global:** Setiap pengurangan stok di `product_warehouses` otomatis menjumlahkan total stok semua gudang dan memperbarui kolom `stock` di tabel `products`.

---

### C. Alur Pembayaran Xendit (Payment Gateway)

```
[Checkout Non-COD]
POST /api/orders → Buat order "pending" → POST Xendit /v2/invoices
→ Simpan payment_url → Customer diarahkan ke halaman bayar Xendit

[Pembayaran Sukses]
POST /api/xendit/webhook { status: "PAID", external_id: "INV-xxx" }
→ Temukan order by invoice_no
→ processOrderPaid(): status = "paid", kurangi stok, hitung komisi
→ Response 200

[Pembayaran Kadaluarsa]
POST /api/xendit/webhook { status: "EXPIRED" }
→ Semua order dengan payment_url yang sama → status = "cancelled"
```

---

### D. Alur Kemitraan Afiliasi (Affiliate System)

1. **Registrasi**: Customer mendaftar via `/register-affiliate`. Sistem menyimpan data sosial media dan rencana promosi dengan status `pending`.
2. **Persetujuan Admin**: Admin mengubah status menjadi `active` dan sistem otomatis membuat `affiliate_code` unik (`KMB-XXX9999`).
3. **Pelacakan Kunjungan**: Klik tautan referal (`/share?ref=KODE`) menyimpan cookie di browser.
4. **Pencatatan Komisi**: Saat checkout dengan kode afiliasi, komisi dihitung per produk:
   - Tipe `percent`: `qty × harga × persentase`
   - Tipe `fixed`: `qty × nilai_tetap`
   - Self-referral (kode milik sendiri) diblokir — tidak ada komisi dicatat.
5. **Approval Komisi**: Saat order berstatus `delivered`, komisi berubah menjadi `approved` dan `withdrawal_request` dibuat otomatis.
6. **Pencairan**: Admin menandai withdrawal sebagai `paid` → status komisi ikut berubah menjadi `paid`.

---

### E. Pengendalian Hak Akses (RBAC)

#### Sisi Admin (`okai-admin`)

| Role | Products | Warehouses | Users/Settings/Analytics | System Settings |
|------|----------|-----------|--------------------------|-----------------|
| `super_admin` | Full CRUD | Full CRUD | Akses penuh | Hanya `admin@gmail.com` |
| `admin` (gudang) | Read-only | Update stok & detail saja | Diblokir (403) | Diblokir (403) |

**System Settings** hanya dapat diakses oleh `super_admin` yang emailnya adalah `admin@gmail.com`. Super admin lain mendapat `403 Unauthorized` meskipun memiliki role yang sama.

#### Sisi Store (`okai-store`)

| Status User | Akses |
|-------------|-------|
| Belum login | Berbelanja publik, lihat produk |
| Customer biasa | Berbelanja, checkout, riwayat order, tulis ulasan |
| Afiliasi aktif | Semua customer + dashboard afiliasi, komisi, penarikan dana |

---

## 4. Integrasi Pihak Ketiga

### A. Xendit (Payment Gateway)
- **Endpoint pembuatan tagihan:** `POST https://api.xendit.co/v2/invoices`
- **Webhook menerima:** `POST /api/xendit/webhook`
  - `PAID` / `SETTLED` → order menjadi `paid`, stok dikurangi
  - `EXPIRED` → order menjadi `cancelled`
- **Konfigurasi:** `XENDIT_SECRET_KEY` di `.env`

### B. Biteship (Logistik)
- **Kalkulasi tarif:** `POST https://api.biteship.com/v1/rates/couriers`
- **Request pickup:** `POST https://api.biteship.com/v1/orders`
  - Nomor resi (`waybill_id`) disimpan di tabel `orders`
- **Webhook tracking:** `POST /api/biteship/webhook`
  - `delivered` → order jadi `delivered`, komisi di-approve
  - `rejected/cancelled/returned` → order jadi `cancelled`
- **Konfigurasi:** `BITESHIP_API_KEY` di `.env` atau System Settings

### C. Binderbyte (Kalkulasi Ongkir Alternatif)
- **Endpoint:** `GET https://api.binderbyte.com/v1/cost`
- Digunakan sebagai provider **utama** sebelum fallback ke Biteship
- **Konfigurasi:** `BINDERBYTE_API_KEY` di `.env` atau System Settings

### D. Google OAuth (Opsional)
- Login dengan akun Google untuk customer di `okai-store`
- **Konfigurasi:** `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` di `.env`

---

## 5. Penyimpanan Data Ekspedisi

Data ekspedisi disimpan di **dua kolom terpisah** pada tabel `orders`:

| Kolom | Contoh Nilai | Keterangan |
|-------|-------------|------------|
| `courier_company` | `JNE` | Nama perusahaan ekspedisi |
| `courier_type` | `REG` | Tipe layanan pengiriman |

---

## 6. Pengujian Otomatis

Proyek dilengkapi dengan 4 file test PHPUnit di `tests/Feature/`:

| File | Jenis | Tests |
|------|-------|-------|
| `SecurityValidationTest.php` | Validation & Access Control | 5 |
| `AffiliateControllerTest.php` | Unit Testing | 7 |
| `OrderFlowTest.php` | Integration Testing | 15 |
| `CornerCaseTest.php` | Edge Case & Security | 20 |

**Hasil terakhir:** 47/47 passed — lihat [`TESTING.md`](file:///C:/Users/naufa/Okai/okai-admin(BE)/TESTING.md) untuk dokumentasi lengkap.

---

## 7. Cara Menjalankan Aplikasi

### Prasyarat
- PHP >= 8.2 & Composer
- Node.js >= 20
- MySQL / MariaDB

### Backend (`okai-admin(BE)`)
```bash
cd "okai-admin(BE)"
composer install
cp .env.example .env
php artisan key:generate
# Konfigurasi DB di .env, lalu:
php artisan migrate --seed
php artisan serve
```

### Admin Portal (`okai-admin(FE)`)
```bash
cd "okai-admin(FE)"
npm install
npm run dev
```

### Online Store (`okai-store`)
```bash
cd okai-store
npm install
npm run dev
```

### Menjalankan Test
```bash
cd "okai-admin(BE)"

# Semua test
php artisan test

# Satu file
php artisan test tests/Feature/OrderFlowTest.php

# Filter nama test tertentu
php artisan test --filter "multi_warehouse"
```

---

## 8. Variabel Lingkungan Kritis (`.env`)

| Key | Keterangan | Wajib? |
|-----|-----------|--------|
| `DB_CONNECTION` | `mysql` (production) | Ya |
| `XENDIT_SECRET_KEY` | API Key Xendit untuk invoice | Ya |
| `BITESHIP_API_KEY` | API Key Biteship logistik | Ya (jika pakai Biteship) |
| `BINDERBYTE_API_KEY` | API Key Binderbyte ongkir | Ya (jika pakai Binderbyte) |
| `FRONTEND_URL` | URL store Next.js (untuk redirect Xendit) | Ya |
| `GOOGLE_CLIENT_ID` | OAuth Google login | Opsional |
| `GOOGLE_CLIENT_SECRET` | OAuth Google login | Opsional |
