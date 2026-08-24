<?php

namespace App\Console\Commands;

use App\Services\ProductRequestSheetSyncService;
use Illuminate\Console\Command;

/**
 * Every row on the tracking sheet against what is actually in the system.
 *
 * The sync reports exceptions — what it skipped, what disagreed. That answers
 * "did anything go wrong", not "is all of it here", and those are different
 * questions: a request that imported with half its SKUs is not an exception to
 * anything, it is just quietly short.
 *
 * Read-only. It creates and changes nothing.
 */
class AuditProductRequestSheet extends Command
{
    protected $signature = 'product-requests:audit-sheet
                            {--problems : Only rows that are not fully accounted for}
                            {--csv= : Write the full report to this path instead of printing it}';

    protected $description = 'Compare every tracking-sheet row against the requests in the system';

    public function handle(ProductRequestSheetSyncService $service): int
    {
        set_time_limit(600);   // the whole workbook, tab by tab

        $this->info('Reading the sheet…');

        $result = $service->audit();
        $rows   = $result['rows'];
        $totals = $result['totals'];

        if ($path = $this->option('csv')) {
            $this->writeCsv($path, $rows);
            $this->info(count($rows) . " row(s) written to {$path}.");
        } else {
            $shown = $this->option('problems')
                ? array_filter($rows, fn ($row) => !str_starts_with($row['verdict'], 'OK'))
                : $rows;

            foreach ($shown as $row) {
                $this->line(sprintf(
                    '%-6s %-12s %-28s sheet:%-6s tab:%-6s system:%-6s %s  %s',
                    $row['request_no'],
                    $row['website'],
                    mb_strimwidth((string) $row['brand'], 0, 28, '…'),
                    $row['sheet_count'],
                    $row['tab_rows'],
                    $row['in_system'],
                    str_pad((string) $row['reference'], 17),
                    $row['verdict'],
                ));
            }

            if (!$shown) {
                $this->info('Every row is fully accounted for.');
            }
        }

        $this->newLine();
        $this->table(['Outcome', 'Rows'], [
            ['Rows on the sheet',            $totals['rows']],
            ['Imported and complete',        $totals['imported']],
            ['Imported but short',           $totals['short']],
            ['SKUs missing from the tab',     $totals['missing']],
            ['Not imported',                 $totals['not_imported']],
            ['Ignored by choice',            $totals['ignored']],
        ]);

        $this->line('sheet = the master row\'s SKU Count · tab = rows on the category tab · system = SKUs on the request');

        return self::SUCCESS;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function writeCsv(string $path, array $rows): void
    {
        $handle = fopen($path, 'w');

        fputcsv($handle, [
            'Request No', 'Website', 'Brand', 'Department', 'Category', 'Reference', 'Stage',
            'Sheet SKU Count', 'Tab Rows', 'Tab Distinct SKUs', 'SKUs In System', 'Verdict',
        ]);

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['request_no'], $row['website'], $row['brand'], $row['department'], $row['category'],
                $row['reference'], $row['status'], $row['sheet_count'], $row['tab_rows'],
                $row['tab_distinct'], $row['in_system'], $row['verdict'],
            ]);
        }

        fclose($handle);
    }
}
