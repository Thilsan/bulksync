<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('barcode_image_sessions', function (Blueprint $table) {
            // Null until somebody pushes: a grab is useful on its own, and most
            // runs are downloaded rather than sent anywhere.
            $table->string('push_status')->nullable()->after('error_message');
            $table->foreignId('push_store_id')->nullable()->after('push_status')->constrained('stores')->nullOnDelete();
            $table->string('push_matching_mode')->nullable()->after('push_store_id');
            $table->unsignedInteger('push_total')->default(0)->after('push_matching_mode');
            $table->unsignedInteger('push_done')->default(0)->after('push_total');
            $table->unsignedInteger('push_pushed')->default(0)->after('push_done');
            $table->unsignedInteger('push_failed')->default(0)->after('push_pushed');
            $table->text('push_error')->nullable()->after('push_failed');
        });

        Schema::table('barcode_image_items', function (Blueprint $table) {
            $table->string('push_status')->nullable()->after('message');
            $table->string('shopify_product_id')->nullable()->after('push_status');
            $table->string('shopify_product_title')->nullable()->after('shopify_product_id');
            $table->unsignedInteger('pushed_images')->default(0)->after('shopify_product_title');
            $table->text('push_message')->nullable()->after('pushed_images');
        });
    }

    public function down(): void
    {
        Schema::table('barcode_image_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('push_store_id');
            $table->dropColumn([
                'push_status', 'push_matching_mode', 'push_total',
                'push_done', 'push_pushed', 'push_failed', 'push_error',
            ]);
        });

        Schema::table('barcode_image_items', function (Blueprint $table) {
            $table->dropColumn([
                'push_status', 'shopify_product_id', 'shopify_product_title',
                'pushed_images', 'push_message',
            ]);
        });
    }
};
