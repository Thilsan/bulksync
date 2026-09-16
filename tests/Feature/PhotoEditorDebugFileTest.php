<?php

namespace Tests\Feature;

use App\Models\PhotoEditSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Viewing a one-off diagnostic file in the browser, for whoever cannot reach
 * the server's filesystem any other way.
 *
 * A tinker script fetches a redraw straight from Photoroom to answer a
 * question the app's own review screen cannot — what did the model actually
 * draw, before any of our own decisions were applied to it — and drops the
 * result next to the session's own storage. This route is the only way to
 * look at it without SFTP, so it has to be gated exactly like every other file
 * this controller serves.
 */
class PhotoEditorDebugFileTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> directories to remove after the test, since these
     *  write to the real filesystem rather than a fake disk. */
    private array $writtenDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->writtenDirs as $dir) {
            \App\Models\PhotoEditSession::deleteDirectory($dir);
        }

        parent::tearDown();
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

    private function dropDebugFile(PhotoEditSession $session, string $filename, string $contents = 'PNGBYTES'): string
    {
        $dir = storage_path('app/' . $session->storageDir() . '/debug');

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $this->writtenDirs[] = dirname($dir); // the session's own storage root

        file_put_contents($dir . '/' . $filename, $contents);

        return $dir . '/' . $filename;
    }

    public function test_a_dropped_file_can_be_viewed_by_someone_authorised_for_the_session(): void
    {
        $user    = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
        $session = $this->makeSession($user);
        $this->dropDebugFile($session, 'redraw-9.png', 'THE-REDRAW-BYTES');

        $response = $this->actingAs($user)
            ->get(route('photo-editor.debug-file', [$session, 'redraw-9.png']))
            ->assertOk();

        $this->assertSame('THE-REDRAW-BYTES', $response->streamedContent());
    }

    /** Someone else's run, someone else's file. */
    public function test_someone_elses_session_is_not_viewable(): void
    {
        $owner    = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
        $outsider = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
        $session  = $this->makeSession($owner);
        $this->dropDebugFile($session, 'redraw-9.png');

        $this->actingAs($outsider)
            ->get(route('photo-editor.debug-file', [$session, 'redraw-9.png']))
            ->assertForbidden();
    }

    /** Nothing dropped, nothing to serve. */
    public function test_a_file_that_was_never_dropped_is_a_404(): void
    {
        $user    = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
        $session = $this->makeSession($user);

        $this->actingAs($user)
            ->get(route('photo-editor.debug-file', [$session, 'never-existed.png']))
            ->assertNotFound();
    }

    /**
     * A crafted filename must not be able to climb out of the debug folder.
     * The route pattern already rejects a slash, so this pins the controller's
     * own defence in depth against a name that gets through some other way.
     */
    public function test_a_path_climbing_filename_is_refused(): void
    {
        $user    = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
        $session = $this->makeSession($user);

        $response = $this->actingAs($user)->get('/photo-editor/' . $session->id . '/debug/' . urlencode('..'));

        $this->assertContains($response->status(), [404, 400]);
    }
}
