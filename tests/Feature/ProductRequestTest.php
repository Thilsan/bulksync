<?php

namespace Tests\Feature;

use App\Models\ProductRequest;
use App\Models\ProductRequestActivity;
use App\Models\Store;
use App\Models\User;
use App\Notifications\ProductRequestAssigned;
use App\Notifications\ProductRequestCommented;
use App\Notifications\ProductRequestReminder;
use App\Notifications\ProductRequestHoldChanged;
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

    public function test_mapping_can_be_applied_to_the_whole_request_not_just_one_page(): void
    {
        Notification::fake();

        $user = $this->brandManager();

        // More SKUs than the 50-per-page table shows, so a page-scoped
        // "select all" would silently leave the rest untouched.
        $skus    = collect(range(1, 60))->map(fn ($i) => "PAGE-{$i}")->implode("\n");
        $request = $this->submitFor($user, $this->mappingSite(), $skus);

        $this->assertSame(60, $request->skus()->count());
        $this->assertSame(ProductRequest::WAITING_MAPPING, $request->status);

        $this->actingAs($user)->post(route('product-requests.skus.mapping', $request), [
            'scope'          => 'all',
            'mapping_status' => ProductRequest::MAP_MAPPED,
        ])->assertRedirect();

        $request->refresh();

        $this->assertSame(60, $request->mapped_skus);
        $this->assertSame(0, $request->pending_skus);
        $this->assertSame(ProductRequest::SKU_VERIFIED, $request->status);
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
            'store_launch_date'         => now()->addDays(20)->toDateString(),
            'online_launch_date'        => now()->addDays(18)->toDateString(),
            'supplier_images_available' => 0,
            'photoshoot_required'       => 1,
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

        // Sitting at SKU Verified, which the E-Commerce team drives.
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

        $request->update(['status' => ProductRequest::PHOTOSHOOT_SCHEDULED, 'photographer_id' => $shooter->id]);
        $request->refresh();

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

        $request->update(['status' => ProductRequest::PHOTOSHOOT_SCHEDULED, 'photographer_id' => $first->id]);
        $request->refresh();

        $this->actingAs($first)->post(route('product-requests.reassign', $request), [
            'user_id' => $second->id,
        ])->assertRedirect();

        $request->refresh();

        // Hand-over writes to the stage's own slot, not some generic field.
        $this->assertSame($second->id, $request->photographer_id);
        $this->assertSame('mine', $request->ownershipFor($second));
        $this->assertSame('other', $request->ownershipFor($first));

        Notification::assertSentTo($second, ProductRequestAssigned::class);
    }

    public function test_mapping_and_image_editing_have_owners_of_their_own(): void
    {
        Notification::fake();

        $requester = $this->brandManager();
        $request   = $this->submitFor($requester, $this->mappingSite(), 'ROLE-1');

        // Waiting for Mapping can now name a person, not just "Supply Chain".
        $this->assertSame(ProductRequest::WAITING_MAPPING, $request->status);
        $this->assertSame('supply_chain_id', $request->currentGuide()['field']);

        $supply = User::create([
            'name' => 'Supply Person', 'email' => 'sc@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'supply_chain',
        ]);
        $editor = User::create([
            'name' => 'Editor Person', 'email' => 'ed@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'image_editor',
        ]);

        // Unclaimed mapping work now reaches the Supply Chain team.
        $this->assertSame('my_team', $request->ownershipFor($supply));

        $this->actingAs($requester)->post(route('product-requests.assign', $request), [
            'supply_chain_id' => $supply->id,
            'image_editor_id' => $editor->id,
        ])->assertRedirect();

        $request->refresh();

        $this->assertSame($supply->id, $request->supply_chain_id);
        $this->assertSame('mine', $request->ownershipFor($supply));
        Notification::assertSentTo($supply, ProductRequestAssigned::class);

        // Image Editing belongs to the editor, not the E-Commerce team.
        $request->update(['status' => ProductRequest::IMAGE_EDITING]);
        $request->refresh();

        $this->assertSame('Photo Editor', $request->currentGuide()['role']);
        $this->assertSame($editor->id, $request->currentGuide()['owner']->id);
        $this->assertSame('mine', $request->ownershipFor($editor));
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

        // With a shoot, that means all of them.
        $this->assertCount(count(ProductRequest::ASSIGNMENT_ROLES), $request->visibleAssignmentRoles());
    }

    public function test_image_editing_is_dropped_when_there_is_no_photoshoot(): void
    {
        Notification::fake();

        $user    = $this->brandManager();
        $request = $this->submitFor($user, $this->plainSite(), 'NOEDIT-1');

        $request->update(['photoshoot_required' => false, 'supplier_images_available' => true]);
        $request->refresh();

        $stages = $request->displayStages();

        // Nothing of ours was shot, so there is nothing of ours to edit.
        $this->assertNotContains(ProductRequest::IMAGE_EDITING, $stages);
        $this->assertNotContains(ProductRequest::PHOTOSHOOT_SCHEDULED, $stages);
        $this->assertNotContains(ProductRequest::WAITING_IMAGES, $stages);
        $this->assertContains(ProductRequest::AI_CONTENT, $stages);
        $this->assertCount(7, $stages);

        // The suggestion must land on a stage this request actually has.
        $this->assertSame(ProductRequest::AI_CONTENT, $request->suggestedNextStatus());

        // And the Move Stage dropdown must not offer it either.
        $this->assertNotContains(ProductRequest::IMAGE_EDITING, $request->allowedTransitions());

        $this->actingAs($user)->get(route('product-requests.show', $request))
            ->assertOk()
            ->assertDontSee('Image Editing');
    }

    public function test_images_are_still_awaited_when_nobody_has_them_yet(): void
    {
        Notification::fake();

        $user    = $this->brandManager();
        $request = $this->submitFor($user, $this->plainSite(), 'NOIMG-1');

        // No shoot booked, but the supplier has not sent anything either — the
        // request still has to wait for images from somewhere.
        $request->update(['photoshoot_required' => false, 'supplier_images_available' => false]);
        $request->refresh();

        $stages = $request->displayStages();

        $this->assertContains(ProductRequest::WAITING_IMAGES, $stages);
        $this->assertNotContains(ProductRequest::IMAGE_EDITING, $stages);
        $this->assertSame(ProductRequest::WAITING_IMAGES, $request->suggestedNextStatus());
    }

    public function test_a_photoshoot_request_keeps_the_full_pipeline(): void
    {
        Notification::fake();

        $user    = $this->brandManager();
        $request = $this->submitFor($user, $this->plainSite(), 'FULL-1');   // photoshoot required

        $stages = $request->displayStages();

        foreach ([ProductRequest::WAITING_IMAGES, ProductRequest::PHOTOSHOOT_SCHEDULED,
                  ProductRequest::PHOTOSHOOT_COMPLETED, ProductRequest::IMAGE_EDITING] as $stage) {
            $this->assertContains($stage, $stages);
        }

        $this->assertCount(11, $stages);
    }

    public function test_photography_roles_are_hidden_when_there_is_no_photoshoot(): void
    {
        Notification::fake();

        $user    = $this->brandManager();
        $request = $this->submitFor($user, $this->plainSite(), 'NOSHOOT-1');

        $request->update(['photoshoot_required' => false, 'supplier_images_available' => true]);
        $request->refresh();

        $roles = $request->visibleAssignmentRoles();

        // No shoot means nothing to photograph and nothing to edit.
        $this->assertArrayNotHasKey('photographer_id', $roles);
        $this->assertArrayNotHasKey('image_editor_id', $roles);
        $this->assertArrayHasKey('content_owner_id', $roles);
        $this->assertArrayHasKey('qa_owner_id', $roles);

        $this->actingAs($user)->get(route('product-requests.show', $request))
            ->assertOk()
            ->assertDontSee('name="photographer_id"', false)
            ->assertDontSee('name="image_editor_id"', false);
    }

    public function test_a_hidden_role_reappears_if_it_is_in_use(): void
    {
        Notification::fake();

        $user    = $this->brandManager();
        $editor  = User::create(['name' => 'Ed Vis', 'email' => 'edvis@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'image_editor']);
        $request = $this->submitFor($user, $this->plainSite(), 'REAPPEAR-1');

        // Someone already holds the role — hiding it would strand the assignment.
        $request->update(['photoshoot_required' => false, 'image_editor_id' => $editor->id]);
        $this->assertArrayHasKey('image_editor_id', $request->refresh()->visibleAssignmentRoles());

        // And it must be offered when it owns the stage the request is sitting at.
        $request->update(['image_editor_id' => null, 'status' => ProductRequest::IMAGE_EDITING]);
        $this->assertArrayHasKey('image_editor_id', $request->refresh()->visibleAssignmentRoles());
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

        $this->assertSame($first->id, $request->fresh()->qa_owner_id);

        // Changing the dropdown to someone else must actually swap them.
        $this->actingAs($requester)->post(route('product-requests.assign', $request), [
            'qa_owner_id' => $second->id,
        ])->assertRedirect();

        $request->refresh();

        $this->assertSame($second->id, $request->qa_owner_id);
        $this->assertSame($second->id, $request->assignmentFor('qa_owner_id')->user_id);
        Notification::assertSentTo($second, ProductRequestAssigned::class);

        // And clearing it back to Unassigned works too.
        $this->actingAs($requester)->post(route('product-requests.assign', $request), [
            'qa_owner_id' => null,
        ])->assertRedirect();

        $this->assertNull($request->fresh()->qa_owner_id);
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
            'category'                  => 'Footwear',
            'skus'                      => 'NB-1',
            'store_launch_date'         => now()->addDays(20)->toDateString(),
            'online_launch_date'        => now()->addDays(18)->toDateString(),
            'supplier_images_available' => 0,
            'photoshoot_required'       => 1,
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
        $request->update(['qa_owner_id' => $qa->id]);

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

        $this->assertSame($editor->id, $a->fresh()->image_editor_id);
        $this->assertSame($editor->id, $b->fresh()->image_editor_id);
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
            'store_launch_date'         => now()->addDays(20)->toDateString(),
            'online_launch_date'        => now()->addDays(18)->toDateString(),
            'supplier_images_available' => 0,
            'photoshoot_required'       => 1,
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

        $this->assertSame($shooter->id, $request->photographer_id);
        $this->assertSame($qa->id, $request->qa_owner_id);

        // Each assignee is told, and the message names who raised it.
        Notification::assertSentTo($shooter, ProductRequestAssigned::class,
            fn ($n) => $n->roleLabel === 'Photographer' && $n->requesterName === $requester->name);
        Notification::assertSentTo($qa, ProductRequestAssigned::class);

        // Assigning yourself is not announced to yourself.
        Notification::assertNotSentTo($requester, ProductRequestAssigned::class);

        // The assignee can see who wants it, from their own task list.
        $this->actingAs($shooter)->get(route('product-requests.my-tasks'))
            ->assertOk()
            ->assertSee('requested by')
            ->assertSee($requester->name);

        // The task is taken from the workflow, not typed by the requester.
        $brief = $request->assignmentFor('photographer_id');
        $this->assertNotNull($brief);
        $this->assertSame(ProductRequest::taskForRole('photographer_id'), $brief->title);
        $this->assertStringContainsString('Photograph the products', $brief->title);
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
            'store_launch_date'         => now()->addDays(20)->toDateString(),
            'online_launch_date'        => now()->addDays(18)->toDateString(),
            'supplier_images_available' => 0,
            'photoshoot_required'       => 1,
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
            'store_launch_date'         => now()->addDays(20)->toDateString(),
            'online_launch_date'        => now()->addDays(18)->toDateString(),
            'supplier_images_available' => 0,
            'photoshoot_required'       => 1,
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
        $this->assertSame($shooter->id, $request->photographer_id);
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
        $this->assertNull($request->image_editor_id);
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

        $mail = ProductRequestAssigned::forRequest($request->refresh(), 'Photographer', $requester->name)
            ->toMail($shooter);

        $html = $mail->render();

        // Addressed to one person, about their own task only.
        $this->assertStringContainsString('Hello Mail Shooter', $html);
        $this->assertStringContainsString('Shoot 45 SKUs on white background', $html);
        $this->assertStringContainsString('Finish by', $html);
        $this->assertStringContainsString('Photographer', $html);

        // Tells them what the stage actually needs.
        $this->assertStringContainsString('Gather the product images', $html);

        // Branding: logo, wordmark and a working link back into the system.
        $this->assertStringContainsString('aih_logo-1.png', $html);
        $this->assertStringContainsString('AI E-Commerce Studio', $html);
        $this->assertStringContainsString('#1d5a74', $html);
        $this->assertStringContainsString(route('product-requests.show', $request->id), $html);
        $this->assertStringContainsString('Abuissa Holding E-Commerce Department', $html);

        // The subject names the role, so it is scannable in an inbox.
        $this->assertStringContainsString('You are the Photographer', $mail->subject);
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
