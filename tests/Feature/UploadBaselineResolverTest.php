<?php

namespace Tests\Feature;

use App\Models\UploadSession;
use App\Services\UploadBaselineResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UploadBaselineResolverTest extends TestCase
{
    use RefreshDatabase;

    private UploadBaselineResolver $resolver;
    private UploadSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new UploadBaselineResolver();
        $this->session  = UploadSession::create([
            'onedrive_link' => 'https://example.test/folder',
            'matching_mode' => 'sku_barcode',
        ]);
    }

    public function test_the_verdict_is_probed_once_and_shared_by_every_sibling_file(): void
    {
        $probes = 0;

        // Three files of the same SKU folder, each asking in turn. Only the
        // first may reach Shopify — this is the bug that dropped files as
        // Already Has Image once a sibling's upload assigned the variant image.
        $verdicts = [];
        for ($i = 0; $i < 3; $i++) {
            $verdicts[] = $this->resolve('42', function () use (&$probes) {
                $probes++;
                return false;
            });
        }

        $this->assertSame(1, $probes, 'the SKU must be probed exactly once per session');
        $this->assertSame([false, false, false], $verdicts);
    }

    public function test_a_sibling_is_not_told_the_sku_has_an_image_just_because_one_was_added_after_the_first_probe(): void
    {
        $shopifyHasImage = false;

        $first = $this->resolve('42', function () use (&$shopifyHasImage) {
            return $shopifyHasImage;
        });

        // The first file uploads and assigns the variant image.
        $shopifyHasImage = true;

        $second = $this->resolve('42', fn () => $shopifyHasImage);

        $this->assertFalse($first);
        $this->assertFalse($second, 'the frozen verdict must survive the folder\'s own uploads');
    }

    public function test_an_existing_image_skips_every_file_in_the_folder(): void
    {
        $this->assertTrue($this->resolve('42', fn () => true));
        $this->assertTrue($this->resolve('42', fn () => throw new \LogicException('must not re-probe')));
    }

    public function test_each_sku_is_decided_independently(): void
    {
        $this->assertTrue($this->resolve('42', fn () => true));
        $this->assertFalse($this->resolve('99', fn () => false));
    }

    public function test_a_failed_probe_leaves_no_claim_behind_so_the_retry_can_ask_again(): void
    {
        try {
            $this->resolve('42', fn () => throw new \RuntimeException('Shopify timed out'));
            $this->fail('the probe failure should have propagated');
        } catch (\RuntimeException $e) {
            $this->assertSame('Shopify timed out', $e->getMessage());
        }

        $this->assertDatabaseCount('upload_sku_baselines', 0);

        // The queue retry gets a real answer rather than waiting out a verdict
        // that is never coming.
        $this->assertTrue($this->resolve('42', fn () => true));
    }

    public function test_the_variant_main_image_slot_is_claimed_by_exactly_one_file(): void
    {
        $this->resolve('42', fn () => false);

        $claims = [
            $this->resolver->claimVariantImageSlot($this->session->id, '42'),
            $this->resolver->claimVariantImageSlot($this->session->id, '42'),
            $this->resolver->claimVariantImageSlot($this->session->id, '42'),
        ];

        $this->assertSame([true, false, false], $claims);
    }

    public function test_releasing_the_slot_lets_the_next_file_take_it(): void
    {
        $this->resolve('42', fn () => false);

        $this->assertTrue($this->resolver->claimVariantImageSlot($this->session->id, '42'));

        // The claiming file's upload failed, so the variant would otherwise be
        // left with no main image at all.
        $this->resolver->releaseVariantImageSlot($this->session->id, '42');

        $this->assertTrue($this->resolver->claimVariantImageSlot($this->session->id, '42'));
    }

    public function test_a_seeded_verdict_is_used_without_probing(): void
    {
        $this->resolver->seed(
            $this->session->id,
            UploadBaselineResolver::SCOPE_VARIANT,
            '42',
            hasExistingImage: false,
            variantImageClaimed: true,
        );

        $this->assertFalse($this->resolve('42', fn () => throw new \LogicException('must not probe')));
        $this->assertFalse(
            $this->resolver->claimVariantImageSlot($this->session->id, '42'),
            'a seeded claim must stay with the sibling that already holds it',
        );
    }

    public function test_verdicts_do_not_leak_between_sessions(): void
    {
        $other = UploadSession::create([
            'onedrive_link' => 'https://example.test/other',
            'matching_mode' => 'sku_barcode',
        ]);

        $this->assertFalse($this->resolve('42', fn () => false));

        $this->assertTrue($this->resolver->resolve(
            $other->id,
            UploadBaselineResolver::SCOPE_VARIANT,
            '42',
            fn () => true,
        ));
    }

    private function resolve(string $scopeId, \Closure $probe): bool
    {
        return $this->resolver->resolve(
            $this->session->id,
            UploadBaselineResolver::SCOPE_VARIANT,
            $scopeId,
            $probe,
        );
    }
}
