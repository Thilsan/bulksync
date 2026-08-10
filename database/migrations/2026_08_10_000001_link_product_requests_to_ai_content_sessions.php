<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ties a request to the AI Content session it kicked off, so the content stage
 * can show live progress and link straight through to the generated copy
 * instead of leaving people to hunt for it in the AI Content Generator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_requests', function (Blueprint $table) {
            $table->foreignId('ai_content_session_id')->nullable()->after('use_ai_content')
                ->constrained('ai_content_sessions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ai_content_session_id');
        });
    }
};
