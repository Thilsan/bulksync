<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The brand side needs a named owner too. The requester raises the request, but
 * the person who supplies samples and signs off on the copy is often somebody
 * else — and until now there was nowhere to record them.
 *
 * Unlike the other roles this one owns no workflow stage; it is a named
 * responsibility with its own task and deadline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_requests', function (Blueprint $table) {
            $table->foreignId('brand_manager_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('brand_manager_id');
        });
    }
};
