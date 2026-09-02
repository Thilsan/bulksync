<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhotoEditItem extends Model
{
    protected $fillable = [
        'photo_edit_session_id',
        'filename',
        'kind',
        'source_item_id',
        'sku_detected',
        'position',
        'skip_edit',
        'keep_background',
        'onedrive_drive_id',
        'onedrive_item_id',
        'onedrive_download_url',
        'original_thumb_path',
        'edited_thumb_path',
        'edited_path',
        'original_size_kb',
        'edited_size_kb',
        'status',
        'view_type',
        'mannequin_visible',
        'apparel_mode_applied',
        'uncertainty_score',
        'selected',
        'product_id',
        'product_title',
        'variant_id',
        'variant_sku',
        'shopify_image_id',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'position'          => 'integer',
            'skip_edit'         => 'boolean',
            'keep_background'   => 'boolean',
            'selected'          => 'boolean',
            'mannequin_visible' => 'boolean',
            'uncertainty_score' => 'float',
        ];
    }

    /**
     * The order the operator put these photos in.
     *
     * Filename is the tiebreak rather than id, so a run that has never been
     * reordered comes out exactly as it did before this column existed — and
     * two photos left on the same position stay in a stable, guessable order
     * instead of whichever the database felt like.
     */
    public function scopeInDisplayOrder($query)
    {
        return $query->orderBy('position')->orderBy('filename');
    }

    /**
     * Cutouts Photoroom itself was unsure about, which is where a reviewer's
     * attention is worth spending. The threshold is deliberately loose: a
     * missed bad cutout costs a re-run, a needless flag costs a glance.
     */
    public const UNCERTAIN_ABOVE = 0.3;

    public function looksUncertain(): bool
    {
        return $this->uncertainty_score !== null
            && $this->uncertainty_score > self::UNCERTAIN_ABOVE;
    }

    public function scopeUncertain($query)
    {
        return $query->where('uncertainty_score', '>', self::UNCERTAIN_ABOVE);
    }

    /**
     * The settings that apply to this photo.
     *
     * Its SKU group owns them — a run mixes product types and each folder was
     * configured separately. The session's own edits are only a fallback, for
     * rows created before groups existed and for a group that somehow went
     * missing; without that fallback an old session would edit with nothing set
     * at all, which silently produces a very different picture.
     */
    public function resolvedEdits(): array
    {
        $group = PhotoEditGroup::where('photo_edit_session_id', $this->photo_edit_session_id)
            ->where('sku', $this->sku_detected)
            ->first();

        return $group?->edits ?? $this->session?->edits ?? [];
    }

    public function isLifestyle(): bool
    {
        return $this->kind === 'lifestyle';
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PhotoEditSession::class, 'photo_edit_session_id');
    }

    /** Only an item that edited cleanly and still has its file can be pushed. */
    /**
     * Can this image be sent to Shopify right now?
     *
     * An image already on Shopify counts, for as long as its full-size file is
     * still on disk. Pushing to the wrong product, or spotting something after
     * the fact, used to mean re-running the whole edit and paying for it again.
     *
     * The file is what limits it, not the status: once the sweep has taken the
     * bytes there is nothing left to send, and the answer becomes no again.
     */
    public function isPushable(): bool
    {
        /*
         * Everything except work still in flight.
         *
         * 'skipped' and 'failed' are here because their reasons are external
         * and fixable: no Shopify product for that SKU, an identifier on two
         * products at once, a refusal from Shopify. Somebody creates the
         * product or corrects the barcode, and the same image should go
         * straight up — the old behaviour left it stranded, and re-running the
         * whole edit to un-strand it cost a credit to produce the same file.
         *
         * 'pushed' is here so an image can be sent again, which replaces what
         * is on Shopify rather than adding to it.
         *
         * The file is the real limit, not the status. An edit that failed never
         * wrote one, and once the sweep has taken the bytes there is nothing
         * left to send — both are caught by the same check.
         */
        return in_array($this->status, ['edited', 'pushed', 'skipped', 'failed'], true)
            && $this->edited_path !== null;
    }

    /** Already on Shopify — so sending it again replaces what is there. */
    public function isRepush(): bool
    {
        return $this->status === 'pushed' && $this->shopify_image_id !== null;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'editing' => 'Editing',
            'edited'  => 'Ready',
            'pushing' => 'Pushing',
            'pushed'  => 'On Shopify',
            'failed'  => 'Failed',
            'skipped' => 'No Match',
            default   => 'Waiting',
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'edited'  => 'emerald',
            'pushed'  => 'brand',
            'editing', 'pushing' => 'indigo',
            'failed'  => 'red',
            'skipped' => 'amber',
            default   => 'gray',
        };
    }

    /**
     * Drop the full-size edit but keep the thumbnails.
     *
     * Called once Shopify has the image: at that point our copy is a duplicate
     * of bytes that already live somewhere permanent, and it is by far the
     * largest thing this feature writes. The thumbnails stay so the session
     * still shows what was done.
     */
    public function discardFullSize(): void
    {
        if (!$this->edited_path) {
            return;
        }

        $path = storage_path('app/' . $this->edited_path);

        if (is_file($path)) {
            @unlink($path);
        }

        $this->update(['edited_path' => null]);
    }
}
