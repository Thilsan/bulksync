<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            // As Search Console holds it: "sc-domain:example.com" for a domain
            // property, or the full URL with its trailing slash for a
            // URL-prefix one. Stored verbatim because the API will not accept
            // anything it did not issue.
            $table->string('gsc_site_url')->nullable()->after('ga4_property_id');
        });

        Schema::table('seo_content_pushes', function (Blueprint $table) {
            // Search Console's side of the same before/after comparison.
            // Nullable throughout: a store may have Analytics but not Search
            // Console, and half an answer is better than none.
            $table->unsignedInteger('impressions_before')->nullable()->after('sessions_after');
            $table->unsignedInteger('impressions_after')->nullable()->after('impressions_before');
            $table->unsignedInteger('clicks_before')->nullable()->after('impressions_after');
            $table->unsignedInteger('clicks_after')->nullable()->after('clicks_before');
            $table->decimal('ctr_before', 5, 2)->nullable()->after('clicks_after');
            $table->decimal('ctr_after', 5, 2)->nullable()->after('ctr_before');
            $table->decimal('position_before', 5, 1)->nullable()->after('ctr_after');
            $table->decimal('position_after', 5, 1)->nullable()->after('position_before');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('gsc_site_url');
        });

        Schema::table('seo_content_pushes', function (Blueprint $table) {
            $table->dropColumn([
                'impressions_before', 'impressions_after',
                'clicks_before', 'clicks_after',
                'ctr_before', 'ctr_after',
                'position_before', 'position_after',
            ]);
        });
    }
};
