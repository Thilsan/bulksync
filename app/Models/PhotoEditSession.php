<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PhotoEditSession extends Model
{
    /** Everything this feature writes lives under one directory, so it can be swept as one. */
    public const STORAGE_ROOT = 'photo-editor';

    protected $fillable = [
        'user_id',
        'store_id',
        'name',
        'onedrive_link',
        'matching_mode',
        'edits',
        'status',
        'scan_status',
        'total_files',
        'scanned_files',
        'edited_files',
        'failed_files',
        'pushed_files',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'edits' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PhotoEditItem::class);
    }

    // ── Progress ───────────────────────────────────────────────────────────

    public function progressPercent(): int
    {
        if ($this->total_files === 0) {
            return 0;
        }

        $done = $this->edited_files + $this->failed_files;

        return (int) min(100, round(($done / $this->total_files) * 100));
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['completed', 'failed'], true);
    }

    // ── Storage ────────────────────────────────────────────────────────────

    /** Path relative to storage/app. */
    public function storageDir(): string
    {
        return self::STORAGE_ROOT . '/' . $this->id;
    }

    public function absoluteStorageDir(): string
    {
        return storage_path('app/' . $this->storageDir());
    }

    /**
     * Remove every file this session wrote. Called when the session is deleted
     * and by the nightly sweep — the disk has filled twice on this server, so
     * nothing here is left to be tidied up by hand.
     */
    public function deleteFiles(): int
    {
        return self::deleteDirectory($this->absoluteStorageDir());
    }

    /** @return int bytes freed */
    public static function deleteDirectory(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $freed = 0;

        foreach (glob("{$dir}/*") ?: [] as $file) {
            if (is_file($file)) {
                $freed += (int) filesize($file);
                @unlink($file);
            }
        }

        @rmdir($dir);

        return $freed;
    }

    /** Total bytes this feature is holding on disk right now. */
    public static function totalBytes(): int
    {
        $root  = storage_path('app/' . self::STORAGE_ROOT);
        $total = 0;

        if (!is_dir($root)) {
            return 0;
        }

        foreach (glob("{$root}/*", GLOB_ONLYDIR) ?: [] as $dir) {
            foreach (glob("{$dir}/*") ?: [] as $file) {
                if (is_file($file)) {
                    $total += (int) filesize($file);
                }
            }
        }

        return $total;
    }

    // ── Display ────────────────────────────────────────────────────────────

    /**
     * A short human summary of what this session was told to do, so the history
     * list says "Background removed, mannequin removed, 1000 × 1000" rather
     * than making people open each run to find out.
     */
    public function editSummary(): string
    {
        $edits = $this->edits ?? [];
        $parts = [];

        // Straightening leads because it happened first, and because it is the
        // setting people go hunting for when a result came out on its side.
        $parts[] = match ($edits['input_rotation'] ?? null) {
            'right' => 'Turned right' . (!empty($edits['rotate_wide_only']) ? ' (wide only)' : ''),
            'left'  => 'Turned left'  . (!empty($edits['rotate_wide_only']) ? ' (wide only)' : ''),
            '180'   => 'Turned 180°',
            default => null,
        };

        $trimmed = (float) ($edits['trim_top'] ?? 0) + (float) ($edits['trim_bottom'] ?? 0);

        if ($trimmed > 0) {
            $parts[] = 'Trimmed ' . round($trimmed * 100) . '%';
        }

        $parts[] = match ($edits['background_mode'] ?? null) {
            'blur'   => 'Background blurred',
            'prompt' => 'AI background',
            'image'  => 'Custom background image',
            default  => !empty($edits['remove_background'])
                ? (empty($edits['background_color'])
                    ? 'Background removed (transparent)'
                    : 'Background → #' . ltrim((string) $edits['background_color'], '#'))
                : null,
        };

        if (!empty($edits['ghost_mannequin'])) $parts[] = 'Mannequin removed';
        if (!empty($edits['flat_lay']))        $parts[] = 'Flat lay';
        if (!empty($edits['virtual_model']))   $parts[] = 'Virtual model';
        if (!empty($edits['ironing']))         $parts[] = 'Ironed';
        if (!empty($edits['shadow']))          $parts[] = 'Shadow';
        if (!empty($edits['lighting']))        $parts[] = 'Relit';
        if (!empty($edits['upscale']))         $parts[] = 'Upscaled';
        if (!empty($edits['beautify']))        $parts[] = 'Beautified';
        if (!empty($edits['expand']))          $parts[] = 'Expanded';
        if (!empty($edits['uncrop']))          $parts[] = 'Uncropped';
        if (!empty($edits['text_removal']))    $parts[] = 'Text removed';
        if (!empty($edits['outline_color']))   $parts[] = 'Outlined';

        // Named rather than described. "Women's dresses" says the run was held
        // to a standard, where "2048 x 2048 - 6%" reads as somebody's guess on
        // the day, which is the thing this is meant to replace.
        if ($framing = \App\Services\PhotoroomService::framingPresetLabel($edits['framing_preset'] ?? null)) {
            $parts[] = $framing . ' framing';
        }

        // A generated canvas sets its own dimensions, so quoting a pixel size
        // next to it would describe something that never happened.
        if (!empty($edits['apparel_size'])) {
            $parts[] = str_replace('_', ' ', $edits['apparel_size']);
        } elseif (!empty($edits['width']) && !empty($edits['height'])) {
            $parts[] = $edits['width'] . ' × ' . $edits['height'];
        }

        return array_filter($parts) ? implode(' · ', array_filter($parts)) : 'No edits';
    }
}
