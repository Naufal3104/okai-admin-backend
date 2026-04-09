<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WithdrawalRequest extends Model
{
    protected $fillable = ['affiliate_id', 'amount', 'bank_name', 'account_number', 'account_name', 'status', 'admin_note'];

    public function affiliate()
    {
        // Relasi ke Model Affiliates (Plural)
        return $this->belongsTo(Affiliates::class, 'affiliate_id');
    }
}