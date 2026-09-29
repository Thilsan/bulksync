<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateAiContentJob;
use App\Jobs\RunSeoAuditJob;
use App\Models\AiContentSession;
use App\Models\SeoAuditItem;
use App\Models\SeoAuditSession;
use App\Models\Store;
use Illuminate\Http\Request;

class SeoAuditController extends Controller
{
    /**
     * Products one "Fix" press may send for generation.
     *
     * Not a technical limit — the generator chunks itself and would happily
     * take more. It is a spending limit: generation is billed per product, and
     * a filter left on "All" over a large catalogue is a four-figure click.
     */
    public const MAX_FIX_BATCH = 500;

    /**
     * Rough US dollars per product, for the confirmation dialog only. Observed
     * average — the real figure follows image count, since most of the spend is
     * one alt-text call per photo, so a product with ten pictures costs several
     * times one with two.
     */
    public const COST_PER_PRODUCT_USD = 0.014;

    public function index()
    {
        $sessions = SeoAuditSession::where('user_id', auth()->id())
            ->with('store')
            ->latest()
            ->paginate(20);

        return view('seo-audit.index', compact('sessions'));
    }

    public function start()
    {
        $store = Store::getActive();

        $session = SeoAuditSession::create([
            'user_id'  => auth()->id(),
            'store_id' => $store?->id,
            'status'   => 'pending',
        ]);

        RunSeoAuditJob::dispatch($session->id)->onQueue('bulkupload');

        return redirect()->route('seo-audit.show', $session)
            ->with('success', 'SEO audit started. A large catalogue can take several minutes.');
    }

    public function show(SeoAuditSession $seoAuditSession)
    {
        abort_if($seoAuditSession->user_id !== auth()->id(), 403);

        return view('seo-audit.show', ['session' => $seoAuditSession]);
    }

    public function status(SeoAuditSession $seoAuditSession)
    {
        abort_if($seoAuditSession->user_id !== auth()->id(), 403);

        return response()->json([
            'status'               => $seoAuditSession->status,
            'total_products'       => $seoAuditSession->total_products,
            'scanned_products'     => $seoAuditSession->scanned_products,
            'progress'             => $seoAuditSession->progressPercent(),
            'clean_products'       => $seoAuditSession->clean_products,
            'products_with_issues' => $seoAuditSession->products_with_issues,
            'total_issues'         => $seoAuditSession->total_issues,
            'average_score'        => $seoAuditSession->average_score,
            'issue_summary'        => $seoAuditSession->issueSummary(),
            'error_message'        => $seoAuditSession->error_message,
        ]);
    }

    public function items(SeoAuditSession $seoAuditSession, Request $request)
    {
        abort_if($seoAuditSession->user_id !== auth()->id(), 403);

        $items = $this->filtered($seoAuditSession, $request)
            ->paginate(100)
            ->withQueryString();

        return response()->json([
            'items' => collect($items->items())->map(fn (SeoAuditItem $item) => [
                'product_id'       => $item->product_id,
                'product_title'    => $item->product_title,
                'handle'           => $item->handle,
                'sku'              => $item->sku,
                'meta_title'       => $item->meta_title,
                'meta_description' => $item->meta_description,
                'title_length'     => $item->meta_title_length,
                'desc_length'      => $item->meta_description_length,
                'image_count'      => $item->image_count,
                'missing_alt'      => $item->images_missing_alt,
                'score'            => $item->score,
                'issues'           => $item->issueLabels(),
            ])->all(),
            'total'        => $items->total(),
            'current_page' => $items->currentPage(),
            'last_page'    => $items->lastPage(),
        ]);
    }

