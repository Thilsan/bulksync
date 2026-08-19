<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the app chose this person or a human did.
 *
 * Staffing from the category fills every role nobody named, and a role with no
 * specific person configured falls back to the category owner — so the owner's
 * name ends up in slots that were never really theirs. Correcting that later has
 * to move those, and only those: a role somebody picked deliberately is not the
 * app's to reassign.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_request_assignments', function (Blueprint $table) {
            $table->boolean('auto')->default(false)->after('assigned_by');
        });

        // Rows that already exist predate the distinction, and every one of them
        // came from staffFromCategory — the requests were imported, not staffed by
        // hand. Marking them automatic is what lets the wrong names be corrected;
        // left as hand-made they would be stuck, with no record of who chose them.
        DB::table('product_request_assignments')->update(['auto' => true]);
    }

    public function down(): void
    {
        Schema::table('product_request_assignments', function (Blueprint $table) {
            $table->dropColumn('auto');
        });
    }
};
