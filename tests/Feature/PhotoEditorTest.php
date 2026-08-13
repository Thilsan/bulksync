<?php

namespace Tests\Feature;

use App\Jobs\PushEditedPhotoJob;
use App\Jobs\ScanPhotoEditFolderJob;
use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\User;
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
            'ghost_mannequin'   => true,
            'width'             => 1200,
            'height'            => 1200,
            'padding'           => 0.15,
            'shadow'            => 'ai.soft',
        ]);

        $this->assertSame('true',      $fields['removeBackground']);
        $this->assertSame('F5F5F5',    $fields['background.color']);   // no leading #
        $this->assertSame('ai.auto',   $fields['ghostMannequin.mode']);
        $this->assertSame('1200x1200', $fields['outputSize']);
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
}
