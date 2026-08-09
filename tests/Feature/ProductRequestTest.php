<?php

namespace Tests\Feature;

use App\Models\ProductRequest;
use App\Models\ProductRequestActivity;
use App\Models\Store;
use App\Models\User;
use App\Notifications\ProductRequestAssigned;
use App\Notifications\ProductRequestStatusChanged;
use App\Services\ProductRequestWorkflow;
use App\Services\SkuMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProductRequestTest extends TestCase
{
    use RefreshDatabase;

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

    private function submitFor(User $user, Store $store, string $skus = 'X-1'): ProductRequest
    {
        $user->stores()->syncWithoutDetaching([$store->id]);

        $this->actingAs($user)->post(route('product-requests.store'), [
            'store_id'                  => $store->id,
            'request_type'              => 'new_brand',
            'brand'                     => 'Samsonite',
            'category'                  => 'Luggage',
            'skus'                      => $skus,
            'store_launch_date'         => now()->addDays(20)->toDateString(),
            'online_launch_date'        => now()->addDays(18)->toDateString(),
            'supplier_images_available' => 0,
            'photoshoot_required'       => 1,
            'use_ai_content'            => 1,
            'priority'                  => 'high',
        ]);

        return ProductRequest::latest('id')->first();
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

        // Nothing mapped yet, so it parks with Supply Chain rather than
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
        $this->assertSame(ProductRequest::SKU_VERIFIED, $request->status);

        // The stage is absent from the stepper and can never be moved to.
        $this->assertNotContains(ProductRequest::WAITING_MAPPING, $request->displayStages());
        $this->assertNotContains(ProductRequest::WAITING_MAPPING, $request->allowedTransitions());
        $this->assertCount(11, $request->displayStages());
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
            'store_launch_date'         => now()->addDays(20)->toDateString(),
            'online_launch_date'        => now()->addDays(18)->toDateString(),
            'supplier_images_available' => 0,
            'photoshoot_required'       => 1,
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
            'store_launch_date'         => now()->addDays(20)->toDateString(),
            'online_launch_date'        => now()->addDays(18)->toDateString(),
            'supplier_images_available' => 0,
            'photoshoot_required'       => 1,
            'use_ai_content'            => 1,
            'priority'                  => 'high',
        ])->assertSessionHasErrors('store_id');
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
            'store_launch_date'         => now()->addDays(20)->toDateString(),
            'online_launch_date'        => now()->addDays(18)->toDateString(),
            'supplier_images_available' => 0,
            'photoshoot_required'       => 1,
            'use_ai_content'            => 1,
            'priority'                  => 'high',
        ])->assertSessionHasErrors('skus');

        $this->assertSame(0, ProductRequest::count());
    }

    // ── Mapping lifecycle (Blue Salon only) ─────────────────────────────────

    public function test_supply_chain_mapping_releases_the_request_automatically(): void
    {
        Notification::fake();

        $user    = $this->brandManager();
        $request = $this->submitFor($user, $this->mappingSite(), "A-1\nA-2");

        $this->assertSame(ProductRequest::WAITING_MAPPING, $request->status);

        $this->actingAs($user)->post(route('product-requests.skus.mapping', $request), [
            'sku_ids'        => $request->skus()->pluck('id')->all(),
            'mapping_status' => ProductRequest::MAP_MAPPED,
            'mapping_note'   => 'Mapped in Cegid by Supply Chain',
            'scope'          => 'selected',
        ])->assertRedirect();

        $request->refresh();

        // No re-submission needed — the request advances on its own.
        $this->assertSame(ProductRequest::SKU_VERIFIED, $request->status);
        $this->assertSame(2, $request->mapped_skus);
        $this->assertSame(0, $request->pending_skus);

        // The entry is attributed, so the audit trail shows who released it.
        $row = $request->skus()->first();
        $this->assertTrue($row->isManuallySet());
        $this->assertSame($user->id, $row->mapping_set_by);
        $this->assertSame('Mapped in Cegid by Supply Chain', $row->mapping_note);

        Notification::assertSentTo($user, ProductRequestStatusChanged::class);
    }

    public function test_the_automatic_check_never_overwrites_supply_chains_entry(): void
    {
        Notification::fake();

        $user    = $this->brandManager();
        $request = $this->submitFor($user, $this->mappingSite(), 'G-1');

        $this->actingAs($user)->post(route('product-requests.skus.mapping', $request), [
            'sku_ids'        => $request->skus()->pluck('id')->all(),
            'mapping_status' => ProductRequest::MAP_NOT_MAPPED,
            'scope'          => 'selected',
        ])->assertRedirect();

        $this->assertSame(ProductRequest::MAP_NOT_MAPPED, $request->skus()->first()->mapping_status);

        app(SkuMappingService::class)->validate($request->fresh());

        $this->assertSame(ProductRequest::MAP_NOT_MAPPED, $request->skus()->first()->mapping_status);
        $this->assertSame(ProductRequest::WAITING_MAPPING, $request->fresh()->status);
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

        $last = $trail->last();
        $this->assertSame(ProductRequest::SKU_VERIFIED, $last->from_status);
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

        ProductRequest::create($base + ['reference' => ProductRequest::nextReference(), 'brand' => 'MINE', 'photographer_id' => $user->id]);
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
            fn ($n) => $n->roleLabel === 'Photographer' && $n->reference === $request->reference);

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

        $user    = $this->brandManager();
        $store   = $this->mappingSite();
        $mine    = $this->submitFor($user, $store, 'MINE-1');
        $notMine = $this->submitFor($user, $store, 'THEIRS-1');

        $mine->update(['brand' => 'MY BRAND', 'qa_owner_id' => $user->id]);
        $notMine->update(['brand' => 'SOMEONE ELSE']);

        $response = $this->actingAs($user)->get(route('product-requests.my-tasks'));

        $response->assertOk()
            ->assertSee('MY BRAND')
            ->assertSee('QA Team')          // the role badge
            ->assertDontSee('SOMEONE ELSE');
    }

    public function test_assigned_to_me_hides_closed_work_by_default(): void
    {
        Notification::fake();

        $user  = $this->brandManager();
        $store = $this->mappingSite();
        $done  = $this->submitFor($user, $store, 'DONE-1');

        $done->update(['brand' => 'FINISHED WORK', 'assigned_to' => $user->id, 'status' => ProductRequest::COMPLETED]);

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
            'store_launch_date'         => now()->addDays(20)->toDateString(),
            'online_launch_date'        => now()->addDays(18)->toDateString(),
            'supplier_images_available' => 0,
            'photoshoot_required'       => 1,
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
            'store_launch_date'         => now()->addDays(20)->toDateString(),
            'online_launch_date'        => now()->addDays(18)->toDateString(),
            'supplier_images_available' => 0,
            'photoshoot_required'       => 1,
            'use_ai_content'            => 0,
            'priority'                  => 'high',
        ])->assertRedirect();

        $request = ProductRequest::first();

        $this->assertTrue($request->awaitingContentSheet());

        $this->actingAs($user)->get(route('product-requests.show', $request))
            ->assertOk()
            ->assertSee('Awaiting content sheet');
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

        $this->assertSame('Supply Chain Team', $request->guideFor(ProductRequest::WAITING_MAPPING)['role']);
        $this->assertSame('Photographer', $request->guideFor(ProductRequest::PHOTOSHOOT_SCHEDULED)['role']);
        $this->assertSame('QA Team', $request->guideFor(ProductRequest::QA_REVIEW)['role']);
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

        // Sitting at SKU Verified, which the E-Commerce owner drives.
        $request->update(['assigned_to' => $user->id]);
        $request->refresh();

        $this->assertTrue($request->isWaitingOn($user));

        $this->actingAs($user)->get(route('product-requests.show', $request))
            ->assertOk()
            ->assertSee('This is your task')
            ->assertSee('Assigned to you');

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

        // Sits at SKU Verified, which the E-Commerce team owns, with nobody named.
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

        $this->assertSame($ecom->id, $request->assigned_to);
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

        $this->assertSame($ecom->id, $request->fresh()->assigned_to);
    }

    public function test_the_dashboard_explains_the_process_to_newcomers(): void
    {
        $user = $this->brandManager();

        $this->actingAs($user)->get(route('product-requests.index'))
            ->assertOk()
            ->assertSee('How this works')
            ->assertSee('Who does what')
            ->assertSee('Supply Chain');
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
            'mapping_set_by' => $request->user_id,
            'mapping_set_at' => now(),
        ]);

        app(SkuMappingService::class)->rollUp($request);
        app(ProductRequestWorkflow::class)->reconcileMapping($request->refresh());

        $request->refresh();
    }
}
