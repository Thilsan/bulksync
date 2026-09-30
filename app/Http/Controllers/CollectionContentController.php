<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateCollectionContentJob;
use App\Models\CollectionContentItem;
use App\Models\CollectionContentSession;
use App\Models\SeoAuditItem;
use App\Models\SeoAuditSession;
use App\Models\Store;
use App\Services\ShopifyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * SEO content for collection pages.
 *
 * Kept apart from the AI Content Generator because a collection is not a
 * product: it has no SKU to look it up by and no photograph to write from, so
 * every entry point that module offers is the wrong shape for it.
 */
class CollectionContentController extends Controller
{
    /** Collections one run may take. Generation is billed per page. */
    public const MAX_BATCH = 100;

    public function index()
    {
        $sessions = CollectionContentSession::where('user_id', auth()->id())
            ->with('store')
            ->latest()
            ->paginate(20);

        return view('collection-content.index', [
            'sessions'    => $sessions,
            'activeStore' => Store::getActive(),
        ]);
    }

    /**
     * Start a run over the whole store's collections.
     *
     * There are only ever a few dozen, so unlike products there is no list to
     * paste: the sensible default is all of them.
     */
    public function store(Request $request)
    {
        $request->validate(['keywords' => 'nullable|string|max:2000']);

        $store = Store::getActive();

        try {
            $collections = $this->collectionsFor($store);
        } catch (\Throwable $e) {
            Log::error('CollectionContent: could not list collections', ['error' => $e->getMessage()]);

            return back()->with('warning', 'Shopify could not be reached to list the collections. Try again shortly.');
        }

        if (empty($collections)) {
            return back()->with('warning', 'This website has no collections to write for.');
        }

        return $this->begin($store, $collections, $request->input('keywords'));
    }

    /**
     * Start a run over the collections an SEO audit flagged, which is the
     * normal way in: the audit already knows which ones are missing what.
     */
    public function fromAudit(SeoAuditSession $seoAuditSession, Request $request)
    {
        abort_if($seoAuditSession->user_id !== auth()->id(), 403);

        $collections = $seoAuditSession->items()
            ->where('resource_type', SeoAuditItem::TYPE_COLLECTION)
            ->where('issue_count', '>', 0)
            ->orderBy('score')
            ->limit(self::MAX_BATCH)
            ->get(['product_id', 'product_title', 'handle'])
            ->map(fn (SeoAuditItem $item) => [
                'id'     => $item->product_id,
                'title'  => $item->product_title,
                'handle' => $item->handle,
            ])
            ->all();

        if (empty($collections)) {
            return back()->with('warning', 'No collections in this audit need fixing.');
        }

        return $this->begin(
            Store::find($seoAuditSession->store_id) ?? Store::getActive(),
            $collections,
            $request->input('keywords'),
        );
    }

    public function show(CollectionContentSession $collectionContentSession)
    {
        abort_if($collectionContentSession->user_id !== auth()->id(), 403);

        return view('collection-content.show', [
            'session' => $collectionContentSession->load('items'),
        ]);
    }

    public function status(CollectionContentSession $collectionContentSession)
    {
        abort_if($collectionContentSession->user_id !== auth()->id(), 403);

        return response()->json([
            'status'          => $collectionContentSession->status,
            'total_items'     => $collectionContentSession->total_items,
            'processed_items' => $collectionContentSession->processed_items,
            'progress'        => $collectionContentSession->progressPercent(),
            'error_message'   => $collectionContentSession->error_message,
        ]);
    }

    /**
     * Save the edits and write the ticked collections to Shopify.
     *
     * Field by field rather than whole-record: someone may want the new meta
     * description and none of the new body copy, and an all-or-nothing push
     * would make them choose between the two.
     */
    public function push(CollectionContentSession $collectionContentSession, Request $request)
    {
        abort_if($collectionContentSession->user_id !== auth()->id(), 403);

        $store     = Store::find($collectionContentSession->store_id) ?? Store::getActive();
        $shopify   = $this->shopifyFor($store);
        $confirmed = (array) $request->input('confirmed', []);

        $pushed = 0;
        $failed = 0;

        foreach ($collectionContentSession->items as $item) {
            // Edits are saved for every row, ticked or not: someone who reviews
            // half the list today should not lose that work by not pushing it.
            $item->update([
                'ai_description'      => $request->input("description.{$item->id}", $item->ai_description),
                'ai_meta_title'       => $request->input("meta_title.{$item->id}", $item->ai_meta_title),
                'ai_meta_description' => $request->input("meta_description.{$item->id}", $item->ai_meta_description),
            ]);

            if (!in_array((string) $item->id, array_map('strval', $confirmed), true)) {
                continue;
            }

            try {
                $shopify->updateCollectionContent(
                    $item->collection_id,
                    $request->boolean("push_description.{$item->id}") ? (string) $item->ai_description : '',
                    (string) $item->ai_meta_title,
                    (string) $item->ai_meta_description,
                );

                $item->update(['status' => 'pushed', 'is_confirmed' => true, 'error_message' => null]);
                $pushed++;
            } catch (\Throwable $e) {
                Log::error('CollectionContent push failed', ['item' => $item->id, 'error' => $e->getMessage()]);
                $item->update(['error_message' => 'Push failed: ' . $e->getMessage()]);
                $failed++;
            }
        }

        if ($pushed > 0) {
            $collectionContentSession->update(['status' => 'done']);
        }

        $message = "{$pushed} collection(s) updated in Shopify.";

        if ($failed > 0) {
            $message .= " {$failed} failed — check the rows below.";
        }

        return back()->with('success', $message);
    }

    public function destroy(CollectionContentSession $collectionContentSession)
    {
        abort_if($collectionContentSession->user_id !== auth()->id(), 403);

        $collectionContentSession->delete();

        return redirect()->route('collection-content.index')->with('success', 'Session deleted.');
    }

    /** @param  list<array{id: string, title: string, handle: string}>  $collections */
    private function begin(?Store $store, array $collections, ?string $keywords)
    {
        $collections = array_slice($collections, 0, self::MAX_BATCH);

        $session = CollectionContentSession::create([
            'user_id'     => auth()->id(),
            'store_id'    => $store?->id,
            'status'      => 'pending',
            'total_items' => count($collections),
            'keywords'    => $keywords ?: null,
        ]);

        foreach ($collections as $collection) {
            CollectionContentItem::create([
                'session_id'    => $session->id,
                'collection_id' => (string) $collection['id'],
                'title'         => $collection['title'] ?? '',
                'handle'        => $collection['handle'] ?? '',
                'status'        => 'pending',
            ]);
        }

        GenerateCollectionContentJob::dispatch($session->id)->onQueue('bulkupload');

        return redirect()->route('collection-content.show', $session)
            ->with('success', count($collections) . ' collection(s) queued. Review the suggestions before anything is pushed.');
    }

    /** @return list<array{id: string, title: string, handle: string}> */
    private function collectionsFor(?Store $store): array
    {
        $found = [];

        $this->shopifyFor($store)->streamCollectionsForSeoAudit(function (array $page) use (&$found) {
            foreach ($page as $collection) {
                $found[] = [
                    'id'     => (string) $collection['id'],
                    'title'  => $collection['title'] ?? '',
                    'handle' => $collection['handle'] ?? '',
                ];
            }
        });

        return $found;
    }

    /** Seam: overridden in tests so nothing reaches Shopify. */
    protected function shopifyFor(?Store $store): ShopifyService
    {
        return new ShopifyService($store);
    }
}
