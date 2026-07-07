<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_content_items', function (Blueprint $table) {
            $table->text('ai_description_ar')->nullable()->after('ai_description');
            $table->string('ai_meta_title_ar', 100)->nullable()->after('ai_meta_title');
            $table->string('ai_meta_description_ar', 200)->nullable()->after('ai_meta_description');
        });

        Schema::table('ai_content_images', function (Blueprint $table) {
            $table->string('ai_alt_text_ar', 255)->nullable()->after('ai_alt_text');
        });
    }

    public function down(): void
    {
        Schema::table('ai_content_items', function (Blueprint $table) {
            $table->dropColumn(['ai_description_ar', 'ai_meta_title_ar', 'ai_meta_description_ar']);
        });

        Schema::table('ai_content_images', function (Blueprint $table) {
            $table->dropColumn('ai_alt_text_ar');
        });
    }
};
