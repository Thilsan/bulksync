<?php

namespace Tests\Feature;

use App\Jobs\GenerateAiContentJob;
use App\Jobs\RunImageAuditJob;
use App\Models\AiContentSession;
use App\Models\ImageAuditSession;
use App\Models\StoreMigrationSession;
use App\Models\JobRun;
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
            ->post(route('super-admin.queues.sessions.resume', ['type' => 'ai-content', 'id' => $session->id]))
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
            ->post(route('super-admin.queues.sessions.resume', ['type' => 'ai-content', 'id' => $session->id]))
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
            ->post(route('super-admin.queues.sessions.resume', ['type' => 'ai-content', 'id' => $session->id]))
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

    private function makeRun(string $status, array $attributes = []): JobRun
    {
        return JobRun::create(array_merge([
            'name'        => 'GenerateAiContentJob',
            'queue'       => 'bulkupload',
            'status'      => $status,
            'attempt'     => 1,
            'started_at'  => now()->subMinutes(5),
            'finished_at' => $status === 'running' ? null : now(),
            'duration_ms' => $status === 'running' ? null : 4321,
        ], $attributes));
    }

    public function test_running_jobs_are_reported(): void
    {
        $this->makeRun('running');
        $this->makeRun('completed');

        $running = $this->actingAs($this->admin)
            ->getJson(route('super-admin.queues.status'))
            ->assertOk()
            ->json('running');

        $this->assertCount(1, $running);
        $this->assertSame('GenerateAiContentJob', $running[0]['name']);
    }

    /**
     * A worker killed outright never writes its own ending, so its row sits on
     * "running" for ever. Counted as live work it would make an idle queue read
     * as permanently busy — which is the opposite of what this page is for.
     */
    public function test_an_abandoned_running_row_is_not_counted_as_live(): void
    {
        $this->makeRun('running', ['started_at' => now()->subHours(JobRun::LOST_AFTER_HOURS + 1)]);

        $this->actingAs($this->admin)
            ->getJson(route('super-admin.queues.status'))
            ->assertOk()
            ->assertJsonCount(0, 'running');
    }

    public function test_history_lists_runs_and_filters_by_status(): void
    {
        $this->makeRun('completed');
        $this->makeRun('failed', ['exception' => 'Gemini credits are depleted']);

        $this->actingAs($this->admin)
            ->getJson(route('super-admin.queues.history'))
            ->assertOk()
            ->assertJsonCount(2, 'rows');

        $failed = $this->actingAs($this->admin)
            ->getJson(route('super-admin.queues.history', ['status' => 'failed']))
            ->assertOk()
            ->json('rows');

        $this->assertCount(1, $failed);
        $this->assertSame('Gemini credits are depleted', $failed[0]['exception']);
    }

    public function test_the_last_day_is_tallied_by_outcome(): void
    {
        $this->makeRun('completed');
        $this->makeRun('completed');
        $this->makeRun('failed');

        $this->actingAs($this->admin)
            ->getJson(route('super-admin.queues.status'))
            ->assertOk()
            ->assertJsonPath('today.completed', 2)
            ->assertJsonPath('today.failed', 1);
    }

    /**
     * Laravel pretty-prints JSON bodies across many physical lines, so a
     * line-based read splits every error in half — which is exactly what made
     * these unreadable when grepped off the server.
     */
    public function test_log_entries_are_reassembled_across_wrapped_lines(): void
    {
        // Never the real log: this test writes the file it reads, and pointed at
        // storage/logs it would destroy the developer's own log to do it.
        $path = tempnam(sys_get_temp_dir(), 'queuelog');
        config(['logging.channels.single.path' => $path]);

        file_put_contents($path, implode("\n", [
            '[2026-08-26 10:00:00] production.INFO: ignore me, first entries are dropped',
            '[2026-08-26 10:59:59] production.WARNING: Gemini API error {"status":429,"body":"{',
            '    \"message\": \"Your prepayment credits are depleted.\"',
            '}"}',
            '',
        ]));

        $entries = $this->actingAs($this->admin)
            ->getJson(route('super-admin.queues.logs', ['q' => 'prepayment']))
            ->assertOk()
            ->json('entries');

        $this->assertCount(1, $entries);
        $this->assertSame('warning', $entries[0]['level']);
        $this->assertStringContainsString('credits are depleted', $entries[0]['message']);

        @unlink($path);
    }

    /** All six kinds of session appear here, not only the one we happened to be fighting. */
    public function test_every_session_type_is_offered_for_resuming(): void
    {
        $this->makeSession('failed');

        ImageAuditSession::create(['user_id' => $this->admin->id, 'status' => 'failed']);

        $types = collect($this->actingAs($this->admin)
            ->getJson(route('super-admin.queues.status'))
            ->assertOk()
            ->json('sessions'))->pluck('type');

        $this->assertTrue($types->contains('ai-content'));
        $this->assertTrue($types->contains('image-audit'));
    }

    public function test_an_image_audit_can_be_resumed(): void
    {
        Queue::fake();

        $audit = ImageAuditSession::create(['user_id' => $this->admin->id, 'status' => 'failed']);

        $this->actingAs($this->admin)
            ->post(route('super-admin.queues.sessions.resume', ['type' => 'image-audit', 'id' => $audit->id]))
            ->assertRedirect()
            ->assertSessionHas('success');

        Queue::assertPushed(RunImageAuditJob::class);
    }

    /**
     * A store sync's SKU list is only ever passed to the job, never written to
     * the session, so there is nothing to resume from. Saying so is better than
     * a button that queues an empty run and reports success.
     */
    public function test_a_store_sync_says_why_it_cannot_be_resumed(): void
    {
        Queue::fake();

        $sync = StoreMigrationSession::create([
            'user_id' => $this->admin->id, 'from_store_id' => null, 'to_store_id' => null,
            'migration_type' => 'images_only', 'token' => 'tok-1', 'status' => 'failed',
        ]);

        $this->actingAs($this->admin)
            ->post(route('super-admin.queues.sessions.resume', ['type' => 'store-migration', 'id' => $sync->id]))
            ->assertRedirect()
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
    }

    /** Purging is irreversible, so a click alone must not do it. */
    public function test_purging_requires_the_queue_name_to_be_typed(): void
    {
        $this->actingAs($this->admin)
            ->post(route('super-admin.queues.purge'), ['queue' => 'bulkupload', 'confirm' => 'wrong'])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_purging_an_unknown_queue_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post(route('super-admin.queues.purge'), ['queue' => 'nonsense', 'confirm' => 'nonsense'])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    /**
     * The list is paged, but the count is a headline in its own right — capping
     * the list at fifty used to hide how bad a bad night had been.
     */
    public function test_failed_jobs_are_paged_and_report_the_true_total(): void
    {
        foreach (range(1, 30) as $i) {
            DB::table('failed_jobs')->insert([
                'uuid'       => "uuid-{$i}",
                'connection' => 'redis',
                'queue'      => 'bulkupload',
                'payload'    => json_encode(['displayName' => 'App\\Jobs\\GenerateAiContentJob']),
                'exception'  => 'Boom.',
                'failed_at'  => now()->subMinutes($i),
            ]);
        }

        $this->actingAs($this->admin)
            ->getJson(route('super-admin.queues.failed.page'))
            ->assertOk()
            ->assertJsonPath('total', 30)
            ->assertJsonPath('pages', 2)
            ->assertJsonCount(25, 'rows');

        $this->actingAs($this->admin)
            ->getJson(route('super-admin.queues.failed.page', ['page' => 2]))
            ->assertOk()
            ->assertJsonCount(5, 'rows');
    }

    public function test_the_full_trace_is_available_for_one_failure(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid'       => 'trace-me',
            'connection' => 'redis',
            'queue'      => 'bulkupload',
            'payload'    => json_encode(['displayName' => 'App\\Jobs\\GenerateAiContentJob']),
            'exception'  => "Timed out.\n#0 deep in the stack",
            'failed_at'  => now(),
        ]);

        $this->actingAs($this->admin)
            ->getJson(route('super-admin.queues.failed.detail', 'trace-me'))
            ->assertOk()
            ->assertJsonPath('exception', "Timed out.\n#0 deep in the stack");
    }
}
