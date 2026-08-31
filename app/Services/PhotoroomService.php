<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Photoroom's Image Editing API (v2/edit).
 *
 * The endpoint takes multipart form fields and answers with raw image bytes,
 * not JSON, so there is nothing to unwrap on success — the body IS the result.
 *
 * Every option the form offers is translated in buildFields(). Keeping that in
 * one place is what stops the form, the queue job and the API drifting apart on
 * what "remove the background and centre it" actually means.
 */
class PhotoroomService
{
    private const ENDPOINT = 'https://image-api.photoroom.com/v2/edit';

    /**
     * Shadow overrides only exist on the newer shadow model, which has to be
     * asked for by header — without it the override fields are ignored in
     * silence and the result looks like the sliders did nothing.
     */
    private const SHADOW_MODEL_VERSION = '2026-04-15';

    /**
     * Photoroom refuses anything above these, so callers shrink first rather
     * than spending a request to be told no.
     */
    public const MAX_INPUT_BYTES = 29_000_000; // API limit is 30 MB
    public const MAX_INPUT_EDGE  = 5000;       // widest side, in pixels

    /**
     * Longest a single throttled request is worth waiting out. Photoroom's
     * per-minute window clears inside a minute; anything longer is a quota
     * that has run out — a sandbox key's 100 calls a day answers with four
     * hours — and no amount of sleeping inside one job will see the end of it.
     */
    private const MAX_THROTTLE_WAIT = 75;

    /**
     * Total seconds one job may spend waiting out throttles across all its
     * attempts. The queue worker kills a job at its own timeout without
     * running failed(), so the waiting has to stop before that does.
     */
    private const MAX_WAIT_BUDGET = 120;

    /** Canvas presets shared by ghost mannequin, flat lay and virtual model. */
    public const SIZE_PRESETS = [
        'SQUARE_HD'          => 'Square',
        'PORTRAIT_HD_4_3'    => 'Portrait 4:3',
        'PORTRAIT_HD_3_2'    => 'Portrait 3:2',
        'PORTRAIT_HD_16_9'   => 'Portrait 16:9',
        'LANDSCAPE_HD_4_3'   => 'Landscape 4:3',
        'LANDSCAPE_HD_3_2'   => 'Landscape 3:2',
        'LANDSCAPE_HD_16_9'  => 'Landscape 16:9',
    ];

    public const VIRTUAL_MODEL_PRESETS = [
        'avery', 'sam', 'taylor', 'kendall', 'jordan', 'casey', 'maya', 'reece',
        'lena', 'julia', 'jackson', 'sophia', 'emma', 'ava', 'zoe', 'fiona',
    ];

    public const VIRTUAL_MODEL_SCENES = [
        'random', 'street', 'bedroom', 'sunset', 'factory', 'studio', 'coloredstudio',
        'concretestudio', 'beach', 'tropical', 'library', 'forest', 'businessdistrict',
        'countryside', 'flowers', 'goldenlight', 'mountain', 'pool', 'latincity',
        'cafe', 'asiancity', 'nightlights', 'desert',
    ];

    public const VIRTUAL_MODEL_POSES = [
        'random', 'standing', '34turn', 'powerstance', 'walkingforward', 'handinpocket',
        'crossedarms', 'back', 'overtheshoulder', 'seated', 'adjustingclothing', 'playfulspin',
    ];

    public const SHADOW_MODES = [
        ''                       => 'None',
        'ai.soft'                => 'Soft',
        'ai.hard'                => 'Hard',
        'ai.floating'            => 'Floating',
        'ai.auto-with-overrides' => 'Custom',
    ];

    public const SHADOW_SPREADS    = ['short', 'medium', 'long'];
    public const SHADOW_DIRECTIONS = ['behind', 'behindLeft', 'left', 'frontLeft', 'front', 'frontRight', 'right', 'behindRight'];
    public const SHADOW_POSES      = ['flatlay', 'upright'];

    public const BEAUTIFY_MODES = [
        ''         => 'Off',
        'ai.auto'  => 'General',
        'ai.food'  => 'Food',
        'ai.car'   => 'Vehicles',
    ];

    public const TEXT_REMOVAL_MODES = [
        ''              => 'Keep all text',
        'ai.artificial' => 'Added graphics only',
        'ai.natural'    => 'Printed on the product',
        'ai.all'        => 'Everything',
    ];

    public const BACKGROUND_MODES = ['transparent', 'white', 'custom', 'prompt', 'image', 'blur'];

    /**
     * Relighting modes.
     *
     * 'ai.auto' is free to shift hue and saturation to make the light look
     * right, which on a garment means the colour on the product page stops
     * matching the colour in the box. The preserve mode is Photoroom's own
     * recommendation for product photography and is the default here for
     * that reason.
     */
    public const LIGHTING_MODES = [
        ''                              => 'Off',
        'ai.preserve-hue-and-saturation' => 'Relight, keep original colours',
        'ai.auto'                        => 'Relight, best-looking light',
        'ai.optimize-portrait'           => 'Relight for portraits',
    ];

    /**
     * JPEG and PNG are what Shopify has always taken. WebP is materially
     * smaller at the same quality, which matters when the compressor is
     * otherwise fighting to get a 20 MP cutout under Shopify's limit.
     */
    public const EXPORT_FORMATS = ['auto', 'jpg', 'png', 'webp', 'avif'];

    /** Formats that carry an alpha channel, and so may hold a cutout. */
    public const ALPHA_FORMATS = ['png', 'webp', 'avif'];

    /** How the canvas is sized when no explicit width and height are given. */
    public const OUTPUT_SIZE_MODES = [
        'auto'           => 'Let Photoroom decide',
        'originalImage'  => 'Same as the original',
        'croppedSubject' => 'Crop tight to the product',
        'custom'         => 'Exact pixel size',
    ];

    /*
     * ── Category framing ───────────────────────────────────────────────────
     *
     * A category's photos come from different shoots, cropped differently, and
     * a collection page shows them side by side — so what makes a page read as
     * one brand is the product landing in the same place on every canvas.
     *
     * Photoroom measures padding and alignment from the subject's own bounding
     * box, so pinning the canvas, the padding, the alignment and the scaling
     * pins the position: the garment is scaled to the same height and set down
     * on the same spot whatever the photograph around it was doing.
     */

    /**
     * Every field a framing preset governs, and what that field means when the
     * preset does not name it.
     *
     * Applying a preset writes all of them rather than only the ones it names.
     * A padding_top left behind by a previous choice is exactly the kind of
     * leftover that puts one photo of the twelve half a centimetre off.
     */
    public const FRAMING_FIELDS = [
        'width'              => null,
        'height'             => null,
        'max_width'          => null,
        'max_height'         => null,
        'padding'            => null,
        'padding_top'        => null,
        'padding_bottom'     => null,
        'padding_left'       => null,
        'padding_right'      => null,
        'margin'             => null,
        'margin_top'         => null,
        'margin_bottom'      => null,
        'margin_left'        => null,
        'margin_right'       => null,
        'h_align'            => 'center',
        'v_align'            => 'center',
        'scaling'            => 'fit',
        'reference_box'      => 'subjectBox',
        'snap_cropped_sides' => false,
    ];

