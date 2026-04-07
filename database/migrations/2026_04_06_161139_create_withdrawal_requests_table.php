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
        Schema::create('withdrawal_requests', function (Blueprint $table) {
            $table->id();
            // Relasi ke tabel affiliates yang sudah ada
            $table->foreignId('affiliate_id')->constrained('affiliates')->onDelete('cascade');
            
            $table->decimal('amount', 15, 2); // Nominal yang ditarik
            $table->string('bank_name');      // Nama Bank (BCA, Mandiri, dll)
            $table->string('account_number'); // Nomor Rekening
            $table->string('account_name');   // Nama di Rekening

            // Status: pending, approved, rejected
            $table->string('status')->default('pending');
            $table->text('admin_note')->nullable(); // Alasan jika ditolak
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('withdrawal_requests');
    }
};