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

    /**
     * An image that found no product can be sent again once one exists.
     *
     * "No match" means Shopify had nothing with that SKU — a reason that lives
     * outside this app and gets fixed outside it. The image is edited, paid
     * for and sitting on disk; leaving it stranded meant re-running the whole
     * edit to produce a file we already had.
     */
    public function test_an_image_that_found_no_product_can_be_sent_again(): void
    {
        $item = $this->item($this->makeSession(), [
            'status'           => 'skipped',
            'shopify_image_id' => null,
            'error_message'    => 'No Shopify product found for SKU or barcode: ENH121PER00508',
        ]);

        $this->assertTrue($item->isPushable(), 'a no-match image should be sendable once the product exists');
        $this->assertFalse($item->isRepush(), 'it was never on Shopify, so nothing is being replaced');
    }

    /** A failed push is worth retrying; a failed edit has no file to retry with. */
    public function test_a_failed_push_can_be_retried_but_a_failed_edit_cannot(): void
    {
        $session = $this->makeSession();

        $pushFailed = $this->item($session, ['status' => 'failed', 'shopify_image_id' => null]);
        $editFailed = $this->item($session, ['status' => 'failed', 'edited_path' => null, 'filename' => 'b.jpg']);

        $this->assertTrue($pushFailed->isPushable());
        $this->assertFalse($editFailed->isPushable(), 'there is no file to send');
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
