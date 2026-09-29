<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_audit_items', function (Blueprint $table) {
            // active | draft | archived. Null for collections, which have no
            // such thing in Shopify and are always treated as live.
            $table->string('status')->nullable()->after('resource_type');

            $table->index(['seo_audit_session_id', 'status']);
        });

        Schema::table('seo_audit_sessions', function (Blueprint $table) {
            // Graded and kept, but left out of the headline figures: a draft
            // product is invisible to a search engine, so counting it would
            // report a problem nobody can see.
            $table->unsignedInteger('not_live_pages')->default(0)->after('scanned_collections');
        });
    }

    public function down(): void
    {
        Schema::table('seo_audit_items', function (Blueprint $table) {
            $table->dropIndex(['seo_audit_session_id', 'status']);
            $table->dropColumn('status');
        });

        Schema::table('seo_audit_sessions', function (Blueprint $table) {
            $table->dropColumn('not_live_pages');
        });
    }
};
