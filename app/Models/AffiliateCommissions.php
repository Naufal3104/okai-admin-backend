<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AffiliateCommissions extends Model
{
    protected $table = 'affiliate_commissions'; // Sesuai screenshot DB kamu
    protected $fillable = ['order_id', 'affiliate_id', 'commission_amount', 'status'];

    public function affiliate()
    {
        // Balik ke model Affiliates (Plural)
        return $this->belongsTo(Affiliates::class, 'affiliate_id');
    }
}