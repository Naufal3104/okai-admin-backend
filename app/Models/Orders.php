<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Orders extends Model
{
    use HasFactory;
    protected $table = 'orders';

    protected $fillable = [
        'user_id', 
        'invoice_no',
        'total_price', 
        'address', 
        'payment_method', 
        'status', 
        'affiliate_id', 
        'id_promotion'
    ];

    public function user() {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function promotion() {
        return $this->belongsTo(Promotions::class, 'id_promotion');
    }

    public function invoice() {
        return $this->hasOne(Invoices::class, 'id_order');
    }

    public function items() {
        return $this->hasMany(OrderItems::class, 'order_id');
    }
}
