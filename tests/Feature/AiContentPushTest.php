<?php

namespace Tests\Feature;

use App\Jobs\PushAiContentJob;
use App\Jobs\RecheckSeoAfterPushJob;
use App\Models\AiContentImage;
use App\Models\AiContentItem;
use App\Models\AiContentSession;
use App\Models\Store;
use App\Models\User;
use App\Services\ShopifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Pushing reviewed AI content to Shopify. It used to run inside the button's
 * request, and a 500-SKU session outlived it: products after the cut-off were
 * silently never pushed.
 */
class AiContentPushTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Store $store;
    private AiContentSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user  = User::create(['name' => 'Merch', 'email' => 'merch@example.test', 'password' => 'password', 'is_active' => true]);
        $this->store = Store::create(['name' => 'Blue Salon', 'shopify_domain' => 'blue.myshopify.com', 'is_active' => true]);

        $this->session = AiContentSession::create([
            'user_id' => $this->user->id, 'store_id' => $this->store->id, 'input_type' => 'manual',
            'status' => 'ready', 'total_items' => 3, 'processed_items' => 3,
        ]);
    }

    private function item(string $productId, string $status = 'done'): AiContentItem
    {
        return AiContentItem::create([
            'session_id' => $this->session->id, 'sku' => "SKU-{$productId}", 'shopify_product_id' => $productId,
            'product_title' => "Product {$productId}", 'ai_description' => '<p>Copy</p>',
            'ai_meta_title' => 'Meta', 'ai_meta_description' => 'Meta desc', 'ai_title' => 'New title',
            'status' => $status,
        ]);
    }

    public function test_the_push_button_queues_every_ticked_item_and_saves_edits(): void
    {
        Queue::fake();

        $a = $this->item('1');
        $b = $this->item('2');
        $image = AiContentImage::create(['item_id' => $b->id, 'shopify_image_id' => '90', 'image_url' => 'https://cdn.test/b.jpg', 'position' => 0, 'ai_alt_text' => 'Old alt', 'status' => 'done']);

        $this->actingAs($this->user)
            ->postJson(route('ai-content.push', $this->session), [
                'confirmed'       => [$a->id, $b->id],
                'description'     => [$a->id => '<p>Edited</p>'],
                'image_alt'       => [$image->id => 'Edited alt'],
                'overwrite_title' => [$a->id => true, $b->id => false],
                'selected_tags'   => [$b->id => ['Gift']],
            ])
            ->assertOk();

        $this->assertSame('<p>Edited</p>', $a->refresh()->ai_description);
        $this->assertSame('Edited alt', $image->refresh()->ai_alt_text);
        $this->assertSame('pushing', $this->session->refresh()->status);
        $this->assertSame(2, $this->session->total_items);

        Queue::assertPushed(PushAiContentJob::class, fn ($job) =>
            $job->itemIds === [$a->id, $b->id]
            && $job->overwriteTitleIds === [$a->id]
            && $job->selectedTags === [$b->id => ['Gift']]
        );
    }

    public function test_generate_arabic_accepts_the_page_json(): void
    {
        Queue::fake();

        $item = $this->item('1');

        $this->actingAs($this->user)
            ->postJson(route('ai-content.translate', $this->session), [
                'confirmed'   => [$item->id],
                'description' => [$item->id => '<p>Edited</p>'],
            ])
            ->assertOk();

        $this->assertSame('<p>Edited</p>', $item->refresh()->ai_description);
        $this->assertSame('translating', $this->session->refresh()->status);
    }

    public function test_the_page_opens_on_the_pushing_progress_rather_than_zero(): void
    {
        $this->session->update(['status' => 'pushing', 'total_items' => 4, 'processed_items' => 1]);

        $this->actingAs($this->user)
            ->get(route('ai-content.show', $this->session))
            ->assertOk()
            ->assertSee('Pushing to Shopify')
            ->assertSee('progress\\u0022:25', false);
    }

    public function test_the_job_pushes_generated_items_and_never_blanks_a_failed_one(): void
    {
        Queue::fake();

        $done   = $this->item('1');
        $failed = $this->item('2', 'failed');
        $broken = $this->item('3');

        $shopify = Mockery::mock(ShopifyService::class);
        $shopify->shouldReceive('updateProductContent')->with('1', '<p>Copy</p>', 'Meta', 'Meta desc', 'New title')->once();
        $shopify->shouldReceive('updateProductContent')->with('3', Mockery::any(), Mockery::any(), Mockery::any(), '')->once()
            ->andThrow(new \RuntimeException('429 Too Many Requests'));
        $shopify->shouldNotReceive('updateProductContent')->with('2', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any());
        $this->app->bind(ShopifyService::class, fn () => $shopify);

        $this->session->update(['status' => 'pushing', 'processed_items' => 0]);

        (new PushAiContentJob($this->session->id, $this->user->id, [$done->id, $failed->id, $broken->id], [$done->id]))->handle();

        $this->assertSame('pushed', $done->refresh()->status);
        $this->assertSame('failed', $failed->refresh()->status);
        $this->assertSame('done', $broken->refresh()->status);
        $this->assertStringContainsString('429', $broken->error_message);

        $this->session->refresh();
        $this->assertSame(3, $this->session->processed_items);

        // Something went live, so the push hands over to the SEO re-check
        // rather than calling itself done.
        $this->assertSame('rechecking', $this->session->status);
        Queue::assertPushed(RecheckSeoAfterPushJob::class, fn ($job) => $job->sessionId === $this->session->id);
    }
}
