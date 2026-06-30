# Testing Documentation — OKAI Backend

> **Proyek:** OKAI E-Commerce (Laravel Backend)
> **Framework Testing:** PHPUnit via `php artisan test`
> **Tanggal dibuat:** 21 Juni 2026
> **Total Test:** 47 tests · 89+ assertions · 4 test file

---

## Daftar Isi

1. [Cara Menjalankan Testing](#cara-menjalankan-testing)
2. [Struktur File Testing](#struktur-file-testing)
3. [Security & Validation Test](#1-securityvalidationtestphp)
4. [Affiliate Controller Test](#2-affiliatecontrollertestphp)
5. [Order Flow Integration Test](#3-orderflowtestphp)
6. [Corner Case & Edge Case Test](#4-cornercasetestphp)
7. [Bug yang Ditemukan](#bug-yang-ditemukan)
8. [Catatan Teknis](#catatan-teknis)

---

## Cara Menjalankan Testing

```bash
# Jalankan semua test sekaligus
php artisan test

# Jalankan hanya satu file
php artisan test tests/Feature/OrderFlowTest.php

# Jalankan test tertentu berdasarkan nama method
php artisan test --filter "multi_warehouse"

# Jalankan dengan output verbose
php artisan test --verbose

# Jalankan tanpa warna (untuk log CI/CD)
php artisan test --no-ansi
```

> **Penting:** Semua test menggunakan `RefreshDatabase` (SQLite in-memory).
> Database produksi tidak tersentuh sama sekali.

---

## Struktur File Testing

```
tests/
└── Feature/
    ├── SecurityValidationTest.php     # Validasi input & kontrol akses
    ├── AffiliateControllerTest.php    # Unit test modul affiliate & withdrawal
    ├── OrderFlowTest.php              # Integration test alur pemesanan penuh
    └── CornerCaseTest.php             # Edge case, boundary, & keamanan
```

---

## 1. `SecurityValidationTest.php`

**Kategori:** Validation Testing & Access Control Testing

File ini menguji lapisan pertama pertahanan sistem: apakah input yang tidak valid
atau akses tidak sah berhasil ditolak sebelum menyentuh logika bisnis.

### Daftar Test

| No | Nama Test | Tujuan | Ekspektasi |
|----|-----------|--------|------------|
| 1 | `test_cart_fails_if_quantity_exceeds_stock` | Tambah ke keranjang melebihi stok gudang | `400` + pesan stok tidak cukup |
| 2 | `test_cart_fails_if_quantity_is_invalid` | Tambah ke keranjang dengan qty negatif | `422` (validation error) |
| 3 | `test_checkout_fails_if_quantity_exceeds_stock` | Checkout dengan qty melebihi stok | `500` (Exception terkontrol) |
| 4 | `test_checkout_fails_if_inputs_missing` | Checkout tanpa `address` & `payment_method` | `422` + pesan validasi |
| 5 | `test_system_settings_restricted_to_admin_gmail` | Super admin lain tidak bisa akses `/system-settings` | `403` untuk email lain, `200` untuk `admin@gmail.com` |

### Skenario Detail

**Test 1 — Stok Keranjang:**
```
User menambah 10 item ke keranjang, padahal stok gudang hanya 5
→ CartController mengecek total stok semua warehouse
→ Sistem mengembalikan 400 "Stok produk tidak mencukupi."
```

**Test 5 — System Settings Guard:**
```
Super admin "other@mail.com"   → GET /api/system-settings → 403 Unauthorized
Super admin "admin@gmail.com"  → GET /api/system-settings → 200 OK
```

---

## 2. `AffiliateControllerTest.php`

**Kategori:** Unit Testing (Controller Layer)

File ini menguji endpoint affiliate secara terisolasi — khususnya fitur update
rekening bank mitra dan proses pencairan komisi (withdrawal).

### Daftar Test

| No | Nama Test | Tujuan | Ekspektasi |
|----|-----------|--------|------------|
| 1 | `test_update_bank_info_validation_fails` | Submit form rekening bank kosong | `422` + error pada `bank_name`, `account_number`, `account_holder_name` |
| 2 | `test_update_bank_info_fails_if_not_affiliate` | User biasa mencoba update bank info | `403` "Anda bukan affiliate." |
| 3 | `test_update_bank_info_succeeds` | Affiliate aktif update rekening bank | `200` + data tersimpan di DB |
| 4 | `test_mark_withdrawal_as_paid_fails_if_not_found` | Tandai lunas withdrawal ID yang tidak ada | `404` |
| 5 | `test_mark_withdrawal_as_paid_fails_if_status_is_pending` | Tandai lunas withdrawal yang masih `pending` | `400` + pesan status harus `approved` |
| 6 | `test_mark_withdrawal_as_paid_fails_if_bank_info_incomplete` | Withdrawal `approved` tapi data rekening mitra kosong | `400` + pesan rekening belum lengkap |
| 7 | `test_mark_withdrawal_as_paid_succeeds` | Proses pencairan lengkap dan benar | `200` + status `withdrawal_requests` dan `affiliate_commissions` jadi `paid` |

### Skenario Detail

**Test 7 — Alur Pencairan Komisi:**
```
Affiliate memiliki:
  - Data rekening bank lengkap (BCA, 123456789, John Doe)
  - Withdrawal request berstatus "approved"
  - Komisi terkait berstatus "approved"

Admin memanggil POST /api/affiliate/withdrawals/{id}/pay
→ withdrawal_requests.status = "paid"
→ affiliate_commissions.status = "paid"
→ Response 200 "Status pencairan berhasil diubah menjadi PAID."
```

---

## 3. `OrderFlowTest.php`

**Kategori:** Integration Testing (Full Business Flow)

File ini adalah inti dari pengujian sistem. Setiap test mensimulasikan seluruh
alur dari database setup → API call → verifikasi database, termasuk interaksi
antar modul (Order <-> Stock <-> Affiliate <-> Xendit).

### Daftar Test

| No | Nama Test | Tujuan | Ekspektasi |
|----|-----------|--------|------------|
| 1 | `test_cod_single_warehouse_order_creates_one_order_and_reduces_stock` | COD satu gudang membuat 1 order dan kurangi stok | 1 order di DB, stok berkurang, global stock tersinkron |
| 2 | `test_multi_warehouse_checkout_creates_separate_orders` | 2 produk dari 2 gudang berbeda | 2 order terpisah, masing-masing terhubung ke gudang yang benar |
| 3 | `test_discount_applied_to_first_order_only_in_split_checkout` | Diskon hanya diterapkan ke order pertama saat split | Order pertama `discount_amount = 20000`, order kedua `= 0` |
| 4 | `test_affiliate_commission_is_calculated_for_percent_type` | Komisi tipe `percent` dihitung benar | `2 x 100.000 x 20% = 40.000` tersimpan di `affiliate_commissions` |
| 5 | `test_affiliate_commission_is_calculated_for_fixed_type` | Komisi tipe `fixed` dihitung benar | `3 x 5.000 = 15.000` tersimpan di `affiliate_commissions` |
| 6 | `test_self_referral_is_prevented` | User tidak bisa gunakan kode afiliasi milik sendiri | `affiliate_id = null` di order, tidak ada record komisi |
| 7 | `test_non_cod_order_does_not_reduce_stock_until_xendit_webhook` | Order non-COD tidak kurangi stok saat dibuat | Stok tetap 10 setelah order, jadi 6 setelah webhook PAID |
| 8 | `test_xendit_expired_webhook_cancels_orders` | Webhook EXPIRED dari Xendit membatalkan order | `orders.status = "cancelled"` |
| 9 | `test_mark_as_paid_reduces_stock_and_transitions_status` | Endpoint mark-paid mengubah status dan kurangi stok | `status = "paid"`, stok berkurang |
| 10 | `test_mark_as_paid_fails_if_already_paid` | Idempotency: panggil mark-paid dua kali | `400` "Hanya pesanan pending yang bisa ditandai lunas." |
| 11 | `test_promotion_code_used_count_increments_on_checkout` | `used_count` promo bertambah 1 setelah checkout | `promotions.used_count = 1` |
| 12 | `test_dropship_order_stores_dropshipper_info` | Order dropship menyimpan nama dropshipper | `is_dropship = true`, `dropshipper_name = "Toko Maju Jaya"` |
| 13 | `test_courier_company_and_type_stored_separately` | Ekspedisi disimpan terpisah di dua kolom | `courier_company` dan `courier_type` tidak mengandung spasi |
| 14 | `test_cart_items_are_cleared_after_successful_checkout` | Keranjang dibersihkan setelah checkout COD | Baris di `carts` terhapus untuk user dan produk tersebut |
| 15 | `test_affiliate_commission_is_not_duplicated_on_double_call` | Komisi tidak dicatat dua kali | Tepat 1 baris di `affiliate_commissions` |

### Diagram Alur: Order COD Split-Warehouse

```
Customer POST /api/orders
  |
  +-- Grouping produk per gudang
  |     +-- Produk A --> Gudang Surabaya
  |     +-- Produk B --> Gudang Bandung
  |
  +-- Hitung ongkir per gudang (Binderbyte / Biteship / Flat Rp25.000)
  |
  +-- Buat Order #1 (Gudang Surabaya)
  |     +-- Simpan order_items
  |     +-- Hapus dari carts
  |     +-- Kurangi stok warehouse (COD)
  |     +-- Hitung komisi affiliate
  |
  +-- Buat Order #2 (Gudang Bandung)
  |     +-- Simpan order_items
  |     +-- Hapus dari carts
  |     +-- Kurangi stok warehouse (COD)
  |     +-- Hitung komisi affiliate
  |
  +-- Response 201 { orders: [Order#1, Order#2] }
```

### Diagram Alur: Order Non-COD + Xendit Webhook

```
Customer POST /api/orders (payment_method: "bca_virtual_account")
  |
  +-- Buat order, status = "pending"
  +-- Stok TIDAK dikurangi
  +-- Buat invoice Xendit --> simpan payment_url
  +-- Response 201

[User membayar di halaman Xendit]

POST /api/xendit/webhook { status: "PAID", external_id: "INV-xxx" }
  |
  +-- Temukan order berdasarkan invoice_no
  +-- processOrderPaid()
  |     +-- status = "paid"
  |     +-- Kurangi stok warehouse
  |     +-- Hitung komisi affiliate
  +-- Response 200
```

---

## 4. `CornerCaseTest.php`

**Kategori:** Edge Case Testing, Security Testing, Boundary Testing

File ini dirancang untuk mencari titik-titik lemah di luar alur normal — input ekstrem,
serangan injeksi, batas stok, dan perilaku sistem saat diberi data yang tidak terduga.

### A. Autentikasi & Otorisasi

| No | Nama Test | Tujuan | Ekspektasi |
|----|-----------|--------|------------|
| 1 | `test_unauthenticated_user_cannot_create_order` | Buat order tanpa token | `401 Unauthorized` |
| 2 | `test_unauthenticated_user_cannot_list_orders` | List order tanpa token | `401 Unauthorized` |
| 3 | `test_system_settings_denied_for_non_privileged_super_admin` | Super admin email lain coba akses System Settings | `403 Forbidden` |
| 4 | `test_system_settings_allowed_for_admin_gmail` | Super admin `admin@gmail.com` akses System Settings | `200 OK` |

### B. Validasi Input

| No | Nama Test | Tujuan | Ekspektasi |
|----|-----------|--------|------------|
| 5 | `test_checkout_with_empty_items_array_fails` | Checkout dengan `items: []` | `422` atau `500` terkontrol |
| 6 | `test_checkout_missing_required_fields_returns_422` | Checkout tanpa field apapun | `422` + validation errors untuk semua field wajib |
| 7 | `test_cart_quantity_zero_fails_validation` | Tambah keranjang dengan `qty: 0` | `422` (min:1 rule) |
| 8 | `test_cart_negative_quantity_fails_validation` | Tambah keranjang dengan `qty: -99` | `422` |
| 9 | `test_cart_float_quantity_is_handled` | Tambah keranjang dengan `qty: 1.5` | `200/201` (cast ke int) atau `422` — tidak boleh `500` |
| 10 | `test_checkout_with_nonexistent_product_is_handled_gracefully` | Checkout dengan `product_id: 99999` yang tidak ada | Tidak boleh `419` (CSRF error) |

### C. Boundary Testing (Batas Stok)

| No | Nama Test | Tujuan | Ekspektasi |
|----|-----------|--------|------------|
| 11 | `test_checkout_quantity_equal_to_stock_succeeds` | `qty == stok` persis (contoh: qty=5, stok=5) | `201 Created` — sistem harus mengizinkan |
| 12 | `test_checkout_quantity_one_above_stock_fails` | `qty == stok + 1` (contoh: qty=6, stok=5) | `500` dari exception "Stok tidak mencukupi" |

> **Insight:** Ini memverifikasi bahwa kondisi `stock >= qty` (bukan `stock > qty`) diterapkan
> dengan benar di `getShippingRate` dan `store` pada `OrderController`.

### D. Security Testing

| No | Nama Test | Tujuan | Ekspektasi |
|----|-----------|--------|------------|
| 13 | `test_xss_payload_in_address_is_stored_as_plain_text` | Masukkan `<script>alert("xss")</script>` di field `address` | Tersimpan sebagai teks biasa di DB (tidak dieksekusi) |
| 14 | `test_sql_injection_in_promotion_code_is_safe` | Masukkan `' OR '1'='1` di field `promotion_code` | `201/400/422` — tidak boleh crash atau bocorkan data |

### E. Business Logic Edge Cases

| No | Nama Test | Tujuan | Ekspektasi |
|----|-----------|--------|------------|
| 15 | `test_promotion_code_lookup_is_case_insensitive` | Input `'upperonly'` harus menemukan kode `'UPPERONLY'` | `used_count` bertambah 1 |
| 16 | `test_nonexistent_promotion_code_is_ignored` | Kode promo yang tidak ada harus diabaikan | Order tetap dibuat, `id_promotion = null` |
| 17 | `test_global_product_stock_syncs_after_cod_order` | Setelah COD, `products.stock` = jumlah stok semua gudang | `products.stock = 5 + 7 = 12` (dari 2 gudang) |
| 18 | `test_duplicate_affiliate_application_is_rejected` | User yang sudah daftar affiliate mencoba daftar lagi | `400` "Anda sudah mengajukan program kemitraan sebelumnya." |
| 19 | `test_order_show_works_by_both_id_and_invoice_no` | Endpoint `/api/orders/{id}` mendukung ID numerik dan `invoice_no` | `200` di kedua kasus |
| 20 | `test_cart_add_fails_if_product_has_zero_stock` | Produk dengan stok = 0 tidak bisa ditambah ke keranjang | `400` |

---

## Bug yang Ditemukan

Testing ini secara aktif menemukan **3 celah nyata** di production logic:

---

### [KRITIS] Bug #1 — `mark-paid` Tidak Ada Role Guard

**Lokasi:** `POST /api/orders/{id}/mark-paid`

**Deskripsi:** Route ini berada di grup middleware `auth:sanctum` biasa, bukan di grup
admin. Artinya **semua user yang login** (termasuk customer) bisa memanggil endpoint
ini dan menandai pesanan apapun sebagai lunas tanpa membayar.

**Dampak:** Customer bisa menandai pesanan orang lain sebagai lunas.

**Rekomendasi Perbaikan:**
```php
// routes/api.php
Route::post('/orders/{id}/mark-paid', [OrderController::class, 'markAsPaid'])
     ->middleware('role:super_admin,admin');
```

---

### [MEDIUM] Bug #2 — Checkout `items: []` Menghasilkan 500 bukan 422

**Lokasi:** `POST /api/orders` dengan `items: []`

**Deskripsi:** Validasi saat ini hanya mengecek `'items' => 'required|array'`. Array
kosong lolos validasi, masuk ke loop `foreach`, tidak membuat satu pun order, dan
akhirnya crash dengan error yang membingungkan.

**Rekomendasi Perbaikan:**
```php
// OrderController@store
'items' => 'required|array|min:1',
```

---

### [MEDIUM] Bug #3 — Product Tidak Ditemukan Diabaikan Secara Diam-diam

**Lokasi:** `OrderController@store`, baris `if (!$product) continue;`

**Deskripsi:** Jika semua `product_id` yang dikirim tidak valid, sistem akan diam-diam
membuat nol order tetapi tetap mengembalikan `201 Created`. Frontend mendapat respons
sukses tapi tidak ada order yang terbentuk.

**Rekomendasi Perbaikan:**
```php
// Setelah loop warehouseGroups, tambahkan validasi:
if (empty($warehouseGroups)) {
    throw new \Exception("Tidak ada produk valid dalam pesanan.");
}
```

---

## Catatan Teknis

### Konfigurasi Database Testing

Testing menggunakan SQLite in-memory yang dikonfigurasi di `phpunit.xml`:

```xml
<php>
    <env name="DB_CONNECTION" value="sqlite"/>
    <env name="DB_DATABASE" value=":memory:"/>
</php>
```

Setiap test class menggunakan `use RefreshDatabase` — semua migrasi dijalankan ulang
dan database dibersihkan sebelum setiap test method.

---

### Constraint Penting dari Migrasi

Beberapa constraint di migrasi mempengaruhi cara penulisan test:

| Tabel | Constraint | Dampak pada Testing |
|-------|-----------|---------------------|
| `warehouses.user_id` | `unique()` (dari `update_order_flow.php`) | Setiap warehouse harus dimiliki user yang berbeda |
| `promotions.type` | `enum(['percentage', 'fixed_amount'])` | Gunakan `'fixed_amount'`, bukan `'fixed'` |
| `products.id` | Auto-increment | Gunakan `Products::create()`, jangan hardcode ID |

---

### Mock HTTP Eksternal (Xendit & Biteship)

Test yang memerlukan panggilan ke API eksternal menggunakan `Http::fake()` sehingga
tidak membutuhkan koneksi internet dan tidak menghabiskan API quota:

```php
Http::fake([
    'api.xendit.co/*' => Http::response([
        'invoice_url' => 'https://checkout.xendit.co/v2/test',
        'id'          => 'test-xendit-id',
        'external_id' => 'INV-TEST',
    ], 200),
]);
```

---

### Pattern Helper Warehouse

Karena migrasi menambahkan constraint `unique()` pada `warehouses.user_id`, helper
`makeWarehouse()` wajib membuat user baru sebagai owner setiap kali dipanggil:

```php
private function makeWarehouse(string $city = 'Surabaya'): Warehouses
{
    $owner = User::factory()->create(); // user unik setiap panggilan
    return Warehouses::create([
        'name'        => 'Gudang ' . $city,
        'city'        => $city,
        'user_id'     => $owner->id,
        'address'     => 'Jl. Test No. 1',
        'province'    => 'Jawa Timur',
        'postal_code' => '60111',
    ]);
}
```

---

## Ringkasan Hasil Run Terakhir

```
PASS  Tests\Feature\AffiliateControllerTest      7 tests   ✅
PASS  Tests\Feature\SecurityValidationTest        5 tests   ✅
PASS  Tests\Feature\OrderFlowTest               15 tests   ✅
PASS  Tests\Feature\CornerCaseTest              20 tests   ✅

Tests:    47 passed (89+ assertions)
Duration: ~150s
```

> Semua test berjalan dengan database SQLite in-memory.
> Tidak ada dependensi terhadap server eksternal (Xendit, Biteship, Binderbyte).
