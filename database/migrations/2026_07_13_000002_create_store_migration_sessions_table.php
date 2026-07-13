<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_migration_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->foreignId('to_store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->string('migration_type')->default('images_only'); // images_only, full_product
            $table->string('token')->unique(); // links back to the cache-based live progress + CSV download
            $table->string('status')->default('running'); // running, completed, failed
            $table->unsignedInteger('total_skus')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_migration_sessions');
    }
};
