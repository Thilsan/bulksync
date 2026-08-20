<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Request Date from the master tab.
 *
 * The team refers to a request by its number and its date — that pair is how they
 * find it on the sheet, and the date is also half of what ties it to its SKU rows
 * on the category tab. Worth showing next to the reference rather than making
 * somebody open the sheet to look it up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_requests', function (Blueprint $table) {
            $table->date('sheet_request_date')->nullable()->after('sheet_request_no');
        });
    }

    public function down(): void
    {
        Schema::table('product_requests', function (Blueprint $table) {
            $table->dropColumn('sheet_request_date');
        });
    }
};
