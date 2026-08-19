<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps the two things the tracking sheet knows that the app otherwise loses:
 * the row's own "Request No" (how the team refers to a request in meetings and
 * on the sheet) and the "Requested By" name. The requester is a free-typed name
 * on the sheet and usually has no matching User row, so user_id alone always
 * showed whoever ran the sync instead of the real requester.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_requests', function (Blueprint $table) {
            $table->unsignedInteger('sheet_request_no')->nullable()->after('reference')->index();
            $table->string('sheet_requested_by')->nullable()->after('sheet_request_no');
        });
    }

    public function down(): void
    {
        Schema::table('product_requests', function (Blueprint $table) {
            $table->dropIndex(['sheet_request_no']);
            $table->dropColumn(['sheet_request_no', 'sheet_requested_by']);
        });
    }
};
