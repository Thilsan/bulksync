<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\SearchConsoleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Searches this store already ranks for, just off the front page.
 *
 * Every other screen in the SEO module answers "what is wrong with our pages".
 * This one answers "where is the traffic we have nearly earned" — which is a
 * better question, because Google has already decided these pages are relevant
 * and the only thing missing is a few places.
 */
class StrikingDistanceController extends Controller
{
    /**
     * How far back to read. Long enough for a slow-moving catalogue to show a
     * pattern, short enough that it reflects where pages sit now rather than
     * where they sat last season.
     */
    private const WINDOW_DAYS = 90;

    /** The answer changes slowly and the query is expensive; an hour is plenty. */
    private const CACHE_MINUTES = 60;

    public function index(Request $request)
    {
        $store = Store::getActive();

        if (!$store?->gsc_site_url) {
            return view('seo-audit.striking-distance', [
                'store'      => $store,
                'rows'       => [],
                'unavailable'=> 'No Search Console site is set for this website. Add one under Stores, and have the service account granted access on the property.',
                'window'     => self::WINDOW_DAYS,
                'search'     => '',
            ]);
        }

        $search = trim((string) $request->get('search', ''));

        try {
            $rows = Cache::remember(
                "striking_distance.{$store->id}",
                now()->addMinutes(self::CACHE_MINUTES),
                function () use ($store) {
                    $to   = SearchConsoleService::latestCompleteDay();
                    $from = $to->copy()->subDays(self::WINDOW_DAYS);

                    return $this->service()->strikingDistance((string) $store->gsc_site_url, $from, $to);
                },
            );

            $unavailable = null;
        } catch (\Throwable $e) {
            $rows        = [];
            $unavailable = $this->explain($e);
        }

        if ($search !== '') {
            $rows = array_values(array_filter(
                $rows,
                fn (array $row) => str_contains(mb_strtolower($row['query']), mb_strtolower($search))
                    || str_contains(mb_strtolower($row['page']), mb_strtolower($search)),
            ));
        }

        return view('seo-audit.striking-distance', [
            'store'       => $store,
            'rows'        => $rows,
            'unavailable' => $unavailable,
            'window'      => self::WINDOW_DAYS,
            'search'      => $search,
        ]);
    }

    /**
     * A 403 here means one specific thing often enough to be worth saying
     * outright, rather than showing somebody a raw Google error.
     */
    private function explain(\Throwable $e): string
    {
        if ($e->getCode() === 403 || str_contains($e->getMessage(), 'HTTP 403')) {
            return 'Search Console refused the request. The service account is usually not yet a user on '
                 . 'this property — add it there, or check the site is spelled exactly as Search Console shows it.';
        }

        return 'Search Console could not be reached: ' . \Illuminate\Support\Str::limit($e->getMessage(), 240);
    }

    /** Seam: overridden in tests so nothing reaches Google. */
    protected function service(): SearchConsoleService
    {
        return new SearchConsoleService();
    }
}
