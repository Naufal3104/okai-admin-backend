<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Affiliates extends Model
{
    use HasFactory;
    protected $table = 'affiliates';

    protected $fillable = [
    'user_id',
    'full_name',
    'email',
    'phone',
    'social_platform',    
    'social_username',    
    'promotional_plan',   
    'status',
    'commission_rate',
    'affiliate_code',
];

    public function user() {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function commissions() {
        return $this->hasMany(AffiliateCommissions::class, 'affiliate_id');
    }
}
