<?php

namespace App\Support;

use App\Jobs\EditPhotoItemJob;
use App\Jobs\GenerateAiContentJob;
use App\Jobs\ProcessUploadItemJob;
use App\Jobs\RunCsvCompareJob;
use App\Jobs\RunImageAuditJob;
use App\Jobs\RunSkuCheckJob;
use App\Models\AiContentSession;
use App\Models\ImageAuditSession;
use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\SkuCheckSession;
use App\Models\StoreMigrationSession;
use App\Models\UploadItem;
use App\Models\UploadSession;
use RuntimeException;

/**
 * Picking up long-running work that stopped short, whatever kind it was.
 *
 * Six different things in this app run as a session over a queue, and any of
 * them strands the same way — a worker timeout, a restart mid-deploy, an API
 * that ran out of credit. Each has its own idea of what "carry on" means, so the
 * knowledge of how to restart one lives here rather than being reinvented per
 * controller: some re-dispatch a single session job, some re-queue only the
 * items that never finished, and one cannot be resumed at all.
 */
class SessionResumer
{
    /** Statuses that mean the work is not finished and may be worth picking up. */
    public const UNFINISHED = ['pending', 'processing', 'scanning', 'editing', 'failed'];

    /**
     * @return array<string, array{label: string, model: class-string, blocked?: string}>
     */
    public static function types(): array
    {
        return [
            'ai-content' => ['label' => 'AI content', 'model' => AiContentSession::class],
            'upload'     => ['label' => 'Image upload', 'model' => UploadSession::class],
            'photo-edit' => ['label' => 'Photo editor', 'model' => PhotoEditSession::class],
            'image-audit' => ['label' => 'Image audit', 'model' => ImageAuditSession::class],
            'sku-check'  => ['label' => 'SKU check', 'model' => SkuCheckSession::class],

            // The SKU list is passed to the job and never written to the session,
            // so there is nothing left to resume from once the job is gone. An
            // honest "no" beats a button that queues an empty run.
            'store-migration' => [
                'label'   => 'Store image sync',
                'model'   => StoreMigrationSession::class,
                'blocked' => 'The SKU list for a sync is only passed to the job, never stored on the session, so there is nothing to resume from. Start a new sync instead.',
            ],
        ];
    }

    /**
     * Every session across every type that stopped before finishing, newest
     * first, in one shape the dashboard can render.
     */
    public static function stalled(int $perType = 10): array
    {
        $rows = [];

        foreach (self::types() as $key => $type) {
            /** @var class-string<\Illuminate\Database\Eloquent\Model> $model */
            $model = $type['model'];

            foreach ($model::whereIn('status', self::UNFINISHED)->latest()->limit($perType)->get() as $session) {
                $rows[] = [
                    'type'      => $key,
                    'label'     => $type['label'],
                    'id'        => $session->id,
                    'status'    => $session->status,
                    'progress'  => self::progress($session),
                    'error'     => \Illuminate\Support\Str::limit((string) ($session->error_message ?? ''), 140),
                    'blocked'   => $type['blocked'] ?? null,
                    'updated'   => optional($session->updated_at)->diffForHumans(),
                    'timestamp' => optional($session->updated_at)->timestamp ?? 0,
                ];
            }
        }

        usort($rows, fn ($a, $b) => $b['timestamp'] <=> $a['timestamp']);

        return $rows;
    }

    /** "68/116" where the model counts its work, blank where it does not. */
    private static function progress($session): string
    {
        $pairs = [
            ['processed_items', 'total_items'],
            ['uploaded_files', 'total_files'],
            ['edited_files', 'total_files'],
            ['scanned_products', 'total_products'],
            ['scanned_skus', 'total_skus'],
        ];

        foreach ($pairs as [$done, $total]) {
            if (isset($session->{$total}) && $session->{$total} > 0) {
                return ($session->{$done} ?? 0) . '/' . $session->{$total};
            }
        }

        return '';
    }

