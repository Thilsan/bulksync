<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('perm_product_request')->default(false)->after('perm_metafield_update');
            // Workflow role — decides which status changes this user is notified about
            // and which stages they may advance. Null = no product-request duties.
            $table->string('pcr_role', 30)->nullable()->after('perm_product_request');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['perm_product_request', 'pcr_role']);
        });
    }
};
