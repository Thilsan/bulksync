<?php

namespace Tests\Feature;

use App\Http\Controllers\CollectionContentController;
use App\Jobs\GenerateCollectionContentJob;
use App\Models\CollectionContentItem;
use App\Models\CollectionContentSession;
use App\Models\Store;
use App\Models\User;
use App\Services\GeminiService;
use App\Services\SearchConsoleService;
use App\Services\ShopifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * SEO content for collection pages — the ones that rank for a category rather
 * than a model name.
 *
 * A collection has no SKU and no photograph, which is the whole reason it needs
 * its own path: every way into the product generator is the wrong shape for it.
 */
class CollectionContentTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        // is_active matters: an inactive user is bounced to the login screen
        // and every assertion below then reads as a routing failure.
        return User::create([
            'name'           => 'Content Writer',
            'email'          => 'collections@example.test',
            'password'       => 'password',
            'is_active'      => true,
            'perm_seo_audit' => true,
        ]);
    }

    private function storeFor(User $user, ?string $site = null): Store
    {
        return Store::create([
            'name'                 => 'Test Store',
            'shopify_domain'       => 'test.myshopify.com',
            'shopify_access_token' => 'shpat_test',
            'gsc_site_url'         => $site,
            'user_id'              => $user->id,
        ]);
    }

    private function sessionWith(User $user, Store $store, array $collectionIds = ['100']): CollectionContentSession
    {
        $session = CollectionContentSession::create([
            'user_id'     => $user->id,
            'store_id'    => $store->id,
            'status'      => 'pending',
            'total_items' => count($collectionIds),
        ]);

        foreach ($collectionIds as $id) {
            CollectionContentItem::create([
                'session_id'    => $session->id,
                'collection_id' => (string) $id,
                'status'        => 'pending',
            ]);
        }

        return $session;
    }

    /**
     * Routes the controller's Shopify calls to a fake.
     *
     * The controller builds its own ShopifyService, so binding the service in
     * the container would not be reached — the controller itself is what has to
     * be swapped.
     */
    private function fakeShopify(ShopifyService $shopify): void
    {
        $this->app->bind(CollectionContentController::class, fn () => new class($shopify) extends CollectionContentController {
            public function __construct(private $fake) {}

            protected function shopifyFor(?Store $store): ShopifyService
            {
                return $this->fake;
            }
        });
    }

    /** Runs the generation job with Shopify, Gemini and Google all faked out. */
    private function generate(CollectionContentSession $session, ShopifyService $shopify, GeminiService $gemini): void
    {
        $job = new class($session->id, $shopify) extends GenerateCollectionContentJob {
            public function __construct(int $id, private $shopify)
            {
                parent::__construct($id);
            }

            protected function shopifyFor(?Store $store): ShopifyService
            {
                return $this->shopify;
            }

            protected function searchConsole(): SearchConsoleService
            {
                return new SearchConsoleService(fn () => []);
            }
        };

        $job->handle($gemini);
    }

    public function test_the_products_inside_a_collection_are_what_it_is_written_from(): void
    {
        $user    = $this->operator();
        $store   = $this->storeFor($user);
        $session = $this->sessionWith($user, $store);

        $shopify = Mockery::mock(ShopifyService::class);
        $shopify->shouldReceive('getCollectionForContent')->once()->with('100')->andReturn([
            'id'               => '100',
            'title'            => 'Cabin',
            'handle'           => 'cabin',
            'description'      => '',
            'meta_title'       => null,
            'meta_description' => null,
            'products'         => [
                ['title' => 'Hedgren Inner City Backpack', 'type' => 'Backpack', 'vendor' => 'Hedgren'],
                ['title' => 'Samsonite Cabin Spinner 55',  'type' => 'Suitcase', 'vendor' => 'Samsonite'],
            ],
        ]);

        $gemini = Mockery::mock(GeminiService::class);
        $gemini->shouldReceive('generateForCollection')
            ->once()
            // A one-word collection name says almost nothing; what it holds is
            // the real evidence, so it has to reach the model.
            ->withArgs(function ($title, $description, $products) {
                return $title === 'Cabin'
                    && collect($products)->pluck('title')->contains('Samsonite Cabin Spinner 55');
            })
            ->andReturn([
                'description'      => '<p>Cabin luggage and carry-on bags.</p>',
                'meta_title'       => 'Cabin Luggage and Carry-On Bags in Qatar',
                'meta_description' => 'Cabin suitcases and backpacks sized for airline lockers, at Test Store in Qatar.',
            ]);

        $this->generate($session, $shopify, $gemini);

        $item = $session->fresh()->items()->sole();

        $this->assertSame('done', $item->status);
        $this->assertSame('Cabin Luggage and Carry-On Bags in Qatar', $item->ai_meta_title);
        $this->assertSame('ready', $session->fresh()->status);
    }

    public function test_what_was_there_before_is_kept_so_the_change_can_be_seen(): void
    {
        $user    = $this->operator();
        $store   = $this->storeFor($user);
        $session = $this->sessionWith($user, $store);

        $shopify = Mockery::mock(ShopifyService::class);
        $shopify->shouldReceive('getCollectionForContent')->andReturn([
            'id'               => '100',
            'title'            => 'Cabin',
            'handle'           => 'cabin',
            'description'      => 'An old hand-written description.',
            'meta_title'       => 'Old meta title',
            'meta_description' => null,
            'products'         => [],
        ]);

        $gemini = Mockery::mock(GeminiService::class);
        $gemini->shouldReceive('generateForCollection')->andReturn([
            'description'      => '<p>New copy.</p>',
            'meta_title'       => 'New meta title',
            'meta_description' => 'New meta description.',
        ]);

        $this->generate($session, $shopify, $gemini);

        $item = $session->items()->sole();

        // The review screen shows the change, not just the replacement.
        $this->assertSame('Old meta title', $item->existing_meta_title);
        $this->assertSame('An old hand-written description.', $item->existing_description);
        $this->assertSame('New meta title', $item->ai_meta_title);
    }

    public function test_a_collection_deleted_since_the_run_started_fails_only_itself(): void
    {
        $user    = $this->operator();
        $store   = $this->storeFor($user);
        $session = $this->sessionWith($user, $store, ['100', '101']);

        $shopify = Mockery::mock(ShopifyService::class);
        $shopify->shouldReceive('getCollectionForContent')->with('100')->andReturnNull();
        $shopify->shouldReceive('getCollectionForContent')->with('101')->andReturn([
            'id' => '101', 'title' => 'Hold', 'handle' => 'hold',
            'description' => '', 'meta_title' => null, 'meta_description' => null, 'products' => [],
        ]);

        $gemini = Mockery::mock(GeminiService::class);
        $gemini->shouldReceive('generateForCollection')->once()->andReturn([
            'description' => '<p>Hold luggage.</p>', 'meta_title' => 'Hold Luggage in Qatar', 'meta_description' => 'Large suitcases.',
        ]);

        $this->generate($session, $shopify, $gemini);

        $this->assertSame('failed', $session->items()->where('collection_id', '100')->first()->status);
        $this->assertSame('done', $session->items()->where('collection_id', '101')->first()->status);
        // One missing collection must not cost the rest of the run.
        $this->assertSame('ready', $session->fresh()->status);
    }

    public function test_only_ticked_collections_reach_shopify_but_every_edit_is_saved(): void
    {
        $user  = $this->operator();
        $store = $this->storeFor($user);

        $session = $this->sessionWith($user, $store, ['100', '101']);
        $session->items->each->update(['status' => 'done', 'ai_meta_title' => 'Generated']);

        [$ticked, $untouched] = $session->items()->orderBy('id')->get()->all();

        $shopify = Mockery::mock(ShopifyService::class);
        $shopify->shouldReceive('updateCollectionContent')
            ->once()
            ->with('100', '', 'Edited by hand', Mockery::any());

        $this->fakeShopify($shopify);

        $this->actingAs($user)->post(route('collection-content.push', $session), [
            'confirmed'             => [$ticked->id],
            'meta_title'            => [$ticked->id => 'Edited by hand', $untouched->id => 'Also edited'],
        ]);

        $this->assertSame('pushed', $ticked->fresh()->status);

        // Reviewing half the list today should not lose that work by not
        // pushing it.
        $this->assertSame('Also edited', $untouched->fresh()->ai_meta_title);
        $this->assertSame('done', $untouched->fresh()->status);
    }

    public function test_the_description_is_only_replaced_when_asked_for(): void
    {
        $user  = $this->operator();
        $store = $this->storeFor($user);

        $session = $this->sessionWith($user, $store);
        $item    = $session->items()->sole();
        $item->update(['status' => 'done', 'ai_description' => '<p>New copy.</p>', 'ai_meta_title' => 'A title']);

        $shopify = Mockery::mock(ShopifyService::class);
        // Empty description argument: replacing body copy somebody wrote by
        // hand has to be a deliberate tick, not a side effect of pushing meta.
        $shopify->shouldReceive('updateCollectionContent')->once()->with('100', '', 'A title', Mockery::any());

        $this->fakeShopify($shopify);

        $this->actingAs($user)->post(route('collection-content.push', $session), [
            'confirmed' => [$item->id],
        ]);

        $this->assertSame('pushed', $item->fresh()->status);
    }

    public function test_another_users_session_is_not_reachable(): void
    {
        $owner   = $this->operator();
        $store   = $this->storeFor($owner);
        $session = $this->sessionWith($owner, $store);

        $intruder = User::create([
            'name' => 'Someone Else', 'email' => 'other-coll@example.test',
            'password' => 'password', 'is_active' => true, 'perm_seo_audit' => true,
        ]);

        $this->actingAs($intruder)->get(route('collection-content.show', $session))->assertForbidden();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
