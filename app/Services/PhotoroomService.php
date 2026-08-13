<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Photoroom's Image Editing API (v2/edit) — background removal, mannequin
 * removal, shadows, positioning and sizing in a single call per image.
 *
 * The endpoint takes multipart form fields and answers with raw image bytes,
 * not JSON, so there is nothing to unwrap on success — the body IS the result.
 */
class PhotoroomService
{
    private const ENDPOINT = 'https://image-api.photoroom.com/v2/edit';

    /**
     * Photoroom refuses anything above these, so callers shrink first rather
     * than spending a request to be told no.
     */
    public const MAX_INPUT_BYTES = 29_000_000; // API limit is 30 MB
    public const MAX_INPUT_EDGE  = 5000;       // widest side, in pixels

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
     * @param  array  $edits  normalised options — see buildFields()
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

        $fields = $this->buildFields($edits);

        return $this->postWithRetry($imageContent, $filename, $fields);
    }

    /**
     * Translate our own option names into Photoroom's parameter names.
     *
     * Kept in one place so the form, the queue job and the API can never drift
     * apart on what "remove the background and centre it" actually means.
     */
    public function buildFields(array $edits): array
    {
        $fields = [
            'removeBackground' => !empty($edits['remove_background']) ? 'true' : 'false',
        ];

        // No colour means the cutout keeps its transparency, which only PNG can
        // carry — enforced again in outputFormat() below.
        if (!empty($edits['background_color'])) {
            $fields['background.color'] = ltrim((string) $edits['background_color'], '#');
        }

        // The mannequin/dress form is removed and the garment's shape rebuilt.
        // Apparel only — Photoroom returns the image untouched for anything else.
        if (!empty($edits['ghost_mannequin'])) {
            $fields['ghostMannequin.mode'] = 'ai.auto';
        }

        if (!empty($edits['flat_lay'])) {
            $fields['flatLay.mode'] = 'ai.auto';
        }

        if (!empty($edits['shadow'])) {
            $fields['shadow.mode'] = (string) $edits['shadow'];
        }

        if (!empty($edits['lighting'])) {
            $fields['lighting.mode'] = 'ai.auto';
        }

        if (!empty($edits['upscale'])) {
            $fields['upscale.mode'] = 'ai.auto';
        }

        if (!empty($edits['text_removal'])) {
            $fields['textRemoval.mode'] = (string) $edits['text_removal'];
        }

        // ── Size and placement ───────────────────────────────────────────────
        // Photoroom sizes natively ("1000x1000"), so the subject is composed
        // into the final canvas in one pass rather than being resized twice.
        if (!empty($edits['width']) && !empty($edits['height'])) {
            $fields['outputSize'] = ((int) $edits['width']) . 'x' . ((int) $edits['height']);
        }

        // Padding is a fraction of the canvas (0–0.49). Anything at or above
        // half would leave no room for the subject at all.
        if (isset($edits['padding']) && $edits['padding'] !== '' && $edits['padding'] !== null) {
            $fields['padding'] = (string) max(0, min(0.49, (float) $edits['padding']));
        }

        $fields['horizontalAlignment'] = in_array($edits['h_align'] ?? '', ['left', 'center', 'right'], true)
            ? $edits['h_align']
            : 'center';

        $fields['verticalAlignment'] = in_array($edits['v_align'] ?? '', ['top', 'center', 'bottom'], true)
            ? $edits['v_align']
            : 'center';

        // "fit" keeps the whole subject visible; "fill" crops it to the edges.
        $fields['scaling'] = ($edits['scaling'] ?? 'fit') === 'fill' ? 'fill' : 'fit';

        $fields['export.format'] = $this->outputFormat($edits);

        return $fields;
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
        $isTransparent = !empty($edits['remove_background']) && empty($edits['background_color']);

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
    private function postWithRetry(string $imageContent, string $filename, array $fields, int $retries = 2): string
    {
        $attempt  = 0;
        $lastError = 'Photoroom did not return an image.';

        do {
            $attempt++;
            $retryable = false;

            try {
                $request = Http::withHeaders([
                    'x-api-key' => $this->apiKey,
                    'Accept'    => 'image/png, image/jpeg, application/json',
                ])->timeout(120);

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
