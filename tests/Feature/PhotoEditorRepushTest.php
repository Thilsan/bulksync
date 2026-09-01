<?php

namespace Tests\Feature;

use App\Jobs\PushEditedPhotoJob;
use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Sending an image to Shopify more than once.
 *
 * It used to be impossible, and not because of a rule: the full-size file was
 * deleted the moment Shopify accepted it, so there was nothing left to send.
 * Fixing a push to the wrong product meant editing the image again and paying
 * for it again.
 *
 * The file is kept now, which costs disk on a server whose disk has filled
 * twice — so what these pin is both halves of the bargain. An image can be sent
 * again while its file is there, and the file does not stay for ever.
 */
class PhotoEditorRepushTest extends TestCase
{
    use RefreshDatabase;

    private function makeSession(): PhotoEditSession
    {
        return PhotoEditSession::create([
            'user_id'       => User::factory()->create(['is_active' => true, 'perm_photo_editor' => true])->id,
            'name'          => 'Run',
            'onedrive_link' => 'https://example.com',
            'edits'         => [],
            'status'        => 'completed',
            'scan_status'   => 'scanned',
        ]);
    }

    private function item(PhotoEditSession $session, array $extra = []): PhotoEditItem
    {
        return PhotoEditItem::create(array_merge([
            'photo_edit_session_id' => $session->id,
            'kind'                  => 'cutout',
            'filename'              => 'a.jpg',
            'sku_detected'          => 'SKU-1',
            'status'                => 'pushed',
            'edited_path'           => 'photo-editor/1/1-after.jpg',
            'shopify_image_id'      => '9001',
            'onedrive_drive_id'     => 'drive-1',
            'onedrive_item_id'      => 'item-1',
        ], $extra));
    }

    /** An image on Shopify can be sent again, as long as its file survives. */
    public function test_an_image_already_on_shopify_can_be_sent_again(): void
    {
        $item = $this->item($this->makeSession());

        $this->assertTrue($item->isPushable(), 'a pushed image with its file should be sendable again');
        $this->assertTrue($item->isRepush(), 'and should know that doing so replaces what is there');
    }

    /** Once the sweep has taken the bytes, there is nothing left to send. */
    public function test_a_pushed_image_without_its_file_is_not_pushable(): void
    {
        $item = $this->item($this->makeSession(), ['edited_path' => null]);

        $this->assertFalse($item->isPushable());
    }

    /** A fresh edit is not a re-push, so nothing gets replaced. */
    public function test_a_first_push_is_not_a_replacement(): void
    {
        $item = $this->item($this->makeSession(), ['status' => 'edited', 'shopify_image_id' => null]);

        $this->assertTrue($item->isPushable());
        $this->assertFalse($item->isRepush());
    }

    /** The push endpoint queues a pushed item rather than dropping it. */
    public function test_the_push_endpoint_accepts_an_image_that_is_already_up(): void
    {
        Queue::fake();

        $session = $this->makeSession();
        $item    = $this->item($session);

        $this->actingAs($session->user)
            ->postJson(route('photo-editor.push', $session), ['item_ids' => [$item->id]])
            ->assertOk()
            ->assertJson(['queued' => 1, 'skipped' => 0]);

        Queue::assertPushed(PushEditedPhotoJob::class, 1);
    }
}
