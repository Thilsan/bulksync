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
        /*
         * The products this run touched. Each one's gallery is then settled
         * across every run that has ever fed it, not only this one — see
         * desiredOrder(). A run is the trigger, never the scope.
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
     * Across every run that has sent a photo to this product, not only the run
     * doing the asking. That is the whole correction here, and it is the same
     * mistake as the colourway one a level up: a product is not owned by a run.
     * Nineteen photos went up at 10:17 and eleven more at 10:39, each run
     * numbering its own from one, so the second run's images claimed positions
     * the first run's were already in and the gallery came out interleaved.
     *
     * Earlier run first, then the later one after it. Which is both what the
     * operator expects of a second push and the only rule that survives a
     * third: ordering runs by when they were created cannot depend on which of
     * them happens to be finishing now.
     *
     * Within a run: SKU, then the order the tiles were dragged into, then the
     * filename to break a tie — the review grid's own sort. SKU first is what
     * keeps a grey case and a black case from alternating down the page.
     *
     * Scoped to the store, because a product id means nothing without one and
     * two stores can easily both have a product 900.
     *
     * Public and static so the ordering can be tested without a Shopify store
     * behind it: it is the part that has been wrong twice, and it needs no
     * network to check.
     *
     * @return list<string>
     */
    public static function desiredOrder(int $sessionId, string $productId): array
    {
        $session = PhotoEditSession::find($sessionId);

        if (!$session) {
            return [];
        }

        return PhotoEditItem::query()
            ->join(
                'photo_edit_sessions',
                'photo_edit_sessions.id',
                '=',
                'photo_edit_items.photo_edit_session_id',
            )
            ->where('photo_edit_items.product_id', $productId)
            ->whereNotNull('photo_edit_items.shopify_image_id')
            ->when(
                $session->store_id,
                fn ($q) => $q->where('photo_edit_sessions.store_id', $session->store_id),
                // An older run saved before store_id was recorded would drop out
                // of its own gallery if this matched on null, so it is left in.
                fn ($q) => $q,
            )
            ->orderBy('photo_edit_sessions.created_at')
            ->orderBy('photo_edit_sessions.id')
            ->orderBy('photo_edit_items.sku_detected')
            ->orderBy('photo_edit_items.position')
            ->orderBy('photo_edit_items.filename')
            ->pluck('photo_edit_items.shopify_image_id')
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
