<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Watches" becomes "Watches & Jewellery".
 *
 * The tracking sheet's department and its tab have always been Watches &
 * Jewellery; only this app shortened it, and the requests coming in are as much
 * jewellery as watches — Buckley London, Luca Barra, Swarovski.
 *
 * The category is stored as a plain string on requests and inside the users'
 * settings arrays, so renaming the constant alone would orphan every one of
 * them: a request whose category is not in CATEGORIES falls out of the pickers,
 * the owner lookups and the department matching all at once. So the stored
 * values move with it.
 */
return new class extends Migration
{
    private const OLD = 'Watches';
    private const NEW = 'Watches & Jewellery';

    public function up(): void
    {
        $this->rename(self::OLD, self::NEW);
    }

    public function down(): void
    {
        $this->rename(self::NEW, self::OLD);
    }

    private function rename(string $from, string $to): void
    {
        DB::table('product_requests')->where('category', $from)->update(['category' => $to]);

        // JSON arrays of category names, rewritten row by row: the shapes differ
        // and there are only ever a handful of users with any of them set.
        foreach (['pcr_categories', 'pcr_brand_categories'] as $column) {
            foreach (DB::table('users')->whereNotNull($column)->get(['id', $column]) as $user) {
                $values = json_decode($user->{$column} ?? '[]', true) ?: [];

                if (!in_array($from, $values, true)) {
                    continue;
                }

                $values = array_values(array_unique(array_map(
                    fn ($value) => $value === $from ? $to : $value,
                    $values,
                )));

                DB::table('users')->where('id', $user->id)->update([$column => json_encode($values)]);
            }
        }

        // Store-scoped pairings are "<store id>|<category>".
        foreach (DB::table('users')->whereNotNull('pcr_store_categories')->get(['id', 'pcr_store_categories']) as $user) {
            $values = json_decode($user->pcr_store_categories ?? '[]', true) ?: [];
            $moved  = array_map(function ($value) use ($from, $to) {
                [$storeId, $category] = array_pad(explode('|', (string) $value, 2), 2, null);

                return $category === $from ? "{$storeId}|{$to}" : $value;
            }, $values);

            if ($moved !== $values) {
                DB::table('users')->where('id', $user->id)->update([
                    'pcr_store_categories' => json_encode(array_values(array_unique($moved))),
                ]);
            }
        }
    }
};
