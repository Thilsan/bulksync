<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The SEO re-check that runs after an AI content push.
 *
 * Pushing used to be the end of the story: the copy went live and nobody could
 * say whether the audit's problems were actually gone without running the whole
 * catalogue again. Now the pushed products are read back from Shopify and
 * graded by the audit's own rules, so each item carries its score before and
 * after, the session carries the batch's summary, and the audit's rows are
 * brought up to date in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_content_sessions', function (Blueprint $table) {
            // Set when the batch came from an audit's Fix button; otherwise the
            // re-check compares against the store's latest completed audit.
            $table->unsignedBigInteger('seo_audit_session_id')->nullable()->index()->after('store_id');
            $table->json('seo_recheck')->nullable()->after('error_message');
        });

        Schema::table('ai_content_items', function (Blueprint $table) {
            $table->unsignedTinyInteger('seo_score_before')->nullable()->after('error_message');
            $table->unsignedTinyInteger('seo_score_after')->nullable()->after('seo_score_before');
            $table->json('seo_issues_before')->nullable()->after('seo_score_after');
            $table->json('seo_issues_after')->nullable()->after('seo_issues_before');
        });

        Schema::table('seo_audit_sessions', function (Blueprint $table) {
            $table->timestamp('rechecked_at')->nullable()->after('error_message');
            $table->unsignedInteger('rechecked_products')->default(0)->after('rechecked_at');
        });
    }

    public function down(): void
    {
        Schema::table('ai_content_sessions', function (Blueprint $table) {
            $table->dropIndex(['seo_audit_session_id']);
            $table->dropColumn(['seo_audit_session_id', 'seo_recheck']);
        });

        Schema::table('ai_content_items', function (Blueprint $table) {
            $table->dropColumn(['seo_score_before', 'seo_score_after', 'seo_issues_before', 'seo_issues_after']);
        });

        Schema::table('seo_audit_sessions', function (Blueprint $table) {
            $table->dropColumn(['rechecked_at', 'rechecked_products']);
        });
    }
};
