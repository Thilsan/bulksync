<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-person brief and deadline for a request.
 *
 * The online launch date says when the whole thing must be live; it says nothing
 * about when the photographer needs to be finished so the editor can start. Each
 * assignment now carries its own task title and due date.
 *
 * The owner columns on product_requests stay authoritative for "who owns this
 * stage" — every ownership, claim and notification path already reads them, and
 * splitting that would risk the workflow for no gain. This table adds the detail
 * alongside, written through one helper so the two never drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_request_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_request_id')->constrained()->cascadeOnDelete();

            // Matches ProductRequest::ASSIGNMENT_ROLES keys, e.g. photographer_id
            $table->string('role', 40);

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title')->nullable();
            $table->date('due_date')->nullable()->index();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            // One entry per role per request.
            $table->unique(['product_request_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_request_assignments');
    }
};
