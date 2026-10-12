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
 * Turn a pasted list of SKUs into photographs to edit.
 *
 * The other way into the editor. ScanPhotoEditFolderJob reads a OneDrive
 * folder of shoot files; this reads Shopify itself, for products whose
 * pictures are already live and want redoing — paste the SKUs, take each
 * product's first image, and the edited version goes back into the same
 * gallery slot.
 *
 * One image per SKU, the first one, because that is the one a shopper sees in
 * a collection grid and the one worth a credit. A product with five images
 * contributes one row here, not five.
 *
 * A SKU that finds nothing is recorded as a failed row rather than dropped.
 * Pasting fifty and getting forty-one back with no account of the nine is the
 * kind of silence that gets noticed a week later, on the shelf.
 */
class ScanShopifySkusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;
    public int $tries   = 2;

    /** @param list<string> $skus */
    public function __construct(
        public readonly int $sessionId,
        public readonly array $skus,
    ) {}

    public function handle(): void
    {
        $session = PhotoEditSession::find($this->sessionId);

        if (!$session) {
            return;
        }

        $session->update(['scan_status' => 'scanning']);

        try {
            /*
             * The run's own store, not the one in the header.
             *
             * A session records which storefront it was created against, and a
             * scan that read the picker instead would fetch Bluesalon's images
             * into a Diamantaire run whenever somebody switched tabs while it
             * was queued. The push job constructs its client the same way.
             */
            $shopify = new ShopifyService(Store::find($session->store_id));

            $found = $shopify->findVariantsBySkus($this->skus);
            $rows  = [];

            foreach ($this->skus as $sku) {
                $variants = $found[$sku] ?? [];

                if ($variants === []) {
                    $rows[] = $this->missing($session, $sku, 'This SKU was not found in Shopify.');

                    continue;
                }

                /*
                 * The first variant's product. Several variants of one product
                 * share its gallery, so a size run of eight returns eight rows
                 * pointing at the same picture — editing it eight times would
                 * spend eight credits to overwrite the same slot.
                 */
                $variant = $variants[0];
                $images  = $shopify->getProductImages((string) $variant['product_id']);

                if ($images === []) {
                    $rows[] = $this->missing($session, $sku, 'This product has no images on Shopify.', $variant);

                    continue;
                }

                $image = $images[0];

                $rows[] = [
                    'photo_edit_session_id' => $session->id,
                    'kind'                  => 'cutout',
                    'filename'              => $sku . '.jpg',
                    'sku_detected'          => $sku,
                    'source_url'            => (string) ($image['src'] ?? ''),
                    'replaces_image_id'     => (string) ($image['id'] ?? ''),
                    'position'              => (int) ($image['position'] ?? 1),
                    'product_id'            => (string) $variant['product_id'],
                    'product_title'         => (string) ($variant['product_title'] ?? ''),
                    'variant_id'            => (string) $variant['variant_id'],
                    'variant_sku'           => (string) ($variant['variant_sku'] ?? $sku),
                    'status'                => 'pending',
                    'selected'              => true,
                    'created_at'            => now(),
                    'updated_at'            => now(),
                ];
            }

            PhotoEditItem::insert($rows);

            $session->update([
                'scan_status'   => 'done',
                'total_files'   => count($rows),
                'scanned_files' => count($rows),
                'failed_files'  => collect($rows)->where('status', 'failed')->count(),
                'status'        => 'configuring',
            ]);
        } catch (\Throwable $e) {
            Log::error("ScanShopifySkusJob session {$this->sessionId}: " . $e->getMessage());

            $session->update([
                'scan_status'   => 'failed',
                'status'        => 'failed',
                'error_message' => 'Could not read the SKUs from Shopify: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * A SKU that produced no photograph, filed rather than forgotten.
     *
     * @param  array<string, mixed>  $variant
     * @return array<string, mixed>
     */
    private function missing(PhotoEditSession $session, string $sku, string $why, array $variant = []): array
    {
        return [
            'photo_edit_session_id' => $session->id,
            'kind'                  => 'cutout',
            'filename'              => $sku . '.jpg',
            'sku_detected'          => $sku,
            'product_id'            => (string) ($variant['product_id'] ?? ''),
            'product_title'         => (string) ($variant['product_title'] ?? ''),
            'variant_id'            => (string) ($variant['variant_id'] ?? ''),
            'variant_sku'           => (string) ($variant['variant_sku'] ?? $sku),
            'status'                => 'failed',
            'selected'              => false,
            'error_message'         => $why,
            'created_at'            => now(),
            'updated_at'            => now(),
        ];
    }
}
