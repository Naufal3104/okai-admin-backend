<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Orders extends Model
{
    use HasFactory;
    protected $table = 'orders';

    protected $fillable = [
        'invoice_no',
        'user_id',
        'affiliate_id',
        'total_price',
        'address',
        'payment_method',
        'payment_url',
        'status',
        'id_promotion',
        'courier_company',
        'courier_type',
        'shipping_cost',
        'waybill_id',
    ];

    public function user() {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function promotion() {
        return $this->belongsTo(Promotions::class, 'id_promotion');
    }

    public function affiliate()
    {
        return $this->belongsTo(Affiliates::class, 'affiliate_id');
    }

    public function invoice() {
        return $this->hasOne(Invoices::class, 'id_order');
    }

    public function items() {
        return $this->hasMany(OrderItems::class, 'order_id');
    }

    public function order_items() {
        return $this->hasMany(OrderItems::class, 'order_id');
    }
}
