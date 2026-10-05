<?php

use App\Models\StoreProduct;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each step checks first: MySQL cannot roll back a schema change, so a
        // run that failed partway leaves the column behind without recording
        // the migration, and the retry must pick up where that one stopped.
        if (!Schema::hasColumn('store_products', 'division')) {
            Schema::table('store_products', function (Blueprint $table) {
                $table->string('division', 16)->nullable()->after('sku');
            });
        }

        if (!Schema::hasIndex('store_products', ['store_id', 'division'])) {
            Schema::table('store_products', function (Blueprint $table) {
                $table->index(['store_id', 'division']);
            });
        }

        // Rows synced before this column existed. The next nightly sync would
        // fill them anyway, but the Divisions view should not sit empty until then.
        DB::table('store_products')->whereNotNull('sku')->select('id', 'sku')->chunkById(1000, function ($rows) {
            foreach ($rows as $row) {
                if ($division = StoreProduct::divisionFromSku($row->sku)) {
                    DB::table('store_products')->where('id', $row->id)->update(['division' => $division]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('store_products', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'division']);
            $table->dropColumn('division');
        });
    }
};
