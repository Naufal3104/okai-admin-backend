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
        Schema::table('affiliates', function (Blueprint $table) {
            // Menambahkan kolom total_clicks dengan nilai default 0
            // Kita taruh setelah kolom status atau commission_rate agar rapi di database
            $table->integer('total_clicks')->default(0)->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('affiliates', function (Blueprint $table) {
            // Menghapus kolom jika migration di-rollback
            $table->dropColumn('total_clicks');
        });
    }
};