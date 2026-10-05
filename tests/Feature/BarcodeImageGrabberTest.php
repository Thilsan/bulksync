<?php

namespace Tests\Feature;

use App\Jobs\PushBarcodeImagesJob;
use App\Jobs\RunBarcodeImageDownloadJob;
use App\Models\BarcodeImageItem;
use App\Models\BarcodeImageSession;
use App\Models\Store;
use App\Models\User;
use App\Services\ProductImageScraper;
use App\Services\ShopifyService;
use Mockery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * The Barcode Image Grabber: a list of internal barcodes and a public website
 * in, a folder of pictures per barcode out.
 *
 * The site being read is somebody else's and can say anything, so the parsing
 * is the part worth holding still — a storefront that answers with JSON, one
 * that answers with only HTML, and one that has never heard of the barcode all
 * have to end somewhere sensible.
 */
class BarcodeImageGrabberTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        // is_active matters: an inactive user is bounced to the login screen
        // and every assertion below then reads as a routing failure.
        return User::create([
            'name'                => 'Image Grabber',
            'email'               => 'grab@example.test',
            'password'            => 'password',
            'is_active'           => true,
            'perm_barcode_images' => true,
        ]);
    }

    private function onePixelPng(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
    }

    // ── The screen ───────────────────────────────────────────────────────────

    public function test_the_form_carries_every_field_the_run_is_started_from(): void
    {
        $this->actingAs($this->operator())
            ->get(route('barcode-images.index'))
            ->assertOk()
            ->assertSee('name="site_url"', false)
            ->assertSee('name="barcodes"', false)
            ->assertSee('name="csv_file"', false);
    }

    public function test_someone_without_the_permission_is_refused(): void
    {
        $outsider = User::create([
            'name' => 'No Access', 'email' => 'no@example.test',
            'password' => 'password', 'is_active' => true,
        ]);

        $this->actingAs($outsider)->get(route('barcode-images.index'))->assertForbidden();
    }

    // ── Starting a run ───────────────────────────────────────────────────────

    public function test_a_pasted_site_and_barcode_list_starts_a_run(): void
    {
        Bus::fake();

        $this->actingAs($this->operator())
            ->post(route('barcode-images.start'), [
                'site_url' => 'BlueSalon.com/collections/all?page=2',
                'barcodes' => "8806094582161\n3614273065580\n8806094582161",
                'name'     => 'Autumn drop',
            ])
            ->assertRedirect();

        $session = BarcodeImageSession::sole();

        // The pasted URL is reduced to the site itself, and the barcode
        // repeated twice is looked up once.
        $this->assertSame('https://bluesalon.com', $session->site_url);
        $this->assertSame(2, $session->total_barcodes);
        $this->assertSame('Autumn drop', $session->name);

        Bus::assertDispatched(RunBarcodeImageDownloadJob::class);
    }

    public function test_a_list_with_no_barcodes_in_it_is_refused_before_anything_is_queued(): void
    {
        Bus::fake();

        $this->actingAs($this->operator())
            ->post(route('barcode-images.start'), ['site_url' => 'bluesalon.com', 'barcodes' => "  \n  "])
            ->assertSessionHasErrors('barcodes');

        $this->assertSame(0, BarcodeImageSession::count());
        Bus::assertNothingDispatched();
    }

    public function test_something_that_is_not_a_website_is_refused(): void
    {
        Bus::fake();

        $this->actingAs($this->operator())
            ->post(route('barcode-images.start'), ['site_url' => 'not a url at all', 'barcodes' => '123'])
            ->assertSessionHasErrors('site_url');

        Bus::assertNothingDispatched();
    }

    // ── The run itself ───────────────────────────────────────────────────────

    public function test_each_barcode_gets_its_own_folder_of_pictures(): void
    {
        Http::fake([
            'shop.test/search/suggest.json*' => Http::response([
                'resources' => ['results' => ['products' => [
                    ['title' => 'Silk Scarf', 'url' => '/products/silk-scarf'],
                ]]],
            ]),
            'shop.test/products/silk-scarf.js' => Http::response([
                'title'  => 'Silk Scarf',
                'images' => [
                    '//cdn.shop.test/files/scarf-1_600x800.jpg?v=1',
                    '//cdn.shop.test/files/scarf-2.jpg',
                ],
            ]),
            'cdn.shop.test/*' => Http::response($this->onePixelPng(), 200, ['Content-Type' => 'image/png']),
        ]);

        $session = BarcodeImageSession::create([
            'user_id'      => $this->operator()->id,
            'site_url'     => 'https://shop.test',
            'status'       => 'pending',
            'raw_barcodes' => '8806094582161',
        ]);

        app(RunBarcodeImageDownloadJob::class, ['sessionId' => $session->id])
            ->handle(app(ProductImageScraper::class));

        $session->refresh();

        $this->assertSame('completed', $session->status);
        $this->assertSame(1, $session->found_count);
        $this->assertSame(2, $session->images_downloaded);

        $folder = $session->folderFor('8806094582161');
        $this->assertDirectoryExists($folder);
        $this->assertFileExists("{$folder}/8806094582161-1.png");
        $this->assertFileExists("{$folder}/8806094582161-2.png");

        $item = $session->items()->sole();
        $this->assertSame('found', $item->status);
        $this->assertSame('Silk Scarf', $item->product_title);
        $this->assertSame(2, $item->image_count);

        // The thumbnail suffix is stripped so what lands is the original
        // upload rather than the 600x800 the grid happened to render.
        Http::assertSent(fn ($request) => $request->url() === 'https://cdn.shop.test/files/scarf-1.jpg?v=1');

        $session->deleteFiles();
    }

    public function test_a_barcode_the_site_has_never_heard_of_is_recorded_and_the_run_carries_on(): void
    {
        Http::fake([
            'shop.test/search/suggest.json*' => Http::response(['resources' => ['results' => ['products' => []]]]),
            '*' => Http::response('', 404),
        ]);

        $session = BarcodeImageSession::create([
            'user_id'      => $this->operator()->id,
            'site_url'     => 'https://shop.test',
            'status'       => 'pending',
            'raw_barcodes' => "0000000000000\n1111111111111",
        ]);

        app(RunBarcodeImageDownloadJob::class, ['sessionId' => $session->id])
            ->handle(app(ProductImageScraper::class));

        $session->refresh();

        $this->assertSame('completed', $session->status);
        $this->assertSame(2, $session->processed);
        $this->assertSame(2, $session->missing_count);
        $this->assertSame(0, $session->images_downloaded);
        $this->assertSame(['not_found', 'not_found'], $session->items()->pluck('status')->all());
    }

    public function test_a_storefront_that_only_answers_in_html_is_still_read(): void
    {
        Http::fake([
            'shop.test/search/suggest.json*' => Http::response('', 404),
            // Query-aware, the way a real search is: the probe that checks
            // whether this site reads its own query has to see it answer
            // differently for something that does not exist.
            'shop.test/search?*' => function ($request) {
                return str_contains($request->url(), '5051234567890')
                    ? Http::response('<a class="card" href="/collections/all/products/leather-belt">Leather Belt</a>')
                    : Http::response('<p>Nothing found.</p>');
            },
            'shop.test/collections/all/products/leather-belt.js' => Http::response('', 404),
            'shop.test/collections/all/products/leather-belt' => Http::response(
                '<html><head><meta property="og:image" content="https://cdn.shop.test/belt.jpg"></head>'
                . '<body><img src="/assets/logo.png"></body></html>'
            ),
            'cdn.shop.test/*' => Http::response($this->onePixelPng(), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $session = BarcodeImageSession::create([
            'user_id'      => $this->operator()->id,
            'site_url'     => 'https://shop.test',
            'status'       => 'pending',
            'raw_barcodes' => '5051234567890',
        ]);

        app(RunBarcodeImageDownloadJob::class, ['sessionId' => $session->id])
            ->handle(app(ProductImageScraper::class));

        $session->refresh();

        $this->assertSame(1, $session->found_count);
        $this->assertSame(1, $session->images_downloaded);
        $this->assertFileExists($session->folderFor('5051234567890') . '/5051234567890-1.jpg');

        $session->deleteFiles();
    }

    // ── Getting it back out ──────────────────────────────────────────────────

    public function test_the_zip_is_one_folder_per_barcode(): void
    {
        $user    = $this->operator();
        $session = BarcodeImageSession::create([
            'user_id'  => $user->id,
            'site_url' => 'https://shop.test',
            'status'   => 'completed',
            'name'     => 'Autumn drop',
        ]);

        foreach (['8806094582161', '3614273065580'] as $barcode) {
            $folder = $session->folderFor($barcode);
            mkdir($folder, 0755, true);
            file_put_contents("{$folder}/{$barcode}-1.png", $this->onePixelPng());
        }

        $path = tempnam(sys_get_temp_dir(), 'grab-test-') . '.zip';

        $response = $this->actingAs($user)->get(route('barcode-images.download', $session))->assertOk();
        file_put_contents($path, $response->streamedContent() ?: $response->getContent());

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true);

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();

        sort($names);
        $this->assertSame([
            '3614273065580/3614273065580-1.png',
            '8806094582161/8806094582161-1.png',
        ], $names);

        @unlink($path);
        $session->deleteFiles();
    }

    public function test_deleting_a_run_takes_its_images_with_it(): void
    {
        $user    = $this->operator();
        $session = BarcodeImageSession::create([
            'user_id'  => $user->id,
            'site_url' => 'https://shop.test',
            'status'   => 'completed',
        ]);

        $folder = $session->folderFor('8806094582161');
        mkdir($folder, 0755, true);
        file_put_contents("{$folder}/8806094582161-1.png", $this->onePixelPng());

        $directory = $session->directory();

        $this->actingAs($user)->delete(route('barcode-images.destroy', $session))->assertRedirect();

        $this->assertDirectoryDoesNotExist($directory);
        $this->assertSame(0, BarcodeImageSession::count());
    }

    public function test_one_run_cannot_be_read_by_another_persons_account(): void
    {
        $session = BarcodeImageSession::create([
            'user_id'  => $this->operator()->id,
            'site_url' => 'https://shop.test',
            'status'   => 'completed',
        ]);

        $other = User::create([
            'name' => 'Someone Else', 'email' => 'else@example.test',
            'password' => 'password', 'is_active' => true, 'perm_barcode_images' => true,
        ]);

        $this->actingAs($other)->get(route('barcode-images.show', $session))->assertForbidden();
    }


    // ── Catalogues that are not Shopify ──────────────────────────────────────

    /**
     * Salesforce Commerce Cloud, which is what sent this back for a rewrite.
     *
     * Its product URLs are /en/qa/542849_127700000000_agello.html — the same
     * shape as /en/qa/privacy-policy.html — so the link cannot be recognised
     * by its path. What identifies it is that it carries the barcode.
     */
    public function test_a_dot_html_catalogue_is_recognised_by_the_barcode_in_the_link(): void
    {
        $tile = '<div class="product" data-pid="542849_127700000000_agello">'
              . '<a id="542849_127700000000_agello-link" href="/en/qa/542849_127700000000_agello.html">Agello</a></div>';

        Http::fake([
            'shop.test/search/suggest.json*' => Http::response('', 404),
            'shop.test/search?*' => Http::response(
                '<a href="/en/qa/privacy-policy.html">Privacy</a>' . $tile . '<a href="/en/qa/returns.html">Returns</a>'
            ),
            'shop.test/en/qa/542849_127700000000_agello.html' => Http::response($this->commerceCloudProductPage()),
            'shop.test/*.jpg*' => Http::response($this->onePixelPng(), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $session = BarcodeImageSession::create([
            'user_id'      => $this->operator()->id,
            'site_url'     => 'https://shop.test',
            'status'       => 'pending',
            'raw_barcodes' => '542849AGELLO',
        ]);

        app(RunBarcodeImageDownloadJob::class, ['sessionId' => $session->id])
            ->handle(app(ProductImageScraper::class));

        $item = $session->refresh()->items()->sole();

        $this->assertSame('found', $item->status);
        $this->assertSame('https://shop.test/en/qa/542849_127700000000_agello.html', $item->product_url);
        $this->assertSame('Agello', $item->product_title);

        // Three photographs of this colourway — not the thirty-odd the page
        // carries for the other colours and the recommendation carousel.
        $this->assertSame(3, $item->image_count);

        $session->deleteFiles();
    }

    public function test_the_other_colourways_on_the_page_are_left_where_they_are(): void
    {
        Http::fake([
            'shop.test/*' => Http::response($this->commerceCloudProductPage()),
        ]);

        $images = app(ProductImageScraper::class)
            ->imagesFor('https://shop.test/en/qa/542849_127700000000_agello.html');

        $names = array_map(fn ($url) => basename(parse_url($url, PHP_URL_PATH)), $images);

        // The main image leads, its two siblings follow, and the same shot
        // served a second time from the resizing CDN is not counted twice.
        $this->assertSame([
            'aaa_11_02710060XX1.jpg',
            'bbb_22_02710060XX2.jpg',
            'ccc_33_02710060XX3.jpg',
        ], $names);
    }

    public function test_a_barcode_matching_two_colourways_collects_both_into_the_one_folder(): void
    {
        Http::fake([
            'shop.test/search/suggest.json*' => Http::response('', 404),
            'shop.test/search?*' => Http::response(
                '<div data-pid="543309_010100000000_agibile"><a href="/en/qa/543309_010100000000_agibile.html">A</a></div>'
                . '<div data-pid="543309_020100000000_agibile"><a href="/en/qa/543309_020100000000_agibile.html">B</a></div>'
            ),
            'shop.test/en/qa/543309_010100000000_agibile.html' => Http::response($this->commerceCloudProductPage('02710090')),
            'shop.test/en/qa/543309_020100000000_agibile.html' => Http::response($this->commerceCloudProductPage('02710100')),
            'shop.test/*.jpg*' => Http::response($this->onePixelPng(), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $session = BarcodeImageSession::create([
            'user_id'      => $this->operator()->id,
            'site_url'     => 'https://shop.test',
            'status'       => 'pending',
            'raw_barcodes' => '543309',
        ]);

        app(RunBarcodeImageDownloadJob::class, ['sessionId' => $session->id])
            ->handle(app(ProductImageScraper::class));

        $item = $session->refresh()->items()->sole();

        $this->assertSame(6, $item->image_count);
        $this->assertStringContainsString('2 product pages matched', (string) $item->message);
        $this->assertCount(6, glob($session->folderFor('543309') . '/*'));

        $session->deleteFiles();
    }

    public function test_a_search_page_of_unrelated_links_is_not_mistaken_for_a_result(): void
    {
        Http::fake([
            'shop.test/search/suggest.json*' => Http::response('', 404),
            'shop.test/search?*' => Http::response(
                '<a href="/en/qa/privacy-policy.html">Privacy</a><a href="/en/qa/delivery-time.html">Delivery</a>'
            ),
            '*' => Http::response('', 404),
        ]);

        $this->assertSame([], app(ProductImageScraper::class)->findProducts('https://shop.test', '9999999'));
    }

    /**
     * Albertoshop and Herrenausstatter, which sent this back a fourth time.
     *
     * Forty-one barcodes came back "not found" on both, while the operator
     * could see the products in the site's own search. Two reasons, one on top
     * of the other: the search is answered at ?query= and every other
     * parameter name is sent to the homepage, and the links it answers with
     * carry the shop's internal id — /alberto-jeans-443939 for article
     * 42071367 — so there is nothing in the link to recognise the article by.
     */
    public function test_a_storefront_that_answers_a_different_search_parameter_is_read(): void
    {
        Http::fake([
            'shop.test/search/suggest.json*' => Http::response('', 404),

            // Every name but ?query= is answered with the homepage, whatever
            // was asked — the shape that reported a thousand certain misses.
            'shop.test/search?q=*' => Http::response($this->nextJsListing()),
            'shop.test/?s=*'       => Http::response($this->nextJsListing()),

            // The one name it does read. Anything it has never heard of is
            // answered with the department rather than an empty page.
            'shop.test/search?query=*' => function ($request) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                return ($query['query'] ?? '') === '42071367'
                    ? Http::response($this->nextJsSearchPage('42071367', [
                        ['443939', '42071367/849'],
                        ['445087', '48071984/112'],
                    ]))
                    : Http::response($this->nextJsListing());
            },

            'shop.test/alberto-jeans-443939' => Http::response($this->nextJsProductPage('443939', '42071367')),
            'shop.test/alberto-jeans-445087' => Http::response($this->nextJsProductPage('445087', '48071984')),
            'cdn.test/*' => Http::response($this->onePixelPng(), 200, ['Content-Type' => 'image/jpeg']),
            '*' => Http::response('', 404),
        ]);

        $session = BarcodeImageSession::create([
            'user_id'      => $this->operator()->id,
            'site_url'     => 'https://shop.test',
            'status'       => 'pending',
            'raw_barcodes' => '42071367',
        ]);

        app(RunBarcodeImageDownloadJob::class, ['sessionId' => $session->id])
            ->handle(app(ProductImageScraper::class));

        $session->refresh();

        // Not stopped as unreadable: one way of asking was ignored, another
        // was answered, and an answer anywhere is enough.
        $this->assertSame('completed', $session->status);

        $item = $session->items()->sole();

        $this->assertSame('found', $item->status);
        $this->assertSame('https://shop.test/alberto-jeans-443939', $item->product_url);
        $this->assertSame(3, $item->image_count);

        // The tile beside it is another article, and its pictures stayed on
        // the page they belong to.
        $this->assertStringNotContainsString('445087', (string) $item->product_url);

        $session->deleteFiles();
    }

    /**
     * The other half of that site: a search with no results is not an empty
     * page but the whole department, with the number that was asked for
     * printed above it. Taking the first tile under that heading would put
     * somebody else's trousers in the folder and call it a match.
     */
    public function test_a_listing_served_under_the_searched_for_number_is_not_a_match(): void
    {
        Http::fake([
            'shop.test/search/suggest.json*' => Http::response('', 404),
            'shop.test/search?query=*' => Http::response(
                $this->nextJsSearchPage('99999999', [
                    ['468727', '48071111/100'],
                    ['469009', '48072222/200'],
                ])
            ),
            'shop.test/search?q=*' => Http::response($this->nextJsListing()),
            'shop.test/alberto-jeans-468727' => Http::response($this->nextJsProductPage('468727', '48071111')),
            'shop.test/alberto-jeans-469009' => Http::response($this->nextJsProductPage('469009', '48072222')),
            '*' => Http::response('', 404),
        ]);

        $this->assertSame([], app(ProductImageScraper::class)->findProducts('https://shop.test', '99999999'));
    }

    // ── Several websites per run ─────────────────────────────────────────────

    public function test_a_run_takes_a_list_of_websites_in_the_order_they_were_typed(): void
    {
        Bus::fake();

        $this->actingAs($this->operator())
            ->post(route('barcode-images.start'), [
                'site_url' => "albertoshop.de\nherrenausstatter.de, ALBERTOSHOP.de",
                'barcodes' => '42071367',
            ])
            ->assertRedirect();

        $session = BarcodeImageSession::sole();

        // Typed twice is asked once, and the first one named leads.
        $this->assertSame(
            ['https://albertoshop.de', 'https://herrenausstatter.de'],
            $session->sites()
        );
        $this->assertSame('https://albertoshop.de', $session->site_url);
    }

    /**
     * The run that prompted all of this: the first site refuses the server, and
     * forty-one barcodes that exist on the second came back empty because the
     * run had nowhere else to look.
     */
    public function test_a_site_that_refuses_the_server_is_skipped_and_the_next_one_answers(): void
    {
        $block = '<html><head><title>Attention Required! | Cloudflare</title></head>'
            . '<body><div id="cf-error-details">blocked</div></body></html>';

        Http::fake([
            'blocked.test/*' => Http::response($block, 403),

            'open.test/search/suggest.json*' => Http::response('', 404),
            'open.test/search?query=*' => function ($request) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                return ($query['query'] ?? '') === '42071367'
                    ? Http::response($this->nextJsSearchPage('42071367', [['443939', '42071367/849']]))
                    : Http::response($this->nextJsListing());
            },
            'open.test/search?q=*' => Http::response($this->nextJsListing()),
            'open.test/alberto-jeans-443939' => Http::response($this->nextJsProductPage('443939', '42071367')),
            'cdn.test/*' => Http::response($this->onePixelPng(), 200, ['Content-Type' => 'image/jpeg']),
            '*' => Http::response('', 404),
        ]);

        $session = BarcodeImageSession::create([
            'user_id'      => $this->operator()->id,
            'site_url'     => 'https://blocked.test',
            'site_urls'    => ['https://blocked.test', 'https://open.test'],
            'status'       => 'pending',
            'raw_barcodes' => '42071367',
        ]);

        app(RunBarcodeImageDownloadJob::class, ['sessionId' => $session->id])
            ->handle(app(ProductImageScraper::class));

        $session->refresh();
        $item = $session->items()->sole();

        // The run carried on without the site that refused it, rather than
        // stopping or reporting a barcode that was there all along as missing.
        $this->assertSame('completed', $session->status);
        $this->assertSame('found', $item->status);
        $this->assertSame(3, $item->image_count);
        $this->assertSame('https://open.test', $item->source_site);

        // And why the other site was left out is recorded where the person
        // looking at a half-empty run will find it.
        $this->assertStringContainsString('blocked.test was left out', (string) $session->error_message);

        $session->deleteFiles();
    }

    public function test_a_barcode_the_first_site_does_not_stock_is_looked_for_on_the_next(): void
    {
        Http::fake([
            'thin.test/search/suggest.json*' => Http::response('', 404),
            'thin.test/search*' => Http::response('<p>Nothing found.</p>'),

            'deep.test/search/suggest.json*' => Http::response('', 404),
            'deep.test/search?query=*' => function ($request) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                return ($query['query'] ?? '') === '42071367'
                    ? Http::response($this->nextJsSearchPage('42071367', [['443939', '42071367/849']]))
                    : Http::response($this->nextJsListing());
            },
            'deep.test/search?q=*' => Http::response($this->nextJsListing()),
            'deep.test/alberto-jeans-443939' => Http::response($this->nextJsProductPage('443939', '42071367')),
            'cdn.test/*' => Http::response($this->onePixelPng(), 200, ['Content-Type' => 'image/jpeg']),
            '*' => Http::response('', 404),
        ]);

        $session = BarcodeImageSession::create([
            'user_id'      => $this->operator()->id,
            'site_url'     => 'https://thin.test',
            'site_urls'    => ['https://thin.test', 'https://deep.test'],
            'status'       => 'pending',
            'raw_barcodes' => "42071367\n99999999",
        ]);

        app(RunBarcodeImageDownloadJob::class, ['sessionId' => $session->id])
            ->handle(app(ProductImageScraper::class));

        $session->refresh();

        $found   = $session->items()->where('barcode', '42071367')->sole();
        $missing = $session->items()->where('barcode', '99999999')->sole();

        $this->assertSame('found', $found->status);
        $this->assertSame('https://deep.test', $found->source_site);

        // One nobody stocks says so for the list, not for one shop.
        $this->assertSame('not_found', $missing->status);
        $this->assertStringContainsString('any of the 2 websites', (string) $missing->message);

        $this->assertSame(1, $session->found_count);
        $this->assertSame(1, $session->missing_count);

        $session->deleteFiles();
    }

    public function test_the_run_screen_marks_a_blocked_site_as_blocked(): void
    {
        $session = BarcodeImageSession::create([
            'user_id'     => $this->operator()->id,
            'site_url'    => 'https://blocked.test',
            'site_urls'   => ['https://blocked.test', 'https://open.test'],
            'site_issues' => [
                'https://blocked.test' => [
                    'label' => 'Blocked',
                    'kind'  => 'blocked',
                    'why'   => 'This site is refusing this server: its security service answered with a block page (403).',
                ],
            ],
            'status'         => 'completed',
            'total_barcodes' => 2,
        ]);

        $this->actingAs($session->user)
            ->get(route('barcode-images.show', $session))
            ->assertOk()
            ->assertSee('Blocked')
            ->assertSee('refusing this server', false)
            ->assertSee('blocked.test');
    }

    public function test_a_run_whose_every_site_refuses_the_server_stops_and_names_them_all(): void
    {
        $block = '<html><head><title>Attention Required! | Cloudflare</title></head>'
            . '<body><div id="cf-error-details">blocked</div></body></html>';

        Http::fake(['*' => Http::response($block, 403)]);

        $session = BarcodeImageSession::create([
            'user_id'      => $this->operator()->id,
            'site_url'     => 'https://one.test',
            'site_urls'    => ['https://one.test', 'https://two.test'],
            'status'       => 'pending',
            'raw_barcodes' => "42071367\n42071764",
        ]);

        app(RunBarcodeImageDownloadJob::class, ['sessionId' => $session->id])
            ->handle(app(ProductImageScraper::class));

        $session->refresh();

        $this->assertSame('failed', $session->status);
        $this->assertStringContainsString('None of the websites', (string) $session->error_message);
        $this->assertStringContainsString('one.test', (string) $session->error_message);
        $this->assertStringContainsString('two.test', (string) $session->error_message);
        $this->assertSame(0, $session->items()->count());
    }

    /**
     * The fifth way Albertoshop came back: the search the operator can see
     * working from the office is answered for the server with a Cloudflare
     * block page, and forty-one "not found" rows read as a bad list.
     */
    public function test_a_site_refusing_this_server_says_so_rather_than_missing_every_barcode(): void
    {
        $block = '<html><head><title>Attention Required! | Cloudflare</title></head><body>'
            . '<div id="cf-error-details"><h1>Sorry, you have been blocked</h1>'
            . '<span class="hidden" id="cf-footer-ip">168.144.191.37</span></div></body></html>';

        Http::fake(['*' => Http::response($block, 403)]);

        $session = BarcodeImageSession::create([
            'user_id'      => $this->operator()->id,
            'site_url'     => 'https://shop.test',
            'status'       => 'pending',
            'raw_barcodes' => "42071367\n42071764",
        ]);

        app(RunBarcodeImageDownloadJob::class, ['sessionId' => $session->id])
            ->handle(app(ProductImageScraper::class));

        $session->refresh();

        $this->assertSame('failed', $session->status);
        $this->assertStringContainsString('refusing this server', (string) $session->error_message);
        $this->assertStringContainsString('168.144.191.37', (string) $session->error_message);

        // Stopped, rather than reporting two barcodes that were never looked up.
        $this->assertSame(0, $session->items()->count());
    }

    /**
     * The block the check up front cannot see: the homepage is let through,
     * and every search behind it is answered with Cloudflare's block page.
     * That finished as "Completed" with nothing found, which reads as a wrong
     * list rather than a refused server.
     */
    public function test_a_site_that_blocks_the_search_but_not_the_homepage_is_reported_as_ip_blocked(): void
    {
        $block = '<html><head><title>Attention Required! | Cloudflare</title></head>'
            . '<body><div id="cf-error-details">Sorry, you have been blocked</div></body></html>';

        Http::fake([
            '*shop.test/' => Http::response('<html><body>Welcome</body></html>'),
            '*'           => Http::response($block, 403),
        ]);

        $session = BarcodeImageSession::create([
            'user_id'      => $this->operator()->id,
            'site_url'     => 'https://shop.test',
            'status'       => 'pending',
            'raw_barcodes' => "1001\n1002\n1003\n1004\n1005",
        ]);

        app(RunBarcodeImageDownloadJob::class, ['sessionId' => $session->id])
            ->handle(app(ProductImageScraper::class));

        $session->refresh();

        $this->assertSame('completed', $session->status);
        $this->assertSame(5, $session->blocked_count);
        $this->assertSame(['label' => 'IP blocked', 'colour' => 'red'], $session->statusBadge());
        $this->assertSame(5, $session->items()->where('status', 'blocked')->count());

        // Dropped after refusing three barcodes running, rather than being
        // asked for the rest of the list.
        $this->assertSame('blocked', $session->issueWith('https://shop.test')['kind'] ?? null);
        $this->assertStringContainsString('Not looked up', (string) $session->items()->where('barcode', '1005')->value('message'));

        $this->actingAs($session->user)
            ->get(route('barcode-images.history'))
            ->assertOk()
            ->assertSee('IP blocked');
    }

    public function test_a_run_blocked_on_one_site_but_finding_pictures_on_another_is_partly_blocked(): void
    {
        $session = BarcodeImageSession::create([
            'user_id'       => $this->operator()->id,
            'site_url'      => 'https://open.test',
            'status'        => 'completed',
            'found_count'   => 3,
            'blocked_count' => 2,
        ]);

        $this->assertSame('Partly blocked', $session->statusBadge()['label']);

        $session->update(['blocked_count' => 0]);

        $this->assertSame(['label' => 'Completed', 'colour' => 'green'], $session->statusBadge());
    }

    /**
     * Dan John's Shopify search answers "429, retry after 60" now and then.
     * That is a pause asked for, and the barcode is there once it is given.
     */
    public function test_a_rate_limit_is_waited_out_once_before_it_counts_as_a_block(): void
    {
        Sleep::fake();

        $asked = 0;

        Http::fake([
            '*shop.test/search/suggest.json*' => function () use (&$asked) {
                return ++$asked === 1
                    ? Http::response('local_rate_limited', 429, ['Retry-After' => '60'])
                    : Http::response(['resources' => ['results' => ['products' => [
                        ['url' => '/products/jeans', 'title' => 'Jeans'],
                    ]]]]);
            },
            '*' => Http::response('', 404),
        ]);

        $result = app(ProductImageScraper::class)->forBarcode('https://shop.test', '1001');

        Sleep::assertSlept(fn ($duration) => (int) $duration->totalSeconds === 60, 1);
        $this->assertNull($result['blocked']);
        $this->assertStringEndsWith('/products/jeans', (string) $result['url']);
    }

    public function test_a_rate_limit_that_persists_is_reported_as_a_block(): void
    {
        Sleep::fake();

        Http::fake(['*' => Http::response('local_rate_limited', 429, ['Retry-After' => '60'])]);

        $result = app(ProductImageScraper::class)->forBarcode('https://shop.test', '1001');

        $this->assertSame(429, $result['blocked']);

        // Waited out once, not once for every request the lookup makes.
        Sleep::assertSleptTimes(1);
    }

    public function test_a_finished_run_with_no_pictures_does_not_read_as_a_green_completed(): void
    {
        $session = BarcodeImageSession::create([
            'user_id'        => $this->operator()->id,
            'site_url'       => 'https://shop.test',
            'status'         => 'completed',
            'total_barcodes' => 41,
        ]);

        $this->assertSame(['label' => 'Nothing found', 'colour' => 'amber'], $session->statusBadge());
    }

    public function test_the_recheck_marks_an_old_run_against_a_refusing_site_as_blocked(): void
    {
        $block = '<html><head><title>Attention Required! | Cloudflare</title></head>'
            . '<body><div id="cf-error-details">blocked</div></body></html>';

        Http::fake([
            '*blocked.test/' => Http::response('<html><body>Welcome</body></html>'),
            '*blocked.test/*' => Http::response($block, 403),
            '*' => Http::response('<p>Nothing found.</p>'),
        ]);

        $user = $this->operator();

        $old = BarcodeImageSession::create([
            'user_id' => $user->id, 'site_url' => 'https://blocked.test',
            'status' => 'completed', 'total_barcodes' => 2, 'missing_count' => 2,
        ]);
        $old->items()->createMany([
            ['barcode' => '1001', 'status' => 'not_found'],
            ['barcode' => '1002', 'status' => 'not_found'],
        ]);

        $honest = BarcodeImageSession::create([
            'user_id' => $user->id, 'site_url' => 'https://open.test',
            'status' => 'completed', 'total_barcodes' => 1, 'missing_count' => 1,
        ]);
        $honest->items()->create(['barcode' => '2001', 'status' => 'not_found']);

        $this->artisan('barcode-images:recheck-blocks')->assertSuccessful();

        $this->assertSame('IP blocked', $old->refresh()->statusBadge()['label']);
        $this->assertSame(2, $old->blocked_count);
        $this->assertSame(2, $old->items()->where('status', 'blocked')->count());

        $this->assertSame('Nothing found', $honest->refresh()->statusBadge()['label']);
    }

    public function test_a_run_stopped_because_every_site_refused_reads_as_ip_blocked(): void
    {
        $session = BarcodeImageSession::create([
            'user_id'     => $this->operator()->id,
            'site_url'    => 'https://shop.test',
            'status'      => 'failed',
            'site_issues' => ['https://shop.test' => ['label' => 'Blocked', 'kind' => 'blocked', 'why' => 'Refused.']],
        ]);

        $this->assertSame('IP blocked', $session->statusBadge()['label']);
    }

    /** A grid of tiles, each naming its own article and linking by internal id. */
    private function nextJsSearchPage(string $searchedFor, array $tiles): string
    {
        $cards = '';

        foreach ($tiles as [$id, $article]) {
            $cards .= '<div itemScope itemType="https://schema.org/Product">'
                . '<meta itemProp="name" content="Alberto Regular Fit Pipe ' . $article . '"/>'
                . '<meta itemProp="sku" content="P' . $id . '"/>'
                . '<a href="/alberto-jeans-' . $id . '"><img src="https://cdn.test/pimages/' . $id . '_suche.jpg"></a>'
                . '<a href="/alberto-jeans-' . $id . '">Jeans Pipe</a>'
                . '</div>';
        }

        // The page says back what was asked for, above the results, and says
        // it again in the framework's own payload further down.
        return '<html><body><a href="/">Shop</a>'
            . '<span>Suchergebnisse für</span><span>„' . $searchedFor . '“</span>'
            . '<div class="grid">' . $cards . '</div>'
            . '<script>self.__next_f.push([1,"search?query=' . $searchedFor . '"])</script>'
            . '</body></html>';
    }

    /** The homepage, which is what this storefront answers an unknown search with. */
    private function nextJsListing(): string
    {
        return '<html><body>' . implode('', array_map(
            fn ($n) => '<a href="/alberto-hosen-46914' . $n . '">Alberto</a>',
            range(0, 7),
        )) . '</body></html>';
    }

    private function nextJsProductPage(string $id, string $article): string
    {
        return <<<HTML
            <html><head>
            <meta property="og:title" content="Alberto, Jeans Pipe, indigo" />
            <meta property="og:image" content="https://cdn.test/pimages/{$id}_norm.jpg" />
            </head><body>
                <img alt="Alberto Regular Fit Pipe {$article}/849 Image 0" src="https://cdn.test/pimages/{$id}_norm.jpg">
                <img alt="Alberto Regular Fit Pipe {$article}/849 Image 1" src="https://cdn.test/pimages/{$id}_norm2.jpg">
                <img alt="Alberto Regular Fit Pipe {$article}/849 Image 2" src="https://cdn.test/pimages/{$id}_norm3.jpg">
                <!-- the recommendation row, which is other people's products -->
                <img src="https://cdn.test/pimages/999111_suche.jpg">
                <img src="https://cdn.test/pimages/999222_suche.jpg">
            </body></html>
            HTML;
    }

    /**
     * A page in the shape the rewrite was built against: schema.org naming one
     * image, a gallery of three for the colour being viewed, three more for
     * another colour, and a recommendation carousel — all in one document.
     */
    private function commerceCloudProductPage(string $colour = '02710060'): string
    {
        $cdn = 'https://shop.test/dw/image/v2/PRD/on/demandware.static/-/Sites-master-catalog/default/dw1/images/large';
        $own = 'https://shop.test/on/demandware.static/-/Sites-master-catalog/default/dw1/images/large';

        return <<<HTML
            <html><head>
            <meta property="og:image" content="{$own}/aaa_11_{$colour}XX1.jpg" />
            <script type="application/ld+json">
                {"@context":"https://schema.org/","@type":"Product","name":"Agello",
                 "image":"{$own}/aaa_11_{$colour}XX1.jpg"}
            </script>
            </head><body>
                <img class="d-block img-fluid" src="{$cdn}/aaa_11_{$colour}XX1.jpg?sw=500">
                <img class="d-block img-fluid" src="{$cdn}/bbb_22_{$colour}XX2.jpg?sw=500">
                <img class="d-block img-fluid" src="{$cdn}/ccc_33_{$colour}XX3.jpg?sw=500">

                <!-- another colour of the same style -->
                <img src="{$cdn}/ddd_44_02718888XX1.jpg?sw=500">
                <img src="{$cdn}/eee_55_02718888XX2.jpg?sw=500">

                <!-- you may also like -->
                <img src="{$cdn}/fff_66_02719999XX1.jpg?sw=500">
                <img src="{$cdn}/ggg_77_02719999XX2.jpg?sw=500">
            </body></html>
            HTML;
    }

// ── Storefronts that differ by country ───────────────────────────────────

    /**
     * The country in the address has to survive, because the server cannot
     * work it out for itself.
     */
    public function test_a_storefront_prefix_is_kept_and_a_page_path_is_not(): void
    {
        $scraper = app(ProductImageScraper::class);

        // A country prefix is where the catalogue lives, so searching happens
        // under it.
        $this->assertSame('https://luisaspagnoli.com/en/qa', $scraper->normaliseSite('luisaspagnoli.com/en/qa'));
        $this->assertSame('https://www.luisaspagnoli.com/en/qa', $scraper->normaliseSite('https://www.luisaspagnoli.com/en/qa/'));
        $this->assertSame('https://shop.test/uk', $scraper->normaliseSite('shop.test/uk'));

        // A page inside the catalogue is not, and searching under it would 404.
        $this->assertSame('https://shop.test', $scraper->normaliseSite('shop.test/collections/all?page=2'));
        $this->assertSame('https://shop.test', $scraper->normaliseSite('https://shop.test/products/silk-scarf'));
        $this->assertSame('https://shop.test', $scraper->normaliseSite('shop.test'));
    }

    public function test_links_from_the_root_are_not_stacked_onto_the_country_prefix(): void
    {
        Http::fake([
            'shop.test/en/qa/search/suggest.json*' => Http::response('', 404),
            'shop.test/en/qa/search?*' => Http::response(
                '<div data-pid="542849_x"><a href="/en/qa/542849_x.html">Agello</a></div>'
            ),
            '*' => Http::response('', 404),
        ]);

        $found = app(ProductImageScraper::class)->findProducts('https://shop.test/en/qa', '542849');

        // Not https://shop.test/en/qa/en/qa/542849_x.html, which is what
        // resolving a root-relative link against the prefixed base would give.
        $this->assertSame('https://shop.test/en/qa/542849_x.html', $found[0]['url']);
    }

    /**
     * The failure that sent this back a second time: from a server in another
     * country the site answered every barcode with nothing, because it was
     * quietly redirecting the search to its US storefront.
     */
    public function test_a_site_that_redirects_the_search_elsewhere_says_so_instead_of_reporting_a_thousand_misses(): void
    {
        Http::fake([
            'shop.test/search*' => Http::response('<html>US homepage</html>', 200, [
                'X-Guzzle-Redirect-History' => 'https://shop.test/en/us',
            ]),
            '*' => Http::response('', 404),
        ]);

        $session = BarcodeImageSession::create([
            'user_id'      => $this->operator()->id,
            'site_url'     => 'https://shop.test',
            'status'       => 'pending',
            'raw_barcodes' => "542849AGELLO\n543309\n543164",
        ]);

        app(RunBarcodeImageDownloadJob::class, ['sessionId' => $session->id])
            ->handle(app(ProductImageScraper::class));

        $session->refresh();

        $this->assertSame('failed', $session->status);
        $this->assertStringContainsString('https://shop.test/en/us', (string) $session->error_message);
        $this->assertStringContainsString('including the country', (string) $session->error_message);

        // And it stopped before working through the list, rather than marking
        // three good barcodes as missing.
        $this->assertSame(0, $session->items()->count());
    }

    public function test_a_site_that_stays_where_it_was_asked_is_not_flagged(): void
    {
        Http::fake([
            'shop.test/en/qa/search*' => Http::response('<html>results</html>', 200, [
                // Redirected, but the question came along — the site was
                // tidying the address, not refusing to answer.
                'X-Guzzle-Redirect-History' => 'https://shop.test/en/qa/search/?q=barcode-probe&lang=en_QA',
            ]),
            '*' => Http::response('', 404),
        ]);

        $this->assertNull(app(ProductImageScraper::class)->searchRedirectsTo('https://shop.test/en/qa'));
    }

// ── Who sees which runs ──────────────────────────────────────────────────

    private function superAdmin(): User
    {
        return User::create([
            'name' => 'Ops Lead', 'email' => 'ops@example.test',
            'password' => 'password', 'is_active' => true, 'is_super_admin' => true,
        ]);
    }

    public function test_a_super_admin_sees_everyones_runs_with_the_name_beside_each(): void
    {
        BarcodeImageSession::create([
            'user_id' => $this->operator()->id, 'site_url' => 'https://shop.test',
            'status' => 'completed', 'name' => 'Autumn drop',
        ]);

        $page = $this->actingAs($this->superAdmin())
            ->get(route('barcode-images.history'))
            ->assertOk();

        $page->assertSee('Started by')
             ->assertSee('Image Grabber')   // whose run it was
             ->assertSee('Autumn drop');
    }

    public function test_an_ordinary_user_sees_only_their_own_runs_and_no_name_column(): void
    {
        BarcodeImageSession::create([
            'user_id' => $this->superAdmin()->id, 'site_url' => 'https://shop.test',
            'status' => 'completed', 'name' => 'Somebody elses run',
        ]);

        $page = $this->actingAs($this->operator())
            ->get(route('barcode-images.history'))
            ->assertOk();

        // The column would repeat one name down the page, and the other
        // person's run is not theirs to read.
        $page->assertDontSee('Started by')
             ->assertDontSee('Somebody elses run');
    }

    public function test_a_super_admin_can_open_and_delete_a_run_that_is_not_theirs(): void
    {
        $session = BarcodeImageSession::create([
            'user_id' => $this->operator()->id, 'site_url' => 'https://shop.test', 'status' => 'completed',
        ]);

        $folder = $session->folderFor('8806094582161');
        mkdir($folder, 0755, true);
        file_put_contents("{$folder}/8806094582161-1.png", $this->onePixelPng());
        $directory = $session->directory();

        $admin = $this->superAdmin();

        // Whose run it is appears on the screen, because Delete is right there.
        $this->actingAs($admin)
            ->get(route('barcode-images.show', $session))
            ->assertOk()
            ->assertSee('Image Grabber');

        $this->actingAs($admin)->delete(route('barcode-images.destroy', $session))->assertRedirect();

        $this->assertDirectoryDoesNotExist($directory);
        $this->assertSame(0, BarcodeImageSession::count());
    }

// ── Pushing a finished grab to Shopify ───────────────────────────────────

    private function storeFor(User $user): Store
    {
        $store = Store::create([
            'name' => 'Destination Site', 'shopify_domain' => 'dest.myshopify.com', 'is_active' => true,
        ]);

        $user->stores()->attach($store->id);

        return $store;
    }

    /** A finished grab with one barcode and two pictures on disk. */
    private function grabReadyToPush(User $user): BarcodeImageSession
    {
        $session = BarcodeImageSession::create([
            'user_id'           => $user->id,
            'site_url'          => 'https://shop.test/en/qa',
            'status'            => 'completed',
            'total_barcodes'    => 1,
            'processed'         => 1,
            'found_count'       => 1,
            'images_downloaded' => 2,
        ]);

        BarcodeImageItem::create([
            'barcode_image_session_id' => $session->id,
            'barcode'                  => '542849AGELLO',
            'status'                   => 'found',
            'image_count'              => 2,
        ]);

        $folder = $session->folderFor('542849AGELLO');
        mkdir($folder, 0755, true);
        file_put_contents("{$folder}/542849AGELLO-1.jpg", $this->onePixelPng());
        file_put_contents("{$folder}/542849AGELLO-2.jpg", $this->onePixelPng());

        return $session;
    }

    public function test_the_push_form_offers_the_websites_and_both_ways_of_matching(): void
    {
        $user = $this->operator();
        $this->storeFor($user);
        $session = $this->grabReadyToPush($user);

        $page = $this->actingAs($user)->get(route('barcode-images.show', $session))->assertOk();

        $page->assertSee('Push to Shopify')
             ->assertSee('name="store_id"', false)
             ->assertSee('Destination Site')
             ->assertSee('value="sku_barcode"', false)
             ->assertSee('value="style_code"', false);

        $session->deleteFiles();
    }

    public function test_starting_a_push_records_the_website_and_the_matching_it_was_asked_for(): void
    {
        Bus::fake();

        $user    = $this->operator();
        $store   = $this->storeFor($user);
        $session = $this->grabReadyToPush($user);

        $this->actingAs($user)
            ->post(route('barcode-images.push', $session), [
                'store_id'      => $store->id,
                'matching_mode' => 'style_code',
            ])
            ->assertRedirect();

        $session->refresh();

        $this->assertSame('pending', $session->push_status);
        $this->assertSame($store->id, $session->push_store_id);
        $this->assertSame('style_code', $session->push_matching_mode);

        Bus::assertDispatched(PushBarcodeImagesJob::class);

        $session->deleteFiles();
    }

    public function test_a_website_the_person_has_no_access_to_cannot_be_pushed_to(): void
    {
        Bus::fake();

        $user    = $this->operator();
        $session = $this->grabReadyToPush($user);

        // A store that exists, but is not theirs — the id is typed into a form,
        // so the check cannot rely on the dropdown only listing their own.
        $theirs = Store::create([
            'name' => 'Someone Elses Shop', 'shopify_domain' => 'other.myshopify.com', 'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('barcode-images.push', $session), [
                'store_id'      => $theirs->id,
                'matching_mode' => 'sku_barcode',
            ])
            ->assertNotFound();

        Bus::assertNothingDispatched();

        $session->deleteFiles();
    }

    public function test_a_second_push_is_refused_while_one_is_still_running(): void
    {
        Bus::fake();

        $user    = $this->operator();
        $store   = $this->storeFor($user);
        $session = $this->grabReadyToPush($user);

        $session->update(['push_status' => 'pushing', 'push_store_id' => $store->id]);

        $this->actingAs($user)
            ->post(route('barcode-images.push', $session), [
                'store_id'      => $store->id,
                'matching_mode' => 'sku_barcode',
            ])
            ->assertSessionHasErrors('store_id');

        // Two jobs uploading the same pictures to the same product would
        // duplicate the gallery.
        Bus::assertNothingDispatched();

        $session->deleteFiles();
    }

    public function test_a_grab_with_nothing_downloaded_has_nothing_to_push(): void
    {
        Bus::fake();

        $user  = $this->operator();
        $store = $this->storeFor($user);

        $session = BarcodeImageSession::create([
            'user_id' => $user->id, 'site_url' => 'https://shop.test',
            'status' => 'completed', 'images_downloaded' => 0,
        ]);

        $this->actingAs($user)
            ->post(route('barcode-images.push', $session), [
                'store_id'      => $store->id,
                'matching_mode' => 'sku_barcode',
            ])
            ->assertSessionHasErrors('store_id');

        Bus::assertNothingDispatched();
    }

// ── What the push actually sends ─────────────────────────────────────────

    /** Runs the push with a stand-in Shopify, and reports what it was handed. */
    private function pushWith(BarcodeImageSession $session, ShopifyService $shopify): void
    {
        $job = new class($session->id, $shopify) extends PushBarcodeImagesJob {
            public function __construct(int $sessionId, private $fake)
            {
                parent::__construct($sessionId);
            }

            protected function shopifyFor(\App\Models\Store $store): ShopifyService
            {
                return $this->fake;
            }
        };

        $job->handle();
    }

    public function test_every_picture_of_a_barcode_goes_to_the_matched_product_with_only_the_first_on_the_variant(): void
    {
        $user    = $this->operator();
        $store   = $this->storeFor($user);
        $session = $this->grabReadyToPush($user);

        $session->update(['push_store_id' => $store->id, 'push_matching_mode' => 'sku_barcode', 'push_status' => 'pending']);

        $uploads = [];
        $shopify = Mockery::mock(ShopifyService::class);

        $shopify->shouldReceive('findVariantsBySkuOrBarcode')->once()->with('542849AGELLO', true)
            ->andReturn([['product_id' => 'gid://P/1', 'variant_id' => 'gid://V/9', 'product_title' => 'Agello']]);

        $shopify->shouldReceive('uploadImageToProduct')
            ->andReturnUsing(function ($productId, $content, $filename, $alt, $variantId = null) use (&$uploads) {
                $uploads[] = ['file' => $filename, 'variant' => $variantId];
                return 'gid://Image/' . count($uploads);
            });

        $this->pushWith($session, $shopify);

        $session->refresh();

        $this->assertSame('completed', $session->push_status);
        $this->assertSame(1, $session->push_pushed);
        $this->assertSame(0, $session->push_failed);

        // In the order they were numbered when downloaded, and the variant is
        // bound once — passing it on every upload leaves whichever finished
        // last showing on the variant.
        $this->assertSame([
            ['file' => '542849AGELLO-1.jpg', 'variant' => 'gid://V/9'],
            ['file' => '542849AGELLO-2.jpg', 'variant' => null],
        ], $uploads);

        $item = $session->items()->sole();
        $this->assertSame('pushed', $item->push_status);
        $this->assertSame('Agello', $item->shopify_product_title);
        $this->assertSame(2, $item->pushed_images);

        $session->deleteFiles();
    }

    public function test_style_code_matching_sends_to_the_gallery_and_binds_no_variant(): void
    {
        $user    = $this->operator();
        $store   = $this->storeFor($user);
        $session = $this->grabReadyToPush($user);

        $session->update(['push_store_id' => $store->id, 'push_matching_mode' => 'style_code', 'push_status' => 'pending']);

        $variants = [];
        $shopify  = Mockery::mock(ShopifyService::class);

        $shopify->shouldReceive('findProductsByStyleCode')->once()->with('542849AGELLO', true)
            ->andReturn([['product_id' => 'gid://P/1', 'product_title' => 'Agello']]);

        $shopify->shouldReceive('uploadImageToProduct')
            ->andReturnUsing(function ($productId, $content, $filename, $alt, $variantId = null) use (&$variants) {
                $variants[] = $variantId;
                return 'gid://Image/1';
            });

        $this->pushWith($session, $shopify);

        // A style code names a product, not a variant, so there is nothing to
        // bind the picture to.
        $this->assertSame([null, null], $variants);
        $this->assertSame(1, $session->refresh()->push_pushed);

        $session->deleteFiles();
    }

    public function test_a_barcode_on_two_different_products_is_skipped_rather_than_guessed_at(): void
    {
        $user    = $this->operator();
        $store   = $this->storeFor($user);
        $session = $this->grabReadyToPush($user);

        $session->update(['push_store_id' => $store->id, 'push_matching_mode' => 'sku_barcode', 'push_status' => 'pending']);

        $shopify = Mockery::mock(ShopifyService::class);
        $shopify->shouldReceive('findVariantsBySkuOrBarcode')->andReturn([
            ['product_id' => 'gid://P/1', 'variant_id' => 'gid://V/1'],
            ['product_id' => 'gid://P/2', 'variant_id' => 'gid://V/2'],
        ]);

        // Sending pictures to the wrong product is worse than sending none.
        $shopify->shouldNotReceive('uploadImageToProduct');

        $this->pushWith($session, $shopify);

        $item = $session->items()->sole();

        $this->assertSame('skipped', $item->push_status);
        $this->assertStringContainsString('2 different products', (string) $item->push_message);
        $this->assertSame(1, $session->refresh()->push_failed);

        $session->deleteFiles();
    }

    public function test_a_barcode_no_product_matches_is_reported_and_the_rest_carry_on(): void
    {
        $user    = $this->operator();
        $store   = $this->storeFor($user);
        $session = $this->grabReadyToPush($user);

        $session->update(['push_store_id' => $store->id, 'push_matching_mode' => 'sku_barcode', 'push_status' => 'pending']);

        $shopify = Mockery::mock(ShopifyService::class);
        $shopify->shouldReceive('findVariantsBySkuOrBarcode')->andReturn([]);
        $shopify->shouldNotReceive('uploadImageToProduct');

        $this->pushWith($session, $shopify);

        $session->refresh();
        $item = $session->items()->sole();

        $this->assertSame('skipped', $item->push_status);
        $this->assertStringContainsString('No product on that website matched', (string) $item->push_message);
        $this->assertSame('completed', $session->push_status);
        $this->assertSame(0, $session->push_pushed);
        $this->assertSame(1, $session->push_failed);

        $session->deleteFiles();
    }

    public function test_barcodes_that_found_no_pictures_are_not_counted_in_the_push(): void
    {
        $user    = $this->operator();
        $store   = $this->storeFor($user);
        $session = $this->grabReadyToPush($user);

        // A miss from the grab: nothing to send, so it must not appear in the
        // progress bar as work still to do.
        BarcodeImageItem::create([
            'barcode_image_session_id' => $session->id,
            'barcode'                  => '000000NOTHING',
            'status'                   => 'not_found',
            'image_count'              => 0,
        ]);

        $session->update(['push_store_id' => $store->id, 'push_matching_mode' => 'sku_barcode', 'push_status' => 'pending']);

        $shopify = Mockery::mock(ShopifyService::class);
        $shopify->shouldReceive('findVariantsBySkuOrBarcode')
            ->andReturn([['product_id' => 'gid://P/1', 'variant_id' => 'gid://V/9']]);
        $shopify->shouldReceive('uploadImageToProduct')->andReturn('gid://Image/1');

        $this->pushWith($session, $shopify);

        $this->assertSame(1, $session->refresh()->push_total);

        $session->deleteFiles();
    }

// ── Sites that cannot be read at all ─────────────────────────────────────

    /**
     * Herrenausstatter, which sent this back a third time.
     *
     * Its /search returns 800kB of identical markup whatever is searched for,
     * because the results arrive afterwards from an API the page calls itself.
     * Sixty-three barcodes all came back "not found", which reads as a bad
     * list rather than an unreadable site.
     */
    public function test_a_site_that_builds_its_results_in_the_browser_is_named_as_such(): void
    {
        Http::fake([
            'shop.test/robots.txt' => Http::response('User-agent: *' . "\n" . 'Allow: /'),

            // The same storefront offered back whatever was asked for — a
            // page full of products that has plainly not read the question.
            'shop.test/search*' => Http::response(implode('', array_map(
                fn ($n) => '<a href="/alberto-hosen-46914' . $n . '">Alberto</a>',
                range(0, 7),
            )) . '<a href="/hemden">Hemden</a>'),
            '*' => Http::response('', 404),
        ]);

        $session = BarcodeImageSession::create([
            'user_id'      => $this->operator()->id,
            'site_url'     => 'https://shop.test',
            'status'       => 'pending',
            'raw_barcodes' => "10152 55511\n80020 161705 200",
        ]);

        app(RunBarcodeImageDownloadJob::class, ['sessionId' => $session->id])
            ->handle(app(ProductImageScraper::class));

        $session->refresh();

        $this->assertSame('failed', $session->status);
        $this->assertStringContainsString('builds its search results in the browser', (string) $session->error_message);

        // Stopped rather than marking every barcode on the list as missing.
        $this->assertSame(0, $session->items()->count());
    }

    public function test_a_site_that_simply_has_no_match_is_still_run_normally(): void
    {
        Http::fake([
            'shop.test/robots.txt' => Http::response('User-agent: *'),

            // An honest empty answer both times. Two empty answers mean the
            // barcode is not stocked, not that the site cannot be read.
            'shop.test/search*'    => Http::response('<p>Nothing found.</p>'),
            '*' => Http::response('', 404),
        ]);

        $session = BarcodeImageSession::create([
            'user_id'      => $this->operator()->id,
            'site_url'     => 'https://shop.test',
            'status'       => 'pending',
            'raw_barcodes' => '0000000000000',
        ]);

        app(RunBarcodeImageDownloadJob::class, ['sessionId' => $session->id])
            ->handle(app(ProductImageScraper::class));

        $session->refresh();

        $this->assertSame('completed', $session->status);
        $this->assertSame('not_found', $session->items()->sole()->status);
    }

    // ── Reading other people's websites politely ─────────────────────────────

    public function test_a_stated_crawl_delay_is_read_from_robots_txt(): void
    {
        Http::fake([
            'shop.test/robots.txt' => Http::response(
                "User-agent: Googlebot\nCrawl-delay: 1\n\nUser-Agent: *\nAllow: /\nCrawl-delay: 15\n"
            ),
        ]);

        // The catch-all group's rule, not Googlebot's: this reader does not
        // claim a name of its own.
        $this->assertSame(15.0, app(ProductImageScraper::class)->crawlDelaySeconds('https://shop.test/en/qa'));
    }

    public function test_a_site_asking_for_nothing_or_for_far_too_much_is_handled(): void
    {
        $scraper = app(ProductImageScraper::class);

        Http::fake(['shop.test/robots.txt' => Http::response("User-agent: *\nAllow: /")]);
        $this->assertSame(0.0, $scraper->crawlDelaySeconds('https://shop.test'));

        Http::fake(['slow.test/robots.txt' => Http::response("User-agent: *\nCrawl-delay: 600")]);

        // Capped: ten minutes a barcode is a run that never visibly finishes,
        // and at that point the site should not be read in bulk at all.
        $this->assertSame(20.0, $scraper->crawlDelaySeconds('https://slow.test'));

        Http::fake(['none.test/robots.txt' => Http::response('', 404)]);
        $this->assertSame(0.0, $scraper->crawlDelaySeconds('https://none.test'));
    }

    // ── A barcode is a folder name, and folder names are dangerous ───────────

    public function test_a_barcode_cannot_climb_out_of_its_own_run_folder(): void
    {
        $session = BarcodeImageSession::create([
            'user_id'  => $this->operator()->id,
            'site_url' => 'https://shop.test',
            'status'   => 'completed',
        ]);

        $folder = $session->folderFor('../../../etc/passwd');

        $this->assertStringStartsWith($session->directory() . '/', $folder);
        $this->assertStringNotContainsString('..', $folder);
    }
}
