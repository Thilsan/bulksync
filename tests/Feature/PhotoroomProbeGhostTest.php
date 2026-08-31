<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Ghost Mannequin probe.
 *
 * A diagnostic that spends a Photoroom credit, so what is worth pinning is that
 * it refuses before it spends: a missing file, an unknown size preset and an
 * unconfigured key all have to stop it, and a live key has to ask first.
 */
class PhotoroomProbeGhostTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refuses_a_file_that_is_not_there(): void
    {
        config(['services.photoroom.api_key' => 'sandbox_test_key']);

        $this->artisan('photoroom:probe-ghost', ['file' => '/nowhere/at/all.jpg'])
            ->expectsOutputToContain('No such file')
            ->assertFailed();
    }

    public function test_it_refuses_a_size_preset_photoroom_does_not_have(): void
    {
        config(['services.photoroom.api_key' => 'sandbox_test_key']);

        $file = tempnam(sys_get_temp_dir(), 'probe') . '.jpg';
        file_put_contents($file, $this->jpeg());

        $this->artisan('photoroom:probe-ghost', ['file' => $file, '--size' => 'ENORMOUS'])
            ->expectsOutputToContain('Unknown size preset')
            ->assertFailed();

        @unlink($file);
    }

    public function test_it_refuses_without_a_key_rather_than_failing_at_the_api(): void
    {
        config(['services.photoroom.api_key' => null]);

        $file = tempnam(sys_get_temp_dir(), 'probe') . '.jpg';
        file_put_contents($file, $this->jpeg());

        $this->artisan('photoroom:probe-ghost', ['file' => $file])
            ->expectsOutputToContain('No Photoroom API key')
            ->assertFailed();

        @unlink($file);
    }

    /** A live key must ask before it spends; declining must spend nothing. */
    public function test_a_live_key_asks_first_and_declining_stops_it(): void
    {
        config(['services.photoroom.api_key' => 'live_key_not_sandbox']);

        $file = tempnam(sys_get_temp_dir(), 'probe') . '.jpg';
        file_put_contents($file, $this->jpeg());

        $this->artisan('photoroom:probe-ghost', ['file' => $file])
            ->expectsOutputToContain('LIVE')
            ->expectsConfirmation('That is one live credit. Continue?', 'no')
            ->assertSuccessful();

        @unlink($file);
    }

    private function jpeg(): string
    {
        $img = imagecreatetruecolor(120, 160);
        imagefill($img, 0, 0, imagecolorallocate($img, 240, 240, 244));

        ob_start();
        imagejpeg($img, null, 90);
        imagedestroy($img);

        return (string) ob_get_clean();
    }
}
