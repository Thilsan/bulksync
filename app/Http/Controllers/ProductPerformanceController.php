<?php

namespace App\Http\Controllers;

use App\Jobs\SyncProductSalesJob;
use App\Models\Store;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
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

    private const DIVISIONS_PER_PAGE = 100;

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
            'division'=> strtoupper(trim((string) $request->get('division', ''))),
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

        if (($filters['division'] ?? '') !== '') {
            $filters['division'] === self::NO_DIVISION
                ? $query->whereNull('p.division')
                : $query->where('p.division', $filters['division']);
        }

        if ($filters['search'] !== '') {
            $term = '%' . str_replace(['%', '_'], ['\%', '\_'], $filters['search']) . '%';
            $query->where(fn ($q) => $q->where('p.title', 'like', $term)->orWhere('p.sku', 'like', $term));
        }

        return $query;
    }

    /**
     * Sales grouped by division — the first three letters and three digits of
     * the SKU — for each website. One row per website and division, so the
     * same code on two websites is two rows, never added together.
     */
    public function divisions(Request $request)
    {
        $filters = $this->filters($request);
        $all     = $this->divisionRows($filters);
        $page    = LengthAwarePaginator::resolveCurrentPage();

        $rows = new LengthAwarePaginator(
            $all->forPage($page, self::DIVISIONS_PER_PAGE)->values(),
            $all->count(),
            self::DIVISIONS_PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return view('product-performance.divisions', [
            'filters'   => $filters,
            'rows'      => $rows,
            'topUnits'  => (int) $all->max('units'),
            'totals'    => ['divisions' => $all->where('units', '>', 0)->count(), 'units' => (int) $all->sum('units')],
            'summary'   => $this->summary($filters),
            'brands'    => $this->brands($filters['stores']),
            'choices'   => $filters['choices'],
            'storeById' => $filters['choices']->keyBy('id'),
            'ranges'    => self::RANGES,
        ]);
    }

    public function divisionsDownload(Request $request): StreamedResponse
    {
        $filters   = $this->filters($request);
        $rows      = $this->divisionRows($filters);
        $storeById = $filters['choices']->keyBy('id');
        $name      = sprintf('division-performance-%dd-%s.csv', $filters['days'], now()->format('Y-m-d'));

        return response()->streamDownload(function () use ($rows, $storeById) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Website', 'Division', 'Live products', 'Products sold', 'Units sold', 'Revenue', 'Currency', 'Stock']);

            foreach ($rows as $row) {
                $store = $storeById[$row['store_id']] ?? null;

                fputcsv($out, [
                    $store?->name,
                    $row['division'],
                    $row['products'],
                    $row['sold'],
                    $row['units'],
                    number_format($row['revenue'], 2, '.', ''),
                    $store?->sales_currency,
                    $row['stock'],
                ]);
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
    }

    /** What a SKU without the three-letters-three-digits start is filed under. */
    public const NO_DIVISION = 'NONE';

    /** @return Collection<int, array{store_id:int, division:string, products:int, stock:int, sold:int, units:int, revenue:float}> */
    private function divisionRows(array $filters): Collection
    {
        $brand = fn ($q) => $filters['brand'] !== '' ? $q->where('p.vendor', $filters['brand']) : $q;

        // What sold, from the sales side, so a product since deleted still
        // counts — under no division, since its SKU is no longer known.
        $sold = DB::query()
            ->fromSub($this->sales($filters), 's')
            ->leftJoin('store_products as p', fn ($j) => $j
                ->on('p.store_id', '=', 's.store_id')
                ->on('p.product_id', '=', 's.product_id'))
            ->tap($brand)
            ->groupBy('s.store_id', 'p.division')
            ->select('s.store_id', 'p.division')
            ->selectRaw('COUNT(*) as sold, SUM(s.units) as units, SUM(s.revenue) as revenue')
            ->get();

        // What is on the shelf, so a division that sold nothing still shows.
        $catalogue = DB::table('store_products as p')
            ->whereIn('p.store_id', $filters['stores']->whereNotNull('sales_synced_at')->pluck('id'))
            ->where('p.status', 'active')
            ->tap($brand)
            ->groupBy('p.store_id', 'p.division')
            ->select('p.store_id', 'p.division')
            ->selectRaw('COUNT(*) as products, SUM(CASE WHEN p.total_inventory > 0 THEN p.total_inventory ELSE 0 END) as stock')
            ->get();

        // Keyed by website and division, so a code on two websites stays two rows.
        $rows = [];
        $slot = function ($r) use (&$rows): string {
            $key = $r->store_id . '|' . ($r->division ?? '');

            $rows[$key] ??= [
                'store_id' => (int) $r->store_id,
                'division' => $r->division ?? self::NO_DIVISION,
                'products' => 0, 'stock' => 0, 'sold' => 0, 'units' => 0, 'revenue' => 0.0,
            ];

            return $key;
        };

        foreach ($catalogue as $r) {
            $key = $slot($r);
            $rows[$key]['products'] = (int) $r->products;
            $rows[$key]['stock']    = (int) $r->stock;
        }

        foreach ($sold as $r) {
            $key = $slot($r);
            $rows[$key]['sold']    = (int) $r->sold;
            $rows[$key]['units']   = (int) $r->units;
            $rows[$key]['revenue'] = (float) $r->revenue;
        }

        $search = mb_strtoupper($filters['search']);

        return collect($rows)
            ->when($search !== '', fn ($c) => $c->filter(fn ($r) => str_contains($r['division'], $search)))
            ->sortBy([['units', 'desc'], ['stock', 'desc'], ['division', 'asc']])
            ->values();
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
