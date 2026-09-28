<?php

namespace Tests\Feature;

use App\Jobs\RunBarcodeImageDownloadJob;
use App\Models\BarcodeImageSession;
use App\Models\User;
use App\Services\ProductImageScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
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
            'shop.test/search?*' => Http::response(
                '<a class="card" href="/collections/all/products/leather-belt">Leather Belt</a>'
            ),
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
