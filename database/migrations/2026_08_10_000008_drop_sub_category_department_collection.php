<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sub category, department and collection were asked for on every request and
 * filled in on almost none — brand and category already say enough to route the
 * work. Drop them rather than keep three columns nobody reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_requests', function (Blueprint $table) {
            $table->dropColumn(['sub_category', 'department', 'collection']);
        });
    }

    public function down(): void
    {
        Schema::table('product_requests', function (Blueprint $table) {
            $table->string('sub_category')->nullable()->after('category');
            $table->string('department')->nullable()->after('sub_category');
            $table->string('collection')->nullable()->after('department');
        });
    }
};
