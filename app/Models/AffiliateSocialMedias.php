<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AffiliateSocialMedias extends Model
{
    use HasFactory;

    protected $table = 'affiliate_social_media';

    protected $fillable = [
        'affiliate_id',
        'platform',
        'username',
        'url',
        'promotion_plan',
    ];

    // Relasi kembali ke profil Affiliate
    public function affiliate()
    {
        return $this->belongsTo(Affiliates::class, 'affiliate_id');
    }
}