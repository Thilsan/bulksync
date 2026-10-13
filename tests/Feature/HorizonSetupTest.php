<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Queues;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Horizon\ProvisioningPlan;
use Laravel\Horizon\SupervisorOptions;
use Tests\TestCase;

class HorizonSetupTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The supervisor options Horizon would start in $env. Resolved the way
     * ProvisioningPlan::deploy() does it — first key matching as a wildcard —
     * because optionsFor() only does exact lookups and so never sees '*'.
     */
    private function supervisor(string $env, string $supervisor): ?SupervisorOptions
    {
        $plan = collect(ProvisioningPlan::get('test-master')->parsed)
            ->first(fn ($_, $name) => Str::is($name, $env));

        return $plan[$supervisor] ?? null;
    }

    /*
     * The plan is matched against APP_ENV. A server whose APP_ENV matched no
     * key would start Horizon with no workers and report nothing, so the plan
     * has to apply whatever the environment is called.
     */
    public function test_every_environment_gets_both_supervisors(): void
    {
        foreach (['production', 'staging', 'local', 'anything'] as $env) {
            $this->assertNotNull($this->supervisor($env, 'main'), "{$env} has no main supervisor");
            $this->assertNotNull($this->supervisor($env, 'maintenance'), "{$env} has no maintenance supervisor");
        }
    }

    public function test_every_queue_a_job_is_sent_to_has_a_supervisor(): void
    {
        $listened = collect(['main', 'maintenance'])
            ->flatMap(fn ($s) => explode(',', $this->supervisor('production', $s)->queue))
            ->all();

        foreach (Queues::WORKERS as $queue) {
            $this->assertContains($queue, $listened, "jobs on {$queue} would sit on Pending");
        }
    }

    public function test_main_balances_automatically_and_is_capped_for_a_small_server(): void
    {
        $main = $this->supervisor('production', 'main');

        $this->assertSame('auto', $main->balance);
        // At least one process per queue, so a SKU check never waits behind an audit.
        $this->assertSame(1, $main->minProcesses);
        $this->assertLessThanOrEqual(6, $main->maxProcesses, 'the server has under 2GB of memory');
        // Long jobs declare their own $timeout; the fallback must stay under retry_after
        // or Redis hands a running job to a second worker.
        $this->assertLessThan(config('queue.connections.redis.retry_after'), $main->timeout);
    }

    public function test_maintenance_runs_on_exactly_one_process(): void
    {
        $maintenance = $this->supervisor('production', 'maintenance');

        $this->assertSame(Queues::MAINTENANCE, $maintenance->queue);
        $this->assertSame(1, $maintenance->maxProcesses);
    }

    public function test_only_super_admins_can_open_the_dashboard(): void
    {
        $admin = User::factory()->create(['is_active' => true, 'is_super_admin' => true]);
        $staff = User::factory()->create(['is_active' => true, 'is_super_admin' => false]);

        $this->assertTrue(Gate::forUser($admin)->allows('viewHorizon'));
        $this->assertFalse(Gate::forUser($staff)->allows('viewHorizon'));
        $this->assertFalse(Gate::allows('viewHorizon'));
    }
}
