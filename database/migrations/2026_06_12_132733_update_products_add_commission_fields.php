<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {

            $table->enum('commission_type', [
                'percent',
                'fixed'
            ])->default('percent');

            $table->decimal('commission_value', 15, 2)
                ->default(0);

            $table->dropColumn('affiliate_commission');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {

            $table->decimal('affiliate_commission', 15, 2)
                ->default(0);

            $table->dropColumn([
                'commission_type',
                'commission_value'
            ]);
        });
    }
};
