<?php

namespace App\Jobs;

use App\Exceptions\GeminiQuotaException;
use App\Models\CollectionContentItem;
use App\Models\CollectionContentSession;
use App\Models\Store;
use App\Services\GeminiService;
use App\Services\SearchConsoleService;
use App\Services\ShopifyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Writes the SEO content for collection pages.
 *
 * Separate from the product generator because the job is genuinely different:
 * there is no photograph, the evidence is whatever products the collection
 * holds, and the copy has to speak to a category search rather than a model
 * name. Sharing the product path would have meant a prompt full of exceptions.
 */
class GenerateCollectionContentJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;
    public int $tries   = 1;

    /** Search terms handed to the model, and how far back they are read. */
    private const MAX_SEARCH_TERMS        = 10;
    private const SEARCH_TERM_WINDOW_DAYS = 90;

    public function __construct(public readonly int $sessionId) {}

    public function handle(GeminiService $gemini): void
    {
        $session = CollectionContentSession::find($this->sessionId);

        if (!$session) {
            return;
        }

        $store   = $session->store_id ? Store::find($session->store_id) : null;
        $shopify = $this->shopifyFor($store);

        $session->update(['status' => 'processing']);

        try {
            $processed = 0;

            foreach ($session->items()->where('status', 'pending')->get() as $item) {
                $this->generateFor($session, $item, $shopify, $gemini, $store?->name ?? '');

                $session->update(['processed_items' => ++$processed]);
            }

            $session->update(['status' => 'ready']);
        } catch (GeminiQuotaException $e) {
            // Whatever already generated is still good and still worth pushing,
            // so the session stays reviewable rather than hiding behind a
            // failure screen.
            Log::error('GenerateCollectionContentJob: Gemini quota exhausted', ['session' => $session->id]);

            CollectionContentItem::where('session_id', $session->id)
                ->where('status', 'processing')
                ->update(['status' => 'failed', 'error_message' => $e->getMessage()]);

            $anyDone = $session->items()->where('status', 'done')->exists();

            $session->update([
                'status'        => $anyDone ? 'ready' : 'failed',
                'error_message' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            Log::error('GenerateCollectionContentJob failed', ['session' => $session->id, 'error' => $e->getMessage()]);
            $session->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
        }
    }

    private function generateFor(
        CollectionContentSession $session,
        CollectionContentItem $item,
        ShopifyService $shopify,
        GeminiService $gemini,
        string $storeName,
    ): void {
        $item->update(['status' => 'processing']);

        $collection = $shopify->getCollectionForContent($item->collection_id);

        if (!$collection) {
            $item->update([
                'status'        => 'failed',
                'error_message' => 'This collection no longer exists in Shopify.',
            ]);

            return;
        }

        $content = $gemini->generateForCollection(
            $collection['title'],
            $collection['description'] ?? '',
            $collection['products'] ?? [],
            $storeName,
            $this->searchTermsFor($session, $collection['handle'] ?? ''),
        );

        if (!$content) {
            $item->update([
                'status'        => 'failed',
                'error_message' => 'Gemini returned nothing usable for this collection.',
            ]);

            return;
        }

        // What was there before is kept alongside the suggestion, so the review
        // screen can show the change rather than only the replacement.
        $item->update([
            'title'                     => $collection['title'],
            'handle'                    => $collection['handle'] ?? '',
            'existing_description'      => $collection['description'] ?? '',
            'existing_meta_title'       => $collection['meta_title'],
            'existing_meta_description' => $collection['meta_description'],
            'ai_description'            => $content['description'],
            'ai_meta_title'             => $content['meta_title'],
            'ai_meta_description'       => $content['meta_description'],
            'status'                    => 'done',
            'error_message'             => null,
        ]);
    }

    /**
     * The search terms to write this collection against: whatever was typed for
     * the batch, plus what this page is already shown for. Identical in spirit
     * to the product path, and just as optional.
     *
     * @return list<string>
     */
    protected function searchTermsFor(CollectionContentSession $session, string $handle): array
    {
        $manual = collect(preg_split('/[\n,]+/', (string) $session->keywords))
            ->map(fn ($term) => trim($term))
            ->filter()
            ->all();

        $store = $session->store_id ? Store::find($session->store_id) : null;

        if (!$handle || !$store?->gsc_site_url) {
            return array_slice($manual, 0, self::MAX_SEARCH_TERMS);
        }

        try {
            $to   = SearchConsoleService::latestCompleteDay();
            $from = $to->copy()->subDays(self::SEARCH_TERM_WINDOW_DAYS);

            $queries = array_column(
                $this->searchConsole()->topQueriesForPage(
                    (string) $store->gsc_site_url,
                    '/collections/' . $handle,
                    $from,
                    $to,
                    self::MAX_SEARCH_TERMS,
                ),
                'query',
            );
        } catch (\Throwable $e) {
            // An enhancement, never a reason to abandon a run that would
            // otherwise have produced good content.
            Log::warning('GenerateCollectionContentJob: search terms unavailable', [
                'session' => $session->id,
                'handle'  => $handle,
                'error'   => $e->getMessage(),
            ]);

            $queries = [];
        }

        return collect($manual)->merge($queries)
            ->unique(fn ($term) => mb_strtolower($term))
            ->take(self::MAX_SEARCH_TERMS)
            ->values()
            ->all();
    }

    /** Seams: overridden in tests so nothing reaches Shopify or Google. */
    protected function shopifyFor(?Store $store): ShopifyService
    {
        return new ShopifyService($store);
    }

    protected function searchConsole(): SearchConsoleService
    {
        return new SearchConsoleService();
    }

    public function failed(?\Throwable $e): void
    {
        CollectionContentSession::where('id', $this->sessionId)
            ->whereIn('status', ['pending', 'processing'])
            ->update([
                'status'        => 'failed',
                'error_message' => $e
                    ? \Illuminate\Support\Str::limit($e->getMessage(), 500)
                    : 'Generation stopped before it finished — most often the queue worker timeout.',
            ]);
    }
}