    public function download(SeoAuditSession $seoAuditSession, Request $request)
    {
        abort_if($seoAuditSession->user_id !== auth()->id(), 403);

        $query    = $this->filtered($seoAuditSession, $request);
        $filter   = $request->get('filter', 'issues');
        $filename = "seo-audit-{$seoAuditSession->id}-{$filter}.csv";

        // Streamed and chunked: a full catalogue export is far too large to
        // collect into memory first.
        $callback = function () use ($query) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'SKU', 'Product Title', 'Handle', 'Product ID', 'Score',
                'Meta Title', 'Meta Title Length',
                'Meta Description', 'Meta Description Length',
                'Description Length', 'Images', 'Images Missing Alt', 'Tags',
                'Issues',
            ]);

            $query->chunk(500, function ($items) use ($handle) {
                foreach ($items as $item) {
                    fputcsv($handle, [
                        $item->sku,
                        $item->product_title,
                        $item->handle,
                        $item->product_id,
                        $item->score,
                        $item->meta_title,
                        $item->meta_title_length,
                        $item->meta_description,
                        $item->meta_description_length,
                        $item->description_length,
                        $item->image_count,
                        $item->images_missing_alt,
                        $item->tag_count,
                        implode(' | ', $item->issueLabels()),
                    ]);
                }
            });

            fclose($handle);
        };

        return response()->stream($callback, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Hand the rows currently on screen to the AI Content Generator.
     *
     * Deliberately stops at generation rather than pushing to Shopify: the
     * review screen is the only thing standing between one bad meta
     * description and two hundred products carrying it. The audit says what is
     * wrong; a person still says what goes live.
     */
    public function fix(SeoAuditSession $seoAuditSession, Request $request)
    {
        abort_if($seoAuditSession->user_id !== auth()->id(), 403);

        if ($seoAuditSession->status !== 'completed') {
            return back()->with('warning', 'Wait for the audit to finish before fixing anything.');
        }

        $rows = $this->filtered($seoAuditSession, $request)->get(['sku', 'product_title']);

        // A product whose first variant carries no SKU cannot be looked up
        // again — the generator only knows products by SKU. Counted and said
        // out loud rather than silently dropped, so the numbers add up for
        // whoever reads the result.
        $skus    = $rows->pluck('sku')->map(fn ($sku) => strtoupper(trim((string) $sku)))->filter()->unique()->values();
        $skipped = $rows->count() - $skus->count();

        if ($skus->isEmpty()) {
            return back()->with('warning', 'Nothing in this view can be fixed automatically — none of these products have a SKU.');
        }

        if ($skus->count() > self::MAX_FIX_BATCH) {
            // Generation is billed per product, so one mis-aimed click on a
            // large catalogue is real money. Narrowing the filter is cheap;
            // an accidental five-thousand-product run is not.
            return back()->with('warning', sprintf(
                'That is %s products, over the %s per run limit. Filter to one issue, or search, and fix in batches.',
                number_format($skus->count()),
                number_format(self::MAX_FIX_BATCH),
            ));
        }

        $session = AiContentSession::create([
            'user_id'     => auth()->id(),
            'store_id'    => $seoAuditSession->store_id,
            'input_type'  => 'sku_list',
            'sku_raw'     => $skus->implode("\n"),
            'skus_json'   => json_encode($skus->all()),
            'status'      => 'pending',
            'total_items' => $skus->count(),
        ]);

        GenerateAiContentJob::dispatch($session->id)->onQueue('bulkupload');

        $message = sprintf(
            'Generating content for %s product(s) from the SEO audit. Review it here, then push to Shopify.',
            number_format($skus->count()),
        );

        if ($skipped > 0) {
            $message .= sprintf(' %s skipped — no SKU to look them up by.', number_format($skipped));
        }

        return redirect()->route('ai-content.show', $session)->with('success', $message);
    }

    public function destroy(SeoAuditSession $seoAuditSession)
    {
        abort_if($seoAuditSession->user_id !== auth()->id(), 403);

        $seoAuditSession->delete();

        return redirect()->route('seo-audit.index')->with('success', 'Audit deleted.');
    }

    /**
     * Shared by the table and the CSV so an export always contains exactly the
     * rows on screen. Worst first: the lowest scores are the work queue.
     */
    private function filtered(SeoAuditSession $session, Request $request)
    {
        $filter = (string) $request->get('filter', 'issues');
        $search = trim((string) $request->get('search', ''));

        $query = $session->items();

        if ($filter === 'issues') {
            $query->where('issue_count', '>', 0);
        } elseif ($filter === 'clean') {
            $query->where('issue_count', 0);
        } elseif (isset(SeoAuditItem::ISSUES[$filter])) {
            // JSON containment rather than LIKE: a LIKE on the encoded array
            // would match 'missing_meta_title' inside any longer code.
            $query->whereJsonContains('issues', $filter);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('sku', 'like', "%{$search}%")
                  ->orWhere('product_title', 'like', "%{$search}%")
                  ->orWhere('handle', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('score')->orderBy('product_title');
    }
}
