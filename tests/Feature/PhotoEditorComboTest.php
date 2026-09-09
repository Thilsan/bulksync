<?php

namespace Tests\Feature;

use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Picking two edited pieces and getting one set image back.
 *
 * The property under test that is easy to lose is the ordering: the ids arrive
 * in the order the operator ticked them, and that order is the only thing
 * saying which garment goes on top. Nothing in the files says it. So there is a
 * test that reversing the picks reverses the result, because a controller that
 * quietly sorted by id would pass every other test here.
 */
class PhotoEditorComboTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create([
            'is_active' => true,
            'perm_photo_editor' => true,
        ]);
    }

    private function makeSession(User $user): PhotoEditSession
    {
        return PhotoEditSession::create([
            'user_id'       => $user->id,
            'name'          => 'Run',
            'onedrive_link' => 'https://example.com',
            'edits'         => [],
        ]);
    }

    /**
     * An item with a real edited file on disk, so the controller has something
     * to read.
     */
    private function item(
        PhotoEditSession $session,
        string $filename,
        string $bytes,
        string $sku = 'SETSKU1',
    ): PhotoEditItem {
        $item = PhotoEditItem::create([
            'photo_edit_session_id' => $session->id,
            'filename'              => $filename,
            'sku_detected'          => $sku,
            'status'                => 'edited',
        ]);

        $dir = storage_path('app/' . $session->storageDir());

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $relative = $session->storageDir() . "/{$item->id}-after.png";
        file_put_contents(storage_path('app/' . $relative), $bytes);

        $item->update(['edited_path' => $relative]);

        return $item->fresh();
    }

    public function test_the_set_is_kept_as_a_pushable_item(): void
    {
        $user    = $this->user();
        $session = $this->makeSession($user);

        $top    = $this->item($session, 'top.png', $this->cutout(1400, 900, 'wide'), 'MSN124COM00282');
        $bottom = $this->item($session, 'trouser.png', $this->cutout(900, 1500, 'tall'), 'MSN124COM00282');

        $this->actingAs($user)->post(
            route('photo-editor.combo', $session),
            ['item_ids' => [$top->id, $bottom->id]],
        )->assertRedirect();

        $set = PhotoEditItem::where('photo_edit_session_id', $session->id)
            ->where('kind', 'set')
            ->first();

        $this->assertNotNull($set, 'no set item was created');

        // The two things the push path needs, and nothing else.
        $this->assertSame('MSN124COM00282', $set->sku_detected, 'the set did not inherit the pieces\' SKU');
        $this->assertSame('edited', $set->status);
        $this->assertNotNull($set->edited_path);

        $image = @imagecreatefromstring((string) file_get_contents(storage_path('app/' . $set->edited_path)));

        $this->assertNotFalse($image, 'the set file is not a readable image');
        $this->assertSame(2000, imagesx($image), 'the set is not on the standard canvas');
        $this->assertSame(2000, imagesy($image));
    }

    /**
     * Two pieces from different products cannot be filed under either of them.
     */
    public function test_pieces_with_different_skus_are_refused(): void
    {
        $user    = $this->user();
        $session = $this->makeSession($user);

        $a = $this->item($session, 'a.png', $this->cutout(900, 900, 'square'), 'MSN124TOP00111');
        $b = $this->item($session, 'b.png', $this->cutout(900, 900, 'square'), 'MSN124BTM00222');

        $this->actingAs($user)
            ->post(route('photo-editor.combo', $session), ['item_ids' => [$a->id, $b->id]])
            ->assertRedirect();

        $this->assertSame(
            0,
            PhotoEditItem::where('photo_edit_session_id', $session->id)->where('kind', 'set')->count(),
            'a set was composed from two different products',
        );
    }

    /** A composed set has no photograph behind it, so it cannot be re-edited. */
    public function test_a_set_cannot_be_re_edited(): void
    {
        $user    = $this->user();
        $session = $this->makeSession($user);

        $top    = $this->item($session, 'top.png', $this->cutout(1400, 900, 'wide'), 'SET1');
        $bottom = $this->item($session, 'trouser.png', $this->cutout(900, 1500, 'tall'), 'SET1');

        $this->actingAs($user)->post(
            route('photo-editor.combo', $session),
            ['item_ids' => [$top->id, $bottom->id]],
        );

        $set = PhotoEditItem::where('photo_edit_session_id', $session->id)->where('kind', 'set')->firstOrFail();

        $this->actingAs($user)
            ->post(route('photo-editor.item.reedit', [$session, $set]))
            ->assertStatus(422);
    }

    /**
     * The ordering property. A controller that sorted the ids would put the
     * same piece on top whichever way round they were picked.
     */
    public function test_the_order_they_were_picked_decides_which_is_on_top(): void
    {
        $user    = $this->user();
        $session = $this->makeSession($user);

        // Two pieces of different colours, so which landed on top is readable
        // off the result rather than inferred.
        $red  = $this->item($session, 'red.png', $this->cutout(900, 900, 'square', [200, 40, 40]));
        $blue = $this->item($session, 'blue.png', $this->cutout(900, 900, 'square', [40, 40, 200]));

        $redOnTop  = $this->topColour($user, $session, [$red->id, $blue->id]);
        $blueOnTop = $this->topColour($user, $session, [$blue->id, $red->id]);

        $this->assertGreaterThan($redOnTop[2], $redOnTop[0], 'red was picked first but is not the top piece');
        $this->assertGreaterThan($blueOnTop[0], $blueOnTop[2], 'blue was picked first but is not the top piece');
    }

    public function test_it_refuses_anything_other_than_two_pieces(): void
    {
        $user    = $this->user();
        $session = $this->makeSession($user);

        $one = $this->item($session, 'one.png', $this->cutout(900, 900, 'square'));

        $this->actingAs($user)
            ->post(route('photo-editor.combo', $session), ['item_ids' => [$one->id]])
            ->assertSessionHasErrors('item_ids');
    }

    public function test_a_piece_with_no_edited_file_is_refused(): void
    {
        $user    = $this->user();
        $session = $this->makeSession($user);

        $edited = $this->item($session, 'edited.png', $this->cutout(900, 900, 'square'));

        $unedited = PhotoEditItem::create([
            'photo_edit_session_id' => $session->id,
            'filename'              => 'pending.png',
            'status'                => 'pending',
        ]);

        $this->actingAs($user)
            ->post(route('photo-editor.combo', $session), ['item_ids' => [$edited->id, $unedited->id]])
            ->assertNotFound();
    }

    /** Somebody else's run is not theirs to compose from. */
    public function test_another_users_session_is_refused(): void
    {
        $owner   = $this->user();
        $session = $this->makeSession($owner);

        $a = $this->item($session, 'a.png', $this->cutout(900, 900, 'square'));
        $b = $this->item($session, 'b.png', $this->cutout(900, 900, 'square'));

        $this->actingAs($this->user())
            ->post(route('photo-editor.combo', $session), ['item_ids' => [$a->id, $b->id]])
            ->assertForbidden();
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * The colour found in the upper part of the composed set.
     *
     * @return array{0:int,1:int,2:int}
     */
    private function topColour(User $user, PhotoEditSession $session, array $ids): array
    {
        $this->actingAs($user)->post(
            route('photo-editor.combo', $session),
            ['item_ids' => $ids],
        )->assertRedirect();

        $set = PhotoEditItem::where('photo_edit_session_id', $session->id)
            ->where('kind', 'set')
            ->latest('id')
            ->firstOrFail();

        $image = @imagecreatefromstring((string) file_get_contents(storage_path('app/' . $set->edited_path)));

        // A quarter of the way down is inside the top piece's band on every
        // layout this service produces.
        $c = imagecolorat($image, 1000, 500);

        return [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
    }

    /** @param array{0:int,1:int,2:int} $rgb */
    private function cutout(int $w, int $h, string $shape, array $rgb = [150, 150, 152]): string
    {
        $img = imagecreatetruecolor($w, $h);

        imagesavealpha($img, true);
        imagealphablending($img, false);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagealphablending($img, true);

        $colour = imagecolorallocate($img, ...$rgb);

        // Inset, so there is transparent margin for the subject box to find.
        imagefilledrectangle(
            $img,
            (int) ($w * 0.1),
            (int) ($h * 0.1),
            (int) ($w * 0.9),
            (int) ($h * 0.9),
            $colour,
        );

        unset($shape);

        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }
}
