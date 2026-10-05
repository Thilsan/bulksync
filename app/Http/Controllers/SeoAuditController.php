<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateAiContentJob;
use App\Jobs\RunSeoAuditJob;
use App\Models\AiContentSession;
use App\Models\SeoAuditItem;
use App\Models\SeoAuditSession;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SeoAuditController extends Controller
{
    /**
     * Products one "Fix" press may send for generation.
     *
     * Not a technical limit — the generator chunks itself and would happily
     * take more. It is a spending limit: generation is billed per product, and
     * a filter left on "All" over a large catalogue is a four-figure click.
     *
     * A view holding more than this is capped rather than refused, taking the
     * worst-scoring end of it. Refusing outright left anyone with a large
     * catalogue facing a button that could only ever say no.
     */
    public const MAX_FIX_BATCH = 500;

    /**
     * Rough US dollars per product, for the confirmation dialog only. Observed
     * average — the real figure follows image count, since most of the spend is
     * one alt-text call per photo, so a product with ten pictures costs several
     * times one with two.
     */
    public const COST_PER_PRODUCT_USD = 0.014;

    /** Duplicate groups shown at once, and pages listed inside each. */
    public const MAX_CLUSTERS        = 50;
    public const MAX_CLUSTER_MEMBERS = 12;

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
            'scanned_collections'  => $seoAuditSession->scanned_collections,
            // The denominator for "clean" and "with issues", which count every
            // page graded rather than the products among them.
            'scanned_total'        => $seoAuditSession->scannedTotal(),
            'not_live_pages'       => $seoAuditSession->not_live_pages,
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
                'resource_type'    => $item->resource_type,
                'status'           => $item->status,
                'is_live'          => $item->isLive(),
                'path'             => $item->path(),
                'product_title'    => $item->product_title,
                'handle'           => $item->handle,
                'sku'              => $item->sku,
                'meta_title'       => $item->meta_title,
                'meta_description' => $item->meta_description,
                // What the storefront renders today when nothing was set.
                'fallback_title'   => $item->fallbackMetaTitle(),
                'fallback_desc'    => $item->fallbackMetaDescription(),
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

    /**
     * Products and collections grouped by the meta title or description they
     * share.
     *
     * The table can already filter to "duplicate meta title", but a flat list
     * shows a row without showing what it clashes with — and a duplicate is
     * only fixable once you can see the other pages competing with it. This
     * returns the clusters themselves.
     */
    public function duplicates(SeoAuditSession $seoAuditSession, Request $request)
    {
        abort_if($seoAuditSession->user_id !== auth()->id(), 403);

        $field  = $request->get('field') === 'meta_description' ? 'meta_description' : 'meta_title';
        $column = $field;

        $values = SeoAuditItem::where('seo_audit_session_id', $seoAuditSession->id)
            ->where($column, '<>', '')
            ->whereNotNull($column)
            ->select(DB::raw("LOWER(TRIM({$column})) as normalised"), DB::raw('COUNT(*) as pages'))
            ->groupBy('normalised')
            ->havingRaw('COUNT(*) > 1')
            ->orderByDesc('pages')
            ->limit(self::MAX_CLUSTERS)
            // get()->pluck(), not the builder's pluck(): the builder's swaps
            // the select list for the named column, dropping the raw aliases.
            ->get();

        $clusters = $values->map(function ($group) use ($seoAuditSession, $column) {
            $members = SeoAuditItem::where('seo_audit_session_id', $seoAuditSession->id)
                ->whereRaw("LOWER(TRIM({$column})) = ?", [$group->normalised])
                ->orderBy('product_title')
                ->limit(self::MAX_CLUSTER_MEMBERS + 1)
                ->get(['resource_type', 'product_id', 'product_title', 'handle', 'sku', $column]);

            return [
                'value'   => $members->first()->{$column},
                'pages'   => (int) $group->pages,
                // The cap is a rendering limit, not a counting one: the group
                // still reports its true size above.
                'shown'   => $members->take(self::MAX_CLUSTER_MEMBERS)->map(fn (SeoAuditItem $item) => [
                    'type'  => $item->resource_type,
                    'title' => $item->product_title,
                    'sku'   => $item->sku,
                    'path'  => $item->path(),
                ])->all(),
            ];
        })->all();

        return response()->json([
            'field'    => $field,
            'clusters' => $clusters,
        ]);
    }

    /**
     * Products that look like one product split into several.
     *
     * The duplicate-meta-title panel reports a symptom; this reports the cause.
     * Two Shopify products with the same title, the same vendor and adjacent
     * SKUs are almost always one product in two colours or sizes — and while
     * they stay split they divide their own ranking between two URLs, and no
     * amount of rewriting can stop their generated titles matching.
     *
     * Built from the audit rows rather than a fresh scan: the catalogue was
     * already read once, and reading it again to group titles would be a second
     * pass over the same data.
     */
    public function mergeCandidates(SeoAuditSession $seoAuditSession)
    {
        abort_if($seoAuditSession->user_id !== auth()->id(), 403);

        $groups = SeoAuditItem::where('seo_audit_session_id', $seoAuditSession->id)
            ->where('resource_type', SeoAuditItem::TYPE_PRODUCT)
            ->live()
            ->where('product_title', '<>', '')
            ->select(DB::raw('LOWER(TRIM(product_title)) as normalised'), DB::raw('COUNT(*) as pages'))
            ->groupBy('normalised')
            ->havingRaw('COUNT(*) > 1')
            ->orderByDesc('pages')
            ->limit(self::MAX_CLUSTERS)
            ->get();

        $candidates = $groups->map(function ($group) use ($seoAuditSession) {
            $members = SeoAuditItem::where('seo_audit_session_id', $seoAuditSession->id)
                ->whereRaw('LOWER(TRIM(product_title)) = ?', [$group->normalised])
                ->orderBy('sku')
                ->limit(self::MAX_CLUSTER_MEMBERS)
                ->get(['product_title', 'sku', 'handle', 'meta_title']);

            $skus = $members->pluck('sku')->filter()->values();

            return [
                'title'    => $members->first()->product_title,
                'pages'    => (int) $group->pages,
                'skus'     => $skus->all(),
                // Adjacent SKUs are the strongest signal that these are the
                // same item catalogued twice rather than two things that happen
                // to share a name.
                'adjacent' => $this->skusLookAdjacent($skus->all()),
                'members'  => $members->map(fn (SeoAuditItem $item) => [
                    'sku'        => $item->sku,
                    'path'       => $item->path(),
                    'meta_title' => $item->meta_title,
                ])->all(),
            ];
        })->all();

        return response()->json(['candidates' => $candidates]);
    }

    /**
     * Whether a group's SKUs are consecutive once their shared prefix is taken
     * off — "SFR207ACC01095" and "SFR207ACC01096".
     *
     * Deliberately conservative: it answers no unless every SKU shares a prefix
     * and the trailing numbers form a run, because a false "these are the same
     * product" invites a merge that would lose a real product.
     */
    private function skusLookAdjacent(array $skus): bool
    {
        if (count($skus) < 2) {
            return false;
        }

        $numbers = [];

        foreach ($skus as $sku) {
            if (!preg_match('/^(.*?)(\d+)$/', (string) $sku, $matches)) {
                return false;
            }

            $prefixes[] = $matches[1];
            $numbers[]  = (int) $matches[2];
        }

        if (count(array_unique($prefixes)) !== 1) {
            return false;
        }

        sort($numbers);

        return ($numbers[count($numbers) - 1] - $numbers[0]) === (count($numbers) - 1);
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
                'Type', 'Status', 'SKU', 'Product Title', 'Handle', 'Product ID', 'Score',
                'Meta Title', 'Meta Title Length',
                'Meta Description', 'Meta Description Length',
                'Description Length', 'Images', 'Images Missing Alt', 'Tags',
                'Issues',
            ]);

            $query->chunk(500, function ($items) use ($handle) {
                foreach ($items as $item) {
                    fputcsv($handle, [
                        $item->resource_type,
                        $item->status ?? 'active',
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

        // Products only. A collection has no SKU and the generator writes from
        // product photographs, so sweeping collections in would silently send
        // nothing and report a count nobody could account for.
        $candidates = $this->filtered($seoAuditSession, $request)
            ->where('resource_type', SeoAuditItem::TYPE_PRODUCT);

        // Rows ticked by hand replace the filter: the person has said exactly
        // which products they mean, so the view's filters must not quietly drop
        // any of them. Still scoped to this session and still subject to the
        // cap, because ids come from the browser.
        $selected = collect(explode(',', (string) $request->input('ids')))
            ->map(fn ($id) => trim($id))
            ->filter()
            ->unique()
            ->values();

        if ($selected->isNotEmpty()) {
            $candidates = $seoAuditSession->items()
                ->where('resource_type', SeoAuditItem::TYPE_PRODUCT)
                ->whereIn('product_id', $selected->all())
                ->orderBy('score')->orderBy('product_title');
        }

        $available = (clone $candidates)->count();

        // Capped rather than refused. The view is ordered worst-score-first, so
        // the cap takes the pages that most need the work — and a button that
        // can only ever say "no" on a catalogue this size is no use to anyone.
        $rows = $candidates->limit(self::MAX_FIX_BATCH)->get(['sku', 'product_title']);

        // A product whose first variant carries no SKU cannot be looked up
        // again — the generator only knows products by SKU. Counted and said
        // out loud rather than silently dropped, so the numbers add up for
        // whoever reads the result.
        $skus    = $rows->pluck('sku')->map(fn ($sku) => strtoupper(trim((string) $sku)))->filter()->unique()->values();
        $skipped = $rows->count() - $skus->count();

        if ($skus->isEmpty()) {
            return back()->with('warning', 'Nothing in this view can be fixed automatically — none of these products have a SKU.');
        }

        $session = AiContentSession::create([
            'user_id'     => auth()->id(),
            'store_id'    => $seoAuditSession->store_id,
            'input_type'  => 'sku_list',
            'sku_raw'     => $skus->implode("\n"),
            'skus_json'   => json_encode($skus->all()),
            'keywords'    => $request->input('keywords') ?: null,
            'status'      => 'pending',
            'total_items' => $skus->count(),
        ]);

        GenerateAiContentJob::dispatch($session->id)->onQueue('bulkupload');

        $message = sprintf(
            'Generating content for %s product(s) from the SEO audit. Review it here, then push to Shopify.',
            number_format($skus->count()),
        );

        $remaining = $available - $rows->count();

        if ($remaining > 0) {
            // Said plainly, because the danger of a cap is someone believing
            // the whole list went and never coming back for the rest.
            $message .= sprintf(
                ' That is the %s worst-scoring of %s — run Fix again for the remaining %s.',
                number_format($rows->count()),
                number_format($available),
                number_format($remaining),
            );
        }

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
        $type   = (string) $request->get('type', 'all');
        // Live by default. A draft product's missing meta title is not a
        // problem anyone can see, and having it sit at the top of the fix
        // queue would spend money on a page nobody can visit.
        $status = (string) $request->get('status', 'live');

        $query = $session->items();

        if (in_array($type, [SeoAuditItem::TYPE_PRODUCT, SeoAuditItem::TYPE_COLLECTION], true)) {
            $query->where('resource_type', $type);
        }

        if ($status === 'live') {
            $query->live();
        } elseif ($status === 'not_live') {
            $query->notLive();
        }

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
