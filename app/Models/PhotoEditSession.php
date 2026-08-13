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

        if (!empty($edits['remove_background'])) {
            $parts[] = empty($edits['background_color'])
                ? 'Background removed (transparent)'
                : 'Background → #' . ltrim((string) $edits['background_color'], '#');
        }

        if (!empty($edits['ghost_mannequin'])) $parts[] = 'Mannequin removed';
        if (!empty($edits['flat_lay']))        $parts[] = 'Flat lay';
        if (!empty($edits['shadow']))          $parts[] = 'Shadow';
        if (!empty($edits['lighting']))        $parts[] = 'Relit';
        if (!empty($edits['upscale']))         $parts[] = 'Upscaled';
        if (!empty($edits['text_removal']))    $parts[] = 'Text removed';

        if (!empty($edits['width']) && !empty($edits['height'])) {
            $parts[] = $edits['width'] . ' × ' . $edits['height'];
        }

        return $parts ? implode(' · ', $parts) : 'No edits';
    }
}
