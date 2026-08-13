<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Somewhere to publish each person's chat public key.
 *
 * Chat messages are end-to-end encrypted, which needs one thing this server has
 * to remember: everyone's public key, so their colleagues can encrypt to them.
 *
 * A public key is not a message and not a secret — it is the part that is meant
 * to be handed out. The private half never leaves the person's browser, and no
 * column here can decrypt anything. Storing this is what lets the delivery
 * buffer hold ciphertext the server cannot read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // A P-256 ECDH key as JWK; a few hundred bytes.
            $table->text('chat_public_key')->nullable()->after('is_active');
            // When it was last published, so a rotation is visible.
            $table->timestamp('chat_key_at')->nullable()->after('chat_public_key');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['chat_public_key', 'chat_key_at']);
        });
    }
};
