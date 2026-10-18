<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One SKU folder within a run, with its own edit settings.
 *
 * The settings live here rather than on the session because a folder of
 * dresses and a folder of watches want different treatment, and a run
 * routinely holds both.
 */
class PhotoEditGroup extends Model
{
    /** Nobody needs forty on-model variations of one product, and each is billed. */
    public const MAX_LIFESTYLE = 6;

    /**
     * The views Edit With AI can be asked for, and the wording that asks.
     *
     * Every prompt insists on the same product, because that is the only thing
     * standing between a new angle and a new product: the pass regenerates the
     * whole picture, and on this account it has already reinvented a printed
     * monogram on a job that only had to remove a stand.
     */
    public const ANGLES = [
        'side'  => self::ANGLE_PROMPT_PREFIX . 'from the side' . self::ANGLE_PROMPT_SUFFIX,
        'back'  => self::ANGLE_PROMPT_PREFIX . 'from behind' . self::ANGLE_PROMPT_SUFFIX,
        'front' => self::ANGLE_PROMPT_PREFIX . 'from the front' . self::ANGLE_PROMPT_SUFFIX,
        'angle' => self::ANGLE_PROMPT_PREFIX . 'from a three-quarter angle' . self::ANGLE_PROMPT_SUFFIX,

        /*
         * The one view that wants two of them.
         *
         * Every other prompt here spends half its words insisting on a single
         * product, because a supplier photograph holding three views of one
         * shoe was being regenerated as three — or as a pair. This asks for
         * the pair on purpose, so it says two as plainly as the others say
         * one, and says which two: a left and a right rather than the same
         * shoe printed twice.
         */
        'pair'  => 'Show a matching pair of this exact product, a left shoe and a right shoe together, '
            . 'photographed from a three-quarter angle. Exactly two items in the frame and nothing else — '
            . 'no additional views, no sole or top-down view beside them. Same product, same colour, '
            . 'same materials and same markings on both, on a plain background',
    ];

    private const ANGLE_PROMPT_PREFIX = 'Show one single item of this exact product, photographed ';

    /*
     * How many products there should be, said out loud.
     *
     * The first wording named the view and left the count to the model. On a
     * supplier photograph holding three views of one shoe — the side, the top
     * and the sole in one frame — Edit With AI regenerated all three: the side
     * view came back with the top and the sole still floating beside it, and
     * "from behind" was read as a pair and drew two shoes.
     *
     * Which is the feature working as documented. It regenerates the whole
     * picture rather than rotating a product in it, so everything in frame is
     * in scope, and a frame with three objects in it produces three.
     */
    private const ANGLE_PROMPT_SUFFIX = '. Exactly one product in the frame and nothing else — '
        . 'no second shoe, no pair, no additional views, no sole or top-down view beside it. '
        . 'Same product, same colour, same materials and same markings, on a plain background';

    protected $fillable = [
        'photo_edit_session_id',
        'sku',
        'edits',
        'lifestyle_count',
        'lifestyle_source_item_id',
        'angles',
        'angle_source_item_id',
        'pendant_closeup',
        'case_height_cm',
        'redraw_refused',
    ];

    protected function casts(): array
    {
        return [
            'edits'           => 'array',
            'lifestyle_count' => 'integer',
            'angles'          => 'array',
            'pendant_closeup' => 'boolean',
            'case_height_cm'  => 'integer',
            'redraw_refused'  => 'boolean',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PhotoEditSession::class, 'photo_edit_session_id');
    }

    /** Every photo in this SKU folder, generated ones included. */
    public function items(): HasMany
    {
        return $this->hasMany(PhotoEditItem::class, 'photo_edit_session_id', 'photo_edit_session_id')
            ->where('sku_detected', $this->sku);
    }

    /** The real photographs, which are what a lifestyle shot can be built from. */
    public function sourceItems()
    {
        return PhotoEditItem::where('photo_edit_session_id', $this->photo_edit_session_id)
            ->where('sku_detected', $this->sku)
            ->where('kind', 'cutout')
            ->orderBy('filename');
    }

    /**
     * Photoroom calls this group will spend: one per photo, plus one per
     * on-model image asked for.
     */
    public function creditCost(): int
    {
        // Photos marked "as is" never reach Photoroom, so they never cost a
        // credit — the whole reason for marking them.
        return $this->sourceItems()->where('skip_edit', false)->count() + $this->lifestyle_count;
    }

    /**
     * On-model generation needs a photo to dress the model from, and it has to
     * be one that actually exists in this group — a stale pick from a previous
     * configuration would silently generate from the wrong garment.
     */
    public function lifestyleSourceIsValid(): bool
    {
        if ($this->lifestyle_count < 1) {
            return true;
        }

        return $this->lifestyle_source_item_id !== null
            && $this->sourceItems()->whereKey($this->lifestyle_source_item_id)->exists();
    }
}
