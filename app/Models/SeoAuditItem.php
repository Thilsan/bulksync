<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoAuditItem extends Model
{
    /**
     * Shopify truncates the search-result title around 60 characters and the
     * description around 160, so anything past those is written but never read.
     * The lower bounds are softer — a 20-character meta description is not
     * broken, it is just wasting the only sentence Google will quote.
     */
    public const META_TITLE_MAX = 60;
    public const META_TITLE_MIN = 30;
    public const META_DESC_MAX  = 160;
    public const META_DESC_MIN  = 70;

    /** Body copy below this many characters of plain text reads as a stub. */
    public const THIN_DESCRIPTION = 200;

    /** The two kinds of page this audit grades. */
    public const TYPE_PRODUCT    = 'product';
    public const TYPE_COLLECTION = 'collection';

    /** The only Shopify status that puts a page on the storefront. */
    public const STATUS_ACTIVE = 'active';

    /**
     * What Shopify renders when a field is left unset.
     *
     * A product with no title_tag metafield still has a <title> — the theme
     * falls back to the product title, and to the description for the meta
     * description. So these issues never mean "this page has nothing"; they
     * mean nobody chose what it says, and the default is rarely what would
     * earn a click.
     */
    public function fallbackMetaTitle(): string
    {
        return (string) $this->product_title;
    }

    public function fallbackMetaDescription(): string
    {
        return (string) $this->description_excerpt;
    }

    /**
     * Every issue the audit can raise: the label shown to the merchant, and the
     * points knocked off a product's score. Weights are ordered by what actually
     * costs traffic — an absent meta title outranks a slightly long one, and a
     * duplicate title is worse than a short one because it makes two pages
     * compete with each other.
     */
    public const ISSUES = [
        'missing_meta_title'        => ['label' => 'No meta title set',          'weight' => 20, 'severity' => 'high'],
        'missing_meta_description'  => ['label' => 'No meta description set',    'weight' => 20, 'severity' => 'high'],
        'duplicate_meta_title'      => ['label' => 'Duplicate meta title',       'weight' => 15, 'severity' => 'high'],
        'no_images'                 => ['label' => 'No images',                  'weight' => 15, 'severity' => 'high'],
        'missing_alt_text'          => ['label' => 'Images without alt text',    'weight' => 12, 'severity' => 'medium'],
        'duplicate_meta_description'=> ['label' => 'Duplicate meta description', 'weight' => 10, 'severity' => 'medium'],
        'thin_description'          => ['label' => 'Thin description',           'weight' => 10, 'severity' => 'medium'],
        'meta_title_too_long'       => ['label' => 'Meta title over 60 chars',   'weight' => 8,  'severity' => 'medium'],
        'meta_description_too_long' => ['label' => 'Meta description over 160',  'weight' => 8,  'severity' => 'medium'],
        // Only the unambiguous title fault is graded. A minimum length was
        // tried and dropped: it flagged "Relaxed Linen Shirt" as a problem,
        // and a check that punishes good titles buries the real ones.
        'title_single_word'         => ['label' => 'One-word title',            'weight' => 8,  'severity' => 'medium'],
        'no_tags'                   => ['label' => 'No tags',                    'weight' => 5,  'severity' => 'low'],
        'meta_title_too_short'      => ['label' => 'Meta title under 30 chars',  'weight' => 4,  'severity' => 'low'],
        'meta_description_too_short'=> ['label' => 'Meta description under 70',  'weight' => 4,  'severity' => 'low'],
    ];

    protected $fillable = [
        'seo_audit_session_id', 'resource_type', 'status', 'product_id', 'product_title', 'handle', 'sku',
        'meta_title', 'meta_description', 'meta_title_length', 'meta_description_length',
        'description_length', 'description_excerpt', 'image_count', 'images_missing_alt', 'tag_count',
        'issues', 'issue_count', 'score',
    ];

    protected $casts = ['issues' => 'array'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(SeoAuditSession::class, 'seo_audit_session_id');
    }

    /** Human labels for this row's issue codes, for the table and the CSV. */
    public function issueLabels(): array
    {
        return array_map(
            fn (string $code) => self::ISSUES[$code]['label'] ?? $code,
            $this->issues ?? []
        );
    }

    public static function scoreFor(array $issues): int
    {
        $penalty = array_sum(array_map(
            fn (string $code) => self::ISSUES[$code]['weight'] ?? 0,
            $issues
        ));

        return (int) max(0, 100 - $penalty);
    }

    public static function label(string $code): string
    {
        return self::ISSUES[$code]['label'] ?? $code;
    }

    /** The public URL path, which is how Analytics and Search Console name it. */
    public function path(): string
    {
        $prefix = $this->resource_type === self::TYPE_COLLECTION ? '/collections/' : '/products/';

        return $prefix . $this->handle;
    }

    public function isCollection(): bool
    {
        return $this->resource_type === self::TYPE_COLLECTION;
    }

    /**
     * Whether a search engine can see this page at all.
     *
     * A null status is a collection, which has no such setting in Shopify and
     * is always reachable.
     */
    public function isLive(): bool
    {
        return $this->status === null || $this->status === self::STATUS_ACTIVE;
    }

    /** Only live pages are graded in the headline figures. */
    public function scopeLive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('status')->orWhere('status', self::STATUS_ACTIVE);
        });
    }

    public function scopeNotLive($query)
    {
        return $query->whereNotNull('status')->where('status', '<>', self::STATUS_ACTIVE);
    }
}
