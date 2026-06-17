<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete()->after('id');
        });

        Schema::table('upload_sessions', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete()->after('id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->text('onedrive_access_token')->nullable()->after('remember_token');
            $table->text('onedrive_refresh_token')->nullable()->after('onedrive_access_token');
            $table->string('onedrive_token_expiry')->nullable()->after('onedrive_refresh_token');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\User::class);
            $table->dropColumn('user_id');
        });

        Schema::table('upload_sessions', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\User::class);
            $table->dropColumn('user_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['onedrive_access_token', 'onedrive_refresh_token', 'onedrive_token_expiry']);
        });
    }
};
