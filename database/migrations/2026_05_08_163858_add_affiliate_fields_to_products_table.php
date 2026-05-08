<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_affiliate_enabled')
                ->default(false)
                ->after('is_active');

            $table->decimal('affiliate_commission', 5, 2)
                ->nullable()
                ->after('is_affiliate_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'is_affiliate_enabled',
                'affiliate_commission'
            ]);
        });
    }
};
