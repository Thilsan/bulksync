<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateAiContentJob;
use App\Jobs\TranslateAiContentJob;
use App\Models\AiContentItem;
use App\Models\AiContentSession;
use App\Models\Store;
use App\Services\ShopifyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AiContentController extends Controller
{
    public function index()
    {
        $sessions = AiContentSession::where('user_id', auth()->id())
            ->with('store')
            ->latest()
            ->paginate(20);

        return view('ai-content.index', compact('sessions'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'input_type' => 'required|in:sku_list,csv_upload',
            'sku_raw'    => 'required_if:input_type,sku_list|nullable|string',
            'csv_file'   => 'required_if:input_type,csv_upload|nullable|file|mimes:csv,txt|max:10240',
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
            'status'     => 'pending',
            'total_items' => $skus->count(),
        ]);

        GenerateAiContentJob::dispatch($session->id)->onQueue('bulkupload');

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

        return view('ai-content.show', compact('aiContentSession'));
    }

    public function status(AiContentSession $aiContentSession)
    {
        abort_if($aiContentSession->user_id !== auth()->id(), 403);

        return response()->json([
            'status'           => $aiContentSession->status,
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
            return back()->with('warning', 'Translation is already in progress.');
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
        TranslateAiContentJob::dispatch($aiContentSession->id)->onQueue('bulkupload');

        return redirect()->route('ai-content.show', $aiContentSession)
            ->with('success', 'Arabic translation started.');
    }

    public function push(Request $request, AiContentSession $aiContentSession)
    {
        abort_if($aiContentSession->user_id !== auth()->id(), 403);

        $store = Store::find($aiContentSession->store_id);
        if (!$store) {
            return back()->with('warning', 'No store associated with this session.');
        }

        $shopify           = new ShopifyService($store);
        $confirmed         = $request->input('confirmed', []);
        $allCollections    = $shopify->getAllCollectionTitles();
        $pushed            = 0;
        $failed            = 0;

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

            try {
                $overwriteTitle = $request->boolean("overwrite_title.{$itemId}");

                $shopify->updateProductContent(
                    $item->shopify_product_id,
                    $item->ai_description,
                    $item->ai_meta_title,
                    $item->ai_meta_description,
                    $overwriteTitle ? ($item->ai_title ?? '') : '',
                );

                // Additive only — checked suggestions get added, nothing existing is ever removed or replaced
                $selectedTags = array_values(array_filter((array) $request->input("selected_tags.{$itemId}", [])));
                if (!empty($selectedTags)) {
                    $shopify->addProductTags($item->shopify_product_id, $selectedTags);
                }

                $selectedCollections = array_values(array_filter((array) $request->input("selected_collections.{$itemId}", [])));
                if (!empty($selectedCollections)) {
                    $shopify->addProductToCollections($item->shopify_product_id, $selectedCollections, $allCollections);
                }

                foreach ($item->images as $image) {
                    $altText   = $request->input("image_alt.{$image->id}", $image->ai_alt_text);
                    $altTextAr = $request->input("image_alt_ar.{$image->id}", $image->ai_alt_text_ar);
                    $image->update(['ai_alt_text' => $altText, 'ai_alt_text_ar' => $altTextAr]);

                    if ($image->shopify_image_id && $altText) {
                        try {
                            $shopify->updateImageAlt($item->shopify_product_id, $image->shopify_image_id, $altText);
                            $image->update(['status' => 'pushed']);
                        } catch (\Throwable $e) {
                            Log::error('AiContent image alt push failed', ['image' => $image->id, 'error' => $e->getMessage()]);
                            $image->update(['status' => 'failed', 'error_message' => 'Push failed: ' . $e->getMessage()]);
                        }
                    }
                }

                $item->update(['status' => 'pushed']);
                $pushed++;
            } catch (\Throwable $e) {
                Log::error('AiContent push failed', ['item' => $item->id, 'error' => $e->getMessage()]);
                $item->update(['error_message' => 'Push failed: ' . $e->getMessage()]);
                $failed++;
            }
        }

        if ($pushed > 0) {
            $aiContentSession->update(['status' => 'done']);
        }

        $message = "{$pushed} product(s) updated in Shopify.";
        if ($failed > 0) $message .= " {$failed} failed — check items below.";

        return back()->with('success', $message);
    }

    public function destroy(AiContentSession $aiContentSession)
    {
        abort_if($aiContentSession->user_id !== auth()->id(), 403);
        $aiContentSession->delete();
        return redirect()->route('ai-content.index')->with('success', 'Session deleted.');
    }
}
