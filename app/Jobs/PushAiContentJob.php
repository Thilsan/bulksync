<?php

namespace App\Jobs;

use App\Models\AiContentItem;
use App\Models\AiContentSession;
use App\Models\ProductRequest;
use App\Models\SeoContentPush;
use App\Models\Store;
use App\Models\User;
use App\Services\ProductRequestWorkflow;
use App\Services\ShopifyService;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Sends reviewed AI content to Shopify.
 *
 * This used to happen inside the Push button's own request. Every product is
 * three to ten Shopify calls (body, two SEO metafields, tags, one per image's
 * alt text) and the store allows two a second, so a 500-SKU session needs the
 * best part of an hour — the web server gave up after a few minutes and every
 * product after that point was silently left unpushed.
 */
class PushAiContentJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;
    public int $tries   = 1;

    /**
     * How long one job pushes before handing the rest to a fresh job — the same
     * reasoning as GenerateAiContentJob::CHUNK_SECONDS: time spent depends on
     * image count, not on how many items were ticked.
     */
    private const CHUNK_SECONDS = 2400;

    /**
     * @param list<int>                   $itemIds           Items the reviewer ticked, in page order.
     * @param list<int>                   $overwriteTitleIds Items whose Shopify title should be replaced.
     * @param array<int, list<string>>    $selectedTags      Tags to add, by item id.
     */
    public function __construct(
        public readonly int $sessionId,
        public readonly int $userId,
        public readonly array $itemIds,
        public readonly array $overwriteTitleIds = [],
        public readonly array $selectedTags = [],
        public readonly int $pushedSoFar = 0,
    ) {}

    public function handle(): void
    {
        $session = AiContentSession::find($this->sessionId);
        if (!$session) return;

        $store = Store::find($session->store_id);
        if (!$store) {
            $session->update(['status' => 'ready']);
            return;
        }

        $shopify   = app(ShopifyService::class, ['store' => $store]);
        $startedAt = microtime(true);
        $pushed    = $this->pushedSoFar;

        foreach ($this->itemIds as $index => $itemId) {
            if (microtime(true) - $startedAt >= self::CHUNK_SECONDS) {
                self::dispatch(
                    $this->sessionId,
                    $this->userId,
                    array_slice($this->itemIds, $index),
                    $this->overwriteTitleIds,
                    $this->selectedTags,
                    $pushed,
                )->onQueue(Queues::AI);

                return;
            }

            $item = AiContentItem::where('id', $itemId)
                ->where('session_id', $session->id)
                ->with('images')
                ->first();

            // Only items that actually generated. A failed item has no copy, and
            // pushing it would blank the product's existing description.
            if ($item && $item->shopify_product_id && in_array($item->status, ['done', 'pushed'], true)) {
                if ($this->pushItem($shopify, $store, $item)) {
                    $pushed++;
                }
            }

            $session->increment('processed_items');
        }

        if ($pushed === 0) {
            $session->update(['status' => 'ready']);

            return;
        }

        // Read back what went live and grade it, rather than assume the push
        // fixed what the audit found. The re-check marks the session done.
        $session->update(['status' => 'rechecking']);
        RecheckSeoAfterPushJob::dispatch($session->id)->onQueue(Queues::AI);

        // The copy is live, which is what the request's content stage was
        // waiting for — move it on without anyone pressing a button.
        $actor = User::find($this->userId);
        ProductRequest::where('ai_content_session_id', $session->id)->get()
            ->each(fn ($r) => app(ProductRequestWorkflow::class)->autoAdvance($r, $actor));
    }

    /**
     * Called when the job dies outright, most likely the worker timeout. Puts
     * the session back where it can be pushed again rather than leaving the
     * page on a progress bar that will never move.
     */
    public function failed(?\Throwable $e): void
    {
        AiContentSession::where('id', $this->sessionId)
            ->where('status', 'pushing')
            ->update(['status' => 'ready']);
    }

    private function pushItem(ShopifyService $shopify, Store $store, AiContentItem $item): bool
    {
        try {
            $overwriteTitle = in_array($item->id, $this->overwriteTitleIds, true);

            $shopify->updateProductContent(
                $item->shopify_product_id,
                $item->ai_description ?? '',
                $item->ai_meta_title ?? '',
                $item->ai_meta_description ?? '',
                $overwriteTitle ? ($item->ai_title ?? '') : '',
            );

            // Additive only — checked suggestions get added, nothing existing is ever removed or replaced
            $tags = $this->selectedTags[$item->id] ?? [];
            if (!empty($tags)) {
                $shopify->addProductTags($item->shopify_product_id, $tags);
            }

            foreach ($item->images as $image) {
                if (!$image->shopify_image_id || !$image->ai_alt_text) continue;

                try {
                    $shopify->updateImageAlt($item->shopify_product_id, $image->shopify_image_id, $image->ai_alt_text);
                    $image->update(['status' => 'pushed']);
                } catch (\Throwable $e) {
                    Log::error('AiContent image alt push failed', ['image' => $image->id, 'error' => $e->getMessage()]);
                    $image->update(['status' => 'failed', 'error_message' => 'Push failed: ' . $e->getMessage()]);
                }
            }

            // The record the impact report is later built from. Written
            // only after Shopify accepted the content, so a failed push
            // never becomes a data point.
            SeoContentPush::create([
                'user_id'          => $this->userId,
                'store_id'         => $store->id,
                'product_id'       => (string) $item->shopify_product_id,
                'product_title'    => $item->product_title,
                'meta_title'       => $item->ai_meta_title,
                'meta_description' => $item->ai_meta_description,
                'pushed_at'        => now(),
            ]);

            $item->update(['status' => 'pushed', 'error_message' => null]);

            return true;
        } catch (\Throwable $e) {
            Log::error('AiContent push failed', ['item' => $item->id, 'error' => $e->getMessage()]);
            $item->update(['error_message' => 'Push failed: ' . $e->getMessage()]);

            return false;
        }
    }
}
