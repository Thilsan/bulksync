<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_user', function (Blueprint $table) {
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['store_id', 'user_id']);
        });

        // Grant all existing users access to all existing stores
        $userIds  = DB::table('users')->pluck('id');
        $storeIds = DB::table('stores')->pluck('id');
        foreach ($userIds as $userId) {
            foreach ($storeIds as $storeId) {
                DB::table('store_user')->insertOrIgnore([
                    'user_id'  => $userId,
                    'store_id' => $storeId,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('store_user');
    }
};
