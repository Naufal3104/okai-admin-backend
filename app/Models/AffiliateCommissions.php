<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AffiliateCommissions extends Model
{
    use HasFactory;
    protected $table = 'affiliate_commissions';

    protected $fillable = ['order_id', 'affiliate_id', 'commission_amount', 'status'];

    public function order() {
        return $this->belongsTo(Orders::class, 'order_id');
    }

    public function affiliate() {
        return $this->belongsTo(Affiliates::class, 'affiliate_id');
    }
}
