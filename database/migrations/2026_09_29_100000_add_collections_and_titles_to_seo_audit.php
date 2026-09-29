<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_audit_items', function (Blueprint $table) {
            // Collection pages are audited alongside products now. Existing
            // rows predate collections, so they can only be products.
            $table->string('resource_type')->default('product')->after('seo_audit_session_id');

            $table->index(['seo_audit_session_id', 'resource_type']);
        });

        Schema::table('seo_audit_sessions', function (Blueprint $table) {
            $table->unsignedInteger('total_collections')->default(0)->after('scanned_products');
            $table->unsignedInteger('scanned_collections')->default(0)->after('total_collections');
        });
    }

    public function down(): void
    {
        Schema::table('seo_audit_items', function (Blueprint $table) {
            $table->dropIndex(['seo_audit_session_id', 'resource_type']);
            $table->dropColumn('resource_type');
        });

        Schema::table('seo_audit_sessions', function (Blueprint $table) {
            $table->dropColumn(['total_collections', 'scanned_collections']);
        });
    }
};
