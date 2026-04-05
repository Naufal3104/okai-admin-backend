<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invoices extends Model
{
    use HasFactory;
    protected $table = 'invoices';
    protected $primaryKey = 'id_invoice';

    protected $fillable = ['id_order', 'invoice_number', 'total_amount', 'payment_status', 'payment_method', 'issued_at', 'paid_at'];

    public function order() {
        return $this->belongsTo(Orders::class, 'id_order');
    }
}
