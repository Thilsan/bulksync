<?php

use App\Models\ProductRequest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A shoot becomes something you can schedule, not just a stage the request sits
 * at. The Photoshoot Room needs a state of its own — a shoot can be in progress
 * or called off without the request moving anywhere — plus a time and a place,
 * so a day on the calendar says who is where and when.
 *
 * The date becomes a datetime for the same reason the launch date did: "Tuesday"
 * is not a booking, "Tuesday 09:30" is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_requests', function (Blueprint $table) {
            $table->dateTime('photoshoot_scheduled_at')->nullable()->change();

            // Null for requests that need no shoot at all.
            $table->string('photoshoot_status', 20)->nullable()->after('photoshoot_scheduled_at');
            $table->string('photoshoot_studio')->nullable()->after('photoshoot_status');
            $table->text('photoshoot_notes')->nullable()->after('photoshoot_studio');

            $table->index(['photoshoot_status', 'photoshoot_scheduled_at'], 'pr_photoshoot_calendar_index');
        });

        // Existing requests that need a shoot get a state that matches where they
        // already are, so the room is not empty on the day it ships.
        ProductRequest::query()
            ->where('photoshoot_required', true)
            ->orWhere('image_source', ProductRequest::IMG_PHOTOSHOOT)
            ->chunkById(200, function ($requests) {
                foreach ($requests as $request) {
                    $request->updateQuietly([
                        'photoshoot_status' => match (true) {
                            in_array($request->status, ProductRequest::CLOSED_STATUSES, true) => ProductRequest::SHOOT_COMPLETED,
                            $request->status === ProductRequest::PHOTOSHOOT_COMPLETED         => ProductRequest::SHOOT_COMPLETED,
                            $request->status === ProductRequest::PHOTOSHOOT_SCHEDULED         => ProductRequest::SHOOT_SCHEDULED,
                            $request->photoshoot_scheduled_at !== null                        => ProductRequest::SHOOT_SCHEDULED,
                            default                                                           => ProductRequest::SHOOT_PENDING,
                        },
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('product_requests', function (Blueprint $table) {
            $table->dropIndex('pr_photoshoot_calendar_index');
            $table->dropColumn(['photoshoot_status', 'photoshoot_studio', 'photoshoot_notes']);
            $table->date('photoshoot_scheduled_at')->nullable()->change();
        });
    }
};
