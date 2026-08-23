<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Clears notifications older than the retention window.
 *
 * The bell is a "what happened lately" list, not an archive. One sync creates
 * hundreds of requests and a notification apiece, so an account copied on
 * everything reaches four figures in a day — at which point "99+" tells nobody
 * anything and the rows are pure disk growth.
 *
 * Read or unread makes no difference: an unread notice from three days ago has
 * already been overtaken by whatever came after it, and the activity log on each
 * request keeps the real history either way.
 */
class PruneNotifications extends Command
{
    protected $signature = 'notifications:prune
                            {--hours=48 : Delete notifications older than this}
                            {--commit : Actually delete, instead of only reporting what would go}';

    protected $description = 'Delete notifications older than the retention window (48 hours by default)';

    public function handle(): int
    {
        $hours  = max(1, (int) $this->option('hours'));
        $commit = (bool) $this->option('commit');
        $cutoff = now()->subHours($hours);

        $total   = DB::table('notifications')->count();
        $expired = DB::table('notifications')->where('created_at', '<', $cutoff)->count();

        if ($expired === 0) {
            $this->info("Nothing older than {$hours} hours — {$total} notification(s) kept.");

            return self::SUCCESS;
        }

        if (!$commit) {
            $this->warn('Dry run — nothing will be deleted. Pass --commit to apply.');
            $this->info("{$expired} of {$total} notification(s) are older than {$hours} hours and would go.");

            return self::SUCCESS;
        }

        // Chunked: a first run over a backlog of tens of thousands should not be
        // one enormous delete holding a lock on the table.
        do {
            $deleted = DB::table('notifications')
                ->where('created_at', '<', $cutoff)
                ->limit(5000)
                ->delete();
        } while ($deleted > 0);

        $this->info("{$expired} notification(s) deleted. " . ($total - $expired) . ' kept.');

        return self::SUCCESS;
    }
}
