<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Photoroom's Image Editing API (v2/edit).
 *
 * The endpoint takes multipart form fields and answers with raw image bytes,
 * not JSON, so there is nothing to unwrap on success — the body IS the result.
 *
 * Every option the form offers is translated in buildFields(). Keeping that in
 * one place is what stops the form, the queue job and the API drifting apart on
 * what "remove the background and centre it" actually means.
 */
class PhotoroomService
{
    private const ENDPOINT = 'https://image-api.photoroom.com/v2/edit';

    /**
     * Shadow overrides only exist on the newer shadow model, which has to be
     * asked for by header — without it the override fields are ignored in
     * silence and the result looks like the sliders did nothing.
     */
    private const SHADOW_MODEL_VERSION = '2026-04-15';

    /**
     * Photoroom refuses anything above these, so callers shrink first rather
     * than spending a request to be told no.
     */
    public const MAX_INPUT_BYTES = 29_000_000; // API limit is 30 MB
    public const MAX_INPUT_EDGE  = 5000;       // widest side, in pixels

    /** Canvas presets shared by ghost mannequin, flat lay and virtual model. */
    public const SIZE_PRESETS = [
        'SQUARE_HD'          => 'Square',
        'PORTRAIT_HD_4_3'    => 'Portrait 4:3',
        'PORTRAIT_HD_3_2'    => 'Portrait 3:2',
        'PORTRAIT_HD_16_9'   => 'Portrait 16:9',
        'LANDSCAPE_HD_4_3'   => 'Landscape 4:3',
        'LANDSCAPE_HD_3_2'   => 'Landscape 3:2',
        'LANDSCAPE_HD_16_9'  => 'Landscape 16:9',
    ];

    public const VIRTUAL_MODEL_PRESETS = [
        'avery', 'sam', 'taylor', 'kendall', 'jordan', 'casey', 'maya', 'reece',
        'lena', 'julia', 'jackson', 'sophia', 'emma', 'ava', 'zoe', 'fiona',
    ];

    public const VIRTUAL_MODEL_SCENES = [
        'random', 'street', 'bedroom', 'sunset', 'factory', 'studio', 'coloredstudio',
        'concretestudio', 'beach', 'tropical', 'library', 'forest', 'businessdistrict',
        'countryside', 'flowers', 'goldenlight', 'mountain', 'pool', 'latincity',
        'cafe', 'asiancity', 'nightlights', 'desert',
    ];

    public const VIRTUAL_MODEL_POSES = [
        'random', 'standing', '34turn', 'powerstance', 'walkingforward', 'handinpocket',
        'crossedarms', 'back', 'overtheshoulder', 'seated', 'adjustingclothing', 'playfulspin',
    ];

    public const SHADOW_MODES = [
        ''                       => 'None',
        'ai.soft'                => 'Soft',
        'ai.hard'                => 'Hard',
        'ai.floating'            => 'Floating',
        'ai.auto-with-overrides' => 'Custom',
    ];

    public const SHADOW_SPREADS    = ['short', 'medium', 'long'];
    public const SHADOW_DIRECTIONS = ['behind', 'behindLeft', 'left', 'frontLeft', 'front', 'frontRight', 'right', 'behindRight'];
    public const SHADOW_POSES      = ['flatlay', 'upright'];

    public const BEAUTIFY_MODES = [
        ''         => 'Off',
        'ai.auto'  => 'General',
        'ai.food'  => 'Food',
        'ai.car'   => 'Vehicles',
    ];

    public const TEXT_REMOVAL_MODES = [
        ''              => 'Keep all text',
        'ai.artificial' => 'Added graphics only',
        'ai.natural'    => 'Printed on the product',
        'ai.all'        => 'Everything',
    ];

    public const BACKGROUND_MODES = ['transparent', 'white', 'custom', 'prompt', 'image', 'blur'];

    /**
     * Ghost Mannequin only reconstructs front views, so it can't help a back
     * or side shot where the stand is left visible. This is the closest
     * Photoroom gets to "erase that object" for such a photo.
     */
    private const MANNEQUIN_REMOVAL_PROMPT = 'Remove the mannequin, dress form, or headless body stand visible in this photo. '
        . 'Keep the garment exactly as it is, in the same position and shape, floating in its place. '
        . 'Do not add a person or any other object.';

    private string $apiKey;

    public function __construct()
    {
        $this->apiKey = (string) (config('services.photoroom.api_key') ?? '');
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * A sandbox key edits 1000 images a month for free but stamps a watermark
     * across every result. Worth saying out loud in the UI — otherwise the
     * output reads as broken rather than as free.
     */
    public function isSandbox(): bool
    {
        return str_starts_with($this->apiKey, 'sandbox_');
    }

    /**
     * Send one image through Photoroom and return the edited bytes.
     *
     * @throws \RuntimeException  when Photoroom refuses the image outright
     */
    public function edit(string $imageContent, array $edits, string $filename = 'image.jpg'): string
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('No Photoroom API key is configured. Add PHOTOROOM_API_KEY to the environment.');
        }

