<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Promotions extends Model
{
    use HasFactory;
    protected $table = 'promotions';
    protected $primaryKey = 'id_promotion';

    protected $fillable = ['code', 'description', 'type', 'value', 'max_usage', 'used_count', 'start_date', 'end_date', 'is_active'];

    // Relasi: Satu promosi bisa digunakan di banyak pesanan
    public function orders()
    {
        return $this->hasMany(Orders::class, 'id_promotion');
    }
}
