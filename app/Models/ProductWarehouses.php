<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductWarehouses extends Model
{
    use HasFactory;
    protected $table = 'product_warehouses';

    protected $fillable = ['id_product', 'id_warehouse', 'stock'];

    public function product() {
        return $this->belongsTo(Products::class, 'id_product');
    }

    public function warehouse() {
        return $this->belongsTo(Warehouses::class, 'id_warehouse');
    }
}
