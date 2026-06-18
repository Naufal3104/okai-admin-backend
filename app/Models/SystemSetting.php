<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    use HasFactory;
    
    protected $table = 'system_settings';

    protected $fillable = [
        'brand_name',
        'brand_logo',
        'maintenance_mode',
        'biteship_api_key',
        'xendit_api_key',
        'binderbyte_api_key',
        'google_client_id'
    ];
}