<?php

namespace App\Jobs;

use App\Exceptions\ShopifyRequestException;
use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\Store;
use App\Services\ShopifyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Send one approved edit to Shopify, matching it to a product the same way the
 * bulk uploader does — by the folder name the image was found under.
 */
class PushEditedPhotoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;
    public int $tries   = 3;
    public int $backoff = 30;

    public function __construct(
        public readonly int $itemId,
    ) {}

    public function handle(): void
    {
        $item = PhotoEditItem::find($this->itemId);

        if (!$item || $item->status === 'pushed') {
            return;
        }

        // The full-size file is deleted once an image reaches Shopify, so an
        // item without one has either been pushed already or been swept.
        if (!$item->edited_path || !is_file(storage_path('app/' . $item->edited_path))) {
            $item->update([
                'status'        => 'failed',
                'error_message' => 'The edited file is no longer on disk — re-run the edit before pushing.',
            ]);
            $this->syncPushedCount($item->photo_edit_session_id);
            return;
        }

        $session = PhotoEditSession::find($item->photo_edit_session_id);

        if (!$session) {
            return;
        }

        $item->update(['status' => 'pushing']);

        $store   = $session->store_id ? Store::find($session->store_id) : Store::getActive($session->user_id);
        $shopify = new ShopifyService($store);

        $matchingMode = $session->matching_mode ?? 'sku_barcode';

        try {
            // Live lookup, not the warm SKU cache — see ProcessUploadItemJob:
            // a snapshot older than the product records a false No Match.
            //
            // throwOnFailure: a network blip must surface as a retryable failure
            // rather than being recorded as "this SKU does not exist".
            $variants = $matchingMode === 'style_code'
                ? $shopify->findProductsByStyleCode($item->sku_detected, true)
                : $shopify->findVariantsBySkuOrBarcode($item->sku_detected, true);

            if (empty($variants)) {
                $label = $matchingMode === 'style_code' ? 'style code' : 'SKU or barcode';

                $item->update([
                    'status'        => 'skipped',
                    'error_message' => "No Shopify product found for {$label}: {$item->sku_detected}",
                ]);
                $this->syncPushedCount($session->id);
                return;
            }

            $uniqueProductIds = array_unique(array_column($variants, 'product_id'));

            if (count($uniqueProductIds) > 1) {
                $item->update([
                    'status'        => 'skipped',
                    'error_message' => 'That identifier is on ' . count($uniqueProductIds) . ' different products — push skipped.',
                ]);
                $this->syncPushedCount($session->id);
                return;
            }

            $variant   = $variants[0];
            $content   = file_get_contents(storage_path('app/' . $item->edited_path));
            $extension = pathinfo($item->edited_path, PATHINFO_EXTENSION) ?: 'jpg';
            $filename  = pathinfo($item->filename, PATHINFO_FILENAME) . '.' . $extension;

            // Style-code matches are gallery-only; they have no variant to bind to.
            $variantId = $matchingMode === 'style_code' ? null : ($variant['variant_id'] ?? null);

            $imageId = $shopify->uploadImageToProduct(
                $variant['product_id'],
                $content,
                $filename,
                $item->sku_detected,
                $variantId,
                $this->galleryPosition($item),
            );

            unset($content);

            if ($variantId && $imageId) {
                $shopify->setVariantImage($variantId, $imageId);
            }

            $item->update([
                'status'           => 'pushed',
                'product_id'       => $variant['product_id'],
                'product_title'    => $variant['product_title'] ?? null,
                'variant_id'       => $variantId,
                'variant_sku'      => $variant['variant_sku'] ?? null,
                'shopify_image_id' => $imageId,
                'error_message'    => null,
            ]);

            // Shopify now holds these bytes permanently, so our copy is a
            // duplicate — and it is the largest thing this feature writes.
            $item->discardFullSize();

        } catch (\Throwable $e) {
            Log::error("PushEditedPhotoJob item {$this->itemId} failed: " . $e->getMessage());

            $item->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            // A refusal from Shopify itself — a product that no longer exists,
            // an image past its limits — reads the same however often it is
            // asked. Retrying only re-sends megabytes to earn the same answer.
            if ($e instanceof ShopifyRequestException && $e->isPermanent()) {
                $this->syncPushedCount($item->photo_edit_session_id);
                return;
            }

            if ($this->attempts() < $this->tries) {
                $this->syncPushedCount($item->photo_edit_session_id);
                throw $e;
            }
        }

        $this->syncPushedCount($item->photo_edit_session_id);
    }

    public function failed(\Throwable $e): void
    {
        $item = PhotoEditItem::find($this->itemId);

        if (!$item) {
            return;
        }

        $item->update([
            'status'        => 'failed',
            'error_message' => 'Max retries reached: ' . $e->getMessage(),
        ]);

        $this->syncPushedCount($item->photo_edit_session_id);
    }

    /**
     * Where this photo belongs in its product's gallery.
     *
     * Counted rather than carried: each photo is pushed by its own job, several
     * run at once, and any of them may be retried minutes later. Asking "how
     * many of my SKU's chosen photos come before me" gives the same answer
     * whenever it is asked, so the gallery ends up in the order the operator
     * arranged no matter which upload finishes first.
     */
    private function galleryPosition(PhotoEditItem $item): int
    {
        $ahead = PhotoEditItem::where('photo_edit_session_id', $item->photo_edit_session_id)
            ->where('sku_detected', $item->sku_detected)
            ->where('selected', true)
            ->whereKeyNot($item->getKey())
            ->where(function ($q) use ($item) {
                $q->where('position', '<', $item->position)
                    ->orWhere(function ($q) use ($item) {
                        // Same position, so the filename breaks the tie — the
                        // same tiebreak the screens sort by.
                        $q->where('position', $item->position)
                            ->where('filename', '<', $item->filename);
                    });
            })
            ->count();

        return $ahead + 1;
    }

    private function syncPushedCount(int $sessionId): void
    {
        PhotoEditSession::where('id', $sessionId)->update([
            'pushed_files' => PhotoEditItem::where('photo_edit_session_id', $sessionId)
                ->where('status', 'pushed')->count(),
        ]);
    }
}
