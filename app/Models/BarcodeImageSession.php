<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One run of "here are some barcodes and a website — fetch me the pictures".
 *
 * The files land under storage/app/barcode-images/{id}/{barcode}/, one folder
 * per barcode, which is the shape the ZIP is built from and the shape the
 * people asking for this work in.
 */
class BarcodeImageSession extends Model
{
    /** Everything this module writes lives under here, relative to storage/app. */
    public const STORAGE_ROOT = 'barcode-images';

    protected $fillable = [
        'user_id',
        'name',
        'site_url',
        'status',
        'total_barcodes',
        'processed',
        'found_count',
        'missing_count',
        'images_downloaded',
        'raw_barcodes',
        'error_message',
        'push_status',
        'push_store_id',
        'push_matching_mode',
        'push_total',
        'push_done',
        'push_pushed',
        'push_failed',
        'push_error',
    ];

    /** How a barcode is matched to a product when the images are pushed. */
    public const MATCHING_MODES = [
        'sku_barcode' => 'SKU / Barcode',
        'style_code'  => 'Style Code',
    ];

    public function progressPercent(): int
    {
        if ((int) $this->total_barcodes === 0) return 0;

        return (int) min(100, round($this->processed / $this->total_barcodes * 100));
    }

    /** storage/app/barcode-images/{id} — where this run's folders live. */
    public function directory(): string
    {
        return storage_path('app/' . self::STORAGE_ROOT . '/' . $this->id);
    }

    /** The folder for one barcode, named after the barcode itself. */
    public function folderFor(string $barcode): string
    {
        return $this->directory() . '/' . self::safeFolder($barcode);
    }

    /**
     * A barcode is somebody's typing, and it becomes a directory name and a ZIP
     * entry — so anything that could climb out of the run's own folder goes.
     */
    public static function safeFolder(string $barcode): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($barcode)) ?? '';
        $clean = trim($clean, '.-');

        return $clean === '' ? 'unnamed' : mb_substr($clean, 0, 120);
    }

    /** Bytes currently on disk for this run. */
    public function bytes(): int
    {
        return self::directoryBytes($this->directory());
    }

    public static function directoryBytes(string $dir): int
    {
        if (!is_dir($dir)) return 0;

        $total = 0;

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        ) as $file) {
            if ($file->isFile()) {
                $total += (int) $file->getSize();
            }
        }

        return $total;
    }

    /** Removes this run's files and returns how many bytes that freed. */
    public function deleteFiles(): int
    {
        return self::deleteDirectory($this->directory());
    }

    public static function deleteDirectory(string $dir): int
    {
        if (!is_dir($dir)) return 0;

        $freed = 0;

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            if ($entry->isFile()) {
                $freed += (int) $entry->getSize();
                @unlink($entry->getPathname());
            } else {
                @rmdir($entry->getPathname());
            }
        }

        @rmdir($dir);

        return $freed;
    }

    public function pushProgressPercent(): int
    {
        if ((int) $this->push_total === 0) return 0;

        return (int) min(100, round($this->push_done / $this->push_total * 100));
    }

    /** A push that is queued or running — the screen polls while this is true. */
    public function isPushing(): bool
    {
        return in_array($this->push_status, ['pending', 'pushing'], true);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pushStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'push_store_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BarcodeImageItem::class);
    }
}
