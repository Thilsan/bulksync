<?php

namespace App\Support;

use App\Jobs\CopyOriginalPhotoJob;
use App\Jobs\EditPhotoItemJob;
use App\Jobs\GenerateAngleImageJob;
use App\Jobs\GenerateLifestyleImageJob;
use App\Models\PhotoEditGroup;
use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use Illuminate\Database\Eloquent\Builder;

/**
 * One lane per person, so photo runs take turns on the shared queue.
 *
 * The queue is first-come. When a whole run went in at once, the next
 * person's photos landed behind every one of them and waited for the lot to
 * finish. Instead, each person's photos wait here (queued_at set, not yet
 * dispatched) and only a few of them (services.photoroom.lane_window) are in
 * the queue at any moment. Each finished job sends that person's next photo.
 * With two people editing, the queue holds a few of each, so workers take
 * them in turn.
 *
 * Nothing extra runs to do this: no worker per person, no new queue. One
 * person working alone still keeps the workers busy, because their next photo
 * goes in the moment one finishes.
 *
 * The scheduled photo-lane:feed tops every lane up once a minute. That is the
 * safety net for a job whose finish never called back here (a worker killed
 * mid-photo); it never sends a photo that is already in the queue.
 */
final class PhotoLane
{
    /** Statuses that mean a dispatched photo is still holding a place. */
    private const IN_FLIGHT = ['pending', 'editing'];

    /**
     * A photo sent this long ago and still unfinished stops holding a place.
     *
     * Its job was lost (Redis cleared, a worker killed after its last try).
     * Without this, enough lost jobs would block that person's lane for good.
     * It is not sent again — that is the Resume button's call, since a job
     * that is merely slow would then run twice and be charged twice.
     */
    private const STALE_AFTER_MINUTES = 120;

    public static function window(): int
    {
        return max(1, (int) config('services.photoroom.lane_window', 3));
    }

    /**
     * Put photos in their owners' lanes and send whatever fits.
     *
     * Also how a photo goes round again (re-edit, resume): clearing
     * dispatched_at puts it back at the end of its owner's lane.
     *
     * @param iterable<int> $itemIds
     */
    public static function enqueue(iterable $itemIds): void
    {
        $ids = collect($itemIds)->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return;
        }

        $ids->chunk(500)->each(fn ($chunk) => PhotoEditItem::whereIn('id', $chunk)
            ->update(['queued_at' => now(), 'dispatched_at' => null]));

        $sessionIds = PhotoEditItem::whereIn('id', $ids)->distinct()->pluck('photo_edit_session_id');

        PhotoEditSession::whereIn('id', $sessionIds)
            ->pluck('user_id')
            ->unique()
            ->each(fn ($userId) => self::feed($userId));
    }

    /** Called when a photo's job finishes, whichever way: send its owner's next one. */
    public static function finished(int $itemId): void
    {
        $sessionId = PhotoEditItem::whereKey($itemId)->value('photo_edit_session_id');

        if ($sessionId === null) {
            return;
        }

        self::feed(PhotoEditSession::whereKey($sessionId)->value('user_id'));
    }

    /** Top up every lane that has photos waiting. */
    public static function feedAll(): int
    {
        $users = PhotoEditSession::query()
            ->whereIn('id', self::waiting()->select('photo_edit_session_id'))
            ->distinct()
            ->pluck('user_id');

        $users->each(fn ($userId) => self::feed($userId));

        return $users->count();
    }

    /**
     * Send this person's next photos until they have a full window in the queue.
     *
     * Two feeds for the same person can overlap (two jobs finishing together).
     * Each photo is claimed with a conditional update before it is sent, so
     * neither can send the same photo twice; at worst the window is briefly
     * one over.
     */
    public static function feed(?int $userId): void
    {
        $free = self::window() - self::ownedBy(PhotoEditItem::query(), $userId)
            ->where('dispatched_at', '>', now()->subMinutes(self::STALE_AFTER_MINUTES))
            ->whereIn('status', self::IN_FLIGHT)
            ->count();

        if ($free <= 0) {
            return;
        }

        $next = self::ownedBy(self::waiting(), $userId)
            ->orderBy('queued_at')
            ->orderBy('id')
            ->limit($free)
            ->get(['id', 'kind', 'skip_edit', 'lane_variant', 'filename']);

        foreach ($next as $item) {
            $claimed = PhotoEditItem::whereKey($item->id)
                ->whereNull('dispatched_at')
                ->where('status', 'pending')
                ->update(['dispatched_at' => now()]);

            if ($claimed) {
                self::dispatch($item);
            }
        }
    }

    /** The job that makes this kind of photo. */
    private static function dispatch(PhotoEditItem $item): void
    {
        $job = match ($item->kind) {
            'lifestyle' => new GenerateLifestyleImageJob($item->id, (int) ($item->lane_variant ?? self::variantFromName($item))),
            'angle'     => new GenerateAngleImageJob($item->id, (string) ($item->lane_variant ?? self::variantFromName($item))),
            default     => $item->skip_edit
                ? new CopyOriginalPhotoJob($item->id)
                : new EditPhotoItemJob($item->id),
        };

        dispatch($job->onQueue(Queues::PHOTOS));
    }

    /**
     * A row made before lane_variant existed (one being resumed) still names
     * its variant in the filename queueRun gave it: "x-lifestyle-2.jpg" is
     * index 1, "x-back.jpg" is the back view.
     */
    private static function variantFromName(PhotoEditItem $item): string
    {
        $name = pathinfo((string) $item->filename, PATHINFO_FILENAME);

        if ($item->kind === 'lifestyle') {
            return preg_match('/-lifestyle-(\d+)$/', $name, $m) ? (string) max(0, (int) $m[1] - 1) : '0';
        }

        foreach (array_keys(PhotoEditGroup::ANGLES) as $angle) {
            if (str_ends_with($name, "-{$angle}")) {
                return $angle;
            }
        }

        return '';
    }

    /** Photos asked for and not yet sent. */
    private static function waiting(): Builder
    {
        return PhotoEditItem::query()
            ->whereNotNull('queued_at')
            ->whereNull('dispatched_at')
            ->where('status', 'pending');
    }

    private static function ownedBy(Builder $query, ?int $userId): Builder
    {
        $sessions = PhotoEditSession::query()->select('id');

        $userId === null ? $sessions->whereNull('user_id') : $sessions->where('user_id', $userId);

        return $query->whereIn('photo_edit_session_id', $sessions);
    }
}
