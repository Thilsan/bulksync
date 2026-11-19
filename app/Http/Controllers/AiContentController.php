<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateAiContentJob;
use App\Jobs\PushAiContentJob;
use App\Jobs\TranslateAiContentJob;
use App\Models\AiContentItem;
use App\Models\AiContentSession;
use App\Models\Store;
use App\Support\ProductTagTaxonomy;
use Illuminate\Http\Request;
use App\Support\Queues;

class AiContentController extends Controller
{
    /**
     * The generator form. Session history moved to history() so this page can
     * be just the one job it does.
     */
    public function index()
    {
        $activeStore = Store::getActive();

        return view('ai-content.index', compact('activeStore'));
    }

    /**
     * Overview of AI content activity. Scoped to the signed-in user's own
     * sessions, matching how every other screen in this module reads.
     */
    public function dashboard()
    {
        $scope = fn () => AiContentSession::where('user_id', auth()->id());

        $sessionIds = (clone $scope())->select('id');

        // Item-level counts are what people actually ask about ("how many
        // descriptions were written"), so they come off the items table.
        $totals = [
            'sessions'   => (clone $scope())->count(),
            'items'      => (int) (clone $scope())->sum('total_items'),
            'processed'  => (int) (clone $scope())->sum('processed_items'),
            'translated' => AiContentItem::whereIn('session_id', $sessionIds)->whereNotNull('ai_description_ar')->count(),
            'confirmed'  => AiContentItem::whereIn('session_id', $sessionIds)->where('is_confirmed', true)->count(),
        ];

        $running = (clone $scope())
            ->whereIn('status', ['pending', 'processing', 'translating', 'pushing'])
            ->with('store')
            ->latest()
            ->limit(4)
            ->get();

        $recent = (clone $scope())->with('store')->latest()->limit(6)->get();

        // 14 days of daily output, zero-filled so the chart has no gaps.
        $rows = (clone $scope())
            ->where('created_at', '>=', now()->subDays(13)->startOfDay())
            ->selectRaw('DATE(created_at) AS day')
            ->selectRaw('COALESCE(SUM(processed_items), 0) AS processed')
            ->groupBy('day')
            ->pluck('processed', 'day');

        $daily = collect(range(13, 0))->map(function ($back) use ($rows) {
            $date = now()->subDays($back)->startOfDay();

            return [
                'date'      => $date,
                'processed' => (int) ($rows[$date->format('Y-m-d')] ?? 0),
            ];
        });

        return view('ai-content.dashboard', compact('totals', 'running', 'recent', 'daily'));
    }

    /** Every session this user has run, paginated. */
    public function history()
    {
        $sessions = AiContentSession::where('user_id', auth()->id())
            ->with('store')
            ->latest()
            ->paginate(20);

        return view('ai-content.history', compact('sessions'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'input_type' => 'required|in:sku_list,csv_upload',
            'sku_raw'    => 'required_if:input_type,sku_list|nullable|string',
            'csv_file'   => 'required_if:input_type,csv_upload|nullable|file|mimes:csv,txt|max:10240',
            'keywords'   => 'nullable|string|max:2000',
        ]);

        if ($request->input_type === 'sku_list') {
            $skus = collect(explode("\n", $request->sku_raw))
                ->map(fn ($s) => strtoupper(trim($s)))
                ->filter()
                ->unique()
                ->values();
        } else {
            $skus = $this->parseSkuCsv($request->file('csv_file')->getRealPath());
        }

        if ($skus->isEmpty()) {
            return back()->with('warning', 'No valid SKUs found.');
        }

        $store = Store::getActive();

        $session = AiContentSession::create([
            'user_id'    => auth()->id(),
            'store_id'   => $store?->id,
            'input_type' => $request->input_type,
            'sku_raw'    => $request->input_type === 'sku_list' ? $request->sku_raw : null,
            'skus_json'  => json_encode($skus->values()->all()),
            'keywords'   => $request->input('keywords') ?: null,
            'status'     => 'pending',
            'total_items' => $skus->count(),
        ]);

        GenerateAiContentJob::dispatch($session->id)->onQueue(Queues::AI);

        return redirect()->route('ai-content.show', $session)
            ->with('success', 'AI content generation started.');
    }

    private function parseSkuCsv(string $path): \Illuminate\Support\Collection
    {
        $skus   = collect();
        $handle = fopen($path, 'r');
        if (!$handle) return $skus;

        $isFirstRow = true;

        while (($line = fgetcsv($handle)) !== false) {
            $value = strtoupper(trim($line[0] ?? ''));

            if ($isFirstRow) {
                $isFirstRow = false;
                if (in_array($value, ['SKU', 'VARIANT SKU', 'VARIANT_SKU'])) {
                    continue;
                }
            }

            if ($value !== '') {
                $skus->push($value);
            }
        }

        fclose($handle);

        return $skus->unique()->values();
    }

    public function show(AiContentSession $aiContentSession)
    {
        abort_if($aiContentSession->user_id !== auth()->id(), 403);

        // The fixed tag vocabulary this store offers per category/type, in
        // place of model-invented tags. Empty for a store not yet configured.
        $tagTaxonomy = ProductTagTaxonomy::forStore($aiContentSession->store);

        return view('ai-content.show', compact('aiContentSession', 'tagTaxonomy'));
    }

    public function status(AiContentSession $aiContentSession)
    {
        abort_if($aiContentSession->user_id !== auth()->id(), 403);

        return response()->json([
            'status'           => $aiContentSession->status,
            'status_label'     => $aiContentSession->statusLabel(),
            'total_items'      => $aiContentSession->total_items,
            'processed_items'  => $aiContentSession->processed_items,
            'progress'         => $aiContentSession->progressPercent(),
            'error_message'    => $aiContentSession->error_message,
        ]);
    }