    /**
     * Queue the work that is left for one session.
     *
     * @return string What was queued, for the operator to read back.
     * @throws RuntimeException When this kind of session cannot be resumed.
     */
    public static function resume(string $type, int $id): string
    {
        $types = self::types();

        if (!isset($types[$type])) {
            throw new RuntimeException('Unknown session type.');
        }

        if ($blocked = $types[$type]['blocked'] ?? null) {
            throw new RuntimeException($blocked);
        }

        $model   = $types[$type]['model'];
        $session = $model::find($id);

        if (!$session) {
            throw new RuntimeException('That session no longer exists.');
        }

        return match ($type) {
            'ai-content'  => self::resumeAiContent($session),
            'upload'      => self::resumeUpload($session),
            'photo-edit'  => self::resumePhotoEdit($session),
            'image-audit' => self::resumeImageAudit($session),
            'sku-check'   => self::resumeSkuCheck($session),
        };
    }

    /** The job knows how to skip what it already generated, so one dispatch is enough. */
    private static function resumeAiContent(AiContentSession $session): string
    {
        GenerateAiContentJob::dispatch($session->id)->onQueue('bulkupload');

        return "AI content session #{$session->id} queued. Finished SKUs are skipped.";
    }

    /**
     * Only the files that never reached a terminal state. ProcessUploadItemJob
     * already refuses an item that finished, so this cannot re-upload anything;
     * failed files are left alone, since a failure is an outcome rather than
     * unfinished work.
     */
    private static function resumeUpload(UploadSession $session): string
    {
        $items = UploadItem::where('upload_session_id', $session->id)
            ->whereIn('status', ['pending', 'processing', 'matched'])
            ->pluck('id');

        foreach ($items as $itemId) {
            ProcessUploadItemJob::dispatch($itemId)->onQueue('bulkupload');
        }

        $session->update(['status' => 'processing']);

        return "Upload session #{$session->id}: re-queued {$items->count()} unfinished file(s).";
    }

    /**
     * EditPhotoItemJob refuses anything already 'edited'/'pushed'/'skipped', so
     * only genuinely unfinished photos are sent. An item stuck on 'editing' is
     * one a dead worker was holding, and needs resetting before the job will
     * take it.
     */
    private static function resumePhotoEdit(PhotoEditSession $session): string
    {
        PhotoEditItem::where('photo_edit_session_id', $session->id)
            ->where('status', 'editing')
            ->update(['status' => 'pending', 'error_message' => null]);

        $items = PhotoEditItem::where('photo_edit_session_id', $session->id)
            ->where('status', 'pending')
            ->pluck('id');

        foreach ($items as $itemId) {
            EditPhotoItemJob::dispatch($itemId)->onQueue('bulkupload');
        }

        $session->update(['status' => 'processing']);

        return "Photo session #{$session->id}: re-queued {$items->count()} unfinished photo(s).";
    }

    /** An audit only reads, so running it again from the top costs nothing but time. */
    private static function resumeImageAudit(ImageAuditSession $session): string
    {
        RunImageAuditJob::dispatch($session->id)->onQueue('bulkupload');

        return "Image audit #{$session->id} queued again.";
    }

    /**
     * Two different jobs write this one model. The uploaded CSV beside the
     * session is what tells them apart — and it is pruned after 30 days, so an
     * old comparison genuinely cannot be re-run.
     */
    private static function resumeSkuCheck(SkuCheckSession $session): string
    {
        $csv = storage_path("app/sku-checks/shopify_{$session->id}.csv");

        if (is_file($csv)) {
            RunCsvCompareJob::dispatch($session->id)->onQueue('bulkupload');

            return "CSV comparison #{$session->id} queued again.";
        }

        if (blank($session->raw_skus)) {
            throw new RuntimeException('This check has neither its SKU list nor its uploaded CSV left, so there is nothing to run again.');
        }

        RunSkuCheckJob::dispatch($session->id)->onQueue('bulkupload');

        return "SKU check #{$session->id} queued again.";
    }
}
