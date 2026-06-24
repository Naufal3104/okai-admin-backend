<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliates', function (Blueprint $table) {
            $table->string('account_number')->nullable()->after('commission_rate');
            $table->string('bank_name')->nullable()->after('account_number');
            $table->string('account_holder_name')->nullable()->after('bank_name');
        });
    }

    public function down(): void
    {
        Schema::table('affiliates', function (Blueprint $table) {
            $table->dropColumn([
                'account_number',
                'bank_name',
                'account_holder_name'
            ]);
        });
    }
};
