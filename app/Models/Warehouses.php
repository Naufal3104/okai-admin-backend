<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Warehouses extends Model
{
    use HasFactory;
    protected $table = 'warehouses';
    protected $primaryKey = 'id_warehouse';

    protected $fillable = ['name', 'address', 'city', 'province', 'postal_code'];

    // Relasi: Satu gudang memiliki banyak stok produk melalui tabel pivot
    public function productStocks() {
        return $this->hasMany(ProductWarehouses::class, 'id_warehouse');
    }
}
