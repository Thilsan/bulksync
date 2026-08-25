<?php

namespace Tests\Feature;

use App\Models\ProductRequest;
use App\Models\Store;
use App\Models\User;
use App\Notifications\ProductRequestBalanceMapped;
use App\Services\ProductRequestWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * What "nothing is outstanding" is allowed to mean.
 *
 * balanceSkus() answers how many SKUs are holding up the Cegid gate, so it is
 * nought on a website that has no gate. Read as "nothing is outstanding", that
 * mailed a brand manager "All 40 SKUs are mapped — ready to finish" over a table
 * saying 19 of 40, and wrote the same claim into the audit trail.
 */
class BalanceMappedCountTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->user = User::create([
            'name' => 'Marilyn Shieller', 'email' => 'marilyn@example.test',
            'password' => 'password', 'is_active' => true, 'perm_product_request' => true,
        ]);
    }

    private function request(bool $requiresMapping, int $mapped, int $total): ProductRequest
    {
        $store = Store::create([
            'name'                => $requiresMapping ? 'Bluesalon Website' : 'PG Website',
            'shopify_domain'      => ($requiresMapping ? 'blue' : 'pg') . '.myshopify.com',
            'is_active'           => true,
            'requires_sku_mapping' => $requiresMapping,
        ]);

        return ProductRequest::create([
            'reference'    => ProductRequest::nextReference(),
            'user_id'      => $this->user->id,
            'store_id'     => $store->id,
            'request_type' => 'new_brand',
            'brand'        => 'GUY LAROCHE',
            'category'     => 'Watches & Jewellery',
            'status'       => ProductRequest::SKU_VERIFIED,
            'priority'     => 'high',
            'total_skus'   => $total,
            'mapped_skus'  => $mapped,
        ]);
    }

    // ── The count itself ─────────────────────────────────────────────────────

    public function test_unmapped_skus_ignores_whether_the_website_has_a_mapping_step(): void
    {
        $this->assertSame(21, $this->request(true, 19, 40)->unmappedSkus());
        $this->assertSame(21, $this->request(false, 19, 40)->unmappedSkus());
    }

    public function test_balance_skus_keeps_answering_the_gate_question(): void
    {
        // Unchanged: nothing is holding up a gate that does not exist, which is
        // what the request screen and the mapping chaser read it for.
        $this->assertSame(21, $this->request(true, 19, 40)->balanceSkus());
        $this->assertSame(0, $this->request(false, 19, 40)->balanceSkus());
    }

    public function test_a_fully_mapped_request_has_nothing_outstanding_either_way(): void
    {
        $this->assertSame(0, $this->request(true, 40, 40)->unmappedSkus());
        $this->assertSame(0, $this->request(false, 40, 40)->unmappedSkus());
    }

    // ── The email ────────────────────────────────────────────────────────────

    public function test_a_part_mapped_request_is_not_announced_as_finished(): void
    {
        $request = $this->request(false, 19, 40);

        $notification = ProductRequestBalanceMapped::forRequest($request, 3);

        $this->assertFalse($notification->isComplete());
        $this->assertSame(21, $notification->remaining);
        $this->assertStringContainsString('3 more SKUs mapped — 19 of 40', $notification->toMail($this->user)->subject);
        $this->assertStringNotContainsString('ready to finish', $notification->toMail($this->user)->subject);
    }

    public function test_a_finished_request_still_says_so(): void
    {
        foreach ([true, false] as $requiresMapping) {
            $notification = ProductRequestBalanceMapped::forRequest($this->request($requiresMapping, 40, 40), 3);

            $this->assertTrue($notification->isComplete());
            $this->assertStringContainsString('All 40 SKUs are mapped', $notification->toMail($this->user)->subject);
        }
    }

    // ── The audit trail written by the same event ────────────────────────────

    public function test_the_audit_entry_agrees_with_the_email(): void
    {
        $request = $this->request(false, 19, 40);

        app(ProductRequestWorkflow::class)->announceBalance($request, 3);

        $entry = $request->activities()->latest('id')->first();

        $this->assertSame('3 more SKU(s) mapped — 19 of 40', $entry->description);
        $this->assertSame('21 still to map', $entry->remarks);
    }

    public function test_the_audit_entry_reports_a_real_finish(): void
    {
        $request = $this->request(false, 40, 40);

        app(ProductRequestWorkflow::class)->announceBalance($request, 3);

        $entry = $request->activities()->latest('id')->first();

        $this->assertSame('The balance is mapped — all 40 SKUs are ready', $entry->description);
    }
}
