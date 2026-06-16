<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {

            $table->boolean('is_dropship_enabled')
                ->default(false);

            $table->enum('dropship_discount_type', [
                'percent',
                'fixed'
            ])->nullable();

            $table->decimal('dropship_discount_value', 15, 2)
                ->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {

            $table->dropColumn([
                'is_dropship_enabled',
                'dropship_discount_type',
                'dropship_discount_value'
            ]);
        });
    }
};
