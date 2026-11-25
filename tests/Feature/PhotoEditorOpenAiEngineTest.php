<?php

namespace Tests\Feature;

use App\Jobs\EditPhotoItemJob;
use App\Models\PhotoEditGroup;
use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\User;
use App\Services\GhostCompositeService;
use App\Services\GhostPrintTransplantService;
use App\Services\ImageProcessingService;
use App\Services\OneDriveService;
use App\Services\OpenAiImageService;
use App\Services\PhotoroomService;
use App\Support\PhotoroomAllowance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * OpenAI as a second editing engine, picked on the settings screen.
 *
 * What matters: a photo set to OpenAI never reaches Photoroom (and is not
 * charged to its allowance), the settings arrive as an instruction, and the
 * one thing OpenAI cannot do — virtual models — quietly stays on Photoroom.
 */
class PhotoEditorOpenAiEngineTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string,string> Fields of the last request OpenAI received. */
    private array $sentToOpenAi = [];

    private int $photoroomCalls = 0;

    /** What the fake OpenAI hands back; null means a 1024 square on pure white. */
    private ?string $openAiReturns = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.openai.api_key' => 'test-key', 'services.openai.image_model' => 'gpt-image-2']);
    }

    private function jpeg(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 240, 240, 240));
        imagefilledrectangle($img, (int) ($w * 0.3), (int) ($h * 0.2), (int) ($w * 0.7), (int) ($h * 0.8),
            imagecolorallocate($img, 30, 60, 120));
        ob_start();
        imagejpeg($img, null, 90);
        imagedestroy($img);

        return ob_get_clean();
    }

    private function png(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imagefilledrectangle($img, (int) ($w * 0.3), (int) ($h * 0.2), (int) ($w * 0.7), (int) ($h * 0.8),
            imagecolorallocate($img, 30, 60, 120));
        ob_start();
        imagepng($img);
        imagedestroy($img);

        return ob_get_clean();
    }

    private function fakeApis(): void
    {
        Http::fake([
            'api.openai.com/*' => function (Request $request) {
                foreach ($request->data() as $part) {
                    $this->sentToOpenAi[$part['name'] ?? ''] = is_string($part['contents'] ?? null)
                        ? $part['contents']
                        : '';
                }

                return Http::response([
                    'data'  => [['b64_json' => base64_encode($this->openAiReturns ?? $this->png(1024, 1024))]],
                    'usage' => ['total_tokens' => 1],
                ]);
            },
            'image-api.photoroom.com/*' => function (Request $request) {
                $this->photoroomCalls++;

                foreach ($request->data() as $part) {
                    if (($part['name'] ?? '') === 'imageFile') {
                        return Http::response($part['contents'], 200, ['Content-Type' => 'image/jpeg']);
                    }
                }

                return Http::response('', 500);
            },
        ]);
    }

    private function editWith(array $edits): PhotoEditItem
    {
        $this->mock(OneDriveService::class, function ($mock) {
            $mock->shouldReceive('setUser')->andReturnSelf();
            $mock->shouldReceive('downloadFileById')->andReturn($this->jpeg(1200, 1600));
        });

        $this->fakeApis();

        $session = PhotoEditSession::create([
            'user_id'       => User::factory()->create(['is_active' => true])->id,
            'name'          => 'Run',
            'onedrive_link' => 'https://example.com',
            'edits'         => $edits,
        ]);

        $item = PhotoEditItem::create([
            'photo_edit_session_id' => $session->id,
            'filename'              => 'shirt.jpg',
            'status'                => 'pending',
            'onedrive_drive_id'     => 'drive-1',
            'onedrive_item_id'      => 'item-1',
        ]);

        (new EditPhotoItemJob($item->id))->handle(
            app(OneDriveService::class),
            app(ImageProcessingService::class),
            app(PhotoroomService::class),
            app(GhostPrintTransplantService::class),
            app(GhostCompositeService::class),
        );

        return $item->fresh();
    }

    public function test_a_photo_set_to_openai_is_edited_by_openai_and_not_photoroom(): void
    {
        $item = $this->editWith([
            'engine'            => 'openai',
            'remove_background' => true,
            'background_mode'   => 'white',
            'remove_price_tag'  => true,
            'ironing'           => true,
        ]);

        $this->assertSame('edited', $item->status, (string) $item->error_message);
        $this->assertSame('openai', $item->edit_engine);
        $this->assertSame(0, $item->photoroom_requests);
        $this->assertSame(0, $this->photoroomCalls, 'Photoroom was called for an OpenAI photo');

        $this->assertSame('gpt-image-2', $this->sentToOpenAi['model']);
        $this->assertSame('opaque', $this->sentToOpenAi['background']);
        $this->assertStringContainsString('#FFFFFF', $this->sentToOpenAi['prompt']);
        $this->assertStringContainsString('price tag', $this->sentToOpenAi['prompt']);
        $this->assertStringContainsString('wrinkles', $this->sentToOpenAi['prompt']);
    }

    public function test_photoroom_is_still_the_default(): void
    {
        $item = $this->editWith(['remove_background' => true, 'background_mode' => 'white']);

        $this->assertSame('edited', $item->status, (string) $item->error_message);
        $this->assertSame('photoroom', $item->edit_engine);
        $this->assertSame(1, $this->photoroomCalls);
        $this->assertSame([], $this->sentToOpenAi);
    }

    public function test_a_virtual_model_photo_stays_on_photoroom(): void
    {
        $item = $this->editWith(['engine' => 'openai', 'virtual_model' => true, 'remove_background' => true]);

        $this->assertSame('photoroom', $item->edit_engine);
        $this->assertSame([], $this->sentToOpenAi);
    }

    public function test_openai_refusing_fails_the_photo_with_its_own_message(): void
    {
        $this->mock(OneDriveService::class, function ($mock) {
            $mock->shouldReceive('setUser')->andReturnSelf();
            $mock->shouldReceive('downloadFileById')->andReturn($this->jpeg(800, 800));
        });

        Http::fake(['api.openai.com/*' => Http::response([
            'error' => ['message' => 'Project does not have access to model gpt-image-2'],
        ], 403)]);

        $session = PhotoEditSession::create([
            'user_id'       => User::factory()->create(['is_active' => true])->id,
            'name'          => 'Run',
            'onedrive_link' => 'https://example.com',
            'edits'         => ['engine' => 'openai', 'remove_background' => true],
        ]);
        $item = PhotoEditItem::create([
            'photo_edit_session_id' => $session->id,
            'filename'              => 'a.jpg',
            'status'                => 'pending',
            'onedrive_drive_id'     => 'drive-1',
            'onedrive_item_id'      => 'item-1',
        ]);

        $job = new EditPhotoItemJob($item->id);
        $job->withFakeQueueInteractions();

        try {
            $job->handle(
                app(OneDriveService::class),
                app(ImageProcessingService::class),
                app(PhotoroomService::class),
                app(GhostPrintTransplantService::class),
                app(GhostCompositeService::class),
            );
        } catch (\Throwable) {
            // Rethrown for the queue's retry; the row is what is checked.
        }

        $item->refresh();
        $this->assertSame('failed', $item->status);
        $this->assertStringContainsString('does not have access to model', (string) $item->error_message);
        $this->assertSame('openai', $item->edit_engine);
    }

    public function test_openai_photos_are_not_charged_to_the_photoroom_allowance(): void
    {
        $session = PhotoEditSession::create([
            'user_id'       => User::factory()->create(['is_active' => true])->id,
            'name'          => 'Run',
            'onedrive_link' => 'https://example.com',
            'edits'         => [],
        ]);

        foreach (['photoroom', 'openai', null] as $i => $engine) {
            PhotoEditItem::create([
                'photo_edit_session_id' => $session->id,
                'filename'              => "{$i}.jpg",
                'status'                => 'edited',
                'photoroom_requests'    => $engine === 'openai' ? 0 : 1,
                'edit_engine'           => $engine,
            ]);
        }

        $report = app(PhotoroomAllowance::class)->report(24);

        $this->assertSame(2, $report['succeeded']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('postedEngines')]
    public function test_the_engine_is_saved_from_the_settings_form(string $posted, string $stored): void
    {
        Queue::fake();

        $user    = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
        $session = PhotoEditSession::create([
            'user_id'       => $user->id,
            'name'          => 'Run',
            'onedrive_link' => 'https://example.com',
            'edits'         => ['remove_background' => true],
            'status'        => 'configuring',
            'scan_status'   => 'scanned',
        ]);
        $group = PhotoEditGroup::create(['photo_edit_session_id' => $session->id, 'sku' => 'SKU-1']);

        $this->actingAs($user)->post(route('photo-editor.start', $session), [
            'edits'  => ['engine' => $posted],
            'groups' => [$group->id => []],
        ]);

        $this->assertSame($stored, $session->fresh()->edits['engine']);
    }

    public static function postedEngines(): array
    {
        return [
            'openai'                     => ['openai', 'openai'],
            'photoroom'                  => ['photoroom', 'photoroom'],
            'unknown means photoroom'    => ['midjourney', 'photoroom'],
        ];
    }

    public function test_the_requested_size_keeps_the_photos_shape_within_openais_limits(): void
    {
        $service = app(OpenAiImageService::class);

        $this->assertSame('1536x2048', $service->outputSize(1200, 1600));
        $this->assertSame('2048x2048', $service->outputSize(500, 500));

        // Narrower than 1:3 is held at 1:3, and every side is a multiple of 16.
        [$w, $h] = array_map('intval', explode('x', $service->outputSize(100, 2000)));
        $this->assertSame(0, $w % 16);
        $this->assertSame(0, $h % 16);
        $this->assertGreaterThanOrEqual(1 / 3 - 0.02, $w / $h);
    }

    public function test_a_transparent_background_is_asked_for_as_transparent(): void
    {
        $this->editWith(['engine' => 'openai', 'remove_background' => true, 'background_mode' => 'transparent']);

        $this->assertSame('transparent', $this->sentToOpenAi['background']);
        $this->assertSame('png', $this->sentToOpenAi['output_format']);
    }

    /**
     * What OpenAI actually sends: its own portrait size, on a painted white
     * that is not quite white, with a soft grey vignette towards the edges.
     */
    private function generatedShirt(int $w = 1360, int $h = 2048, int $shirt = 0x3C5A8C, int $outline = -1): string
    {
        $img = imagecreatetruecolor($w, $h);
        $cx = $w / 2;
        $cy = $h / 2;
        $maxD = sqrt($cx * $cx + $cy * $cy);
        for ($y = 0; $y < $h; $y += 2) {
            for ($x = 0; $x < $w; $x += 2) {
                $v = (int) round(247 - 14 * (sqrt(($x - $cx) ** 2 + ($y - $cy) ** 2) / $maxD));
                imagefilledrectangle($img, $x, $y, $x + 1, $y + 1, imagecolorallocate($img, $v, $v, $v));
            }
        }
        if ($outline >= 0) {
            imagefilledrectangle($img, (int) ($w * 0.2) - 6, (int) ($h * 0.25) - 6, (int) ($w * 0.8) + 6, (int) ($h * 0.75) + 6, $outline);
        }
        imagefilledrectangle($img, (int) ($w * 0.2), (int) ($h * 0.25), (int) ($w * 0.8), (int) ($h * 0.75), $shirt);
        ob_start();
        imagepng($img);
        imagedestroy($img);

        return ob_get_clean();
    }

    private function publishedImage(PhotoEditItem $item): \GdImage
    {
        return imagecreatefromstring(file_get_contents(storage_path('app/' . $item->edited_path)));
    }

    public function test_an_openai_result_is_framed_onto_the_runs_canvas(): void
    {
        $this->openAiReturns = $this->generatedShirt();

        $item = $this->editWith(PhotoroomService::applyFramingPreset([
            'engine'            => 'openai',
            'remove_background' => true,
            'background_mode'   => 'white',
            'ghost_mannequin'   => true,
        ], 'men/t-shirt'));

        $this->assertSame('edited', $item->status, (string) $item->error_message);

        $img = $this->publishedImage($item);
        $this->assertSame(2000, imagesx($img));
        $this->assertSame(2000, imagesy($img));

        // Framed, not merely padded: the shirt fills the preset's 80% of the height.
        $column = [];
        for ($y = 0; $y < 2000; $y += 4) {
            $c = imagecolorat($img, 1000, $y);
            $column[] = (($c >> 16) & 0xFF) < 150 ? $y : null;
        }
        $rows = array_values(array_filter($column, fn ($v) => $v !== null));
        $this->assertEqualsWithDelta(0.80, (end($rows) - $rows[0]) / 2000, 0.03);

        // And the painted grey is gone: the corners are true white.
        foreach ([[5, 5], [1994, 5], [5, 1994], [1994, 1994]] as [$x, $y]) {
            $this->assertSame(0xFFFFFF, imagecolorat($img, $x, $y) & 0xFFFFFF);
        }
    }

    public function test_a_white_garment_is_not_erased_with_the_background(): void
    {
        // A white shirt (250) on painted off-white, held apart by its shading edge.
        $this->openAiReturns = $this->generatedShirt(shirt: 0xFAFAFA, outline: 0xB4B4B4);

        $item = $this->editWith(PhotoroomService::applyFramingPreset([
            'engine'            => 'openai',
            'remove_background' => true,
            'background_mode'   => 'white',
        ], 'men/t-shirt'));

        $img = $this->publishedImage($item);
        $this->assertSame(0xFAFAFA, imagecolorat($img, 1000, 1000) & 0xFFFFFF, 'the shirt itself was whitened');
    }

    public function test_a_result_that_cannot_be_framed_still_reaches_the_canvas_size(): void
    {
        // A busy scene edge to edge: nothing to find, so it is fitted instead.
        $img = imagecreatetruecolor(1360, 2048);
        for ($i = 0; $i < 400; $i++) {
            imagefilledellipse($img, random_int(0, 1360), random_int(0, 2048), 200, 200,
                imagecolorallocate($img, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        }
        ob_start();
        imagepng($img);
        $this->openAiReturns = ob_get_clean();

        $item = $this->editWith([
            'engine'            => 'openai',
            'remove_background' => true,
            'background_mode'   => 'white',
            'width'             => 2000,
            'height'            => 2000,
        ]);

        $out = $this->publishedImage($item);
        $this->assertSame([2000, 2000], [imagesx($out), imagesy($out)]);
    }

    public function test_a_transparent_run_gets_real_transparency_from_a_painted_white(): void
    {
        $this->openAiReturns = $this->generatedShirt();

        $item = $this->editWith(PhotoroomService::applyFramingPreset([
            'engine'            => 'openai',
            'remove_background' => true,
            'background_mode'   => 'transparent',
        ], 'men/t-shirt'));

        $img = $this->publishedImage($item);
        $this->assertSame([2000, 2000], [imagesx($img), imagesy($img)]);
        $this->assertSame(127, (imagecolorat($img, 5, 5) >> 24) & 0x7F);
        $this->assertSame(0, (imagecolorat($img, 1000, 1000) >> 24) & 0x7F);
    }

    /**
     * Every category's framing applies to OpenAI the same as to Photoroom.
     *
     * The framing is done here after the edit, not by Photoroom: padding, base
     * line, height fill and the Samsonite canvas are all applied locally. So
     * an OpenAI result has to be framed by that same step, not just padded
     * onto the canvas by the fallback.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('everyCategory')]
    public function test_every_category_frames_an_openai_result(string $preset): void
    {
        // Half size: the framing is proportional, and 42 of these add up.
        $this->openAiReturns = $this->generatedShirt(680, 1024);

        // Records whether the fallback had to step in.
        $spy = new class extends ImageProcessingService {
            public bool $fellBack = false;

            public function fitOntoCanvas(string $imageContent, int $width, int $height, float $padding = 0.10,
                bool $transparent = false, string $colour = 'FFFFFF'): string
            {
                $this->fellBack = true;

                return parent::fitOntoCanvas($imageContent, $width, $height, $padding, $transparent, $colour);
            }
        };
        $this->app->instance(ImageProcessingService::class, $spy);

        $edits = PhotoroomService::applyFramingPreset([
            'engine'            => 'openai',
            'remove_background' => true,
            'background_mode'   => 'white',
        ], $preset);

        $item = $this->editWith($edits);
        $this->assertSame('edited', $item->status, (string) $item->error_message);

        $img = $this->publishedImage($item);
        $this->assertSame(
            [(int) ($edits['canvas_width'] ?? $edits['width']), (int) ($edits['canvas_height'] ?? $edits['height'])],
            [imagesx($img), imagesy($img)],
        );
        $this->assertFalse($spy->fellBack, 'the category framing did not run; the photo was only padded');
    }

    public static function everyCategory(): array
    {
        $keys = array_keys(PhotoroomService::framingPresetsFlat());

        return array_combine($keys, array_map(fn ($k) => [$k], $keys));
    }

    /** A back shot came back as a front: OpenAI is told which side it is looking at. */
    public function test_a_photo_marked_as_the_back_is_kept_as_the_back(): void
    {
        $this->mock(OneDriveService::class, function ($mock) {
            $mock->shouldReceive('setUser')->andReturnSelf();
            $mock->shouldReceive('downloadFileById')->andReturn($this->jpeg(1200, 1600));
        });
        $this->fakeApis();

        $session = PhotoEditSession::create([
            'user_id'       => User::factory()->create(['is_active' => true])->id,
            'name'          => 'Run',
            'onedrive_link' => 'https://example.com',
            'edits'         => ['engine' => 'openai', 'remove_background' => true, 'ghost_mannequin' => true],
        ]);
        $item = PhotoEditItem::create([
            'photo_edit_session_id' => $session->id,
            'filename'              => '1_0.jpg',
            'status'                => 'pending',
            'back_view'             => true,
            'onedrive_drive_id'     => 'drive-1',
            'onedrive_item_id'      => 'item-1',
        ]);

        (new EditPhotoItemJob($item->id))->handle(
            app(OneDriveService::class),
            app(ImageProcessingService::class),
            app(PhotoroomService::class),
            app(GhostPrintTransplantService::class),
            app(GhostCompositeService::class),
        );

        $prompt = $this->sentToOpenAi['prompt'];
        $this->assertStringContainsString('BACK of the garment', $prompt);
        $this->assertStringContainsString('no front neckline', $prompt);
        // The inside-of-the-neck instruction is a front-view one, and is left out.
        $this->assertStringNotContainsString('inside fabric', $prompt);
    }

    public function test_every_photo_is_told_to_keep_its_side_and_angle(): void
    {
        $prompt = app(OpenAiImageService::class)->buildPrompt(['remove_background' => true]);

        $this->assertStringContainsString('same side of the product facing the camera', $prompt);
        $this->assertStringNotContainsString('BACK of the garment', $prompt);
    }
}
