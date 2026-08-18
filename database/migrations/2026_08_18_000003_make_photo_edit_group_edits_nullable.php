<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Null edits mean "this SKU follows the run".
 *
 * Every group started life with its own copy of the settings, which made each
 * one a separate thing to configure — a run of thirty SKUs that all wanted the
 * same treatment became thirty identical decisions. The settings are chosen
 * once for the run now; a group only stores its own when it genuinely differs,
 * and null is how it says it does not.
 *
 * Existing groups are collapsed back to null where they still match the run
 * they belong to, so nothing that was never deliberately changed is left
 * pinned to a stale copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photo_edit_groups', function (Blueprint $table) {
            $table->json('edits')->nullable()->change();
        });

        DB::table('photo_edit_groups')
            ->join('photo_edit_sessions', 'photo_edit_sessions.id', '=', 'photo_edit_groups.photo_edit_session_id')
            ->whereColumn('photo_edit_groups.edits', 'photo_edit_sessions.edits')
            ->update(['photo_edit_groups.edits' => null]);
    }

    public function down(): void
    {
        // A null has to become something before the column can refuse nulls.
        DB::table('photo_edit_groups')->whereNull('edits')->update(['edits' => json_encode([])]);

        Schema::table('photo_edit_groups', function (Blueprint $table) {
            $table->json('edits')->nullable(false)->change();
        });
    }
};
