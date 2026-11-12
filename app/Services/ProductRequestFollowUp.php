<?php

namespace App\Services;

use App\Models\ProductRequest;
use App\Models\User;

/**
 * Going ahead with the mapped SKUs, and coming back for the rest.
 *
 * When a Cegid website has mapped only part of a request, the team carries on
 * with what is mapped. The SKUs that are not are set aside: taken off the
 * request and kept on its record (left_out_skus), so the request runs through
 * every stage as an ordinary, fully mapped one — nothing downstream has to
 * know about a balance. Once it is published, the set-aside SKUs are offered
 * back as a new request of their own, which starts again from the mapping.
 *
 * A set-aside SKU is recorded as ['sku', 'reason' => 'set_aside', 'at'], and
 * gains 'moved_to' => the new request's reference once it has gone on.
 */
class ProductRequestFollowUp
{
    public function __construct(
        private readonly ProductRequestWorkflow $workflow,
        private readonly SkuMappingService $mapping,
    ) {}

    /**
     * Take every SKU not mapped yet off the request and onto its record.
     *
     * @return list<string> the SKUs set aside
     */
    public function setAside(ProductRequest $request): array
    {
        $skus = $request->skus()
            ->where('mapping_status', '!=', ProductRequest::MAP_MAPPED)
            ->orderBy('id')
            ->pluck('sku')
            ->all();

        if ($skus === []) {
            return [];
        }

        $at = now()->toIso8601String();

        $request->update(['left_out_skus' => array_merge(
            $request->left_out_skus ?? [],
            array_map(fn ($sku) => ['sku' => $sku, 'reason' => ProductRequest::LEFT_SET_ASIDE, 'at' => $at], $skus),
        )]);

        foreach (array_chunk($skus, 500) as $chunk) {
            $request->skus()->whereIn('sku', $chunk)->delete();
        }

        $this->recount($request);

        return $skus;
    }

    /**
     * Raise a new request for the SKUs a published one never put live, on the
     * same website, brand and category, and mark them moved on the original.
     *
     * @param  array{name?: string|null, online_launch_date: string}  $data
     */
    public function start(ProductRequest $original, User $actor, array $data): ProductRequest
    {
        $skus  = $original->followUpSkus();
        $photo = $original->image_source === ProductRequest::IMG_PHOTOSHOOT;

        $new = ProductRequest::create([
            'reference'                 => ProductRequest::nextReference(),
            'name'                      => filled($data['name'] ?? null) ? $data['name'] : $original->displayName() . ' — remaining SKUs',
            // Still the requester's ask, so it stays on their list.
            'user_id'                   => $original->user_id,
            'store_id'                  => $original->store_id,
            'request_type'              => $original->request_type,
            'brand'                     => $original->brand,
            'category'                  => $original->category,
            'status'                    => ProductRequest::SUBMITTED,
            'priority'                  => $original->priority,
            'online_launch_date'        => $data['online_launch_date'],
            'image_source'              => $original->image_source,
            'images_location'           => $original->images_location,
            'images_url'                => $original->images_url,
            'supplier_images_available' => $original->image_source === ProductRequest::IMG_SUPPLIER,
            'photoshoot_required'       => $photo,
            // Already decided on the original; an old request that never was
            // is asked again, as it would have been.
            'photoshoot_decision'       => $original->image_source ? ($photo ? 'yes' : 'no') : null,
            'photoshoot_decided_at'     => $original->image_source ? now() : null,
            'photoshoot_status'         => $photo ? ProductRequest::SHOOT_PENDING : null,
            'use_ai_content'            => $original->use_ai_content,
            'notes'                     => "Follow-up of {$original->reference}: the SKUs that were not mapped in Cegid when it went ahead.",
            'validation_status'         => 'skipped',
            'total_skus'                => count($skus),
        ]);

        $this->mapping->syncSkus($new, $skus);

        $this->workflow->log(
            request:     $new,
            action:      'created',
            description: 'Product request created',
            actor:       $actor,
            toStatus:    ProductRequest::SUBMITTED,
            remarks:     count($skus) . " SKUs carried over from {$original->reference}, where they were not mapped in Cegid when it went ahead",
        );

        $this->markMoved($original, $skus, $new->reference);

        $this->workflow->log(
            request:     $original,
            action:      'follow_up',
            description: "Started {$new->reference} for the " . count($skus) . ' SKU(s) not mapped',
            actor:       $actor,
        );

        // From here it is any new request: staffed from its category, then
        // parked on the mapping until the brand manager has done it.
        $this->workflow->staffFromCategory($new, $actor);
        $this->mapping->rollUp($new);
        $this->workflow->reconcileMapping($new->refresh(), $actor);

        return $new->refresh();
    }

    /**
     * Record on the original where its leftover SKUs went. One still on it
     * unmapped — carried on with before set-aside existed — comes off it too.
     */
    private function markMoved(ProductRequest $original, array $skus, string $reference): void
    {
        $moving = collect($skus)->flip();

        $leftOut = array_map(
            fn ($row) => in_array($row['reason'] ?? null, ProductRequest::FOLLOW_UP_REASONS, true)
                && empty($row['moved_to']) && $moving->has($row['sku'])
                ? $row + ['moved_to' => $reference]
                : $row,
            $original->left_out_skus ?? [],
        );

        $onIt = $original->skus()->whereIn('sku', $skus)->pluck('sku')->all();
        $at   = now()->toIso8601String();

        foreach ($onIt as $sku) {
            $leftOut[] = ['sku' => $sku, 'reason' => ProductRequest::LEFT_SET_ASIDE, 'at' => $at, 'moved_to' => $reference];
        }

        $original->update(['left_out_skus' => $leftOut ?: null]);

        if ($onIt !== []) {
            foreach (array_chunk($onIt, 500) as $chunk) {
                $original->skus()->whereIn('sku', $chunk)->delete();
            }

            $this->recount($original);
        }
    }

    /**
     * The counts from the rows left. Not SkuMappingService::rollUp(), which
     * also stamps the request as checked — and nothing was checked here.
     */
    private function recount(ProductRequest $request): void
    {
        $counts = $request->skus()
            ->selectRaw('mapping_status, COUNT(*) as aggregate')
            ->groupBy('mapping_status')
            ->pluck('aggregate', 'mapping_status');

        $mapped    = (int) ($counts[ProductRequest::MAP_MAPPED] ?? 0);
        $pending   = (int) ($counts[ProductRequest::MAP_PENDING] ?? 0);
        $notMapped = (int) ($counts[ProductRequest::MAP_NOT_MAPPED] ?? 0);

        $request->update([
            'total_skus'      => $mapped + $pending + $notMapped,
            'mapped_skus'     => $mapped,
            'pending_skus'    => $pending,
            'not_mapped_skus' => $notMapped,
        ]);
    }
}
