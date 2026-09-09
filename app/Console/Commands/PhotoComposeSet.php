<?php

namespace App\Console\Commands;

use App\Services\SetLayoutService;
use Illuminate\Console\Command;

/**
 * Lay a top and a bottom out as one two-piece product image.
 *
 *   php artisan photo:compose-set top.png trouser.png --out=set.png --size=2000
 *
 * Both inputs should already be cutouts. Costs nothing and calls nothing — the
 * two Photoroom credits are spent cutting each piece out, which is what they
 * would cost as separate product images anyway.
 */
class PhotoComposeSet extends Command
{
    protected $signature = 'photo:compose-set
                            {top : the upper garment, already cut out}
                            {bottom : the lower garment, already cut out}
                            {--out= : where to write the set image}
                            {--size=2000 : edge of the square canvas}';

    protected $description = 'Place a cut-out top and bottom on one canvas as a two-piece set image';

    public function handle(SetLayoutService $layout): int
    {
        $topPath    = (string) $this->argument('top');
        $bottomPath = (string) $this->argument('bottom');

        foreach ([$topPath, $bottomPath] as $path) {
            if (!is_file($path)) {
                $this->error("No such file: {$path}");

                return self::FAILURE;
            }
        }

        $size = max(256, min(8000, (int) $this->option('size')));

        try {
            $result = $layout->compose(
                (string) file_get_contents($topPath),
                (string) file_get_contents($bottomPath),
                $size,
            );
        } catch (\Throwable $e) {
            $this->error('Could not compose the set: ' . $e->getMessage());

            return self::FAILURE;
        }

        $m = $result['metrics'];

        $this->line('');
        $this->line('  canvas       : ' . $m['canvas']);
        $this->line('  top placed   : ' . $this->describePlacement($m['top_placed'], $size));
        $this->line('  bottom placed: ' . $this->describePlacement($m['bottom_placed'], $size));
        $this->line('  gap between  : ' . $m['gap_px'] . ' px');
        $this->line('');

        if ($out = $this->option('out')) {
            file_put_contents($out, $result['image']);
            $this->line("  written : {$out}");
            $this->line('');
        }

        return self::SUCCESS;
    }

    /**
     * Where a piece landed, as pixels and as a share of the canvas — the share
     * is what a framing preset is argued about in.
     *
     * @param  array{x:int,y:int,w:int,h:int}  $p
     */
    private function describePlacement(array $p, int $edge): string
    {
        return sprintf(
            '%dx%d at %d,%d  (%.1f%% of the height, top edge %.1f%% down)',
            $p['w'],
            $p['h'],
            $p['x'],
            $p['y'],
            100 * $p['h'] / $edge,
            100 * $p['y'] / $edge,
        );
    }
}
