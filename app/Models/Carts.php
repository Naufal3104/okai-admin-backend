<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Carts extends Model
{
    use HasFactory;

    // Mendefinisikan nama tabel secara eksplisit
    protected $table = 'carts';

    protected $fillable = [
        'user_id',
        'product_id',
        'qty',
    ];

    // Relasi ke tabel Users (Pemilik Keranjang)
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // Relasi ke tabel Products (Barang yang ada di keranjang)
    public function product()
    {
        return $this->belongsTo(Products::class, 'product_id');
    }
}