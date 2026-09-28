<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Finds a product on a public website by the barcode printed on it, and pulls
 * that product's images off the page.
 *
 * This reads storefronts as a visitor does — no Admin API, no credentials — so
 * it works against any site somebody can paste a URL for, including ones this
 * workspace has no connection to. The cost of that is that it is guesswork:
 * every step below is a ladder of attempts, from the cheapest and most exact
 * (a Shopify storefront's own JSON) down to reading <img> tags out of HTML.
 *
 * Nothing here throws for a barcode that simply isn't on the site. A miss is a
 * miss, reported as one, because a list of two thousand barcodes will always
 * contain some the site has never heard of and that must not stop the run.
 */
class ProductImageScraper
{
    /** A storefront that is slow is common; one that is hanging is not. */
    private const TIMEOUT = 20;

    /** No single image is worth more than this. Nothing legitimate is bigger. */
    private const MAX_IMAGE_BYTES = 25 * 1024 * 1024;

    /** A barcode matching more pages than this is a search being loose, not a catalogue. */
    private const MAX_PRODUCTS_PER_BARCODE = 6;

    /** Above this many product links, a page is showing a listing rather than an empty result. */
    private const PRODUCTS_MEANING_A_LISTING = 5;

    /** The longest wait between barcodes this will honour before giving up on a site. */
    private const MAX_CRAWL_DELAY = 20.0;

    /** How much of a page is worth sifting for the product's own gallery. */
    private const MAX_PAGE_IMAGES = 400;

    /** Beyond this many per product we are collecting page furniture, not product shots. */
    private const MAX_IMAGES_PER_PRODUCT = 30;

    /**
     * Sites serve different markup to obvious robots, and some serve none at
     * all. Asking as a browser is the difference between a page and a 403.
     */
    private const USER_AGENT =
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0 Safari/537.36';

    /** Filenames that are furniture on every storefront ever built. */
    private const JUNK = [
        'logo', 'placeholder', 'sprite', 'icon', 'favicon', 'loader', 'spinner',
        'payment', 'visa', 'mastercard', 'paypal', 'badge', 'flag', 'avatar',
        'banner', 'no-image', 'noimage', 'blank', 'pixel', 'transparent',
    ];

    private function client(): PendingRequest
    {
        return Http::withHeaders([
                'User-Agent'      => self::USER_AGENT,
                'Accept-Language' => 'en-US,en;q=0.9',
            ])
            ->timeout(self::TIMEOUT)
            ->connectTimeout(10)
            ->withOptions(['allow_redirects' => ['max' => 5]]);
    }

    // ── The site itself ──────────────────────────────────────────────────────

    /**
     * "bluesalon.com", "https://bluesalon.com/collections/all?page=2" and
     * "HTTPS://BlueSalon.com/" are all the same website to a person pasting a
     * URL, so they are made the same thing here before anything is fetched.
     *
     * The path is kept, though, where it looks like a storefront prefix rather
     * than a page. Catalogues that run a storefront per country decide which
     * one you get from where the request comes from, and a server sits in a
     * different country from the person using it: Luisa Spagnoli sends this
     * server's bare /search to /en/us and drops the query, while the same
     * request from Doha is answered at /en/qa. Pasting the country in is the
     * only way to say which storefront is meant, so it has to survive.
     */
    public function normaliseSite(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') return null;

        if (!preg_match('~^https?://~i', $url)) {
            $url = 'https://' . $url;
        }

        $parts = parse_url($url);

        if (!$parts || empty($parts['host'])) return null;

        $scheme = strtolower($parts['scheme'] ?? 'https');

        if (!in_array($scheme, ['http', 'https'], true)) return null;

        $host = strtolower($parts['host']);

        // parse_url is forgiving enough to call "not a url at all" a host, and
        // a typo must come back as "that isn't a website" rather than as a run
        // that quietly finds nothing.
        if (!preg_match('~^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$~', $host)
            && $host !== 'localhost') {
            return null;
        }

        $base = $scheme . '://' . $host;

        if (!empty($parts['port'])) {
            $base .= ':' . $parts['port'];
        }

        return $base . $this->storefrontPrefix($parts['path'] ?? '');
    }

    /**
     * The part of a pasted path that is a storefront prefix, not a page.
     *
     * /en/qa is where the catalogue lives; /collections/all and
     * /products/silk-scarf are somewhere inside it, and searching under those
     * would 404. Short segments with no file extension are prefixes — which
     * covers the /en/qa, /en-gb, /uk/en and /qa shapes catalogues use — and
     * anything longer is treated as a page and dropped.
     */
    private function storefrontPrefix(string $path): string
    {
        $kept = [];

        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment === '' || str_contains($segment, '.') || mb_strlen($segment) > 5) break;

            $kept[] = strtolower($segment);

            if (count($kept) === 2) break; // /en/qa is as deep as these go
        }

        return $kept === [] ? '' : '/' . implode('/', $kept);
    }

    /** The scheme and host alone, for resolving links that start with a slash. */
    private function origin(string $base): string
    {
        $parts = parse_url($base);

        if (!$parts || empty($parts['host'])) return $base;

        return ($parts['scheme'] ?? 'https') . '://' . $parts['host']
            . (empty($parts['port']) ? '' : ':' . $parts['port']);
    }

    /**
     * Whether this site can be read at all, before a long list is started
     * against it. Returns a sentence explaining why not, or null when it can.
     *
     * Two ways a catalogue defeats this, both of which otherwise report every
     * barcode as missing — which reads as "your list is wrong" when the list is
     * fine:
     *
     *  - it decides the country from where the request comes from, and sends
     *    the search to another storefront without the query;
     *  - it builds its search results in the browser, so the page that arrives
     *    here is the same page whatever was searched for. Herrenausstatter is
     *    one: its /search returns 800kB of identical markup for a real article
     *    number and for nonsense, because the results arrive afterwards from
     *    an API the page calls itself.
     *
     * The second is checked by asking twice — once for something that cannot
     * exist, once for a real barcode — and comparing what came back. Only a
     * page that offers the same products both times is ignoring the question;
     * two empty answers just mean the barcode is not stocked, which is an
     * ordinary miss and not worth stopping for.
     */
    public function searchability(string $base, string $sampleBarcode): ?string
    {
        if ($landed = $this->searchRedirectsTo($base)) {
            return "This site sent the search to {$landed} instead of answering it. "
                . 'Catalogues that run a storefront per country decide which one to show from where the '
                . 'request comes from, and this server is not in the same country as you — so the address '
                . 'has to say which storefront is meant. Paste it in full, including the country, '
                . 'for example www.luisaspagnoli.com/en/qa, and run it again.';
        }

        try {
            $nonsense = $this->searchFingerprint($base, 'zzqq-no-such-thing-99');
            $real     = $this->searchFingerprint($base, $sampleBarcode);
        } catch (\Throwable $e) {
            return null; // Unreachable is a different problem, reported per barcode.
        }

        // Identical pages that are both full of products: the search ignored
        // the question and served the storefront. A pair of identical *empty*
        // pages is an honest "no results" for both, which is a miss and not a
        // reason to stop.
        if ($nonsense['links'] === $real['links'] && $nonsense['products'] >= self::PRODUCTS_MEANING_A_LISTING) {
            return 'This website builds its search results in the browser, so the page that reaches this '
                . 'server is the same one whatever is searched for — there is nothing in it to read. '
                . 'Its pictures cannot be collected this way. Ask the brand for the images directly, or '
                . 'use a site whose search results are in the page.';
        }

        return null;
    }

    /**
     * Enough of one search's answer to tell two answers apart.
     *
     * Every internal link on the page, sorted, plus a count of how many of
     * them look like a product. The link list is what changes when a search is
     * really performed; the count is what separates a storefront served
     * regardless of the question from an honest empty result.
     *
     * The loose product test here is not the one used to pick a link to
     * follow — that one insists on the barcode, because following the wrong
     * link puts the wrong pictures in the folder. This one only has to
     * recognise the shape of a listing, so it takes slug-then-id
     * (/alberto-hosen-469146) as well as the named /products/ forms.
     *
     * @return array{links: string[], products: int}
     */
    private function searchFingerprint(string $base, string $term): array
    {
        $response = $this->client()->get("{$base}/search", ['q' => $term]);

        if (!$response->successful()) return ['links' => [], 'products' => 0];

        preg_match_all('~href=["\'](/[^"\'?#]*)["\']~i', $response->body(), $matches);

        $links = array_values(array_unique($matches[1] ?? []));
        sort($links);

        $products = count(array_filter(
            $links,
            fn (string $href) => (bool) preg_match('~/(?:products?|p|item)/|-\d{4,}$|\d{4,}\.html?$~i', $href),
        ));

        return ['links' => $links, 'products' => $products];
    }

    /**
     * How long this site asks automated readers to wait between requests.
     *
     * robots.txt is where a site says how it wants to be read, and a stated
     * Crawl-delay is the clearest form of that. Herrenausstatter asks for
     * fifteen seconds. Ignoring it is how a workspace's server ends up blocked,
     * and it is the site's call to make rather than ours.
     *
     * Capped, because a delay of minutes would leave a run that never visibly
     * finishes — at which point the honest answer is that the site should not
     * be read in bulk at all.
     */
    public function crawlDelaySeconds(string $base): float
    {
        try {
            $response = $this->client()->timeout(10)->get($this->origin($base) . '/robots.txt');
        } catch (\Throwable $e) {
            return 0.0;
        }

        if (!$response->successful()) return 0.0;

        $applies = false;
        $delay   = 0.0;

        foreach (preg_split('~\R~', $response->body()) ?: [] as $line) {
            $line = trim(preg_replace('~#.*$~', '', $line) ?? '');

            if ($line === '') continue;

            if (preg_match('~^user-agent:\s*(.+)$~i', $line, $m)) {
                // Only the catch-all group: this reader does not claim a name
                // of its own, so a rule aimed at Googlebot is not aimed at it.
                $applies = trim($m[1]) === '*';

                continue;
            }

            if ($applies && preg_match('~^crawl-delay:\s*([\d.]+)~i', $line, $m)) {
                $delay = max($delay, (float) $m[1]);
            }
        }

        return min($delay, self::MAX_CRAWL_DELAY);
    }

    /**
     * Whether this site answers a search where it was asked to, or sends the
     * request somewhere else entirely.
     *
     * Returns the address it redirected to, or null when it stayed put. A
     * catalogue that bounces the search to another country's storefront will
     * report every barcode as missing, which reads as "your list is wrong"
     * when the truth is "you are asking the wrong storefront" — so the run
     * says so instead of grinding through a thousand certain misses.
     */
    public function searchRedirectsTo(string $base): ?string
    {
        try {
            $response = $this->client()
                ->withOptions(['allow_redirects' => ['max' => 5, 'track_redirects' => true]])
                ->get("{$base}/search", ['q' => 'barcode-probe']);
        } catch (\Throwable $e) {
            return null; // Unreachable is a different problem, reported elsewhere.
        }

        $history = array_filter(explode(', ', (string) $response->header('X-Guzzle-Redirect-History')));

        if ($history === []) return null;

        $landed = (string) end($history);

        parse_str((string) parse_url($landed, PHP_URL_QUERY), $query);

        // Whether the question survived the journey is the thing that matters,
        // not where it ended up. A site tidying the address — http to https, a
        // country prefix added, a trailing slash — carries the query along and
        // still answers it. One that decided to show its own homepage instead
        // drops the query, and every barcode after that is a certain miss.
        if (array_key_exists('q', $query)) return null;

        return $landed;
    }

    // ── One barcode ──────────────────────────────────────────────────────────

    /**
     * Everything known about one barcode on one site.
     *
     * A barcode can legitimately land on more than one product page — the same
     * style in two colourways is two pages on most catalogues — and both are
     * that barcode's pictures, so every confident match is read and the images
     * are merged into the one folder.
     *
     * @return array{url: ?string, title: ?string, images: string[], matches: int}
     */
    public function forBarcode(string $site, string $barcode): array
    {
        $products = $this->findProducts($site, $barcode);

        if ($products === []) {
            return ['url' => null, 'title' => null, 'images' => [], 'matches' => 0];
        }

        $images = [];
        $title  = $products[0]['title'];

        foreach ($products as $index => $product) {
            $page = $this->readProduct($product['url']);

            // A search results page rarely names the product; the product page
            // always does. Only the first one is asked, since that is the row
            // the results table shows.
            if ($index === 0 && !$title) {
                $title = $page['title'];
            }

            foreach ($page['images'] as $image) {
                $images[$image] = true;
            }
        }

        return [
            'url'     => $products[0]['url'],
            'title'   => $title,
            'images'  => array_keys($images),
            'matches' => count($products),
        ];
    }

    /**
     * Where the product with this barcode lives, if it lives anywhere.
     *
     * Shopify's own predictive search is tried first because it is a single
     * cheap JSON call and it searches the barcode field; the HTML search page
     * is the fallback for every other platform, and the direct URL guess is
     * there for the catalogues whose product handle simply is the barcode.
     *
     * @return list<array{url: string, title: ?string}>
     */
    public function findProducts(string $site, string $barcode): array
    {
        $barcode = trim($barcode);

        if ($barcode === '') return [];

        foreach ([
            fn () => $this->shopifySuggest($site, $barcode),
            fn () => $this->htmlSearch($site, $barcode),
            fn () => $this->directHandle($site, $barcode),
        ] as $attempt) {
            try {
                $hits = $attempt();
            } catch (\Throwable $e) {
                Log::debug("ProductImageScraper: lookup step failed for {$barcode}: " . $e->getMessage());
                continue;
            }

            if ($hits !== []) return $hits;
        }

        return [];
    }

    /** Shopify's predictive search endpoint — it matches on barcode. */
    private function shopifySuggest(string $site, string $barcode): array
    {
        $response = $this->client()->get("{$site}/search/suggest.json", [
            'q'                 => $barcode,
            'resources[type]'   => 'product',
            'resources[limit]'  => 5,
        ]);

        if (!$response->successful()) return [];

        $products = $response->json('resources.results.products');

        if (!is_array($products) || $products === []) return [];

        $hits = [];

        foreach ($products as $product) {
            $url = $product['url'] ?? null;

            if (!is_string($url) || $url === '') continue;

            $hits[] = [
                'url'   => $this->absolute($site, $url),
                'title' => is_string($product['title'] ?? null)
                    ? html_entity_decode(strip_tags($product['title']))
                    : null,
            ];

            if (count($hits) >= self::MAX_PRODUCTS_PER_BARCODE) break;
        }

        return $hits;
    }

    /**
     * The site's own search results page, read as HTML.
     *
     * Two query shapes rather than one: Shopify and most bespoke carts use
     * ?q=, WordPress and WooCommerce use ?s=. Whichever answers with links
     * that look like product pages wins. A storefront that keeps its catalogue
     * behind a locale prefix — /en/qa/search on Salesforce Commerce Cloud —
     * redirects the bare path there itself, which is why redirects are followed.
     */
    private function htmlSearch(string $site, string $barcode): array
    {
        $encoded = rawurlencode($barcode);

        $pages = [
            "{$site}/search?q={$encoded}",
            "{$site}/?s={$encoded}&post_type=product",
        ];

        foreach ($pages as $page) {
            $response = $this->client()->get($page);

            if (!$response->successful()) continue;

            $links = $this->productLinks($site, $response->body(), $barcode);

            if ($links !== []) {
                return array_map(fn (string $url) => ['url' => $url, 'title' => null], $links);
            }
        }

        return [];
    }

    /**
     * Catalogues where the barcode is the product handle — /products/1234567890
     * — answer directly, and a 404 costs one call to find out.
     */
    private function directHandle(string $site, string $barcode): array
    {
        $handle = strtolower(preg_replace('/[^A-Za-z0-9-]+/', '-', $barcode) ?? '');
        $handle = trim($handle, '-');

        if ($handle === '') return [];

        foreach (["{$site}/products/{$handle}", "{$site}/product/{$handle}"] as $candidate) {
            $response = $this->client()->get($candidate);

            if ($response->successful() && $this->looksLikeProductPage($response->body())) {
                return [['url' => $candidate, 'title' => null]];
            }
        }

        return [];
    }

    /**
     * The links on a results page that lead to a product, best first.
     *
     * No two platforms agree on what a product URL looks like. Shopify says
     * /products/handle, WooCommerce /product/slug, and Salesforce Commerce
     * Cloud says /en/qa/542849_127700000000_agello.html — which is
     * indistinguishable from the site's own /en/qa/privacy-policy.html by
     * shape alone. So the barcode itself is used as the evidence: a link
     * carrying the number that was searched for is that product, whatever the
     * URL happens to look like, and when any link carries it the ones that do
     * not are dropped entirely.
     *
     * @return list<string>
     */
    private function productLinks(string $site, string $html, string $barcode): array
    {
        $candidates = [];

        $add = function (?string $href) use (&$candidates, $site) {
            $absolute = $href === null ? null : $this->absolute($site, html_entity_decode($href));

            if ($absolute && $this->sameHost($site, $absolute)) {
                $candidates[$absolute] = true;
            }
        };

        // A product tile states its own id. The link beside it is the product
        // page, whatever the path looks like — this is what reaches Salesforce
        // Commerce Cloud and the other .html catalogues.
        if (preg_match_all('~data-pid=["\']([^"\']+)["\']~i', $html, $pids)) {
            foreach (array_unique($pids[1]) as $pid) {
                if (preg_match('~href=["\']([^"\']*' . preg_quote($pid, '~') . '[^"\']*)["\']~i', $html, $m)) {
                    $add($m[1]);
                }
            }
        }

        foreach ([
            '~href=["\']([^"\']*?/products/[^"\'?#]+)~i',
            '~href=["\']([^"\']*?/product/[^"\'?#]+)~i',
            '~href=["\']([^"\']*?/p/[^"\'?#]+)~i',
            '~href=["\']([^"\']*?/item/[^"\'?#]+)~i',
        ] as $pattern) {
            if (preg_match_all($pattern, $html, $matches)) {
                foreach ($matches[1] as $href) $add($href);
            }
        }

        // The number that was searched for, as it appears in a URL. A barcode
        // is usually a bare number; where it is "543309 AGIBILE" the number is
        // the half a URL will carry.
        $core = preg_match('~\d{4,}~', $barcode, $m) ? $m[0] : preg_replace('~[^A-Za-z0-9]+~', '', $barcode);

        $confident = $core === '' ? [] : array_filter(
            array_keys($candidates),
            fn (string $url) => str_contains(strtolower($url), strtolower($core)),
        );

        if ($confident !== []) {
            return array_slice(array_values($confident), 0, self::MAX_PRODUCTS_PER_BARCODE);
        }

        // Nothing named the barcode. Only the shapes that mean "product"
        // outright are trusted now — a bare .html could be the privacy policy.
        $shaped = array_filter(
            array_keys($candidates),
            fn (string $url) => (bool) preg_match('~/(?:products|product|p|item)/~i', $url),
        );

        return array_slice(array_values($shaped), 0, self::MAX_PRODUCTS_PER_BARCODE);
    }

    private function looksLikeProductPage(string $html): bool
    {
        return (bool) preg_match('~(og:type["\'\s:=]+product|"@type"\s*:\s*"Product"|add-to-cart|addToCart)~i', $html);
    }

    // ── One product page ─────────────────────────────────────────────────────

    /**
     * Every image belonging to a product, biggest version available, in the
     * order the page lists them.
     *
     * @return string[]
     */
    public function imagesFor(string $productUrl): array
    {
        return $this->readProduct($productUrl)['images'];
    }

    /**
     * One product page: what it is called, and every picture of it.
     *
     * @return array{title: ?string, images: string[]}
     */
    private function readProduct(string $productUrl): array
    {
        // Shopify hands over the whole product as JSON if you ask the product
        // URL with .js on the end — exact, ordered, full size, and no parsing.
        $shopify = $this->shopifyProductJson($productUrl);

        if ($shopify['images'] !== []) {
            return [
                'title'  => $shopify['title'],
                'images' => $this->tidy($productUrl, $shopify['images']),
            ];
        }

        $response = $this->client()->get($productUrl);

        if (!$response->successful()) return ['title' => null, 'images' => []];

        $html  = $response->body();
        $title = $this->titleFrom($html);

        // The page states its own main image in markup meant to be read —
        // schema.org first, then og:image. That one is certain.
        $primary = $this->tidy($productUrl, array_merge($this->fromJsonLd($html), $this->fromMeta($html)));

        // Every image anywhere on the page. Most of them belong to something
        // else: other colourways, "you may also like", the footer.
        // Not capped here: this is the pool the gallery is picked out of, and
        // the product's own third photograph can sit below thirty other
        // people's. The cap is applied to what survives the filter.
        $everything = $this->tidy($productUrl, $this->fromImgTags($html), self::MAX_PAGE_IMAGES);

        if ($primary === []) {
            return ['title' => $title, 'images' => array_slice($everything, 0, self::MAX_IMAGES_PER_PRODUCT)];
        }

        $gallery = $this->siblingsOf($primary[0], $everything);

        // The stated main image leads, then the rest of the gallery. Keyed on
        // the filename, not the URL: the same photograph is commonly served
        // from two paths — the static one og:image names and the resizing CDN
        // the gallery uses — and both would otherwise land in the folder.
        $final = [];

        foreach (array_merge($primary, $gallery) as $url) {
            $final[$this->filename($url)] ??= $url;
        }

        return [
            'title'  => $title,
            'images' => array_slice(array_values($final), 0, self::MAX_IMAGES_PER_PRODUCT),
        ];
    }

    /**
     * What the page calls this product — schema.org first, then og:title.
     *
     * Not <title>, which carries the shop's name and a tagline on most
     * storefronts: "Agello - Barrel-leg jeans | LS storefront catalog - Luisa
     * Spagnoli" is not a product name.
     */
    private function titleFrom(string $html): ?string
    {
        if (preg_match_all(
            '~<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>~is',
            $html,
            $blocks
        )) {
            foreach ($blocks[1] as $block) {
                $data = json_decode(trim($block), true);
                $type = is_array($data) ? ($data['@type'] ?? null) : null;

                if (in_array('Product', is_array($type) ? $type : [$type], true)
                    && is_string($data['name'] ?? null)) {
                    return html_entity_decode(trim($data['name']));
                }
            }
        }

        if (preg_match(
            '~<meta[^>]+(?:property|name)=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']~i',
            $html,
            $m
        )) {
            return html_entity_decode(trim($m[1]));
        }

        return null;
    }

    /**
     * The other shots of the same product, picked out of everything on the page.
     *
     * A product page shows far more images than the product: on one catalogue
     * checked while building this, a pair of jeans had three photographs and
     * the page carried thirty-six pictures — the other colourways and the
     * recommendation carousel. Taking them all put other people's products in
     * the folder, which is worse than taking too few.
     *
     * So the main image is used as the pattern. Catalogues name a product's
     * shots as one code and an index — foo_02710060XX1.jpg, ...XX2, ...XX3 —
     * so the code without its index identifies the set, and images sharing it
     * are the same product. Where that finds nothing, the filenames are
     * compared for a common opening instead (shirt-front / shirt-back), and
     * where that finds nothing either the stated main image stands alone.
     *
     * @param  string[] $everything
     * @return string[]
     */
    private function siblingsOf(string $primary, array $everything): array
    {
        $name = $this->filename($primary);

        if ($name === '') return [];

        // The trailing code, with the index digits taken off the end.
        $key = rtrim($this->lastSegment($name), '0123456789');

        $matched = strlen($key) >= 4
            ? array_values(array_filter($everything, fn ($url) => str_contains($this->filename($url), $key)))
            : [];

        // Whether the shared-code branch is what matched decides how the set
        // can be de-duplicated below: only a shared code makes the tail of a
        // filename specific enough to key on.
        $keyed = count($matched) >= 2;

        if (count($matched) < 2) {
            // No shared code. Fall back to a shared opening on the filename,
            // which is how the plainer catalogues name a set.
            $prefix = mb_substr($name, 0, max(4, (int) floor(mb_strlen($name) * 0.6)));

            $matched = mb_strlen($prefix) >= 4
                ? array_values(array_filter($everything, fn ($url) => str_starts_with($this->filename($url), $prefix)))
                : [];
        }

        if (count($matched) < 2) return [];

        // One file per index — the same shot is often on the page twice under
        // two different leading hashes, and both would otherwise be downloaded.
        //
        // Keyed on the tail only where a shared code was what gathered these;
        // on the prefix fallback the tail can be a word like "large", and
        // front_large and back_large are two photographs, not one.
        $byIndex = [];

        foreach ($matched as $url) {
            $index = $keyed ? $this->lastSegment($this->filename($url)) : $this->filename($url);

            $byIndex[$index] ??= $url;
        }

        ksort($byIndex, SORT_NATURAL);

        return array_values($byIndex);
    }

    /** The last underscore- or hyphen-delimited piece of a filename. */
    private function lastSegment(string $name): string
    {
        $parts = preg_split('~[_-]~', $name) ?: [$name];

        return (string) end($parts);
    }

    /** The filename of a URL, lowercased and without its extension. */
    private function filename(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;

        return strtolower(pathinfo($path, PATHINFO_FILENAME) ?: '');
    }

    /** @return array{title: ?string, images: string[]} */
    private function shopifyProductJson(string $productUrl): array
    {
        $empty = ['title' => null, 'images' => []];
        $base  = strtok($productUrl, '?') ?: $productUrl;

        if (!str_contains($base, '/products/')) return $empty;

        $response = $this->client()->get(rtrim($base, '/') . '.js');

        if (!$response->successful()) return $empty;

        $data = $response->json();

        if (!is_array($data)) return $empty;

        $images = $data['images'] ?? [];

        return [
            'title'  => is_string($data['title'] ?? null) ? $data['title'] : null,
            'images' => is_array($images) ? array_values(array_filter($images, 'is_string')) : [],
        ];
    }

    /** Schema.org product markup — the one place a site states its own images. */
    private function fromJsonLd(string $html): array
    {
        if (!preg_match_all(
            '~<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>~is',
            $html,
            $blocks
        )) {
            return [];
        }

        foreach ($blocks[1] as $block) {
            $data = json_decode(trim($block), true);

            if (!is_array($data)) continue;

            $images = $this->imagesFromLdNode($data);

            if ($images !== []) return $images;
        }

        return [];
    }

    /** JSON-LD nests Products inside @graph and arrays, so this walks whatever it is given. */
    private function imagesFromLdNode(array $node): array
    {
        $type = $node['@type'] ?? null;
        $type = is_array($type) ? $type : [$type];

        if (in_array('Product', $type, true) && isset($node['image'])) {
            $image  = $node['image'];
            $images = [];

            foreach (is_array($image) ? $image : [$image] as $entry) {
                if (is_string($entry)) {
                    $images[] = $entry;
                } elseif (is_array($entry) && is_string($entry['url'] ?? null)) {
                    $images[] = $entry['url'];
                }
            }

            if ($images !== []) return $images;
        }

        foreach ($node as $child) {
            if (is_array($child)) {
                $found = $this->imagesFromLdNode($child);

                if ($found !== []) return $found;
            }
        }

        return [];
    }

    /** og:image — one image, usually the main shot. Better than nothing. */
    private function fromMeta(string $html): array
    {
        preg_match_all(
            '~<meta[^>]+(?:property|name)=["\']og:image(?::secure_url)?["\'][^>]+content=["\']([^"\']+)["\']~i',
            $html,
            $matches
        );

        return $matches[1] ?? [];
    }

    /** Last resort: the <img> tags themselves, junk filtered out afterwards. */
    private function fromImgTags(string $html): array
    {
        preg_match_all('~<img\b[^>]*>~i', $html, $tags);

        $found = [];

        foreach ($tags[0] ?? [] as $tag) {
            // Lazy-loaded galleries keep the real file in data-src/data-zoom
            // and a placeholder in src, so those are read first.
            foreach (['data-zoom-src', 'data-large_image', 'data-src', 'data-original', 'src'] as $attribute) {
                if (preg_match('~\b' . preg_quote($attribute, '~') . '=["\']([^"\']+)["\']~i', $tag, $m)) {
                    $found[] = $m[1];
                    break;
                }
            }

            if (preg_match('~\bsrcset=["\']([^"\']+)["\']~i', $tag, $m)) {
                $found[] = $this->widestFromSrcset($m[1]);
            }
        }

        return array_values(array_filter($found));
    }

    /** A srcset lists the same picture at several widths; take the largest. */
    private function widestFromSrcset(string $srcset): ?string
    {
        $best      = null;
        $bestWidth = -1;

        foreach (explode(',', $srcset) as $candidate) {
            $parts = preg_split('~\s+~', trim($candidate)) ?: [];
            $url   = $parts[0] ?? '';

            if ($url === '') continue;

            $width = isset($parts[1]) && preg_match('~(\d+)w~', $parts[1], $m) ? (int) $m[1] : 0;

            if ($width > $bestWidth) {
                $best      = $url;
                $bestWidth = $width;
            }
        }

        return $best;
    }

    // ── Cleaning up what was found ───────────────────────────────────────────

    /**
     * Absolute, de-duplicated, junk removed, and asking for the original file
     * rather than the thumbnail the page happened to render.
     *
     * @param  string[] $images
     * @return string[]
     */
    private function tidy(string $productUrl, array $images, ?int $limit = null): array
    {
        $limit ??= self::MAX_IMAGES_PER_PRODUCT;

        $site = $this->normaliseSite($productUrl);
        $out  = [];

        foreach ($images as $image) {
            $absolute = $this->absolute($site ?? '', html_entity_decode((string) $image));

            if (!$absolute) continue;

            $absolute = $this->fullSize($absolute);

            if ($this->isJunk($absolute)) continue;

            // Keyed by URL so the same picture found twice — once in a
            // gallery, once in a zoom link — is downloaded once.
            $out[$absolute] = true;

            if (count($out) >= $limit) break;
        }

        return array_keys($out);
    }

    /**
     * Shopify and most CDNs encode the rendered size into the filename or the
     * query string. Stripping it asks for the original upload, which is the
     * whole point of downloading these rather than screenshotting them.
     */
    private function fullSize(string $url): string
    {
        // .../shirt_600x800_crop_center.jpg?v=1 → .../shirt.jpg?v=1
        $url = preg_replace(
            '~_(?:\d+x\d*|x\d+|pico|icon|thumb|small|compact|medium|large|grande|master)(?:_crop_[a-z]+)?(?=\.(?:jpe?g|png|webp|gif|avif)\b)~i',
            '',
            $url
        ) ?? $url;

        // ?width=200&height=200 — the CDN resizing on request.
        $url = preg_replace('~([?&])(?:width|height|w|h|sw|sh|size|quality)=\d+~i', '$1', $url) ?? $url;
        $url = preg_replace('~[?&]+$~', '', $url) ?? $url;

        return str_replace('?&', '?', $url);
    }

    private function isJunk(string $url): bool
    {
        $path = strtolower(parse_url($url, PHP_URL_PATH) ?: $url);

        if (str_ends_with($path, '.svg')) return true;

        // Not an image path at all — tracking pixels and 1x1 beacons come
        // through the same <img> sweep.
        if (!preg_match('~\.(?:jpe?g|png|webp|gif|avif)$~', $path) && !str_contains($url, 'cdn/shop')) {
            return true;
        }

        foreach (self::JUNK as $word) {
            if (str_contains($path, $word)) return true;
        }

        return false;
    }

    private function absolute(string $site, string $url): ?string
    {
        $url = trim($url);

        if ($url === '') return null;

        if (str_starts_with($url, '//'))  return 'https:' . $url;
        if (preg_match('~^https?://~i', $url)) return $url;
        if (str_starts_with($url, 'data:')) return null;

        if ($site === '') return null;

        // "/en/qa/x.html" is from the root of the site; "x.html" is from
        // wherever we are. With a storefront prefix in play the two resolve
        // against different bases, and mixing them up builds /en/qa/en/qa/...
        return str_starts_with($url, '/')
            ? rtrim($this->origin($site), '/') . $url
            : rtrim($site, '/') . '/' . ltrim($url, '/');
    }

    private function sameHost(string $a, string $b): bool
    {
        return strtolower(parse_url($a, PHP_URL_HOST) ?: '') === strtolower(parse_url($b, PHP_URL_HOST) ?: '');
    }

    // ── Fetching the bytes ───────────────────────────────────────────────────

    /**
     * Writes one image into a folder and returns the filename it was given, or
     * null if what came back was not an image worth keeping.
     */
    public function download(string $imageUrl, string $directory, string $stem, int $index): ?string
    {
        $response = $this->client()->get($imageUrl);

        if (!$response->successful()) return null;

        $type = strtolower(explode(';', (string) $response->header('Content-Type'))[0]);

        if (!str_starts_with($type, 'image/')) return null;

        $body = $response->body();

        if ($body === '' || strlen($body) > self::MAX_IMAGE_BYTES) return null;

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            return null;
        }

        $filename = "{$stem}-{$index}." . $this->extensionFor($type, $imageUrl);

        return file_put_contents("{$directory}/{$filename}", $body) === false ? null : $filename;
    }

    private function extensionFor(string $contentType, string $url): string
    {
        $known = [
            'image/jpeg' => 'jpg',
            'image/jpg'  => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
            'image/avif' => 'avif',
        ];

        if (isset($known[$contentType])) return $known[$contentType];

        $fromUrl = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));

        return in_array($fromUrl, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'], true)
            ? ($fromUrl === 'jpeg' ? 'jpg' : $fromUrl)
            : 'jpg';
    }
}
