<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EventLogs extends Model
{
    use HasFactory;
    protected $table = 'event_logs';

    protected $fillable = ['user_id', 'event_name', 'product_id', 'affiliate_id', 'session_id', 'device', 'page', 'order_id'];
}
