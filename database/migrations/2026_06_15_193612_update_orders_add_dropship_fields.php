<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {

            $table->foreignId('warehouse_id')
                ->nullable()
                ->constrained('product_warehouses')
                ->nullOnDelete();

            $table->boolean('is_dropship')
                ->default(false);

            $table->string('dropshipper_name')
                ->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {

            $table->dropForeign(['warehouse_id']);

            $table->dropColumn([
                'warehouse_id',
                'is_dropship',
                'dropshipper_name'
            ]);
        });
    }
};
