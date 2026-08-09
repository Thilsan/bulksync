<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique(); // PCR-2026-00045
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();       // requester
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();

            $table->string('request_type', 30)->default('new_brand'); // new_brand | existing_brand
            $table->string('brand');
            $table->string('category');
            $table->string('sub_category')->nullable();
            $table->string('department')->nullable();
            $table->string('collection')->nullable();

            $table->string('status', 40)->default('submitted')->index();
            $table->string('priority', 10)->default('medium')->index();

            $table->date('store_launch_date')->nullable();
            $table->date('online_launch_date')->nullable()->index();

            $table->boolean('supplier_images_available')->default(false);
            $table->boolean('photoshoot_required')->default(false);
            $table->date('photoshoot_scheduled_at')->nullable();

            $table->text('notes')->nullable();

            // Validation roll-up — kept denormalised so dashboard lists never fan out per SKU
            $table->string('validation_status', 20)->default('pending'); // pending|running|completed|failed
            $table->unsignedInteger('total_skus')->default(0);
            $table->unsignedInteger('mapped_skus')->default(0);
            $table->unsignedInteger('pending_skus')->default(0);
            $table->unsignedInteger('not_mapped_skus')->default(0);
            $table->timestamp('validated_at')->nullable();
            $table->text('validation_error')->nullable();

            // Team assignments
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('photographer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('content_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('qa_owner_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('published_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_requests');
    }
};
