<?php

namespace App\Console\Commands;

use App\Services\GhostCompositeService;
use App\Services\PhotoroomService;
use Illuminate\Console\Command;

/**
 * Try the composite on one pair and report whether it held together.
 *
 * The question it answers: Ghost Mannequin comes back at 1000x1000, which
 * destroys a printed logo. Keeping the original's pixels everywhere except the
 * hole the mannequin left should get the stand removed without paying that
 * price — but only if the redraw put the garment back where it found it.
 *
 * Two ways to run it. With a redraw already on disk it costs nothing and can
 * be run as many times as it takes to judge the seam:
 *
 *   php artisan photoroom:ghost-composite before.jpg ghost.png --out=out.png --mask=mask.png
 *
 * Or with --ghost-from-api it fetches the redraw first, which is one Photoroom
 * credit (free and watermarked on a sandbox key — and a watermark does not
 * move the garment, so the geometry it reports is still the real answer).
 */
class PhotoroomGhostComposite extends Command
{
    protected $signature = 'photoroom:ghost-composite
                            {original : the full-size photograph, garment on the stand}
                            {ghost? : Photoroom\'s redraw of it; omit with --ghost-from-api}
                            {--ghost-from-api : fetch the redraw from Photoroom (costs one credit)}
                            {--size=SQUARE_HD : the ghostMannequin.size preset, with --ghost-from-api}
                            {--out= : where to write the composite}
                            {--mask= : where to write the mask, for seeing what was replaced}
                            {--save-ghost= : where to keep the fetched redraw, so it can be reused free}';

    protected $description = 'Composite a Ghost Mannequin redraw onto its full-size original and report whether it lined up';

    public function handle(GhostCompositeService $composite, PhotoroomService $photoroom): int
    {
        $originalPath = (string) $this->argument('original');

        if (!is_file($originalPath)) {
            $this->error("No such file: {$originalPath}");

            return self::FAILURE;
        }

        $original = (string) file_get_contents($originalPath);

        $ghost = $this->option('ghost-from-api')
            ? $this->fetchGhost($photoroom, $original, basename($originalPath))
            : $this->readGhost();

        if ($ghost === null) {
            return self::FAILURE;
        }

        $this->line('');
        $this->line('  original : ' . $this->describe($original) . ', ' . (strlen($original) >> 10) . ' KB');
        $this->line('  redraw   : ' . $this->describe($ghost) . ', ' . (strlen($ghost) >> 10) . ' KB');
        $this->line('');

        try {
            $result = $composite->composite($original, $ghost);
        } catch (\Throwable $e) {
            $this->error('Could not composite: ' . $e->getMessage());

            return self::FAILURE;
        }

        $m = $result['metrics'];

        $this->line('  subject in original : ' . implode(', ', $m['original_box']));
        $this->line('  subject in redraw   : ' . implode(', ', $m['ghost_box']));
        $this->line('  shape drift         : ' . sprintf('%.2f%%', $m['aspect_shift'] * 100));
        $this->line('  redraw on garment   : ' . sprintf('%.2f%%', $m['containment'] * 100));
        $this->line('  taken from redraw   : ' . sprintf('%.2f%% of the garment', $m['mask_coverage'] * 100));
        $this->line('  composite           : ' . $this->describe($result['image']));
        $this->line('');

        $result['accepted']
            ? $this->info('  USABLE — ' . $result['reason'])
            : $this->warn('  REJECTED (' . $result['verdict'] . ') — ' . $result['reason']);

        $this->line('');

        if ($out = $this->option('out')) {
            file_put_contents($out, $result['image']);
            $this->line("  written : {$out}");
        }

        if ($maskPath = $this->option('mask')) {
            file_put_contents($maskPath, $result['mask']);
            $this->line("  mask    : {$maskPath}  (white is where the redraw was used)");
        }

        $this->line('');

        /*
         * The verdict is the point of the command, so it is the exit code too —
         * a rejected composite is not an error (nothing went wrong; the redraw
         * moved the dress) but it is not a success either, and a caller in a
         * shell loop over a folder needs to be able to tell them apart.
         */
        return $result['accepted'] ? self::SUCCESS : self::FAILURE;
    }

    private function readGhost(): ?string
    {
        $path = (string) $this->argument('ghost');

        if ($path === '') {
            $this->error('Give a redraw to composite, or pass --ghost-from-api to fetch one.');

            return null;
        }

        if (!is_file($path)) {
            $this->error("No such file: {$path}");

            return null;
        }

        return (string) file_get_contents($path);
    }

    private function fetchGhost(PhotoroomService $photoroom, string $original, string $filename): ?string
    {
        if (!$photoroom->isConfigured()) {
            $this->error('No Photoroom API key is configured.');

            return null;
        }

        $size = (string) $this->option('size');

        if (!isset(PhotoroomService::SIZE_PRESETS[$size])) {
            $this->error("Unknown size preset: {$size}");
            $this->line('Known: ' . implode(', ', array_keys(PhotoroomService::SIZE_PRESETS)));

            return null;
        }

        $this->line('');
        $this->line('  key : ' . ($photoroom->isSandbox() ? 'sandbox — free, watermarked' : 'LIVE — one credit'));

        if (!$photoroom->isSandbox() && !$this->confirm('That is one live credit. Continue?', false)) {
            return null;
        }

        try {
            /*
             * A white background rather than a cutout, deliberately: the
             * composite finds the subject by looking for what is not white, and
             * both sides have to be measured the same way.
             */
            $ghost = $photoroom->edit($original, [
                'ghost_mannequin'   => true,
                'apparel_size'      => $size,
                'apparel_prompt'    => PhotoroomService::GHOST_MANNEQUIN_PROMPT,
                'remove_background' => true,
                'background_mode'   => 'white',
                'export_format'     => 'png',
            ], $filename);
        } catch (\Throwable $e) {
            $this->error('Photoroom refused it: ' . $e->getMessage());

            return null;
        }

        if ($keep = $this->option('save-ghost')) {
            file_put_contents($keep, $ghost);
            $this->line("  redraw kept at {$keep} — rerun against it for free while tuning");
        }

        return $ghost;
    }

    private function describe(string $bytes): string
    {
        $info = @getimagesizefromstring($bytes);

        return $info ? $info[0] . 'x' . $info[1] : 'unreadable';
    }
}
