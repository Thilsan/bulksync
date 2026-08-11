<?php

use App\Models\ProductRequest;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * The category split as the team actually works it today.
 *
 * Matched on email rather than name — names get corrected, addresses don't. A
 * missing account is skipped rather than created: this records who handles what,
 * it is not the place new people get made.
 *
 * From here on it is maintained in Super Admin → Users → Categories Handled, so
 * a handover never needs another migration.
 */
return new class extends Migration
{
    private const OWNERS = [
        'ghassen.aribi@bluesalon.com' => ['Lingerie', 'Linen', 'Food & Beverages', "Men's Fashion", 'Fashion Accessories', 'Watches'],
        'sheikh.khadime@abuissa.com'  => ['Leather Goods', 'Beauty', 'PG Operations'],
        'ahmed.abdaltif@abuissa.com'  => ['Luggage', "Women's Fashion", 'Kids', 'Home'],
    ];

    /** Ghassen arranges every photoshoot, whichever category it belongs to. */
    private const COORDINATOR = 'ghassen.aribi@bluesalon.com';

    public function up(): void
    {
        foreach (self::OWNERS as $email => $categories) {
            $user = User::where('email', $email)->first();

            if (!$user) {
                Log::warning("Category owners: no account for {$email} — its categories are unassigned.");
                continue;
            }

            $user->update([
                'pcr_categories' => array_values(array_intersect(ProductRequest::CATEGORIES, $categories)),
            ]);
        }

        User::where('email', self::COORDINATOR)->update(['pcr_role' => 'photographer']);

        // The shoot is only auto-assigned when exactly one account holds the
        // role — anyone else still carrying it makes it ambiguous, and the
        // request form falls back to asking the requester.
        $holders = User::where('is_active', true)->where('pcr_role', 'photographer')->pluck('email');

        if ($holders->count() > 1) {
            Log::warning('Photoshoot coordinator is ambiguous — held by ' . $holders->join(', ') . '.');
        }
    }

    public function down(): void
    {
        User::whereIn('email', array_keys(self::OWNERS))->update(['pcr_categories' => null]);
    }
};
