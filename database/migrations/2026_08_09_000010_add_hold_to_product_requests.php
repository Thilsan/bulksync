<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Work stalls for reasons that are nobody's fault and belong to no stage — the
 * commonest being that the physical samples never reached the studio, so the
 * photographer cannot shoot. Without somewhere to say that, a request just sits
 * looking neglected and the brand team chases the wrong person.
 *
 * Hold is deliberately a flag rather than a pipeline stage: a request keeps its
 * place in the workflow and resumes exactly where it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_requests', function (Blueprint $table) {
            $table->boolean('on_hold')->default(false)->after('status')->index();
            $table->string('hold_reason')->nullable()->after('on_hold');
            $table->timestamp('hold_since')->nullable()->after('hold_reason');
            $table->foreignId('hold_by')->nullable()->after('hold_since')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hold_by');
            $table->dropColumn(['on_hold', 'hold_reason', 'hold_since']);
        });
    }
};
