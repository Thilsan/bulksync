<?php

namespace Tests\Feature;

use App\Jobs\GenerateAiContentJob;
use App\Models\AiContentSession;
use App\Models\ProductRequest;
use App\Models\ProductRequestActivity;
use App\Models\Store;
use App\Models\User;
use App\Notifications\ProductRequestAssigned;
use App\Notifications\ProductRequestBalanceMapped;
use App\Notifications\ProductRequestCommented;
use App\Notifications\ProductRequestHandedOff;
use App\Notifications\ProductRequestReminder;
use App\Notifications\ProductRequestHoldChanged;
use App\Notifications\ProductRequestStatusChanged;
use App\Services\ProductRequestWorkflow;
use App\Services\SkuMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProductRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Static, so it would otherwise carry SKUs from one test into the next —
        // and the SKU names repeat across these tests.
        FakeMappingShopify::$present = [];
    }

    private function brandManager(): User
    {
        return User::create([
            'name'                 => 'Brand Manager',
            'email'                => 'brand@example.test',
            'password'             => 'password',
            'is_active'            => true,
            'perm_product_request' => true,
            'pcr_role'             => 'brand_manager',
        ]);
    }

    /**
     * Somebody who sees the ordinary screens.
     *
     * The brand manager's dashboard and task list are deliberately narrowed to
     * the two things asked of them, so a test about the shared screens has to be
     * somebody else — otherwise it is testing the wrong page.
     */
    private function ecommerceUser(string $email = 'ecom-user@example.test'): User
    {
        return User::create([
            'name'                 => 'E-Commerce User',
            'email'                => $email,
            'password'             => 'password',
            'is_active'            => true,
            'perm_product_request' => true,
            'pcr_role'             => 'ecommerce',
        ]);
    }

    /** Blue Salon — the one website whose SKUs go through Cegid mapping. */
    private function mappingSite(): Store
    {
        return Store::create([
            'name'                 => 'Bluesalon Website',
            'shopify_domain'       => 'bluesalon.myshopify.com',
            'is_active'            => true,
            'requires_sku_mapping' => true,
        ]);
    }

    /** Any other website — no Cegid, so no mapping stage. */
    private function plainSite(): Store
    {
        return Store::create([
            'name'                 => 'Other Website',
            'shopify_domain'       => 'other.myshopify.com',
            'is_active'            => false,
            'requires_sku_mapping' => false,
        ]);
    }

    /** Assign a role the way the app does — ownership lives in the assignments table. */
    private function assign(ProductRequest $request, string $role, ?User $user): ProductRequest
    {
        app(ProductRequestWorkflow::class)->assignRole(
            request: $request,
            field:   $role,
            userId:  $user?->id,
            actor:   $request->user,
            notify:  false,
        );

        return $request->refresh();
    }

    /** Reached SKU Verified, then moved on by itself to $next. */
    private function assertPassedVerified(ProductRequest $request, string $next): void
    {
        $this->assertSame($next, $request->fresh()->status);
        $this->assertTrue(ProductRequestActivity::where('product_request_id', $request->id)
            ->where('to_status', ProductRequest::SKU_VERIFIED)->exists());
    }

    /** Who currently holds a role. */
    private function ownerId(ProductRequest $request, string $role): ?int
    {
        return $request->ownerFor($role)?->id;
    }

    private function submitFor(User $user, Store $store, string $skus = 'X-1'): ProductRequest
    {
        $user->stores()->syncWithoutDetaching([$store->id]);

        $this->actingAs($user)->post(route('product-requests.store'), [
            'store_id'                  => $store->id,
            'request_type'              => 'new_brand',
            'brand'                     => 'Samsonite',
            'category'                  => 'Luggage',
            'skus'                      => $skus,
            'online_launch_date'        => now()->addDays(18)->format('Y-m-d H:i'),
            'image_source'              => ProductRequest::IMG_PHOTOSHOOT,
            'use_ai_content'            => 1,
            'priority'                  => 'high',
        ]);

        return ProductRequest::latest('id')->first();
    }

    // ── The list reads newest first ──────────────────────────────────────────

    /**
     * The request created most recently is on top, whether it came from the
     * sheet or was raised by hand. A hand-raised one has no sheet number but
     * must not sink below older numbered rows.
     */
    public function test_the_list_shows_the_newest_request_first(): void
    {
        Notification::fake();
        Queue::fake();

        $user  = $this->brandManager();
        $store = $this->plainSite();

        // Sheet numbers deliberately disagree with creation order, so passing
        // cannot be an accident of the old sheet-number sort.
        $this->travelTo(now()->subHours(3));
        $oldest = $this->submitFor($user, $store);
        $oldest->update(['sheet_request_no' => 100, 'brand' => 'AUBADE']);

        $this->travelTo(now()->addHour());
        $middle = $this->submitFor($user, $store);
        $middle->update(['sheet_request_no' => 12, 'brand' => 'AORA ATHLETICS']);

        $this->travelBack();
        $newest = $this->submitFor($user, $store);
        $newest->update(['brand' => 'RAISED BY HAND']);   // no sheet number

        $order = $this->actingAs($user)
            ->get(route('product-requests.list'))
            ->assertOk()
            ->viewData('requests')
            ->pluck('brand')
            ->all();

        $this->assertSame(['RAISED BY HAND', 'AORA ATHLETICS', 'AUBADE'], $order);
    }

    /** Two websites off one sheet row stay together rather than splitting up. */
    public function test_requests_sharing_a_sheet_number_stay_adjacent(): void
    {
        Notification::fake();
        Queue::fake();

        $user  = $this->brandManager();
        $store = $this->plainSite();

        $bs = $this->submitFor($user, $store);
        $bs->update(['sheet_request_no' => 170, 'brand' => 'BS COPY']);

        $other = $this->submitFor($user, $store);
        $other->update(['sheet_request_no' => 171, 'brand' => 'NEXT ROW']);

        $pg = $this->submitFor($user, $store);
        $pg->update(['sheet_request_no' => 170, 'brand' => 'PG COPY']);

        $order = $this->actingAs($user)
            ->get(route('product-requests.list'))
            ->viewData('requests')
            ->pluck('brand')
            ->all();

        $this->assertSame(['NEXT ROW', 'BS COPY', 'PG COPY'], $order);
    }

    // ── Website selection drives whether mapping applies ─────────────────────

    public function test_a_bluesalon_request_waits_for_mapping(): void
    {
        Notification::fake();

        $user    = $this->brandManager();
        $request = $this->submitFor($user, $this->mappingSite(), "123456\n123457\n123458");

        $this->assertNotNull($request);
        $this->assertMatchesRegularExpression('/^PCR-\d{4}-\d{5}$/', $request->reference);
        $this->assertSame(3, $request->skus()->count());

        // Nothing mapped yet, so it parks at Waiting for Mapping rather than
        // rejecting the submission.
        $this->assertTrue($request->requiresMapping());
        $this->assertSame(ProductRequest::WAITING_MAPPING, $request->status);
        $this->assertSame(3, $request->pending_skus);
        $this->assertContains(ProductRequest::WAITING_MAPPING, $request->displayStages());
    }

    public function test_a_non_mapping_website_skips_straight_to_sku_verified(): void
    {
        Notification::fake();

        $user    = $this->brandManager();
        $request = $this->submitFor($user, $this->plainSite(), "A-1\nA-2");

        $this->assertFalse($request->requiresMapping());
        // Verified straight away — and it stays there: none of these products
        // are on Shopify yet, and nothing after this can happen until they are.
        $this->assertSame(ProductRequest::SKU_VERIFIED, $request->fresh()->status);

        // Once they show on Shopify it moves on by itself.
        $request->skus()->update(['in_shopify' => true]);
        app(ProductRequestWorkflow::class)->autoAdvance($request->refresh());
        $this->assertPassedVerified($request, ProductRequest::AI_CONTENT);

        // The stage is absent from the stepper and can never be moved to.
        $this->assertNotContains(ProductRequest::WAITING_MAPPING, $request->displayStages());
        $this->assertNotContains(ProductRequest::WAITING_MAPPING, $request->allowedTransitions());

        // Submitted, SKU Verified, AI Content, the three image stages, Published.
        // QA is retired: the person who writes the copy checks it and publishes.
        $this->assertCount(7, $request->displayStages());
        $this->assertContains(ProductRequest::AI_CONTENT, $request->displayStages());
        $this->assertNotContains(ProductRequest::QA_REVIEW, $request->displayStages());
    }

    public function test_the_website_must_be_one_the_user_can_access(): void
    {
        $user  = $this->brandManager();
        $mine  = $this->mappingSite();
        $other = $this->plainSite();

        // Non-super-admin: only explicitly granted websites are selectable.
        $user->stores()->sync([$mine->id]);

        $this->actingAs($user)->post(route('product-requests.store'), [
            'store_id'                  => $other->id,
            'request_type'              => 'new_brand',
            'brand'                     => 'Samsonite',
            'category'                  => 'Luggage',
            'skus'                      => 'Z-1',
            'online_launch_date'        => now()->addDays(18)->format('Y-m-d H:i'),
            'image_source'              => ProductRequest::IMG_PHOTOSHOOT,
            'use_ai_content'            => 1,
            'priority'                  => 'high',
        ])->assertSessionHasErrors('store_id');

        $this->assertSame(0, ProductRequest::count());
    }

    public function test_a_website_must_be_chosen(): void
    {
        $user = $this->brandManager();
        $user->stores()->sync([$this->mappingSite()->id]);

        $this->actingAs($user)->post(route('product-requests.store'), [
            'request_type'              => 'new_brand',
            'brand'                     => 'Samsonite',
            'category'                  => 'Luggage',
            'skus'                      => 'Z-1',
            'online_launch_date'        => now()->addDays(18)->format('Y-m-d H:i'),
            'image_source'              => ProductRequest::IMG_PHOTOSHOOT,
            'use_ai_content'            => 1,
            'priority'                  => 'high',
        ])->assertSessionHasErrors('store_id');
    }

    public function test_supplier_images_must_say_where_they_are(): void
    {
        $user  = $this->brandManager();
        $store = $this->mappingSite();
        $user->stores()->sync([$store->id]);

        $base = [
            'store_id'           => $store->id,
            'request_type'       => 'new_brand',
            'brand'              => 'Samsonite',
            'category'           => 'Luggage',
            'skus'               => 'LOC-1',
            'online_launch_date' => now()->addDays(18)->format('Y-m-d H:i'),
            'use_ai_content'     => 1,
            'priority'           => 'high',
        ];

        // "The supplier sent them" is useless without saying where.
        $this->actingAs($user)->post(route('product-requests.store'),
            $base + ['image_source' => ProductRequest::IMG_SUPPLIER])
            ->assertSessionHasErrors('images_location');

        // Choosing a link means there has to be one, and it has to be a URL.
        $this->actingAs($user)->post(route('product-requests.store'),
            $base + ['image_source' => ProductRequest::IMG_SUPPLIER, 'images_location' => ProductRequest::IMAGES_AT_URL])
            ->assertSessionHasErrors('images_url');

        $this->actingAs($user)->post(route('product-requests.store'),
            $base + ['image_source' => ProductRequest::IMG_SUPPLIER,
                     'images_location' => ProductRequest::IMAGES_AT_URL, 'images_url' => 'not-a-url'])
            ->assertSessionHasErrors('images_url');

        $this->assertSame(0, ProductRequest::count());

        // A link is recorded and shown to whoever needs the files.
        $this->actingAs($user)->post(route('product-requests.store'),
            $base + ['image_source' => ProductRequest::IMG_SUPPLIER,
                     'images_location' => ProductRequest::IMAGES_AT_URL,
                     'images_url' => 'https://onedrive.example.com/folder/abc'])
            ->assertRedirect();

        $request = ProductRequest::first();

        $this->assertSame('https://onedrive.example.com/folder/abc', $request->images_url);
        $this->assertFalse($request->awaitingImageLocation());

        $this->actingAs($user)->get(route('product-requests.show', $request))
            ->assertOk()
            ->assertSee('https://onedrive.example.com/folder/abc');
    }

    public function test_pim_needs_no_link_and_a_photoshoot_needs_no_location(): void
    {
        Notification::fake();

        $user  = $this->brandManager();
        $store = $this->mappingSite();
        $user->stores()->sync([$store->id]);

        $base = [
            'store_id'           => $store->id,
            'request_type'       => 'new_brand',
            'brand'              => 'Samsonite',
            'category'           => 'Luggage',
            'skus'               => 'LOC-2',
            'online_launch_date' => now()->addDays(18)->format('Y-m-d H:i'),
            'use_ai_content'     => 1,
            'priority'           => 'high',
        ];

        // PIM: no link required.
        $this->actingAs($user)->post(route('product-requests.store'),
            $base + ['image_source' => ProductRequest::IMG_SUPPLIER, 'images_location' => ProductRequest::IMAGES_AT_PIM])
            ->assertRedirect();

        $pim = ProductRequest::first();
        $this->assertTrue($pim->imagesInPim());
        $this->assertNull($pim->images_url);
        $this->assertFalse($pim->awaitingImageLocation());
        $this->assertSame('Already in the Brand PIM', $pim->imageLocationLabel());

        // A photoshoot has nothing to point at, so it is never asked.
        $this->actingAs($user)->post(route('product-requests.store'),
            $base + ['skus' => 'LOC-3', 'image_source' => ProductRequest::IMG_PHOTOSHOOT])
            ->assertRedirect()->assertSessionHasNoErrors();

        $shoot = ProductRequest::latest('id')->first();
        $this->assertFalse($shoot->needsImageLocation());
        $this->assertFalse($shoot->awaitingImageLocation());
        $this->assertNull($shoot->imageLocationLabel());
    }

    public function test_switching_away_from_supplier_images_clears_the_location(): void
    {
        Notification::fake();

        $user  = $this->brandManager();
        $store = $this->mappingSite();
        $user->stores()->sync([$store->id]);

        $this->actingAs($user)->post(route('product-requests.store'), [
            'store_id'           => $store->id,
            'request_type'       => 'new_brand',
            'brand'              => 'Samsonite',
            'category'           => 'Luggage',
            'skus'               => 'LOC-4',
            'online_launch_date' => now()->addDays(18)->format('Y-m-d H:i'),
            'use_ai_content'     => 1,
            'priority'           => 'high',
            'image_source'       => ProductRequest::IMG_SUPPLIER,
            'images_location'    => ProductRequest::IMAGES_AT_URL,
            'images_url'         => 'https://onedrive.example.com/folder/abc',
        ])->assertRedirect();

        $request = ProductRequest::first();

        // Moving to a photoshoot leaves a link that describes nothing.
        $this->actingAs($user)->put(route('product-requests.update', $request), [
            'name'               => 'Switched',
            'brand'              => 'Samsonite',
            'category'           => 'Luggage',
            'online_launch_date' => now()->addDays(18)->format('Y-m-d H:i'),
            'image_source'       => ProductRequest::IMG_PHOTOSHOOT,
            'use_ai_content'     => 1,
            'priority'           => 'high',
        ])->assertRedirect();

        $request->refresh();

        $this->assertNull($request->images_location);
        $this->assertNull($request->images_url);
    }

    public function test_the_image_source_must_be_chosen(): void
    {
        $user  = $this->brandManager();
        $store = $this->mappingSite();
        $user->stores()->sync([$store->id]);

        $base = [
            'store_id'           => $store->id,
            'request_type'       => 'new_brand',
            'brand'              => 'Samsonite',
            'category'           => 'Luggage',
            'skus'               => 'ANSWER-1',
            'online_launch_date' => now()->addDays(18)->format('Y-m-d H:i'),
            'use_ai_content'     => 1,
            'priority'           => 'high',
        ];

        // This replaced two booleans that could contradict each other, so there
        // is no default and no way to submit an incoherent combination.
        $this->actingAs($user)->post(route('product-requests.store'), $base)
            ->assertSessionHasErrors('image_source');

        $this->actingAs($user)->post(route('product-requests.store'),
            $base + ['image_source' => 'something_else'])
            ->assertSessionHasErrors('image_source');

        $this->assertSame(0, ProductRequest::count());

        // The old booleans are kept in step, so anything still reading them works.
        $this->actingAs($user)->post(route('product-requests.store'),
            $base + ['image_source' => ProductRequest::IMG_SUPPLIER, 'images_location' => ProductRequest::IMAGES_AT_PIM])->assertRedirect();

        $request = ProductRequest::first();
        $this->assertSame(ProductRequest::IMG_SUPPLIER, $request->image_source);
        $this->assertTrue($request->supplier_images_available);
        $this->assertFalse($request->photoshoot_required);
    }

    public function test_the_category_must_come_from_the_agreed_list(): void
    {
        $user  = $this->brandManager();
        $store = $this->mappingSite();
        $user->stores()->sync([$store->id]);

        $base = [
            'store_id'           => $store->id,
            'request_type'       => 'new_brand',
            'brand'              => 'Samsonite',
            'skus'               => 'CAT-1',
            'online_launch_date' => now()->addDays(18)->format('Y-m-d H:i'),
            'image_source'       => ProductRequest::IMG_PHOTOSHOOT,
            'use_ai_content'     => 1,
            'priority'           => 'high',
        ];

        // Free text let the same category arrive spelled three ways, so the queue
        // could not be grouped. Anything off the list is refused now.
        $this->actingAs($user)->post(route('product-requests.store'), $base + ['category' => 'Bags & Cases'])
            ->assertSessionHasErrors('category');

        $this->assertSame(0, ProductRequest::count());

        $this->actingAs($user)->post(route('product-requests.store'), $base + ['category' => 'Luggage'])
            ->assertRedirect();

        $this->assertSame('Luggage', ProductRequest::first()->category);
    }

    public function test_a_category_raised_before_the_list_survives_an_edit(): void
    {
        Notification::fake();

        $user    = $this->brandManager();
        $request = $this->submitFor($user, $this->mappingSite(), 'OLDCAT-1');

        // Written straight to the row, the way it would have been before the list.
        $request->update(['category' => 'Footwear']);

        $this->actingAs($user)->put(route('product-requests.update', $request), [
            'brand'              => $request->brand,
            'category'           => 'Footwear',
            'online_launch_date' => now()->addDays(20)->format('Y-m-d H:i'),
            'image_source'       => $request->image_source,
            'use_ai_content'     => 1,
            'priority'           => 'low',
        ])->assertRedirect();

        $this->assertSame('Footwear', $request->fresh()->category);
        $this->assertContains('Footwear', $request->fresh()->categoryOptions());
    }

    // ── The category decides who does the work ───────────────────────────────

    /** Ahmad handles Luggage, and there is exactly one photographer. */
    private function luggageOwner(): User
    {
        return User::create([
            'name' => 'Ahmad', 'email' => 'ahmad@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'ecommerce',
            'pcr_categories' => ['Luggage', "Women's Fashion", 'Kids', 'Home'],
        ]);
    }

    private function photographer(string $email = 'shoot@example.test'): User
    {
        return User::create([
            'name' => 'Studio', 'email' => $email, 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'photographer',
        ]);
    }

    public function test_the_category_owner_takes_every_role_except_the_photoshoot(): void
    {
        Notification::fake();

        $owner  = $this->luggageOwner();
        $shoot  = $this->photographer();
        $author = $this->brandManager();

        // A Blue Salon request with a photoshoot — every role is in play.
        $request = $this->submitFor($author, $this->mappingSite(), 'CATOWN-1');

        $this->assertSame($owner->id, $this->ownerId($request, 'assigned_to'));

        // Supply Chain is retired — there is no such team, and the brand manager
        // does the Cegid mapping themselves.
        $this->assertNull($this->ownerId($request, 'supply_chain_id'));
        $this->assertArrayNotHasKey('supply_chain_id', ProductRequest::assignableRoles());

        // Photo Editor is retired — the photoshoot delivers finished images.
        $this->assertNull($this->ownerId($request, 'image_editor_id'));
        $this->assertArrayNotHasKey('image_editor_id', ProductRequest::assignableRoles());

        // No brand manager is set for Luggage here, so the owner keeps that too.
        $this->assertSame($owner->id, $this->ownerId($request, 'brand_manager_id'));

        // Content is retired: the owner writes the copy as part of running the
        // request, so there is no separate Content Team assignee to give it to.
        $this->assertNull($this->ownerId($request, 'content_owner_id'));
        $this->assertArrayNotHasKey('content_owner_id', ProductRequest::assignableRoles());

        // The shoot is the one thing that is somebody else's job.
        $this->assertSame($shoot->id, $this->ownerId($request, 'photographer_id'));

        // Four roles, but one message — the same request four times over is noise.
        Notification::assertSentToTimes($owner, ProductRequestAssigned::class, 1);
        Notification::assertSentToTimes($shoot, ProductRequestAssigned::class, 1);
    }

    public function test_one_person_can_own_the_category_and_coordinate_the_shoot(): void
    {
        Notification::fake();

        // Ghassen's situation: he handles his own categories and arranges every
        // shoot, so he holds both jobs on the same request.
        $ghassen = User::create([
            'name' => 'Ghassen', 'email' => 'ghassen@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'photographer',
            'pcr_categories' => ['Luggage'],
        ]);

        $request = $this->submitFor($this->brandManager(), $this->plainSite(), 'BOTH-1');

        $this->assertSame($ghassen->id, $this->ownerId($request, 'assigned_to'));
        $this->assertSame($ghassen->id, $this->ownerId($request, 'photographer_id'));

        // Six roles, one person, one message.
        Notification::assertSentToTimes($ghassen, ProductRequestAssigned::class, 1);
    }

    public function test_no_photoshoot_coordinator_is_guessed_when_there_is_more_than_one(): void
    {
        Notification::fake();

        $this->luggageOwner();
        $this->photographer('shoot1@example.test');
        $this->photographer('shoot2@example.test');

        $request = $this->submitFor($this->brandManager(), $this->plainSite(), 'TWOSHOOT-1');

        // Picking one of two would be a coin toss, so it waits for a person.
        $this->assertNull($this->ownerId($request, 'photographer_id'));
    }

    public function test_a_role_the_requester_filled_in_beats_the_category_default(): void
    {
        Notification::fake();

        $owner  = $this->luggageOwner();
        $author = $this->brandManager();
        $store  = $this->plainSite();
        $author->stores()->sync([$store->id]);

        $chosen = User::create([
            'name' => 'Copy Desk', 'email' => 'copy@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'content',
        ]);

        $this->actingAs($author)->post(route('product-requests.store'), [
            'store_id'           => $store->id,
            'request_type'       => 'new_brand',
            'brand'              => 'Samsonite',
            'category'           => 'Luggage',
            'skus'               => 'OVERRIDE-1',
            'online_launch_date' => now()->addDays(18)->format('Y-m-d H:i'),
            'image_source'       => ProductRequest::IMG_SUPPLIER,
            'images_location'    => ProductRequest::IMAGES_AT_PIM,
            'use_ai_content'     => 1,
            'priority'           => 'high',
            'assignments'        => [['role' => 'content_owner_id', 'user_id' => $chosen->id]],
        ])->assertRedirect();

        $request = ProductRequest::latest('id')->first();

        $this->assertSame($chosen->id, $this->ownerId($request, 'content_owner_id'));
        $this->assertSame($owner->id, $this->ownerId($request, 'assigned_to'));
    }

    public function test_a_category_nobody_owns_arrives_unassigned_rather_than_failing(): void
    {
        Notification::fake();

        // Nobody has been given Beauty.
        $author = $this->brandManager();
        $store  = $this->plainSite();
        $author->stores()->sync([$store->id]);

        $this->actingAs($author)->post(route('product-requests.store'), [
            'store_id'           => $store->id,
            'request_type'       => 'new_brand',
            'brand'              => 'Dior',
            'category'           => 'Beauty',
            'skus'               => 'NOOWNER-1',
            'online_launch_date' => now()->addDays(18)->format('Y-m-d H:i'),
            'image_source'       => ProductRequest::IMG_PHOTOSHOOT,
            'use_ai_content'     => 1,
            'priority'           => 'low',
        ])->assertRedirect();

        $request = ProductRequest::latest('id')->first();

        $this->assertNotNull($request);
        $this->assertNull($this->ownerId($request, 'assigned_to'));
    }

    public function test_giving_a_category_to_someone_takes_it_off_whoever_held_it(): void
    {
        $admin = User::create([
            'name' => 'Root', 'email' => 'root@example.test', 'password' => 'password',
            'is_active' => true, 'is_super_admin' => true,
        ]);

        $ahmad = $this->luggageOwner();
        $rasul = User::create([
            'name' => 'Rasul', 'email' => 'rasul@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'ecommerce',
        ]);

        $this->actingAs($admin)->post(route('super-admin.users.permissions', $rasul), [
            'perm_product_request' => 1,
            'pcr_role'             => 'ecommerce',
            'pcr_categories'       => ['Luggage', 'Beauty', 'Not A Category'],
        ])->assertRedirect();

        // One category, one owner — and made-up values are dropped.
        $this->assertSame(['Beauty', 'Luggage'], $rasul->fresh()->pcr_categories);
        $this->assertNotContains('Luggage', $ahmad->fresh()->pcr_categories);
        $this->assertSame($rasul->id, User::ownerForCategory('Luggage')->id);

        // Ahmad keeps everything else — listed in dropdown order, not the order
        // the tick boxes happened to be saved in.
        $this->assertSame(['Home', "Women's Fashion", 'Kids'], $ahmad->fresh()->ownedCategories());
    }

    /**
     * Thirteen categories as a grid of tick boxes takes the same space whether
     * one is on or all of them. The picker has to render, and — more to the
     * point — still submit every chosen category.
     */
    public function test_the_category_picker_saves_what_was_chosen(): void
    {
        $admin = User::create([
            'name' => 'Root', 'email' => 'root@example.test', 'password' => 'password',
            'is_active' => true, 'is_super_admin' => true,
        ]);

        $manager = User::create([
            'name' => 'Brand One', 'email' => 'brand1@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'brand_manager',
        ]);

        $this->actingAs($admin)->get(route('super-admin.index'))
            ->assertOk()
            ->assertSee('Brand Manager / Brand Coordinator')
            ->assertSee('Categories Handled')
            ->assertSee('Search categories');

        $this->actingAs($admin)->post(route('super-admin.users.permissions', $manager), [
            'perm_product_request'  => 1,
            'pcr_role'              => 'brand_manager',
            'pcr_brand_categories'  => ['Beauty', 'Lingerie', 'Watches & Jewellery'],
        ])->assertRedirect();

        $this->assertSame(['Beauty', 'Lingerie', 'Watches & Jewellery'], $manager->fresh()->pcr_brand_categories);
    }

    /**
     * More than one person can be brand manager for a category: the first holds
     * the task, the rest are copied. Naming a second must not take it off the
     * first, the way the owner categories do.
     */
    public function test_a_category_can_have_several_brand_managers(): void
    {
        $admin = User::create([
            'name' => 'Root', 'email' => 'root@example.test', 'password' => 'password',
            'is_active' => true, 'is_super_admin' => true,
        ]);

        $first = User::create([
            'name' => 'First Manager', 'email' => 'first@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'brand_manager',
            'pcr_brand_categories' => ['Beauty'],
        ]);

        $second = User::create([
            'name' => 'Second Manager', 'email' => 'second@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'brand_manager',
        ]);

        $this->actingAs($admin)->post(route('super-admin.users.permissions', $second), [
            'perm_product_request'  => 1,
            'pcr_role'              => 'brand_manager',
            'pcr_brand_categories'  => ['Beauty'],
        ])->assertRedirect();

        $this->assertSame(['Beauty'], $first->fresh()->pcr_brand_categories, 'The first must keep it.');
        $this->assertSame(['Beauty'], $second->fresh()->pcr_brand_categories);

        // And the task still lands on one person, not both.
        $this->assertSame($first->id, User::brandManagerForCategory('Beauty')->id);
    }

    /**
     * A thousand SKUs must not become a thousand Shopify calls.
     *
     * Validation asks Shopify about each SKU, and an unwarmed lookup is a live,
     * throttled request — so a 1,020-SKU request took over an hour while the
     * same answer was one paginated read away.
     */
    public function test_a_large_request_reads_the_store_in_batches_not_per_sku(): void
    {
        Notification::fake();

        $store = $this->plainSite();
        $user  = $this->brandManager();

        $skus    = collect(range(1, 120))->map(fn ($i) => "BATCH-{$i}")->implode("\n");
        $request = $this->submitFor($user, $store, $skus);

        $this->assertSame(120, $request->skus()->count());

        // Every SKU still gets an answer. The validation used to warm the whole
        // catalogue into a cache to achieve that; it now asks Shopify for these
        // 120 SKUs in batches, so nothing depends on a cache being warm.
        $this->assertSame(0, $request->skus()->whereNull('in_shopify')->count());
    }

    // ── Whose dashboard is it ────────────────────────────────────────────────

    public function test_the_dashboard_and_list_show_only_your_own_requests(): void
    {
        Notification::fake();

        $owner = $this->luggageOwner();
        $store = $this->plainSite();

        // Two brand people, one request each.
        $mine   = $this->ecommerceUser('mine@example.test');
        $theirs = $this->ecommerceUser('theirs@example.test');

        $myRequest    = $this->submitFor($mine, $store, 'MINE-1');
        $theirRequest = $this->submitFor($theirs, $store, 'THEIRS-1');

        // The submission's flash message names its reference and would still be
        // in the session on the next page.
        $this->flushSession();

        // The dashboard counts one request, not two, and lists only mine.
        $this->actingAs($mine)->get(route('product-requests.index'))
            ->assertOk()
            ->assertSee($myRequest->reference)
            ->assertDontSee($theirRequest->reference);

        $this->flushSession();

        $this->actingAs($mine)->get(route('product-requests.list'))
            ->assertOk()
            ->assertSee($myRequest->reference)
            ->assertDontSee($theirRequest->reference);

        // A workflow role is not a reason to be shown everybody's work…
        $this->assertSame(1, ProductRequest::query()->onMyDesk($mine->fresh())->count());

        // …but it still lets you open what you are emailed about, or the team
        // would not be able to pick anything up.
        $this->actingAs($mine)->get(route('product-requests.show', $theirRequest))->assertOk();

        // The category owner sees both, because both are assigned to them.
        $this->assertSame(2, ProductRequest::query()->onMyDesk($owner->fresh())->count());
    }

    public function test_a_super_admin_and_the_watching_account_see_every_request(): void
    {
        Notification::fake();

        $this->luggageOwner();
        $store = $this->plainSite();

        $this->submitFor($this->brandManager(), $store, 'ALL-1');

        $admin = User::create([
            'name' => 'Root', 'email' => 'root4@example.test', 'password' => 'password',
            'is_active' => true, 'is_super_admin' => true,
        ]);

        // Oversight is the whole job of these two accounts.
        $this->assertSame(1, ProductRequest::query()->onMyDesk($admin)->count());
        $this->assertSame(1, ProductRequest::query()->onMyDesk($this->watcher())->count());
    }

    // ── Notifications: mine versus the team's ────────────────────────────────

    public function test_the_bell_only_rings_for_your_own_work(): void
    {
        // Not faked: the point of this test is the rows that get written.
        $author = $this->brandManager();
        $store  = $this->plainSite();
        $author->stores()->sync([$store->id]);

        $shooter = $this->photographer();

        // Hears about this stage because of their role, but holds nothing on the
        // request and owns no category — the definition of an FYI.
        $bystander = User::create([
            'name' => 'Other Desk', 'email' => 'other@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'ecommerce',
        ]);

        $request = $this->submitFor($author, $store, 'BELL-1');

        app(ProductRequestWorkflow::class)->transition($request->refresh(), ProductRequest::WAITING_IMAGES, $author, 'Off to the studio');

        // The assignee's own task, and the requester's own request, both count.
        $this->assertGreaterThan(0, $shooter->fresh()->unreadOwnNotifications()->count());
        $this->assertGreaterThan(0, $author->fresh()->unreadOwnNotifications()->count());

        // The bystander was told, but it is not their work — no bell.
        $this->assertGreaterThan(0, $bystander->fresh()->unreadNotifications()->count());
        $this->assertSame(0, $bystander->fresh()->unreadOwnNotifications()->count());
    }

    public function test_the_notifications_page_opens_on_your_own_work(): void
    {
        $author = $this->brandManager();
        $store  = $this->plainSite();
        $author->stores()->sync([$store->id]);

        // Told about the stage by role; holds nothing, owns no category.
        $bystander = User::create([
            'name' => 'Other Desk', 'email' => 'other3@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'ecommerce',
        ]);

        $request = $this->submitFor($author, $store, 'PAGE-1');
        app(ProductRequestWorkflow::class)->transition($request->refresh(), ProductRequest::WAITING_IMAGES, $author, 'Off to the studio');
        // The submission's own flash message names the reference, and it would
        // still be in the session on the next page — nothing to do with the
        // notification list this test is about.
        $this->flushSession();

        // Default view: nothing, because none of it is theirs.
        $this->actingAs($bystander)->get(route('product-requests.notifications'))
            ->assertOk()
            ->assertSee('Nothing waiting on you')
            ->assertDontSee($request->reference);


        // Everything: the team update is here, marked as FYI.
        $this->actingAs($bystander)->get(route('product-requests.notifications', ['scope' => 'all']))
            ->assertOk()
            ->assertSee($request->reference)
            ->assertSee('FYI');
    }

    public function test_the_feed_endpoint_returns_only_this_users_unread_work(): void
    {
        $author = $this->brandManager();
        $store  = $this->plainSite();
        $author->stores()->sync([$store->id]);

        $request = $this->submitFor($author, $store, 'FEED-1');

        $this->actingAs($author)->getJson(route('product-requests.notifications.feed'))
            ->assertOk()
            ->assertJsonStructure(['unread', 'items' => [['id', 'kind', 'title', 'body', 'url']]]);

        // Somebody with no stake in anything gets an empty feed, not everyone's.
        $outsider = User::create([
            'name' => 'Nobody', 'email' => 'nobody@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true,
        ]);

        $this->actingAs($outsider)->getJson(route('product-requests.notifications.feed'))
            ->assertOk()
            ->assertJson(['unread' => 0, 'items' => []]);
    }

    // ── Deleting ─────────────────────────────────────────────────────────────

    public function test_only_a_super_admin_can_delete_a_request(): void
    {
        Notification::fake();
        Storage::fake('local');

        $author  = $this->brandManager();
        $request = $this->submitFor($author, $this->plainSite(), "DEL-1\nDEL-2");

        // The person who raised it can cancel, not delete.
        $this->actingAs($author)->delete(route('product-requests.destroy', $request))->assertForbidden();
        $this->assertDatabaseHas('product_requests', ['id' => $request->id]);

        // Not offered in the list either.
        $this->actingAs($author)->get(route('product-requests.list'))
            ->assertOk()
            ->assertDontSee('Delete this request');

        $admin = User::create([
            'name' => 'Root', 'email' => 'root2@example.test', 'password' => 'password',
            'is_active' => true, 'is_super_admin' => true,
        ]);

        $this->actingAs($admin)->get(route('product-requests.list'))
            ->assertOk()
            ->assertSee('Delete this request');

        $this->actingAs($admin)->delete(route('product-requests.destroy', $request))
            ->assertRedirect(route('product-requests.list'));

        // The request and everything hanging off it are gone.
        $this->assertDatabaseMissing('product_requests', ['id' => $request->id]);
        $this->assertDatabaseMissing('product_request_skus', ['product_request_id' => $request->id]);
        $this->assertDatabaseMissing('product_request_activities', ['product_request_id' => $request->id]);
        $this->assertDatabaseMissing('product_request_assignments', ['product_request_id' => $request->id]);
    }

    public function test_deleting_takes_the_attachment_files_and_bell_entries_with_it(): void
    {
        Notification::fake();

        $author  = $this->brandManager();
        $request = $this->submitFor($author, $this->plainSite(), 'DELFILE-1');

        $this->actingAs($author)->post(route('product-requests.attachments.store', $request), [
            'reference_images' => [UploadedFile::fake()->image('swatch.jpg')],
        ])->assertRedirect();

        $attachment = $request->attachments()->firstOrFail();
        $path       = storage_path("app/{$attachment->path}");

        $this->assertFileExists($path);

        // A bell entry pointing at this request, the way a real one is stored.
        DB::table('notifications')->insert([
            'id'              => (string) \Illuminate\Support\Str::uuid(),
            'type'            => ProductRequestAssigned::class,
            'notifiable_type' => User::class,
            'notifiable_id'   => $author->id,
            'data'            => json_encode(['kind' => 'assigned', 'request_id' => $request->id, 'reference' => $request->reference]),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $admin = User::create([
            'name' => 'Root', 'email' => 'root3@example.test', 'password' => 'password',
            'is_active' => true, 'is_super_admin' => true,
        ]);

        $this->actingAs($admin)->delete(route('product-requests.destroy', $request))->assertRedirect();

        // A file nobody can reach is just disk, and a bell entry linking to a
        // deleted request is a dead end.
        $this->assertFileDoesNotExist($path);
        $this->assertSame(0, DB::table('notifications')
            ->where('data', 'like', '%"request_id":' . $request->id . ',%')->count());
    }

    // ── Carrying on with part of a request ───────────────────────────────────

    /**
     * Mapped in Cegid and now live in Shopify — which is the only way a SKU
     * becomes Mapped, since nobody types a status in.
     */
    private function mapSome(ProductRequest $request, int $howMany): ProductRequest
    {
        $request->skus()->orderBy('id')->limit($howMany)->get()->each(function ($row) use ($request) {
            // Warmed too, so a later re-check still finds them and does not
            // read "gone from Shopify" as "unmapped again".
            $this->appearsInShopify($request, $row->sku);

            $row->update([
                'mapping_status'        => ProductRequest::MAP_MAPPED,
                'in_shopify'            => true,
                'shopify_product_id'    => 111,
                'shopify_product_title' => 'Mapped in Cegid',
                'last_checked_at'       => now(),
            ]);
        });

        app(SkuMappingService::class)->rollUp($request);

        return $request->refresh();
    }

    /** Put a SKU in Shopify as far as the read-only check can see. */
    /**
     * Put a SKU into Shopify as far as the mapping check can tell.
     *
     * The check reads Shopify live, in batches, so this registers the SKU with a
     * fake service bound into the container. It used to seed warm-cache keys
     * instead — there is no cache on this path any more.
     */
    private function appearsInShopify(ProductRequest $request, string $sku): void
    {
        FakeMappingShopify::$present[$request->store->shopify_domain . '|' . $sku] = [
            ['product_id' => 111, 'product_title' => 'Now in Shopify', 'published' => true],
        ];

        $this->app->bind(
            \App\Services\ShopifyService::class,
            fn ($app, $parameters) => new FakeMappingShopify($parameters['store'] ?? null),
        );
    }

    public function test_ten_mapped_skus_do_not_wait_on_ten_that_are_not(): void
    {
        Notification::fake();

        $author  = $this->brandManager();
        $skus    = collect(range(1, 20))->map(fn ($i) => 'BAL-' . $i)->implode("\n");
        $request = $this->submitFor($author, $this->mappingSite(), $skus);

        // Cegid site, nothing mapped: the request parks with the brand manager.
        $this->assertSame(ProductRequest::WAITING_MAPPING, $request->status);
        $this->assertSame(20, $request->balanceSkus());
        $this->assertSame(0, $request->skuCompletionPercent());

        // With nothing mapped there is genuinely nothing to get on with.
        $this->assertFalse($request->canContinueWithMapped());
        $this->assertSame([ProductRequest::CANCELLED], $request->allowedTransitions());

        $request = $this->mapSome($request, 10);

        $this->assertSame(10, $request->mapped_skus);
        $this->assertSame(10, $request->balanceSkus());
        $this->assertSame(50, $request->skuCompletionPercent());
        $this->assertTrue($request->canContinueWithMapped());

        $this->actingAs($author)->post(route('product-requests.continue-mapped', $request))->assertRedirect();

        $request->refresh();

        // The mapped half moves on; the balance is still recorded against it.
        $this->assertSame(ProductRequest::SKU_VERIFIED, $request->status);
        $this->assertSame(10, $request->balanceSkus());
        $this->assertTrue($request->hasSkuBalance());

        // And the reason is on the record, percentage included.
        $this->assertTrue($request->activities()
            ->where('description', 'like', '%Continuing with 10 of 20 SKUs%')
            ->orWhere('remarks', 'like', '%Continuing with 10 of 20 SKUs (50%)%')
            ->exists());
    }

    /**
     * Nobody records a mapping outcome by hand any more, so pressing Validate
     * SKUs is how the balance arrives — and the people who carried on with the
     * mapped half have to hear about it, since they are not on the SKUs tab.
     */
    public function test_validating_picks_up_the_balance_and_tells_the_people_waiting_on_it(): void
    {
        Notification::fake();

        $author  = $this->brandManager();
        $request = $this->submitFor($author, $this->mappingSite(), "BAL-1\nBAL-2\nBAL-3\nBAL-4");

        $request = $this->mapSome($request, 2);
        $this->actingAs($author)->post(route('product-requests.continue-mapped', $request))->assertRedirect();

        // The brand manager finishes one in Cegid and it reaches Shopify.
        $this->appearsInShopify($request, 'BAL-3');

        $this->actingAs($author)->post(route('product-requests.revalidate', $request))->assertRedirect();

        Notification::assertSentTo($author, ProductRequestBalanceMapped::class, function ($n) {
            return $n->justMapped === 1 && $n->mapped === 3 && $n->total === 4
                && $n->remaining === 1 && !$n->isComplete();
        });

        // The last one lands: the message says it can be finished now.
        $this->appearsInShopify($request, 'BAL-4');

        $this->actingAs($author)->post(route('product-requests.revalidate', $request))->assertRedirect();

        Notification::assertSentTo($author, ProductRequestBalanceMapped::class,
            fn ($n) => $n->isComplete() && $n->remaining === 0 && $n->mapped === 4);

        $this->assertFalse($request->fresh()->hasSkuBalance());
        $this->assertSame(100, $request->fresh()->skuCompletionPercent());
    }

    /** The status is the check's to set, and only the check's. */
    public function test_the_skus_tab_offers_no_way_to_type_a_status_in(): void
    {
        $author  = $this->brandManager();
        $author->update(['is_super_admin' => true]);
        $request = $this->submitFor($author, $this->mappingSite(), "BAL-1\nBAL-2");

        $response = $this->actingAs($author)->get(route('product-requests.show', $request))->assertOk();

        $response->assertDontSee('Update mapping status');
        $response->assertDontSee('Recorded By');

        // And no endpoint left behind the removed buttons.
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('product-requests.skus.mapping'));
    }

    public function test_the_hourly_check_announces_a_balance_that_appears_in_shopify_by_itself(): void
    {
        Notification::fake();

        $author  = $this->brandManager();
        $request = $this->submitFor($author, $this->mappingSite(), "BAL-1\nBAL-2");

        $request = $this->mapSome($request, 1);
        $this->actingAs($author)->post(route('product-requests.continue-mapped', $request))->assertRedirect();

        // The remaining SKU was never touched by hand, so the read-only Shopify
        // check owns it. Put every SKU in Shopify — that is what "created in
        // Shopify later" looks like to this code.
        foreach ($request->skus as $row) {
            $this->appearsInShopify($request, $row->sku);
        }

        app(\App\Jobs\RecheckProductRequestMappingsJob::class)->handle(
            app(SkuMappingService::class),
            app(ProductRequestWorkflow::class),
        );

        // Nothing was re-submitted; the request simply reports itself finished.
        Notification::assertSentTo($author, ProductRequestBalanceMapped::class,
            fn ($n) => $n->isComplete() && $n->mapped === 2);
    }

    public function test_a_site_without_cegid_has_no_balance_to_wait_for(): void
    {
        Notification::fake();

        // Not a mapping site: a SKU missing from Shopify is just a product nobody
        // has uploaded yet, which is the normal state of a new brand.
        $request = $this->submitFor($this->brandManager(), $this->plainSite(), "NB-1\nNB-2");

        $this->assertSame(0, $request->balanceSkus());
        $this->assertFalse($request->hasSkuBalance());
        $this->assertFalse($request->canContinueWithMapped());
        $this->assertNotSame(ProductRequest::WAITING_MAPPING, $request->status);
    }

    // ── The Photoshoot Schedule ──────────────────────────────────────────────────

    public function test_a_shoot_enters_the_room_pending_and_leaves_when_it_is_not_needed(): void
    {
        Notification::fake();

        $author  = $this->brandManager();
        $request = $this->submitFor($author, $this->plainSite(), 'ROOM-1');

        // Raised as "we are photographing" — it needs a date, not noticing.
        $this->assertSame(ProductRequest::SHOOT_PENDING, $request->photoshoot_status);
        $this->assertTrue($request->shootIsOpen());

        // Switching to supplier images takes it out of the room entirely.
        $this->actingAs($author)->put(route('product-requests.update', $request), [
            'brand'              => $request->brand,
            'category'           => $request->category,
            'online_launch_date' => now()->addDays(20)->format('Y-m-d H:i'),
            'image_source'       => ProductRequest::IMG_SUPPLIER,
            'images_location'    => ProductRequest::IMAGES_AT_PIM,
            'use_ai_content'     => 1,
            'priority'           => 'low',
        ])->assertRedirect();

        $this->assertNull($request->fresh()->photoshoot_status);
        $this->assertSame(0, ProductRequest::withPhotoshoot()->count());
    }

    public function test_only_the_coordinator_can_change_the_calendar(): void
    {
        Notification::fake();

        $author  = $this->brandManager();
        $request = $this->submitFor($author, $this->plainSite(), 'ROOM-2');

        $booking = [
            'photoshoot_status'       => ProductRequest::SHOOT_SCHEDULED,
            'photoshoot_scheduled_at' => now()->addDays(4)->format('Y-m-d H:i'),
            'photoshoot_studio'       => 'Studio 2, Doha',
        ];

        // Everyone can read the room…
        $this->actingAs($author)->get(route('product-requests.photoshoot-room'))->assertOk();

        // …but the brand manager cannot book anything.
        $this->actingAs($author)
            ->put(route('product-requests.photoshoot-room.update', $request), $booking)
            ->assertForbidden();

        $this->assertSame(ProductRequest::SHOOT_PENDING, $request->fresh()->photoshoot_status);

        $coordinator = $this->photographer();

        $this->actingAs($coordinator)
            ->put(route('product-requests.photoshoot-room.update', $request), $booking)
            ->assertRedirect();

        $request->refresh();

        $this->assertSame(ProductRequest::SHOOT_SCHEDULED, $request->photoshoot_status);
        $this->assertSame('Studio 2, Doha', $request->photoshoot_studio);
        // A booking carries a time, not just a day.
        $this->assertSame(now()->addDays(4)->format('Y-m-d H:i'), $request->photoshoot_scheduled_at->format('Y-m-d H:i'));
    }

    public function test_a_shoot_cannot_be_scheduled_without_a_date(): void
    {
        Notification::fake();

        $request     = $this->submitFor($this->brandManager(), $this->plainSite(), 'ROOM-3');
        $coordinator = $this->photographer();

        $this->actingAs($coordinator)
            ->put(route('product-requests.photoshoot-room.update', $request), [
                'photoshoot_status' => ProductRequest::SHOOT_SCHEDULED,
            ])
            ->assertSessionHasErrors('photoshoot_scheduled_at');

        $this->assertSame(ProductRequest::SHOOT_PENDING, $request->fresh()->photoshoot_status);

        // Cancelling needs no date — the shoot is not happening.
        $this->actingAs($coordinator)
            ->put(route('product-requests.photoshoot-room.update', $request), [
                'photoshoot_status' => ProductRequest::SHOOT_CANCELLED,
            ])
            ->assertRedirect();

        $this->assertSame(ProductRequest::SHOOT_CANCELLED, $request->fresh()->photoshoot_status);
    }

    public function test_booking_and_finishing_a_shoot_moves_the_request_with_it(): void
    {
        Notification::fake();

        $request     = $this->submitFor($this->brandManager(), $this->plainSite(), 'ROOM-4');
        $coordinator = $this->photographer();

        // Get the request to the stage where a shoot is the next thing.
        $request->update(['status' => ProductRequest::WAITING_IMAGES]);

        $this->actingAs($coordinator)->put(route('product-requests.photoshoot-room.update', $request), [
            'photoshoot_status'       => ProductRequest::SHOOT_SCHEDULED,
            'photoshoot_scheduled_at' => now()->addDays(2)->format('Y-m-d H:i'),
        ])->assertRedirect();

        $this->assertSame(ProductRequest::PHOTOSHOOT_SCHEDULED, $request->fresh()->status);

        $this->actingAs($coordinator)->put(route('product-requests.photoshoot-room.update', $request), [
            'photoshoot_status'       => ProductRequest::SHOOT_COMPLETED,
            'photoshoot_scheduled_at' => now()->subDay()->format('Y-m-d H:i'),
        ])->assertRedirect();

        $request->refresh();

        $this->assertSame(ProductRequest::PHOTOSHOOT_COMPLETED, $request->status);
        // Done is done: a past date no longer counts as late.
        $this->assertFalse($request->isShootOverdue());
    }

    public function test_a_calendar_tidy_up_never_drags_a_request_backwards(): void
    {
        Notification::fake();

        $request     = $this->submitFor($this->brandManager(), $this->plainSite(), 'ROOM-5');
        $coordinator = $this->photographer();

        // The request has moved well past the shoot.
        $request->update(['status' => ProductRequest::QA_REVIEW]);

        $this->actingAs($coordinator)->put(route('product-requests.photoshoot-room.update', $request), [
            'photoshoot_status'       => ProductRequest::SHOOT_SCHEDULED,
            'photoshoot_scheduled_at' => now()->addDay()->format('Y-m-d H:i'),
        ])->assertRedirect();

        // The booking is recorded; the request stays where it is.
        $request->refresh();
        $this->assertSame(ProductRequest::SHOOT_SCHEDULED, $request->photoshoot_status);
        $this->assertSame(ProductRequest::QA_REVIEW, $request->status);
    }

    public function test_the_room_shows_the_shoot_and_leaves_supplier_requests_out(): void
    {
        Notification::fake();

        $author = $this->brandManager();
        $shoot  = $this->submitFor($author, $this->plainSite(), 'ROOM-6');
        $store  = $this->mappingSite();
        $author->stores()->syncWithoutDetaching([$store->id]);

        $this->actingAs($author)->post(route('product-requests.store'), [
            'store_id'           => $store->id,
            'request_type'       => 'new_brand',
            'brand'              => 'Rimowa',
            'category'           => 'Luggage',
            'skus'               => 'NOSHOOT-1',
            'online_launch_date' => now()->addDays(18)->format('Y-m-d H:i'),
            'image_source'       => ProductRequest::IMG_SUPPLIER,
            'images_location'    => ProductRequest::IMAGES_AT_PIM,
            'use_ai_content'     => 1,
            'priority'           => 'low',
        ])->assertRedirect();

        $this->actingAs($author)->get(route('product-requests.photoshoot-room'))
            ->assertOk()
            ->assertSee($shoot->reference)
            ->assertSee('Awaiting a date')
            ->assertDontSee('NOSHOOT-1');
    }

    // ── Brand managers follow their categories ───────────────────────────────

    /** The brand manager for Luggage: holds the brand-side task on its requests. */
    private function luggageBrandManager(): User
    {
        return User::create([
            'name' => 'Brand Desk', 'email' => 'bd@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true,
            'pcr_brand_categories' => ['Luggage'],
        ]);
    }

    public function test_a_categorys_brand_manager_holds_the_brand_side_task_and_nothing_else(): void
    {
        Notification::fake();

        $brandDesk = $this->luggageBrandManager();
        $owner     = $this->luggageOwner();
        $author    = $this->brandManager();

        $request = $this->submitFor($author, $this->plainSite(), 'BM-1');

        // Theirs: supply the information and approve the content.
        $this->assertSame($brandDesk->id, $this->ownerId($request, 'brand_manager_id'));

        // Everything else stays with whoever runs the category.
        $this->assertSame($owner->id, $this->ownerId($request, 'assigned_to'), 'Brand manager took the request');

        // They are told it is theirs, not copied in about somebody else's task.
        Notification::assertSentTo($brandDesk, ProductRequestAssigned::class,
            fn ($n) => !$n->isCopy() && $n->roleField === 'brand_manager_id');

        // And they hear the request move on.
        app(ProductRequestWorkflow::class)->transition($request->refresh(), ProductRequest::WAITING_IMAGES, $owner, 'Moving on');
        Notification::assertSentTo($brandDesk, ProductRequestStatusChanged::class);
    }

    public function test_a_brand_manager_only_hears_about_their_own_categories(): void
    {
        Notification::fake();

        $follower = $this->luggageBrandManager();
        $this->luggageOwner();
        $author = $this->brandManager();
        $store  = $this->plainSite();
        $author->stores()->sync([$store->id]);

        // A Beauty request — not theirs.
        $this->actingAs($author)->post(route('product-requests.store'), [
            'store_id'           => $store->id,
            'request_type'       => 'new_brand',
            'brand'              => 'Dior',
            'category'           => 'Beauty',
            'skus'               => 'OTHERCAT-1',
            'online_launch_date' => now()->addDays(18)->format('Y-m-d H:i'),
            'image_source'       => ProductRequest::IMG_PHOTOSHOOT,
            'use_ai_content'     => 1,
            'priority'           => 'low',
        ])->assertRedirect();

        Notification::assertNothingSentTo($follower);
    }

    public function test_a_brand_manager_can_open_the_requests_they_are_emailed_about(): void
    {
        Notification::fake();

        $follower = $this->luggageBrandManager();   // no pcr_role
        $this->luggageOwner();
        $author  = $this->brandManager();
        $request = $this->submitFor($author, $this->plainSite(), 'BMVIEW-1');

        // An email nobody can open is worse than no email.
        $this->actingAs($follower)->get(route('product-requests.show', $request))->assertOk();

        // A category they neither manage nor hold a role on stays out of reach.
        $store = $this->plainSite();
        $author->stores()->syncWithoutDetaching([$store->id]);

        $this->actingAs($author)->post(route('product-requests.store'), [
            'store_id'           => $store->id,
            'request_type'       => 'new_brand',
            'brand'              => 'Dior',
            'category'           => 'Beauty',
            'skus'               => 'BMHIDDEN-1',
            'online_launch_date' => now()->addDays(18)->format('Y-m-d H:i'),
            'image_source'       => ProductRequest::IMG_SUPPLIER,
            'images_location'    => ProductRequest::IMAGES_AT_PIM,
            'use_ai_content'     => 1,
            'priority'           => 'low',
        ])->assertRedirect();

        $hidden = ProductRequest::latest('id')->first();

        $this->actingAs($follower)->get(route('product-requests.show', $hidden))->assertForbidden();
    }

    // ── The shared inbox that watches everything ─────────────────────────────

    /** The e-commerce account: copied on every request without holding a role. */
    private function watcher(): User
    {
        return User::create([
            'name' => 'Ecommerce', 'email' => 'ecommerce@example.test', 'password' => 'password',
            'is_active' => true, 'is_super_admin' => true, 'pcr_notify_all' => true,
        ]);
    }

    public function test_the_watching_account_is_copied_on_assignments_and_status_changes(): void
    {
        Notification::fake();

        $watcher = $this->watcher();
        $owner   = $this->luggageOwner();
        $author  = $this->brandManager();

        $request = $this->submitFor($author, $this->plainSite(), 'WATCH-1');

        // It holds no role on this request, but it hears about the staffing…
        $this->assertNotSame($watcher->id, $this->ownerId($request, 'assigned_to'));
        Notification::assertSentTo($watcher, ProductRequestAssigned::class,
            fn ($n) => $n->reference === $request->reference);

        // …and about the request moving on.
        app(ProductRequestWorkflow::class)->transition($request, ProductRequest::WAITING_IMAGES, $owner, 'Ready to shoot');

        Notification::assertSentTo($watcher, ProductRequestStatusChanged::class);
    }

    public function test_the_watching_account_hears_about_comments(): void
    {
        Notification::fake();

        $watcher = $this->watcher();
        $author  = $this->brandManager();
        $request = $this->submitFor($author, $this->plainSite(), 'WATCHCMT-1');

        $this->actingAs($author)->post(route('product-requests.comment', $request), [
            'remarks' => 'Samples arrive Thursday.',
        ])->assertRedirect();

        Notification::assertSentTo($watcher, ProductRequestCommented::class);
    }

    public function test_the_watching_account_is_not_told_about_its_own_doing(): void
    {
        Notification::fake();

        $watcher = $this->watcher();
        $owner   = $this->luggageOwner();
        $store   = $this->plainSite();
        $watcher->stores()->sync([$store->id]);

        // The watcher raises the request itself — being copied on your own
        // action is the one thing nobody wants.
        $request = $this->submitFor($watcher, $store, 'SELFWATCH-1');

        Notification::assertNotSentTo($watcher, ProductRequestAssigned::class);
        $this->assertSame($owner->id, $this->ownerId($request, 'assigned_to'));
    }

    public function test_a_deactivated_watcher_stops_receiving_copies(): void
    {
        Notification::fake();

        $watcher = $this->watcher();
        $watcher->update(['is_active' => false]);

        $this->luggageOwner();
        $request = $this->submitFor($this->brandManager(), $this->plainSite(), 'DEADWATCH-1');

        $this->assertNotNull($request);
        Notification::assertNotSentTo($watcher, ProductRequestAssigned::class);
    }

    public function test_submission_requires_at_least_one_sku(): void
    {
        $user  = $this->brandManager();
        $store = $this->mappingSite();
        $user->stores()->sync([$store->id]);

        $this->actingAs($user)->post(route('product-requests.store'), [
            'store_id'                  => $store->id,
            'request_type'              => 'new_brand',
            'brand'                     => 'Samsonite',
            'category'                  => 'Luggage',
            'skus'                      => '',
            'online_launch_date'        => now()->addDays(18)->format('Y-m-d H:i'),
            'image_source'              => ProductRequest::IMG_PHOTOSHOOT,
            'use_ai_content'            => 1,
            'priority'                  => 'high',
        ])->assertSessionHasErrors('skus');

        $this->assertSame(0, ProductRequest::count());
    }

    // ── Mapping lifecycle (Blue Salon only) ─────────────────────────────────

    public function test_the_check_releases_the_request_once_the_products_reach_shopify(): void
    {
        Notification::fake();

        $user    = $this->brandManager();
        $request = $this->submitFor($user, $this->mappingSite(), "A-1\nA-2");

        $this->assertSame(ProductRequest::WAITING_MAPPING, $request->status);

        // Mapped in Cegid on the brand manager's side, so the products appear.
        $this->appearsInShopify($request, 'A-1');
        $this->appearsInShopify($request, 'A-2');

        $this->actingAs($user)->post(route('product-requests.revalidate', $request))->assertRedirect();

        $request->refresh();

        // No re-submission and nothing typed in — the request advances on its
        // own, through SKU Verified to the first stage with work in it.
        $this->assertPassedVerified($request, ProductRequest::AI_CONTENT);
        $this->assertSame(2, $request->mapped_skus);
        $this->assertSame(0, $request->pending_skus);

        Notification::assertSentTo($user, ProductRequestStatusChanged::class);
    }

    /** Paging is a table concern; the check has always read the whole request. */
    public function test_the_check_covers_every_sku_not_just_the_page_on_screen(): void
    {
        Notification::fake();

        $user = $this->brandManager();

        // More SKUs than the 50-per-page table shows.
        $skus    = collect(range(1, 60))->map(fn ($i) => "PAGE-{$i}")->implode("\n");
        $request = $this->submitFor($user, $this->mappingSite(), $skus);

        $this->assertSame(60, $request->skus()->count());
        $this->assertSame(ProductRequest::WAITING_MAPPING, $request->status);

        foreach ($request->skus as $row) {
            $this->appearsInShopify($request, $row->sku);
        }

        $this->actingAs($user)->post(route('product-requests.revalidate', $request))->assertRedirect();

        $request->refresh();

        $this->assertSame(60, $request->mapped_skus);
        $this->assertSame(0, $request->pending_skus);
        $this->assertPassedVerified($request, ProductRequest::AI_CONTENT);
    }

    /**
     * Rows carry statuses somebody typed in before the buttons were removed.
     * The check owns them now, or they would sit on an answer nothing can
     * correct — including a red one on a SKU that has since gone live.
     */
    public function test_the_check_takes_over_a_status_that_was_once_set_by_hand(): void
    {
        Notification::fake();

        $user    = $this->brandManager();
        $request = $this->submitFor($user, $this->mappingSite(), 'G-1');

        $request->skus()->update([
            'mapping_status' => ProductRequest::MAP_NOT_MAPPED,
            'mapping_set_by' => $user->id,
            'mapping_set_at' => now(),
        ]);

        $this->appearsInShopify($request, 'G-1');

        app(SkuMappingService::class)->validate($request->fresh());

        $this->assertSame(ProductRequest::MAP_MAPPED, $request->skus()->first()->mapping_status);
    }

    public function test_a_request_cannot_leave_waiting_for_mapping_while_skus_are_unmapped(): void
    {
        Notification::fake();

        $user    = $this->brandManager();
        $request = $this->submitFor($user, $this->mappingSite(), 'B-1');

        $this->actingAs($user)->post(route('product-requests.transition', $request), [
            'to_status' => ProductRequest::READY_FOR_UPLOAD,
        ])->assertSessionHasErrors('to_status');

        $this->assertSame(ProductRequest::WAITING_MAPPING, $request->fresh()->status);
    }

    // ── Audit trail, permissions, lifecycle ─────────────────────────────────

    public function test_every_status_change_is_written_to_the_audit_trail(): void
    {
        Notification::fake();

        $user    = $this->brandManager();
        $request = $this->submitFor($user, $this->mappingSite(), 'C-1');

        $this->markMapped($request);

        $this->actingAs($user)->post(route('product-requests.transition', $request), [
            'to_status' => ProductRequest::WAITING_IMAGES,
            'remarks'   => 'Booking the studio',
        ])->assertRedirect();

        $trail = ProductRequestActivity::where('product_request_id', $request->id)
            ->orderBy('id')
            ->get();

        $this->assertTrue($trail->contains(fn ($a) => $a->action === 'created'));
        $this->assertTrue($trail->contains(fn ($a) => $a->to_status === ProductRequest::WAITING_MAPPING));
        $this->assertTrue($trail->contains(fn ($a) => $a->to_status === ProductRequest::SKU_VERIFIED));

        // Verified moved it on to the copy by itself; the manual move starts there.
        $this->assertTrue($trail->contains(fn ($a) => $a->to_status === ProductRequest::AI_CONTENT));

        $last = $trail->last();
        $this->assertSame(ProductRequest::AI_CONTENT, $last->from_status);
        $this->assertSame(ProductRequest::WAITING_IMAGES, $last->to_status);
        $this->assertSame('Booking the studio', $last->remarks);
        $this->assertSame($user->id, $last->user_id);
    }

    public function test_users_without_the_permission_are_refused(): void
    {
        $outsider = User::create([
            'name'                 => 'No Access',
            'email'                => 'nope@example.test',
            'password'             => 'password',
            'is_active'            => true,
            'perm_product_request' => false,
        ]);

        $this->actingAs($outsider)->get(route('product-requests.index'))->assertForbidden();
    }

    public function test_a_request_is_hidden_from_unrelated_users(): void
    {
        Notification::fake();

        $owner   = $this->brandManager();
        $request = $this->submitFor($owner, $this->mappingSite(), 'D-1');

        $other = User::create([
            'name'                 => 'Other Team',
            'email'                => 'other@example.test',
            'password'             => 'password',
            'is_active'            => true,
            'perm_product_request' => true,
            'pcr_role'             => null,
        ]);

        $this->actingAs($other)->get(route('product-requests.show', $request))->assertForbidden();
    }

    public function test_publishing_ends_the_request(): void
    {
        Notification::fake();

        $user    = $this->brandManager();
        $request = $this->submitFor($user, $this->plainSite(), 'PUB-1');

        // Publishing is the last stage — there is no Ready for Upload or
        // Completed step after it.
        $stages = $request->displayStages();
        $this->assertSame(ProductRequest::PUBLISHED, end($stages));
        $this->assertNotContains(ProductRequest::READY_FOR_UPLOAD, $stages);
        $this->assertNotContains(ProductRequest::COMPLETED, $stages);

        // The shoot this request asked for is finished, so nothing holds it back.
        $request->update(['photoshoot_status' => ProductRequest::SHOOT_COMPLETED]);

        app(ProductRequestWorkflow::class)->transition($request, ProductRequest::PUBLISHED, $user, 'Live');
        $request->refresh();

        $this->assertTrue($request->isClosed());
        $this->assertSame([], $request->allowedTransitions());
        $this->assertFalse($request->isOverdue());

        // Publishing stamps completion too, or every closed request would look
        // like it was never finished.
        $this->assertNotNull($request->published_at);
        $this->assertNotNull($request->completed_at);
    }

    public function test_a_closed_request_shows_every_phase_as_done(): void
    {
        Notification::fake();

        $user    = $this->brandManager();
        $request = $this->submitFor($user, $this->plainSite(), 'PHASE-1');

        // Mid-flight: the phase being worked reads as in progress.
        $request->update(['status' => ProductRequest::QA_REVIEW]);
        $states = collect($request->refresh()->phaseProgress())->pluck('state', 'label');
        $this->assertSame('current', $states['Review & Launch']);

        // Closed: sitting on the final stage is finished, not still running —
        // a 100% request reading "In Progress" is a contradiction.
        foreach ([ProductRequest::PUBLISHED, ProductRequest::COMPLETED] as $closed) {
            $request->update(['status' => $closed]);
            $request->refresh();

            $this->assertTrue($request->isClosed());
            $this->assertSame(100, $request->progressPercent());

            foreach ($request->phaseProgress() as $phase) {
                $this->assertSame('done', $phase['state'],
                    "Phase {$phase['label']} should be done on a {$closed} request");
            }
        }

        $this->actingAs($user)->get(route('product-requests.show', $request))
            ->assertOk()
            ->assertDontSee('In Progress');
    }

    public function test_a_request_left_on_a_retired_stage_still_works(): void
    {
        Notification::fake();

        $user    = $this->brandManager();
        $request = $this->submitFor($user, $this->plainSite(), 'LEGACY-1');

        // Created under the old flow, before Ready for Upload was retired.
        $request->update(['status' => ProductRequest::READY_FOR_UPLOAD]);
        $request->refresh();

        // It must still render — the stepper needs to contain its own status.
        $this->assertContains(ProductRequest::READY_FOR_UPLOAD, $request->displayStages());
        $this->assertGreaterThanOrEqual(0, $request->displayStageIndex());
        $this->assertGreaterThan(0, $request->progressPercent());

        // And it can still be finished.
        $this->assertSame(ProductRequest::PUBLISHED, $request->suggestedNextStatus());
        $this->assertFalse($request->isClosed());

        $this->actingAs($user)->get(route('product-requests.show', $request))->assertOk();
    }

    public function test_cancelling_closes_the_request(): void
    {
        Notification::fake();

        $user    = $this->brandManager();
        $request = $this->submitFor($user, $this->mappingSite(), 'E-1');

        $this->actingAs($user)->post(route('product-requests.cancel', $request), [
            'cancel_reason' => 'Brand postponed the launch',
        ])->assertRedirect();

        $request->refresh();

        $this->assertSame(ProductRequest::CANCELLED, $request->status);
        $this->assertNotNull($request->cancelled_at);
        $this->assertTrue($request->isClosed());
        $this->assertSame([], $request->allowedTransitions());
    }

    public function test_skus_export_streams_the_requested_subset(): void
    {
        Notification::fake();

        $user    = $this->brandManager();
        $request = $this->submitFor($user, $this->mappingSite(), "F-1\nF-2");

        $response = $this->actingAs($user)
            ->get(route('product-requests.skus.download', [$request, 'filter' => ProductRequest::MAP_PENDING]));

        $response->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('F-1', $csv);
        $this->assertStringContainsString('F-2', $csv);
        $this->assertStringContainsString('Pending Mapping', $csv);
    }

    // ── Stage queues ─────────────────────────────────────────────────────────

    public function test_each_queue_shows_only_its_own_stages(): void
    {
        $user  = $this->brandManager();
        $store = $this->mappingSite();
        $user->stores()->sync([$store->id]);

        $stages = [
            ProductRequest::WAITING_IMAGES       => 'SHOOT-A',
            ProductRequest::PHOTOSHOOT_SCHEDULED => 'SHOOT-B',
            ProductRequest::IMAGE_EDITING        => 'CONTENT-A',
            ProductRequest::AI_CONTENT           => 'CONTENT-B',
            ProductRequest::QA_REVIEW            => 'QA-ONLY',
        ];

        foreach ($stages as $status => $brand) {
            ProductRequest::create([
                'reference'          => ProductRequest::nextReference(),
                'user_id'            => $user->id,
                'store_id'           => $store->id,
                'request_type'       => 'new_brand',
                'brand'              => $brand,
                'category'           => 'Luggage',
                'status'             => $status,
                'priority'           => 'high',
                'online_launch_date' => now()->addDays(5),
            ]);
        }

        $photoshoot = $this->actingAs($user)->get(route('product-requests.queue', 'photoshoot'));
        $photoshoot->assertOk()
            ->assertSee('SHOOT-A')->assertSee('SHOOT-B')
            ->assertDontSee('CONTENT-A')->assertDontSee('QA-ONLY');

        $content = $this->actingAs($user)->get(route('product-requests.queue', 'content'));
        $content->assertOk()
            ->assertSee('CONTENT-A')->assertSee('CONTENT-B')
            ->assertDontSee('SHOOT-A')->assertDontSee('QA-ONLY');
    }

    public function test_a_queue_can_be_filtered_to_my_assignments(): void
    {
        $user  = $this->brandManager();
        $store = $this->mappingSite();
        $user->stores()->sync([$store->id]);

        $base = [
            'user_id'            => $user->id,
            'store_id'           => $store->id,
            'request_type'       => 'new_brand',
            'category'           => 'Luggage',
            'status'             => ProductRequest::WAITING_IMAGES,
            'priority'           => 'high',
            'online_launch_date' => now()->addDays(5),
        ];

        $mineRow = ProductRequest::create($base + ['reference' => ProductRequest::nextReference(), 'brand' => 'MINE']);
        $this->assign($mineRow, 'photographer_id', $user);
        ProductRequest::create($base + ['reference' => ProductRequest::nextReference(), 'brand' => 'NOBODYS']);

        $this->actingAs($user)->get(route('product-requests.queue', ['queue' => 'photoshoot', 'mine' => 1]))
            ->assertOk()->assertSee('MINE')->assertDontSee('NOBODYS');

        $this->actingAs($user)->get(route('product-requests.queue', ['queue' => 'photoshoot', 'unassigned' => 1]))
            ->assertOk()->assertSee('NOBODYS')->assertDontSee('MINE');
    }

    public function test_the_all_requests_page_can_raise_a_new_request(): void
    {
        $user  = $this->brandManager();
        $store = $this->mappingSite();
        $user->stores()->sync([$store->id]);

        // The slide-over is on this page, so it must render the form and the
        // website options — not just the table.
        $this->actingAs($user)->get(route('product-requests.list'))
            ->assertOk()
            ->assertSee('New Request')
            ->assertSee('New Product Creation Request')
            ->assertSee($store->name);
    }

    public function test_being_assigned_notifies_the_person_but_not_the_assigner(): void
    {
        Notification::fake();

        $user    = $this->brandManager();
        $request = $this->submitFor($user, $this->mappingSite(), 'ASG-1');

        $photographer = User::create([
            'name'                 => 'Shooter',
            'email'                => 'shooter@example.test',
            'password'             => 'password',
            'is_active'            => true,
            'perm_product_request' => true,
            'pcr_role'             => 'photographer',
        ]);

        $this->actingAs($user)->post(route('product-requests.assign', $request), [
            'photographer_id' => $photographer->id,
            'assigned_to'     => $user->id,   // assigning myself
        ])->assertRedirect();

        Notification::assertSentTo($photographer, ProductRequestAssigned::class,
            fn ($n) => $n->roleLabel === 'Photoshoot Coordinator' && $n->reference === $request->reference);

        // Assigning yourself shouldn't ping you.
        Notification::assertNotSentTo($user, ProductRequestAssigned::class);

        // Re-saving the same assignments must not re-notify.
        Notification::fake();
        $this->actingAs($user)->post(route('product-requests.assign', $request), [
            'photographer_id' => $photographer->id,
            'assigned_to'     => $user->id,
        ]);
        Notification::assertNothingSent();
    }

    public function test_assigned_to_me_lists_my_work_and_my_role(): void
    {
        Notification::fake();

        $user    = $this->ecommerceUser();
        $store   = $this->mappingSite();
        $mine    = $this->submitFor($user, $store, 'MINE-1');
        $notMine = $this->submitFor($user, $this->plainSite(), 'THEIRS-1');

        $mine->update(['brand' => 'MY BRAND']);
        $this->assign($mine, 'qa_owner_id', $user);

        // Given to somebody else, so it is neither mine nor unclaimed work my
        // role would be offered further down the page.
        $notMine->update(['brand' => 'SOMEONE ELSE']);
        $this->assign($notMine, 'assigned_to', $this->ecommerceUser('other-ecom@example.test'));

        $response = $this->actingAs($user)->get(route('product-requests.my-tasks'));

        $response->assertOk()
            ->assertSee('MY BRAND')
            ->assertSee('QA Team')          // the role badge
            ->assertDontSee('SOMEONE ELSE');
    }

    public function test_assigned_to_me_hides_closed_work_by_default(): void
    {
        Notification::fake();

        $user  = $this->ecommerceUser();
        $store = $this->mappingSite();
        $done  = $this->submitFor($user, $store, 'DONE-1');

        $done->update(['brand' => 'FINISHED WORK']);
        $this->assign($done, 'assigned_to', $user);
        $done->update(['status' => ProductRequest::COMPLETED]);

        $this->actingAs($user)->get(route('product-requests.my-tasks'))
            ->assertOk()->assertDontSee('FINISHED WORK');

        $this->actingAs($user)->get(route('product-requests.my-tasks', ['include_closed' => 1]))
            ->assertOk()->assertSee('FINISHED WORK');
    }

    // ── Content source ───────────────────────────────────────────────────────

    public function test_a_content_sheet_can_be_supplied_instead_of_ai_content(): void
    {
        Notification::fake();
        Storage::fake();

        $user  = $this->brandManager();
        $store = $this->mappingSite();
        $user->stores()->sync([$store->id]);

        $this->actingAs($user)->post(route('product-requests.store'), [
            'store_id'                  => $store->id,
            'request_type'              => 'new_brand',
            'brand'                     => 'Samsonite',
            'category'                  => 'Luggage',
            'skus'                      => 'CS-1',
            'online_launch_date'        => now()->addDays(18)->format('Y-m-d H:i'),
            'image_source'              => ProductRequest::IMG_PHOTOSHOOT,
            'use_ai_content'            => 0,
            'priority'                  => 'high',
            'content_sheet'             => UploadedFile::fake()->create('copy.csv', 12, 'text/csv'),
        ])->assertRedirect();

        $request = ProductRequest::first();

        $this->assertFalse($request->use_ai_content);
        $this->assertSame(1, $request->contentSheets()->count());
        $this->assertSame(0, $request->referenceImages()->count());
        $this->assertFalse($request->awaitingContentSheet());

        // The stage reads honestly for a request that isn't using AI.
        $this->assertSame('Content from Brand Team', $request->stageLabel(ProductRequest::AI_CONTENT));
    }

    /**
     * Only the first column of a SKU CSV becomes SKUs, so the file as uploaded
     * is kept and offered for download, at submission and when SKUs are added.
     */
    public function test_the_uploaded_sku_csv_is_kept_and_downloadable(): void
    {
        Notification::fake();
        Queue::fake();

        $user  = $this->brandManager();
        $store = $this->plainSite();
        $user->stores()->sync([$store->id]);

        $original = "SKU,Colour,Size\nCSV-1,Black,M\nCSV-2,Red,L\n";

        $this->actingAs($user)->post(route('product-requests.store'), [
            'store_id'                  => $store->id,
            'request_type'              => 'new_brand',
            'brand'                     => 'Samsonite',
            'category'                  => 'Luggage',
            'sku_csv'                   => UploadedFile::fake()->createWithContent('brand-skus.csv', $original),
            'online_launch_date'        => now()->addDays(18)->format('Y-m-d H:i'),
            'image_source'              => ProductRequest::IMG_PHOTOSHOOT,
            'use_ai_content'            => 1,
            'priority'                  => 'high',
        ])->assertRedirect();

        $request = ProductRequest::first();

        try {
            $this->assertSame(['CSV-1', 'CSV-2'], $request->skus()->orderBy('id')->pluck('sku')->all());

            $file = $request->skuFiles()->sole();
            $this->assertSame('brand-skus.csv', $file->original_name);

            $this->actingAs($user)->get(route('product-requests.show', $request))
                ->assertOk()
                ->assertSee('Original upload: brand-skus.csv');

            $download = $this->actingAs($user)
                ->get(route('product-requests.attachments.download', [$request, $file]))
                ->assertOk();
            $this->assertSame($original, file_get_contents($download->baseResponse->getFile()->getPathname()));

            // A later "add SKUs" upload is kept alongside, not in place of, the first.
            $this->actingAs($user)->post(route('product-requests.skus.add', $request), [
                'sku_csv' => UploadedFile::fake()->createWithContent('more.csv', "SKU\nCSV-3\n"),
            ])->assertRedirect();

            $this->assertSame(['more.csv', 'brand-skus.csv'], $request->skuFiles()->pluck('original_name')->all());
        } finally {
            File::deleteDirectory(storage_path("app/product-requests/{$request->id}"));
        }
    }

    /** The expected showroom launch is optional, a day not a slot, and editable. */
    public function test_the_expected_showroom_launch_date_is_saved_and_editable(): void
    {
        Notification::fake();
        Queue::fake();

        $user  = $this->brandManager();
        $store = $this->plainSite();
        $user->stores()->sync([$store->id]);

        $payload = [
            'store_id'           => $store->id,
            'request_type'       => 'new_brand',
            'brand'              => 'Samsonite',
            'category'           => 'Luggage',
            'skus'               => 'SR-1',
            'online_launch_date' => now()->addDays(18)->format('Y-m-d H:i'),
            'image_source'       => ProductRequest::IMG_PHOTOSHOOT,
            'use_ai_content'     => 1,
            'priority'           => 'high',
        ];

        // Left blank, as most will be.
        $this->actingAs($user)->post(route('product-requests.store'), $payload)->assertSessionHasNoErrors();
        $this->assertNull(ProductRequest::latest('id')->first()->store_launch_date);

        // The picker sends a time with it; only the day is kept.
        $this->actingAs($user)->post(route('product-requests.store'), $payload + ['store_launch_date' => '2026-11-20T10:00'])
            ->assertSessionHasNoErrors();
        $request = ProductRequest::latest('id')->first();
        $this->assertSame('2026-11-20', $request->store_launch_date->format('Y-m-d'));

        $this->actingAs($user)->get(route('product-requests.show', $request))->assertOk()->assertSee('20 Nov 2026');

        $this->actingAs($user)->put(route('product-requests.update', $request), [
            'brand'              => 'Samsonite',
            'category'           => 'Luggage',
            'online_launch_date' => now()->addDays(18)->format('Y-m-d\TH:i'),
            'store_launch_date'  => '',
            'image_source'       => ProductRequest::IMG_PHOTOSHOOT,
            'use_ai_content'     => 1,
            'priority'           => 'high',
        ])->assertSessionHasNoErrors();

        $this->assertNull($request->fresh()->store_launch_date);
    }

    /** POST a new request with only a SKU CSV as its products. */
    private function submitCsv(User $user, Store $store, string $csv)
    {
        $user->stores()->syncWithoutDetaching([$store->id]);

        return $this->actingAs($user)->post(route('product-requests.store'), [
            'store_id'           => $store->id,
            'request_type'       => 'new_brand',
            'brand'              => 'Samsonite',
            'category'           => 'Luggage',
            'sku_csv'            => UploadedFile::fake()->createWithContent('skus.csv', $csv),
            'online_launch_date' => now()->addDays(18)->format('Y-m-d H:i'),
            'image_source'       => ProductRequest::IMG_PHOTOSHOOT,
            'use_ai_content'     => 1,
            'priority'           => 'high',
        ]);
    }

    /**
     * The SKU column is found by its header, not its position, and "Item SKU"
     * is accepted too — whatever the case, spacing or Excel BOM.
     */
    public function test_skus_are_read_from_the_sku_or_item_sku_column(): void
    {
        Notification::fake();
        Queue::fake();

        $user  = $this->brandManager();
        $store = $this->plainSite();

        $this->submitCsv($user, $store, "\xEF\xBB\xBFBarcode, Item  SKU ,Name\n6291,IS-1,Bag\n6292,IS-2,Case\n")
            ->assertSessionHasNoErrors();

        $request = ProductRequest::latest('id')->first();

        try {
            $this->assertSame(['IS-1', 'IS-2'], $request->skus()->orderBy('id')->pluck('sku')->all());
        } finally {
            File::deleteDirectory(storage_path("app/product-requests/{$request->id}"));
        }
    }

    /**
     * The uploaded CSV's Long Description / Description column says which SKUs
     * already have copy, so only the empty ones are offered for generation.
     */
    public function test_the_csv_description_column_decides_which_skus_need_copy(): void
    {
        Notification::fake();
        Queue::fake();

        $user  = $this->brandManager();
        $store = $this->plainSite();

        // A Long Description that runs over two lines inside quotes, as Excel writes it.
        $csv = "SKU,Name,Long Description\r\n"
             . "LD-1,Bag,\"Soft leather.\nTwo pockets, one zip.\"\r\n"
             . "LD-2,Case,\r\n"
             . "LD-3,Trolley,<p></p>\r\n";

        $this->submitCsv($user, $store, $csv)->assertSessionHasNoErrors();
        $request = ProductRequest::latest('id')->first();

        try {
            $this->assertSame(['LD-1', 'LD-2', 'LD-3'], $request->skus()->orderBy('id')->pluck('sku')->all());

            $copy = $request->skus()->pluck('sheet_has_description', 'sku')->map(fn ($v) => (bool) $v)->all();
            $this->assertSame(['LD-1' => true, 'LD-2' => false, 'LD-3' => false], $copy);

            // Live with nothing in Shopify: only the two empty ones are offered.
            $request->skus()->update(['in_shopify' => true, 'has_description' => false]);
            $this->assertSame(['LD-2', 'LD-3'], $request->skusNeedingContent()->orderBy('id')->pluck('sku')->all());
        } finally {
            File::deleteDirectory(storage_path("app/product-requests/{$request->id}"));
        }
    }

    /**
     * The brand team's export: a long description's later paragraphs land on
     * rows of their own with no SKU. Every product described means no AI step.
     */
    public function test_a_fully_described_export_skips_the_ai_step(): void
    {
        Notification::fake();
        Queue::fake();

        $user  = $this->brandManager();
        $store = $this->plainSite();

        $csv = "Date,Item Status,Brand Name,Item SKU,Product Name,Long Description,Barcode/EAN\n"
             . "31/08/2026,Available,ELIE SAAB,ESB-1,L'Homme 100ml,\"A bold, leathery fragrance.\",7.64E+12\n"
             . ",,,,,,\n"
             . ",,,,,\"Fragrance Family: Woody, Spicy\",\n"
             . ",,,,,Key Notes:,\n"
             // Nothing in its own row: the copy is only on the rows below it.
             . "31/08/2026,Available,VERSACE,VSS-2,Dylan 50ml,,8.01E+12\n"
             . ",,,,,\"At its heart, orange blossom and tonka.\",\n"
             . "31/08/2026,Available,VERSACE,VSS-3,Eros Set,Eros pour femme gift case.,8.01E+12\n";

        $this->submitCsv($user, $store, $csv)->assertSessionHasNoErrors();
        $request = ProductRequest::latest('id')->first();

        try {
            $this->assertSame(['ESB-1', 'VSS-2', 'VSS-3'], $request->skus()->orderBy('id')->pluck('sku')->all());
            $this->assertSame(3, $request->skus()->where('sheet_has_description', true)->count());

            $this->assertFalse((bool) $request->use_ai_content);
            $this->assertFalse($request->stageApplies(ProductRequest::AI_CONTENT));
        } finally {
            File::deleteDirectory(storage_path("app/product-requests/{$request->id}"));
        }
    }

    /** A gap anywhere keeps the AI step, for the missing ones. */
    public function test_a_partly_described_export_keeps_the_ai_step(): void
    {
        Notification::fake();
        Queue::fake();

        $user  = $this->brandManager();
        $store = $this->plainSite();

        $this->submitCsv($user, $store, "SKU,Long Description\nG-1,Written\nG-2,\n")->assertSessionHasNoErrors();
        $request = ProductRequest::latest('id')->first();

        try {
            $this->assertTrue((bool) $request->use_ai_content);
        } finally {
            File::deleteDirectory(storage_path("app/product-requests/{$request->id}"));
        }
    }

    /** "Description" works too when there is no Long Description column. */
    public function test_a_plain_description_column_is_read_too(): void
    {
        Notification::fake();
        Queue::fake();

        $user  = $this->brandManager();
        $store = $this->plainSite();

        $this->submitCsv($user, $store, "Item SKU,Description\nD-1,Lovely\nD-2,\n")->assertSessionHasNoErrors();
        $request = ProductRequest::latest('id')->first();

        try {
            $this->assertTrue((bool) $request->skus()->where('sku', 'D-1')->value('sheet_has_description'));
            $this->assertFalse((bool) $request->skus()->where('sku', 'D-2')->value('sheet_has_description'));
        } finally {
            File::deleteDirectory(storage_path("app/product-requests/{$request->id}"));
        }
    }

    /** With no description column the file says nothing; the sheet check still can. */
    public function test_a_csv_without_a_description_column_leaves_copy_unknown(): void
    {
        Notification::fake();
        Queue::fake();

        $user  = $this->brandManager();
        $store = $this->plainSite();

        $this->submitCsv($user, $store, "SKU,Name\nN-1,Bag\n")->assertSessionHasNoErrors();
        $request = ProductRequest::latest('id')->first();

        try {
            $this->assertNull($request->skus()->where('sku', 'N-1')->value('sheet_has_description'));
        } finally {
            File::deleteDirectory(storage_path("app/product-requests/{$request->id}"));
        }
    }

    /** A CSV without a SKU / Item SKU header is refused, and no request is made. */
    public function test_a_csv_without_a_sku_column_is_refused(): void
    {
        Notification::fake();
        Queue::fake();

        $user  = $this->brandManager();
        $store = $this->plainSite();

        $this->submitCsv($user, $store, "Barcode,Name\n6291,Bag\n")
            ->assertSessionHasErrors(['sku_csv' => 'Please upload a valid CSV. Its first row must have a column named "SKU" or "Item SKU".']);

        // A bare list with no header row is refused too: the first SKU is not a header.
        $this->submitCsv($user, $store, "AB-1\nAB-2\n")->assertSessionHasErrors('sku_csv');

        $this->submitCsv($user, $store, "SKU,Name\n,Bag\n")
            ->assertSessionHasErrors(['sku_csv' => 'The CSV has a SKU column but no SKUs under it. Please check the file and upload it again.']);

        $this->assertSame(0, ProductRequest::count());
    }

    public function test_an_upload_php_rejected_is_reported_not_silently_dropped(): void
    {
        Notification::fake();

        $user  = $this->brandManager();
        $store = $this->mappingSite();
        $user->stores()->sync([$store->id]);

        // PHP marks a file over upload_max_filesize as invalid and hands it to
        // Laravel with an error code; hasFile() then hides it entirely.
        $tooBig = new UploadedFile(
            tempnam(sys_get_temp_dir(), 'big'),
            'huge.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            UPLOAD_ERR_INI_SIZE,
            true,
        );

        $this->actingAs($user)->post(route('product-requests.store'), [
            'store_id'                  => $store->id,
            'request_type'              => 'new_brand',
            'brand'                     => 'Samsonite',
            'category'                  => 'Luggage',
            'skus'                      => 'BIG-1',
            'online_launch_date'        => now()->addDays(18)->format('Y-m-d H:i'),
            'image_source'              => ProductRequest::IMG_PHOTOSHOOT,
            'use_ai_content'            => 0,
            'priority'                  => 'high',
            'content_sheet'             => $tooBig,
        ])->assertSessionHasErrors('content_sheet');

        // The request must not be created with a quietly missing file.
        $this->assertSame(0, ProductRequest::count());
    }

    public function test_a_request_without_ai_flags_that_it_is_awaiting_a_content_sheet(): void
    {
        Notification::fake();

        $user  = $this->brandManager();
        $store = $this->mappingSite();
        $user->stores()->sync([$store->id]);

        // Submitting without the sheet is allowed — it just isn't ready yet.
        $this->actingAs($user)->post(route('product-requests.store'), [
            'store_id'                  => $store->id,
            'request_type'              => 'new_brand',
            'brand'                     => 'Samsonite',
            'category'                  => 'Luggage',
            'skus'                      => 'CS-2',
            'online_launch_date'        => now()->addDays(18)->format('Y-m-d H:i'),
            'image_source'              => ProductRequest::IMG_PHOTOSHOOT,
            'use_ai_content'            => 0,
            'priority'                  => 'high',
        ])->assertRedirect();

        $request = ProductRequest::first();

        // Its SKU is not in Shopify yet, so there is no product to write copy on
        // and nothing to say about it. Nor is there an upload to offer: copy is
        // written here or not at all, never sent in as a file.
        $this->actingAs($user)->get(route('product-requests.show', $request))
            ->assertOk()
            ->assertDontSee('Awaiting content')
            ->assertDontSee('Upload a content sheet');
    }

    public function test_an_ai_request_never_asks_for_a_content_sheet(): void
    {
        Notification::fake();

        $user    = $this->brandManager();
        $request = $this->submitFor($user, $this->mappingSite(), 'AI-1');

        $this->assertTrue($request->use_ai_content);
        $this->assertFalse($request->awaitingContentSheet());
        $this->assertSame('AI Content Generation', $request->stageLabel(ProductRequest::AI_CONTENT));

        $this->actingAs($user)->get(route('product-requests.show', $request))
            ->assertOk()
            ->assertDontSee('Awaiting content sheet');
    }

    // ── Guidance for people who don't know the process ───────────────────────

    public function test_every_stage_tells_you_who_is_responsible_and_what_to_do(): void
    {
        $request = new ProductRequest(['status' => ProductRequest::SUBMITTED, 'use_ai_content' => true]);

        // No stage may be left without guidance — that is the whole point.
        foreach (ProductRequest::PIPELINE as $stage) {
            $guide = $request->guideFor($stage);
            $this->assertNotEmpty($guide['what'], "Stage {$stage} has no guidance text");
        }

        $this->assertSame('Brand Manager', $request->guideFor(ProductRequest::WAITING_MAPPING)['role']);
        $this->assertSame('Photoshoot Coordinator', $request->guideFor(ProductRequest::PHOTOSHOOT_SCHEDULED)['role']);
        // One person per category writes the copy, reviews it and publishes it,
        // so the content stages belong to the E-Commerce owner.
        $this->assertSame('E-Commerce Team', $request->guideFor(ProductRequest::AI_CONTENT)['role']);
        $this->assertSame('E-Commerce Team', $request->guideFor(ProductRequest::QA_REVIEW)['role']);
        $this->assertSame('E-Commerce Team', $request->guideFor(ProductRequest::PUBLISHED)['role']);
    }

    /** The content stage finishes on its own once every product has its copy. */
    public function test_ai_content_moves_on_once_every_product_has_copy(): void
    {
        Notification::fake();

        $user    = $this->brandManager();
        $request = $this->submitFor($user, $this->plainSite(), "AC-1\nAC-2");
        $request->skus()->update(['in_shopify' => true]);   // the products exist
        app(ProductRequestWorkflow::class)->autoAdvance($request->refresh());
        $this->assertSame(ProductRequest::AI_CONTENT, $request->fresh()->status);

        // One supplied by the brand team, one deliberately left.
        $request->skus()->where('sku', 'AC-1')->update(['sheet_has_description' => true]);
        $request->skus()->where('sku', 'AC-2')->update(['content_skipped_at' => now()]);

        app(ProductRequestWorkflow::class)->autoAdvance($request->refresh());

        $this->assertSame(ProductRequest::WAITING_IMAGES, $request->fresh()->status);   // the shoot comes next
    }

    /** No shoot: "Images received" is the person's say-so, then it goes live by itself. */
    public function test_images_received_then_published_once_live(): void
    {
        Notification::fake();

        $user    = $this->brandManager();
        $request = $this->submitFor($user, $this->plainSite(), 'IR-1');
        $request->update([
            'status' => ProductRequest::WAITING_IMAGES, 'use_ai_content' => false,
            'image_source' => ProductRequest::IMG_BRAND_WEBSITE, 'photoshoot_decision' => 'no', 'image_request_decision' => 'yes',
        ]);

        $this->actingAs($user)->post(route('product-requests.images-received', $request))->assertRedirect();

        // Images in, but the products are not live yet: it waits, not closes.
        $this->assertSame(ProductRequest::WAITING_IMAGES, $request->fresh()->status);

        // The hourly check sees them live and published.
        $request->skus()->update(['in_shopify' => true, 'shopify_published' => true]);
        app(ProductRequestWorkflow::class)->autoAdvance($request->refresh());

        $this->assertSame(ProductRequest::PUBLISHED, $request->fresh()->status);
    }

    /** A request's SKU opens to the same colour/size breakdown the SKU Checker shows. */
    public function test_a_requests_sku_shows_its_variants_from_its_own_website(): void
    {
        Notification::fake();

        $user    = $this->brandManager();
        $request = $this->submitFor($user, $this->plainSite(), "VAR-1");

        $shopify = \Mockery::mock(\App\Services\ShopifyService::class);
        $shopify->shouldReceive('getSkuVariantBreakdown')->with('VAR-1', true)
            ->andReturn(['product_title' => 'Trolley', 'product_id' => '42', 'colours' => []]);
        $this->app->bind(\App\Services\ShopifyService::class, fn () => $shopify);

        $this->actingAs($user)->getJson(route('product-requests.variants', [$request, 'sku' => 'VAR-1']))
            ->assertOk()
            ->assertJson(['product_title' => 'Trolley', 'product_id' => '42']);

        // Only this request's SKUs: it is not a way to look up anything in the store.
        $this->actingAs($user)->getJson(route('product-requests.variants', [$request, 'sku' => 'SOMEONE-ELSES']))
            ->assertNotFound();
    }

    /** On a Cegid-mapped website the list is checked before the request is made. */
    public function test_the_check_before_submitting_splits_mapped_from_unmapped(): void
    {
        Notification::fake();

        $user  = $this->brandManager();
        $store = $this->mappingSite();
        $user->stores()->sync([$store->id]);

        $shopify = \Mockery::mock(\App\Services\ShopifyService::class);
        $shopify->shouldReceive('findVariantsBySkus')->andReturn([
            'MAP-1' => [['product_title' => 'Trolley', 'published' => true]],
            'MAP-2' => [['product_title' => 'Case', 'published' => false]],
        ]);
        $this->app->bind(\App\Services\ShopifyService::class, fn () => $shopify);

        $this->actingAs($user)->postJson(route('product-requests.precheck'), [
            'store_id' => $store->id,
            'sku_csv'  => UploadedFile::fake()->createWithContent('list.csv', "SKU\nMAP-1\nMAP-2\nNEW-1\n"),
        ])->assertOk()->assertJson([
            'mapping'  => true,
            'mapped'   => [['sku' => 'MAP-1', 'title' => 'Trolley', 'published' => true], ['sku' => 'MAP-2', 'title' => 'Case', 'published' => false]],
            'unmapped' => ['NEW-1'],
        ]);

        // A website without mapping has nothing to check.
        $plain = $this->plainSite();
        $user->stores()->attach($plain->id);
        $this->actingAs($user)->postJson(route('product-requests.precheck'), ['store_id' => $plain->id, 'skus' => 'X-1'])
            ->assertOk()->assertJson(['mapping' => false]);
    }

    /** Going ahead with only the mapped SKUs leaves the rest out, and says so. */
    public function test_submitting_with_only_the_mapped_skus(): void
    {
        Notification::fake();
        Queue::fake();

        $user  = $this->brandManager();
        $store = $this->mappingSite();
        $user->stores()->sync([$store->id]);

        $this->actingAs($user)->post(route('product-requests.store'), [
            'store_id'           => $store->id,
            'request_type'       => 'new_brand',
            'brand'              => 'Samsonite',
            'category'           => 'Luggage',
            'skus'               => "MAP-1\nMAP-2\nNEW-1",
            'only_skus'          => json_encode(['MAP-1', 'map-2']),
            'online_launch_date' => now()->addDays(18)->format('Y-m-d H:i'),
            'image_source'       => ProductRequest::IMG_PHOTOSHOOT,
            'use_ai_content'     => 1,
            'priority'           => 'high',
        ])->assertSessionHasNoErrors();

        $request = ProductRequest::latest('id')->first();

        $this->assertSame(['MAP-1', 'MAP-2'], $request->skus()->orderBy('id')->pluck('sku')->all());
        $this->assertStringContainsString('1 left out as not mapped in Cegid yet: NEW-1',
            (string) ProductRequestActivity::where('product_request_id', $request->id)->where('action', 'created')->value('remarks'));
    }

    /** With nothing left before going live, it waits: Published has to mean live. */
    public function test_verified_never_moves_itself_onto_published(): void
    {
        Notification::fake();

        $user    = $this->brandManager();
        $request = $this->submitFor($user, $this->plainSite(), 'PUB-1');
        $request->update([
            'status' => ProductRequest::SKU_VERIFIED, 'use_ai_content' => false,
            'image_source' => ProductRequest::IMG_SUPPLIER, 'photoshoot_decision' => 'no', 'image_request_decision' => 'no',
        ]);
        $request->refresh();

        $this->assertSame(ProductRequest::PUBLISHED, $request->suggestedNextStatus());
        $this->assertFalse(app(ProductRequestWorkflow::class)->advancePastVerified($request));
        $this->assertSame(ProductRequest::SKU_VERIFIED, $request->fresh()->status);
    }

    /** An unanswered photoshoot question holds it; answering moves it on. */
    public function test_verified_waits_for_the_photoshoot_answer_then_moves_on(): void
    {
        Notification::fake();

        $user    = $this->brandManager();
        $request = $this->submitFor($user, $this->plainSite(), 'ASK-1');
        $request->skus()->update(['in_shopify' => true]);   // only the question is holding it
        $request->update(['status' => ProductRequest::SKU_VERIFIED, 'photoshoot_decision' => null, 'image_source' => null]);
        $request->refresh();

        $this->assertTrue($request->needsPhotoshootDecision());
        $this->assertFalse(app(ProductRequestWorkflow::class)->advancePastVerified($request));

        $this->actingAs($user)->post(route('product-requests.photoshoot-decision', $request), ['needed' => 'yes'])
            ->assertRedirect();

        $this->assertNotSame(ProductRequest::SKU_VERIFIED, $request->fresh()->status);
    }

    /** Supplier images picked on the form answer "where are the images coming from?". */
    public function test_supplier_images_from_the_form_are_not_asked_about_again(): void
    {
        Notification::fake();

        $user = $this->brandManager();
        $user->stores()->syncWithoutDetaching([$store = $this->plainSite()]);

        $this->actingAs($user)->post(route('product-requests.store'), [
            'store_id'           => $store->id,
            'request_type'       => 'new_brand',
            'brand'              => 'Mosafer',
            'category'           => 'Luggage',
            'skus'               => 'SUP-1',
            'online_launch_date' => now()->addDays(18)->format('Y-m-d H:i'),
            'image_source'       => ProductRequest::IMG_SUPPLIER,
            'images_location'    => ProductRequest::IMAGES_AT_URL,
            'images_url'         => 'https://example.test/folder',
            'use_ai_content'     => 1,
            'priority'           => 'high',
        ])->assertSessionHasNoErrors();

        $request = ProductRequest::latest('id')->first();

        $this->assertFalse($request->needsImageSourceDecision());
        $this->actingAs($user)->get(route('product-requests.show', $request))
            ->assertOk()
            ->assertDontSee('Where are the images coming from?');

        // Nothing left to answer — it waits only for the products to reach
        // Shopify, then moves straight on to the copy.
        $this->assertSame(ProductRequest::SKU_VERIFIED, $request->status);
        $request->skus()->update(['in_shopify' => true]);
        app(ProductRequestWorkflow::class)->autoAdvance($request->refresh());
        $this->assertSame(ProductRequest::AI_CONTENT, $request->fresh()->status);
    }

    /** The list never jumps ahead of products Shopify does not have. */
    public function test_the_list_says_not_mapped_until_the_products_are_on_shopify(): void
    {
        Notification::fake();

        $request = $this->submitFor($this->brandManager(), $this->plainSite(), "NM-1\nNM-2");
        $this->assertSame(ProductRequest::SKU_VERIFIED, $request->status);
        $this->assertSame('SKU Not Mapped', $request->listStatusLabel());

        $request->skus()->update(['in_shopify' => true]);
        $fresh = ProductRequest::find($request->id);
        $fresh->status = ProductRequest::SKU_VERIFIED;   // the hand-off view, before the move
        $this->assertNotSame('SKU Not Mapped', $fresh->listStatusLabel());
    }

    /** "Mapped" is only said where Cegid maps SKUs. */
    public function test_the_verified_stage_is_only_called_mapped_on_a_cegid_website(): void
    {
        Notification::fake();

        $user = $this->brandManager();

        $this->assertSame('SKUs Checked', $this->submitFor($user, $this->plainSite(), 'LBL-1')->stageLabel(ProductRequest::SKU_VERIFIED));
        $this->assertSame('SKU Mapped',   $this->submitFor($user, $this->mappingSite(), 'LBL-2')->stageLabel(ProductRequest::SKU_VERIFIED));
    }

    /** The image source was picked on the form, so SKU Verified does not ask for it again. */
    public function test_sku_verified_guidance_follows_the_image_source_picked_on_the_form(): void
    {
        $shoot    = new ProductRequest(['status' => ProductRequest::SKU_VERIFIED, 'image_source' => ProductRequest::IMG_PHOTOSHOOT]);
        $supplier = new ProductRequest(['status' => ProductRequest::SKU_VERIFIED, 'image_source' => ProductRequest::IMG_SUPPLIER]);

        $this->assertStringContainsString('going to the photoshoot', $shoot->currentGuide()['what']);
        $this->assertStringContainsString('supplier has sent the images', $supplier->currentGuide()['what']);
        $this->assertStringNotContainsString('Confirm where the images are coming from', $shoot->currentGuide()['what']);
    }

    public function test_the_content_stage_guidance_changes_when_the_brand_team_supplies_copy(): void
    {
        $ai     = new ProductRequest(['status' => ProductRequest::AI_CONTENT, 'use_ai_content' => true]);
        $manual = new ProductRequest(['status' => ProductRequest::AI_CONTENT, 'use_ai_content' => false]);

        $this->assertStringContainsString('AI Content Generator', $ai->currentGuide()['what']);
        $this->assertStringContainsString('brand team', $manual->currentGuide()['what']);
    }

    public function test_the_request_page_tells_the_assignee_it_is_their_task(): void
    {
        Notification::fake();

        $user    = $this->brandManager();
        $request = $this->submitFor($user, $this->plainSite(), 'GUIDE-1');

        // Sitting at SKU Verified, which the E-Commerce team drives.
        $this->assign($request, 'assigned_to', $user);

        $this->assertTrue($request->isWaitingOn($user));

        $this->actingAs($user)->get(route('product-requests.show', $request))
            ->assertOk()
            ->assertSee('This is your task');

        // Someone else's stage shouldn't be badged as theirs.
        $other = User::create([
            'name' => 'Bystander', 'email' => 'by@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'ecommerce',
        ]);
        $this->assertFalse($request->isWaitingOn($other));
    }

    public function test_unassigned_work_is_flagged_to_the_team_that_owns_the_stage(): void
    {
        Notification::fake();

        $requester = $this->brandManager();
        $request   = $this->submitFor($requester, $this->plainSite(), 'TEAM-1');

        // At SKU Verified — the products are not on Shopify yet — which the
        // E-Commerce team owns, with nobody named.
        $this->assertSame(ProductRequest::SKU_VERIFIED, $request->status);

        $ecom = User::create([
            'name' => 'Ecom Person', 'email' => 'ecom@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'ecommerce',
        ]);
        $qa = User::create([
            'name' => 'QA Person', 'email' => 'qa@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'qa',
        ]);

        // The E-Commerce person sees it as their team's; QA does not.
        $this->assertSame('my_team', $request->ownershipFor($ecom));
        $this->assertSame('none',    $request->ownershipFor($qa));

        $this->actingAs($ecom)->get(route('product-requests.show', $request))
            ->assertOk()
            ->assertSee('Waiting on your team')
            ->assertSee('Take this task');

        // And it shows up on their Assigned to Me page as unclaimed.
        $this->actingAs($ecom)->get(route('product-requests.my-tasks'))
            ->assertOk()
            ->assertSee('Waiting on your team');

        $this->actingAs($qa)->get(route('product-requests.my-tasks'))
            ->assertOk()
            ->assertDontSee('Waiting on your team');
    }

    public function test_taking_a_task_puts_your_name_on_the_current_stage(): void
    {
        Notification::fake();

        $requester = $this->brandManager();
        $request   = $this->submitFor($requester, $this->plainSite(), 'CLAIM-1');

        $ecom = User::create([
            'name' => 'Ecom Person', 'email' => 'ecom2@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'ecommerce',
        ]);

        $this->assertTrue($request->claimableBy($ecom));

        $this->actingAs($ecom)->post(route('product-requests.claim', $request))->assertRedirect();

        $request->refresh();

        $this->assertSame($ecom->id, $this->ownerId($request, 'assigned_to'));
        $this->assertSame('mine', $request->ownershipFor($ecom));

        // Claiming is recorded, so there is no mystery about who picked it up.
        $this->assertTrue(
            ProductRequestActivity::where('product_request_id', $request->id)
                ->where('description', 'like', '%took this task%')->exists()
        );

        // A second person cannot take it off them.
        $other = User::create([
            'name' => 'Other Ecom', 'email' => 'ecom3@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'ecommerce',
        ]);
        $this->assertFalse($request->claimableBy($other));
        $this->actingAs($other)->post(route('product-requests.claim', $request))
            ->assertSessionHasErrors('claim');

        $this->assertSame($ecom->id, $this->ownerId($request->fresh(), 'assigned_to'));
    }

    // ── Blocked work and hand-over ───────────────────────────────────────────

    public function test_a_photographer_can_report_that_samples_never_arrived(): void
    {
        Notification::fake();

        $requester = $this->brandManager();
        $request   = $this->submitFor($requester, $this->plainSite(), 'HOLD-1');

        $shooter = User::create([
            'name' => 'Shooter', 'email' => 'shoot@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'photographer',
        ]);

        $request->update(['status' => ProductRequest::PHOTOSHOOT_SCHEDULED]);
        $this->assign($request->refresh(), 'photographer_id', $shooter);

        $this->actingAs($shooter)->post(route('product-requests.hold', $request), [
            'hold_reason' => 'Samples not received at studio',
        ])->assertRedirect();

        $request->refresh();

        $this->assertTrue($request->isOnHold());
        $this->assertSame('Samples not received at studio', $request->hold_reason);
        $this->assertSame($shooter->id, $request->hold_by);
        $this->assertNotNull($request->hold_since);

        // The stage does not move — the request is blocked where it stands.
        $this->assertSame(ProductRequest::PHOTOSHOOT_SCHEDULED, $request->status);

        // Everyone involved hears about it, including the person who raised it.
        Notification::assertSentTo($requester, ProductRequestHoldChanged::class);

        $this->actingAs($shooter)->get(route('product-requests.show', $request))
            ->assertOk()
            ->assertSee('On hold — work is blocked')
            ->assertSee('Samples not received at studio');
    }

    public function test_a_free_text_blocker_is_accepted_and_can_be_cleared(): void
    {
        Notification::fake();

        $user    = $this->brandManager();
        $request = $this->submitFor($user, $this->plainSite(), 'HOLD-2');

        $this->actingAs($user)->post(route('product-requests.hold', $request), [
            'hold_reason_other' => 'Only 12 of 45 samples arrived',
        ])->assertRedirect();

        $this->assertSame('Only 12 of 45 samples arrived', $request->fresh()->hold_reason);

        $this->actingAs($user)->post(route('product-requests.resume', $request))->assertRedirect();

        $request->refresh();

        $this->assertFalse($request->isOnHold());
        $this->assertNull($request->hold_reason);
        $this->assertNull($request->hold_by);

        // Both events are in the trail, so the stall is auditable after the fact.
        $trail = ProductRequestActivity::where('product_request_id', $request->id)->pluck('action');
        $this->assertContains('on_hold', $trail->all());
        $this->assertContains('resumed', $trail->all());
    }

    public function test_a_blocker_needs_a_reason(): void
    {
        Notification::fake();

        $user    = $this->brandManager();
        $request = $this->submitFor($user, $this->plainSite(), 'HOLD-3');

        $this->actingAs($user)->post(route('product-requests.hold', $request), [
            'hold_reason'       => '',
            'hold_reason_other' => '   ',
        ])->assertSessionHasErrors('hold_reason');

        $this->assertFalse($request->fresh()->isOnHold());
    }

    public function test_a_handover_keeps_the_previous_owner_as_history(): void
    {
        Notification::fake();

        $requester = $this->brandManager();
        $request   = $this->submitFor($requester, $this->plainSite(), 'HIST-1');

        $first  = User::create(['name' => 'Holder One', 'email' => 'ho1@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'ecommerce']);
        $second = User::create(['name' => 'Holder Two', 'email' => 'ho2@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'ecommerce']);

        $this->assign($request, 'assigned_to', $first);

        // Backdate at the query level — created_at is not fillable.
        DB::table('product_request_assignments')
            ->where('product_request_id', $request->id)
            ->update(['created_at' => now()->subDays(4)]);

        $this->assign($request, 'assigned_to', $second);

        // Two rows, one live. The old owner columns could not do this: a handover
        // simply overwrote them and the previous holder was gone.
        $this->assertSame(2, $request->assignments()->count());
        $this->assertSame(1, $request->currentAssignments()->count());
        $this->assertSame($second->id, $this->ownerId($request, 'assigned_to'));

        $history = $request->refresh()->ownershipHistory();

        $this->assertCount(2, $history);
        $this->assertSame('Holder One', $history[0]['user']);
        $this->assertSame(4, $history[0]['days']);          // held it for four days
        $this->assertFalse($history[0]['current']);
        $this->assertSame('Holder Two', $history[1]['user']);
        $this->assertTrue($history[1]['current']);

        // The previous holder no longer has it in their task list.
        $this->assertFalse(ProductRequest::assignedTo($first)->whereKey($request->id)->exists());
        $this->assertTrue(ProductRequest::assignedTo($second)->whereKey($request->id)->exists());

        // And the request page shows the trail.
        $this->actingAs($requester)->get(route('product-requests.show', $request))
            ->assertOk()
            ->assertSee('Ownership History')
            ->assertSee('Holder One');
    }

    public function test_clearing_a_role_closes_its_assignment_rather_than_deleting_history(): void
    {
        Notification::fake();

        $requester = $this->brandManager();
        $request   = $this->submitFor($requester, $this->plainSite(), 'HIST-2');

        $editor = User::create(['name' => 'Ed Hist', 'email' => 'edh@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'image_editor']);

        $this->assign($request, 'image_editor_id', $editor);
        $this->assign($request, 'image_editor_id', null);

        $this->assertNull($this->ownerId($request, 'image_editor_id'));
        $this->assertSame(0, $request->currentAssignments()->count());

        // The record of who held it survives being unassigned.
        $this->assertSame(1, $request->assignments()->count());
        $this->assertNotNull($request->assignments()->first()->ended_at);
    }

    public function test_a_handover_emails_both_the_new_and_the_previous_owner(): void
    {
        Notification::fake();

        $requester = $this->brandManager();
        $request   = $this->submitFor($requester, $this->plainSite(), 'HANDMAIL-1');

        $first  = User::create(['name' => 'First Owner', 'email' => 'fo@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'ecommerce']);
        $second = User::create(['name' => 'Second Owner', 'email' => 'so@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'ecommerce']);

        $workflow = app(ProductRequestWorkflow::class);
        $workflow->assignRole($request, 'assigned_to', $first->id, $requester);

        Notification::fake();   // only look at the handover itself

        $this->actingAs($requester)->post(route('product-requests.reassign', $request->refresh()), [
            'user_id' => $second->id,
        ])->assertRedirect();

        // The new owner is told, and told it was a handover, not a fresh assignment.
        Notification::assertSentTo($second, ProductRequestAssigned::class,
            fn ($n) => $n->handedOverFrom === 'First Owner');

        // The previous owner is told they are off it — otherwise they keep working.
        Notification::assertSentTo($first, ProductRequestHandedOff::class,
            fn ($n) => $n->newOwnerName === 'Second Owner' && $n->roleLabel === 'E-Commerce Team');

        $this->assertSame($second->id, $this->ownerId($request->fresh(), 'assigned_to'));
    }

    public function test_the_handover_emails_render(): void
    {
        $requester = $this->brandManager();
        $request   = $this->submitFor($requester, $this->plainSite(), 'HANDRENDER-1');

        $to = User::create(['name' => 'New Owner', 'email' => 'no@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'ecommerce']);

        // New owner's copy reads as a handover.
        $mail = ProductRequestAssigned::forRequest($request, 'E-Commerce Team', $requester->name, 'Old Owner')
            ->toMail($to);
        $html = $mail->render();

        $this->assertStringContainsString('handed this task over to you', $html);
        $this->assertStringContainsString('Old Owner', $html);
        $this->assertStringContainsString('handed over to you', $mail->subject);

        // Previous owner's copy tells them to stop.
        $off = ProductRequestHandedOff::forRequest($request, 'E-Commerce Team', 'New Owner', $requester->name)
            ->toMail($to);
        $offHtml = $off->render();

        $this->assertStringContainsString('No longer with you', $offHtml);
        $this->assertStringContainsString('New Owner', $offHtml);
        $this->assertStringContainsString('aih_logo-1.png', $offHtml);
    }

    public function test_a_task_can_be_handed_to_someone_else(): void
    {
        Notification::fake();

        $requester = $this->brandManager();
        $request   = $this->submitFor($requester, $this->plainSite(), 'HAND-1');

        $first = User::create([
            'name' => 'First Shooter', 'email' => 'first@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'photographer',
        ]);
        $second = User::create([
            'name' => 'Second Shooter', 'email' => 'second@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'photographer',
        ]);

        $request->update(['status' => ProductRequest::PHOTOSHOOT_SCHEDULED]);
        $this->assign($request->refresh(), 'photographer_id', $first);

        $this->actingAs($first)->post(route('product-requests.reassign', $request), [
            'user_id' => $second->id,
        ])->assertRedirect();

        $request->refresh();

        // Hand-over writes to the stage's own slot, not some generic field.
        $this->assertSame($second->id, $this->ownerId($request, 'photographer_id'));
        $this->assertSame('mine', $request->ownershipFor($second));
        $this->assertSame('other', $request->ownershipFor($first));

        Notification::assertSentTo($second, ProductRequestAssigned::class);
    }

    public function test_mapping_and_image_editing_have_owners_of_their_own(): void
    {
        Notification::fake();

        $requester = $this->brandManager();
        $request   = $this->submitFor($requester, $this->mappingSite(), 'ROLE-1');

        // Waiting for Mapping names the brand manager: they map in Cegid
        // themselves, and there is no Supply Chain team to hand it to.
        $this->assertSame(ProductRequest::WAITING_MAPPING, $request->status);
        $this->assertSame('brand_manager_id', $request->currentGuide()['field']);

        $manager = User::create([
            'name' => 'Mapping Manager', 'email' => 'map@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'brand_manager',
        ]);
        $editor = User::create([
            'name' => 'Editor Person', 'email' => 'ed@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'image_editor',
        ]);

        // Unclaimed mapping work reaches the brand managers.
        $this->assertSame('my_team', $request->ownershipFor($manager));

        $this->actingAs($requester)->post(route('product-requests.assign', $request), [
            'brand_manager_id' => $manager->id,
            'image_editor_id'  => $editor->id,
        ])->assertRedirect();

        $request->refresh();

        $this->assertSame($manager->id, $this->ownerId($request, 'brand_manager_id'));
        $this->assertSame('mine', $request->ownershipFor($manager));
        Notification::assertSentTo($manager, ProductRequestAssigned::class);

        // Image Editing is retired, so a request still parked there belongs to
        // whoever runs it rather than to a Photo Editor nobody appoints now.
        $request->update(['status' => ProductRequest::IMAGE_EDITING]);
        $request->refresh();

        $this->assertSame('E-Commerce Team', $request->currentGuide()['role']);
        $this->assertSame('assigned_to', $request->currentGuide()['field']);
        $this->assertContains(ProductRequest::IMAGE_EDITING, ProductRequest::RETIRED_STAGES);

        // The editor's own assignment is not lost — it is still shown and can be
        // cleared, which is the point of retiring a role instead of deleting it.
        $this->assertSame($editor->id, $this->ownerId($request, 'image_editor_id'));
        $this->assertArrayHasKey('image_editor_id', $request->visibleAssignmentRoles());
    }

    public function test_the_assignment_panel_offers_every_applicable_role(): void
    {
        Notification::fake();

        $user    = $this->brandManager();
        $request = $this->submitFor($user, $this->mappingSite(), 'ROLE-2');   // photoshoot required

        $page = $this->actingAs($user)->get(route('product-requests.show', $request))->assertOk();

        foreach ($request->visibleAssignmentRoles() as $field => $label) {
            $page->assertSee($label);
            $page->assertSee('name="' . $field . '"', false);
        }

        // With a shoot, that means every live role — QA is retired, so not it.
        $this->assertCount(count(ProductRequest::assignableRoles()), $request->visibleAssignmentRoles());
        $this->assertArrayNotHasKey('qa_owner_id', $request->visibleAssignmentRoles());
    }

    public function test_image_editing_is_dropped_when_there_is_no_photoshoot(): void
    {
        Notification::fake();

        $user    = $this->brandManager();
        $request = $this->submitFor($user, $this->plainSite(), 'NOEDIT-1');

        $request->update([
            'image_source' => ProductRequest::IMG_SUPPLIER, 'photoshoot_required' => false,
            'supplier_images_available' => true, 'photoshoot_decision' => 'no',
            'status' => ProductRequest::SKU_VERIFIED,   // where the suggestion is asked from
        ]);
        $request->refresh();

        $stages = $request->displayStages();

        // Nothing of ours was shot, so there is nothing of ours to edit.
        $this->assertNotContains(ProductRequest::IMAGE_EDITING, $stages);
        $this->assertNotContains(ProductRequest::PHOTOSHOOT_SCHEDULED, $stages);
        $this->assertNotContains(ProductRequest::WAITING_IMAGES, $stages);

        // Supplier images and no QA leaves the shortest run there is: submitted,
        // verified, the copy, published.
        $this->assertContains(ProductRequest::AI_CONTENT, $stages);
        $this->assertCount(4, $stages);

        // The suggestion must land on a stage this request actually has, and the
        // copy comes before anything else that is left.
        $this->assertSame(ProductRequest::AI_CONTENT, $request->suggestedNextStatus());

        // And the Move Stage dropdown must not offer it either.
        $this->assertNotContains(ProductRequest::IMAGE_EDITING, $request->allowedTransitions());

        $this->actingAs($user)->get(route('product-requests.show', $request))
            ->assertOk()
            ->assertDontSee('Image Editing');
    }

    public function test_the_brand_website_option_is_retired_but_legacy_requests_keep_working(): void
    {
        Notification::fake();

        // Not offered any more…
        $this->assertArrayNotHasKey(ProductRequest::IMG_BRAND_WEBSITE, ProductRequest::selectableImageSources());
        $this->assertCount(2, ProductRequest::selectableImageSources());

        $user  = $this->brandManager();
        $store = $this->mappingSite();
        $user->stores()->sync([$store->id]);

        $this->actingAs($user)->post(route('product-requests.store'), [
            'store_id'           => $store->id,
            'request_type'       => 'new_brand',
            'brand'              => 'Samsonite',
            'category'           => 'Luggage',
            'skus'               => 'RETIRED-1',
            'online_launch_date' => now()->addDays(18)->format('Y-m-d H:i'),
            'image_source'       => ProductRequest::IMG_BRAND_WEBSITE,
            'use_ai_content'     => 1,
            'priority'           => 'high',
        ])->assertSessionHasErrors('image_source');

        $this->assertSame(0, ProductRequest::count());
    }

    public function test_a_legacy_brand_website_request_keeps_its_stages_and_can_be_changed(): void
    {
        Notification::fake();

        $user    = $this->brandManager();
        $request = $this->submitFor($user, $this->plainSite(), 'WEB-1');

        $request->update([
            'image_source'              => ProductRequest::IMG_BRAND_WEBSITE,
            'photoshoot_required'       => false,
            'supplier_images_available' => false,
            'photoshoot_decision'       => 'no',
        ]);
        $request->refresh();

        $stages = $request->displayStages();

        // Someone still has to fetch them, but there is no studio shoot and no
        // separate editing stage — whoever produces the images finishes them.
        $this->assertContains(ProductRequest::WAITING_IMAGES, $stages);
        $this->assertNotContains(ProductRequest::IMAGE_EDITING, $stages);
        $this->assertNotContains(ProductRequest::PHOTOSHOOT_SCHEDULED, $stages);
        $this->assertNotContains(ProductRequest::PHOTOSHOOT_COMPLETED, $stages);

        // The gathering stage says where to get them from.
        $this->assertStringContainsString(
            'brand website',
            $request->guideFor(ProductRequest::WAITING_IMAGES)['what']
        );

        // Neither photography role is offered: there is no shoot, and editing is
        // no longer a role of its own.
        $roles = $request->visibleAssignmentRoles();
        $this->assertArrayNotHasKey('image_editor_id', $roles);
        $this->assertArrayNotHasKey('photographer_id', $roles);

        // The edit form still shows its current value, so it is not stuck on a
        // setting nobody can see or change.
        $this->assertArrayHasKey(ProductRequest::IMG_BRAND_WEBSITE, $request->imageSourceOptions());

        $this->actingAs($user)->get(route('product-requests.show', $request))
            ->assertOk()
            ->assertSee('Take the images from the brand website');
    }

    public function test_a_photoshoot_request_keeps_the_full_pipeline(): void
    {
        Notification::fake();

        $user    = $this->brandManager();
        $request = $this->submitFor($user, $this->plainSite(), 'FULL-1');   // photoshoot required

        $stages = $request->displayStages();

        foreach ([ProductRequest::WAITING_IMAGES, ProductRequest::PHOTOSHOOT_SCHEDULED,
                  ProductRequest::PHOTOSHOOT_COMPLETED] as $stage) {
            $this->assertContains($stage, $stages);
        }

        // Editing is part of the shoot, so it is not a stage of its own.
        $this->assertNotContains(ProductRequest::IMAGE_EDITING, $stages);
        $this->assertCount(7, $stages);

        // The copy is written from the products, not from the photographs, so it
        // sits before the shoot rather than waiting on it.
        $this->assertLessThan(
            array_search(ProductRequest::WAITING_IMAGES, $stages, true),
            array_search(ProductRequest::AI_CONTENT, $stages, true),
        );
    }

    public function test_photography_roles_are_hidden_when_there_is_no_photoshoot(): void
    {
        Notification::fake();

        $user    = $this->brandManager();
        $request = $this->submitFor($user, $this->plainSite(), 'NOSHOOT-1');

        $request->update([
            'image_source' => ProductRequest::IMG_SUPPLIER, 'photoshoot_required' => false,
            'supplier_images_available' => true, 'photoshoot_decision' => 'no',
        ]);
        $request->refresh();

        $roles = $request->visibleAssignmentRoles();

        // No shoot means nothing to photograph and nothing to edit.
        $this->assertArrayNotHasKey('photographer_id', $roles);
        $this->assertArrayNotHasKey('image_editor_id', $roles);

        // What is left is the request itself and the brand side of it.
        $this->assertArrayHasKey('assigned_to', $roles);
        $this->assertArrayHasKey('brand_manager_id', $roles);
        $this->assertArrayNotHasKey('content_owner_id', $roles);

        $this->actingAs($user)->get(route('product-requests.show', $request))
            ->assertOk()
            ->assertDontSee('name="photographer_id"', false)
            ->assertDontSee('name="image_editor_id"', false);
    }

    public function test_the_retired_qa_role_is_not_offered_but_is_not_stranded(): void
    {
        Notification::fake();

        $user    = $this->brandManager();
        $request = $this->submitFor($user, $this->plainSite(), 'QARET-1');

        // Not offered on a new request — QA Review belongs to the Content Team.
        $this->assertArrayNotHasKey('qa_owner_id', ProductRequest::assignableRoles());
        $this->assertArrayNotHasKey('qa_owner_id', $request->visibleAssignmentRoles());

        $this->actingAs($user)->get(route('product-requests.show', $request))
            ->assertOk()
            ->assertDontSee('name="qa_owner_id"', false);

        // But a request that already has one still shows it, so it can be cleared
        // rather than being stuck with an invisible assignee.
        $qa = User::create(['name' => 'Old QA', 'email' => 'oldqa@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true]);
        $this->assign($request, 'qa_owner_id', $qa);

        $this->assertArrayHasKey('qa_owner_id', $request->refresh()->visibleAssignmentRoles());

        $this->actingAs($user)->get(route('product-requests.show', $request))
            ->assertOk()
            ->assertSee('name="qa_owner_id"', false);

        // And clearing it works.
        $this->actingAs($user)->post(route('product-requests.assign', $request), ['qa_owner_id' => null]);
        $this->assertNull($this->ownerId($request->fresh(), 'qa_owner_id'));
        $this->assertArrayNotHasKey('qa_owner_id', $request->fresh()->visibleAssignmentRoles());
    }

    public function test_a_hidden_role_reappears_if_it_is_in_use(): void
    {
        Notification::fake();

        $user    = $this->brandManager();
        $editor  = User::create(['name' => 'Ed Vis', 'email' => 'edvis@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'image_editor']);
        $request = $this->submitFor($user, $this->plainSite(), 'REAPPEAR-1');

        // Someone already holds the role — hiding it would strand the assignment.
        $request->update([
            'image_source' => ProductRequest::IMG_SUPPLIER, 'photoshoot_required' => false,
            'photoshoot_decision' => 'no',
        ]);
        $this->assign($request, 'image_editor_id', $editor);
        $this->assertArrayHasKey('image_editor_id', $request->refresh()->visibleAssignmentRoles());

        // Cleared, it is gone for good — the role is retired, so nothing offers
        // it again even on a request sitting at the old editing stage.
        $this->assign($request, 'image_editor_id', null);
        $request->update(['status' => ProductRequest::IMAGE_EDITING]);
        $this->assertArrayNotHasKey('image_editor_id', $request->refresh()->visibleAssignmentRoles());
    }

    public function test_an_assigned_person_can_be_swapped_for_someone_else(): void
    {
        Notification::fake();

        $requester = $this->brandManager();
        $request   = $this->submitFor($requester, $this->plainSite(), 'SWAP-1');

        $first  = User::create(['name' => 'First QA', 'email' => 'q1s@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'qa']);
        $second = User::create(['name' => 'Second QA', 'email' => 'q2s@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'qa']);

        $this->actingAs($requester)->post(route('product-requests.assign', $request), [
            'qa_owner_id' => $first->id,
        ])->assertRedirect();

        $this->assertSame($first->id, $this->ownerId($request->fresh(), 'qa_owner_id'));

        // Changing the dropdown to someone else must actually swap them.
        $this->actingAs($requester)->post(route('product-requests.assign', $request), [
            'qa_owner_id' => $second->id,
        ])->assertRedirect();

        $request->refresh();

        $this->assertSame($second->id, $this->ownerId($request, 'qa_owner_id'));
        $this->assertSame($second->id, $request->assignmentFor('qa_owner_id')->user_id);
        Notification::assertSentTo($second, ProductRequestAssigned::class);

        // And clearing it back to Unassigned works too.
        $this->actingAs($requester)->post(route('product-requests.assign', $request), [
            'qa_owner_id' => null,
        ])->assertRedirect();

        $this->assertNull($this->ownerId($request->fresh(), 'qa_owner_id'));
    }

    public function test_the_launch_moment_keeps_its_time(): void
    {
        Notification::fake();

        $user  = $this->brandManager();
        $store = $this->mappingSite();
        $user->stores()->sync([$store->id]);

        $launch = now()->addDays(6)->setTime(9, 30);

        $this->actingAs($user)->post(route('product-requests.store'), [
            'store_id'                  => $store->id,
            'request_type'              => 'new_brand',
            'brand'                     => 'Samsonite',
            'category'                  => 'Luggage',
            'skus'                      => 'TIME-1',
            'online_launch_date'        => $launch->format('Y-m-d H:i'),
            'image_source'              => ProductRequest::IMG_PHOTOSHOOT,
            'use_ai_content'            => 1,
            'priority'                  => 'high',
        ])->assertRedirect();

        $request = ProductRequest::first();

        // A time, not just a date — a 09:30 launch is not the same as midnight.
        $this->assertSame($launch->format('Y-m-d H:i'), $request->online_launch_date->format('Y-m-d H:i'));
        $this->assertStringContainsString('09:30', $request->launchLabel());

        // Day-level countdown still reads naturally.
        $this->assertSame(6, $request->daysToOnlineLaunch());
        $this->assertFalse($request->isOverdue());
    }

    public function test_a_launch_is_overdue_once_the_moment_passes_not_at_midnight(): void
    {
        Notification::fake();

        $user    = $this->brandManager();
        $request = $this->submitFor($user, $this->plainSite(), 'LATE-1');

        // Earlier today: the launch window has gone, so it is late now — waiting
        // for midnight to admit it would hide a whole day of slippage.
        $request->update(['online_launch_date' => now()->subHours(2)]);
        $this->assertTrue($request->fresh()->isOverdue());

        // Later today is not late.
        $request->update(['online_launch_date' => now()->addHours(2)]);
        $this->assertFalse($request->fresh()->isOverdue());

        // And a published request is never chased, whatever the clock says.
        $request->update(['online_launch_date' => now()->subDays(3), 'status' => ProductRequest::PUBLISHED]);
        $this->assertFalse($request->fresh()->isOverdue());
    }

    public function test_a_request_is_listed_by_its_name_with_the_reference_kept(): void
    {
        Notification::fake();

        $user  = $this->brandManager();
        $store = $this->mappingSite();
        $user->stores()->sync([$store->id]);

        $this->actingAs($user)->post(route('product-requests.store'), [
            'store_id'                  => $store->id,
            'name'                      => 'New Balance Running SS26 launch',
            'request_type'              => 'new_brand',
            'brand'                     => 'New Balance',
            'category'                  => "Men's Fashion",
            'skus'                      => 'NB-1',
            'online_launch_date'        => now()->addDays(18)->format('Y-m-d H:i'),
            'image_source'              => ProductRequest::IMG_PHOTOSHOOT,
            'use_ai_content'            => 1,
            'priority'                  => 'high',
        ])->assertRedirect();

        $request = ProductRequest::first();
        $this->assertSame('New Balance Running SS26 launch', $request->name);

        // Name leads, but the reference is never lost — it is the handle people quote.
        $this->actingAs($user)->get(route('product-requests.list'))
            ->assertOk()
            ->assertSee('New Balance Running SS26 launch')
            ->assertSee($request->reference);

        // And it is searchable by name.
        $this->actingAs($user)->get(route('product-requests.list', ['search' => 'SS26']))
            ->assertOk()
            ->assertSee('New Balance Running SS26 launch');
    }

    public function test_a_request_without_a_name_falls_back_to_brand_and_category(): void
    {
        Notification::fake();

        $user    = $this->brandManager();
        $request = $this->submitFor($user, $this->mappingSite(), 'NONAME-1');

        $this->assertNull($request->name);
        $this->assertSame('Samsonite · Luggage', $request->displayName());

        $this->actingAs($user)->get(route('product-requests.list'))
            ->assertOk()
            ->assertSee('Samsonite · Luggage', false);
    }

    // ── Comments, reminders, AI content, bulk actions ────────────────────────

    public function test_a_comment_notifies_the_people_on_the_request(): void
    {
        Notification::fake();

        $author  = $this->brandManager();
        $request = $this->submitFor($author, $this->plainSite(), 'CMT-1');

        $qa = User::create([
            'name' => 'Qadir Ahmed', 'email' => 'qa2@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'qa',
        ]);
        $this->assign($request, 'qa_owner_id', $qa);

        $this->actingAs($author)->post(route('product-requests.comment', $request), [
            'remarks' => '@Qadir can you check the sizing copy?',
        ])->assertRedirect();

        Notification::assertSentTo($qa, ProductRequestCommented::class,
            fn ($n) => $n->mentioned === true && str_contains($n->body, 'sizing copy'));

        // The author isn't notified about their own comment.
        Notification::assertNotSentTo($author, ProductRequestCommented::class);
    }

    public function test_the_reminder_digest_finds_stalled_blocked_and_due_work(): void
    {
        Notification::fake();

        $user  = $this->brandManager();
        $store = $this->plainSite();

        $make = function (string $brand, array $attrs) use ($user, $store) {
            return ProductRequest::create(array_merge([
                'reference'         => ProductRequest::nextReference(),
                'user_id'           => $user->id,
                'store_id'          => $store->id,
                'request_type'      => 'new_brand',
                'brand'             => $brand,
                'category'          => 'X',
                'status'            => ProductRequest::SKU_VERIFIED,
                'priority'          => 'medium',
                'assigned_to'       => $user->id,
                'online_launch_date'=> now()->addDays(30),
            ], $attrs));
        };

        $stale = $make('Stale', []);
        ProductRequest::where('id', $stale->id)->update(['updated_at' => now()->subDays(6)]);
        $make('DueSoon', ['online_launch_date' => now()->addDays(1)]);
        $make('Blocked', ['on_hold' => true, 'hold_reason' => 'Samples not received at studio',
                          'hold_since' => now()->subDays(4), 'hold_by' => $user->id]);
        $make('Healthy', []);   // must NOT be chased

        $this->artisan('product-requests:remind')->assertSuccessful();

        Notification::assertSentTo($user, ProductRequestReminder::class, function ($n) {
            $refs = collect($n->items)->pluck('reason')->implode(' | ');

            return count($n->items) === 3                       // Healthy excluded
                && str_contains($refs, 'no movement')
                && str_contains($refs, 'launches in 1 day')
                && str_contains($refs, 'blocked 4 days');
        });
    }

    public function test_ai_content_needs_skus_that_exist_in_shopify(): void
    {
        Notification::fake();

        $user    = $this->brandManager();
        $request = $this->submitFor($user, $this->plainSite(), "AI-1\nAI-2");

        // Nothing is live yet, so there are no images to generate from.
        $this->assertFalse($request->canGenerateAiContent());

        $this->actingAs($user)->post(route('product-requests.ai-content', $request))
            ->assertSessionHasErrors('ai');

        $this->assertNull($request->fresh()->ai_content_session_id);

        // One SKU goes live — generate for that one and skip the other.
        $request->skus()->limit(1)->update(['in_shopify' => true]);
        $request->refresh();

        $this->assertTrue($request->canGenerateAiContent());

        $this->actingAs($user)->post(route('product-requests.ai-content', $request))->assertRedirect();

        $request->refresh();
        $this->assertNotNull($request->ai_content_session_id);
        $this->assertSame(1, $request->aiContentSession->total_items);
    }

    public function test_generating_from_a_request_hands_the_job_its_sku_list(): void
    {
        Notification::fake();
        Queue::fake();   // the real job would call Gemini

        $user    = $this->brandManager();
        $request = $this->submitFor($user, $this->plainSite(), "aicon-1\nAICON-2");

        $request->skus()->update(['in_shopify' => true]);

        $this->actingAs($user)->post(route('product-requests.ai-content', $request))->assertRedirect();

        $session = AiContentSession::latest('id')->firstOrFail();

        // The job reads skus_json. This path only ever set sku_raw, so the job
        // found nothing to do and reported itself ready — "Progress 0 / 1" with no
        // error anywhere, which looked like Gemini being slow for ten minutes.
        $this->assertSame(['AICON-1', 'AICON-2'], json_decode($session->skus_json, true));
        $this->assertSame(2, $session->total_items);

        Queue::assertPushed(GenerateAiContentJob::class);
    }

    public function test_bulk_actions_apply_to_many_requests_and_skip_what_cannot_change(): void
    {
        Notification::fake();

        $user  = $this->brandManager();
        $store = $this->plainSite();

        $a = $this->submitFor($user, $store, 'BULK-1');
        $b = $this->submitFor($user, $store, 'BULK-2');
        $closed = $this->submitFor($user, $store, 'BULK-3');
        $closed->update(['status' => ProductRequest::COMPLETED]);

        $this->actingAs($user)->post(route('product-requests.bulk'), [
            'action'   => 'priority',
            'ids'      => [$a->id, $b->id, $closed->id],
            'priority' => 'low',
        ])->assertRedirect();

        $this->assertSame('low', $a->fresh()->priority);
        $this->assertSame('low', $b->fresh()->priority);
        $this->assertNotSame('low', $closed->fresh()->priority);   // closed is skipped

        // Assignment in bulk notifies each new owner.
        $editor = User::create([
            'name' => 'Bulk Editor', 'email' => 'be@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'image_editor',
        ]);

        $this->actingAs($user)->post(route('product-requests.bulk'), [
            'action'  => 'assign',
            'ids'     => [$a->id, $b->id],
            'field'   => 'image_editor_id',
            'user_id' => $editor->id,
        ])->assertRedirect();

        $this->assertSame($editor->id, $this->ownerId($a->fresh(), 'image_editor_id'));
        $this->assertSame($editor->id, $this->ownerId($b->fresh(), 'image_editor_id'));
        Notification::assertSentTo($editor, ProductRequestAssigned::class);
    }

    public function test_bulk_actions_cannot_touch_requests_you_cannot_see(): void
    {
        Notification::fake();

        $owner  = $this->brandManager();
        $hidden = $this->submitFor($owner, $this->plainSite(), 'HIDDEN-1');

        $outsider = User::create([
            'name' => 'Outsider', 'email' => 'out@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => null,
        ]);

        $this->actingAs($outsider)->post(route('product-requests.bulk'), [
            'action'   => 'priority',
            'ids'      => [$hidden->id],
            'priority' => 'low',
        ])->assertRedirect();

        $this->assertNotSame('low', $hidden->fresh()->priority);
    }

    public function test_the_requester_can_assign_the_team_when_raising_the_request(): void
    {
        Notification::fake();

        $requester = $this->brandManager();
        $store     = $this->mappingSite();
        $requester->stores()->sync([$store->id]);

        $shooter = User::create(['name' => 'Shooter One', 'email' => 's1@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'photographer']);
        $qa = User::create(['name' => 'QA One', 'email' => 'q1@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'qa']);

        $this->actingAs($requester)->post(route('product-requests.store'), [
            'store_id'                  => $store->id,
            'request_type'              => 'new_brand',
            'brand'                     => 'Samsonite',
            'category'                  => 'Luggage',
            'skus'                      => 'TEAM-1',
            'online_launch_date'        => now()->addDays(18)->format('Y-m-d H:i'),
            'image_source'              => ProductRequest::IMG_PHOTOSHOOT,
            'use_ai_content'            => 1,
            'priority'                  => 'high',
            'assignments'               => [
                ['role' => 'photographer_id', 'user_id' => $shooter->id,
                 'due_date' => now()->addDays(6)->toDateString()],
                ['role' => 'qa_owner_id',     'user_id' => $qa->id],
                ['role' => 'assigned_to',     'user_id' => $requester->id],   // themselves
                ['role' => '',                'user_id' => ''],               // untouched row
            ],
        ])->assertRedirect();

        $request = ProductRequest::first();

        $this->assertSame($shooter->id, $this->ownerId($request, 'photographer_id'));
        $this->assertSame($qa->id, $this->ownerId($request, 'qa_owner_id'));

        // Each assignee is told, and the message names who raised it.
        Notification::assertSentTo($shooter, ProductRequestAssigned::class,
            fn ($n) => $n->roleLabel === 'Photoshoot Coordinator' && $n->requesterName === $requester->name);
        Notification::assertSentTo($qa, ProductRequestAssigned::class);

        // Assigning yourself is not announced to yourself.
        Notification::assertNotSentTo($requester, ProductRequestAssigned::class);

        // The assignee can see who wants it, from their own task list. Asked of
        // the QA owner rather than the photographer: photoshoot work is run from
        // the Photoshoot Schedule and deliberately kept off this list.
        $this->actingAs($qa)->get(route('product-requests.my-tasks'))
            ->assertOk()
            ->assertSee($requester->name)
            ->assertSee('QA Team');

        $this->actingAs($shooter)->get(route('product-requests.my-tasks'))
            ->assertOk()
            ->assertSee('Photoshoot Schedule');

        // The task is taken from the workflow, not typed by the requester.
        $brief = $request->assignmentFor('photographer_id');
        $this->assertNotNull($brief);
        $this->assertSame(ProductRequest::taskForRole('photographer_id'), $brief->title);
        $this->assertStringContainsString('Arrange the shoot', $brief->title);
        $this->assertSame(now()->addDays(6)->toDateString(), $brief->due_date->toDateString());
        $this->assertSame($requester->id, $brief->assigned_by);
        $this->assertSame(6, $brief->daysLeft());

        // A role given without a deadline simply has none.
        $this->assertNull($request->assignmentFor('qa_owner_id')->due_date);
    }

    public function test_the_task_comes_from_the_workflow_not_the_form(): void
    {
        Notification::fake();

        $requester = $this->brandManager();
        $store     = $this->mappingSite();
        $requester->stores()->sync([$store->id]);

        $qa = User::create(['name' => 'QA Person', 'email' => 'qat@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'qa']);

        $this->actingAs($requester)->post(route('product-requests.store'), [
            'store_id'                  => $store->id,
            'request_type'              => 'new_brand',
            'brand'                     => 'Samsonite',
            'category'                  => 'Luggage',
            'skus'                      => 'TASK-1',
            'online_launch_date'        => now()->addDays(18)->format('Y-m-d H:i'),
            'image_source'              => ProductRequest::IMG_PHOTOSHOOT,
            'use_ai_content'            => 1,
            'priority'                  => 'high',
            'assignments'               => [
                // A hand-crafted task is ignored: the workflow decides the wording.
                ['role' => 'qa_owner_id', 'user_id' => $qa->id, 'title' => 'do whatever you like'],
            ],
        ])->assertRedirect();

        $brief = ProductRequest::first()->assignmentFor('qa_owner_id');

        $this->assertSame(ProductRequest::taskForRole('qa_owner_id'), $brief->title);
        $this->assertStringNotContainsString('whatever you like', $brief->title);
    }

    public function test_every_assignable_role_has_a_task_description(): void
    {
        // A role with no job description would show an empty box on the form.
        foreach (ProductRequest::ASSIGNMENT_ROLES as $field => $label) {
            $this->assertNotEmpty(
                ProductRequest::taskForRole($field),
                "Role {$label} ({$field}) has no task description"
            );
        }
    }

    public function test_a_role_cannot_be_given_to_two_people_at_once(): void
    {
        Notification::fake();

        $requester = $this->brandManager();
        $store     = $this->mappingSite();
        $requester->stores()->sync([$store->id]);

        $a = User::create(['name' => 'Shooter A', 'email' => 'sa@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'photographer']);
        $b = User::create(['name' => 'Shooter B', 'email' => 'sb@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'photographer']);

        $this->actingAs($requester)->post(route('product-requests.store'), [
            'store_id'                  => $store->id,
            'request_type'              => 'new_brand',
            'brand'                     => 'Samsonite',
            'category'                  => 'Luggage',
            'skus'                      => 'DUP-1',
            'online_launch_date'        => now()->addDays(18)->format('Y-m-d H:i'),
            'image_source'              => ProductRequest::IMG_PHOTOSHOOT,
            'use_ai_content'            => 1,
            'priority'                  => 'high',
            'assignments'               => [
                ['role' => 'photographer_id', 'user_id' => $a->id],
                ['role' => 'photographer_id', 'user_id' => $b->id],
            ],
        ])->assertSessionHasErrors('assignments');

        // Rejected outright rather than silently keeping whichever came last.
        $this->assertSame(0, ProductRequest::count());
    }

    public function test_a_personal_deadline_is_tracked_and_chased(): void
    {
        Notification::fake();

        $requester = $this->brandManager();
        $request   = $this->submitFor($requester, $this->plainSite(), 'DUE-1');

        $shooter = User::create(['name' => 'Late Shooter', 'email' => 'late@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'photographer']);

        $request->update(['status' => ProductRequest::WAITING_IMAGES]);

        // Assign with a deadline that has already passed.
        app(ProductRequestWorkflow::class)->assignRole(
            request: $request->refresh(),
            field:   'photographer_id',
            userId:  $shooter->id,
            actor:   $requester,
            title:   'Shoot the samples',
            dueDate: now()->subDays(2)->toDateString(),
        );

        $brief = $request->refresh()->assignmentFor('photographer_id');

        $this->assertTrue($brief->isOverdue());
        $this->assertSame(-2, $brief->daysLeft());
        $this->assertStringContainsString('overdue', $brief->dueLabel());

        // The owner column and the brief agree — nothing drifted.
        $this->assertSame($shooter->id, $this->ownerId($request, 'photographer_id'));
        $this->assertSame($shooter->id, $brief->user_id);

        // The digest chases the person by name, quoting their own task.
        $this->artisan('product-requests:remind')->assertSuccessful();

        Notification::assertSentTo($shooter, ProductRequestReminder::class, function ($n) {
            return str_contains(collect($n->items)->pluck('reason')->implode(' '), 'Shoot the samples');
        });

        // Finished work stops being chased even though the date has passed.
        $brief->update(['completed_at' => now()]);
        $this->assertFalse($brief->fresh()->isOverdue());
    }

    public function test_the_daily_chase_reaches_the_watching_account_as_one_board(): void
    {
        Notification::fake();

        $watcher   = $this->watcher();
        $requester = $this->brandManager();
        $shooter   = $this->photographer();
        $request   = $this->submitFor($requester, $this->plainSite(), 'WATCHREM-1');

        app(ProductRequestWorkflow::class)->assignRole(
            request: $request->refresh(),
            field:   'photographer_id',
            userId:  $shooter->id,
            actor:   $requester,
            title:   'Shoot the samples',
            dueDate: now()->subDays(2)->toDateString(),
        );

        $this->artisan('product-requests:remind')->assertSuccessful();

        // Same overdue task, but told as somebody else's — the watcher is reading
        // over the team's shoulder, not being chased.
        Notification::assertSentTo($watcher, ProductRequestReminder::class, function ($n) use ($shooter) {
            $reasons = collect($n->items)->pluck('reason')->implode(' ');

            return str_contains($reasons, "{$shooter->name}'s") && str_contains($reasons, 'Shoot the samples');
        });

        Notification::assertSentTo($shooter, ProductRequestReminder::class, function ($n) {
            return str_contains(collect($n->items)->pluck('reason')->implode(' '), 'your ');
        });
    }

    public function test_unassigning_a_role_removes_its_brief(): void
    {
        Notification::fake();

        $requester = $this->brandManager();
        $request   = $this->submitFor($requester, $this->plainSite(), 'UNASSIGN-1');

        $editor = User::create(['name' => 'Ed', 'email' => 'ed2@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'image_editor']);

        $workflow = app(ProductRequestWorkflow::class);

        $workflow->assignRole($request, 'image_editor_id', $editor->id, $requester, 'Edit the images', now()->addDays(3)->toDateString());
        $this->assertNotNull($request->refresh()->assignmentFor('image_editor_id'));

        $workflow->assignRole($request, 'image_editor_id', null, $requester);

        $request->refresh();
        $this->assertNull($this->ownerId($request, 'image_editor_id'));
        $this->assertNull($request->assignmentFor('image_editor_id'));   // no orphan brief left behind
    }

    public function test_the_assignment_email_is_branded_and_personal(): void
    {
        $requester = $this->brandManager();
        $request   = $this->submitFor($requester, $this->plainSite(), 'MAIL-1');

        $shooter = User::create(['name' => 'Mail Shooter', 'email' => 'ms@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'photographer']);

        $request->update(['status' => ProductRequest::WAITING_IMAGES, 'name' => 'New Balance SS26']);

        app(ProductRequestWorkflow::class)->assignRole(
            request: $request->refresh(),
            field:   'photographer_id',
            userId:  $shooter->id,
            actor:   $requester,
            title:   'Shoot 45 SKUs on white background',
            dueDate: now()->addDays(2)->toDateString(),
        );

        $mail = ProductRequestAssigned::forRequest($request->refresh(), 'Photoshoot Coordinator', $requester->name)
            ->toMail($shooter);

        $html = $mail->render();

        // Addressed to one person, about their own task only.
        $this->assertStringContainsString('Hello Mail Shooter', $html);
        $this->assertStringContainsString('Shoot 45 SKUs on white background', $html);
        $this->assertStringContainsString('Finish by', $html);
        $this->assertStringContainsString('Photoshoot Coordinator', $html);

        // Tells them what the stage actually needs.
        $this->assertStringContainsString('samples into the studio', $html);

        // Branding: logo, wordmark and a working link back into the system.
        $this->assertStringContainsString('aih_logo-1.png', $html);
        $this->assertStringContainsString('AI E-Commerce Studio', $html);
        $this->assertStringContainsString('#1d5a74', $html);
        $this->assertStringContainsString(route('product-requests.show', $request->id), $html);
        $this->assertStringContainsString('Abuissa Holding E-Commerce Department', $html);

        // The subject names the role, so it is scannable in an inbox.
        $this->assertStringContainsString('You are the Photoshoot Coordinator', $mail->subject);
    }

    public function test_a_finished_status_email_does_not_ask_who_we_are_waiting_on(): void
    {
        $user    = $this->brandManager();
        $request = $this->submitFor($user, $this->plainSite(), 'MAILEND-1');

        // Mid-flight it should still explain the next step and the owner.
        $request->update(['status' => ProductRequest::QA_REVIEW]);
        $open = ProductRequestStatusChanged::forRequest($request->fresh(), ProductRequest::QA_REVIEW, 'Ahamed')
            ->toMail($user)->render();

        $this->assertStringContainsString('What happens next', $open);
        $this->assertStringContainsString('Waiting on', $open);

        // Closed, there is no next step and nobody to wait on.
        foreach ([ProductRequest::PUBLISHED, ProductRequest::COMPLETED] as $status) {
            $request->update(['status' => $status]);
            $html = ProductRequestStatusChanged::forRequest($request->fresh(), $status, 'Ahamed')
                ->toMail($user)->render();

            $this->assertStringNotContainsString('Waiting on', $html);
            $this->assertStringNotContainsString('What happens next', $html);
            $this->assertStringContainsString('Finished', $html);
        }

        // Cancelled says so rather than claiming to be finished.
        $request->update(['status' => ProductRequest::CANCELLED]);
        $cancelled = ProductRequestStatusChanged::forRequest($request->fresh(), ProductRequest::CANCELLED, 'Ahamed')
            ->toMail($user)->render();

        $this->assertStringContainsString('Cancelled', $cancelled);
        $this->assertStringNotContainsString('Waiting on', $cancelled);
    }

    public function test_the_balance_email_quotes_the_share_and_changes_tone_when_it_is_done(): void
    {
        Notification::fake();

        $user    = $this->brandManager();
        $request = $this->submitFor($user, $this->mappingSite(), "MAILBAL-1\nMAILBAL-2\nMAILBAL-3\nMAILBAL-4");

        $request = $this->mapSome($request, 3);

        $partial = ProductRequestBalanceMapped::forRequest($request, 2);
        $html    = $partial->toMail($user)->render();

        $this->assertStringContainsString('3 of 4 mapped (75%)', $html);
        $this->assertStringContainsString('1 still to map', $html);
        // The bell entry says the same thing in one line.
        $this->assertSame('3 of 4 SKUs mapped', $partial->toArray($user)['status_label']);

        // The last one changes what the email is for: finish it and close it.
        $request = $this->mapSome($request, 4);
        $done    = ProductRequestBalanceMapped::forRequest($request, 1);

        $this->assertTrue($done->isComplete());
        $this->assertStringContainsString('ready to finish', $done->toMail($user)->subject);
        $this->assertStringContainsString('Nothing is outstanding', $done->toMail($user)->render());
    }

    public function test_the_reminder_email_only_lists_that_persons_own_work(): void
    {
        $me    = $this->brandManager();
        $other = User::create(['name' => 'Someone Else', 'email' => 'se@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'qa']);

        $mine = $this->submitFor($me, $this->plainSite(), 'MINE-MAIL');
        $mine->update(['name' => 'My Own Request']);

        $html = (new ProductRequestReminder([[
            'request_id' => $mine->id,
            'reference'  => $mine->reference,
            'name'       => $mine->displayName(),
            'reason'     => 'your QA Team task "Check the copy" is due today',
        ]]))->toMail($me)->render();

        $this->assertStringContainsString('Hello Brand Manager', $html);
        $this->assertStringContainsString('My Own Request', $html);
        $this->assertStringContainsString('Check the copy', $html);

        // Nobody else's name or work appears in a personal digest.
        $this->assertStringNotContainsString($other->name, $html);
    }

    public function test_the_dashboard_has_no_process_explainer(): void
    {
        $user = $this->ecommerceUser();

        // Kept minimal for non-technical users: no "how this works" panel.
        $this->actingAs($user)->get(route('product-requests.index'))
            ->assertOk()
            ->assertDontSee('How this works')
            ->assertDontSee('Who does what')
            ->assertDontSee('Supply Chain');
    }

    public function test_an_unknown_queue_is_not_found(): void
    {
        $user = $this->brandManager();

        $this->actingAs($user)->get('/product-requests/queue/nonsense')->assertNotFound();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function markMapped(ProductRequest $request): void
    {
        $request->skus()->update([
            'mapping_status' => ProductRequest::MAP_MAPPED,
            'in_shopify'     => true,
            'mapping_set_by' => $request->user_id,
            'mapping_set_at' => now(),
        ]);

        app(SkuMappingService::class)->rollUp($request);
        app(ProductRequestWorkflow::class)->reconcileMapping($request->refresh());

        $request->refresh();
    }
}

/**
 * Answers the mapping check's batched lookup from a registry of SKUs the test
 * has declared present, and reaches no network at all. A SKU that was never
 * registered simply has no answer — which is how a SKU not yet in Shopify
 * reads.
 */
class FakeMappingShopify extends \App\Services\ShopifyService
{
    /** @var array<string, list<array<string, mixed>>> keyed "domain|sku" */
    public static array $present = [];

    public function __construct(private ?\App\Models\Store $forStore = null) {}

    public function findVariantsBySkus(array $skus, bool $throwOnFailure = false): array
    {
        $domain = $this->forStore?->shopify_domain ?? '';
        $found  = [];

        foreach ($skus as $sku) {
            if (isset(self::$present[$domain . '|' . $sku])) {
                $found[$sku] = self::$present[$domain . '|' . $sku];
            }
        }

        return $found;
    }
}
