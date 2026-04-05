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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id('id_invoice');
            $table->foreignId('id_order')->constrained('orders')->onDelete('cascade');
            $table->string('invoice_number', 50)->unique()->nullable();
            $table->decimal('total_amount', 12, 2);
            $table->enum('payment_status', ['pending', 'paid', 'failed', 'expired'])->default('pending');
            $table->string('payment_method', 50)->nullable();
            $table->dateTime('issued_at')->useCurrent();
            $table->dateTime('paid_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
