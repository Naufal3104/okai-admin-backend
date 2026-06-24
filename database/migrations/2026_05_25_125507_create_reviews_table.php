<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->onDelete('cascade');

            $table->foreignId('product_id')
                ->constrained('products')
                ->onDelete('cascade');

            $table->foreignId('order_id')
                ->constrained('orders')
                ->onDelete('cascade');

            $table->tinyInteger('rating');

            $table->text('comment')
                ->nullable();

            $table->json('images')
                ->nullable();

            $table->text('admin_reply')
                ->nullable();

            $table->timestamps();

            $table->unique([
                'user_id',
                'product_id',
                'order_id'
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
