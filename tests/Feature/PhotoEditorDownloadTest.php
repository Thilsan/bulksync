<?php

namespace Tests\Feature;

use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Saving the edited file, for the items this app cannot push itself.
 *
 * A SKU with no Shopify product behind it yet is not a failed edit — it is a
 * finished picture with nowhere to go. Without this it would have to be edited
 * again, and paid for again, once the product exists.
 */
class PhotoEditorDownloadTest extends TestCase
{
    use RefreshDatabase;

    private function item(User $user, string $status = 'skipped'): PhotoEditItem
    {
        $session = PhotoEditSession::create([
            'user_id'       => $user->id,
            'name'          => 'Run',
            'onedrive_link' => 'https://example.com',
            'edits'         => ['remove_background' => true],
        ]);

        $relative = $session->storageDir() . '/edited.jpg';
        $absolute = storage_path('app/' . $relative);

        if (!is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0775, true);
        }

        file_put_contents($absolute, 'THE-EDITED-BYTES');

        return PhotoEditItem::create([
            'photo_edit_session_id' => $session->id,
            'filename'              => 'ANK547.jpg',
            'sku_detected'          => 'ANK547',
            'status'                => $status,
            'edited_path'           => $relative,
        ]);
    }

    /** What comes down is the file itself, not a re-render of it. */
    public function test_the_download_is_the_edited_file(): void
    {
        $user = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
        $item = $this->item($user);

        $response = $this->actingAs($user)
            ->get(route('photo-editor.download', [$item->photo_edit_session_id, $item->id]))
            ->assertOk();

        $this->assertSame('THE-EDITED-BYTES', $response->streamedContent());
    }

    /** Named for the SKU, since it is about to be uploaded against a product. */
    public function test_it_is_named_for_the_sku(): void
    {
        $user = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
        $item = $this->item($user);

        $this->actingAs($user)
            ->get(route('photo-editor.download', [$item->photo_edit_session_id, $item->id]))
            ->assertDownload('ANK547.jpg');
    }

    /** An item with nothing edited yet has nothing to hand over. */
    public function test_an_unedited_item_has_nothing_to_download(): void
    {
        $user = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
        $item = $this->item($user);
        $item->update(['edited_path' => null]);

        $this->actingAs($user)
            ->get(route('photo-editor.download', [$item->photo_edit_session_id, $item->id]))
            ->assertNotFound();
    }

    /**
     * The files sit outside the web root and are reached only through here, so
     * this route is the access control — an id from another person's run must
     * not resolve.
     */
    public function test_someone_elses_run_is_not_downloadable(): void
    {
        $owner     = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
        $outsider  = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
        $item      = $this->item($owner);

        $this->actingAs($outsider)
            ->get(route('photo-editor.download', [$item->photo_edit_session_id, $item->id]))
            ->assertForbidden();
    }

    /** An item id from a different session must not resolve against this one. */
    public function test_an_item_from_another_session_is_not_downloadable(): void
    {
        $user  = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
        $mine  = $this->item($user);
        $other = $this->item($user);

        $this->actingAs($user)
            ->get(route('photo-editor.download', [$mine->photo_edit_session_id, $other->id]))
            ->assertNotFound();
    }

    /** The whole selection in one file, for a run where nothing matched. */
    public function test_selected_items_come_down_as_one_zip(): void
    {
        $user    = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
        $first   = $this->item($user);
        $session = PhotoEditSession::find($first->photo_edit_session_id);
        $second  = $this->itemIn($session, 'ANK560');

        $response = $this->actingAs($user)
            ->post(route('photo-editor.download-selected', $session), [
                'item_ids' => [$first->id, $second->id],
            ])
            ->assertOk();

        $zipPath = tempnam(sys_get_temp_dir(), 'test-zip');
        file_put_contents($zipPath, $response->streamedContent());

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true, 'the response is not a readable zip');
        $this->assertSame(2, $zip->numFiles);

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }

        sort($names);
        $this->assertSame(['ANK547.jpg', 'ANK560.jpg'], $names, 'files are not named for their SKU');
        $this->assertSame('THE-EDITED-BYTES', $zip->getFromName('ANK547.jpg'));

        $zip->close();
        @unlink($zipPath);
    }

    /**
     * A product with several photos must not arrive as one file. Same SKU on
     * every item is the normal case, not an edge one.
     */
    public function test_repeated_skus_are_numbered_rather_than_overwritten(): void
    {
        $user    = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
        $first   = $this->item($user);
        $session = PhotoEditSession::find($first->photo_edit_session_id);
        $second  = $this->itemIn($session, 'ANK547');

        $response = $this->actingAs($user)
            ->post(route('photo-editor.download-selected', $session), [
                'item_ids' => [$first->id, $second->id],
            ])->assertOk();

        $zipPath = tempnam(sys_get_temp_dir(), 'test-zip');
        file_put_contents($zipPath, $response->streamedContent());

        $zip = new \ZipArchive();
        $zip->open($zipPath);

        $this->assertSame(2, $zip->numFiles, 'one file overwrote the other');
        $zip->close();
        @unlink($zipPath);
    }

    /** Nothing selected, nothing to send. */
    public function test_an_empty_selection_is_rejected(): void
    {
        $user    = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
        $session = PhotoEditSession::find($this->item($user)->photo_edit_session_id);

        $this->actingAs($user)
            ->post(route('photo-editor.download-selected', $session), ['item_ids' => []])
            ->assertSessionHasErrors('item_ids');
    }

    /** An id from another run must not be smuggled into this zip. */
    public function test_only_items_from_this_session_are_included(): void
    {
        $user    = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
        $mine    = $this->item($user);
        $theirs  = $this->item($user); // its own session
        $session = PhotoEditSession::find($mine->photo_edit_session_id);

        $response = $this->actingAs($user)
            ->post(route('photo-editor.download-selected', $session), [
                'item_ids' => [$mine->id, $theirs->id],
            ])->assertOk();

        $zipPath = tempnam(sys_get_temp_dir(), 'test-zip');
        file_put_contents($zipPath, $response->streamedContent());

        $zip = new \ZipArchive();
        $zip->open($zipPath);

        $this->assertSame(1, $zip->numFiles, 'an item from another session was included');
        $zip->close();
        @unlink($zipPath);
    }

    private function itemIn(PhotoEditSession $session, string $sku): PhotoEditItem
    {
        $relative = $session->storageDir() . '/' . $sku . '-edited.jpg';
        $absolute = storage_path('app/' . $relative);

        if (!is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0775, true);
        }

        file_put_contents($absolute, 'THE-EDITED-BYTES');

        return PhotoEditItem::create([
            'photo_edit_session_id' => $session->id,
            'filename'              => $sku . '.jpg',
            'sku_detected'          => $sku,
            'status'                => 'skipped',
            'edited_path'           => $relative,
        ]);
    }
}
