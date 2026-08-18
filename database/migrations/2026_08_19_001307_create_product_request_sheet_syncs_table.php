<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_request_sheet_syncs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('request_no');       // "Request No" on the master tab
            $table->string('website_token', 40);          // one row per store token found in "Website"
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('pending'); // created|unmatched_department|unmatched_store|unmatched_skus|error
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['request_no', 'website_token']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_request_sheet_syncs');
    }
};
