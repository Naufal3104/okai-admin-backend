<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Products extends Model
{
    use HasFactory;
    protected $table = 'products';

    protected $fillable = [
        'name',
        'sku',
        'category',
        'price',
        'description',
        'image_url',
        'is_active',
        'is_affiliate_enabled', // 👈 PASTIKAN NAMANYA INI
        'affiliate_commission', 
    ];
    // Relasi: Satu produk bisa ada di banyak gudang
    public function warehouseStocks() {
        return $this->hasMany(ProductWarehouses::class, 'id_product');
    }

    // Relasi: Produk sering muncul di banyak item pesanan
    public function orderItems() {
        return $this->hasMany(OrderItems::class, 'product_id');
    }
}
