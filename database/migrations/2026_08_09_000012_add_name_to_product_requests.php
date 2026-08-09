<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PCR-2026-00004 is the right identifier to quote in an email and the wrong
 * thing to scan a list with. Give each request a human name; the reference
 * stays as the permanent handle underneath it.
 *
 * Nullable rather than required: requests raised before this column existed
 * fall back to a name built from their brand and category.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_requests', function (Blueprint $table) {
            $table->string('name')->nullable()->after('reference');
        });
    }

    public function down(): void
    {
        Schema::table('product_requests', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
