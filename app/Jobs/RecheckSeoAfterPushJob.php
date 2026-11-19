<?php

namespace App\Jobs;

use App\Models\AiContentItem;
use App\Models\AiContentSession;
use App\Models\SeoAuditItem;
use App\Models\SeoAuditSession;
use App\Models\Store;
use App\Services\ShopifyService;
use App\Support\SeoGrader;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * After an AI content push, reads the pushed products back from Shopify and
 * grades them by the SEO audit's own rules.
 *
 * What went live is checked, not assumed. A push can report success while a
 * product still fails the audit — it has no photographs, its title is one word
 * and was left alone, two products were written the same meta title — and
 * nobody would know until the next full audit. Now the batch gets its own
 * before-and-after, and the audit it is compared against is brought up to date
 * for those products in place, so the audit page stops listing work already
 * done.
 */
class RecheckSeoAfterPushJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;
    public int $tries   = 1;

    /** The products a summary names as still needing work. More is a CSV's job. */
    private const MAX_LISTED = 50;

    public function __construct(public readonly int $sessionId) {}

    public function handle(): void
    {
        $session = AiContentSession::find($this->sessionId);
        if (!$session) return;

        try {
            $session->update(['seo_recheck' => $this->recheck($session)]);
        } catch (\Throwable $e) {
            // The push itself succeeded; a failed check must not make it look
            // otherwise. Say the check did not run, and finish.
            Log::error('RecheckSeoAfterPushJob failed', ['session' => $this->sessionId, 'error' => $e->getMessage()]);
            $session->update(['seo_recheck' => ['error' => $e->getMessage(), 'checked_at' => now()->toIso8601String()]]);
        }

        $session->update(['status' => 'done']);
    }

    public function failed(?\Throwable $e): void
    {
        AiContentSession::where('id', $this->sessionId)
            ->where('status', 'rechecking')
            ->update(['status' => 'done']);
    }

    private function recheck(AiContentSession $session): array
    {
        $items = AiContentItem::where('session_id', $session->id)
            ->where('status', 'pushed')
            ->whereNotNull('shopify_product_id')
            ->get();

        $session->update(['total_items' => $items->count(), 'processed_items' => 0]);

        $audit  = $this->auditFor($session);
        $before = $audit
            ? $audit->items()->where('resource_type', SeoAuditItem::TYPE_PRODUCT)
                ->whereIn('product_id', $items->pluck('shopify_product_id')->map(fn ($id) => (string) $id)->all())
                ->get()->keyBy('product_id')
            : collect();

        $live = $this->shopifyFor(Store::find($session->store_id))
            ->getProductsForSeoAudit($items->pluck('shopify_product_id')->all());

        // Grade and write back first, duplicates after: a duplicate is only
        // decided once every changed product holds its new wording.
        $touched = ['meta_title' => [], 'meta_description' => []];
        $updated = [];

        foreach ($items as $item) {
            $product = $live[(string) $item->shopify_product_id] ?? null;
            $row     = $before[(string) $item->shopify_product_id] ?? null;

            if ($product && $row) {
                $graded = SeoGrader::product($product);

                $touched['meta_title'][]       = $row->meta_title;
                $touched['meta_title'][]       = $graded['meta_title'];
                $touched['meta_description'][] = $row->meta_description;
                $touched['meta_description'][] = $graded['meta_description'];

                // Remembered before the row is overwritten — it is the "before".
                // Only the first time: a session pushed twice still compares
                // against the page as it was before any AI copy reached it.
                if ($item->seo_score_before === null) {
                    $item->forceFill(['seo_score_before' => $row->score, 'seo_issues_before' => $row->issues ?? []]);
                }

                $row->update(collect($graded)->except(['resource_type', 'product_id'])->all());
                $updated[] = $row->product_id;
            } elseif ($product) {
                $graded = SeoGrader::product($product);
                $item->forceFill(['seo_score_after' => $graded['score'], 'seo_issues_after' => $graded['issues']]);
            }

            $item->save();
            $session->increment('processed_items');
        }

        if ($audit) {
            foreach ($touched as $column => $values) {
                $audit->refreshDuplicateFlags($column, $values);
            }

            // The "after" read once duplicates are settled, so a product that now
            // clashes with another is reported as such rather than as clean.
            $after = $audit->items()->whereIn('product_id', $updated)->get()->keyBy('product_id');
            foreach ($items as $item) {
                if ($row = $after[(string) $item->shopify_product_id] ?? null) {
                    $item->update(['seo_score_after' => $row->score, 'seo_issues_after' => $row->issues ?? []]);
                }
            }

            $audit->recount();
            $audit->update([
                'rechecked_at'       => now(),
                'rechecked_products' => $audit->rechecked_products + $after->count(),
            ]);
        }

        return $this->summarise($items->fresh(), $audit, count($live));
    }

    /**
     * The audit to compare against: the one the batch was made from, else the
     * store's most recent finished one. None at all is fine — the batch is still
     * graded, it just has no "before" and duplicates cannot be judged.
     */
    private function auditFor(AiContentSession $session): ?SeoAuditSession
    {
        if ($session->seo_audit_session_id) {
            $linked = SeoAuditSession::where('id', $session->seo_audit_session_id)->where('status', 'completed')->first();
            if ($linked) return $linked;
        }

        return SeoAuditSession::where('store_id', $session->store_id)
            ->where('status', 'completed')
            ->latest('id')
            ->first();
    }

    private function summarise($items, ?SeoAuditSession $audit, int $found): array
    {
        $graded   = $items->whereNotNull('seo_score_after');
        $compared = $graded->whereNotNull('seo_score_before');
        $failing  = $graded->filter(fn ($i) => !empty($i->seo_issues_after));

        $remaining = [];
        foreach ($failing as $item) {
            foreach ($item->seo_issues_after as $code) {
                $remaining[$code] = ($remaining[$code] ?? 0) + 1;
            }
        }
        // Heaviest first, the order the audit lists them in.
        $remaining = collect(SeoAuditItem::ISSUES)->keys()
            ->filter(fn ($code) => isset($remaining[$code]))
            ->mapWithKeys(fn ($code) => [$code => $remaining[$code]])
            ->all();

        $fixed = 0;
        foreach ($compared as $item) {
            $fixed += count(array_diff($item->seo_issues_before ?? [], $item->seo_issues_after ?? []));
        }

        return [
            'checked_at'     => now()->toIso8601String(),
            'audit_id'       => $audit?->id,
            'pushed'         => $items->count(),
            'checked'        => $graded->count(),
            // Pushed, but gone from Shopify by the time it was read back.
            'not_found'      => max(0, $items->count() - $found),
            'compared'       => $compared->count(),
            'score_before'   => $compared->isEmpty() ? null : (int) round($compared->avg('seo_score_before')),
            'score_after'    => $graded->isEmpty() ? null : (int) round($graded->avg('seo_score_after')),
            'clean_before'   => $compared->filter(fn ($i) => empty($i->seo_issues_before))->count(),
            'clean_after'    => $graded->count() - $failing->count(),
            'issues_fixed'   => $fixed,
            'still_failing'  => $failing->count(),
            'remaining'      => $remaining,
            'failing_items'  => $failing->sortBy('seo_score_after')->take(self::MAX_LISTED)->map(fn ($i) => [
                'id'     => $i->id,
                'sku'    => $i->sku,
                'title'  => $i->product_title,
                'before' => $i->seo_score_before,
                'after'  => $i->seo_score_after,
                'issues' => array_map(fn ($c) => SeoAuditItem::label($c), $i->seo_issues_after),
            ])->values()->all(),
        ];
    }

    /** Seam: overridden in tests so nothing reaches Shopify. */
    protected function shopifyFor(?Store $store): ShopifyService
    {
        return app(ShopifyService::class, ['store' => $store]);
    }
}
