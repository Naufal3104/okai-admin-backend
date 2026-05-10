<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Affiliates extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'full_name',
        'email',
        'phone',
        'affiliate_code',
        'commission_rate',
        'account_number',
        'bank_name',
        'account_holder_name',
        'status',
    ];

    // Relasi ke User (Akun Login)
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // Relasi ke daftar Sosial Media mitra
    public function socialMedia()
    {
        return $this->hasMany(AffiliateSocialMedias::class, 'affiliate_id');
    }

    // Relasi ke daftar Komisi
    public function commissions()
    {
        return $this->hasMany(AffiliateCommissions::class, 'affiliate_id');
    }
}