<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Activity rows used to copy the request's current status onto every entry,
 * whatever the entry was about — so an assignment logged after publishing read
 * as "Published" in the trail. The workflow no longer stamps them, but the rows
 * already written still carry the borrowed status. Clear it: only a status
 * change ever legitimately held one.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('product_request_activities')
            ->whereNotIn('action', ['status_changed', 'created'])
            ->update(['from_status' => null, 'to_status' => null]);
    }

    /**
     * Irreversible by design — the cleared values were never this row's own
     * status, so there is nothing truthful to put back.
     */
    public function down(): void
    {
        //
    }
};
