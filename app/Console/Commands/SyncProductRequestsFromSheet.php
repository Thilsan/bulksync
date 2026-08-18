<?php

namespace App\Console\Commands;

use App\Services\ProductRequestSheetSyncService;
use Illuminate\Console\Command;

/**
 * Pulls new rows from the "PRODUCT LISTING REQUEST" tracking sheet and turns
 * matched ones into real ProductRequest records.
 *
 * Defaults to a dry run — it prints what it would do and touches nothing.
 * Pass --commit to actually create requests. See
 * config/product_request_sync.php for the mappings this depends on, and
 * App\Services\ProductRequestSheetSyncService for the matching rules.
 */
class SyncProductRequestsFromSheet extends Command
{
    protected $signature = 'product-requests:sync-sheet {--commit : Actually create requests, instead of only reporting what would happen}';

    protected $description = 'Sync new rows from the SharePoint product listing tracking sheet into product requests';

    public function handle(ProductRequestSheetSyncService $service): int
    {
        $commit = (bool) $this->option('commit');

        if (!$commit) {
            $this->warn('Dry run — nothing will be created. Pass --commit to actually create requests.');
        }

        $result = $service->run($commit);

        foreach ($result['log'] as $line) {
            $this->line($line);
        }

        $this->newLine();
        $this->table(['Outcome', 'Count'], [
            ['Created',                $result['created']],
            ['Already synced',         $result['skipped_existing']],
            ['Unmatched department',   $result['unmatched_department']],
            ['Unmatched store',        $result['unmatched_store']],
            ['Unmatched SKUs',         $result['unmatched_skus']],
            ['Errors',                 $result['errors']],
        ]);

        return self::SUCCESS;
    }
}
