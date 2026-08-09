<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two stages had no owner of their own.
 *
 * Waiting for Mapping could never name a person, so a blocked request only ever
 * said "Supply Chain Team" in the abstract. And Image Editing borrowed the
 * E-Commerce owner, even though editing is its own job done by its own person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_requests', function (Blueprint $table) {
            $table->foreignId('supply_chain_id')->nullable()->after('assigned_to')->constrained('users')->nullOnDelete();
            $table->foreignId('image_editor_id')->nullable()->after('photographer_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supply_chain_id');
            $table->dropConstrainedForeignId('image_editor_id');
        });
    }
};
