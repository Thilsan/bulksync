<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * There is no Cegid integration and none is planned — Supply Chain does the
 * mapping in Cegid themselves and records the outcome here by hand. So the
 * derived `in_cegid` flag goes, and `mapping_status` becomes the authoritative
 * value, stamped with who set it and why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_request_skus', function (Blueprint $table) {
            $table->dropColumn('in_cegid');

            // Null = never touched by a human, so the Shopify check may fill it in.
            $table->foreignId('mapping_set_by')->nullable()->after('mapping_status')->constrained('users')->nullOnDelete();
            $table->timestamp('mapping_set_at')->nullable()->after('mapping_set_by');
            $table->string('mapping_note')->nullable()->after('mapping_set_at');
        });
    }

    public function down(): void
    {
        Schema::table('product_request_skus', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mapping_set_by');
            $table->dropColumn(['mapping_set_at', 'mapping_note']);

            $table->boolean('in_cegid')->nullable()->after('mapping_status');
        });
    }
};
