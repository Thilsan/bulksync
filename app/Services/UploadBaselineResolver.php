<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Answers "did this SKU already have its photo on Shopify before this batch
 * started?" exactly once per (session, SKU), then hands the same answer to
 * every sibling file in that SKU's folder.
 *
 * Asking per file cannot work: the first file of a folder assigns the variant
 * image, so a sibling that asks a second later gets a different — and wrong —
 * answer and drops itself as Already Has Image. The verdict has to be frozen
 * at the moment the batch first touches the SKU, and shared from there.
 */
class UploadBaselineResolver
{
    public const SCOPE_VARIANT = 'variant';
    public const SCOPE_PRODUCT = 'product';

    private const TABLE = 'upload_sku_baselines';

    /** 20 × 0.25s = a 5s ceiling on waiting for the probing worker's verdict. */
    private const POLL_ATTEMPTS = 20;
    private const POLL_USLEEP   = 250_000;

    /**
     * $probe is only ever called by the ONE worker that wins the claim for this
     * scope; it must return true when the SKU already has its image. Anything it
     * throws propagates to the caller so a transient Shopify failure retries the
     * job instead of silently recording "no image" and uploading a duplicate.
     */
    public function resolve(int $sessionId, string $scope, string $scopeId, Closure $probe): bool
    {
        // insertOrIgnore against the unique index is the claim: exactly one
        // concurrent worker sees a row count of 1, whatever the worker count.
        $claimed = DB::table(self::TABLE)->insertOrIgnore([
            'upload_session_id'     => $sessionId,
            'scope'                 => $scope,
            'scope_id'              => $scopeId,
            'has_existing_image'    => null,
            'variant_image_claimed' => false,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]) === 1;

        if ($claimed) {
            try {
                $verdict = (bool) $probe();
            } catch (\Throwable $e) {
                // Leave no half-claimed row behind, or every sibling burns the
                // full poll window waiting on a verdict that is never coming.
                $this->row($sessionId, $scope, $scopeId)->delete();
                throw $e;
            }

            $this->store($sessionId, $scope, $scopeId, $verdict);

            return $verdict;
        }

        for ($attempt = 0; $attempt < self::POLL_ATTEMPTS; $attempt++) {
            $verdict = $this->row($sessionId, $scope, $scopeId)->value('has_existing_image');

            if ($verdict !== null) {
                return (bool) $verdict;
            }

            usleep(self::POLL_USLEEP);
        }

        // The claiming worker was killed mid-probe (timeout, worker restart).
        // Probing ourselves is still the best answer available, and recording it
        // frees the remaining siblings from the same wait.
        Log::warning(self::TABLE . ": no verdict for {$scope}:{$scopeId} in session {$sessionId} after "
            . (self::POLL_ATTEMPTS * self::POLL_USLEEP / 1_000_000) . 's — probing directly.');

        $verdict = (bool) $probe();
        $this->store($sessionId, $scope, $scopeId, $verdict, onlyIfUndecided: true);

        return $verdict;
    }

    /**
     * Hand the variant's main-image slot to exactly one file of the folder.
     *
     * The old test counted sibling rows already marked 'uploaded', but that row
     * is written only after the Shopify upload returns — so two parallel files
     * could both believe they were first and both reassign the variant image.
     * A single conditional UPDATE cannot: only one of them changes a row.
     */
    public function claimVariantImageSlot(int $sessionId, string $scopeId): bool
    {
        return $this->row($sessionId, self::SCOPE_VARIANT, $scopeId)
            ->where('variant_image_claimed', false)
            ->update([
                'variant_image_claimed' => true,
                'updated_at'            => now(),
            ]) === 1;
    }

    /** Give the slot back when the upload it was claimed for never landed. */
    public function releaseVariantImageSlot(int $sessionId, string $scopeId): void
    {
        $this->row($sessionId, self::SCOPE_VARIANT, $scopeId)->update([
            'variant_image_claimed' => false,
            'updated_at'            => now(),
        ]);
    }

    /**
     * Record a known verdict without probing — used when recovering files that
     * a previous run dropped, where the sibling uploads are themselves proof
     * the SKU had no image of its own beforehand.
     */
    public function seed(
        int $sessionId,
        string $scope,
        string $scopeId,
        bool $hasExistingImage,
        bool $variantImageClaimed = false,
    ): void {
        DB::table(self::TABLE)->updateOrInsert(
            [
                'upload_session_id' => $sessionId,
                'scope'             => $scope,
                'scope_id'          => $scopeId,
            ],
            [
                'has_existing_image'    => $hasExistingImage,
                'variant_image_claimed' => $variantImageClaimed,
                'updated_at'            => now(),
                'created_at'            => now(),
            ],
        );
    }

    // ──────────────────────────────────────────────────────────────────────

    private function store(
        int $sessionId,
        string $scope,
        string $scopeId,
        bool $verdict,
        bool $onlyIfUndecided = false,
    ): void {
        $query = $this->row($sessionId, $scope, $scopeId);

        if ($onlyIfUndecided) {
            $query->whereNull('has_existing_image');
        }

        $query->update([
            'has_existing_image' => $verdict,
            'updated_at'         => now(),
        ]);
    }

    private function row(int $sessionId, string $scope, string $scopeId): \Illuminate\Database\Query\Builder
    {
        return DB::table(self::TABLE)
            ->where('upload_session_id', $sessionId)
            ->where('scope', $scope)
            ->where('scope_id', $scopeId);
    }
}
