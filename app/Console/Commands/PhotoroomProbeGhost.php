<?php

namespace App\Console\Commands;

use App\Services\PhotoroomService;
use Illuminate\Console\Command;

/**
 * Ask Photoroom's Ghost Mannequin for one image and report what comes back.
 *
 * The question this exists to answer: Photoroom's own app offers three quality
 * tiers and they turn out to be resolutions — 1024, 2048 and 4096. A 1024
 * redraw destroys a printed logo, measured at 7% of the original's detail; 2048
 * does not. The API documents only "HD" presets and no quality parameter, so
 * there is no way to tell from the documentation which tier a request lands in.
 *
 * That matters because EditPhotoItemJob deliberately refuses to run Ghost
 * Mannequin at all — it substitutes a narrower erase, on the grounds that
 * generative reconstruction cannot be trusted. If the API only reaches 1024
 * that policy is right and should stay. If it reaches 2048 the policy was
 * calibrated against the wrong tier and is worth revisiting.
 *
 * One image, one request, nothing written to any run. Point it at staging and
 * the sandbox key makes it free — the result is watermarked, but a watermark
 * does not change the dimensions, which is the whole question.
 */
class PhotoroomProbeGhost extends Command
{
    protected $signature = 'photoroom:probe-ghost
                            {file : a local image to send}
                            {--size=SQUARE_HD : the ghostMannequin.size preset to ask for}
                            {--out= : where to write the result, for looking at}';

    protected $description = 'Send one image through Ghost Mannequin and report the resolution it returns';

    public function handle(PhotoroomService $photoroom): int
    {
        $file = $this->argument('file');

        if (!is_file($file)) {
            $this->error("No such file: {$file}");

            return self::FAILURE;
        }

        if (!$photoroom->isConfigured()) {
            $this->error('No Photoroom API key is configured.');

            return self::FAILURE;
        }

        $size = (string) $this->option('size');

        if (!isset(PhotoroomService::SIZE_PRESETS[$size])) {
            $this->error("Unknown size preset: {$size}");
            $this->line('Known: ' . implode(', ', array_keys(PhotoroomService::SIZE_PRESETS)));

            return self::FAILURE;
        }

        $input = (string) file_get_contents($file);
        $was   = $this->describe($input);

        $this->line('');
        $this->line("  key      : " . ($photoroom->isSandbox() ? 'sandbox — this call is free' : 'LIVE — this call costs one credit'));
        $this->line("  sending  : {$was}, " . (strlen($input) >> 10) . ' KB');
        $this->line("  asking   : ghostMannequin.mode=ai.auto, ghostMannequin.size={$size}");
        $this->line('');

        if (!$photoroom->isSandbox() && !$this->confirm('That is one live credit. Continue?', false)) {
            return self::SUCCESS;
        }

        try {
            /*
             * Deliberately the whole edit path, not a hand-built request — the
             * point is to learn what the app would get if the policy changed,
             * not what a different request would return.
             */
            $edited = $photoroom->edit($input, [
                'ghost_mannequin'   => true,
                'apparel_size'      => $size,
                'remove_background' => true,
                'background_mode'   => 'white',
                'export_format'     => 'png',
            ], basename($file));
        } catch (\Throwable $e) {
            $this->error('Photoroom refused it: ' . $e->getMessage());

            return self::FAILURE;
        }

        $got = $this->describe($edited);
        $this->line("  returned : {$got}, " . (strlen($edited) >> 10) . ' KB');
        $this->line('');

        // The verdict, stated rather than left to be worked out.
        $edge = max((int) (@getimagesizefromstring($edited)[0] ?? 0), (int) (@getimagesizefromstring($edited)[1] ?? 0));

        match (true) {
            $edge >= 3500 => $this->info('  4096 class — the premium tier. Prints survive this comfortably.'),
            $edge >= 1800 => $this->info('  2048 class — the advanced tier. A print measured 289% of the original\'s detail here.'),
            $edge >= 900  => $this->warn('  1024 class — the standard tier. A print measured 7% of the original\'s detail here: this is the tier that destroys logos, and the downgrade policy in EditPhotoItemJob is right to avoid it.'),
            default       => $this->warn('  Smaller than 1024. Not usable for a 2000 catalogue.'),
        };

        if ($out = $this->option('out')) {
            file_put_contents($out, $edited);
            $this->line("  written  : {$out}");
        }

        $this->line('');

        return self::SUCCESS;
    }

    private function describe(string $bytes): string
    {
        $info = @getimagesizefromstring($bytes);

        return $info ? $info[0] . 'x' . $info[1] : 'unreadable';
    }
}
