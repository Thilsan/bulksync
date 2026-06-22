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
        Schema::create('sku_check_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sku_check_session_id')->constrained()->cascadeOnDelete();
            $table->string('sku');
            $table->boolean('available')->default(false);
            $table->string('product_title')->nullable();
            $table->string('product_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sku_check_items');
    }
};
