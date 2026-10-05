<?php

namespace App\Http\Controllers;

use App\Jobs\SyncProductSalesJob;
use App\Models\Store;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Which products sell, which barely do, and which sit in stock selling nothing —
 * per website or across every website someone can see.
 *
 * Reads only the tables the nightly sync fills, never Shopify, so it answers in
 * a second for any range. Across websites the list is ranked by units, not
 * money: the stores bill in different currencies, and a sum across them would
 * not be one number.
 */
class ProductPerformanceController extends Controller
{
    public const RANGES = [7, 30, 60, 90, 180, 365];

    private const TABS = ['best', 'low', 'none'];

    private const PER_PAGE = 50;

    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $stores  = $filters['stores'];

        $rows = $this->query($filters)->paginate(self::PER_PAGE)->withQueryString();

        // "Last sold" reaches past the selected range, so a product with no
        // sales this month still says whether it sold in the spring or never.
        $lastSold = $filters['tab'] === 'best' ? [] : $this->lastSold(collect($rows->items()));

        return view('product-performance.index', [
            'filters'   => $filters,
            'rows'      => $rows,
            'lastSold'  => $lastSold,
            'summary'   => $this->summary($filters),
            'brands'    => $this->brands($stores),
            'choices'   => $filters['choices'],
            'storeById' => $filters['choices']->keyBy('id'),
            'ranges'    => self::RANGES,
        ]);
    }

    public function download(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $storeById = $filters['choices']->keyBy('id');
        $all = $this->query($filters)->get();
        $lastSold = $filters['tab'] === 'best' ? [] : $this->lastSold($all);

        $name = sprintf('product-performance-%s-%dd-%s.csv', $filters['tab'], $filters['days'], now()->format('Y-m-d'));

        return response()->streamDownload(function () use ($all, $storeById, $lastSold) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Website', 'Product', 'SKU', 'Brand', 'Type', 'Units sold', 'Revenue', 'Currency', 'Stock', 'Last sold', 'Shopify product id']);

            foreach ($all as $row) {
                $store = $storeById[$row->store_id] ?? null;

                fputcsv($out, [
                    $store?->name,
                    $row->title ?? 'Removed from Shopify',
                    $row->sku,
                    $row->vendor,
                    $row->product_type,
                    (int) $row->units,
                    number_format((float) $row->revenue, 2, '.', ''),
                    $store?->sales_currency,
                    $row->total_inventory,
                    substr((string) ($row->last_sold ?? ($lastSold["{$row->store_id}|{$row->product_id}"] ?? '')), 0, 10),
                    $row->product_id,
                ]);
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
    }

    /** Super admin only: starts tonight's sync now, for a store just connected. */
    public function refresh()
    {
        SyncProductSalesJob::dispatch();

        return back()->with('success', 'Sync started for every connected website. A first sync reads a year of orders and can take a while; reload this page later.');
    }

    private function filters(Request $request): array
    {
        $user = $request->user();
        abort_unless($user->hasFeature('product_performance'), 403);

        $choices = Store::accessibleBy($user)
            ->whereNotNull('shopify_domain')
            ->orderBy('name')
            ->get();

        $storeParam = (string) $request->get('store', 'all');
        $stores = $storeParam === 'all'
            ? $choices
            : $choices->where('id', (int) $storeParam)->values();

        if ($stores->isEmpty()) {
            $storeParam = 'all';
            $stores = $choices;
        }

        $days = (int) $request->get('days', 30);
        $days = in_array($days, self::RANGES, true) ? $days : 30;

        $tab = in_array($request->get('tab'), self::TABS, true) ? $request->get('tab') : 'best';

        return [
            'choices' => $choices,
            'stores'  => $stores,
            'store'   => $storeParam,
            'days'    => $days,
            'from'    => now()->subDays($days - 1)->toDateString(),
            'tab'     => $tab,
            'brand'   => trim((string) $request->get('brand', '')),
            'search'  => trim((string) $request->get('q', '')),
            'max'     => max(1, min(50, (int) $request->get('max', 2))),
        ];
    }

    /** Units and revenue per product per store inside the range. */
    private function sales(array $filters): Builder
    {
        return DB::table('product_sales_daily')
            ->select('store_id', 'product_id')
            ->selectRaw('SUM(units) as units, SUM(revenue) as revenue, MAX(date) as last_sold')
            ->whereIn('store_id', $filters['stores']->pluck('id'))
            ->where('date', '>=', $filters['from'])
            ->groupBy('store_id', 'product_id');
    }

    private function query(array $filters): Builder
    {
        $columns = ['p.title', 'p.handle', 'p.sku', 'p.vendor', 'p.product_type', 'p.image_url', 'p.total_inventory', 'p.status'];

        if ($filters['tab'] === 'best') {
            $query = DB::query()
                ->fromSub($this->sales($filters), 's')
                ->leftJoin('store_products as p', fn ($j) => $j
                    ->on('p.store_id', '=', 's.store_id')
                    ->on('p.product_id', '=', 's.product_id'))
                ->select(['s.store_id', 's.product_id', 's.units', 's.revenue', 's.last_sold', ...$columns])
                ->orderByDesc('s.units')
                ->orderByDesc('s.revenue')
                ->orderBy('s.product_id');
        } else {
            // Only products that could have sold: live, in stock, and already
            // on the site when the range began. A product added last week is
            // not a slow seller for having no sales last month.
            $query = DB::table('store_products as p')
                ->leftJoinSub($this->sales($filters), 's', fn ($j) => $j
                    ->on('s.store_id', '=', 'p.store_id')
                    ->on('s.product_id', '=', 'p.product_id'))
                ->select(['p.store_id', 'p.product_id', DB::raw('COALESCE(s.units, 0) as units'), DB::raw('COALESCE(s.revenue, 0) as revenue'), DB::raw('NULL as last_sold'), ...$columns])
                ->whereIn('p.store_id', $filters['stores']->whereNotNull('sales_synced_at')->pluck('id'))
                ->where('p.status', 'active')
                ->where('p.total_inventory', '>', 0)
                ->where(fn ($q) => $q->whereNull('p.shopify_created_at')->orWhere('p.shopify_created_at', '<', $filters['from']))
                ->orderByDesc('p.total_inventory')
                ->orderBy('p.title');

            $filters['tab'] === 'low'
                ? $query->whereBetween('s.units', [1, $filters['max']])
                : $query->whereNull('s.units');
        }

        if ($filters['brand'] !== '') {
            $query->where('p.vendor', $filters['brand']);
        }

        if ($filters['search'] !== '') {
            $term = '%' . str_replace(['%', '_'], ['\%', '\_'], $filters['search']) . '%';
            $query->where(fn ($q) => $q->where('p.title', 'like', $term)->orWhere('p.sku', 'like', $term));
        }

        return $query;
    }

    /** @return array<string, string> "storeId|productId" => last day sold, any time on record */
    private function lastSold(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        return DB::table('product_sales_daily')
            ->selectRaw('store_id, product_id, MAX(date) as last_sold')
            ->whereIn('store_id', $rows->pluck('store_id')->unique())
            ->whereIn('product_id', $rows->pluck('product_id')->unique())
            ->groupBy('store_id', 'product_id')
            ->get()
            ->mapWithKeys(fn ($r) => ["{$r->store_id}|{$r->product_id}" => (string) $r->last_sold])
            ->all();
    }

    private function summary(array $filters): array
    {
        $sold = DB::query()
            ->fromSub($this->sales($filters), 's')
            ->leftJoin('store_products as p', fn ($j) => $j
                ->on('p.store_id', '=', 's.store_id')
                ->on('p.product_id', '=', 's.product_id'))
            ->when($filters['brand'] !== '', fn ($q) => $q->where('p.vendor', $filters['brand']))
            ->selectRaw('COUNT(*) as products, COALESCE(SUM(s.units), 0) as units, COALESCE(SUM(s.revenue), 0) as revenue')
            ->first();

        $idle = $this->query(['tab' => 'none', 'search' => ''] + $filters)
            ->reorder()
            ->select(DB::raw('COUNT(*) as products, COALESCE(SUM(p.total_inventory), 0) as stock'))
            ->first();

        $single = $filters['stores']->count() === 1 ? $filters['stores']->first() : null;

        // A range reaching back past where a store's data starts would report
        // products as unsold that simply were not read yet.
        $partial = $filters['stores']->filter(fn (Store $s) => $s->sales_covered_from && $s->sales_covered_from->toDateString() > $filters['from']);

        return [
            'products' => (int) $sold->products,
            'units'    => (int) $sold->units,
            'revenue'  => $single ? (float) $sold->revenue : null,
            'currency' => $single?->sales_currency,
            'idle'     => (int) $idle->products,
            'idleStock'=> (int) $idle->stock,
            'never'    => $filters['stores']->whereNull('sales_synced_at')->values(),
            'failing'  => $filters['stores']->whereNotNull('sales_sync_error')->values(),
            'partial'  => $partial->values(),
            'syncedAt' => $filters['stores']->max('sales_synced_at'),
        ];
    }

    private function brands(Collection $stores): array
    {
        return DB::table('store_products')
            ->whereIn('store_id', $stores->pluck('id'))
            ->whereNotNull('vendor')
            ->distinct()
            ->orderBy('vendor')
            ->pluck('vendor')
            ->all();
    }
}
