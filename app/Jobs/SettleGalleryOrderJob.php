<?php

namespace App\Jobs;

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
 * Put a run's photos into the order the operator chose, after they are all up.
 *
 * The order could not be settled while uploading. A position named on an
 * upload is clamped to the gallery as it stands at that instant, so asking for
 * position 7 of an eventual 27 puts the image at the end when only six are
 * there — and with several workers uploading at once, which six are there is
 * decided by whichever job happened to finish first. The result on a luggage
 * product was 27 photos in no order at all: front, three angles, an interior,
 * a side, a front again.
 *
 * So the sequence is asserted once, at the end, against a gallery that is
 * finally complete. That is the only moment at which the numbers mean what they
 * say.
 *
 * Positions are walked in ascending order because Shopify renumbers everything
 * around the image that moves: setting the last one first would shuffle the
 * ones already placed.
 */
class SettleGalleryOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries   = 2;
    public int $backoff = 60;

    public function __construct(
        public readonly int $sessionId,
    ) {}

    public function handle(): void
    {
        $session = PhotoEditSession::find($this->sessionId);

        if (!$session) {
            return;
        }

        // Resolved the same way the push itself resolves it, so the gallery is
        // reordered on the store the images were actually sent to.
        $store = $session->store_id ? Store::find($session->store_id) : Store::getActive($session->user_id);

        if (!$store) {
            Log::warning("SettleGalleryOrderJob session {$this->sessionId}: no store");
            return;
        }

        $shopify = new ShopifyService($store);

        /*
         * A run can touch several products — a folder of colourways is one
         * product and several SKUs, and a run of mixed folders is several
         * products. Each gallery is settled on its own.
         */
        $products = PhotoEditItem::where('photo_edit_session_id', $session->id)
            ->whereNotNull('product_id')
            ->whereNotNull('shopify_image_id')
            ->distinct()
            ->pluck('product_id');

        foreach ($products as $productId) {
            $this->settle($shopify, (string) $productId);
        }
    }

    /**
     * The sequence one product's photos should end up in.
     *
     * SKU first, then the order the operator dragged the tiles into, then the
     * filename to break a tie — the same sort the review grid shows, and the
     * same one galleryPosition() uses while uploading.
     *
     * SKU first is what keeps colourways apart. A grey case and a black case
     * are two SKUs on one Shopify product, and each numbering its own photos
     * from one is what laid a gallery out grey, black, grey, black.
     *
     * Public and static so the ordering can be tested without a Shopify store
     * behind it: it is the part that has been wrong twice, and it is the part
     * that needs no network to check.
     *
     * @return list<string>
     */
    public static function desiredOrder(int $sessionId, string $productId): array
    {
        return PhotoEditItem::where('product_id', $productId)
            ->where('photo_edit_session_id', $sessionId)
            ->whereNotNull('shopify_image_id')
            ->orderBy('sku_detected')
            ->orderBy('position')
            ->orderBy('filename')
            ->pluck('shopify_image_id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
    }

    private function settle(ShopifyService $shopify, string $productId): void
    {
        $desired = self::desiredOrder($this->sessionId, $productId);

        if (count($desired) < 2) {
            return;
        }

        /*
         * What is already right costs nothing to leave alone. A re-push of one
         * photo would otherwise renumber an entire gallery for no reason, and
         * every move is an API call against a two-a-second budget.
         */
        $current = collect($shopify->getProductImages($productId))
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();

        $ours = array_values(array_filter($current, fn ($id) => in_array($id, $desired, true)));

        if ($ours === $desired) {
            Log::info("SettleGalleryOrderJob: product {$productId} already in order");
            return;
        }

        $moved = 0;

        foreach ($desired as $i => $imageId) {
            if ($shopify->setImagePosition($productId, $imageId, $i + 1)) {
                $moved++;
            }
        }

        Log::info("SettleGalleryOrderJob: product {$productId} reordered", [
            'images' => count($desired),
            'moved'  => $moved,
        ]);
    }
}
