<?php

namespace Tests\Feature;

use App\Services\PhotoroomService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A spent monthly plan stops a batch rather than being discovered by each
 * photograph in it.
 *
 * Photoroom answers an exhausted allowance with 402 and the body "You have
 * exhausted the number of images in your plan". That used to be an ordinary
 * error: every remaining item uploaded its several megabytes, waited, and was
 * told the same thing. A twelve-item run showed it; a catalogue run would be
 * gigabytes of uploads, each still counted as a request against the plan it
 * has already spent.
 *
 * 429 already had this handling and 402 did not, because 429 is "too many,
 * too fast" — a different thing that happens to share a remedy.
 */
class PhotoroomPlanExhaustedTest extends TestCase
{
    private const BODY = 'You have exhausted the number of images in your plan. '
        . 'Visit https://app.photoroom.com/api-dashboard to update your plan';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['services.photoroom.key' => 'test-key', 'services.photoroom.rpm' => 6000]);
    }

    private function photoroom(): PhotoroomService
    {
        return app(PhotoroomService::class);
    }

    public function test_the_second_photo_never_leaves_the_building(): void
    {
        Http::fake(['*' => Http::response(self::BODY, 402)]);

        try {
            $this->photoroom()->edit('fake-bytes', ['remove_background' => true], 'a.jpg');
        } catch (\Throwable) {
            // Expected — what matters is what happens to the next one.
        }

        $after = 0;
        Http::fake(function () use (&$after) {
            $after++;

            return Http::response(self::BODY, 402);
        });

        try {
            $this->photoroom()->edit('fake-bytes', ['remove_background' => true], 'b.jpg');
            $this->fail('a second photo was accepted after the plan was known to be spent');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('exhausted', $e->getMessage());
        }

        $this->assertSame(0, $after, 'the second photo was uploaded to a plan with nothing left in it');
    }

    /** And the operator is told what Photoroom said, not a paraphrase of it. */
    public function test_the_card_carries_photorooms_own_words(): void
    {
        Http::fake(['*' => Http::response(self::BODY, 402)]);

        try {
            $this->photoroom()->edit('fake-bytes', ['remove_background' => true], 'a.jpg');
            $this->fail('an exhausted plan was reported as a success');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('402', $e->getMessage());
            $this->assertStringContainsString('exhausted the number of images', $e->getMessage());
        }
    }

    /** The door reopens on its own, so a topped-up plan needs no cache clear. */
    public function test_the_door_reopens_without_anybody_clearing_a_cache(): void
    {
        config(['services.photoroom.quota_closed_seconds' => 1]);

        // One stub throughout: a later Http::fake() does not replace an
        // earlier '*' pattern, it queues behind it.
        $spent = true;
        $calls = 0;

        Http::fake(function () use (&$spent, &$calls) {
            $calls++;

            return $spent
                ? Http::response(self::BODY, 402)
                : Http::response('ok-bytes', 200);
        });

        try {
            $this->photoroom()->edit('fake-bytes', ['remove_background' => true], 'a.jpg');
        } catch (\Throwable) {
            //
        }

        $this->assertSame(1, $calls);

        // The plan is topped up; nothing clears any cache.
        $spent = false;
        sleep(2);

        $this->photoroom()->edit('fake-bytes', ['remove_background' => true], 'b.jpg');

        $this->assertSame(2, $calls, 'the quota marker outlived its own window');
    }
}
