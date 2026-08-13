<?php

namespace Tests\Feature;

use App\Jobs\PushEditedPhotoJob;
use App\Jobs\ScanPhotoEditFolderJob;
use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\User;
use App\Services\ImageProcessingService;
use App\Services\PhotoroomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The Photo Editor sends OneDrive photos through Photoroom, holds the results
 * on disk, and pushes only what a person approved.
 *
 * Two things here are worth guarding beyond the happy path: the feature spends
 * money per image, so access and the folder cap are not cosmetic; and it is the
 * only part of this app that writes full-size images and keeps them, on a
 * server whose disk has filled twice.
 */
class PhotoEditorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Deterministic regardless of what the developer's .env holds.
        config(['services.photoroom.api_key' => 'sandbox_test_key']);
    }

    protected function tearDown(): void
    {
        // These tests write real files; RefreshDatabase does not undo that.
        $root = storage_path('app/' . PhotoEditSession::STORAGE_ROOT);

        foreach (glob("{$root}/*", GLOB_ONLYDIR) ?: [] as $dir) {
            PhotoEditSession::deleteDirectory($dir);
        }

        parent::tearDown();
    }

    /**
     * is_active is set explicitly: the factory leaves it unset, and the global
     * CheckActive middleware signs out anyone it finds inactive — which shows
     * up as a redirect to login rather than as a permission failure.
     */
    private function editor(bool $permitted = true): User
    {
        return User::factory()->create([
            'is_active'         => true,
            'perm_photo_editor' => $permitted,
        ]);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'onedrive_link'     => 'https://1drv.ms/f/s!example',
            'matching_mode'     => 'sku_barcode',
            'remove_background' => '1',
            'background_mode'   => 'white',
            'apparel_mode'      => 'ghost_mannequin',
            'image_width'       => 1000,
            'image_height'      => 1000,
            'padding'           => 0.1,
            'h_align'           => 'center',
            'v_align'           => 'center',
            'scaling'           => 'fit',
        ], $overrides);
    }

    // ── Access ─────────────────────────────────────────────────────────────

    public function test_the_form_opens_for_someone_with_the_feature(): void
    {
        $this->actingAs($this->editor())
            ->get(route('photo-editor.index'))
            ->assertOk()
            ->assertSee('Where are the photos?');
    }

    /** Hiding the sidebar link is not access control — the URL must refuse too. */
    public function test_someone_without_the_feature_is_refused_even_at_the_url(): void
    {
        $this->actingAs($this->editor(permitted: false))
            ->get(route('photo-editor.index'))
            ->assertForbidden();
    }

    public function test_one_persons_session_is_not_visible_to_another(): void
    {
        $session = PhotoEditSession::create([
            'user_id' => $this->editor()->id,
            'name'    => 'Theirs',
            'onedrive_link' => 'https://example.com',
            'edits'   => [],
        ]);

        $this->actingAs($this->editor())
            ->get(route('photo-editor.show', $session))
            ->assertForbidden();
    }

    // ── Starting a run ─────────────────────────────────────────────────────

    public function test_starting_a_run_records_the_chosen_edits_and_queues_the_scan(): void
    {
        Queue::fake();

        $this->actingAs($this->editor())
            ->post(route('photo-editor.store'), $this->validPayload())
            ->assertRedirect();

        $session = PhotoEditSession::sole();

        $this->assertSame('FFFFFF', $session->edits['background_color']);
        $this->assertTrue($session->edits['remove_background']);
        $this->assertTrue($session->edits['ghost_mannequin']);
        $this->assertFalse($session->edits['flat_lay']);
        $this->assertSame(1000, $session->edits['width']);

        Queue::assertPushed(ScanPhotoEditFolderJob::class);
    }

    /**
     * Transparency and JPEG cannot coexist — asking for both is the one
     * combination that silently fills the cutout with black.
     */
    public function test_a_transparent_background_is_saved_as_png(): void
    {
        Queue::fake();

        $this->actingAs($this->editor())
            ->post(route('photo-editor.store'), $this->validPayload(['background_mode' => 'transparent']));

        $edits = PhotoEditSession::sole()->edits;

        $this->assertNull($edits['background_color']);
        $this->assertSame('png', app(PhotoroomService::class)->outputFormat($edits));
    }

    public function test_a_white_background_is_saved_as_jpeg(): void
    {
        $format = app(PhotoroomService::class)->outputFormat([
            'remove_background' => true,
            'background_color'  => 'FFFFFF',
        ]);

        $this->assertSame('jpg', $format);
    }

    public function test_photoroom_parameter_names_are_the_ones_the_api_expects(): void
    {
        $fields = app(PhotoroomService::class)->buildFields([
            'remove_background' => true,
            'background_color'  => '#F5F5F5',
            'width'             => 1200,
            'height'            => 1200,
            'padding'           => 0.15,
            'shadow'            => 'ai.soft',
        ]);

        $this->assertSame('true',      $fields['removeBackground']);
        $this->assertSame('F5F5F5',    $fields['background.color']);   // no leading #
        $this->assertSame('1200x1200', $fields['outputSize']);
        $this->assertSame('0.15',      $fields['padding']);
        $this->assertSame('ai.soft',   $fields['shadow.mode']);
        $this->assertSame('jpg',       $fields['export.format']);
    }

    // ── Pushing ────────────────────────────────────────────────────────────

    public function test_only_images_that_edited_cleanly_are_pushed(): void
    {
        Queue::fake();

        $user    = $this->editor();
        $session = PhotoEditSession::create([
            'user_id'       => $user->id,
            'name'          => 'Run',
            'onedrive_link' => 'https://example.com',
            'edits'         => [],
        ]);

        $ready = PhotoEditItem::create([
            'photo_edit_session_id' => $session->id,
            'filename'    => 'a.jpg',
            'status'      => 'edited',
            'edited_path' => 'photo-editor/x/a.jpg',
        ]);

        // Failed, and one that edited but whose file is already gone: both are
        // in the posted list, and neither may become a job.
        $failed = PhotoEditItem::create([
            'photo_edit_session_id' => $session->id,
            'filename' => 'b.jpg',
            'status'   => 'failed',
        ]);

        $fileless = PhotoEditItem::create([
            'photo_edit_session_id' => $session->id,
            'filename'    => 'c.jpg',
            'status'      => 'edited',
            'edited_path' => null,
        ]);

        $this->actingAs($user)
            ->postJson(route('photo-editor.push', $session), [
                'item_ids' => [$ready->id, $failed->id, $fileless->id],
            ])
            ->assertOk()
            ->assertJson(['queued' => 1, 'skipped' => 2]);

        Queue::assertPushed(PushEditedPhotoJob::class, 1);
    }

    public function test_the_selection_survives_reopening_the_page(): void
    {
        Queue::fake();

        $user    = $this->editor();
        $session = PhotoEditSession::create([
            'user_id' => $user->id, 'name' => 'Run',
            'onedrive_link' => 'https://example.com', 'edits' => [],
        ]);

        $kept    = PhotoEditItem::create(['photo_edit_session_id' => $session->id, 'filename' => 'a.jpg', 'status' => 'edited', 'edited_path' => 'p/a.jpg']);
        $dropped = PhotoEditItem::create(['photo_edit_session_id' => $session->id, 'filename' => 'b.jpg', 'status' => 'edited', 'edited_path' => 'p/b.jpg']);

        $this->actingAs($user)
            ->postJson(route('photo-editor.push', $session), ['item_ids' => [$kept->id]]);

        $this->assertTrue($kept->fresh()->selected);
        $this->assertFalse($dropped->fresh()->selected);
    }

    // ── Disk safety ────────────────────────────────────────────────────────

    /**
     * The full-size edit is the biggest thing written, and it is redundant the
     * moment Shopify has it.
     */
    public function test_the_full_size_file_is_dropped_once_shopify_has_it(): void
    {
        $session = PhotoEditSession::create([
            'user_id' => $this->editor()->id, 'name' => 'Run',
            'onedrive_link' => 'https://example.com', 'edits' => [],
        ]);

        @mkdir($session->absoluteStorageDir(), 0775, true);

        $full  = $session->storageDir() . '/1-after.jpg';
        $thumb = $session->storageDir() . '/1-after-thumb.jpg';
        file_put_contents(storage_path('app/' . $full), str_repeat('x', 5000));
        file_put_contents(storage_path('app/' . $thumb), 'thumb');

        $item = PhotoEditItem::create([
            'photo_edit_session_id' => $session->id,
            'filename'          => 'a.jpg',
            'status'            => 'pushed',
            'edited_path'       => $full,
            'edited_thumb_path' => $thumb,
        ]);

        $item->discardFullSize();

        $this->assertFileDoesNotExist(storage_path('app/' . $full));
        $this->assertNull($item->fresh()->edited_path);

        // The thumbnail stays, so the session still shows what was done.
        $this->assertFileExists(storage_path('app/' . $thumb));
    }

    public function test_deleting_a_session_takes_its_files_with_it(): void
    {
        $user    = $this->editor();
        $session = PhotoEditSession::create([
            'user_id' => $user->id, 'name' => 'Run',
            'onedrive_link' => 'https://example.com', 'edits' => [],
        ]);

        @mkdir($session->absoluteStorageDir(), 0775, true);
        file_put_contents($session->absoluteStorageDir() . '/1-after.jpg', str_repeat('x', 4096));

        PhotoEditItem::create(['photo_edit_session_id' => $session->id, 'filename' => 'a.jpg', 'status' => 'edited']);

        $dir = $session->absoluteStorageDir();

        $this->actingAs($user)
            ->delete(route('photo-editor.destroy', $session))
            ->assertRedirect(route('photo-editor.history'));

        $this->assertDirectoryDoesNotExist($dir);
        $this->assertDatabaseCount('photo_edit_sessions', 0);
        $this->assertDatabaseCount('photo_edit_items', 0);
    }

    /** What the nightly sweep leans on to reclaim an orphaned directory. */
    public function test_an_orphaned_directory_can_be_reclaimed_and_measured(): void
    {
        $root = storage_path('app/' . PhotoEditSession::STORAGE_ROOT . '/999999');

        @mkdir($root, 0775, true);
        file_put_contents("{$root}/stray.jpg", str_repeat('x', 2048));

        $this->assertGreaterThanOrEqual(2048, PhotoEditSession::totalBytes());

        $freed = PhotoEditSession::deleteDirectory($root);

        $this->assertSame(2048, $freed);
        $this->assertDirectoryDoesNotExist($root);
    }

    // ── Orientation ────────────────────────────────────────────────────────

    /**
     * A landscape JPEG carrying an EXIF orientation flag: the pixels are wide,
     * the flag says "turn this to display". Exactly what a studio camera hands
     * over, and the reason a shirt shot upright arrives lying on its side.
     */
    private function jpegWithOrientation(int $orientation, int $w = 400, int $h = 200): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 200, 40, 40));
        ob_start();
        imagejpeg($im, null, 90);
        $plain = ob_get_clean();

        $ifd = pack('v', 1)                              // one entry
             . pack('v', 0x0112)                         // Orientation tag
             . pack('v', 3)                              // type SHORT
             . pack('V', 1)                              // count
             . pack('v', $orientation) . "\x00\x00"      // value, padded to 4 bytes
             . pack('V', 0);                             // no next IFD

        $payload = "Exif\x00\x00" . "II\x2A\x00\x08\x00\x00\x00" . $ifd;
        $app1    = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;

        return "\xFF\xD8" . $app1 . substr($plain, 2);
    }

    public function test_a_sideways_photo_is_straightened_before_it_is_edited(): void
    {
        $sideways = $this->jpegWithOrientation(6);

        // The fixture has to actually carry the flag, or this proves nothing.
        $this->assertSame([400, 200], array_slice(getimagesizefromstring($sideways), 0, 2));

        $fixed = app(ImageProcessingService::class)->normalizeOrientation($sideways);

        $this->assertSame(
            [200, 400],
            array_slice(getimagesizefromstring($fixed), 0, 2),
            'the pixels themselves should have been rotated upright',
        );
    }

    /** Nothing to correct means nothing to recompress — quality is not spent for free. */
    public function test_an_already_upright_photo_passes_through_untouched(): void
    {
        $im = imagecreatetruecolor(300, 200);
        imagefilledrectangle($im, 0, 0, 300, 200, imagecolorallocate($im, 20, 120, 220));
        ob_start();
        imagejpeg($im, null, 90);
        $plain = ob_get_clean();

        $this->assertSame($plain, app(ImageProcessingService::class)->normalizeOrientation($plain));
    }

    // ── The wider Photoroom feature set ────────────────────────────────────

    public function test_virtual_model_presets_map_to_photoroom_parameters(): void
    {
        $fields = app(PhotoroomService::class)->buildFields([
            'virtual_model' => true,
            'vm_model'      => 'avery',
            'vm_scene'      => 'street',
            'vm_pose'       => 'standing',
            'apparel_size'  => 'PORTRAIT_HD_3_2',
            'apparel_prompt' => 'street style',
            'ironing'       => true,
        ]);

        $this->assertSame('ai.auto',         $fields['virtualModel.mode']);
        $this->assertSame('avery',           $fields['virtualModel.model.preset.name']);
        $this->assertSame('street',          $fields['virtualModel.scene.preset.name']);
        $this->assertSame('standing',        $fields['virtualModel.pose']);
        $this->assertSame('PORTRAIT_HD_3_2', $fields['virtualModel.size']);
        $this->assertSame('street style',    $fields['virtualModel.prompt']);

        // Ironing is independent — a garment can be pressed on a model too.
        $this->assertSame('ai.auto', $fields['ironing.mode']);
    }

    public function test_an_invented_preset_name_is_dropped_rather_than_sent(): void
    {
        $fields = app(PhotoroomService::class)->buildFields([
            'virtual_model' => true,
            'vm_model'      => 'not-a-real-model',
        ]);

        $this->assertArrayNotHasKey('virtualModel.model.preset.name', $fields);
    }

    /**
     * The generated canvas already has a shape. Forcing a pixel size on top is
     * what produces a 1024 image stretched to 2048 and blamed on the AI.
     */
    public function test_a_generated_canvas_is_not_also_forced_to_a_pixel_size(): void
    {
        $fields = app(PhotoroomService::class)->buildFields([
            'ghost_mannequin' => true,
            'apparel_size'    => 'SQUARE_HD',
            'width'           => 2048,
            'height'          => 2048,
        ]);

        $this->assertSame('ai.auto',   $fields['ghostMannequin.mode']);
        $this->assertSame('SQUARE_HD', $fields['ghostMannequin.size']);
        $this->assertArrayNotHasKey('outputSize', $fields);
    }

    public function test_a_plain_edit_still_gets_its_exact_pixel_size(): void
    {
        $fields = app(PhotoroomService::class)->buildFields([
            'remove_background' => true,
            'width'             => 2048,
            'height'            => 2048,
        ]);

        $this->assertSame('2048x2048', $fields['outputSize']);
    }

    public function test_blurring_keeps_the_background_instead_of_removing_it(): void
    {
        $fields = app(PhotoroomService::class)->buildFields([
            'remove_background'      => true,   // blur overrules it
            'background_mode'        => 'blur',
            'background_blur_mode'   => 'bokeh',
            'background_blur_radius' => 0.03,
        ]);

        $this->assertSame('false', $fields['removeBackground']);
        $this->assertSame('bokeh', $fields['background.blur.mode']);
        $this->assertSame('0.03',  $fields['background.blur.radius']);
    }

    public function test_an_ai_background_prompt_replaces_the_colour(): void
    {
        $fields = app(PhotoroomService::class)->buildFields([
            'remove_background' => true,
            'background_mode'   => 'prompt',
            'background_prompt' => 'sunlit marble table',
        ]);

        $this->assertSame('sunlit marble table', $fields['background.prompt']);
        $this->assertArrayNotHasKey('background.color', $fields);
    }

    /** Without the version header the override fields are ignored in silence. */
    public function test_shadow_overrides_request_the_newer_shadow_model(): void
    {
        $service = app(PhotoroomService::class);

        $edits = [
            'shadow'           => 'ai.auto-with-overrides',
            'shadow_softness'  => 0.8,
            'shadow_direction' => 'behindLeft',
            'shadow_pose'      => 'upright',
        ];

        $fields = $service->buildFields($edits);

        $this->assertSame('0.8',        $fields['shadow.softnessOverride']);
        $this->assertSame('behindLeft', $fields['shadow.directionOverride']);
        $this->assertSame('upright',    $fields['shadow.subjectPoseOverride']);

        $this->assertArrayHasKey('pr-ai-shadows-model-version', $service->buildHeaders($edits));
        $this->assertSame([], $service->buildHeaders(['shadow' => 'ai.soft']));
    }

    public function test_a_preset_shadow_sends_no_override_fields(): void
    {
        $fields = app(PhotoroomService::class)->buildFields([
            'shadow'          => 'ai.soft',
            'shadow_softness' => 0.8,
        ]);

        $this->assertSame('ai.soft', $fields['shadow.mode']);
        $this->assertArrayNotHasKey('shadow.softnessOverride', $fields);
    }

    public function test_the_form_can_start_a_virtual_model_run(): void
    {
        Queue::fake();

        $this->actingAs($this->editor())
            ->post(route('photo-editor.store'), $this->validPayload([
                'apparel_mode' => 'virtual_model',
                'vm_model'     => 'maya',
                'vm_scene'     => 'studio',
                'vm_pose'      => 'crossedarms',
                'apparel_size' => 'PORTRAIT_HD_4_3',
                'ironing'      => '1',
                'beautify'     => 'ai.auto',
                'expand'       => '1',
            ]))
            ->assertRedirect();

        $edits = PhotoEditSession::sole()->edits;

        $this->assertTrue($edits['virtual_model']);
        $this->assertFalse($edits['ghost_mannequin']);
        $this->assertSame('maya', $edits['vm_model']);
        $this->assertSame('PORTRAIT_HD_4_3', $edits['apparel_size']);
        $this->assertTrue($edits['ironing']);
        $this->assertSame('ai.auto', $edits['beautify']);
        $this->assertTrue($edits['expand']);
    }

    /** Model-only options are dropped when the run isn't using a model. */
    public function test_model_options_are_not_kept_for_a_ghost_mannequin_run(): void
    {
        Queue::fake();

        $this->actingAs($this->editor())
            ->post(route('photo-editor.store'), $this->validPayload([
                'apparel_mode' => 'ghost_mannequin',
                'vm_model'     => 'maya',
            ]));

        $this->assertNull(PhotoEditSession::sole()->edits['vm_model']);
    }
}
