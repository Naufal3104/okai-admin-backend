<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_social_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')
                ->constrained('affiliates')
                ->onDelete('cascade');

            $table->enum('platform', [
                'instagram',
                'tiktok',
                'youtube',
                'twitter',
                'facebook',
                'website'
            ]);

            $table->string('username');
            $table->string('url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_social_media');
    }
};
