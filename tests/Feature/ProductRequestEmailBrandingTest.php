<?php

namespace Tests\Feature;

use App\Models\ProductRequest;
use App\Models\ProductRequestSku;
use App\Models\Store;
use App\Models\User;
use App\Notifications\ProductRequestMappingNeeded;
use App\Notifications\ProductRequestPhotosNeeded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * Every Product Creation Request email has to arrive in the department's own
 * shell.
 *
 * A notification that builds a MailMessage out of ->greeting()/->line() renders
 * in Laravel's stock template instead — Laravel logo, "Regards, Laravel" — and
 * nothing about it looks broken from the code, so it ships and only the
 * recipient finds out. Two of these had been sending that way. The structural
 * check below is the one that catches the next one.
 */
class ProductRequestEmailBrandingTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        return User::create([
            'name'                 => 'Brand Manager',
            'email'                => 'brand@example.test',
            'password'             => 'password',
            'is_active'            => true,
            'perm_product_request' => true,
            'pcr_role'             => 'brand_manager',
        ]);
    }

    private function request(): ProductRequest
    {
        $store = Store::create([
            'name'                 => 'Bluesalon Website',
            'shopify_domain'       => 'qatarbluesalon.myshopify.com',
            'is_active'            => true,
            'requires_sku_mapping' => true,
        ]);

        $request = ProductRequest::create([
            'reference'    => ProductRequest::nextReference(),
            'user_id'      => $this->manager()->id,
            'store_id'     => $store->id,
            'request_type' => 'new_brand',
            'brand'        => 'ALBERTO',
            'category'     => "Men's Fashion",
            'status'       => ProductRequest::WAITING_MAPPING,
            'priority'     => 'medium',
            'total_skus'   => 2,
            'mapped_skus'  => 1,
            'pending_skus' => 1,
        ]);

        foreach ([['A-1', true], ['A-2', false]] as [$sku, $mapped]) {
            ProductRequestSku::create([
                'product_request_id' => $request->id,
                'sku'                => $sku,
                'mapping_status'     => $mapped ? ProductRequest::MAP_MAPPED : ProductRequest::MAP_PENDING,
                'in_shopify'         => $mapped,
            ]);
        }

        return $request;
    }

    public function test_the_mapping_request_arrives_in_the_branded_shell(): void
    {
        $request = $this->request();
        $manager = $request->user;

        $mail = ProductRequestMappingNeeded::forRequest($request)->toMail($manager);
        $html = $mail->render();

        // The outstanding SKUs still travel as a file that can be worked from.
        $this->assertSame(
            ["{$request->reference}-needs-mapping.csv"],
            array_column($mail->rawAttachments, 'name'),
        );
        $this->assertStringContainsString('A-2', $mail->rawAttachments[0]['data']);

        $this->assertStringContainsString('AI E-Commerce Studio', $html);
        $this->assertStringContainsString('Abuissa Holding', $html);
        $this->assertStringNotContainsString('Regards,<br>', $html);

        // The facts the brand manager is being asked to act on.
        $this->assertStringContainsString('1 of 2', $html);
        $this->assertStringContainsString('1 SKU still to map', $html);
        $this->assertStringContainsString($request->reference, $html);
    }

    public function test_the_photo_request_arrives_in_the_branded_shell(): void
    {
        $request = $this->request();
        $manager = $request->user;

        $html = ProductRequestPhotosNeeded::forRequest($request, 'Nada Rezeg')
            ->toMail($manager)
            ->render();

        $this->assertStringContainsString('AI E-Commerce Studio', $html);
        $this->assertStringContainsString('Samples for 2 SKUs', $html);
        $this->assertStringContainsString('Nada Rezeg', $html);
    }

    /**
     * No request notification may fall back to the stock Laravel template.
     */
    public function test_every_request_email_declares_a_branded_view(): void
    {
        $files = glob(app_path('Notifications/ProductRequest*.php'));

        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $name   = basename($file);

            $this->assertMatchesRegularExpression(
                "/->view\('(emails\.product-request\.[a-z-]+)'/",
                $source,
                "{$name} builds its mail without a branded view, so it would send as Laravel's default.",
            );

            preg_match("/->view\('(emails\.product-request\.[a-z-]+)'/", $source, $m);

            $this->assertTrue(
                View::exists($m[1]),
                "{$name} points at the missing view {$m[1]}.",
            );
        }
    }
}
