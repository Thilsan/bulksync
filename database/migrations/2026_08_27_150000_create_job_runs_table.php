<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A record of every job the workers pick up.
 *
 * Laravel keeps a queue of work still to do and a table of work that failed, and
 * nothing at all about work that succeeded — so "what ran last night, and how
 * long did it take" has no answer, and a job that is running right now is
 * invisible. That is most of what an operator actually wants to know.
 *
 * Rows are written by the workers themselves and pruned on a schedule; see the
 * 'prune-job-runs' task in routes/console.php. A single bulk upload can dispatch
 * one job per image, so this table grows fast and the prune is not optional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('job_uuid')->nullable()->index();
            $table->string('name');
            $table->string('queue')->index();
            $table->string('connection')->nullable();

            // running | completed | failed | lost
            $table->string('status', 12)->index();
            $table->unsignedSmallInteger('attempt')->default(1);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            // Trimmed before it is written — a full stack trace per row would
            // dwarf everything else in the table.
            $table->text('exception')->nullable();

            $table->timestamps();

            // The two questions the page asks: what is happening now, and what
            // happened recently on this queue.
            $table->index(['status', 'started_at']);
            $table->index(['queue', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_runs');
    }
};
