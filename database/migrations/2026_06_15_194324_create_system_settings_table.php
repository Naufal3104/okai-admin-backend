<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {

            $table->id();

            $table->string('brand_name')->nullable();
            $table->string('brand_logo')->nullable();

            $table->boolean('maintenance_mode')
                ->default(false);

            $table->text('biteship_api_key')->nullable();
            $table->text('xendit_api_key')->nullable();
            $table->text('binderbyte_api_key')->nullable();
            $table->text('google_client_id')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
