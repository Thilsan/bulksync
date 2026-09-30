<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_audit_items', function (Blueprint $table) {
            // The opening of the body copy. Shopify falls back to this for the
            // meta description when none is set, so showing it is what stops
            // "no meta description" reading as "this page has no description
            // at all" — which is what Shopify's own editor implies.
            $table->string('description_excerpt', 512)->nullable()->after('description_length');
        });
    }

    public function down(): void
    {
        Schema::table('seo_audit_items', function (Blueprint $table) {
            $table->dropColumn('description_excerpt');
        });
    }
};
