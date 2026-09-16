<?php

namespace Tests\Feature;

use App\Jobs\EditPhotoItemJob;
use App\Jobs\GenerateLifestyleImageJob;
use App\Models\PhotoEditGroup;
use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The step between finding photos and paying for them.
 *
 * A run mixes product types that want opposite treatment, so settings belong
 * to each SKU folder rather than to the run. The property that matters most
 * here is that nothing is queued — and so nothing is billed — until somebody
 * has looked at what was found and pressed start.
 */
class PhotoEditorConfigureTest extends TestCase
{
    use RefreshDatabase;

    private function editor(): User
    {
        return User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
    }

    private function makeSession(array $attributes = []): PhotoEditSession
    {
        return PhotoEditSession::create(array_merge([
            'user_id'       => $this->editor()->id,
            'name'          => 'Run',
            'onedrive_link' => 'https://example.com',
            'edits'         => ['remove_background' => true, 'background_mode' => 'white'],
            'status'        => 'configuring',
            'scan_status'   => 'scanned',
        ], $attributes));
    }

    private function photo(PhotoEditSession $session, string $sku, string $filename): PhotoEditItem
    {
        return PhotoEditItem::create([
            'photo_edit_session_id' => $session->id,
            'kind'                  => 'cutout',
            'filename'              => $filename,
            'sku_detected'          => $sku,
            'status'                => 'pending',
            'onedrive_drive_id'     => 'drive-1',
            'onedrive_item_id'      => 'item-' . $filename,
        ]);
    }

    private function group(PhotoEditSession $session, string $sku): PhotoEditGroup
    {
        return PhotoEditGroup::create([
            'photo_edit_session_id' => $session->id,
            'sku'                   => $sku,
            'edits'                 => null, // follows the run until told otherwise
        ]);
    }

    /** Scanning finds the photos; it must not spend anything on them. */
    public function test_a_scanned_session_queues_nothing_until_it_is_started(): void
    {
        Queue::fake();

        $session = $this->makeSession();
        $this->photo($session, 'SKU-1', 'a.jpg');
        $this->group($session, 'SKU-1');

        $this->actingAs($session->user)
            ->get(route('photo-editor.configure', $session))
            ->assertOk()
            ->assertSee('SKU-1');

        Queue::assertNothingPushed();
    }

    /** Each SKU keeps its own settings, not the run's. */
    public function test_settings_are_saved_per_sku(): void
    {
        Queue::fake();

        $session = $this->makeSession();
        $this->photo($session, 'DRESS', 'a.jpg');
        $this->photo($session, 'WATCH', 'b.jpg');
        $dress = $this->group($session, 'DRESS');
        $watch = $this->group($session, 'WATCH');

        $this->actingAs($session->user)->post(route('photo-editor.start', $session), [
            'groups' => [
                $dress->id => ['differs' => '1', 'edits' => ['padding' => '0.12', 'background_mode' => 'white']],
                $watch->id => ['differs' => '1', 'edits' => ['padding' => '0',    'background_mode' => 'transparent']],
            ],
        ])->assertRedirect(route('photo-editor.show', $session));

        // assertEquals, not assertSame: edits round-trip through JSON, and a
        // zero comes back as an int however it went in.
        $this->assertEquals(0.12, $dress->fresh()->edits['padding']);
        $this->assertSame('transparent', $watch->fresh()->edits['background_mode']);
        $this->assertEquals(0, $watch->fresh()->edits['padding']);
    }

    /** Starting the run is what queues the work. */
    public function test_starting_queues_one_edit_per_photo(): void
    {
        Queue::fake();

        $session = $this->makeSession();
        $this->photo($session, 'SKU-1', 'a.jpg');
        $this->photo($session, 'SKU-1', 'b.jpg');
        $group = $this->group($session, 'SKU-1');

        $this->actingAs($session->user)->post(route('photo-editor.start', $session), [
            'groups' => [$group->id => ['lifestyle_count' => 0]],
        ]);

        Queue::assertPushed(EditPhotoItemJob::class, 2);
        Queue::assertNotPushed(GenerateLifestyleImageJob::class);
        $this->assertSame('processing', $session->fresh()->status);
    }

