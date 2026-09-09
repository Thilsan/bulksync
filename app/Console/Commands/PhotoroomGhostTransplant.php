<?php

namespace App\Console\Commands;

use App\Services\GhostPrintTransplantService;
use Illuminate\Console\Command;

/**
 * Put a photograph's real print back onto a Ghost Mannequin redraw and report
 * what the swap bought.
 *
 *   php artisan photoroom:ghost-transplant original.jpg ghost.jpg \
 *       --out=fixed.png --size=2000
 *
 * Costs nothing and calls nothing: both images are already on disk by the time
 * this runs. Fetch the redraw with photoroom:probe-ghost first, keep it, and
 * iterate here for free.
 */
class PhotoroomGhostTransplant extends Command
{
    protected $signature = 'photoroom:ghost-transplant
                            {original : the photograph, stand and all}
                            {ghost? : Photoroom\'s Ghost Mannequin redraw of it; not needed with --keep-photo}
                            {--out= : where to write the result}
                            {--size=2000 : longest edge of the output}
                            {--keep-photo : keep the photograph and erase only the stand, so the direction never changes}';

    protected $description = 'Transplant the photograph\'s print onto a Ghost Mannequin redraw, keeping the redraw\'s geometry';

    public function handle(GhostPrintTransplantService $service): int
    {
        $originalPath = (string) $this->argument('original');
        $ghostPath    = (string) $this->argument('ghost');

        $keepPhoto = (bool) $this->option('keep-photo');

        foreach ($keepPhoto ? [$originalPath] : [$originalPath, $ghostPath] as $path) {
            if ($path === '' || !is_file($path)) {
                $this->error("No such file: {$path}");

                return self::FAILURE;
            }
        }

        $size = max(256, min(8000, (int) $this->option('size')));

        $original = (string) file_get_contents($originalPath);
        $ghost    = $keepPhoto ? '' : (string) file_get_contents($ghostPath);

        $this->line('');
        $this->line('  photograph : ' . $this->describe($original));
        $this->line('  redraw     : ' . ($keepPhoto ? 'not needed' : $this->describe($ghost)));
        $this->line('  output      : ' . $size . ' px on the longest edge');
        $this->line('');

        try {
            $result = $keepPhoto
                ? $service->removeStand($original, $size)
                : $service->transplant($original, $ghost, $size);
        } catch (\Throwable $e) {
            $this->error('Could not transplant: ' . $e->getMessage());

            return self::FAILURE;
        }

        $m = $result['metrics'];

        if ($keepPhoto) {
            $this->line('  stand erased at         : ' . implode(', ', $m['stand_box']));
            $this->line('  share of the frame      : ' . sprintf('%.2f%%', $m['stand_share'] * 100));
            $this->line('  output                  : ' . $m['output_size']);
            $this->line('');
            $this->info('  ' . $result['reason']);
            $this->line('');

            if ($out = $this->option('out')) {
                file_put_contents($out, $result['image']);
                $this->line("  written : {$out}");
                $this->line('');
            }

            return self::SUCCESS;
        }

        $this->line('  print in the photograph : ' . $m['photo_print']);
        $this->line('  print on the canvas     : ' . $m['target_print']);
        $this->line('  redraw enlarged by      : ' . $m['redraw_upscale'] . 'x');
        $this->line('  print aspect shift      : ' . sprintf('%.2f%%', $m['print_aspect_shift'] * 100));
        $this->line('  fabric level shift      : ' . $m['level_shift'] . ' levels');
        $this->line('  print detail vs redraw  : ' . $m['print_detail_gain'] . 'x');
        $this->line('  output                  : ' . $m['output_size']);
        $this->line('  neck label              : ' . ($m['tag'] ?? 'not looked for'));
        $this->line('');

        $result['accepted']
            ? $this->info('  USABLE — ' . $result['reason'])
            : $this->warn('  REJECTED — ' . $result['reason']);

        $this->line('');

        if ($out = $this->option('out')) {
            file_put_contents($out, $result['image']);
            $this->line("  written : {$out}");
            $this->line('');
        }

        return $result['accepted'] ? self::SUCCESS : self::FAILURE;
    }

    private function describe(string $bytes): string
    {
        $info = @getimagesizefromstring($bytes);

        return $info ? $info[0] . 'x' . $info[1] : 'unreadable';
    }
}
