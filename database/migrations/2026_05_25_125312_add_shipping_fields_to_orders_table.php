<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('courier_company')
                ->nullable()
                ->after('payment_method');

            $table->string('courier_type')
                ->nullable()
                ->after('courier_company');

            $table->decimal('shipping_cost', 10, 2)
                ->default(0)
                ->after('courier_type');

            $table->string('waybill_id')
                ->nullable()
                ->after('shipping_cost');

            $table->string('payment_url')
                ->nullable()
                ->after('waybill_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'courier_company',
                'courier_type',
                'shipping_cost',
                'waybill_id'
            ]);
        });
    }
};
