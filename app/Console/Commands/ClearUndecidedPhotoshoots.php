<?php

namespace App\Console\Commands;

use App\Models\ProductRequest;
use Illuminate\Console\Command;

/**
 * Empties the Photoshoot Room of requests nobody chose to shoot.
 *
 * The import used to read "the sheet does not say the images are ready" as "this
 * needs a photoshoot", which put every request in the room at once. A queue
 * holding everything tells the coordinator nothing.
 *
 * Only untouched entries go: a shoot with a date, or one somebody has moved past
 * pending, is a real booking and is left alone whatever the import assumed.
 */
class ClearUndecidedPhotoshoots extends Command
{
    protected $signature = 'product-requests:clear-undecided-photoshoots
                            {--commit : Actually clear them, instead of only reporting what would go}';

    protected $description = 'Take requests out of the Photoshoot Room where nobody asked for a shoot';

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');

        if (!$commit) {
            $this->warn('Dry run — nothing will change. Pass --commit to apply.');
        }

        $candidates = ProductRequest::query()
            ->whereNotNull('photoshoot_status')
            ->whereNull('photoshoot_decision')
            ->with('store')
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('Nothing to clear — every shoot in the room was asked for.');
            return self::SUCCESS;
        }

        $cleared = 0;
        $kept    = [];

        foreach ($candidates as $request) {
            if ($reason = $this->realBooking($request)) {
                $kept[] = "{$request->reference} — {$request->brand}: kept, {$reason}";
                continue;
            }

            if ($commit) {
                $request->update([
                    'photoshoot_status'   => null,
                    'photoshoot_required' => false,
                ]);
            }

            $cleared++;
        }

        foreach ($kept as $line) {
            $this->warn($line);
        }

        $this->newLine();
        $this->info($cleared . ' request(s) ' . ($commit ? 'taken out of' : 'would be taken out of')
            . ' the Photoshoot Room. They are asked whether they need one when somebody opens them.');

        return self::SUCCESS;
    }

    /** Whether this is a booking somebody made, rather than one the import assumed. */
    private function realBooking(ProductRequest $request): ?string
    {
        if ($request->photoshoot_scheduled_at) {
            return 'a date is set for ' . $request->photoshoot_scheduled_at->format('d M Y');
        }

        if ($request->photoshoot_status !== ProductRequest::SHOOT_PENDING) {
            return 'the shoot is ' . (ProductRequest::SHOOT_STATUSES[$request->photoshoot_status] ?? $request->photoshoot_status);
        }

        if (filled($request->photoshoot_studio) || filled($request->photoshoot_notes)) {
            return 'somebody has filled in the studio or notes';
        }

        return null;
    }
}
