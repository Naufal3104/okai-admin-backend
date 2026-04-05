<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 50)->unique()->nullable();
            $table->string('name');
            $table->string('category', 100)->default('General');
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->integer('stock')->default(0);
            $table->string('warehouse', 100)->default('Gudang Utama (Surabaya)');
            $table->string('image_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
