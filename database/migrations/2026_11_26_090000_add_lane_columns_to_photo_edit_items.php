<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each person's photos wait in a lane of their own (App\Support\PhotoLane)
 * and are sent to the shared queue a few at a time.
 *
 * queued_at     — someone asked for this photo to be made; it is in the lane.
 * dispatched_at — it has been sent to the queue and a worker will take it.
 * lane_variant  — the extra argument its job needs: a lifestyle shot's index
 *                 or an angle's name. Null for a plain edit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photo_edit_items', function (Blueprint $table) {
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->string('lane_variant', 40)->nullable();

            $table->index(['queued_at', 'dispatched_at', 'status'], 'photo_edit_items_lane_index');
        });
    }

    public function down(): void
    {
        Schema::table('photo_edit_items', function (Blueprint $table) {
            $table->dropIndex('photo_edit_items_lane_index');
            $table->dropColumn(['queued_at', 'dispatched_at', 'lane_variant']);
        });
    }
};