        if (strlen($imageContent) > self::MAX_INPUT_BYTES) {
            throw new \RuntimeException('Image is larger than Photoroom accepts (30 MB) even after downscaling.');
        }

        return $this->postWithRetry(
            $imageContent,
            $filename,
            $this->buildFields($edits),
            $this->buildHeaders($edits),
        );
    }

    /**
     * Erase a visible mannequin/dress-form stand from a photo via Photoroom's
     * generative editWithAI mode, returning the cleaned-up bytes.
     *
     * Deliberately its own request rather than a field folded into edit():
     * Photoroom warns that mixing editWithAI with removeBackground in one
     * call gives unpredictable results, so this pass runs first and its
     * output becomes the input to the normal edit() call.
     *
     * @throws \RuntimeException  when Photoroom refuses the image outright
     */
    public function removeMannequin(string $imageContent, string $filename = 'image.jpg'): string
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('No Photoroom API key is configured. Add PHOTOROOM_API_KEY to the environment.');
        }

        return $this->postWithRetry($imageContent, $filename, [
            'editWithAI.mode'   => 'ai.auto',
            'editWithAI.prompt' => self::MANNEQUIN_REMOVAL_PROMPT,
            'export.format'     => 'jpg',
        ]);
    }

    /**
     * Extra request headers some options depend on.
     */
    public function buildHeaders(array $edits): array
    {
        return ($edits['shadow'] ?? null) === 'ai.auto-with-overrides'
            ? ['pr-ai-shadows-model-version' => self::SHADOW_MODEL_VERSION]
            : [];
    }

    /**
     * Translate our own option names into Photoroom's parameter names.
     */
    public function buildFields(array $edits): array
    {
        $fields = [];

        $this->applyBackground($fields, $edits);
        $this->applyApparel($fields, $edits);
        $this->applyEnhancements($fields, $edits);
        $this->applyShadow($fields, $edits);
        $this->applyLayout($fields, $edits);

        $fields['export.format'] = $this->outputFormat($edits);

        if (!empty($edits['dpi'])) {
            $fields['export.dpi'] = (string) max(72, min(1200, (int) $edits['dpi']));
        }

        return $fields;
    }

    // ── Field groups ───────────────────────────────────────────────────────

    private function applyBackground(array &$fields, array $edits): void
    {
        $mode = $this->backgroundMode($edits);

        // Blur keeps the original scene and softens it, so the background is
        // explicitly NOT removed — the two are alternatives, not a sequence.
        if ($mode === 'blur') {
            $fields['removeBackground']    = 'false';
            $fields['background.blur.mode'] = in_array($edits['background_blur_mode'] ?? '', ['gaussian', 'bokeh'], true)
                ? $edits['background_blur_mode']
                : 'gaussian';
            $fields['background.blur.radius'] = (string) max(0, min(0.05, (float) ($edits['background_blur_radius'] ?? 0.02)));

            return;
        }

        $fields['removeBackground'] = !empty($edits['remove_background']) ? 'true' : 'false';

        if (empty($edits['remove_background'])) {
            return;
        }

        match ($mode) {
            'white'  => $fields['background.color'] = 'FFFFFF',
            'custom' => $fields['background.color'] = ltrim((string) ($edits['background_color'] ?? 'FFFFFF'), '#') ?: 'FFFFFF',
            'prompt' => $fields['background.prompt'] = (string) ($edits['background_prompt'] ?? ''),
            'image'  => $fields['background.imageUrl'] = (string) ($edits['background_image_url'] ?? ''),
            default  => null, // transparent — no background field at all
        };

        // A generated scene is random per call; a seed makes a re-run repeatable.
        if ($mode === 'prompt' && !empty($edits['background_seed'])) {
            $fields['background.seed'] = (string) (int) $edits['background_seed'];
        }
    }

    private function applyApparel(array &$fields, array $edits): void
    {
        $size   = $edits['apparel_size'] ?? null;
        $prompt = trim((string) ($edits['apparel_prompt'] ?? ''));

        if (!empty($edits['virtual_model'])) {
            $fields['virtualModel.mode'] = 'ai.auto';

            if (in_array($edits['vm_model'] ?? '', self::VIRTUAL_MODEL_PRESETS, true)) {
                $fields['virtualModel.model.preset.name'] = $edits['vm_model'];
            }
            if (in_array($edits['vm_scene'] ?? '', self::VIRTUAL_MODEL_SCENES, true)) {
                $fields['virtualModel.scene.preset.name'] = $edits['vm_scene'];
            }
            if (in_array($edits['vm_pose'] ?? '', self::VIRTUAL_MODEL_POSES, true)) {
                $fields['virtualModel.pose'] = $edits['vm_pose'];
            }
            if ($prompt !== '') {
                $fields['virtualModel.prompt'] = $prompt;
            }
            if (isset(self::SIZE_PRESETS[$size])) {
                $fields['virtualModel.size'] = $size;
            }
        } elseif (!empty($edits['ghost_mannequin'])) {
            $fields['ghostMannequin.mode'] = 'ai.auto';

            if ($prompt !== '') {
                $fields['ghostMannequin.prompt'] = $prompt;
            }
            if (isset(self::SIZE_PRESETS[$size])) {
                $fields['ghostMannequin.size'] = $size;
            }
        } elseif (!empty($edits['flat_lay'])) {
            $fields['flatLay.mode'] = 'ai.auto';

            if ($prompt !== '') {
                $fields['flatLay.prompt'] = $prompt;
            }
            if (isset(self::SIZE_PRESETS[$size])) {
                $fields['flatLay.size'] = $size;
            }
        }

        // Ironing is independent of the three above — a garment can be pressed
        // whether it is on a mannequin, laid flat or on a model.
        if (!empty($edits['ironing'])) {
            $fields['ironing.mode'] = 'ai.auto';
        }
    }

    private function applyEnhancements(array &$fields, array $edits): void
    {
        if (!empty($edits['lighting'])) {
            $fields['lighting.mode'] = 'ai.auto';
        }

        if (!empty($edits['upscale'])) {
            $fields['upscale.mode'] = 'ai.auto';
        }

        if (!empty($edits['expand'])) {
            $fields['expand.mode'] = 'ai.auto';
        }

        if (!empty($edits['uncrop'])) {
            $fields['uncrop.mode'] = 'ai.auto';
        }

        if (!empty($edits['beautify']) && array_key_exists($edits['beautify'], self::BEAUTIFY_MODES)) {
            $fields['beautify.mode'] = $edits['beautify'];
        }

        if (!empty($edits['text_removal']) && array_key_exists($edits['text_removal'], self::TEXT_REMOVAL_MODES)) {
            $fields['textRemoval.mode'] = $edits['text_removal'];
        }

        if (!empty($edits['outline_color'])) {
            $fields['outline.color'] = ltrim((string) $edits['outline_color'], '#');
            $fields['outline.width'] = (string) max(0, min(0.1, (float) ($edits['outline_width'] ?? 0.03)));
        }
    }

    private function applyShadow(array &$fields, array $edits): void
    {
        $mode = $edits['shadow'] ?? null;

        if (!$mode || !array_key_exists($mode, self::SHADOW_MODES)) {
            return;
        }

        $fields['shadow.mode'] = $mode;

        if ($mode !== 'ai.auto-with-overrides') {
            return;
        }

        if (isset($edits['shadow_softness'])) {
            $fields['shadow.softnessOverride'] = (string) max(0, min(1, (float) $edits['shadow_softness']));
        }
        if (isset($edits['shadow_intensity'])) {
            $fields['shadow.intensityOverride'] = (string) max(0, min(1, (float) $edits['shadow_intensity']));
        }
        if (in_array($edits['shadow_spread'] ?? '', self::SHADOW_SPREADS, true)) {
            $fields['shadow.spreadOverride'] = $edits['shadow_spread'];
        }
        if (in_array($edits['shadow_direction'] ?? '', self::SHADOW_DIRECTIONS, true)) {
            $fields['shadow.directionOverride'] = $edits['shadow_direction'];
        }
        if (in_array($edits['shadow_pose'] ?? '', self::SHADOW_POSES, true)) {
            $fields['shadow.subjectPoseOverride'] = $edits['shadow_pose'];
        }
    }

    private function applyLayout(array &$fields, array $edits): void
    {
        /*
         * A generated canvas (ghost mannequin, flat lay, virtual model) already
         * decides its own dimensions from its size preset. Sending outputSize
         * as well asks for the picture to be built at one shape and then forced
         * into another, which is where soft, upscaled results come from — so
         * the explicit size is only applied when nothing else is generating the
         * canvas.
         */
        if (!$this->generatesOwnCanvas($edits) && !empty($edits['width']) && !empty($edits['height'])) {
            $fields['outputSize'] = ((int) $edits['width']) . 'x' . ((int) $edits['height']);
        }

        // Padding is a fraction of the canvas. Half would leave no subject.
        if (isset($edits['padding']) && $edits['padding'] !== '' && $edits['padding'] !== null) {
            $fields['padding'] = (string) max(0, min(0.49, (float) $edits['padding']));
        }

        $fields['horizontalAlignment'] = in_array($edits['h_align'] ?? '', ['left', 'center', 'right'], true)
            ? $edits['h_align']
            : 'center';

        $fields['verticalAlignment'] = in_array($edits['v_align'] ?? '', ['top', 'center', 'bottom'], true)
            ? $edits['v_align']
            : 'center';

        $fields['scaling'] = ($edits['scaling'] ?? 'fit') === 'fill' ? 'fill' : 'fit';

        if (($edits['reference_box'] ?? '') === 'originalImage') {
            $fields['referenceBox'] = 'originalImage';
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /** True when an AI feature builds the canvas itself. */
    public function generatesOwnCanvas(array $edits): bool
    {
        return !empty($edits['ghost_mannequin'])
            || !empty($edits['flat_lay'])
            || !empty($edits['virtual_model']);
    }

    /**
     * Older sessions were stored before background_mode existed; their intent
     * is still readable from whether a colour was set.
     */
    private function backgroundMode(array $edits): string
    {
        $mode = $edits['background_mode'] ?? null;

        if (in_array($mode, self::BACKGROUND_MODES, true)) {
            return $mode;
        }

        return empty($edits['background_color']) ? 'transparent' : 'custom';
    }

    /**
     * PNG whenever the result has to carry transparency, JPEG otherwise.
     *
     * Asking for a JPEG of a transparent cutout is the one combination that
     * silently ruins the image — JPEG has no alpha channel, so the background
     * Photoroom just removed comes back as black.
     */
    public function outputFormat(array $edits): string
    {
        $isTransparent = !empty($edits['remove_background'])
            && $this->backgroundMode($edits) === 'transparent';

        return $isTransparent ? 'png' : 'jpg';
    }

    /**
     * True when the result will be a JPEG, and therefore safe to hand to
     * ImageProcessingService for compression without losing an alpha channel.
     */
    public function producesJpeg(array $edits): bool
    {
        return $this->outputFormat($edits) === 'jpg';
    }

    // ──────────────────────────────────────────────────────────────────────

    /**
     * POST with retries. Photoroom does not charge for a call that errors, so
     * a retry costs nothing but time: rate limits and 5xx are worth waiting
     * out, while a 4xx describes the image itself and would be refused again.
     */
    private function postWithRetry(string $imageContent, string $filename, array $fields, array $extraHeaders = [], int $retries = 2): string
    {
        $attempt   = 0;
        $lastError = 'Photoroom did not return an image.';

        do {
            $attempt++;
            $retryable = false;

            try {
                $request = Http::withHeaders(array_merge([
                    'x-api-key' => $this->apiKey,
                    'Accept'    => 'image/png, image/jpeg, application/json',
                ], $extraHeaders))->timeout(180);

                // Each field is attached as its own multipart part rather than
                // passed as an array: Photoroom's names contain dots
                // ("background.color"), which array-keyed bodies are free to
                // reinterpret as nesting.
                foreach ($fields as $name => $value) {
                    $request = $request->attach($name, (string) $value);
                }

                $request  = $request->attach('imageFile', $imageContent, $filename);
                $response = $request->post(self::ENDPOINT);

                if ($response->successful()) {
                    $body = $response->body();

                    // A successful status with a JSON body means the edit was
                    // refused in a way the status code did not admit to.
                    if ($body !== '' && !str_starts_with(ltrim($body), '{')) {
                        return $body;
                    }

                    $lastError = $this->describeError($body);
                } else {
                    $status    = $response->status();
                    $retryable = $status === 429 || $status >= 500;
                    $lastError = "Photoroom returned {$status}: " . $this->describeError($response->body());

                    Log::warning('Photoroom API error', [
                        'attempt' => $attempt,
                        'status'  => $status,
                        'body'    => substr($response->body(), 0, 500),
                    ]);

                    // A rejected image gives the same answer however often it
                    // is asked; stop rather than re-uploading megabytes for it.
                    if (!$retryable) {
                        break;
                    }
                }
            } catch (\Throwable $e) {
                $retryable = true;
                $lastError = 'Photoroom request failed: ' . $e->getMessage();
                Log::warning('Photoroom API exception', ['attempt' => $attempt, 'error' => $e->getMessage()]);
            }

            if ($retryable && $attempt <= $retries) {
                sleep($attempt * 5);
            }
        } while ($retryable && $attempt <= $retries);

        throw new \RuntimeException($lastError);
    }

    private function describeError(string $body): string
    {
        $decoded = json_decode($body, true);

        if (is_array($decoded)) {
            $detail = $decoded['detail'] ?? $decoded['error'] ?? $decoded['message'] ?? null;

            if (is_array($detail)) {
                $detail = $detail['message'] ?? json_encode($detail);
            }

            if ($detail) {
                return (string) $detail;
            }
        }

        return trim(substr($body, 0, 300)) ?: 'no detail given';
    }
}
