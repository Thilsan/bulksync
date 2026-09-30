<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_content_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('pending'); // pending|processing|ready|done|failed

            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('processed_items')->default(0);

            // Target search terms for the batch, as typed.
            $table->text('keywords')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('collection_content_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('collection_content_sessions')->cascadeOnDelete();

            $table->string('collection_id');
            $table->string('title')->nullable();
            $table->string('handle')->nullable();

            // What was there before, kept so the review screen can show the
            // change rather than only the suggestion.
            $table->text('existing_description')->nullable();
            $table->string('existing_meta_title', 512)->nullable();
            $table->string('existing_meta_description', 1024)->nullable();

            $table->text('ai_description')->nullable();
            $table->string('ai_meta_title', 512)->nullable();
            $table->string('ai_meta_description', 1024)->nullable();

            $table->string('status')->default('pending'); // pending|processing|done|pushed|failed
            $table->boolean('is_confirmed')->default(false);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['session_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_content_items');
        Schema::dropIfExists('collection_content_sessions');
    }
};
