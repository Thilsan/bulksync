<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The first screen is a two-column grid: the form on the left, the cost and
 * folder-layout notes on the right. Trimming the form down to "where are the
 * photos" once left a stray </div> behind, which closed the left-hand column
 * early and dropped the notes underneath the form — so the shape is asserted
 * rather than eyeballed.
 */
class PhotoEditorLayoutTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The before/after panel compares like with like.
     *
     * It used to show the 420px review thumbnail on the left against the
     * full-size edit on the right — one image stretched up to fill its panel,
     * the other shrunk down into it. Every quality judgement made from that
     * view was measuring the thumbnail, not the edit.
     *
     * Asserted because the mistake is invisible: both panels look like
     * photographs of the same garment, and nothing says one of them is a
     * sixth of the resolution of the other.
     */
    public function test_the_before_and_after_panels_are_the_same_size_copies(): void
    {
        $view = file_get_contents(resource_path('views/photo-editor/show.blade.php'));

        // The comparison itself uses the two thumbnails.
        $this->assertStringContainsString('lightbox?.before_url', $view);
        $this->assertStringContainsString('lightbox?.after_url', $view);

        // The full file is offered as a link, never as one half of the pair.
        $this->assertStringNotContainsString(':src="lightbox?.full_url"', $view,
            'the full-size edit is being compared against a thumbnail again');
        $this->assertStringContainsString(':href="lightbox?.full_url"', $view);
    }

    /**
     * The push button asks before it writes to a live storefront.
     *
     * Everything else in this feature is reversible by re-running; pushing is
     * not — it puts images on a website customers are looking at, and decides
     * which one they see first. A stray click on a button labelled with a live
     * shop's name is not something to leave to a steady hand, so the dialog is
     * asserted rather than assumed still wired up.
     */
    public function test_pushing_to_shopify_asks_first(): void
    {
        $user = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);

        $session = \App\Models\PhotoEditSession::create([
            'user_id'       => $user->id,
            'name'          => 'Run',
            'onedrive_link' => 'https://example.com',
            'status'        => 'completed',
            'scan_status'   => 'scanned',
            'edits'         => [],
        ]);

        $html = $this->actingAs($user)
            ->get(route('photo-editor.show', $session))
            ->assertOk()
            ->getContent();

        // The button opens the dialog; it must not push on its own.
        $this->assertStringContainsString('@click="confirmOpen = true"', $html);
        $this->assertStringNotContainsString('@click="push()"', $html,
            'the push button still fires without asking');

        // And the dialog has to say what it is about to do, including the part
        // that changes products already on the shop.
        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('main', $html);
        $this->assertStringContainsString('Cancel', $html);
    }

    public function test_the_helper_column_sits_beside_the_form(): void
    {
        config(['services.photoroom.api_key' => 'sandbox_test_key']);

        $html = $this->actingAs(User::factory()->create([
            'is_active' => true, 'perm_photo_editor' => true,
        ]))->get(route('photo-editor.index'))->assertOk()->getContent();

        $dom = new \DOMDocument();
        @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);

        $form = $xpath->query('//form[contains(@action, "photo-editor")]')->item(0);
        $this->assertNotNull($form, 'the run form is missing');

        // Both grid tracks, or there is only one column to sit in.
        $this->assertStringContainsString('lg:grid-cols-', $form->getAttribute('class'));

        // The helper column must be a direct child of the form, beside the
        // left-hand column — not nested inside it.
        $aside = $xpath->query('./aside', $form);
        $this->assertSame(1, $aside->length, 'the helper column is not a direct child of the form');

        $this->assertStringContainsString('What this run costs', $aside->item(0)->textContent);

        // The submit button belongs to the form's own column, not adrift.
        $submit = $xpath->query('.//button[@type="submit"]', $form);
        $this->assertSame(1, $submit->length);
    }
}