    /** On-model images are extra rows, extra jobs and extra credits. */
    public function test_lifestyle_images_are_generated_from_the_chosen_photo(): void
    {
        Queue::fake();

        $session = $this->makeSession();
        $front   = $this->photo($session, 'SKU-1', 'front.jpg');
        $this->photo($session, 'SKU-1', 'detail.jpg');
        $group = $this->group($session, 'SKU-1');

        $this->actingAs($session->user)->post(route('photo-editor.start', $session), [
            'groups' => [$group->id => [
                'lifestyle_count'          => 3,
                'lifestyle_source_item_id' => $front->id,
            ]],
        ]);

        Queue::assertPushed(GenerateLifestyleImageJob::class, 3);

        $generated = PhotoEditItem::where('photo_edit_session_id', $session->id)
            ->where('kind', 'lifestyle')->get();

        $this->assertCount(3, $generated);
        $this->assertTrue($generated->every(fn ($i) => $i->source_item_id === $front->id));

        // Generated imagery is opted into, never pushed by default.
        $this->assertTrue($generated->every(fn ($i) => !$i->selected));
    }

    /** A count with no photo to dress the model from can only fail later. */
    public function test_lifestyle_without_a_source_photo_is_refused(): void
    {
        Queue::fake();

        $session = $this->makeSession();
        $this->photo($session, 'SKU-1', 'a.jpg');
        $group = $this->group($session, 'SKU-1');

        $this->actingAs($session->user)
            ->post(route('photo-editor.start', $session), [
                'groups' => [$group->id => ['lifestyle_count' => 2]],
            ])
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
    }

    /** A run cannot be started twice and billed twice. */
    public function test_a_started_run_cannot_be_started_again(): void
    {
        Queue::fake();

        $session = $this->makeSession(['status' => 'processing']);
        $this->photo($session, 'SKU-1', 'a.jpg');
        $group = $this->group($session, 'SKU-1');

        $this->actingAs($session->user)
            ->post(route('photo-editor.start', $session), [
                'groups' => [$group->id => ['lifestyle_count' => 0]],
            ])
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
    }

    /** An item edits with its own group's settings, not the run's. */
    public function test_an_item_resolves_its_own_groups_settings(): void
    {
        $session = $this->makeSession();
        $item    = $this->photo($session, 'WATCH', 'a.jpg');

        PhotoEditGroup::create([
            'photo_edit_session_id' => $session->id,
            'sku'                   => 'WATCH',
            'edits'                 => ['padding' => 0.4, 'background_mode' => 'transparent'],
        ]);

        $this->assertSame(0.4, $item->resolvedEdits()['padding']);
        $this->assertSame('transparent', $item->resolvedEdits()['background_mode']);
    }

    /** Runs created before groups existed still edit with the run's settings. */
    public function test_an_item_without_a_group_falls_back_to_the_session(): void
    {
        $session = $this->makeSession();
        $item    = $this->photo($session, 'ORPHAN', 'a.jpg');

        $this->assertSame('white', $item->resolvedEdits()['background_mode']);
    }

    /**
     * The size, padding and framing fields are the ones a mixed run most needs
     * to differ on — a watch face wants none of the padding a dress does — so
     * they are guarded end to end rather than only in the markup.
     */
    public function test_size_padding_and_framing_save_per_sku(): void
    {
        Queue::fake();

        $session = $this->makeSession();
        $this->photo($session, 'DRESS', 'a.jpg');
        $this->photo($session, 'WATCH', 'b.jpg');
        $dress = $this->group($session, 'DRESS');
        $watch = $this->group($session, 'WATCH');

        $this->actingAs($session->user)->post(route('photo-editor.start', $session), [
            'groups' => [
                $dress->id => ['differs' => '1', 'edits' => [
                    'width' => '2048', 'height' => '2048', 'padding' => '0.1',
                    'h_align' => 'center', 'v_align' => 'center',
                    'scaling' => 'fit', 'reference_box' => 'subjectBox',
                ]],
                $watch->id => ['differs' => '1', 'edits' => [
                    'width' => '1000', 'height' => '1000', 'padding' => '0',
                    'h_align' => 'center', 'v_align' => 'bottom',
                    'scaling' => 'fill', 'reference_box' => 'originalImage',
                ]],
            ],
        ]);

        $d = $dress->fresh()->edits;
        $w = $watch->fresh()->edits;

        $this->assertSame(2048, $d['width']);
        $this->assertEquals(0.1, $d['padding']);
        $this->assertSame('fit', $d['scaling']);

        $this->assertSame(1000, $w['width']);
        $this->assertEquals(0, $w['padding']);
        $this->assertSame('bottom', $w['v_align']);
        $this->assertSame('fill', $w['scaling']);
        $this->assertSame('originalImage', $w['reference_box']);
    }

