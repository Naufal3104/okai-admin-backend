<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homepage_settings', function (Blueprint $table) {

            $table->id();

            $table->string('hero_badge')
                ->default('100% Organik & Premium');

            $table->string('hero_title_1')
                ->default('Lebih Sehat,');

            $table->string('hero_title_2')
                ->default('Lebih Mudah');

            $table->string('hero_highlight')
                ->default('Dicerna.');

            $table->text('hero_description')
                ->nullable();

            $table->string('hero_button_text')
                ->default('Beli Sekarang');

            $table->string('hero_button_link')
                ->default('/product/susu-kambing-original');

            $table->string('hero_image_url')
                ->nullable();

            $table->string('hero_product_title')
                ->default('KAMBI Etawa Premium');

            $table->string('hero_product_subtitle')
                ->default('Box 200g (10 Sachet)');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homepage_settings');
    }
};
