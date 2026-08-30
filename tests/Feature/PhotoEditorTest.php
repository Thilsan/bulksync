<?php

namespace Tests\Feature;

use App\Jobs\PushEditedPhotoJob;
use App\Jobs\ScanPhotoEditFolderJob;
use App\Models\PhotoEditGroup;
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

    /**
     * The settings live on this screen, not only on the configure screen, and
     * they post under the same names both places so one partial can serve both.
     */
    public function test_the_form_asks_what_photoroom_should_do_before_the_folder_is_read(): void
    {
        $this->actingAs($this->editor())
            ->get(route('photo-editor.index'))
            ->assertOk()
            ->assertSee('What should Photoroom do to them?')
            ->assertSee('name="edits[background_mode]"', false)
            ->assertSee('name="edits[shadow]"', false)
            ->assertSee('name="edits[padding]"', false)
            ->assertSee('name="edits[segmentation_prompt]"', false);
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

    /**
     * A request that names no settings keeps every default. The distinction
     * that matters is absent versus empty: merging an empty array over the
     * defaults would read each unticked box as a deliberate "no" and switch
     * the cutout itself off.
     */
    public function test_a_run_posted_without_settings_keeps_the_defaults(): void
    {
        Queue::fake();

        $this->actingAs($this->editor())
            ->post(route('photo-editor.store'), $this->validPayload())
            ->assertRedirect();

        $session = PhotoEditSession::sole();

        $this->assertSame(PhotoroomService::defaultEdits()['background_mode'], $session->edits['background_mode']);
        $this->assertTrue($session->edits['remove_background']);
        $this->assertFalse($session->edits['ghost_mannequin']);
        $this->assertSame('configuring', $session->fresh()->status === 'processing' ? 'configuring' : $session->status);

        Queue::assertPushed(ScanPhotoEditFolderJob::class);
    }

    /**
     * The settings are chosen on the first screen now, for the folder as a
     * whole, and every SKU group follows them unless one is set to differ.
     */
    public function test_the_settings_chosen_on_the_first_screen_are_saved_to_the_run(): void
    {
        Queue::fake();

        $this->actingAs($this->editor())
            ->post(route('photo-editor.store'), $this->validPayload([
                'edits' => [
                    'remove_background'   => '1',
                    'background_mode'     => 'transparent',
                    'segmentation_prompt' => 'the dress',
                    'shadow'              => 'ai.soft',
                    'width'               => '2048',
                    'height'              => '2048',
                    'padding'             => '0.08',
                    'padding_top'         => '40',
                    'upscale'             => '1',
                ],
            ]))->assertRedirect();

        $edits = PhotoEditSession::sole()->edits;

        $this->assertSame('transparent', $edits['background_mode']);
        $this->assertSame('the dress', $edits['segmentation_prompt']);
        $this->assertSame('ai.soft', $edits['shadow']);
        $this->assertSame(2048, $edits['width']);
        $this->assertSame(0.08, $edits['padding']);
        $this->assertSame('40px', $edits['padding_top']);
        $this->assertTrue($edits['upscale']);

        // Left off the form, so it stays off rather than picking up a default.
        $this->assertFalse($edits['expand']);

        // Not on that form at all — kept from the defaults rather than reset.
        $this->assertSame(PhotoroomService::defaultEdits()['color_space'], $edits['color_space']);
    }

    /**
     * A run's settings are the starting point the configure screen shows back;
     * it is one form posting the same field names, so what was picked first
     * has to survive the round trip untouched when nobody changes it.
     */
    public function test_the_configure_screen_shows_back_what_the_first_screen_chose(): void
    {
        Queue::fake();

        $this->actingAs($this->editor())
            ->post(route('photo-editor.store'), $this->validPayload([
                'edits' => ['remove_background' => '1', 'background_mode' => 'transparent', 'padding' => '0.12'],
            ]))->assertRedirect();

        $session = PhotoEditSession::sole();
        $session->update(['scan_status' => 'scanned', 'status' => 'configuring']);

        PhotoEditGroup::create(['photo_edit_session_id' => $session->id, 'sku' => 'AB-1']);

        $this->actingAs(User::find($session->user_id))
            ->get(route('photo-editor.configure', $session))
            ->assertOk()
            ->assertSee('name="edits[background_mode]" value="transparent" checked', false)
            ->assertSee('name="edits[padding]"', false);
    }

    /**
     * The erase instruction forbids the things it was caught doing.
     *
     * This is a generative model redrawing the whole picture, so anything the
     * wording leaves open it may take. An earlier version asked for the garment
     * "floating in its place" and shirts came back tilted, as if set down at an
     * angle — nothing had forbidden rotation, and "floating" invited it.
     *
     * Asserted rather than trusted because the failure is silent: a tilted
     * shirt is still a plausible photograph, and nothing in the pipeline can
     * tell that it was not the one that went in.
     */
    public function test_the_erase_instruction_forbids_moving_the_garment(): void
    {
        $prompt = strtolower((new \ReflectionClass(PhotoroomService::class))
            ->getConstant('MANNEQUIN_REMOVAL_PROMPT'));

        foreach (['do not rotate', 'do not tilt', 'lay it flat', 'same angle', 'same position'] as $rule) {
            $this->assertStringContainsString($rule, $prompt, "the erase instruction stopped forbidding: {$rule}");
        }

        // The word that caused it. A garment described as floating is a garment
        // the model is free to set down wherever it likes.
        $this->assertStringNotContainsString('floating', $prompt);
    }

    /** A transparent cutout is a PNG wherever the setting was chosen. */
    public function test_a_transparent_background_is_saved_as_png(): void
    {
        $edits = array_merge(PhotoroomService::defaultEdits(), [
            'background_mode' => 'transparent',
        ]);

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

        /*
         * PNG, though the file is kept as a JPEG. Photoroom's JPEG export is
         * fixed at quality 80 and their API has no parameter to raise it, so
         * the edit is fetched losslessly and the JPEG written here instead, at
         * a quality and a size we choose. transportFormat() is what decides
         * this; outputFormat() still says jpg, and the test above proves it.
         */
        $this->assertSame('png',       $fields['export.format']);
    }

    /**
     * What Photoroom is asked for and what is kept are two questions.
     *
     * They only diverge on the answer JPEG: an opaque background is kept as a
     * JPEG but fetched as a PNG, so Photoroom's own lossy pass never happens.
     * A transparent cutout is already lossless in both directions and has
     * nothing to gain, so it asks for exactly what it will keep.
     */
    public function test_a_jpeg_is_fetched_losslessly_and_encoded_here(): void
    {
        $service = app(PhotoroomService::class);

        $opaque = ['remove_background' => true, 'background_mode' => 'white'];
        $this->assertSame('jpg', $service->outputFormat($opaque));
        $this->assertSame('png', $service->transportFormat($opaque), 'a JPEG must be fetched losslessly');

        $cutout = ['remove_background' => true, 'background_mode' => 'transparent'];
        $this->assertSame('png', $service->outputFormat($cutout));
        $this->assertSame('png', $service->transportFormat($cutout), 'nothing to gain re-asking for PNG');

        // WebP holds alpha and is asked for as itself rather than upgraded.
        $webp = $cutout + ['export_format' => 'webp'];
        $this->assertSame('webp', $service->outputFormat($webp));
        $this->assertSame('webp', $service->transportFormat($webp));
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

    /**
     * A plain JPEG at the given size, no EXIF — pixels genuinely that shape.
     * A red block marks the top-left corner so a rotation can be told apart
     * from its mirror image.
     */
    private function plainJpeg(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 240, 210, 40));
        imagefilledrectangle($im, 0, 0, (int) ($w / 10), (int) ($h / 10), imagecolorallocate($im, 255, 0, 0));
        ob_start();
        imagejpeg($im, null, 95);

        return ob_get_clean();
    }

    /** True when the marker block sits within a few pixels of ($x, $y). */
    private function isRed(string $jpeg, int $x, int $y): bool
    {
        $im    = imagecreatefromstring($jpeg);
        $rgb   = imagecolorat($im, $x, $y);

        return (($rgb >> 16) & 255) > 200 && (($rgb >> 8) & 255) < 80;
    }

    /**
     * The case EXIF cannot reach: a garment photographed lying across the frame.
     * There is no flag to read, so the operator's quarter turn has to do it.
     *
     * The corner is what makes this a real test — a turn the wrong way also
     * swaps width for height, so dimensions alone cannot tell the two apart.
     */
    public function test_a_quarter_turn_right_moves_the_top_left_corner_to_the_top_right(): void
    {
        $turned = app(ImageProcessingService::class)->rotate($this->plainJpeg(400, 200), 'right');

        $this->assertSame([200, 400], array_slice(getimagesizefromstring($turned), 0, 2));
        $this->assertTrue($this->isRed($turned, 195, 4), 'turning right should carry the corner clockwise');
    }

    public function test_a_quarter_turn_left_moves_the_top_left_corner_to_the_bottom_left(): void
    {
        $turned = app(ImageProcessingService::class)->rotate($this->plainJpeg(400, 200), 'left');

        $this->assertSame([200, 400], array_slice(getimagesizefromstring($turned), 0, 2));
        $this->assertTrue($this->isRed($turned, 4, 395), 'turning left should carry the corner anti-clockwise');
    }

    public function test_turning_only_the_wide_ones_leaves_an_upright_photo_alone(): void
    {
        $upright = $this->plainJpeg(200, 400);

        $this->assertSame(
            $upright,
            app(ImageProcessingService::class)->rotate($upright, 'right', onlyWhenWide: true),
            'a photo that is already tall has nothing to turn, so it should not even be re-encoded',
        );
    }

    // ── Trimming ───────────────────────────────────────────────────────────

    /**
     * The non-generative answer to a mannequin in shot: cut the stand off
     * rather than have Photoroom redraw the garment without it.
     */
    public function test_trimming_keeps_only_the_band_between_the_two_cuts(): void
    {
        $trimmed = app(ImageProcessingService::class)->trimEdges($this->plainJpeg(400, 1000), 0.1, 0.3);

        // 1000 px less 100 off the top and 300 off the bottom.
        $this->assertSame([400, 600], array_slice(getimagesizefromstring($trimmed), 0, 2));
    }

    /**
     * Trimming the bottom must take the bottom, not a centred crop of the same
     * height — the whole point is to lose the mannequin's legs and keep the hem.
     */
    public function test_trimming_the_bottom_keeps_the_top_of_the_photo(): void
    {
        $im = imagecreatetruecolor(200, 400);
        imagefilledrectangle($im, 0, 0, 199, 199, imagecolorallocate($im, 255, 0, 0));    // top half red
        imagefilledrectangle($im, 0, 200, 199, 399, imagecolorallocate($im, 0, 0, 255));  // bottom half blue
        ob_start();
        imagejpeg($im, null, 95);
        $split = ob_get_clean();

        $trimmed = app(ImageProcessingService::class)->trimEdges($split, 0.0, 0.4);

        $this->assertSame([200, 240], array_slice(getimagesizefromstring($trimmed), 0, 2));
        $this->assertTrue($this->isRed($trimmed, 100, 4), 'the surviving band should still start at the original top edge');
    }

    public function test_a_zero_trim_returns_the_original_bytes(): void
    {
        $photo = $this->plainJpeg(400, 400);

        $this->assertSame($photo, app(ImageProcessingService::class)->trimEdges($photo, 0, 0));
    }

    /** Half the picture is the most a trim may take, whatever the form posts. */
    public function test_a_trim_can_never_take_more_than_it_leaves(): void
    {
        $trimmed = app(ImageProcessingService::class)->trimEdges($this->plainJpeg(400, 1000), 5.0, 5.0);

        // Both fractions clamp to 0.4, leaving the middle 20%.
        $this->assertSame([400, 200], array_slice(getimagesizefromstring($trimmed), 0, 2));
    }

    /**
     * The order of the two is the subtle part, so it is pinned end to end rather
     * than inferred from the two units passing separately.
     *
     * A photo on its side is turned upright first, and only then trimmed — so
     * "off the bottom" means the bottom of the picture the operator was looking
     * at when they set the slider. Trim first and it would cut a side instead.
     */
    public function test_a_photo_is_turned_upright_before_it_is_trimmed(): void
    {
        // Wide, so a quarter turn makes it 200 × 400. Trimming 25% off the
        // bottom of that leaves 200 × 300. Had the trim run first it would have
        // taken 25% off a 400-wide edge and left 200 × 400 turned to 400 × 200.
        $sideways = $this->plainJpeg(400, 200);

        $this->mock(\App\Services\OneDriveService::class, function ($mock) use ($sideways) {
            $mock->shouldReceive('setUser')->andReturnSelf();
            $mock->shouldReceive('downloadFileById')->andReturn($sideways);
        });

        // Photoroom hands back raw image bytes, so the fake echoes the input —
        // whatever reaches the API is what lands on disk as the result.
        \Illuminate\Support\Facades\Http::fake([
            'image-api.photoroom.com/*' => function ($request) {
                foreach ($request->data() as $part) {
                    if (($part['name'] ?? '') === 'imageFile') {
                        return \Illuminate\Support\Facades\Http::response($part['contents'], 200, ['Content-Type' => 'image/jpeg']);
                    }
                }

                return \Illuminate\Support\Facades\Http::response('', 500);
            },
        ]);

        $session = PhotoEditSession::create([
            'user_id'       => $this->editor()->id,
            'name'          => 'Run',
            'onedrive_link' => 'https://example.com',
            'edits'         => ['input_rotation' => 'right', 'trim_bottom' => 0.25],
        ]);

        $item = PhotoEditItem::create([
            'photo_edit_session_id' => $session->id,
            'filename'              => 'a.jpg',
            'status'                => 'pending',
            'onedrive_drive_id'     => 'drive-1',
            'onedrive_item_id'      => 'item-1',
        ]);

        (new \App\Jobs\EditPhotoItemJob($item->id))->handle(
            app(\App\Services\OneDriveService::class),
            app(ImageProcessingService::class),
            app(PhotoroomService::class),
            app(\App\Services\GeminiService::class),
        );

        $this->assertSame('edited', $item->fresh()->status, $item->fresh()->error_message ?? '');

        $result = file_get_contents(storage_path('app/' . $item->fresh()->edited_path));

        $this->assertSame([200, 300], array_slice(getimagesizefromstring($result), 0, 2));
    }

    /** A half turn keeps the shape, so "only the wide ones" cannot gate it. */
    public function test_asking_for_no_rotation_returns_the_original_bytes(): void
    {
        $photo = $this->plainJpeg(400, 200);

        $this->assertSame($photo, app(ImageProcessingService::class)->rotate($photo, ''));
    }

    /**
     * A hidden tickbox still posts. Pairing "only the wide ones" with a half
     * turn would otherwise skip every portrait photo for no stated reason.
     */
    public function test_the_wide_only_limit_is_dropped_when_the_turn_is_180(): void
    {
        Queue::fake();

        $this->actingAs($this->editor())
            ->post(route('photo-editor.store'), $this->validPayload([
                'input_rotation'   => '180',
                'rotate_wide_only' => '1',
            ]))->assertRedirect();

        $edits = PhotoEditSession::sole()->edits;

        $this->assertSame('180', $edits['input_rotation']);
        $this->assertFalse($edits['rotate_wide_only']);
    }

    public function test_a_quarter_turn_keeps_the_wide_only_limit(): void
    {
        Queue::fake();

        $this->actingAs($this->editor())
            ->post(route('photo-editor.store'), $this->validPayload([
                'input_rotation'   => 'left',
                'rotate_wide_only' => '1',
            ]))->assertRedirect();

        $edits = PhotoEditSession::sole()->edits;

        $this->assertSame('left', $edits['input_rotation']);
        $this->assertTrue($edits['rotate_wide_only']);
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

    /** On-model settings reach Photoroom whichever screen collected them. */
    public function test_virtual_model_settings_reach_photoroom(): void
    {
        $fields = app(PhotoroomService::class)->buildFields([
            'virtual_model' => true,
            'vm_model'      => 'maya',
            'vm_scene'      => 'studio',
            'vm_pose'       => 'crossedarms',
            'apparel_size'  => 'PORTRAIT_HD_4_3',
            'ironing'       => true,
        ]);

        $this->assertSame('ai.auto', $fields['virtualModel.mode']);
        $this->assertSame('maya', $fields['virtualModel.model.preset.name']);
        $this->assertSame('studio', $fields['virtualModel.scene.preset.name']);
        $this->assertSame('crossedarms', $fields['virtualModel.pose']);
        $this->assertSame('PORTRAIT_HD_4_3', $fields['virtualModel.size']);
        $this->assertSame('ai.auto', $fields['ironing.mode']);
    }

    /** Model options belong to the model mode and nowhere else. */
    public function test_model_options_are_ignored_without_the_model_mode(): void
    {
        $fields = app(PhotoroomService::class)->buildFields([
            'ghost_mannequin' => true,
            'vm_model'        => 'maya',
        ]);

        $this->assertArrayNotHasKey('virtualModel.mode', $fields);
        $this->assertArrayNotHasKey('virtualModel.model.preset.name', $fields);
    }

    /*
     * ── Rate limiting and quota ────────────────────────────────────────────
     *
     * Photoroom answers 429 for two different situations that read the same in
     * the body: the per-minute rate limit, which clears in under a minute, and
     * a spent quota, which does not. Telling them apart is the difference
     * between a short wait and a batch that re-uploads itself for nothing.
     */

    /** A quota that runs out is recorded, not retried. */
    public function test_a_long_throttle_fails_immediately_instead_of_retrying(): void
    {
        \Illuminate\Support\Facades\Cache::flush();

        \Illuminate\Support\Facades\Http::fake([
            'image-api.photoroom.com/*' => \Illuminate\Support\Facades\Http::response(
                json_encode(['detail' => 'Request was throttled. Expected available in 14782 seconds.']),
                429,
            ),
        ]);

        $started = microtime(true);

        try {
            app(PhotoroomService::class)->edit('fake-bytes', ['remove_background' => true]);
            $this->fail('A spent quota should surface as an exception.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('quota is exhausted', $e->getMessage());
            $this->assertStringContainsString('4h 6m', $e->getMessage());
        }

        // One request, not three: the retries would have taken the better part
        // of a minute and been refused by the same closed window each time.
        \Illuminate\Support\Facades\Http::assertSentCount(1);
        $this->assertLessThan(5, microtime(true) - $started);
    }

    /** Once the quota is known to be spent, nothing else is uploaded. */
    public function test_the_rest_of_the_batch_fails_without_uploading(): void
    {
        \Illuminate\Support\Facades\Cache::flush();

        \Illuminate\Support\Facades\Http::fake([
            'image-api.photoroom.com/*' => \Illuminate\Support\Facades\Http::response(
                json_encode(['detail' => 'Request was throttled. Expected available in 14782 seconds.']),
                429,
            ),
        ]);

        $service = app(PhotoroomService::class);

        foreach (range(1, 3) as $ignored) {
            try {
                $service->edit('fake-bytes', ['remove_background' => true]);
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('quota is exhausted', $e->getMessage());
            }
        }

        // Only the first image had to find out the hard way.
        \Illuminate\Support\Facades\Http::assertSentCount(1);
    }

    /** A short throttle is the per-minute window, and is worth waiting out. */
    public function test_a_short_throttle_is_retried(): void
    {
        \Illuminate\Support\Facades\Cache::flush();

        $calls = 0;

        \Illuminate\Support\Facades\Http::fake([
            'image-api.photoroom.com/*' => function () use (&$calls) {
                $calls++;

                return $calls === 1
                    ? \Illuminate\Support\Facades\Http::response(
                        json_encode(['detail' => 'Request was throttled. Expected available in 1 seconds.']),
                        429,
                    )
                    : \Illuminate\Support\Facades\Http::response('edited-bytes', 200);
            },
        ]);

        $result = app(PhotoroomService::class)->edit('fake-bytes', ['remove_background' => true]);

        $this->assertSame('edited-bytes', $result);
        $this->assertSame(2, $calls);
    }

    /** A quota marker belongs to the key that hit it, not to the app. */
    public function test_swapping_the_key_clears_a_spent_quota(): void
    {
        \Illuminate\Support\Facades\Cache::flush();

        \Illuminate\Support\Facades\Http::fake([
            'image-api.photoroom.com/*' => function ($request) {
                return $request->hasHeader('x-api-key', 'sandbox_test_key')
                    ? \Illuminate\Support\Facades\Http::response(
                        json_encode(['detail' => 'Request was throttled. Expected available in 14782 seconds.']),
                        429,
                    )
                    : \Illuminate\Support\Facades\Http::response('edited-bytes', 200);
            },
        ]);

        try {
            app(PhotoroomService::class)->edit('fake-bytes', ['remove_background' => true]);
        } catch (\RuntimeException $e) {
            // Expected — the sandbox key is spent.
        }

        // The live key starts clean rather than inheriting four hours of
        // back-off from the key it replaced.
        config(['services.photoroom.api_key' => 'live_test_key']);

        $this->assertSame(
            'edited-bytes',
            (new PhotoroomService())->edit('fake-bytes', ['remove_background' => true]),
        );
    }

    /*
     * ── Output size ────────────────────────────────────────────────────────
     *
     * A mixed catalogue — shoes, bags, dresses, watches, caps — needs every
     * photo landing on the same canvas whatever its native shape. The size
     * picker was once hidden for the AI cleanup mode, back when that mode let
     * Photoroom redraw the garment on a canvas of its own; it no longer does.
     */

    /** A pixel size survives the AI cleanup mode rather than being dropped. */
    public function test_output_size_is_kept_for_the_ai_cleanup_mode(): void
    {
        $group = \App\Models\PhotoEditGroup::create([
            'photo_edit_session_id' => PhotoEditSession::create([
                'user_id'       => $this->editor()->id,
                'name'          => 'Run',
                'onedrive_link' => 'https://example.com',
                'edits'         => PhotoroomService::defaultEdits(),
            ])->id,
            'sku'   => 'SKU-1',
            'edits' => array_merge(PhotoroomService::defaultEdits(), [
                'ghost_mannequin' => true,
                'width'           => 2000,
                'height'          => 2000,
            ]),
        ]);

        $this->assertSame(2000, $group->fresh()->edits['width']);
        $this->assertSame(2000, $group->fresh()->edits['height']);
    }

    /**
     * The job downgrades every apparel mode to a plain cutout before the edit,
     * which is what leaves outputSize free to apply. Guarding the pair together
     * because the size is only honoured while nothing generates its own canvas.
     */
    public function test_the_ai_cleanup_mode_sends_the_requested_pixel_size(): void
    {
        $service = app(PhotoroomService::class);

        $edits = [
            'ghost_mannequin'   => true,
            'remove_background' => true,
            'background_mode'   => 'white',
            'width'             => 2000,
            'height'            => 2000,
        ];

        // What EditPhotoItemJob hands to edit() once it has stripped the
        // generative flags in favour of a real-pixel cutout.
        $itemEdits = array_merge($edits, [
            'ghost_mannequin' => false,
            'flat_lay'        => false,
            'virtual_model'   => false,
        ]);

        $this->assertFalse($service->generatesOwnCanvas($itemEdits));
        $this->assertSame('2000x2000', $service->buildFields($itemEdits)['outputSize']);
    }

    /** Left unset, the photo keeps whatever size it came in at. */
    public function test_no_output_size_is_sent_when_none_was_asked_for(): void
    {
        $fields = app(PhotoroomService::class)->buildFields([
            'remove_background' => true,
            'background_mode'   => 'white',
        ]);

        $this->assertArrayNotHasKey('outputSize', $fields);
    }

    /*
     * ── Photoroom feature coverage ─────────────────────────────────────────
     *
     * Everything below is a parameter the API has always accepted and this app
     * did not send. Guarded per feature, because the failure mode when one is
     * wrong is silence: Photoroom ignores what it does not recognise, and the
     * result looks like the option simply did nothing.
     */

    /** Relighting defaults to the mode that leaves garment colour alone. */
    public function test_lighting_uses_the_colour_safe_mode(): void
    {
        $fields = app(PhotoroomService::class)->buildFields([
            'remove_background' => true,
            'lighting'          => 'ai.preserve-hue-and-saturation',
        ]);

        $this->assertSame('ai.preserve-hue-and-saturation', $fields['lighting.mode']);
    }

    /**
     * Sessions saved before relighting had modes stored it as a checkbox.
     * Re-running one should not be what changes a garment's colour.
     */
    public function test_a_legacy_lighting_checkbox_becomes_the_colour_safe_mode(): void
    {
        $fields = app(PhotoroomService::class)->buildFields([
            'remove_background' => true,
            'lighting'          => true,
        ]);

        $this->assertSame('ai.preserve-hue-and-saturation', $fields['lighting.mode']);
    }

    /** Sharper cutout edges are asked for whenever there is a cutout. */
    public function test_hd_cutout_is_requested_for_background_removal(): void
    {
        $service = app(PhotoroomService::class);

        $this->assertSame('auto', $service->buildHeaders(['remove_background' => true])['pr-hd-background-removal']);
        $this->assertArrayNotHasKey('pr-hd-background-removal', $service->buildHeaders(['remove_background' => false]));
    }

    /** WebP is honoured; JPEG is not, when the result has to carry alpha. */
    public function test_export_format_never_flattens_a_transparent_cutout(): void
    {
        $service = app(PhotoroomService::class);

        $transparent = ['remove_background' => true, 'background_mode' => 'transparent'];

        $this->assertSame('png',  $service->outputFormat($transparent + ['export_format' => 'jpg']));
        $this->assertSame('webp', $service->outputFormat($transparent + ['export_format' => 'webp']));
        $this->assertSame('png',  $service->outputFormat($transparent + ['export_format' => 'auto']));

        // On a solid background there is no alpha to lose.
        $this->assertSame('jpg', $service->outputFormat(['remove_background' => true, 'background_mode' => 'white', 'export_format' => 'jpg']));
    }

    /** A size ceiling caps the long edge without forcing a shape. */
    public function test_max_dimensions_are_sent_without_an_exact_size(): void
    {
        $fields = app(PhotoroomService::class)->buildFields([
            'remove_background' => true,
            'max_width'         => 2048,
            'max_height'        => 2048,
        ]);

        $this->assertSame('2048', $fields['maxWidth']);
        $this->assertSame('2048', $fields['maxHeight']);
        $this->assertArrayNotHasKey('outputSize', $fields);
    }

    /** Cropping tight to the product is a canvas choice of its own. */
    public function test_cropped_subject_output_size(): void
    {
        $fields = app(PhotoroomService::class)->buildFields([
            'remove_background' => true,
            'output_size_mode'  => 'croppedSubject',
        ]);

        $this->assertSame('croppedSubject', $fields['outputSize']);
    }

    /** Per-edge spacing takes fractions or pixels, and blank means no opinion. */
    public function test_per_edge_padding_and_margins(): void
    {
        $fields = app(PhotoroomService::class)->buildFields([
            'remove_background' => true,
            'padding_top'       => '0.2',
            'padding_bottom'    => '40px',
            'margin_left'       => '0.1',
            'margin_right'      => '',
        ]);

        $this->assertSame('0.2',  $fields['paddingTop']);
        $this->assertSame('40px', $fields['paddingBottom']);
        $this->assertSame('0.1',  $fields['marginLeft']);
        $this->assertArrayNotHasKey('marginRight', $fields);
    }

    /** Describing the subject is the one-request answer to a visible stand. */
    public function test_text_guided_segmentation(): void
    {
        $fields = app(PhotoroomService::class)->buildFields([
            'remove_background'            => true,
            'segmentation_prompt'          => 'the dress',
            'segmentation_negative_prompt' => 'the mannequin',
            'segmentation_mode'            => 'keepSalientObject',
        ]);

        $this->assertSame('the dress',        $fields['segmentation.prompt']);
        $this->assertSame('the mannequin',    $fields['segmentation.negativePrompt']);
        $this->assertSame('keepSalientObject', $fields['segmentation.mode']);
    }

    /** Output colour is pinned so one product looks the same on every listing. */
    public function test_srgb_is_sent_by_default(): void
    {
        $fields = app(PhotoroomService::class)->buildFields(['remove_background' => true]);

        $this->assertSame('sRGB', $fields['colorSpace']);
    }

    /** Seeds are what make a re-edit reproduce the run it re-edits. */
    public function test_seeds_are_sent_for_every_generative_step(): void
    {
        $fields = app(PhotoroomService::class)->buildFields([
            'remove_background' => true,
            'beautify'          => 'ai.auto',
            'beautify_seed'     => 7,
            'expand'            => true,
            'expand_seed'       => 8,
            'uncrop'            => true,
            'uncrop_seed'       => 9,
        ]);

        $this->assertSame('7', $fields['beautify.seed']);
        $this->assertSame('8', $fields['expand.seed']);
        $this->assertSame('9', $fields['uncrop.seed']);
    }

    /** A reference photo steers a generated background better than adjectives. */
    public function test_background_guidance(): void
    {
        $fields = app(PhotoroomService::class)->buildFields([
            'remove_background'          => true,
            'background_mode'            => 'prompt',
            'background_prompt'          => 'a marble surface',
            'background_negative_prompt' => 'people',
            'background_guidance_url'    => 'https://example.com/ref.jpg',
            'background_guidance_scale'  => 0.8,
        ]);

        $this->assertSame('people', $fields['background.negativePrompt']);
        $this->assertSame('https://example.com/ref.jpg', $fields['background.guidance.imageUrl']);
        $this->assertSame('0.8', $fields['background.guidance.scale']);
    }

    /** Virtual Try-On: your own model and set instead of Photoroom's stock. */
    public function test_virtual_try_on_uses_custom_images_over_presets(): void
    {
        $fields = app(PhotoroomService::class)->buildFields([
            'virtual_model'         => true,
            'vm_model'              => 'maya',
            'vm_scene'              => 'studio',
            'vm_model_url'          => 'https://example.com/model.jpg',
            'vm_scene_url'          => 'https://example.com/scene.jpg',
            'vm_extra_product_urls' => ['https://example.com/back.jpg'],
        ]);

        $this->assertSame('https://example.com/model.jpg', $fields['virtualModel.model.custom.imageUrl']);
        $this->assertSame('https://example.com/scene.jpg', $fields['virtualModel.scene.custom.imageUrl']);
        $this->assertSame('https://example.com/back.jpg',  $fields['virtualModel.additionalProductImages[0].imageUrl']);

        // A supplied photo replaces the preset rather than fighting with it.
        $this->assertArrayNotHasKey('virtualModel.model.preset.name', $fields);
        $this->assertArrayNotHasKey('virtualModel.scene.preset.name', $fields);
    }

    /** A template supplies the canvas, so an explicit size would contradict it. */
    public function test_a_template_id_replaces_the_output_size(): void
    {
        $fields = app(PhotoroomService::class)->buildFields([
            'remove_background' => true,
            'width'             => 2000,
            'height'            => 2000,
            'template_id'       => 'tpl_123',
        ]);

        $this->assertSame('tpl_123', $fields['templateId']);
        $this->assertArrayNotHasKey('outputSize', $fields);
    }

    /** Generating from a description has no image to attach. */
    public function test_generate_from_prompt_sends_no_image(): void
    {
        \Illuminate\Support\Facades\Cache::flush();

        \Illuminate\Support\Facades\Http::fake([
            'image-api.photoroom.com/*' => \Illuminate\Support\Facades\Http::response('generated-bytes', 200),
        ]);

        $result = app(PhotoroomService::class)->generateFromPrompt('a linen shirt on marble', 'SQUARE_HD', 42);

        $this->assertSame('generated-bytes', $result);

        \Illuminate\Support\Facades\Http::assertSent(function ($request) {
            $names = array_column($request->data(), 'name');

            return in_array('imageFromPrompt.prompt', $names, true)
                && in_array('imageFromPrompt.seed', $names, true)
                && !in_array('imageFile', $names, true);
        });
    }

    /** The cutout confidence Photoroom volunteers is kept, not discarded. */
    public function test_the_uncertainty_score_is_captured(): void
    {
        \Illuminate\Support\Facades\Cache::flush();

        \Illuminate\Support\Facades\Http::fake([
            'image-api.photoroom.com/*' => \Illuminate\Support\Facades\Http::response(
                'edited-bytes', 200, ['x-uncertainty-score' => '0.42'],
            ),
        ]);

        $service = app(PhotoroomService::class);
        $service->edit('fake-bytes', ['remove_background' => true]);

        $this->assertSame(0.42, $service->lastUncertaintyScore());
    }

    /** -1 means "no opinion", which must not be filed as "completely sure". */
    public function test_an_unavailable_uncertainty_score_is_not_recorded_as_confident(): void
    {
        \Illuminate\Support\Facades\Cache::flush();

        \Illuminate\Support\Facades\Http::fake([
            'image-api.photoroom.com/*' => \Illuminate\Support\Facades\Http::response(
                'edited-bytes', 200, ['x-uncertainty-score' => '-1'],
            ),
        ]);

        $service = app(PhotoroomService::class);
        $service->edit('fake-bytes', ['remove_background' => true]);

        $this->assertNull($service->lastUncertaintyScore());
    }

    /*
     * ── AI cleanup stays as it was ─────────────────────────────────────────
     *
     * "Keep the photo + AI cleanup" is the mode in daily use, so the changes
     * around it are guarded by what it must keep doing: erase a visible
     * mannequin with a generative pass, then cut out the real pixels. The new
     * options are additions beside it, not a replacement for it.
     */

    private function fakeGarment(): string
    {
        $img = imagecreatetruecolor(60, 90);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 180, 160));

        ob_start();
        imagejpeg($img, null, 90);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    private function runCleanupItem(array $editOverrides, array $classification): PhotoEditItem
    {
        \Illuminate\Support\Facades\Cache::flush();

        $session = PhotoEditSession::create([
            'user_id'       => $this->editor()->id,
            'name'          => 'Cleanup',
            'onedrive_link' => 'https://example.com',
            'edits'         => array_merge([
                'ghost_mannequin'   => true,
                'remove_background' => true,
                'background_mode'   => 'white',
            ], $editOverrides),
        ]);

        $item = PhotoEditItem::create([
            'photo_edit_session_id' => $session->id,
            'filename'              => 'a.jpg',
            'status'                => 'pending',
            'onedrive_drive_id'     => 'drive-1',
            'onedrive_item_id'      => 'item-1',
        ]);

        $garment = $this->fakeGarment();

        $oneDrive = \Mockery::mock(\App\Services\OneDriveService::class);
        $oneDrive->shouldReceive('setUser')->andReturnSelf();
        $oneDrive->shouldReceive('downloadFileById')->andReturn($garment);

        $gemini = \Mockery::mock(\App\Services\GeminiService::class);
        $gemini->shouldReceive('classifyGarmentView')->andReturn($classification);

        \Illuminate\Support\Facades\Http::fake([
            'image-api.photoroom.com/*' => \Illuminate\Support\Facades\Http::response($garment, 200),
        ]);

        (new \App\Jobs\EditPhotoItemJob($item->id))->handle(
            $oneDrive,
            app(ImageProcessingService::class),
            app(PhotoroomService::class),
            $gemini,
        );

        return $item->fresh();
    }

    /** The default path still spends the extra erase request, exactly as before. */
    public function test_ai_cleanup_still_erases_a_visible_mannequin(): void
    {
        $item = $this->runCleanupItem([], ['view_type' => 'front', 'mannequin_visible' => true]);

        $this->assertSame('edited', $item->status, $item->error_message ?? '');
        $this->assertSame('mannequin_removed', $item->apparel_mode_applied);

        // Two requests: the generative erase, then the real-pixel cutout.
        \Illuminate\Support\Facades\Http::assertSentCount(2);
    }

    /** No mannequin in frame means no erase pass and no wasted credit. */
    public function test_ai_cleanup_spends_nothing_extra_when_there_is_no_mannequin(): void
    {
        $item = $this->runCleanupItem([], ['view_type' => 'front', 'mannequin_visible' => false]);

        $this->assertSame('none', $item->apparel_mode_applied);
        \Illuminate\Support\Facades\Http::assertSentCount(1);
    }

    /** A segmentation prompt does the same job inside the one cutout request. */
    public function test_a_segmentation_prompt_replaces_the_extra_erase_request(): void
    {
        $item = $this->runCleanupItem(
            ['segmentation_prompt' => 'the dress'],
            ['view_type' => 'front', 'mannequin_visible' => true],
        );

        $this->assertSame('segmented', $item->apparel_mode_applied);
        \Illuminate\Support\Facades\Http::assertSentCount(1);
    }

    /**
     * Virtual Model is generative by definition — there is no real-pixel
     * version of a person who was never photographed — so it is never
     * downgraded to a cutout the way Ghost Mannequin is.
     */
    public function test_putting_it_on_a_model_is_never_downgraded(): void
    {
        $item = $this->runCleanupItem(
            ['ghost_mannequin' => false, 'virtual_model' => true],
            ['view_type' => 'front', 'mannequin_visible' => true],
        );

        $this->assertSame('on_model', $item->apparel_mode_applied);

        // Photoroom builds the whole scene, so no separate erase pass.
        \Illuminate\Support\Facades\Http::assertSentCount(1);
    }

    /** Ghost Mannequin keeps its downgrade — that is the colour-fidelity guard. */
    public function test_ghost_mannequin_is_still_downgraded_to_a_cutout(): void
    {
        $item = $this->runCleanupItem([], ['view_type' => 'front', 'mannequin_visible' => true]);

        $this->assertNotSame('on_model', $item->apparel_mode_applied);
        $this->assertSame('mannequin_removed', $item->apparel_mode_applied);
    }


    /** AVIF holds alpha, so a cutout asked for as AVIF stays AVIF. */
    public function test_avif_is_offered_and_survives_a_transparent_cutout(): void
    {
        $service = app(PhotoroomService::class);

        $transparent = ['remove_background' => true, 'background_mode' => 'transparent'];

        $this->assertSame('avif', $service->outputFormat($transparent + ['export_format' => 'avif']));
        $this->assertSame('avif', $service->outputFormat(['remove_background' => true, 'background_mode' => 'white', 'export_format' => 'avif']));
        $this->assertTrue($service->producesAlpha($transparent + ['export_format' => 'avif']));
    }

    /** Asking for the original size is said outright, not left to 'auto'. */
    public function test_original_image_output_size(): void
    {
        $fields = app(PhotoroomService::class)->buildFields([
            'remove_background' => true,
            'output_size_mode'  => 'originalImage',
        ]);

        $this->assertSame('originalImage', $fields['outputSize']);
    }

    /**
     * The mannequin-erase pass runs outside the main request, so it needs its
     * own seed — without one it was the single step that stopped a re-edit
     * reproducing the run it was re-editing.
     */
    public function test_the_mannequin_erase_pass_accepts_a_seed(): void
    {
        \Illuminate\Support\Facades\Cache::flush();

        \Illuminate\Support\Facades\Http::fake([
            'image-api.photoroom.com/*' => \Illuminate\Support\Facades\Http::response('erased-bytes', 200),
        ]);

        app(PhotoroomService::class)->removeMannequin('fake-bytes', 'a.jpg', 99);

        \Illuminate\Support\Facades\Http::assertSent(function ($request) {
            foreach ($request->data() as $part) {
                if ($part['name'] === 'editWithAI.seed') {
                    return $part['contents'] === '99';
                }
            }

            return false;
        });
    }

    /** No seed given, none sent — the pass stays random by default. */
    public function test_the_mannequin_erase_pass_sends_no_seed_by_default(): void
    {
        \Illuminate\Support\Facades\Cache::flush();

        \Illuminate\Support\Facades\Http::fake([
            'image-api.photoroom.com/*' => \Illuminate\Support\Facades\Http::response('erased-bytes', 200),
        ]);

        app(PhotoroomService::class)->removeMannequin('fake-bytes', 'a.jpg');

        \Illuminate\Support\Facades\Http::assertSent(
            fn ($request) => !in_array('editWithAI.seed', array_column($request->data(), 'name'), true),
        );
    }

}
