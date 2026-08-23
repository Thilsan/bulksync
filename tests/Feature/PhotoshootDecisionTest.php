<?php

namespace Tests\Feature;

use App\Models\ProductRequest;
use App\Models\Store;
use App\Models\User;
use App\Notifications\ProductRequestPhotosNeeded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A photoshoot is something somebody asks for, not something the import assumes.
 *
 * Reading "the sheet does not say the images are ready" as "this needs a shoot"
 * put all 156 requests in the Photoshoot Room, and a queue holding everything
 * tells the coordinator nothing.
 */
class PhotoshootDecisionTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->store = Store::create([
            'name' => 'Bluesalon Website', 'shopify_domain' => 'qatarbluesalon.myshopify.com', 'is_active' => true,
        ]);

        $this->admin = User::create([
            'name' => 'Sheikh Rasul', 'email' => 'ecom@example.test', 'password' => 'password',
            'is_active' => true, 'is_super_admin' => true, 'perm_product_request' => true,
            'pcr_role' => 'ecommerce',
        ]);

        $this->admin->stores()->attach($this->store->id);
    }

    private function brandManager(): User
    {
        return User::create([
            'name' => 'Brand Manager', 'email' => 'brand@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true,
            'pcr_role' => 'brand_manager', 'pcr_brand_categories' => ['Leather Goods'],
        ]);
    }

    private function request(?string $decision = null, ?string $shootStatus = null): ProductRequest
    {
        return ProductRequest::create([
            'reference'           => ProductRequest::nextReference(),
            'user_id'             => $this->admin->id,
            'store_id'            => $this->store->id,
            'request_type'        => 'new_brand',
            'brand'               => 'POURCHET',
            'category'            => 'Leather Goods',
            'status'              => ProductRequest::SKU_VERIFIED,
            'priority'            => 'medium',
            'photoshoot_decision' => $decision,
            'photoshoot_status'   => $shootStatus,
            'total_skus'          => 34,
        ]);
    }

    public function test_an_undecided_request_is_not_in_the_photoshoot_room(): void
    {
        // Exactly what the import left behind: a pending shoot nobody asked for.
        $this->request(decision: null, shootStatus: ProductRequest::SHOOT_PENDING);

        $this->assertSame(0, ProductRequest::withPhotoshoot()->count());
    }

    /** A shoot is arranged in the room — nobody is emailed for it. */
    public function test_saying_yes_puts_it_in_the_room_and_emails_nobody(): void
    {
        $manager = $this->brandManager();
        $request = $this->request();

        $this->actingAs($this->admin)
            ->post(route('product-requests.photoshoot-decision', $request), ['needed' => 'yes'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $request->refresh();
        $this->assertSame('yes', $request->photoshoot_decision);
        $this->assertSame(ProductRequest::SHOOT_PENDING, $request->photoshoot_status);
        $this->assertSame(1, ProductRequest::withPhotoshoot()->count());

        Notification::assertNotSentTo($manager, ProductRequestPhotosNeeded::class);

        // And with a shoot booked there is nothing more to ask about images.
        $this->assertFalse($request->needsImageSourceDecision());
    }

    public function test_saying_no_keeps_it_out_of_the_room_and_asks_where_images_come_from(): void
    {
        $manager = $this->brandManager();
        $request = $this->request();

        $this->actingAs($this->admin)
            ->post(route('product-requests.photoshoot-decision', $request), ['needed' => 'no'])
            ->assertRedirect();

        $request->refresh();
        $this->assertSame('no', $request->photoshoot_decision);
        $this->assertNull($request->photoshoot_status);
        $this->assertSame(0, ProductRequest::withPhotoshoot()->count());

        Notification::assertNotSentTo($manager, ProductRequestPhotosNeeded::class);
        $this->assertTrue($request->needsImageSourceDecision());
    }

    // ── With no shoot, where do the images come from? ────────────────────────

    public function test_asking_writes_to_the_brand_manager(): void
    {
        $manager = $this->brandManager();
        $request = $this->request(decision: 'no');

        $this->actingAs($this->admin)
            ->post(route('product-requests.image-request-decision', $request), ['ask' => 'yes'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('yes', $request->refresh()->image_request_decision);

        Notification::assertSentTo($manager, ProductRequestPhotosNeeded::class, function ($notification) {
            return $notification->brand === 'POURCHET' && $notification->skus === 34;
        });
    }

    public function test_already_having_the_images_tells_nobody(): void
    {
        $manager = $this->brandManager();
        $request = $this->request(decision: 'no');

        $this->actingAs($this->admin)
            ->post(route('product-requests.image-request-decision', $request), ['ask' => 'no'])
            ->assertRedirect();

        $this->assertSame('no', $request->refresh()->image_request_decision);
        $this->assertFalse($request->needsImageSourceDecision());

        Notification::assertNotSentTo($manager, ProductRequestPhotosNeeded::class);
    }

    // ── The answer has to show up in the workflow ────────────────────────────

    /**
     * Answering the question has to change what the request looks like. The
     * stepper was reading image_source — what the import guessed off the sheet —
     * so a request answered "no photoshoot, images in hand" still displayed a
     * Product Images phase with Photoshoot Scheduled and Photoshoot Completed in
     * it, which is the opposite of what was just said.
     */
    public function test_no_shoot_and_images_in_hand_removes_the_whole_images_phase(): void
    {
        $request = $this->request();
        $request->update(['image_source' => ProductRequest::IMG_PHOTOSHOOT]);

        $this->actingAs($this->admin)
            ->post(route('product-requests.photoshoot-decision', $request), ['needed' => 'no']);
        $this->actingAs($this->admin)
            ->post(route('product-requests.image-request-decision', $request), ['ask' => 'no']);

        $request->refresh();

        $this->assertFalse($request->needsPhotoshoot());
        $this->assertFalse($request->needsImagesGathered());

        $stages = $request->displayStages();
        $this->assertNotContains(ProductRequest::WAITING_IMAGES, $stages);
        $this->assertNotContains(ProductRequest::PHOTOSHOOT_SCHEDULED, $stages);
        $this->assertNotContains(ProductRequest::PHOTOSHOOT_COMPLETED, $stages);

        $this->assertNotContains('Product Images', array_column($request->phaseProgress(), 'label'));
    }

    /** Asked for from the brand manager: still waiting, but no shoot. */
    public function test_asking_the_brand_manager_keeps_the_wait_without_the_shoot(): void
    {
        $this->brandManager();
        $request = $this->request();
        $request->update(['image_source' => ProductRequest::IMG_PHOTOSHOOT]);

        $this->actingAs($this->admin)
            ->post(route('product-requests.photoshoot-decision', $request), ['needed' => 'no']);
        $this->actingAs($this->admin)
            ->post(route('product-requests.image-request-decision', $request), ['ask' => 'yes']);

        $stages = $request->refresh()->displayStages();

        $this->assertContains(ProductRequest::WAITING_IMAGES, $stages);
        $this->assertNotContains(ProductRequest::PHOTOSHOOT_SCHEDULED, $stages);

        // Named for what it is, since no camera is involved.
        $this->assertContains('Product Images', array_column($request->phaseProgress(), 'label'));
    }

    /** Saying yes puts the shoot stages back. */
    public function test_a_shoot_keeps_its_stages(): void
    {
        $request = $this->request();

        $this->actingAs($this->admin)
            ->post(route('product-requests.photoshoot-decision', $request), ['needed' => 'yes']);

        $stages = $request->refresh()->displayStages();

        $this->assertContains(ProductRequest::PHOTOSHOOT_SCHEDULED, $stages);
        $this->assertContains(ProductRequest::PHOTOSHOOT_COMPLETED, $stages);
        $this->assertContains('Photoshoot', array_column($request->phaseProgress(), 'label'));
    }

    /** No photographer is needed for a request that is not being photographed. */
    public function test_the_photographer_slot_goes_with_the_shoot(): void
    {
        $request = $this->request();
        $request->update(['image_source' => ProductRequest::IMG_PHOTOSHOOT]);

        $this->actingAs($this->admin)
            ->post(route('product-requests.photoshoot-decision', $request), ['needed' => 'no']);

        $this->assertArrayNotHasKey('photographer_id', $request->refresh()->visibleAssignmentRoles());
    }

    // ── The shoot gates publishing ───────────────────────────────────────────

    /**
     * A request that asked for photographs is not finished until it has them.
     * Publishing says the products are live with their pictures, so the studio
     * has to deliver before anybody can say that.
     */
    public function test_an_unfinished_shoot_stops_the_request_being_published(): void
    {
        $request = $this->request(decision: 'yes', shootStatus: ProductRequest::SHOOT_PENDING);

        $this->assertTrue($request->isWaitingOnPhotoshoot());
        $this->assertNotContains(ProductRequest::PUBLISHED, $request->allowedTransitions());
        $this->assertFalse($request->canTransitionTo(ProductRequest::PUBLISHED));

        // Refused, and told what would unblock it.
        $this->actingAs($this->admin)
            ->post(route('product-requests.transition', $request), [
                'to_status' => ProductRequest::PUBLISHED,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('to_status');

        $this->assertNotSame(ProductRequest::PUBLISHED, $request->refresh()->status);
        $this->assertStringContainsString('Photoshoot Room', (string) $request->publishBlockedBecause());
    }

    public function test_a_finished_shoot_lets_it_be_published(): void
    {
        $request = $this->request(decision: 'yes', shootStatus: ProductRequest::SHOOT_COMPLETED);

        $this->assertNull($request->publishBlockedBecause());
        $this->assertContains(ProductRequest::PUBLISHED, $request->allowedTransitions());
    }

    /** No shoot was asked for, so there is nothing to wait on. */
    public function test_a_request_with_no_shoot_can_be_published(): void
    {
        $request = $this->request(decision: 'no');

        $this->assertNull($request->publishBlockedBecause());
        $this->assertContains(ProductRequest::PUBLISHED, $request->allowedTransitions());
    }

    /** The coordinator's screen is what moves the request along. */
    public function test_the_room_drives_the_request_stage(): void
    {
        $coordinator = User::create([
            'name' => 'Coordinator', 'email' => 'shoot@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'photographer',
        ]);

        $request = $this->request(decision: 'yes', shootStatus: ProductRequest::SHOOT_PENDING);
        $request->update(['image_source' => ProductRequest::IMG_PHOTOSHOOT, 'photoshoot_required' => true]);

        // Booked: the request follows into Photoshoot Scheduled.
        $this->actingAs($coordinator)
            ->put(route('product-requests.photoshoot-room.update', $request), [
                'photoshoot_status'       => ProductRequest::SHOOT_SCHEDULED,
                'photoshoot_scheduled_at' => now()->addWeek()->format('Y-m-d H:i'),
            ])
            ->assertRedirect();

        $this->assertSame(ProductRequest::PHOTOSHOOT_SCHEDULED, $request->refresh()->status);
        $this->assertNotContains(ProductRequest::PUBLISHED, $request->allowedTransitions());

        // Shot: the request reaches Photoshoot Completed and publishing opens up.
        $this->actingAs($coordinator)
            ->put(route('product-requests.photoshoot-room.update', $request), [
                'photoshoot_status'       => ProductRequest::SHOOT_COMPLETED,
                'photoshoot_scheduled_at' => now()->addWeek()->format('Y-m-d H:i'),
            ])
            ->assertRedirect();

        $request->refresh();

        $this->assertSame(ProductRequest::PHOTOSHOOT_COMPLETED, $request->status);
        $this->assertFalse($request->isWaitingOnPhotoshoot());
        $this->assertContains(ProductRequest::PUBLISHED, $request->allowedTransitions());

        // Which is a person's move to make, not the room's.
        $this->actingAs($this->admin)
            ->post(route('product-requests.transition', $request), ['to_status' => ProductRequest::PUBLISHED])
            ->assertRedirect();

        $this->assertSame(ProductRequest::PUBLISHED, $request->refresh()->status);
    }

    /**
     * Requests published before the shoot became a gate cannot be un-published,
     * and the images really are still outstanding on them, so they keep saying so.
     */
    public function test_a_request_published_before_the_gate_still_reports_the_wait(): void
    {
        $request = $this->request(decision: 'yes', shootStatus: ProductRequest::SHOOT_PENDING);
        $request->update(['status' => ProductRequest::PUBLISHED]);

        $this->assertTrue($request->isWaitingOnPhotoshoot());

        $this->actingAs($this->admin)
            ->get(route('product-requests.show', $request))
            ->assertOk()
            ->assertSee('Waiting on the photoshoot')
            ->assertSee('published before the images were delivered');
    }

    /** No shoot asked for means nothing to wait on. */
    public function test_a_request_with_no_shoot_never_waits_on_one(): void
    {
        $this->assertFalse($this->request(decision: 'no')->isWaitingOnPhotoshoot());
    }

    /** The room owns booking and finishing, so neither is offered on the request. */
    public function test_the_photoshoot_stages_are_not_offered_as_manual_moves(): void
    {
        $request = $this->request(decision: 'yes', shootStatus: ProductRequest::SHOOT_PENDING);
        $request->update(['image_source' => ProductRequest::IMG_PHOTOSHOOT, 'photoshoot_required' => true]);

        // Offered on the request: no. Permitted at all: yes — the Photoshoot Room
        // makes exactly these moves, so forbidding them would break booking.
        $this->assertNotContains(ProductRequest::PHOTOSHOOT_SCHEDULED, $request->manualTransitions());
        $this->assertNotContains(ProductRequest::PHOTOSHOOT_COMPLETED, $request->manualTransitions());
        $this->assertContains(ProductRequest::PHOTOSHOOT_SCHEDULED, $request->allowedTransitions());
    }

    /** Asked once — an answered request stops being asked. */
    public function test_the_question_is_only_asked_while_unanswered(): void
    {
        $this->assertTrue($this->request()->needsPhotoshootDecision());
        $this->assertFalse($this->request(decision: 'yes')->needsPhotoshootDecision());
        $this->assertFalse($this->request(decision: 'no')->needsPhotoshootDecision());
    }

    public function test_the_question_is_shown_on_an_undecided_request(): void
    {
        $request = $this->request();

        $this->actingAs($this->admin)
            ->get(route('product-requests.show', $request))
            ->assertOk()
            ->assertSee('Does this need a photoshoot?')
            ->assertSee('Yes — we need photos');
    }

    // ── Clearing what the import assumed ─────────────────────────────────────

    public function test_the_clear_command_empties_the_room_of_undecided_shoots(): void
    {
        $assumed = $this->request(decision: null, shootStatus: ProductRequest::SHOOT_PENDING);

        $this->artisan('product-requests:clear-undecided-photoshoots', ['--commit' => true])->assertSuccessful();

        $this->assertNull($assumed->refresh()->photoshoot_status);
    }

    /** A booking somebody made is not the import's to undo. */
    public function test_a_shoot_with_a_date_is_kept(): void
    {
        $booked = $this->request(decision: null, shootStatus: ProductRequest::SHOOT_PENDING);
        $booked->update(['photoshoot_scheduled_at' => now()->addWeek()]);

        $this->artisan('product-requests:clear-undecided-photoshoots', ['--commit' => true])
            ->expectsOutputToContain('a date is set for')
            ->assertSuccessful();

        $this->assertSame(ProductRequest::SHOOT_PENDING, $booked->refresh()->photoshoot_status);
    }

    public function test_a_shoot_already_under_way_is_kept(): void
    {
        $running = $this->request(decision: null, shootStatus: ProductRequest::SHOOT_IN_PROGRESS);

        $this->artisan('product-requests:clear-undecided-photoshoots', ['--commit' => true])->assertSuccessful();

        $this->assertSame(ProductRequest::SHOOT_IN_PROGRESS, $running->refresh()->photoshoot_status);
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $assumed = $this->request(decision: null, shootStatus: ProductRequest::SHOOT_PENDING);

        $this->artisan('product-requests:clear-undecided-photoshoots')->assertSuccessful();

        $this->assertSame(ProductRequest::SHOOT_PENDING, $assumed->refresh()->photoshoot_status);
    }
}
