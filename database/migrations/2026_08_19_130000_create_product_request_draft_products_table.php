<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The staging area between the tracking sheet and Shopify.
 *
 * A request's SKUs that came back as not-in-Shopify are pulled off the sheet
 * into these two tables, reviewed and corrected by the team, and only then
 * pushed as draft products. Nothing here is live: a row is a proposal until
 * someone presses push, and the push always creates a draft.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_request_draft_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_request_id')->constrained()->cascadeOnDelete();
            $table->string('handle');                      // groups the variants; also the Shopify handle
            $table->string('style_code')->nullable();      // what grouped them on the sheet
            $table->string('title');
            $table->text('body_html')->nullable();
            $table->string('vendor')->nullable();
            $table->string('product_type')->nullable();
            $table->text('tags')->nullable();
            $table->string('option1_name')->nullable();
            $table->string('option2_name')->nullable();
            $table->string('option3_name')->nullable();
            $table->text('image_src')->nullable();
            // pending → pushed, or failed with the reason Shopify gave.
            $table->string('push_status', 20)->default('pending');
            $table->string('shopify_product_id')->nullable();
            $table->text('push_error')->nullable();
            $table->timestamp('pushed_at')->nullable();
            $table->foreignId('pushed_to_store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->timestamps();

            $table->unique(['product_request_id', 'handle']);
        });

        Schema::create('product_request_draft_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('draft_product_id')->constrained('product_request_draft_products')->cascadeOnDelete();
            $table->string('sku');
            $table->string('option1_value')->nullable();
            $table->string('option2_value')->nullable();
            $table->string('option3_value')->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->decimal('compare_at_price', 12, 2)->nullable();
            $table->string('barcode')->nullable();
            $table->decimal('weight', 10, 3)->nullable();
            $table->string('weight_unit', 10)->nullable();
            $table->unsignedInteger('inventory_qty')->default(0);
            $table->text('image_src')->nullable();
            $table->timestamps();

            $table->unique(['draft_product_id', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_request_draft_variants');
        Schema::dropIfExists('product_request_draft_products');
    }
};
