<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_audit_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('pending'); // pending|running|completed|failed

            $table->unsignedInteger('total_products')->default(0);
            $table->unsignedInteger('scanned_products')->default(0);
            $table->unsignedInteger('clean_products')->default(0);
            $table->unsignedInteger('products_with_issues')->default(0);
            $table->unsignedInteger('total_issues')->default(0);
            $table->unsignedTinyInteger('average_score')->default(0);

            // Issue code => count, for the summary tiles. Kept on the session so
            // the overview does not re-aggregate a quarter-million rows on every
            // page load.
            $table->json('issue_breakdown')->nullable();

            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('seo_audit_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seo_audit_session_id')->constrained()->cascadeOnDelete();

            $table->string('product_id');
            $table->string('product_title')->nullable();
            $table->string('handle')->nullable();
            $table->string('sku')->nullable();

            $table->string('meta_title', 512)->nullable();
            $table->string('meta_description', 1024)->nullable();
            $table->unsignedSmallInteger('meta_title_length')->default(0);
            $table->unsignedSmallInteger('meta_description_length')->default(0);
            $table->unsignedInteger('description_length')->default(0);

            $table->unsignedSmallInteger('image_count')->default(0);
            $table->unsignedSmallInteger('images_missing_alt')->default(0);
            $table->unsignedSmallInteger('tag_count')->default(0);

            // Issue codes for this product, resolved by SeoAuditItem::ISSUES.
            $table->json('issues')->nullable();
            $table->unsignedSmallInteger('issue_count')->default(0);
            $table->unsignedTinyInteger('score')->default(100);

            $table->timestamps();

            $table->index(['seo_audit_session_id', 'score']);
            $table->index(['seo_audit_session_id', 'issue_count']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_audit_items');
        Schema::dropIfExists('seo_audit_sessions');
    }
};