    /** What the group stores is what Photoroom is told. */
    public function test_group_size_and_padding_reach_photoroom(): void
    {
        $fields = app(\App\Services\PhotoroomService::class)->buildFields([
            'remove_background' => true,
            'width'             => 2048,
            'height'            => 2048,
            'padding'           => 0.1,
            'h_align'           => 'center',
            'scaling'           => 'fit',
        ]);

        $this->assertSame('2048x2048', $fields['outputSize']);
        $this->assertSame('0.1', $fields['padding']);
        $this->assertSame('center', $fields['horizontalAlignment']);
        $this->assertSame('fit', $fields['scaling']);
    }

    /**
     * A shoe photographed on its sole wants headroom above and almost none
     * below, or it floats in the frame. The even slider cannot express that,
     * so per-edge overrides have to survive the round trip to Photoroom.
     */
    public function test_per_edge_padding_saves_and_reaches_photoroom(): void
    {
        Queue::fake();

        $session = $this->makeSession();
        $this->photo($session, 'SHOE', 'a.jpg');
        $shoe = $this->group($session, 'SHOE');

        $this->actingAs($session->user)->post(route('photo-editor.start', $session), [
            'groups' => [$shoe->id => ['differs' => '1', 'edits' => [
                'width' => '2000', 'height' => '2000',
                'padding_top' => '180', 'padding_bottom' => '40',
                'v_align' => 'bottom', 'scaling' => 'fit',
            ]]],
        ]);

        $edits = $shoe->fresh()->edits;

        // Typed in pixels and stored with its unit, so nothing downstream has
        // to infer what a bare number meant.
        $this->assertSame('180px', $edits['padding_top']);
        $this->assertSame('bottom', $edits['v_align']);

        $fields = app(\App\Services\PhotoroomService::class)->buildFields(
            array_merge($edits, ['remove_background' => true]),
        );

        $this->assertSame('2000x2000', $fields['outputSize']);
        $this->assertSame('180px', $fields['paddingTop']);
        $this->assertSame('40px', $fields['paddingBottom']);
        $this->assertSame('bottom', $fields['verticalAlignment']);
    }

    /**
     * "10" used to mean 0.49 — a fraction cannot exceed half the canvas, so a
     * typed pixel count was clamped to the maximum and quietly produced 49%
     * padding around a stamp-sized product.
     */
    public function test_a_bare_number_over_one_is_read_as_pixels_not_a_clamped_fraction(): void
    {
        $fields = app(\App\Services\PhotoroomService::class)->buildFields([
            'remove_background' => true,
            'padding_top'       => '10',
        ]);

        $this->assertSame('10px', $fields['paddingTop']);
    }

    /** A real fraction still means a fraction. */
    public function test_a_fraction_is_still_a_fraction(): void
    {
        $fields = app(\App\Services\PhotoroomService::class)->buildFields([
            'remove_background' => true,
            'padding_top'       => '0.18',
        ]);

        $this->assertSame('0.18', $fields['paddingTop']);
    }

    /** An explicit unit is passed through untouched. */
    public function test_an_explicit_unit_is_respected(): void
    {
        $fields = app(\App\Services\PhotoroomService::class)->buildFields([
            'remove_background' => true,
            'padding_top'       => '40px',
            'padding_left'      => '5%',
        ]);

        $this->assertSame('40px', $fields['paddingTop']);
        $this->assertSame('5%',   $fields['paddingLeft']);
    }

    /**
     * The erase target is the same on every apparel shot; the product's own
     * name is not. Pre-filling only the half that generalises is what keeps
     * segmentation off until somebody has actually named the product — running
     * it on a guess would cut out the wrong thing.
     */
    public function test_segmentation_is_prefilled_but_stays_off_until_the_product_is_named(): void
    {
        $defaults = \App\Services\PhotoroomService::defaultEdits();

        $this->assertNull($defaults['segmentation_prompt']);
        $this->assertStringContainsString('clothes rail', $defaults['segmentation_negative_prompt']);

        // Nothing sent while the product is unnamed.
        $fields = app(\App\Services\PhotoroomService::class)->buildFields(
            array_merge($defaults, ['remove_background' => true]),
        );

        $this->assertArrayNotHasKey('segmentation.prompt', $fields);
        $this->assertArrayNotHasKey('segmentation.negativePrompt', $fields);
    }

    /** Naming the product is what switches segmentation on. */
    public function test_naming_the_product_switches_segmentation_on(): void
    {
        $fields = app(\App\Services\PhotoroomService::class)->buildFields(
            array_merge(\App\Services\PhotoroomService::defaultEdits(), [
                'remove_background'   => true,
                'segmentation_prompt' => 'the orange polo shirt',
            ]),
        );

        $this->assertSame('the orange polo shirt', $fields['segmentation.prompt']);
        $this->assertStringContainsString('clothes rail', $fields['segmentation.negativePrompt']);
    }