    /**
     * The framing each category is photographed to, as the website is
     * organised: a main category holding subcategories.
     *
     * Two levels because that is how the catalogue is navigated and how the
     * people using this screen think — nobody goes looking for "dresses" in
     * the abstract, they go to Women and then to Dresses. The stored value is
     * still one string, "women/dresses", so nothing downstream has to know
     * the shape of this table.
     *
     * Layout only. Background, mannequin removal and finishing stay separate
     * decisions — a preset that quietly changed them would be a preset nobody
     * trusted, and they are not what makes a collection page look uneven.
     *
     * ── Where the numbers come from ────────────────────────────────────────
     *
     * The padding on each womenswear entry below is what that subcategory's
     * own sample measured, not a figure applied across the board. Photoroom
     * fits the product inside the canvas, so the padding lands on whichever
     * pair of edges is tighter: a dress or a pair of jeans is held by top and
     * bottom, a belt or a shoe by left and right. Each note records the
     * reading it came from.
     *
     * Five of the twelve — tops, t-shirts, blazers, jeans and skirts — came
     * back at 10.0% independently, with the product filling exactly 80% of the
     * height, 80% being what is left of 100 after two 10s. Five unrelated
     * shoots do not land on one figure by accident, so 10% is a real house
     * rule and that is why the value repeats. The ones that differ differ
     * because their own photograph did: dresses tighter at 6%, footwear at 7%
     * and standing on the bottom edge, bras looser at 17%.
     *
     * One photograph per subcategory. That is enough to read what a category
     * does and not enough to prove it meant to, so the notes say which figures
     * rest on a reading that agreed with the house rule and which rest on a
     * reading that stood alone. The lonely ones — bras and bags especially —
     * are the first to revisit when more photographs turn up.
     *
     * The canvas is 2000 square where the sampled files were saved at 1200.
     * The grid never sees a master — Shopify's CDN serves it a ~370px
     * rendition — so the size costs nothing on a collection page and buys the
     * product page room to zoom, which on beaded eveningwear is the thing
     * being sold. Mixing the two is safe precisely because padding is held as
     * a fraction: 10% of 2000 lands in the same place in a tile as 10% of 1200.
     *
     * Men, Kids and Watches have not been sampled at all. They carry the house
     * rule on the grounds that it held everywhere it was checked, and each of
     * their notes says so.
     */
    public const FRAMING_PRESETS = [
        'women' => [
            'label'         => 'Women',
            'subcategories' => [
                'dresses' => [
                    'label' => 'Dresses',
                    'note'  => 'Measured: 7.7% top, 5.0% bottom — tighter than the rest of the catalogue, which is what a full-length dress on a square canvas comes to. Rounded to 6% and evened up; the sample was 2.7% lower at the hem than the shoulder.',
                    'edits' => ['width' => 2000, 'height' => 2000, 'padding' => 0.06],
                ],
                'gown' => [
                    'label' => 'Gowns',
                    'note'  => 'Measured: 9.1% top, 7.8% bottom, product filling 83% of the height. Rounded to 8%.',
                    'edits' => ['width' => 2000, 'height' => 2000, 'padding' => 0.08],
                ],
                'top' => [
                    'label' => 'Tops',
                    'note'  => 'Measured: 10.2% top, 9.6% bottom, product filling 80% of the height.',
                    'edits' => ['width' => 2000, 'height' => 2000, 'padding' => 0.10],
                ],
                't-shirt' => [
                    'label' => 'T-shirts',
                    'note'  => 'Measured: 10.1% top, 9.8% bottom, product filling 80% of the height.',
                    'edits' => ['width' => 2000, 'height' => 2000, 'padding' => 0.10],
                ],
                'blazer' => [
                    'label' => 'Blazers',
                    'note'  => 'Measured: 10.1% top, 10.0% bottom, product filling 80% of the height.',
                    'edits' => ['width' => 2000, 'height' => 2000, 'padding' => 0.10],
                ],
                'jeans' => [
                    'label' => 'Jeans',
                    'note'  => 'Measured: 10.0% top, 9.9% bottom, product filling 80% of the height.',
                    'edits' => ['width' => 2000, 'height' => 2000, 'padding' => 0.10],
                ],
                'skirts' => [
                    'label' => 'Skirts',
                    'note'  => 'Measured: 10.0% top, 9.9% bottom, product filling 80% of the height.',
                    'edits' => ['width' => 2000, 'height' => 2000, 'padding' => 0.10],
                ],
                'swim-wear' => [
                    'label' => 'Swimwear',
                    'note'  => 'Measured: 11.7% and 8.7% left and right, the width being what holds a swimsuit rather than the height. Rounded to 10% and evened up — the sample sat 3% off centre.',
                    'edits' => ['width' => 2000, 'height' => 2000, 'padding' => 0.10],
                ],
                'bras' => [
                    'label' => 'Bras',
                    'note'  => 'Measured: 16.6% and 16.7% left and right, well looser than anything else in the catalogue. Taken at face value from one sample; if bras are not meant to sit this small, this is the entry to correct.',
                    'edits' => ['width' => 2000, 'height' => 2000, 'padding' => 0.17],
                ],
                'bags' => [
                    'label' => 'Bags',
                    'note'  => 'Base 20% up from the bottom, measured across three bags that agreed on it — 19.8%, 19.9% and 18.6%. 18% on the other three sides, which is scale rather than measurement: those same bags filled 49%, 70% and 53% of the height and agreed on nothing, so this one is a judgement and the entry to change if bags read too small or too large.',
                    'edits' => [
                        'width'   => 2000,
                        'height'  => 2000,
                        /*
                         * Two numbers, not one, because a bag category asks two
                         * separate questions, and the samples answered only one
                         * of them.
                         *
                         * Where a bag stands: agreed, 20% up. A clutch is wide
                         * and a top-handle bag is tall, so fitting each to the
                         * canvas leaves them different heights whatever the
                         * padding — a shared bottom edge is the only thing that
                         * makes a row of them read as a row.
                         *
                         * How big it reads: not agreed by the samples, which
                         * ranged from 49% to 70% of the frame. 18% sits a
                         * little above the two that agreed on ~50%, which is
                         * where it was asked to be. Note it is the only number
                         * of the two that may move: the bottom edge is
                         * measured, this one is taste.
                         * Tighter padding does not crop or lose anything, it
                         * enlarges — a bag shot small in a tall frame gets
                         * blown up to fill a square, and next to the catalogue
                         * it reads as zoomed.
                         */
                        'padding'        => 0.18,
                        'padding_bottom' => 0.20,
                        'v_align'        => 'bottom',
                    ],
                ],
                'belts' => [
                    'label' => 'Belts',
                    'note'  => 'Measured: 11.0% and 10.8% left and right. A belt is wider than it is tall, so the width is what holds it.',
                    'edits' => ['width' => 2000, 'height' => 2000, 'padding' => 0.11],
                ],
                'footwear' => [
                    'label' => 'Footwear',
                    'note'  => 'Measured: 7.3% and 7.7% left and right, sitting 47% low rather than centred. Bottom-aligned on purpose — a shoe on a line reads as standing, a centred one reads as floating.',
                    'edits' => ['width' => 2000, 'height' => 2000, 'padding' => 0.07, 'v_align' => 'bottom'],
                ],
            ],
        ],

        /*
         * Men, Kids and Watches carry the house rule on the grounds that it is
         * a house rule — one theme, one tile, one grid. None of them has been
         * sampled yet, so the moment any of them is, these are the entries to
         * correct.
         */
        'men' => [
            'label'         => 'Men',
            'subcategories' => [
                'shirts'   => ['label' => 'Shirts',   'note' => 'House rule, unmeasured: 10% around a 2000 square, centred.', 'edits' => ['width' => 2000, 'height' => 2000, 'padding' => 0.10]],
                'trousers' => ['label' => 'Trousers', 'note' => 'House rule, unmeasured: 10% around a 2000 square, centred.', 'edits' => ['width' => 2000, 'height' => 2000, 'padding' => 0.10]],
                'bags'     => ['label' => 'Bags',     'note' => 'House rule, unmeasured: 10% around a 2000 square, centred.', 'edits' => ['width' => 2000, 'height' => 2000, 'padding' => 0.10]],
                'footwear' => ['label' => 'Footwear', 'note' => 'House rule, unmeasured, bottom-aligned as womenswear footwear measured.', 'edits' => ['width' => 2000, 'height' => 2000, 'padding' => 0.10, 'v_align' => 'bottom']],
            ],
        ],

        'kids' => [
            'label'         => 'Kids & Baby',
            'subcategories' => [
                'dresses'  => ['label' => 'Dresses',  'note' => 'Measured: 10.1% and 10.2% top, 9.9% and 10.0% bottom, both filling exactly 80% of the height. No baseline override needed — a dress is always taller than it is wide, so the height binds and the 10% is the baseline. Framed like a dress, not like a small dress: the tile is the same size whoever the garment is for.', 'edits' => ['width' => 2000, 'height' => 2000, 'padding' => 0.10]],
                'tops'     => ['label' => 'Tops',     'note' => 'House rule, unmeasured: 10% around a 2000 square, centred.', 'edits' => ['width' => 2000, 'height' => 2000, 'padding' => 0.10]],
                'footwear' => ['label' => 'Footwear', 'note' => 'House rule, unmeasured, bottom-aligned as womenswear footwear measured.', 'edits' => ['width' => 2000, 'height' => 2000, 'padding' => 0.10, 'v_align' => 'bottom']],
            ],
        ],

        'watches_jewellery' => [
            'label'         => 'Watches & Jewellery',
            'subcategories' => [
                'watches'   => ['label' => 'Watches',   'note' => 'House rule, unmeasured: 10% around a 2000 square. A small product may want the canvas filled harder — worth sampling before it is trusted.', 'edits' => ['width' => 2000, 'height' => 2000, 'padding' => 0.10]],
                'jewellery' => ['label' => 'Jewellery', 'note' => 'House rule, unmeasured: 10% around a 2000 square. A small product may want the canvas filled harder — worth sampling before it is trusted.', 'edits' => ['width' => 2000, 'height' => 2000, 'padding' => 0.10]],
            ],
        ],
    ];

