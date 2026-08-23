<?php

namespace App\Console\Commands;

use App\Services\OneDriveService;
use Illuminate\Console\Command;

/**
 * Prints the header row of the tracking sheet's worksheets.
 *
 * Every column the sync reads is matched by name, and those names are typed by
 * hand by a dozen people — so "what is that column actually called, and which
 * tab is it on" is the question behind most of the sync's surprises. Answering
 * it by screenshot has been wrong twice; this answers it from the sheet.
 */
class ShowProductRequestSheetColumns extends Command
{
    protected $signature = 'product-requests:sheet-columns
                            {--tab=* : Only these worksheets, e.g. --tab=Lingerie --tab=Beauty}
                            {--find= : Only headers containing this word, e.g. --find=price}';

    protected $description = 'Print the column headers of the tracking sheet, master tab and category tabs';

    public function handle(OneDriveService $drive): int
    {
        $drive->asServiceAccount();

        $item = $drive->resolveShareItem(config('product_request_sync.master_sheet_url'));

        $master = config('product_request_sync.master_worksheet');
        $tabs   = $this->option('tab') ?: array_values(array_unique(array_merge(
            [$master],
            array_column(config('product_request_sync.departments'), 'sheet'),
        )));

        $find = strtolower(trim((string) $this->option('find')));

        foreach ($tabs as $tab) {
            try {
                $values = $drive->worksheetValues($item['driveId'], $item['itemId'], $tab);
            } catch (\Throwable $e) {
                $this->error("{$tab}: could not be read — " . $e->getMessage());
                continue;
            }

            $header = array_map('trim', $values[0] ?? []);

            // A header row is not always row 1 — some tabs carry a title above it.
            // The row with the most filled cells in the first few is the real one.
            foreach (array_slice($values, 0, 5) as $index => $row) {
                if (count(array_filter($row, 'filled')) > count(array_filter($header, 'filled'))) {
                    $header = array_map('trim', $row);
                }
            }

            $columns = [];

            foreach ($header as $position => $name) {
                if ($name === '' || ($find !== '' && !str_contains(strtolower($name), $find))) {
                    continue;
                }

                $columns[] = [$this->columnLetter($position), $name];
            }

            $this->newLine();
            $this->info("{$tab} — " . (count($values) - 1) . ' row(s)');

            if (!$columns) {
                $this->line($find !== '' ? "  no header contains \"{$find}\"" : '  no headers found');
                continue;
            }

            $this->table(['Cell', 'Header'], $columns);
        }

        return self::SUCCESS;
    }

    /** 0 → A, 26 → AA — so a header can be found by eye in Excel. */
    private function columnLetter(int $position): string
    {
        $letter = '';

        for ($n = $position; $n >= 0; $n = intdiv($n, 26) - 1) {
            $letter = chr($n % 26 + 65) . $letter;
        }

        return $letter;
    }
}