    /**
     * Whether the segmentation word was typed or auto-filled has to survive
     * the round trip, because the job treats the two differently: a guess that
     * cuts nothing out is retried without it, a typed word is failed and
     * reported. The Keep box refills itself whenever the category changes —
     * that is a category's guess, not a person's, and it has to say so.
     */
    public function test_whether_the_segmentation_word_was_a_guess_is_saved(): void
    {
        Queue::fake();

        $session = $this->makeSession();
        $this->photo($session, 'SKU-1', 'a.jpg');
        $group = $this->group($session, 'SKU-1');

        $this->actingAs($session->user)->post(route('photo-editor.start', $session), [
            'groups' => [$group->id => [
                'differs'                            => '1',
                'edits'                               => [
                    'segmentation_prompt'                 => 'the skirt',
                    'segmentation_prompt_is_a_guess'       => '1', // filled in by the category
                ],
            ]],
        ])->assertRedirect(route('photo-editor.show', $session));

        $this->assertTrue((bool) $group->fresh()->edits['segmentation_prompt_is_a_guess'],
            'an auto-filled word was stored as though a person had typed it');
    }

    /** A word typed after clearing the box is stored as a person's choice. */
    public function test_a_typed_segmentation_word_is_not_stored_as_a_guess(): void
    {
        Queue::fake();

        $session = $this->makeSession();
        $this->photo($session, 'SKU-1', 'a.jpg');
        $group = $this->group($session, 'SKU-1');

        $this->actingAs($session->user)->post(route('photo-editor.start', $session), [
            'groups' => [$group->id => [
                'differs' => '1',
                'edits'   => [
                    'segmentation_prompt'           => 'the pleated maxi skirt',
                    'segmentation_prompt_is_a_guess' => '0', // the input handler cleared this on typing
                ],
            ]],
        ]);

        $this->assertFalse((bool) $group->fresh()->edits['segmentation_prompt_is_a_guess']);
    }

    /**
     * The guess flag has to survive a reload, not just the round trip within
     * one request.
     *
     * It was being re-derived from "is segmentation_prompt filled" on every
     * render, which is exactly wrong the moment a guess is saved: the first
     * save makes the field non-empty, so every reload after that read it as
     * typed — a category's guess turning into a fact about a photograph
     * nobody had looked at, forever, with no way for the operator to undo it
     * short of clearing the box and losing the guess entirely.
     */
    public function test_the_guess_flag_survives_a_reload_of_the_configure_screen(): void
    {
        $session = $this->makeSession();
        $this->photo($session, 'SKU-1', 'a.jpg');
        $this->photo($session, 'SKU-2', 'b.jpg');

        $guessed = $this->group($session, 'SKU-1');
        $guessed->update(['edits' => [
            'segmentation_prompt'            => 'the skirt',
            'segmentation_prompt_is_a_guess' => true,
        ]]);

        $typed = $this->group($session, 'SKU-2');
        $typed->update(['edits' => [
            'segmentation_prompt'            => 'the pleated maxi skirt',
            'segmentation_prompt_is_a_guess' => false,
        ]]);

        $html = $this->actingAs($session->user)
            ->get(route('photo-editor.configure', $session))
            ->assertOk()
            ->getContent();

        // Each SKU's own box, found by its unique id, then read forward to the
        // hidden field that follows it in the same card.
        $guessedBox = substr($html, strpos($html, "seg-keep-{$guessed->id}\""), 1200);
        $typedBox   = substr($html, strpos($html, "seg-keep-{$typed->id}\""), 1200);

        $this->assertStringContainsString('data-auto="1"', $guessedBox,
            'a saved guess rendered as though it had been typed');
        $this->assertMatchesRegularExpression(
            '/id="seg-keep-guess-' . $guessed->id . '"[^>]*value="1"/s',
            $guessedBox,
        );

        $this->assertStringContainsString('data-auto="0"', $typedBox,
            'a saved typed word rendered as though it were a guess');
        $this->assertMatchesRegularExpression(
            '/id="seg-keep-guess-' . $typed->id . '"[^>]*value="0"/s',
            $typedBox,
        );
    }