    public const COLOR_SPACES = ['sRGB', 'original'];

    public const METADATA_MODES = [
        'never'                          => 'Strip everything',
        'xmp'                            => 'XMP only',
        'exifSubset'                     => 'EXIF subset',
        'exifSubsetWithXmpCompatibility' => 'EXIF subset + XMP',
    ];

    public const ALPHA_MODES = ['auto', 'never'];

    public const SEGMENTATION_MODES = [
        ''                    => 'Whatever the prompt describes',
        'keepSalientObject'   => 'Also keep the main product',
        'ignoreSalientObject' => 'Go purely on the description',
    ];

    /**
     * Photoroom returns how sure it was of the cutout in a response header,
     * on every call, for free. 0 is confident and 1 is a guess; -1 means it
     * could not tell (photos with people in them, mostly).
     */
    private const UNCERTAINTY_HEADER = 'x-uncertainty-score';

    /**
     * Sharper subject edges on large inputs. Every photo coming out of a
     * studio camera is well past the 2K this needs, so it is asked for by
     * default rather than exposed as a choice.
     */
    private const HD_CUTOUT_HEADER = 'pr-hd-background-removal';

    /**
     * Ghost Mannequin only reconstructs front views, so it can't help a back
     * or side shot where the stand is left visible. This is the closest
     * Photoroom gets to "erase that object" for such a photo.
     */
    /**
     * What the generative pass is told to do, and mostly what not to do.
     *
     * This is an image model regenerating the whole picture, so every freedom
     * left in the wording is one it may take. The earlier version asked for the
     * garment "floating in its place" and got exactly that: shirts came back
     * tilted, as though set down at an angle. Nothing had forbidden rotation,
     * and "floating" invited it.
     *
     * So the instruction is now almost entirely negative. The one thing to
     * change is named once; everything else is a list of things that must stay
     * as they were. Verbose on purpose — brevity here is freedom for the model.
     */
    private const MANNEQUIN_REMOVAL_PROMPT = 'Remove only the hanger, hook, clothes rail, garment rack, mannequin, '
        . 'dress form, headless body or stand that this garment is displayed on. '
        . 'Change nothing else whatsoever. '
        . 'The garment must stay in exactly the same position, at exactly the same angle, at the same size and in '
        . 'the same shape, with the same folds, creases and shadows. '
        . 'Keep it upright and square to the frame: do not rotate it, do not tilt or lean it, do not lay it flat, '
        . 'do not drape or crumple it, do not move it up, down or sideways, do not enlarge or shrink it. '
        . 'Do not redraw the garment. Do not add a person, a hanger, a surface or any other object.';

    private string $apiKey;

    /**
     * Cutout confidence from the most recent call, so the caller can record it
     * without every method in the chain having to return a pair.
     */
    private ?float $lastUncertainty = null;

    public function __construct()
    {
        $this->apiKey = (string) (config('services.photoroom.api_key') ?? '');
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * A sandbox key edits 1000 images a month for free but stamps a watermark
     * across every result. Worth saying out loud in the UI — otherwise the
     * output reads as broken rather than as free.
     */
    public function isSandbox(): bool
    {
        return str_starts_with($this->apiKey, 'sandbox_');
    }

    /**
     * Send one image through Photoroom and return the edited bytes.
     *
     * @throws \RuntimeException  when Photoroom refuses the image outright
     */
    public function edit(string $imageContent, array $edits, string $filename = 'image.jpg'): string
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('No Photoroom API key is configured. Add PHOTOROOM_API_KEY to the environment.');
        }

        if (strlen($imageContent) > self::MAX_INPUT_BYTES) {
            throw new \RuntimeException('Image is larger than Photoroom accepts (30 MB) even after downscaling.');
        }

        $fields  = $this->buildFields($edits);
        $headers = $this->buildHeaders($edits);

        /*
         * What was actually asked for, when someone needs to know.
         *
         * Off by default and worth having: a result that comes back looking
         * untouched is indistinguishable from a request that never carried the
         * settings, and without this the only way to tell them apart is to
         * guess. The image bytes are not logged, only the instructions.
         */
        if (config('services.photoroom.log_requests')) {
            Log::info('Photoroom request', [
                'file'    => $filename,
                'fields'  => $fields,
                'headers' => array_keys($headers),
            ]);
        }

