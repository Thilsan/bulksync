<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SKUs that were in the uploaded product list but did not go into the
     * request — not mapped yet, or unticked at the check — kept so the request
     * records everything that was asked for, not only what went ahead.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('product_requests', 'left_out_skus')) {
            Schema::table('product_requests', function (Blueprint $table) {
                $table->json('left_out_skus')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('product_requests', function (Blueprint $table) {
            $table->dropColumn('left_out_skus');
        });
    }
};
