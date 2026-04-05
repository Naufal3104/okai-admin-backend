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
        Schema::create('affiliate_commissions', function (Blueprint $table) {
            $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');
            $table->foreignId('affiliate_id')->constrained('affiliates')->onDelete('cascade');
            $table->decimal('commission_amount', 10, 2);
            $table->enum('status', ['pending', 'approved', 'paid'])->default('pending');
            $table->timestamps();
            $table->primary(['order_id', 'affiliate_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('affiliate_commissions');
    }
};
