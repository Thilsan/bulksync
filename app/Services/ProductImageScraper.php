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

        return $base;
    }

    // ── One barcode ──────────────────────────────────────────────────────────

    /**
     * Everything known about one barcode on one site.
     *
     * @return array{url: ?string, title: ?string, images: string[]}
     */
    public function forBarcode(string $site, string $barcode): array
    {
        $product = $this->findProduct($site, $barcode);

        if (!$product) {
            return ['url' => null, 'title' => null, 'images' => []];
        }

        return [
            'url'    => $product['url'],
            'title'  => $product['title'],
            'images' => $this->imagesFor($product['url']),
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
     * @return array{url: string, title: ?string}|null
     */
    public function findProduct(string $site, string $barcode): ?array
    {
        $barcode = trim($barcode);

        if ($barcode === '') return null;

        foreach ([
            fn () => $this->shopifySuggest($site, $barcode),
            fn () => $this->htmlSearch($site, $barcode),
            fn () => $this->directHandle($site, $barcode),
        ] as $attempt) {
            try {
                $hit = $attempt();
            } catch (\Throwable $e) {
                Log::debug("ProductImageScraper: lookup step failed for {$barcode}: " . $e->getMessage());
                continue;
            }

            if ($hit) return $hit;
        }

        return null;
    }

    /** Shopify's predictive search endpoint — it matches on barcode. */
    private function shopifySuggest(string $site, string $barcode): ?array
    {
        $response = $this->client()->get("{$site}/search/suggest.json", [
            'q'                 => $barcode,
            'resources[type]'   => 'product',
            'resources[limit]'  => 5,
        ]);

        if (!$response->successful()) return null;

        $products = $response->json('resources.results.products');

        if (!is_array($products) || $products === []) return null;

        $first = $products[0];
        $url   = $first['url'] ?? null;

        if (!is_string($url) || $url === '') return null;

        return [
            'url'   => $this->absolute($site, $url),
            'title' => is_string($first['title'] ?? null) ? html_entity_decode(strip_tags($first['title'])) : null,
        ];
    }

    /**
     * The site's own search results page, read as HTML.
     *
     * Two query shapes rather than one: Shopify and most bespoke carts use
     * ?q=, WordPress and WooCommerce use ?s=. Whichever answers with a link
     * that looks like a product page wins.
     */
    private function htmlSearch(string $site, string $barcode): ?array
    {
        $encoded = rawurlencode($barcode);

        $pages = [
            "{$site}/search?q={$encoded}",
            "{$site}/?s={$encoded}&post_type=product",
        ];

        foreach ($pages as $page) {
            $response = $this->client()->get($page);

            if (!$response->successful()) continue;

            $html = $response->body();
            $link = $this->firstProductLink($site, $html);

            if ($link) {
                return ['url' => $link, 'title' => null];
            }
        }

        return null;
    }

    /**
     * Catalogues where the barcode is the product handle — /products/1234567890
     * — answer directly, and a 404 costs one call to find out.
     */
    private function directHandle(string $site, string $barcode): ?array
    {
        $handle = strtolower(preg_replace('/[^A-Za-z0-9-]+/', '-', $barcode) ?? '');
        $handle = trim($handle, '-');

        if ($handle === '') return null;

        foreach (["{$site}/products/{$handle}", "{$site}/product/{$handle}"] as $candidate) {
            $response = $this->client()->get($candidate);

            if ($response->successful() && $this->looksLikeProductPage($response->body())) {
                return ['url' => $candidate, 'title' => null];
            }
        }

        return null;
    }

    /** The first href on a results page that has the shape of a product page. */
    private function firstProductLink(string $site, string $html): ?string
    {
        // Ordered by how strongly each shape means "product": Shopify and
        // WooCommerce name theirs outright, so those are trusted before the
        // looser /p/ and /item/ forms other carts use.
        $patterns = [
            '~href=["\']([^"\']*?/products/[^"\'?#]+)~i',
            '~href=["\']([^"\']*?/product/[^"\'?#]+)~i',
            '~href=["\']([^"\']*?/p/[^"\'?#]+)~i',
            '~href=["\']([^"\']*?/item/[^"\'?#]+)~i',
        ];

        foreach ($patterns as $pattern) {
            if (!preg_match_all($pattern, $html, $matches)) continue;

            foreach ($matches[1] as $href) {
                $href = html_entity_decode($href);

                // Shopify repeats the product link inside collection markup as
                // /collections/x/products/y — same page, longer URL. Either is
                // fine to open, so the first one found is taken.
                $absolute = $this->absolute($site, $href);

                if ($absolute && $this->sameHost($site, $absolute)) {
                    return $absolute;
                }
            }
        }

        return null;
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
        // Shopify hands over the whole product as JSON if you ask the product
        // URL with .js on the end — exact, ordered, full size, and no parsing.
        $images = $this->shopifyProductJson($productUrl);

        if ($images !== []) {
            return $this->tidy($productUrl, $images);
        }

        $response = $this->client()->get($productUrl);

        if (!$response->successful()) return [];

        $html = $response->body();

        foreach ([
            $this->fromJsonLd($html),
            $this->fromMeta($html),
            $this->fromImgTags($html),
        ] as $found) {
            if ($found !== []) {
                return $this->tidy($productUrl, $found);
            }
        }

        return [];
    }

    private function shopifyProductJson(string $productUrl): array
    {
        $base = strtok($productUrl, '?') ?: $productUrl;

        if (!str_contains($base, '/products/')) return [];

        $response = $this->client()->get(rtrim($base, '/') . '.js');

        if (!$response->successful()) return [];

        $data = $response->json();

        if (!is_array($data)) return [];

        $images = $data['images'] ?? [];

        return is_array($images) ? array_values(array_filter($images, 'is_string')) : [];
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
    private function tidy(string $productUrl, array $images): array
    {
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

            if (count($out) >= self::MAX_IMAGES_PER_PRODUCT) break;
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
        $url = preg_replace('~([?&])(?:width|height|w|h|size|quality)=\d+~i', '$1', $url) ?? $url;
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

        return rtrim($site, '/') . '/' . ltrim($url, '/');
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
