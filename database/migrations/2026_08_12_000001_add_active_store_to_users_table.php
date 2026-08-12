<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The active website used to be one boolean on the stores table, so whoever
 * switched last switched it for the whole company. It belongs on the person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('active_store_id')->nullable()->after('is_active')
                ->constrained('stores')->nullOnDelete();
        });

        // Carry the old global choice over so nobody lands on a different
        // website than the one they were looking at before the deploy.
        $activeStoreId = DB::table('stores')->where('is_active', true)->value('id');

        if ($activeStoreId) {
            $allowed = DB::table('store_user')->where('store_id', $activeStoreId)->pluck('user_id');

            DB::table('users')
                ->where(fn ($q) => $q->whereIn('id', $allowed)->orWhere('is_super_admin', true))
                ->update(['active_store_id' => $activeStoreId]);
        }

        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->boolean('is_active')->default(false);
        });

        $firstStoreId = DB::table('stores')->orderBy('id')->value('id');

        if ($firstStoreId) {
            DB::table('stores')->where('id', $firstStoreId)->update(['is_active' => true]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('active_store_id');
        });
    }
};
