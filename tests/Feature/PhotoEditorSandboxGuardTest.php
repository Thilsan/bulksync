<?php

namespace Tests\Feature;

use App\Jobs\PushEditedPhotoJob;
use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A watermarked image never reaches a live product page.
 *
 * A sandbox key hands the photograph back with the work largely not done and
 * "Photoroom" written across it. Nothing between the edit and the push can see
 * that: the file is on disk, the status reads edited, the badge says the
 * mannequin was segmented out. The review screen has always carried a banner
 * saying the results are watermarked — and a banner is a thing you read once
 * and stop seeing, so the run that gets here is the run where it was not read.
 */
class PhotoEditorSandboxGuardTest extends TestCase
{
    use RefreshDatabase;

    private function itemOnDisk(bool $sandbox): PhotoEditItem
    {
        $user = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);

        $session = PhotoEditSession::create([
            'user_id'       => $user->id,
            'name'          => 'Run',
            'onedrive_link' => 'https://example.com',
            'edits'         => [],
        ]);

        $item = PhotoEditItem::create([
            'photo_edit_session_id' => $session->id,
            'filename'              => 'a.jpg',
            'sku_detected'          => 'SKU-1',
            'status'                => 'edited',
            'sandbox'               => $sandbox,
        ]);

        $dir = storage_path('app/' . $session->storageDir());

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $relative = $session->storageDir() . "/{$item->id}-after.png";
        file_put_contents(storage_path('app/' . $relative), 'not really a png');

        $item->update(['edited_path' => $relative]);

        return $item->fresh();
    }

    public function test_a_sandbox_image_is_refused_before_anything_is_uploaded(): void
    {
        $item = $this->itemOnDisk(true);

        (new PushEditedPhotoJob($item->id))->handle();

        $item->refresh();

        $this->assertSame('failed', $item->status, 'a watermarked image was sent to Shopify');
        $this->assertStringContainsString('sandbox', (string) $item->error_message);
        $this->assertNull($item->shopify_image_id);
    }

    /**
     * Refused on how the image was made, not on today's key. An image edited on
     * the sandbox key does not become safe to publish because somebody has since
     * switched the key over — it is still the watermarked one.
     */
    public function test_switching_the_key_afterwards_does_not_release_an_old_sandbox_image(): void
    {
        config(['services.photoroom.api_key' => 'live_sk_pr_something']);

        $item = $this->itemOnDisk(true);

        (new PushEditedPhotoJob($item->id))->handle();

        $this->assertSame('failed', $item->fresh()->status);
    }

    /**
     * And the flag the guard reads is the key the edit ran on.
     *
     * Tested here rather than by pushing a live item, because the push would go
     * looking for a real Shopify store: ShopifyService talks through Guzzle
     * rather than the HTTP facade, so it cannot be faked from a test and would
     * reach out of the suite to find one.
     */
    public function test_the_flag_follows_the_key_the_edit_ran_on(): void
    {
        config(['services.photoroom.api_key' => 'sandbox_sk_pr_default_abc']);
        $this->assertTrue(app(\App\Services\PhotoroomService::class)->isSandbox());

        config(['services.photoroom.api_key' => 'sk_pr_live_abc']);
        $this->assertFalse(app(\App\Services\PhotoroomService::class)->isSandbox());
    }
}
