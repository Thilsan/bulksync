<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_content_sessions', function (Blueprint $table) {
            // Target search terms typed by the merchant, applied to every
            // product in the session. Used alongside — not instead of — the
            // per-product terms Search Console reports, which are the better
            // source when a page already ranks for something.
            $table->text('keywords')->nullable()->after('skus_json');
        });
    }

    public function down(): void
    {
        Schema::table('ai_content_sessions', function (Blueprint $table) {
            $table->dropColumn('keywords');
        });
    }
};
