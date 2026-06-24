<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateAiContentJob;
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
            'input_type'   => 'required|in:sku_list,onedrive',
            'sku_raw'      => 'required_if:input_type,sku_list|nullable|string',
            'onedrive_link' => 'required_if:input_type,onedrive|nullable|url',
        ]);

        $store = Store::getActive();

        $session = AiContentSession::create([
            'user_id'       => auth()->id(),
            'store_id'      => $store?->id,
            'input_type'    => $request->input_type,
            'onedrive_link' => $request->onedrive_link,
            'sku_raw'       => $request->sku_raw,
            'status'        => 'pending',
        ]);

        if ($request->input_type === 'sku_list') {
            $skus = collect(explode("\n", $request->sku_raw))
                ->map(fn ($s) => strtoupper(trim($s)))
                ->filter()
                ->unique()
                ->values();

            foreach ($skus as $sku) {
                AiContentItem::create([
                    'session_id' => $session->id,
                    'sku'        => $sku,
                    'status'     => 'pending',
                ]);
            }

            $session->update(['total_items' => $skus->count()]);
        }

        GenerateAiContentJob::dispatch($session->id)->onQueue('bulkupload');

        return redirect()->route('ai-content.show', $session)
            ->with('success', 'AI content generation started.');
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

        $items = $aiContentSession->items()->orderBy('id')->get();

        return response()->json($items);
    }

    public function push(Request $request, AiContentSession $aiContentSession)
    {
        abort_if($aiContentSession->user_id !== auth()->id(), 403);

        $store = Store::find($aiContentSession->store_id);
        if (!$store) {
            return back()->with('warning', 'No store associated with this session.');
        }

        $shopify   = new ShopifyService($store);
        $confirmed = $request->input('confirmed', []);
        $pushed    = 0;
        $failed    = 0;

        foreach ($confirmed as $itemId) {
            $item = AiContentItem::where('id', $itemId)
                ->where('session_id', $aiContentSession->id)
                ->first();

            if (!$item || !$item->shopify_product_id) continue;

            // Save user-edited content
            $item->update([
                'ai_description'      => $request->input("description.{$itemId}", $item->ai_description),
                'ai_meta_title'       => $request->input("meta_title.{$itemId}", $item->ai_meta_title),
                'ai_meta_description' => $request->input("meta_description.{$itemId}", $item->ai_meta_description),
                'is_confirmed'        => true,
            ]);

            try {
                $shopify->updateProductContent(
                    $item->shopify_product_id,
                    $item->ai_description,
                    $item->ai_meta_title,
                    $item->ai_meta_description,
                );
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