        return $this->postWithRetry($imageContent, $filename, $fields, $headers);
    }

    /**
     * Erase a visible mannequin/dress-form stand from a photo via Photoroom's
     * generative editWithAI mode, returning the cleaned-up bytes.
     *
     * Deliberately its own request rather than a field folded into edit():
     * Photoroom warns that mixing editWithAI with removeBackground in one
     * call gives unpredictable results, so this pass runs first and its
     * output becomes the input to the normal edit() call.
     *
     * @throws \RuntimeException  when Photoroom refuses the image outright
     */
    public function removeMannequin(string $imageContent, string $filename = 'image.jpg', ?int $seed = null): string
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('No Photoroom API key is configured. Add PHOTOROOM_API_KEY to the environment.');
        }

        $fields = [
            'editWithAI.mode'   => 'ai.auto',
            'editWithAI.prompt' => self::MANNEQUIN_REMOVAL_PROMPT,
            // Left unset, Photoroom defaults to removing the background on
            // its own — which then collides with the forced jpg export below
            // (JPEG can't hold transparency). Background removal is this
            // request's job, not this pass's, so it's explicitly turned off.
            'removeBackground'  => 'false',
            'export.format'     => 'jpg',
        ];

        // Without a seed this pass redraws differently every run, which is the
        // one step that stops a re-edit reproducing the result being re-edited.
        if ($seed !== null) {
            $fields['editWithAI.seed'] = (string) $seed;
        }

        return $this->postWithRetry($imageContent, $filename, $fields);
    }

    /**
     * Make an image from a description alone, with no photo to start from.
     *
     * The odd one out: every other call here edits something the operator
     * already has. This one has no imageFile at all, so it cannot ride the
     * same multipart path, and it bills like any other edited image.
     *
     * @throws \RuntimeException  when Photoroom refuses the prompt
     */
    public function generateFromPrompt(string $prompt, string $size = 'SQUARE_HD', ?int $seed = null): string
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('No Photoroom API key is configured. Add PHOTOROOM_API_KEY to the environment.');
        }

        if (trim($prompt) === '') {
            throw new \RuntimeException('An image can only be generated from a non-empty description.');
        }

        $fields = [
            'imageFromPrompt.prompt' => trim($prompt),
            'imageFromPrompt.size'   => isset(self::SIZE_PRESETS[$size]) ? $size : 'SQUARE_HD',
            'export.format'          => 'png',
        ];

        if ($seed !== null) {
            $fields['imageFromPrompt.seed'] = (string) $seed;
        }

        return $this->postWithRetry(null, 'generated.png', $fields);
    }

    /**
     * What a SKU group starts from before anybody has configured it.
     *
     * These used to be collected on the first screen and applied to the whole
     * folder, which only ever held while a folder was one kind of thing. The
     * run now asks for nothing but where the photos are; every setting is
     * chosen per SKU once the photos can actually be seen, so this is the
     * starting point each group is created with rather than a decision anyone
     * has committed to.
     *
     * Chosen to be the safe answer for a Shopify listing: a real-pixel cutout
     * on white, nothing generated, nothing that shifts colour.
     */
    public static function defaultEdits(): array
    {
        return [
            'remove_background' => true,
            'background_mode'   => 'white',

            'ghost_mannequin' => false,
            'flat_lay'        => false,
            'virtual_model'   => false,
            'ironing'         => false,

            /*
             * Segmentation is the preferred way to lose a mannequin: it cuts
             * one out of the real photograph, where the generative pass redraws
             * the garment and can move or reshape it. What to remove is the
             * same on every apparel shot, so it is filled in. What to keep is
             * the product's own name and only the operator knows it — and
             * without it the segmentation does not run at all, which is the
             * safe way round.
             */
            /*
             * Erase the stand from a strip of the photo and put only that strip
             * back, rather than letting the generative pass redraw the whole
             * garment. See StandEraseCompositor for why: on a printed garment
             * the redraw does not soften the print, it reinvents it.
             */
            'surgical_erase' => false,
            'erase_zone'     => 0.40,

            'segmentation_prompt'          => null,
            'segmentation_negative_prompt' => 'the mannequin, dress form, clothes rail, hanger and stand',

            'shadow'   => null,
            'lighting' => null,
            'beautify' => null,
            'upscale'  => false,
            'expand'   => false,

            /*
             * Which category framing was followed, kept beside the values it
             * produced so a run can say what standard it was held to — and so
             * the screen can show the choice again rather than a bare 6%.
             */
            'framing_preset' => null,

            'width'    => null,
            'height'   => null,
            'padding'  => null,
            'h_align'  => 'center',
            'v_align'  => 'center',
            'scaling'  => 'fit',

            'trim_top'    => null,
            'trim_bottom' => null,

            /*
             * The format the finished file is kept in. 'auto' means JPEG for a
             * product on an opaque background and PNG for a transparent cutout,
             * which JPEG cannot hold.
             *
             * What Photoroom is asked to send back is a separate question, and
             * a different answer — see transportFormat().
             */
            'export_format' => 'auto',
            'color_space'   => 'sRGB',
        ];
    }

    /**
     * What to call the product, per category, when something is holding it up.
     *
     * Deliberately not part of FRAMING_PRESETS, which is layout and only
     * layout. This answers a different question — "what is the subject?" — and
     * it is asked whenever a hanger, rail or dress form is in shot.
     *
     * Naming the product cuts the stand out of the real photograph inside the
     * one cutout request. The alternative is a generative pass that erases the
     * stand and redraws the garment, which costs an extra credit and can come
     * back with the garment reshaped or turned. A category has already been
     * told what the product is, so there is no reason to make anyone type it.
     */
    public const PRODUCT_NOUNS = [
        'women/dresses'   => 'the dress',
        'women/gown'      => 'the gown',
        'women/top'       => 'the top',
        'women/t-shirt'   => 'the t-shirt',
        'women/blazer'    => 'the blazer',
        'women/jeans'     => 'the jeans',
        'women/skirts'    => 'the skirt',
        'women/swim-wear' => 'the swimsuit',
        'women/bras'      => 'the bra',
        'women/bags'      => 'the bag',
        'women/belts'     => 'the belt',
        'women/footwear'  => 'the shoes',

        'men/shirts'      => 'the shirt',
        'men/trousers'    => 'the trousers',
        'men/bags'        => 'the bag',
        'men/footwear'    => 'the shoes',

        'kids/dresses'    => 'the dress',
        'kids/tops'       => 'the top',
        'kids/footwear'   => 'the shoes',

        'watches_jewellery/watches'   => 'the watch',
        'watches_jewellery/jewellery' => 'the jewellery',
    ];

    /** What a category calls its product, or null if it has no name for it. */
    public static function productNoun(?string $key): ?string
    {
        return filled($key) ? (self::PRODUCT_NOUNS[$key] ?? null) : null;
    }

    /**
     * Every subcategory flattened to the key that is actually stored,
     * "women/dresses" => its framing, label and note.
     *
     * The tree is how the screen is laid out; this is how everything else
     * addresses it, so a stored run holds one string and does not care that
     * the table above ever gained or lost a level.
     */
    public static function framingPresetsFlat(): array
    {
        $flat = [];

        foreach (self::FRAMING_PRESETS as $mainKey => $main) {
            foreach ($main['subcategories'] as $subKey => $sub) {
                $flat[$mainKey . '/' . $subKey] = $sub + [
                    'main'      => $main['label'],
                    'full'      => $main['label'] . ' → ' . $sub['label'],
                    'main_key'  => $mainKey,
                ];
            }
        }

        return $flat;
    }

    /** One subcategory's framing, or null when the key is not one we know. */
    public static function framingPreset(?string $key): ?array
    {
        return filled($key) ? (self::framingPresetsFlat()[$key] ?? null) : null;
    }

    /** "Women → Dresses", for anywhere a run has to say what it was held to. */
    public static function framingPresetLabel(?string $key): ?string
    {
        return self::framingPreset($key)['full'] ?? null;
    }

    /**
     * Write a category's framing over whatever framing $edits had.
     *
     * A blank or unknown key means this one is being framed by hand: the
     * framing is left exactly as it is and the category name is dropped,
     * rather than a run claiming a standard it is not actually following.
     */
    public static function applyFramingPreset(array $edits, ?string $key): array
    {
        $preset = self::framingPreset($key);

        if (!$preset) {
            $edits['framing_preset'] = null;

            return $edits;
        }

        return array_merge($edits, self::FRAMING_FIELDS, $preset['edits'], [
            'framing_preset' => $key,
        ]);
    }

    /**
     * Extra request headers some options depend on.
     */
    public function buildHeaders(array $edits): array
    {
        $headers = [];

        if (($edits['shadow'] ?? null) === 'ai.auto-with-overrides') {
            $headers['pr-ai-shadows-model-version'] = self::SHADOW_MODEL_VERSION;
        }

        /*
         * Only meaningful when there is a cutout to sharpen the edges of — and
         * refused outright, with a 400, alongside a text-guided segmentation.
         * Photoroom decides the subject one way or the other: from the prompt,
         * or from its own HD matting. Asked for both, it does neither.
         *
         * The prompt wins, because it is what the operator said the product is
         * and it is what keeps a hanger out of shot without redrawing the
         * garment. Losing the HD edge is the cheaper half of that trade.
         */
        $segmenting = trim((string) ($edits['segmentation_prompt'] ?? '')) !== '';

        if (!$segmenting && (!empty($edits['remove_background']) || $this->generatesOwnCanvas($edits))) {
            $headers[self::HD_CUTOUT_HEADER] = 'auto';
        }

        return $headers;
    }

    /**
     * How unsure Photoroom was of the last cutout: 0 confident, 1 a guess,
     * null when it declined to say. Reset per call, so read it straight after
     * the edit it belongs to.
     */
    public function lastUncertaintyScore(): ?float
    {
        return $this->lastUncertainty;
    }

    /**
     * Translate our own option names into Photoroom's parameter names.
     */
    public function buildFields(array $edits): array
    {
        $fields = [];

        $this->applyBackground($fields, $edits);
        $this->applyApparel($fields, $edits);
        $this->applyEnhancements($fields, $edits);
        $this->applyShadow($fields, $edits);
        $this->applyLayout($fields, $edits);

        // A saved Photoroom template supplies the canvas, so outputSize is
        // left to its 'auto' default, which means "keep the template's size".
        if (!empty($edits['template_id'])) {
            $fields['templateId'] = (string) $edits['template_id'];
            unset($fields['outputSize']);
        }

        $fields['export.format'] = $this->transportFormat($edits);

        if (!empty($edits['dpi'])) {
            $fields['export.dpi'] = (string) max(72, min(1200, (int) $edits['dpi']));
        }

        // Left alone, the output can come back in whatever space the camera
        // wrote. Product colour has to be the same on every listing, and
        // sRGB is what browsers assume when a file does not say.
        $fields['colorSpace'] = in_array($edits['color_space'] ?? '', self::COLOR_SPACES, true)
            ? $edits['color_space']
            : 'sRGB';

        if (array_key_exists($edits['preserve_metadata'] ?? '', self::METADATA_MODES)) {
            $fields['preserveMetadata'] = $edits['preserve_metadata'];
        }

        if (in_array($edits['keep_alpha'] ?? '', self::ALPHA_MODES, true)) {
            $fields['keepExistingAlphaChannel'] = $edits['keep_alpha'];
        }

        return $fields;
    }

    // ── Field groups ───────────────────────────────────────────────────────

    private function applyBackground(array &$fields, array $edits): void
    {
        $mode = $this->backgroundMode($edits);

        // Blur keeps the original scene and softens it, so the background is
        // explicitly NOT removed — the two are alternatives, not a sequence.
        if ($mode === 'blur') {
            $fields['removeBackground']    = 'false';
            $fields['background.blur.mode'] = in_array($edits['background_blur_mode'] ?? '', ['gaussian', 'bokeh'], true)
                ? $edits['background_blur_mode']
                : 'gaussian';
            $fields['background.blur.radius'] = (string) max(0, min(0.05, (float) ($edits['background_blur_radius'] ?? 0.02)));

            return;
        }

        $fields['removeBackground'] = !empty($edits['remove_background']) ? 'true' : 'false';

        if (empty($edits['remove_background'])) {
            return;
        }

        match ($mode) {
            'white'  => $fields['background.color'] = 'FFFFFF',
            'custom' => $fields['background.color'] = ltrim((string) ($edits['background_color'] ?? 'FFFFFF'), '#') ?: 'FFFFFF',
            'prompt' => $fields['background.prompt'] = (string) ($edits['background_prompt'] ?? ''),
            'image'  => $fields['background.imageUrl'] = (string) ($edits['background_image_url'] ?? ''),
            default  => null, // transparent — no background field at all
        };

        if ($mode !== 'prompt') {
            return;
        }

        // A generated scene is random per call; a seed makes a re-run repeatable.
        if (!empty($edits['background_seed'])) {
            $fields['background.seed'] = (string) (int) $edits['background_seed'];
        }

        if (!empty($edits['background_negative_prompt'])) {
            $fields['background.negativePrompt'] = (string) $edits['background_negative_prompt'];
        }

        // A reference photo steers the generated scene far more reliably than
        // adjectives do — "like this shoot" instead of "warm editorial light".
        if (!empty($edits['background_guidance_url'])) {
            $fields['background.guidance.imageUrl'] = (string) $edits['background_guidance_url'];
            $fields['background.guidance.scale']    = (string) max(0, min(1, (float) ($edits['background_guidance_scale'] ?? 0.5)));
        }

        if (in_array($edits['background_scaling'] ?? '', ['fit', 'fill'], true)) {
            $fields['background.scaling'] = $edits['background_scaling'];
        }

        if (in_array($edits['background_expand_prompt'] ?? '', ['ai.auto', 'ai.never'], true)) {
            $fields['background.expandPrompt.mode'] = $edits['background_expand_prompt'];
        }
    }

    private function applyApparel(array &$fields, array $edits): void
    {
        $size   = $edits['apparel_size'] ?? null;
        $prompt = trim((string) ($edits['apparel_prompt'] ?? ''));

        if (!empty($edits['virtual_model'])) {
            $fields['virtualModel.mode'] = 'ai.auto';

            if (in_array($edits['vm_model'] ?? '', self::VIRTUAL_MODEL_PRESETS, true)) {
                $fields['virtualModel.model.preset.name'] = $edits['vm_model'];
            }
            if (in_array($edits['vm_scene'] ?? '', self::VIRTUAL_MODEL_SCENES, true)) {
                $fields['virtualModel.scene.preset.name'] = $edits['vm_scene'];
            }
            if (in_array($edits['vm_pose'] ?? '', self::VIRTUAL_MODEL_POSES, true)) {
                $fields['virtualModel.pose'] = $edits['vm_pose'];
            }

            // Virtual Try-On is this same feature pointed at your own model
            // and your own set, rather than one of Photoroom's stock people.
            // A custom image overrides the preset of the same name, so the
            // preset above is only sent when no photo was supplied.
            if (!empty($edits['vm_model_url'])) {
                $fields['virtualModel.model.custom.imageUrl'] = (string) $edits['vm_model_url'];
                unset($fields['virtualModel.model.preset.name']);
            }

            if (!empty($edits['vm_scene_url'])) {
                $fields['virtualModel.scene.custom.imageUrl'] = (string) $edits['vm_scene_url'];
                unset($fields['virtualModel.scene.preset.name']);
            }

            // More angles of the same garment give the model a better idea of
            // how it is actually cut than one front-on photo can.
            foreach (array_values(array_filter((array) ($edits['vm_extra_product_urls'] ?? []))) as $i => $url) {
                $fields["virtualModel.additionalProductImages[{$i}].imageUrl"] = (string) $url;
            }
            if ($prompt !== '') {
                $fields['virtualModel.prompt'] = $prompt;
            }
            if (isset(self::SIZE_PRESETS[$size])) {
                $fields['virtualModel.size'] = $size;
            }
        } elseif (!empty($edits['ghost_mannequin'])) {
            $fields['ghostMannequin.mode'] = 'ai.auto';

            if ($prompt !== '') {
                $fields['ghostMannequin.prompt'] = $prompt;
            }
            if (isset(self::SIZE_PRESETS[$size])) {
                $fields['ghostMannequin.size'] = $size;
            }
        } elseif (!empty($edits['flat_lay'])) {
            $fields['flatLay.mode'] = 'ai.auto';

            if ($prompt !== '') {
                $fields['flatLay.prompt'] = $prompt;
            }
            if (isset(self::SIZE_PRESETS[$size])) {
                $fields['flatLay.size'] = $size;
            }
        }

        // Ironing is independent of the three above — a garment can be pressed
        // whether it is on a mannequin, laid flat or on a model.
        if (!empty($edits['ironing'])) {
            $fields['ironing.mode'] = 'ai.auto';
        }
    }

    private function applyEnhancements(array &$fields, array $edits): void
    {
        $this->applyLighting($fields, $edits);

        if (!empty($edits['upscale'])) {
            $fields['upscale.mode'] = 'ai.auto';

            /*
             * Without a target, upscale picks its own factor. Naming the
             * resolution is what makes a mixed catalogue come out uniform.
             *
             * The field wants "widthxheight", not a bare number — a plain
             * "2000" is rejected. Callers pass one edge because every canvas
             * here is square, so it is squared on the way out.
             */
            if (!empty($edits['upscale_resolution'])) {
                $edge = (int) $edits['upscale_resolution'];

                $fields['upscale.targetResolution'] = $edge . 'x' . $edge;
            }
        }

        if (!empty($edits['expand'])) {
            $fields['expand.mode'] = 'ai.auto';
            $this->applySeed($fields, 'expand.seed', $edits['expand_seed'] ?? null);
        }

        if (!empty($edits['uncrop'])) {
            $fields['uncrop.mode'] = 'ai.auto';
            $this->applySeed($fields, 'uncrop.seed', $edits['uncrop_seed'] ?? null);
        }

        if (!empty($edits['beautify']) && array_key_exists($edits['beautify'], self::BEAUTIFY_MODES)) {
            $fields['beautify.mode'] = $edits['beautify'];
            $this->applySeed($fields, 'beautify.seed', $edits['beautify_seed'] ?? null);

            // Food and vehicle beautify on a photo of neither is a silent
            // no-op by default. Asking to be told turns a mystery result into
            // an error message naming the reason.
            if (!empty($edits['beautify_strict'])) {
                $fields['beautify.onSubjectMismatch'] = 'error';
            }
        }

        if (!empty($edits['text_removal']) && array_key_exists($edits['text_removal'], self::TEXT_REMOVAL_MODES)) {
            $fields['textRemoval.mode'] = $edits['text_removal'];
        }

        if (!empty($edits['outline_color'])) {
            $fields['outline.color'] = ltrim((string) $edits['outline_color'], '#');
            $fields['outline.width'] = (string) max(0, min(0.1, (float) ($edits['outline_width'] ?? 0.03)));

            if (isset($edits['outline_blur'])) {
                $fields['outline.blurRadius'] = (string) max(0, min(0.025, (float) $edits['outline_blur']));
            }
        }

        $this->applySegmentation($fields, $edits);
    }

    /**
     * Relighting, defaulting to the mode that leaves colour alone.
     *
     * Older sessions stored lighting as a checkbox. Those are honoured as the
     * colour-safe mode rather than the free-for-all one they actually ran
     * with: re-running an old session should not be the thing that shifts a
     * garment's colour.
     */
    private function applyLighting(array &$fields, array $edits): void
    {
        $mode = $edits['lighting'] ?? null;

        if ($mode === true || $mode === 1 || $mode === '1') {
            $mode = 'ai.preserve-hue-and-saturation';
        }

        if (is_string($mode) && $mode !== '' && array_key_exists($mode, self::LIGHTING_MODES)) {
            $fields['lighting.mode'] = $mode;
        }
    }

    /**
     * Keep or drop parts of a photo by describing them.
     *
     * This is the cheap answer to "the stand is still in frame": one cutout
     * request that is told what the subject is, rather than a generative pass
     * to erase the stand followed by a second request to cut out what is left.
     */
    private function applySegmentation(array &$fields, array $edits): void
    {
        $prompt = trim((string) ($edits['segmentation_prompt'] ?? ''));

        if ($prompt === '') {
            return;
        }

        $fields['segmentation.prompt'] = $prompt;

        if (!empty($edits['segmentation_negative_prompt'])) {
            $fields['segmentation.negativePrompt'] = (string) $edits['segmentation_negative_prompt'];
        }

        $mode = $edits['segmentation_mode'] ?? '';

        if (in_array($mode, ['keepSalientObject', 'ignoreSalientObject'], true)) {
            $fields['segmentation.mode'] = $mode;

            return;
        }

        /*
         * Left to itself, the segmentation model protects whatever it judges to
         * be the salient object — and on a garment hanging up, that judgement
         * takes in the hanger. The negative prompt then loses the argument and
         * the stand survives the cutout, which is the one thing naming the
         * product was meant to prevent.
         *
         * Naming the product is a statement about what the subject is, so once
         * that has been said there is nothing left for saliency to decide.
         */
        $fields['segmentation.mode'] = 'ignoreSalientObject';
    }

    /** Seeds are what make a re-edit reproduce the run being re-edited. */
    private function applySeed(array &$fields, string $field, mixed $seed): void
    {
        if (filled($seed)) {
            $fields[$field] = (string) (int) $seed;
        }
    }

    private function applyShadow(array &$fields, array $edits): void
    {
        $mode = $edits['shadow'] ?? null;

        if (!$mode || !array_key_exists($mode, self::SHADOW_MODES)) {
            return;
        }

        $fields['shadow.mode'] = $mode;

        if ($mode !== 'ai.auto-with-overrides') {
            return;
        }

        if (isset($edits['shadow_softness'])) {
            $fields['shadow.softnessOverride'] = (string) max(0, min(1, (float) $edits['shadow_softness']));
        }
        if (isset($edits['shadow_intensity'])) {
            $fields['shadow.intensityOverride'] = (string) max(0, min(1, (float) $edits['shadow_intensity']));
        }
        if (in_array($edits['shadow_spread'] ?? '', self::SHADOW_SPREADS, true)) {
            $fields['shadow.spreadOverride'] = $edits['shadow_spread'];
        }
        if (in_array($edits['shadow_direction'] ?? '', self::SHADOW_DIRECTIONS, true)) {
            $fields['shadow.directionOverride'] = $edits['shadow_direction'];
        }
        if (in_array($edits['shadow_pose'] ?? '', self::SHADOW_POSES, true)) {
            $fields['shadow.subjectPoseOverride'] = $edits['shadow_pose'];
        }
    }

    private function applyLayout(array &$fields, array $edits): void
    {
        /*
         * A generated canvas (ghost mannequin, flat lay, virtual model) already
         * decides its own dimensions from its size preset. Sending outputSize
         * as well asks for the picture to be built at one shape and then forced
         * into another, which is where soft, upscaled results come from — so
         * the explicit size is only applied when nothing else is generating the
         * canvas.
         */
        if (!$this->generatesOwnCanvas($edits)) {
            if (!empty($edits['width']) && !empty($edits['height'])) {
                $fields['outputSize'] = ((int) $edits['width']) . 'x' . ((int) $edits['height']);
            } elseif (($edits['output_size_mode'] ?? '') === 'originalImage') {
                // Explicit rather than relying on 'auto', which only means the
                // same thing while no template is in play.
                $fields['outputSize'] = 'originalImage';
            } elseif (($edits['output_size_mode'] ?? '') === 'croppedSubject') {
                // No canvas at all — the result is exactly the product's
                // bounding box, which is what a marketplace feed usually wants
                // when it is going to lay the images out itself.
                $fields['outputSize'] = 'croppedSubject';
            }

            // A ceiling rather than a shape. For a catalogue holding both a
            // long dress and a flat watch face, capping the longest edge keeps
            // file sizes in line without forcing either into a square.
            if (!empty($edits['max_width'])) {
                $fields['maxWidth'] = (string) (int) $edits['max_width'];
            }
            if (!empty($edits['max_height'])) {
                $fields['maxHeight'] = (string) (int) $edits['max_height'];
            }
        }

        // Padding is a fraction of the canvas. Half would leave no subject.
        if (isset($edits['padding']) && $edits['padding'] !== '' && $edits['padding'] !== null) {
            $fields['padding'] = (string) max(0, min(0.49, (float) $edits['padding']));
        }

        // Per-edge padding and margins, for the shots that need breathing room
        // on one side only — a heel photographed to the bottom of the frame,
        // a hat that wants headroom.
        foreach (['Top', 'Bottom', 'Left', 'Right'] as $edge) {
            $this->applyEdge($fields, 'padding', $edge, $edits['padding_' . strtolower($edge)] ?? null);
            $this->applyEdge($fields, 'margin', $edge, $edits['margin_' . strtolower($edge)] ?? null);
        }

        if (isset($edits['margin']) && $edits['margin'] !== '' && $edits['margin'] !== null) {
            $fields['margin'] = (string) max(0, min(0.49, (float) $edits['margin']));
        }

        if (!empty($edits['snap_cropped_sides'])) {
            $fields['ignorePaddingAndSnapOnCroppedSides'] = 'true';
        }

        $fields['horizontalAlignment'] = in_array($edits['h_align'] ?? '', ['left', 'center', 'right'], true)
            ? $edits['h_align']
            : 'center';

        $fields['verticalAlignment'] = in_array($edits['v_align'] ?? '', ['top', 'center', 'bottom'], true)
            ? $edits['v_align']
            : 'center';

        $fields['scaling'] = ($edits['scaling'] ?? 'fit') === 'fill' ? 'fill' : 'fit';

        if (($edits['reference_box'] ?? '') === 'originalImage') {
            $fields['referenceBox'] = 'originalImage';
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * One edge of padding or margin, accepting either a fraction of the
     * canvas or an explicit pixel count ("40px"), which is what Photoroom
     * itself accepts. Blank means "no opinion", which is not the same as 0.
     */
    private function applyEdge(array &$fields, string $kind, string $edge, mixed $value): void
    {
        if (!filled($value)) {
            return;
        }

        $value = trim((string) $value);

        if (str_ends_with($value, 'px') || str_ends_with($value, '%')) {
            $fields[$kind . $edge] = $value;

            return;
        }

        $number = (float) $value;

        /*
         * A fraction of the canvas cannot exceed 0.49 — half would leave no
         * subject — so anything above 1 was never a fraction. Clamping it
         * instead turned a typed "10" into 49% padding and a product the size
         * of a stamp, which is the kind of wrong that looks deliberate.
         */
        $fields[$kind . $edge] = $number > 1
            ? ((int) round($number)) . 'px'
            : (string) max(0, min(0.49, $number));
    }

    /** True when an AI feature builds the canvas itself. */
    public function generatesOwnCanvas(array $edits): bool
    {
        return !empty($edits['ghost_mannequin'])
            || !empty($edits['flat_lay'])
            || !empty($edits['virtual_model']);
    }

    /**
     * Older sessions were stored before background_mode existed; their intent
     * is still readable from whether a colour was set.
     */
    private function backgroundMode(array $edits): string
    {
        $mode = $edits['background_mode'] ?? null;

        if (in_array($mode, self::BACKGROUND_MODES, true)) {
            return $mode;
        }

        return empty($edits['background_color']) ? 'transparent' : 'custom';
    }

    /**
     * PNG whenever the result has to carry transparency, JPEG otherwise.
     *
     * Asking for a JPEG of a transparent cutout is the one combination that
     * silently ruins the image — JPEG has no alpha channel, so the background
     * Photoroom just removed comes back as black.
     */
    /**
     * The format Photoroom is asked to export, as opposed to the one the file
     * is kept in.
     *
     * These differ on purpose whenever the answer is JPEG. Photoroom's JPEG
     * export is fixed at quality 80 with chroma subsampling and their API has
     * no parameter to raise it, so accepting a JPEG from them means accepting a
     * lossy pass nobody chose, at a quality nobody can change. Asking for PNG
     * instead costs a larger download and gets the edit back intact; the JPEG
     * is then made here, at a quality we pick, under a size we pick.
     *
     * Where the kept format already holds an alpha channel there is nothing to
     * gain — PNG in, PNG out — so it asks for exactly what it will keep.
     */
    public function transportFormat(array $edits): string
    {
        return $this->outputFormat($edits) === 'jpg' ? 'png' : $this->outputFormat($edits);
    }

    public function outputFormat(array $edits): string
    {
        $isTransparent = !empty($edits['remove_background'])
            && $this->backgroundMode($edits) === 'transparent';

        $requested = $edits['export_format'] ?? 'auto';

        if (!in_array($requested, self::EXPORT_FORMATS, true)) {
            $requested = 'auto';
        }

        // JPEG cannot hold an alpha channel, so a transparent cutout asked for
        // as a JPEG comes back on a black rectangle. WebP can, so a WebP
        // request is honoured either way; a JPEG one is quietly upgraded.
        if ($isTransparent) {
            return in_array($requested, self::ALPHA_FORMATS, true) ? $requested : 'png';
        }

        return $requested === 'auto' ? 'jpg' : $requested;
    }

    /** True when the result carries an alpha channel that must not be flattened. */
    public function producesAlpha(array $edits): bool
    {
        return in_array($this->outputFormat($edits), self::ALPHA_FORMATS, true)
            && !empty($edits['remove_background'])
            && $this->backgroundMode($edits) === 'transparent';
    }

    /**
     * True when the result will be a JPEG, and therefore safe to hand to
     * ImageProcessingService for compression without losing an alpha channel.
     */
    public function producesJpeg(array $edits): bool
    {
        return $this->outputFormat($edits) === 'jpg';
    }

    // ──────────────────────────────────────────────────────────────────────

    /**
     * POST with retries. Photoroom does not charge for a call that errors, so
     * a retry costs nothing but time: rate limits and 5xx are worth waiting
     * out, while a 4xx describes the image itself and would be refused again.
     */
    private function postWithRetry(?string $imageContent, string $filename, array $fields, array $extraHeaders = [], int $retries = 2): string
    {
        $attempt   = 0;
        $slept     = 0;
        $lastError = 'Photoroom did not return an image.';

        // Belongs to this call only — a stale score from the previous image
        // would be filed against this one.
        $this->lastUncertainty = null;

        do {
            $attempt++;
            $retryable = false;
            $waitFor   = $attempt * 5;

            // Both of these run before the upload, not after: the point is to
            // not spend several megabytes finding out we were not welcome yet.
            $this->guardQuota();
            $this->throttle();

            try {
                $request = Http::withHeaders(array_merge([
                    'x-api-key' => $this->apiKey,
                    'Accept'    => 'image/png, image/jpeg, application/json',
                ], $extraHeaders))->timeout(180);

                // Each field is attached as its own multipart part rather than
                // passed as an array: Photoroom's names contain dots
                // ("background.color"), which array-keyed bodies are free to
                // reinterpret as nesting.
                foreach ($fields as $name => $value) {
                    $request = $request->attach($name, (string) $value);
                }

                // Generation from a prompt has nothing to attach.
                if ($imageContent !== null) {
                    $request = $request->attach('imageFile', $imageContent, $filename);
                }

                $response = $request->post(self::ENDPOINT);

                if ($response->successful()) {
                    $body = $response->body();

                    // A successful status with a JSON body means the edit was
                    // refused in a way the status code did not admit to.
                    if ($body !== '' && !str_starts_with(ltrim($body), '{')) {
                        $this->rememberUncertainty($response);

                        return $body;
                    }

                    $lastError = $this->describeError($body);
                } else {
                    $status    = $response->status();
                    $retryable = $status === 429 || $status >= 500;
                    $lastError = "Photoroom returned {$status}: " . $this->describeError($response->body());

                    Log::warning('Photoroom API error', [
                        'attempt' => $attempt,
                        'status'  => $status,
                        'body'    => substr($response->body(), 0, 500),
                    ]);

                    if ($status === 429) {
                        $wait = $this->throttleWait($response);

                        // Longer than a rate-limit window means a quota has run
                        // out rather than that we arrived a few seconds early.
                        // Recorded once, so the rest of the batch fails in
                        // milliseconds instead of each item re-uploading its
                        // megabytes to be told the same thing.
                        if ($wait > self::MAX_THROTTLE_WAIT) {
                            $this->closeQuota($wait);

                            $lastError = 'Photoroom quota is exhausted — available again in ' . self::describeWait($wait) . '.';
                            $retryable = false;
                        } else {
                            // A second's grace, so the window has closed rather
                            // than merely be closing when the retry lands.
                            $waitFor = $wait + 1;

                            // Tell the other workers too. Left to themselves
                            // they each discover the same shut door, one wasted
                            // upload apiece.
                            $this->deferFleet($waitFor);
                        }
                    }

                    // A rejected image gives the same answer however often it
                    // is asked; stop rather than re-uploading megabytes for it.
                    if (!$retryable) {
                        break;
                    }
                }
            } catch (\Throwable $e) {
                $retryable = true;
                $lastError = 'Photoroom request failed: ' . $e->getMessage();
                Log::warning('Photoroom API exception', ['attempt' => $attempt, 'error' => $e->getMessage()]);
            }

            if ($retryable && $attempt <= $retries) {
                // Stop rather than sleep past the worker's own timeout — that
                // kills the job mid-wait, and takes failed() down with it, so
                // the item never gets the message explaining why.
                if ($slept + $waitFor > self::MAX_WAIT_BUDGET) {
                    break;
                }

                sleep($waitFor);
                $slept += $waitFor;
            }
        } while ($retryable && $attempt <= $retries);

        throw new \RuntimeException($lastError);
    }

    /**
     * Hold the whole fleet under Photoroom's requests-per-minute ceiling.
     *
     * The pacing has to be shared rather than static: a per-process timestamp
     * paces one worker, and eight workers each politely staying under the
     * limit still add up to eight times the limit. Every worker instead draws
     * its departure time from one queue of slots kept in the cache.
     */
    private function throttle(): void
    {
        $rpm  = max(1, (int) config('services.photoroom.rpm', 50));
        $gap  = 60 / $rpm;
        $slot = microtime(true);

        try {
            $lock = Cache::lock($this->cacheKey('pace-lock'), 10);

            if (!$lock->block(15)) {
                return;
            }

            try {
                $slot = max(microtime(true), (float) Cache::get($this->cacheKey('next-slot'), 0.0));

                Cache::put($this->cacheKey('next-slot'), $slot + $gap, now()->addMinutes(5));
            } finally {
                $lock->release();
            }
        } catch (\Throwable $e) {
            // Pacing is a courtesy to the API, not a correctness requirement.
            // A cache that is down should not fail edits that would succeed.
            Log::warning('Photoroom pacing unavailable: ' . $e->getMessage());

            return;
        }

        $wait = $slot - microtime(true);

        if ($wait > 0) {
            usleep((int) (min($wait, self::MAX_THROTTLE_WAIT) * 1_000_000));
        }
    }

    /**
     * Push every worker's next slot past a window Photoroom has just told us
     * is closed, so the fleet backs off together instead of one worker at a
     * time discovering it.
     */
    private function deferFleet(int $seconds): void
    {
        try {
            $until = microtime(true) + $seconds;
            $lock  = Cache::lock($this->cacheKey('pace-lock'), 10);

            if (!$lock->block(15)) {
                return;
            }

            try {
                if ((float) Cache::get($this->cacheKey('next-slot'), 0.0) < $until) {
                    Cache::put($this->cacheKey('next-slot'), $until, now()->addMinutes(5));
                }
            } finally {
                $lock->release();
            }
        } catch (\Throwable $e) {
            Log::warning('Photoroom fleet back-off failed: ' . $e->getMessage());
        }
    }

    /**
     * Remember that the account's quota is spent until a given moment.
     */
    private function closeQuota(int $seconds): void
    {
        try {
            Cache::put(
                $this->cacheKey('closed-until'),
                microtime(true) + $seconds,
                now()->addSeconds($seconds + 60),
            );
        } catch (\Throwable $e) {
            Log::warning('Photoroom quota marker could not be stored: ' . $e->getMessage());
        }
    }

    /**
     * Refuse a request the account has no quota left for.
     *
     * @throws \RuntimeException  while the quota is known to be spent
     */
    private function guardQuota(): void
    {
        try {
            $until = (float) Cache::get($this->cacheKey('closed-until'), 0.0);
        } catch (\Throwable $e) {
            return; // Nothing readable — let the request find out for itself.
        }

        $remaining = (int) ceil($until - microtime(true));

        if ($remaining > 0) {
            throw new \RuntimeException(
                'Photoroom quota is exhausted — available again in ' . self::describeWait($remaining) . '.'
            );
        }
    }

    /**
     * Rate limits and quotas belong to the key, so the pacing and the quota
     * marker do too. Scoping by key also means swapping a spent sandbox key
     * for a live one starts clean rather than inheriting hours of back-off.
     */
    private function cacheKey(string $suffix): string
    {
        return 'photoroom:' . substr(sha1($this->apiKey), 0, 12) . ':' . $suffix;
    }

    /**
     * How long Photoroom says to wait before asking again.
     *
     * The body carries the exact figure ("Expected available in 14782
     * seconds"); Retry-After is the fallback. The last resort guesses at the
     * per-minute window, which is the shorter of the two things it can be.
     */
    private function throttleWait(Response $response): int
    {
        if (preg_match('/available in (\d+(?:\.\d+)?) seconds?/i', $response->body(), $m)) {
            return (int) ceil((float) $m[1]);
        }

        $header = (int) $response->header('Retry-After');

        return $header > 0 ? $header : 60;
    }

    /** A wait a person can act on, rather than four figures of seconds. */
    private static function describeWait(int $seconds): string
    {
        if ($seconds < 120) {
            return "{$seconds}s";
        }

        $hours   = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return $hours > 0 ? "{$hours}h {$minutes}m" : "{$minutes}m";
    }

    /**
     * Read the cutout confidence Photoroom volunteers on every response.
     *
     * -1 is its way of saying it has no opinion (photos with people in them),
     * which is not the same as a confident 0 and must not be filed as one.
     */
    private function rememberUncertainty(Response $response): void
    {
        $raw = $response->header(self::UNCERTAINTY_HEADER);

        if ($raw === '' || $raw === null || !is_numeric($raw)) {
            return;
        }

        $score = (float) $raw;

        $this->lastUncertainty = $score < 0 ? null : min(1.0, $score);
    }

    private function describeError(string $body): string
    {
        $decoded = json_decode($body, true);

        if (is_array($decoded)) {
            $detail = $decoded['detail'] ?? $decoded['error'] ?? $decoded['message'] ?? null;

            if (is_array($detail)) {
                $detail = $detail['message'] ?? json_encode($detail);
            }

            if ($detail) {
                return (string) $detail;
            }
        }

        return trim(substr($body, 0, 300)) ?: 'no detail given';
    }
}
