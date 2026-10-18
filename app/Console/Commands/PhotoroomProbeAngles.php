<?php

namespace App\Console\Commands;

use App\Services\PhotoroomService;
use Illuminate\Console\Command;

/**
 * Ask Photoroom for other views of a product it has only seen from one side.
 *
 * Their documentation says Edit With AI takes a prompt like "show the product
 * from the side", one request per angle, and warns in the same breath that it
 * "generates new images rather than rotating existing ones". That distinction
 * is the whole question, and it is not one to settle by reading: a generated
 * back view is a photograph of a product that does not exist, and whether that
 * matters depends entirely on how much of the product is on the side nobody
 * photographed.
 *
 * A probe rather than a feature, deliberately. It writes the results to a
 * folder and prints what it cost, so the pictures can be looked at next to the
 * real thing before anything built on them reaches a customer. Measured on
 * this account already: the same pass reinvented an Aigner horseshoe monogram
 * as rings at 4% of the original's print detail, on a job that only had to
 * remove a stand from a photo it could see.
 *
 * One credit per angle. A seed is sent so a disappointing result can be
 * reproduced rather than re-rolled.
 */
class PhotoroomProbeAngles extends Command
{
    protected $signature = 'photoroom:probe-angles
        {image : Path to the source photograph}
        {--out= : Folder to write into, defaults to beside the source}
        {--seed=1 : Sent to Photoroom so a run can be repeated}
        {--angle=* : Override the default four views}
        {--live : Required when the key is a live one, because each angle costs a credit}';

    protected $description = 'Generate other camera angles of a product from one photo, to judge whether they are usable';

    /** What a shoe, a bag or a case is usually missing when only one view was shot. */
    private const DEFAULT_ANGLES = [
        'side'  => 'Show this exact product photographed from the side, same product, same colour, same materials and same markings',
        'back'  => 'Show this exact product photographed from behind, same product, same colour, same materials and same markings',
        'front' => 'Show this exact product photographed from the front, same product, same colour, same materials and same markings',
        'angle' => 'Show this exact product photographed from a three-quarter angle, same product, same colour, same materials and same markings',
    ];

    public function handle(PhotoroomService $photoroom): int
    {
        $path = (string) $this->argument('image');

        if (!is_file($path)) {
            $this->error("No file at {$path}");

            return self::FAILURE;
        }

        if (!$photoroom->isConfigured()) {
            $this->error('No Photoroom API key is configured.');

            return self::FAILURE;
        }

        $angles = $this->option('angle')
            ? array_combine($this->option('angle'), array_map(
                fn ($a) => "Show this exact product photographed from the {$a}, same product, same colour, "
                    . 'same materials and same markings',
                $this->option('angle'),
            ))
            : self::DEFAULT_ANGLES;

        /*
         * A live key spends real money per angle, and this command exists to
         * be run speculatively — which is exactly the combination that bills
         * somebody for an experiment they thought was free.
         */
        if (!$photoroom->isSandbox() && !$this->option('live')) {
            $this->warn(sprintf(
                'This is a live key: %d angles will cost %d credits. Re-run with --live to go ahead.',
                count($angles),
                count($angles),
            ));

            return self::FAILURE;
        }

        $out = (string) ($this->option('out') ?: dirname($path) . '/angles');

        if (!is_dir($out) && !@mkdir($out, 0775, true) && !is_dir($out)) {
            $this->error("Could not create {$out}");

            return self::FAILURE;
        }

        $source = (string) file_get_contents($path);
        $seed   = (int) $this->option('seed');
        $stem   = pathinfo($path, PATHINFO_FILENAME);
        $spent  = 0;

        $this->line("Source: {$path}");
        $this->line('Each angle is one credit, and is generated rather than rotated.');
        $this->newLine();

        foreach ($angles as $name => $prompt) {
            try {
                $bytes = $photoroom->eraseObject($source, $prompt, basename($path), $seed);
                $spent++;

                $file = "{$out}/{$stem}-{$name}.jpg";
                file_put_contents($file, $bytes);

                $size = @getimagesizefromstring($bytes);

                $this->info(sprintf(
                    '  %-6s %s  (%s)',
                    $name,
                    $file,
                    $size ? "{$size[0]}x{$size[1]}" : 'unreadable',
                ));
            } catch (\Throwable $e) {
                $this->error("  {$name}  " . $e->getMessage());
            }
        }

        $this->newLine();
        $this->line("Spent: {$spent} credit" . ($spent === 1 ? '' : 's') . ".");
        $this->warn('Compare each against the real product before trusting any of it — this pass has'
            . ' previously reinvented a printed logo on this account.');

        return self::SUCCESS;
    }
}
