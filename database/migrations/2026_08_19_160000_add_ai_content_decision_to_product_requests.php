<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the team chose to generate AI content, and when they decided.
 *
 * use_ai_content already says what the request was raised with, but not whether
 * anybody was asked afterwards — and "never used AI" looks identical to "offered
 * it and turned it down". The publish summary has to be able to tell the two
 * apart to say what was skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_requests', function (Blueprint $table) {
            // generate | skip — null means nobody has been asked.
            $table->string('ai_content_decision', 10)->nullable()->after('ai_content_session_id');
            $table->timestamp('ai_content_decided_at')->nullable()->after('ai_content_decision');
        });
    }

    public function down(): void
    {
        Schema::table('product_requests', function (Blueprint $table) {
            $table->dropColumn(['ai_content_decision', 'ai_content_decided_at']);
        });
    }
};
