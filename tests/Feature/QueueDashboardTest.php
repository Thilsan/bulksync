<?php

namespace Tests\Feature;

use App\Jobs\GenerateAiContentJob;
use App\Models\AiContentSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The operational page: queue depth, worker age, failed jobs, and the two
 * actions that previously needed SSH and a hand-typed tinker line — restarting
 * workers after a deploy, and resuming a session that stopped short.
 */
class QueueDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Ada Okonkwo', 'email' => 'ada@example.test',
            'password' => 'password', 'is_active' => true, 'is_super_admin' => true,
        ]);

        $this->staff = User::create([
            'name' => 'Rui Barbosa', 'email' => 'rui@example.test',
            'password' => 'password', 'is_active' => true,
        ]);
    }

    private function makeSession(string $status, int $processed = 5, int $total = 20): AiContentSession
    {
        return AiContentSession::create([
            'user_id'         => $this->admin->id,
            'input_type'      => 'sku_list',
            'status'          => $status,
            'skus_json'       => json_encode(['A-1', 'B-1']),
            'total_items'     => $total,
            'processed_items' => $processed,
        ]);
    }

    public function test_super_admin_can_open_the_dashboard(): void
    {
        $this->actingAs($this->admin)
            ->get(route('super-admin.queues.index'))
            ->assertOk();
    }

    /** The page can restart workers and flush the failed list, so it is not for everyone. */
    public function test_ordinary_staff_are_refused(): void
    {
        $this->actingAs($this->staff)
            ->get(route('super-admin.queues.index'))
            ->assertForbidden();
    }

    public function test_status_endpoint_reports_queues_workers_and_failures(): void
    {
        $this->actingAs($this->admin)
            ->getJson(route('super-admin.queues.status'))
            ->assertOk()
            ->assertJsonStructure(['queues', 'workers' => ['available', 'list'], 'failed', 'sessions', 'driver']);
    }

    public function test_failed_jobs_are_listed_with_a_readable_reason(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid'       => 'abc-123',
            'connection' => 'redis',
            'queue'      => 'bulkupload',
            'payload'    => json_encode(['displayName' => 'App\\Jobs\\GenerateAiContentJob']),
            'exception'  => "App\\Jobs\\GenerateAiContentJob has timed out.\n#0 /app/vendor/... \n#1 more noise",
            'failed_at'  => now(),
        ]);

        $failed = $this->actingAs($this->admin)
            ->getJson(route('super-admin.queues.status'))
            ->assertOk()
            ->json('failed');

        $this->assertCount(1, $failed);
        $this->assertSame('GenerateAiContentJob', $failed[0]['name']);

        // Only the first line: the stack trace is what makes queue:failed unreadable.
        $this->assertSame('App\Jobs\GenerateAiContentJob has timed out.', $failed[0]['reason']);
    }

    public function test_a_stopped_session_can_be_resumed(): void
    {
        Queue::fake();

        $session = $this->makeSession('failed');

        $this->actingAs($this->admin)
            ->post(route('super-admin.queues.sessions.resume', $session))
            ->assertRedirect();

        Queue::assertPushed(GenerateAiContentJob::class);
    }

    /**
     * A slow product and a dead worker look identical from this page, so the
     * guard is on movement rather than status. Dispatching a second job onto a
     * live session would generate everything twice and bill for it twice.
     */
    public function test_a_session_that_is_still_moving_is_not_resumed_twice(): void
    {
        Queue::fake();

        $session = $this->makeSession('processing');
        $session->forceFill(['updated_at' => now()])->save();

        $this->actingAs($this->admin)
            ->post(route('super-admin.queues.sessions.resume', $session))
            ->assertRedirect()
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
    }

    /** A session stuck on "processing" with nothing moving is the case worth rescuing. */
    public function test_a_stalled_processing_session_can_still_be_resumed(): void
    {
        Queue::fake();

        $session = $this->makeSession('processing');
        $session->forceFill(['updated_at' => now()->subHour()])->save();

        $this->actingAs($this->admin)
            ->post(route('super-admin.queues.sessions.resume', $session))
            ->assertRedirect()
            ->assertSessionHas('success');

        Queue::assertPushed(GenerateAiContentJob::class);
    }

    public function test_staff_cannot_restart_workers(): void
    {
        $this->actingAs($this->staff)
            ->post(route('super-admin.queues.restart'))
            ->assertForbidden();
    }
}
