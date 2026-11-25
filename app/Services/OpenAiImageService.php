<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The second photo-editing engine: OpenAI's image edit endpoint.
 *
 * Photoroom takes a list of switches. OpenAI takes one instruction in plain
 * words. So this class reads the same edit settings the run already stores
 * (background, ghost mannequin, ironing, price tag) and writes them out as a
 * prompt. Everything before and after the edit — fetching, rotating, cropping,
 * framing onto the canvas, the JPEG — is still done by EditPhotoItemJob exactly
 * as it is for Photoroom.
 *
 * What OpenAI cannot do here is Photoroom's virtual models, so a photo set to
 * go on a model is still sent to Photoroom (see EditPhotoItemJob).
 *
 * It regenerates the whole image rather than cutting it out, so fine print and
 * small text on a product can drift. The prompt asks it not to; compare on a
 * few products before running a large folder through it.
 */
class OpenAiImageService
{
    private const ENDPOINT = 'https://api.openai.com/v1/images/edits';

    public const ENGINE_PHOTOROOM = 'photoroom';
    public const ENGINE_OPENAI    = 'openai';

    /** What the settings screen offers. */
    public const ENGINES = [
        self::ENGINE_PHOTOROOM => 'Photoroom',
        self::ENGINE_OPENAI    => 'OpenAI (GPT Image)',
    ];

    /** Which engine a set of edits asks for; anything unrecognised is Photoroom. */
    public static function engineFor(array $edits): string
    {
        return ($edits['engine'] ?? null) === self::ENGINE_OPENAI
            ? self::ENGINE_OPENAI
            : self::ENGINE_PHOTOROOM;
    }

    public function isConfigured(): bool
    {
        return filled(config('services.openai.api_key'));
    }

    public function model(): string
    {
        return (string) config('services.openai.image_model', 'gpt-image-2');
    }

