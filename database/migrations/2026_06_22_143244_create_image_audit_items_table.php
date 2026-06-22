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
        Schema::create('image_audit_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('image_audit_session_id')->constrained()->cascadeOnDelete();
            $table->string('sku');
            $table->string('product_id');
            $table->string('product_title');
            $table->string('variant_id')->nullable();
            $table->unsignedInteger('image_count')->default(0);
            $table->boolean('has_image')->default(false);
            $table->timestamps();
            $table->index(['image_audit_session_id', 'has_image']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('image_audit_items');
    }
};
