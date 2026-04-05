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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->integer('affiliate_id')->nullable();
            $table->decimal('total_price', 10, 2)->nullable();
            $table->enum('status', ['pending', 'paid', 'shipped', 'delivered'])->default('pending');
            $table->foreignId('id_promotion')->nullable()->constrained('promotions', 'id_promotion')->onDelete('set null');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