    public function items(AiContentSession $aiContentSession)
    {
        abort_if($aiContentSession->user_id !== auth()->id(), 403);

        $items = $aiContentSession->items()->with('images')->orderBy('id')->get();

        return response()->json($items);
    }

    public function translate(Request $request, AiContentSession $aiContentSession)
    {
        abort_if($aiContentSession->user_id !== auth()->id(), 403);

        if ($aiContentSession->status === 'translating') {
            return $this->respond($request, 'warning', 'Translation is already in progress.');
        }

        // Save any English edits from the review form first, so Arabic is
        // translated from the final approved English text
        $items = $aiContentSession->items()->with('images')->get();
        foreach ($items as $item) {
            $item->update([
                'ai_description'      => $request->input("description.{$item->id}", $item->ai_description),
                'ai_meta_title'       => $request->input("meta_title.{$item->id}", $item->ai_meta_title),
                'ai_meta_description' => $request->input("meta_description.{$item->id}", $item->ai_meta_description),
                'ai_title'            => $request->input("title.{$item->id}", $item->ai_title),
            ]);

            foreach ($item->images as $image) {
                $image->update([
                    'ai_alt_text' => $request->input("image_alt.{$image->id}", $image->ai_alt_text),
                ]);
            }
        }

        $aiContentSession->update(['status' => 'translating']);
        TranslateAiContentJob::dispatch($aiContentSession->id)->onQueue(Queues::AI);

        if ($request->expectsJson()) {
            return $this->respond($request, 'success', 'Arabic translation started.');
        }

        return redirect()->route('ai-content.show', $aiContentSession)
            ->with('success', 'Arabic translation started.');
    }

    public function push(Request $request, AiContentSession $aiContentSession)
    {
        abort_if($aiContentSession->user_id !== auth()->id(), 403);

        if (!Store::find($aiContentSession->store_id)) {
            return $this->respond($request, 'warning', 'No store associated with this session.');
        }

        if ($aiContentSession->status === 'pushing') {
            return $this->respond($request, 'warning', 'A push is already in progress.');
        }

        $confirmed         = array_map('intval', (array) $request->input('confirmed', []));
        $itemIds           = [];
        $overwriteTitleIds = [];
        $selectedTags      = [];

        foreach ($confirmed as $itemId) {
            $item = AiContentItem::where('id', $itemId)
                ->where('session_id', $aiContentSession->id)
                ->with('images')
                ->first();

            if (!$item || !$item->shopify_product_id) continue;

            // Save user-edited content (English pushes to Shopify now; Arabic is saved for a future translation push)
            $item->update([
                'ai_description'         => $request->input("description.{$itemId}", $item->ai_description),
                'ai_meta_title'          => $request->input("meta_title.{$itemId}", $item->ai_meta_title),
                'ai_meta_description'    => $request->input("meta_description.{$itemId}", $item->ai_meta_description),
                'ai_description_ar'      => $request->input("description_ar.{$itemId}", $item->ai_description_ar),
                'ai_meta_title_ar'       => $request->input("meta_title_ar.{$itemId}", $item->ai_meta_title_ar),
                'ai_meta_description_ar' => $request->input("meta_description_ar.{$itemId}", $item->ai_meta_description_ar),
                'ai_title'               => $request->input("title.{$itemId}", $item->ai_title),
                'is_confirmed'           => true,
            ]);

            foreach ($item->images as $image) {
                $image->update([
                    'ai_alt_text'    => $request->input("image_alt.{$image->id}", $image->ai_alt_text),
                    'ai_alt_text_ar' => $request->input("image_alt_ar.{$image->id}", $image->ai_alt_text_ar),
                ]);
            }

            $itemIds[] = $item->id;

            if ($request->boolean("overwrite_title.{$itemId}")) {
                $overwriteTitleIds[] = $item->id;
            }

            $tags = array_values(array_filter((array) $request->input("selected_tags.{$itemId}", [])));
            if (!empty($tags)) {
                $selectedTags[$item->id] = $tags;
            }
        }

        if (empty($itemIds)) {
            return $this->respond($request, 'warning', 'No items selected to push.');
        }

        // Shopify allows two calls a second and each product takes several, so
        // a large session runs far longer than a web request is allowed to —
        // the queue does the pushing and the page follows its progress.
        $aiContentSession->update([
            'status'          => 'pushing',
            'total_items'     => count($itemIds),
            'processed_items' => 0,
        ]);

        PushAiContentJob::dispatch($aiContentSession->id, auth()->id(), $itemIds, $overwriteTitleIds, $selectedTags)
            ->onQueue(Queues::AI);

        return $this->respond($request, 'success', count($itemIds) . ' product(s) are being pushed to Shopify.');
    }

    /**
     * The review page posts JSON, because a form field per edit runs past PHP's
     * max_input_vars on a large session and everything after the limit is
     * silently dropped. It reloads itself afterwards, so the message is flashed
     * for that next page view rather than returned.
     */
    private function respond(Request $request, string $level, string $message)
    {
        if ($request->expectsJson()) {
            session()->flash($level, $message);

            return response()->json(['message' => $message]);
        }

        return back()->with($level, $message);
    }

    public function destroy(AiContentSession $aiContentSession)
    {
        abort_if($aiContentSession->user_id !== auth()->id(), 403);
        $aiContentSession->delete();

        // Back to the list the delete was triggered from, not the generator form.
        return redirect()->route('ai-content.history')->with('success', 'Session deleted.');
    }
}
