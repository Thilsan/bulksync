<?php

namespace Tests\Feature;

use App\Models\PhotoEditSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Completed" on its own only ever meant the editing pass finished — it said
 * nothing about whether any of those photos had actually reached Shopify. A
 * run edited an hour ago and never pushed looked identical on this list to
 * one that had gone out, same green pill, and the only way to tell them apart
 * was to open each one and check the Pushed column by eye.
 *
 * The status pill now says which of the two a completed run actually is —
 * "Shopify Completed" once every edited photo has been pushed, "Push to
 * Shopify" while any of them have not — and falls back to the plain status
 * word for every run that was never a candidate for either (still
 * processing, failed, nothing edited yet).
 */
class PhotoEditorHistoryStatusTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(User $user, array $overrides): PhotoEditSession
    {
        return PhotoEditSession::create(array_merge([
            'user_id'       => $user->id,
            'name'          => 'Run',
            'onedrive_link' => 'https://example.com',
            'edits'         => [],
            'status'        => 'completed',
            'total_files'   => 0,
            'edited_files'  => 0,
            'pushed_files'  => 0,
            'failed_files'  => 0,
        ], $overrides));
    }

    private function editor(): User
    {
        return User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
    }

    public function test_a_completed_run_pushed_in_full_reads_shopify_completed(): void
    {
        $user = $this->editor();
        $this->makeRun($user, ['edited_files' => 5, 'pushed_files' => 5]);

        $this->actingAs($user)
            ->get(route('photo-editor.history'))
            ->assertOk()
            ->assertSee('Shopify Completed');
    }

    /**
     * A run pushed past its own edited count still reads as fully pushed —
     * a re-push of an item already on Shopify (allowed elsewhere in this
     * app) can leave pushed_files at or above edited_files, and that is
     * still "nothing left to push", not a reason to undercount it.
     */
    public function test_a_run_pushed_at_least_as_much_as_edited_reads_shopify_completed(): void
    {
        $user = $this->editor();
        $this->makeRun($user, ['edited_files' => 5, 'pushed_files' => 6]);

        $this->actingAs($user)
            ->get(route('photo-editor.history'))
            ->assertOk()
            ->assertSee('Shopify Completed');
    }

    public function test_a_completed_run_not_yet_pushed_reads_push_to_shopify(): void
    {
        $user = $this->editor();
        $this->makeRun($user, ['edited_files' => 5, 'pushed_files' => 0]);

        $this->actingAs($user)
            ->get(route('photo-editor.history'))
            ->assertOk()
            ->assertSee('Push to Shopify');
    }

    /** Partly pushed is still "something left to do", not "done". */
    public function test_a_partly_pushed_run_still_reads_push_to_shopify(): void
    {
        $user = $this->editor();
        $this->makeRun($user, ['edited_files' => 5, 'pushed_files' => 2]);

        $html = $this->actingAs($user)
            ->get(route('photo-editor.history'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Push to Shopify', $html);
        $this->assertStringNotContainsString('Shopify Completed', $html);
    }

    /**
     * Nothing to push is not a push decision — a run that completed with
     * every photo failed has no edited output at all, and the plain status
     * word is the honest answer, not a claim about Shopify either way.
     */
    public function test_a_completed_run_with_nothing_edited_keeps_the_plain_status(): void
    {
        $user = $this->editor();
        $this->makeRun($user, ['edited_files' => 0, 'pushed_files' => 0, 'failed_files' => 3]);

        $html = $this->actingAs($user)
            ->get(route('photo-editor.history'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Completed', $html);
        $this->assertStringNotContainsString('Push to Shopify', $html);
        $this->assertStringNotContainsString('Shopify Completed', $html);
    }

    /** A run still in progress is never described in terms of Shopify at all. */
    public function test_a_processing_run_shows_its_own_status_not_a_push_state(): void
    {
        $user = $this->editor();
        $this->makeRun($user, ['status' => 'processing', 'edited_files' => 2, 'pushed_files' => 0]);

        $html = $this->actingAs($user)
            ->get(route('photo-editor.history'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Processing', $html);
        $this->assertStringNotContainsString('Push to Shopify', $html);
    }

    /** Same for a status this list has no special wording for at all — it still says something. */
    public function test_an_unmatched_status_falls_back_to_its_own_word(): void
    {
        $user = $this->editor();
        $this->makeRun($user, ['status' => 'configuring']);

        $this->actingAs($user)
            ->get(route('photo-editor.history'))
            ->assertOk()
            ->assertSee('Configuring');
    }
}