    /**
     * Edit one product photograph and return the image bytes (PNG).
     *
     * @throws \RuntimeException with OpenAI's own message when it refuses.
     */
    public function edit(string $imageContent, array $edits, string $filename = 'image.jpg'): string
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('OpenAI is not set up — OPENAI_API_KEY is missing from .env.');
        }

        $info = @getimagesizefromstring($imageContent);
        $mime = $info['mime'] ?? 'image/jpeg';
        $ext  = match ($mime) {
            'image/png'  => 'png',
            'image/webp' => 'webp',
            default      => 'jpg',
        };

        $transparent = $this->wantsTransparency($edits);

        $fields = [
            'model'         => $this->model(),
            'prompt'        => $this->buildPrompt($edits),
            'size'          => $this->outputSize((int) ($info[0] ?? 0), (int) ($info[1] ?? 0)),
            'quality'       => (string) config('services.openai.image_quality', 'high'),
            'output_format' => 'png',
            'background'    => $transparent ? 'transparent' : 'opaque',
            'n'             => '1',
        ];

        $request = Http::withToken((string) config('services.openai.api_key'))
            ->timeout((int) config('services.openai.timeout', 240))
            ->attach('image[]', $imageContent, pathinfo($filename, PATHINFO_FILENAME) . '.' . $ext, [
                'Content-Type' => $mime,
            ]);

        $response = $request->post(self::ENDPOINT, $fields);

        if (!$response->successful()) {
            $message = (string) ($response->json('error.message') ?? $response->body());

            Log::warning('OpenAI image edit refused', [
                'status' => $response->status(),
                'model'  => $fields['model'],
                'error'  => $message,
            ]);

            throw new \RuntimeException('OpenAI refused the edit (HTTP ' . $response->status() . '): ' . $message);
        }

        $b64 = $response->json('data.0.b64_json');

        if (!is_string($b64) || $b64 === '') {
            throw new \RuntimeException('OpenAI answered but sent no image back.');
        }

        $image = base64_decode($b64, true);

        if ($image === false || @getimagesizefromstring($image) === false) {
            throw new \RuntimeException('OpenAI sent back something that is not an image.');
        }

        Log::info('OpenAI image edit', [
            'model' => $fields['model'],
            'size'  => $fields['size'],
            'usage' => $response->json('usage'),
        ]);

        return $image;
    }

    /**
     * The edit settings, written out as an instruction.
     *
     * The first paragraph is the one that matters most and is always sent:
     * the product is the thing being sold, so it must come back as it was
     * photographed, not as the model imagines it.
     */
    public function buildPrompt(array $edits): string
    {
        $lines = [
            'Edit this product photograph for an online store catalogue. Keep the product exactly as '
            . 'photographed: the same shape, proportions, colours, materials, texture, stitching, prints, '
            . 'logos, labels and any text on it. Do not redraw, restyle, recolour, add or remove any part '
            . 'of the product. Show the whole product, uncropped, centred, with clear space on every side.',
        ];

        $noun = trim((string) ($edits['segmentation_prompt'] ?? ''));
        if ($noun !== '') {
            $lines[] = "The product is: {$noun}.";
        }

        /*
         * Always sent, because a back shot came back as a front: a plain back
         * of a t-shirt looks to the model like a front it should complete,
         * so it drew a front neckline on it. The photo marked "back" on the
         * settings screen gets it said outright as well.
         */
        $back = !empty($edits['back_view']);

        $lines[] = 'Keep the same side of the product facing the camera, at the same angle, as in the photo. '
            . 'Do not turn it around, flip it or mirror it.';

        if ($back) {
            $lines[] = 'This photo shows the BACK of the garment. The result must show the back: a plain back '
                . 'with the back of the collar, and no front neckline, buttons, pockets, logos or labels that '
                . 'are not visible in this photo.';
        }

        if (!empty($edits['ghost_mannequin'])) {
            $lines[] = 'Remove the mannequin, stand, hanger and any clamps completely, and show the garment as '
                . 'if worn by an invisible body (a ghost mannequin shot), keeping its natural worn shape.'
                . ($back
                    ? ''
                    : ' Where the neck or openings show the inside, show the garment\'s own inside fabric there.');
        } elseif (!empty($edits['flat_lay'])) {
            $lines[] = 'Remove any hanger or mannequin and present the garment as a neat flat lay, seen from '
                . 'directly above.';
        }

        if (!empty($edits['ironing'])) {
            $lines[] = 'Smooth out the wrinkles and creases in the fabric, as if freshly pressed, while keeping '
                . 'every seam, print, trim and button exactly where it is.';
        }

        if (!empty($edits['remove_price_tag'])) {
            $lines[] = 'Remove any paper price tag, barcode ticket or swing tag and the string or plastic loop '
                . 'holding it. Keep labels that are sewn or printed onto the product.';
        }

        $lines[] = $this->backgroundInstruction($edits);

        return implode("\n\n", $lines);
    }

    private function backgroundInstruction(array $edits): string
    {
        if (array_key_exists('remove_background', $edits) && empty($edits['remove_background'])
            && ($edits['background_mode'] ?? null) !== 'blur') {
            return 'Keep the original background and lighting unchanged.';
        }

        return match ($edits['background_mode'] ?? 'white') {
            'transparent' => 'Remove the background completely, leaving only the product on a transparent '
                . 'background, with no shadow.',
            'custom'      => 'Replace the background with a flat, even, solid colour #'
                . ltrim((string) ($edits['background_color'] ?? 'FFFFFF'), '#')
                . ', with no props, gradient or texture. A soft, natural contact shadow under the product is fine.',
            'blur'        => 'Keep the original scene behind the product but blur it softly so the product '
                . 'stands out.',
            default       => 'Replace the background with pure, even studio white (#FFFFFF), with no props, '
                . 'gradient or texture. A soft, natural contact shadow under the product is fine.',
        };
    }

    private function wantsTransparency(array $edits): bool
    {
        return !empty($edits['remove_background'])
            && ($edits['background_mode'] ?? null) === 'transparent';
    }

    /**
     * The size to ask for: the photo's own shape, its longest side at the
     * configured edge.
     *
     * gpt-image-2 accepts any size whose sides are multiples of 16 with an
     * aspect between 1:3 and 3:1. Asking for the photo's own shape means the
     * product is not squeezed into a square and then framed back out of it —
     * the canvas and margins are applied locally afterwards, as with Photoroom.
     */
    public function outputSize(int $width, int $height): string
    {
        $edge = max(1024, min(3840, (int) config('services.openai.image_edge', 2048)));

        if ($width <= 0 || $height <= 0) {
            return "{$edge}x{$edge}";
        }

        $ratio = max(1 / 3, min(3, $width / $height));

        [$w, $h] = $ratio >= 1
            ? [$edge, $edge / $ratio]
            : [$edge * $ratio, $edge];

        // The model's pixel-count range: 655,360 to 8,294,400 (3840x2160).
        $pixels = $w * $h;
        if ($pixels > 8_000_000 || $pixels < 700_000) {
            $scale = sqrt(($pixels > 8_000_000 ? 8_000_000 : 700_000) / $pixels);
            [$w, $h] = [$w * $scale, $h * $scale];
        }

        $snap = fn (float $v) => max(16, (int) (round($v / 16) * 16));

        return $snap($w) . 'x' . $snap($h);
    }
}
