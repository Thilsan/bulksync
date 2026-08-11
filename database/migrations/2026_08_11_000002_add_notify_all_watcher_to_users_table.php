<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shared e-commerce account keeps an eye on every request, so it is copied
 * on all of them — assignments, status changes, comments, holds and the daily
 * chase — without having to hold a role on any of them.
 *
 * A flag rather than a hardcoded address: the account that watches the process
 * will change, and that should be a tick box, not a deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('pcr_notify_all')->default(false)->after('pcr_categories');
        });

        User::where('email', 'ecommerce@abuissa.com')->update(['pcr_notify_all' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('pcr_notify_all');
        });
    }
};
