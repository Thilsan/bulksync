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
