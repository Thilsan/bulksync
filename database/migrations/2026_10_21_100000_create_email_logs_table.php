<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every email the app sends, one row each.
 *
 * "Why am I getting this?" had no answer short of reading the code: the
 * in-app notifications are kept, but not what actually went out by email, to
 * whom, or whether the mail server took it. Bodies are kept for a fortnight
 * and rows for three months — see the prune in routes/console.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('to_address', 512);
            $table->string('subject', 512)->nullable();
            $table->string('notification', 120)->nullable();
            $table->foreignId('product_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16)->default('sending')->index();
            $table->text('error')->nullable();
            $table->longText('body')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
