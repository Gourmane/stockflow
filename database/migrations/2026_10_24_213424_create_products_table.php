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
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            $table->foreignId('category_id')
                ->constrained('categories')
                ->restrictOnDelete();

            $table->string('sku', 100)->unique();

            $table->string('name', 255)->index();

            $table->string('brand', 100)->nullable();

            $table->decimal('unit_price', 12, 2)->nullable();

            $table->unsignedInteger('quantity')->default(0);

            $table->unsignedInteger('min_stock')->default(0);

            $table->boolean('is_active')->default(true);

            $table->text('image_url')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
