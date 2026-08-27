<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One pass of one job through a worker. See the job_runs migration for why this
 * exists at all — Laravel records failures and pending work, never successes.
 */
class JobRun extends Model
{
    protected $fillable = [
        'job_uuid', 'name', 'queue', 'connection', 'status',
        'attempt', 'started_at', 'finished_at', 'duration_ms', 'exception',
    ];

    protected $casts = [
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * A job killed outright — a worker timeout, an OOM, a restarted container —
     * never gets the chance to write its own ending, so its row would sit on
     * "running" for ever and be counted as live work. Anything older than this
     * with no ending is treated as lost.
     */
    public const LOST_AFTER_HOURS = 6;

    public function scopeRunning($query)
    {
        return $query->where('status', 'running');
    }

    /** Human-readable duration; jobs here range from milliseconds to hours. */
    public function humanDuration(): string
    {
        if ($this->duration_ms === null) {
            return '—';
        }

        $seconds = $this->duration_ms / 1000;

        return match (true) {
            $seconds < 1    => $this->duration_ms . 'ms',
            $seconds < 60   => round($seconds, 1) . 's',
            $seconds < 3600 => intdiv((int) $seconds, 60) . 'm ' . ((int) $seconds % 60) . 's',
            default         => intdiv((int) $seconds, 3600) . 'h ' . intdiv((int) $seconds % 3600, 60) . 'm',
        };
    }
}
