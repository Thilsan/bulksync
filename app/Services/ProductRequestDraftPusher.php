<?php

namespace App\Services;

use App\Models\ProductRequest;
use App\Models\ProductRequestDraftProduct;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Creates the reviewed drafts in Shopify.
 *
 * Every product is created with status "draft" — this never puts anything on the
 * storefront. The team publishes from Shopify once they are happy, which keeps
 * the decision to go live where it belongs.
 */
class ProductRequestDraftPusher
{
    public function __construct(
        private ProductRequestWorkflow $workflow,
    ) {}

    /**
     * @param  array<int, int>|null  $only  draft product ids, or null for everything pushable
     * @return array{pushed: int, failed: int, skipped: int}
     */
    public function push(ProductRequest $request, Store $store, ?User $actor = null, ?array $only = null): array
    {
        $shopify = $this->shopifyFor($store);

        $query = $request->draftProducts()->with('variants')->orderBy('id');

        if ($only !== null) {
            $query->whereIn('id', $only);
        }

        $pushed = $failed = $skipped = 0;

        foreach ($query->get() as $draft) {
            // Already in Shopify, or not finished being filled in. Pushing a draft
            // twice would create a second product with the same SKUs.
            if ($draft->isPushed() || !$draft->isReadyToPush()) {
                $skipped++;
                continue;
            }

            try {
                $result = $shopify->createFullProduct($this->payload($draft));

                $draft->update([
                    'push_status'        => ProductRequestDraftProduct::PUSHED,
                    'shopify_product_id' => $result['product_id'],
                    'push_error'         => null,
                    'pushed_at'          => now(),
                    'pushed_to_store_id' => $store->id,
                ]);

                $pushed++;
            } catch (\Throwable $e) {
                Log::error("ProductRequestDraftPusher: {$request->reference} draft {$draft->id} failed: " . $e->getMessage());

                $draft->update([
                    'push_status' => ProductRequestDraftProduct::FAILED,
                    'push_error'  => $e->getMessage(),
                ]);

                $failed++;
            }
        }

        if ($pushed > 0) {
            $this->workflow->log(
                request:     $request,
                action:      'drafts_pushed',
                description: "{$pushed} draft product(s) created in {$store->name}",
                actor:       $actor,
                remarks:     'Created as drafts — not published.',
            );
        }

        return ['pushed' => $pushed, 'failed' => $failed, 'skipped' => $skipped];
    }

    /** Seam for tests, which have no Shopify store to talk to. */
    protected function shopifyFor(Store $store): ShopifyService
    {
        return new ShopifyService($store);
    }

    /** The shape ShopifyService::createFullProduct() expects. */
    private function payload(ProductRequestDraftProduct $draft): array
    {
        $options = array_map(fn ($name) => ['name' => $name], $draft->optionNames());

        $variants = $draft->variants->map(fn ($variant) => array_filter([
            'sku'                  => $variant->sku,
            'price'                => $variant->price,
            'compare_at_price'     => $variant->compare_at_price,
            'barcode'              => $variant->barcode,
            'weight'               => $variant->weight,
            'weight_unit'          => $variant->weight_unit,
            'inventory_quantity'   => $variant->inventory_qty,
            'inventory_management' => 'shopify',
            'option1'              => $variant->option1_value,
            'option2'              => $variant->option2_value,
            'option3'              => $variant->option3_value,
        ], fn ($value) => $value !== null && $value !== ''))->all();

        $images = collect([$draft->image_src])
            ->merge($draft->variants->pluck('image_src'))
            ->filter()
            ->unique()
            ->values()
            ->map(fn ($src) => ['src' => $src, 'alt' => $draft->title])
            ->all();

        return [
            'title'        => $draft->title,
            'body_html'    => $draft->body_html ?? '',
            'vendor'       => $draft->vendor ?? '',
            'product_type' => $draft->product_type ?? '',
            'tags'         => $draft->tags ?? '',
            'options'      => $options,
            'variants'     => $variants,
            'images'       => $images,
        ];
    }
}
