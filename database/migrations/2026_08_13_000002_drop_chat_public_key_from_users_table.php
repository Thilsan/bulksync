<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chat encryption was removed, so the published public keys have no reader.
 *
 * Dropping rather than leaving them: a column nothing writes to and nothing
 * reads is a question mark for the next person, and these keys are useless
 * without the code that agreed secrets from them. Nothing of value is lost —
 * the private halves only ever existed in people's browsers, so these columns
 * could not decrypt anything even while the feature was live.
 *
 * Written as a forward migration instead of rolling back 2026_08_13_000001,
 * because that one has already run in production.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['chat_public_key', 'chat_key_at']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('chat_public_key')->nullable()->after('is_active');
            $table->timestamp('chat_key_at')->nullable()->after('chat_public_key');
        });
    }
};
