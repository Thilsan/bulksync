<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_content_pushes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();

            $table->string('product_id');
            // Resolved after the fact rather than at push time: the handle is
            // only needed to match a GA4 landing page, and fetching it during a
            // push would add a Shopify call per product to the slowest screen
            // in the app.
            $table->string('handle')->nullable();
            $table->string('product_title')->nullable();

            $table->string('meta_title', 512)->nullable();
            $table->string('meta_description', 1024)->nullable();
            $table->timestamp('pushed_at');

            // Organic sessions either side of the push. Nullable because a push
            // is recorded long before it can be measured, and because GA4 may
            // have nothing at all to say about a URL.
            $table->unsignedInteger('sessions_before')->nullable();
            $table->unsignedInteger('sessions_after')->nullable();
            $table->timestamp('measured_at')->nullable();
            $table->string('measurement_status')->default('pending'); // pending|measured|no_data|failed
            $table->text('measurement_note')->nullable();

            $table->timestamps();

            $table->index(['store_id', 'measurement_status']);
            $table->index(['store_id', 'pushed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_content_pushes');
    }
};
