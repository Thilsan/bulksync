<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The variant breakdown, asked for as a file.
 *
 * It is a second run over a finished check — one Shopify lookup per mapped SKU,
 * minutes of work on a long list — so it carries its own status and counter
 * rather than reusing the check's, which must keep reading as completed while
 * the export is still going.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sku_check_sessions', function (Blueprint $table) {
            $table->string('variant_export_status')->nullable()->after('error_message');
            $table->unsignedInteger('variant_export_total')->default(0)->after('variant_export_status');
            $table->unsignedInteger('variant_export_scanned')->default(0)->after('variant_export_total');
            $table->unsignedInteger('variant_export_failed')->default(0)->after('variant_export_scanned');
            $table->text('variant_export_error')->nullable()->after('variant_export_failed');
        });
    }

    public function down(): void
    {
        Schema::table('sku_check_sessions', function (Blueprint $table) {
            $table->dropColumn([
                'variant_export_status',
                'variant_export_total',
                'variant_export_scanned',
                'variant_export_failed',
                'variant_export_error',
            ]);
        });
    }
};
