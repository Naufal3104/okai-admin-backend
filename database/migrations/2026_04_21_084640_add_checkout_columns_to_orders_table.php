<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
   public function up()
{
    Schema::table('orders', function (Blueprint $table) {
        $table->text('address')->after('total_price')->nullable();
        $table->string('payment_method')->after('address')->nullable();
        $table->string('invoice_no')->after('id')->nullable(); // Opsional kalau mau pake nomor resi
    });
}
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            //
        });
    }
};
