<?php

namespace App\Jobs;

use App\Models\BarcodeImageItem;
use App\Models\BarcodeImageSession;
use App\Models\Store;
use App\Services\ShopifyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Send a finished grab to Shopify: every barcode's folder onto the product
 * that barcode names.
 *
 * The matching is the photo editor's, deliberately — SKU-or-barcode, or the
 * style code that starts the product title — because it is the same question
 * asked of the same catalogues, and two answers to it that drift apart would
 * be worse than one shared.
 *
 * Images are appended to the product's gallery rather than replacing what is
 * there. What is already on a product was put there by somebody, and a grab
 * from another website is not grounds for this to decide it was wrong.
 */
class PushBarcodeImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 10800;
    public int $tries   = 1;

    public function __construct(public readonly int $sessionId) {}

    public function handle(): void
    {
        $session = BarcodeImageSession::find($this->sessionId);

        if (!$session) {
            return;
        }

        $store = Store::find($session->push_store_id);

        if (!$store) {
            $session->update([
                'push_status' => 'failed',
                'push_error'  => 'That website is no longer set up in Stores.',
            ]);

            return;
        }

        // Only barcodes that actually have pictures — the rest have nothing to
        // send, and counting them would make the progress bar lie.
        $items = $session->items()->where('image_count', '>', 0)->orderBy('id')->get();

        $session->update([
            'push_status' => 'pushing',
            'push_total'  => $items->count(),
            'push_done'   => 0,
            'push_pushed' => 0,
            'push_failed' => 0,
            'push_error'  => null,
        ]);

        $shopify = $this->shopifyFor($store);
        $mode    = $session->push_matching_mode === 'style_code' ? 'style_code' : 'sku_barcode';

        $done = 0;
        $ok   = 0;
        $bad  = 0;

        foreach ($items as $item) {
            try {
                $sent = $this->pushOne($session, $item, $shopify, $mode);

                $sent ? $ok++ : $bad++;
            } catch (\Throwable $e) {
                Log::error("PushBarcodeImagesJob: {$item->barcode} failed: " . $e->getMessage());

                $item->update(['push_status' => 'failed', 'push_message' => $e->getMessage()]);
                $bad++;
            }

            $done++;

            $session->update(['push_done' => $done, 'push_pushed' => $ok, 'push_failed' => $bad]);
        }

        $session->update(['push_status' => 'completed']);

        Log::info("PushBarcodeImagesJob: {$ok} pushed, {$bad} not, to {$store->name}.");
    }

    /** True when at least one image of this barcode reached a product. */
    private function pushOne(
        BarcodeImageSession $session,
        BarcodeImageItem $item,
        ShopifyService $shopify,
        string $mode,
    ): bool {
        $files = $this->filesFor($session, $item->barcode);

        if ($files === []) {
            $item->update([
                'push_status'  => 'skipped',
                'push_message' => 'The downloaded images are no longer on disk — grab them again before pushing.',
            ]);

            return false;
        }

        // throwOnFailure: a network blip has to surface as a failure rather
        // than be recorded as "this barcode is not on the store".
        $matches = $mode === 'style_code'
            ? $shopify->findProductsByStyleCode($item->barcode, true)
            : $shopify->findVariantsBySkuOrBarcode($item->barcode, true);

        if (empty($matches)) {
            $label = $mode === 'style_code' ? 'style code' : 'SKU or barcode';

            $item->update([
                'push_status'  => 'skipped',
                'push_message' => "No product on that website matched this {$label}.",
            ]);

            return false;
        }

        $productIds = array_unique(array_column($matches, 'product_id'));

        if (count($productIds) > 1) {
            // Sending the pictures to the wrong product is worse than sending
            // them nowhere, and nothing here can tell which was meant.
            $item->update([
                'push_status'  => 'skipped',
                'push_message' => 'That barcode is on ' . count($productIds) . ' different products — nothing was pushed.',
            ]);

            return false;
        }

        $match     = $matches[0];
        $productId = $match['product_id'];

        // A style-code match is a product, not a variant, so there is nothing
        // to bind the picture to — it goes into the gallery only.
        $variantId = $mode === 'style_code' ? null : ($match['variant_id'] ?? null);

        $sent = 0;

        foreach (array_values($files) as $index => $path) {
            $content = file_get_contents($path);

            if ($content === false) continue;

            $imageId = $shopify->uploadImageToProduct(
                $productId,
                $content,
                basename($path),
                $item->barcode,

                // Only the first photo is bound to the variant. Uploading with
                // a variant attached is itself an assignment and Shopify keeps
                // one image per variant, so passing it every time leaves
                // whichever upload finished last showing on the variant — the
                // lesson PushEditedPhotoJob learned the hard way.
                $index === 0 ? $variantId : null,
            );

            unset($content);

            if ($imageId) $sent++;
        }

        if ($sent === 0) {
            $item->update([
                'push_status'  => 'failed',
                'push_message' => 'Shopify accepted none of the images for this barcode.',
            ]);

            return false;
        }

        $item->update([
            'push_status'           => 'pushed',
            'shopify_product_id'    => $productId,
            'shopify_product_title' => $match['product_title'] ?? null,
            'pushed_images'         => $sent,
            'push_message'          => $sent < count($files)
                ? "{$sent} of " . count($files) . ' images were accepted.'
                : null,
        ]);

        return true;
    }

    /** Seam for tests: the live client is built from the store's own credentials. */
    protected function shopifyFor(Store $store): ShopifyService
    {
        return new ShopifyService($store);
    }

    /** This barcode's files, in the order they were numbered when downloaded. */
    private function filesFor(BarcodeImageSession $session, string $barcode): array
    {
        $files = glob($session->folderFor($barcode) . '/*') ?: [];

        $files = array_values(array_filter($files, 'is_file'));

        sort($files, SORT_NATURAL);

        return $files;
    }

    public function failed(\Throwable $e): void
    {
        BarcodeImageSession::where('id', $this->sessionId)->update([
            'push_status' => 'failed',
            'push_error'  => $e->getMessage(),
        ]);
    }
}
