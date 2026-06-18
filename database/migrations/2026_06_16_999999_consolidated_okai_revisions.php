<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Consolidation of all Okai Store revisions (Warehouses, Orders, Products, and System Settings).
     */
    public function up(): void
    {
        // 1. WAREHOUSES - Add Admin (User) Assignment
        if (Schema::hasTable('warehouses')) {
            Schema::table('warehouses', function (Blueprint $table) {
                if (!Schema::hasColumn('warehouses', 'user_id')) {
                    $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
                }
            });
        }

        // 2. PRODUCTS - Add Dropship Configuration
        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                if (!Schema::hasColumn('products', 'is_dropship_enabled')) {
                    $table->boolean('is_dropship_enabled')->default(false);
                }
                if (!Schema::hasColumn('products', 'dropship_min_qty')) {
                    $table->integer('dropship_min_qty')->nullable()->default(1);
                }
                if (!Schema::hasColumn('products', 'dropship_discount_type')) {
                    $table->enum('dropship_discount_type', ['percent', 'fixed'])->nullable();
                }
                if (!Schema::hasColumn('products', 'dropship_discount_value')) {
                    $table->decimal('dropship_discount_value', 15, 2)->default(0);
                }
            });
        }

        // 3. ORDERS - Add Warehouse Tracking & Dropship Info
        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                if (!Schema::hasColumn('orders', 'warehouse_id')) {
                    $table->unsignedBigInteger('warehouse_id')->nullable();
                    $table->foreign('warehouse_id')->references('id_warehouse')->on('warehouses')->nullOnDelete();
                }
                if (!Schema::hasColumn('orders', 'is_dropship')) {
                    $table->boolean('is_dropship')->default(false);
                }
                if (!Schema::hasColumn('orders', 'dropshipper_name')) {
                    $table->string('dropshipper_name')->nullable();
                }
            });
        }

        // 4. SYSTEM SETTINGS - Create Table for Dynamic Configuration
        if (!Schema::hasTable('system_settings')) {
            Schema::create('system_settings', function (Blueprint $table) {
                $table->id();
                $table->string('brand_name')->nullable();
                $table->string('brand_logo')->nullable();
                $table->boolean('maintenance_mode')->default(false);
                
                // Secret Keys (Encrypted)
                $table->text('xendit_api_key')->nullable();
                $table->text('biteship_api_key')->nullable();
                $table->text('binderbyte_api_key')->nullable();
                
                // Google OAuth
                $table->text('google_client_id')->nullable();
                $table->text('google_client_secret')->nullable();
                $table->text('google_redirect_uri')->nullable();

                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_settings');

        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropForeign(['warehouse_id']);
                $table->dropColumn(['warehouse_id', 'is_dropship', 'dropshipper_name']);
            });
        }

        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn(['is_dropship_enabled', 'dropship_min_qty', 'dropship_discount_type', 'dropship_discount_value']);
            });
        }

        if (Schema::hasTable('warehouses')) {
            Schema::table('warehouses', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
                $table->dropColumn('user_id');
            });
        }
    }
};
