<?php

namespace Tests\Feature;

use App\Models\PhotoEditSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who ran each edit, on the history screen.
 *
 * A super admin's history is everybody's runs in one list, and nothing on it
 * said whose — which is fine until two people are editing the same brand and a
 * run has to be asked about. Everyone else sees only their own runs, so the
 * same column would be their own name repeated down the page; the test that
 * earns its keep is the one checking it stays hidden for them.
 */
class PhotoEditorHistoryOwnerTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(User $user, string $name): PhotoEditSession
    {
        return PhotoEditSession::create([
            'user_id'       => $user->id,
            'name'          => $name,
            'onedrive_link' => 'https://example.com',
            'edits'         => [],
            'status'        => 'completed',
        ]);
    }

    public function test_a_super_admin_sees_who_ran_each_session(): void
    {
        $admin = User::factory()->create([
            'is_active'         => true,
            'perm_photo_editor' => true,
            'is_super_admin'    => true,
            'name'              => 'Ahamed',
        ]);

        $colleague = User::factory()->create([
            'is_active'         => true,
            'perm_photo_editor' => true,
            'name'              => 'Priya Raman',
            'email'             => 'priya@example.com',
        ]);

        $this->makeRun($admin, 'Mine');
        $this->makeRun($colleague, 'Theirs');

        $html = $this->actingAs($admin)
            ->get(route('photo-editor.history'))
            ->assertOk()
            ->assertSee('Run by')
            ->getContent();

        // Both runs are listed, and each carries its own owner rather than the
        // viewer's — the failure worth catching is a column that renders
        // auth()->user() for every row.
        $this->assertStringContainsString('Priya Raman', $html, 'the colleague\'s run does not say who ran it');
        $this->assertStringContainsString('priya@example.com', $html);
        $this->assertStringContainsString('Ahamed', $html);
    }

    /** Every row would be their own name, so there is no column. */
    public function test_an_ordinary_user_does_not_get_the_column(): void
    {
        $user = User::factory()->create([
            'is_active'         => true,
            'perm_photo_editor' => true,
            'name'              => 'Solo Editor',
        ]);

        $this->makeRun($user, 'Mine');

        $this->actingAs($user)
            ->get(route('photo-editor.history'))
            ->assertOk()
            ->assertDontSee('Run by');
    }

    /**
     * Someone else's runs stay invisible to an ordinary user — the new column
     * must not have widened what the page shows, only what it labels.
     */
    public function test_an_ordinary_user_still_sees_only_their_own_runs(): void
    {
        $user      = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
        $colleague = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true, 'name' => 'Priya Raman']);

        $this->makeRun($user, 'Mine');
        $this->makeRun($colleague, 'Theirs');

        $this->actingAs($user)
            ->get(route('photo-editor.history'))
            ->assertOk()
            ->assertSee('Mine')
            ->assertDontSee('Theirs')
            ->assertDontSee('Priya Raman');
    }
}
