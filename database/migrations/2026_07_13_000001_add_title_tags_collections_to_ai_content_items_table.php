<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_content_items', function (Blueprint $table) {
            $table->string('ai_title', 100)->nullable()->after('ai_description_ar');
            $table->text('ai_new_tags')->nullable()->after('ai_title');
            $table->text('ai_new_collections')->nullable()->after('ai_new_tags');
        });
    }

    public function down(): void
    {
        Schema::table('ai_content_items', function (Blueprint $table) {
            $table->dropColumn(['ai_title', 'ai_new_tags', 'ai_new_collections']);
        });
    }
};