    /**
     * Keep never fills itself in — the category's word is a hint in the
     * placeholder, not a value in the box.
     *
     * Naming the product, however it got there, routes the whole request to
     * text-guided segmentation instead of whatever treatment was chosen —
     * Ghost Mannequin included. Auto-filling Keep from the category used to
     * put a word in the box that silently defeated the redraw the operator
     * had just selected, in either order they picked things, with nothing on
     * screen to say why. The fix is not to chase every ordering with more
     * watchers; it is to never fill the box unless a person types into it.
     */
    public function test_keep_shows_the_category_only_as_a_hint_never_as_a_value(): void
    {
        $session = $this->makeSession();
        $this->photo($session, 'SKU-1', 'a.jpg');
        $this->group($session, 'SKU-1');

        $html = $this->actingAs($session->user)
            ->get(route('photo-editor.configure', $session))
            ->assertOk()
            ->getContent();

        // The function that runs when a category is picked sets a placeholder
        // and nothing else — no assignment to the input's value anywhere in
        // it, under any name a future edit might give that assignment.
        $this->assertStringContainsString('box.placeholder = this.nouns[key]', $html);
        $this->assertStringNotContainsString('box.value = noun', $html);
        $this->assertStringNotContainsString('box.value =', $html);
    }

    /**
     * A garment rail holds a scarf up exactly as a dress form holds a dress.
     * The erase pass only ever named mannequins, so a rail-hung item came back
     * with the rail still in the cutout — and the label said "cutout only",
     * which read as nothing having gone wrong.
     */
    public function test_the_erase_prompt_covers_rails_not_just_mannequins(): void
    {
        $service = new \ReflectionClass(\App\Services\PhotoroomService::class);
        $prompt  = $service->getConstant('MANNEQUIN_REMOVAL_PROMPT');

        foreach (['mannequin', 'dress form', 'clothes rail', 'hanger', 'stand'] as $support) {
            $this->assertStringContainsString($support, $prompt);
        }
    }

    /**
     * The common case: one set of settings for the run, and every SKU follows
     * it. Thirty products that want the same treatment should be one decision.
     */
    public function test_settings_chosen_once_apply_to_every_sku(): void
    {
        Queue::fake();

        $session = $this->makeSession();
        $this->photo($session, 'A', 'a.jpg');
        $this->photo($session, 'B', 'b.jpg');
        $a = $this->group($session, 'A');
        $b = $this->group($session, 'B');

        $this->actingAs($session->user)->post(route('photo-editor.start', $session), [
            'edits'  => ['background_mode' => 'transparent', 'padding' => '0.1'],
            'groups' => [$a->id => [], $b->id => []],
        ]);

        // Stored once, on the run.
        $this->assertSame('transparent', $session->fresh()->edits['background_mode']);

        // Nothing pinned to the groups, so changing the run still reaches them.
        $this->assertNull($a->fresh()->edits);
        $this->assertNull($b->fresh()->edits);

        $item = PhotoEditItem::where('sku_detected', 'A')->first();
        $this->assertSame('transparent', $item->resolvedEdits()['background_mode']);
    }

    /** A SKU that opts out keeps its own settings and ignores the run's. */
    public function test_a_sku_that_differs_keeps_its_own_settings(): void
    {
        Queue::fake();

        $session = $this->makeSession();
        $this->photo($session, 'DRESS', 'a.jpg');
        $this->photo($session, 'WATCH', 'b.jpg');
        $dress = $this->group($session, 'DRESS');
        $watch = $this->group($session, 'WATCH');

        $this->actingAs($session->user)->post(route('photo-editor.start', $session), [
            'edits'  => ['background_mode' => 'white', 'padding' => '0.1'],
            'groups' => [
                $dress->id => [],
                $watch->id => ['differs' => '1', 'edits' => ['background_mode' => 'transparent', 'padding' => '0']],
            ],
        ]);

        $this->assertNull($dress->fresh()->edits);
        $this->assertSame('transparent', $watch->fresh()->edits['background_mode']);

        $dressItem = PhotoEditItem::where('sku_detected', 'DRESS')->first();
        $watchItem = PhotoEditItem::where('sku_detected', 'WATCH')->first();

        $this->assertSame('white', $dressItem->resolvedEdits()['background_mode']);
        $this->assertSame('transparent', $watchItem->resolvedEdits()['background_mode']);
    }

    /** Unticking "differs" hands the SKU back to the run rather than freezing it. */
    public function test_a_sku_can_be_handed_back_to_the_run(): void
    {
        Queue::fake();

        $session = $this->makeSession();
        $this->photo($session, 'A', 'a.jpg');
        $group = $this->group($session, 'A');
        $group->update(['edits' => ['background_mode' => 'transparent']]);

        $this->actingAs($session->user)->post(route('photo-editor.start', $session), [
            'edits'  => ['background_mode' => 'white'],
            'groups' => [$group->id => []],
        ]);

        $this->assertNull($group->fresh()->edits);
        $this->assertSame('white', PhotoEditItem::sole()->resolvedEdits()['background_mode']);
    }
}
