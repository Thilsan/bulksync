<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the tracking sheet carries copy for this SKU.
 *
 * This is the signal that decides whether content has to be written, and it is
 * not the same as what Shopify holds. The sheet is where the brand team supplies
 * everything they are going to supply — so a blank Description column there means
 * nobody is coming with copy later, and the choice is to generate it or go
 * without. What Shopify already has only says whether writing would overwrite
 * something.
 *
 * Null means the sheet has not been read for this SKU, which is not "blank".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_request_skus', function (Blueprint $table) {
            $table->boolean('sheet_has_description')->nullable()->after('has_description');
            $table->timestamp('sheet_checked_at')->nullable()->after('sheet_has_description');
        });
    }

    public function down(): void
    {
        Schema::table('product_request_skus', function (Blueprint $table) {
            $table->dropColumn(['sheet_has_description', 'sheet_checked_at']);
        });
    }
};
