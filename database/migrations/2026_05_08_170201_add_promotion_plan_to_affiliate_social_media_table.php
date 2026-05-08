<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_social_media', function (Blueprint $table) {
            $table->string('promotion_plan')
                ->nullable()
                ->after('url');
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_social_media', function (Blueprint $table) {
            $table->dropColumn('promotion_plan');
        });
    }
};
