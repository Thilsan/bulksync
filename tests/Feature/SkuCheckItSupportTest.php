<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\SkuCheckSession;
use App\Models\Store;
use App\Models\User;
use App\Notifications\ProductRequestItSupportMapping;
use App\Notifications\SkuCheckItSupport;
use App\Services\MailConfigurator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** A finished check with SKUs missing can be sent to IT support, list attached. */
class SkuCheckItSupportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Checker', 'email' => 'checker@example.test',
            'password' => 'password', 'is_active' => true,
        ]);

        // Mail is on, so the send is real rather than refused.
        Setting::set('mail_enabled', '1');
        Setting::set('mail_host', 'send.smtp.com');
        MailConfigurator::flush();
        MailConfigurator::apply();
    }

    protected function tearDown(): void
    {
        foreach (glob(storage_path('app/sku-checks/*.csv')) as $file) {
            unlink($file);
        }

        parent::tearDown();
    }

    private function check(string $csv, int $notMapped = 2): SkuCheckSession
    {
        $store   = Store::create(['name' => 'Bluesalon', 'shopify_domain' => 'bluesalon.myshopify.com', 'is_active' => true]);
        $session = SkuCheckSession::create([
            'user_id' => $this->user->id, 'store_id' => $store->id,
            'status'  => 'completed', 'total_skus' => 3,
            'scanned_skus' => 3, 'available_count' => 3 - $notMapped, 'not_available_count' => $notMapped,
        ]);

        $dir = storage_path('app/sku-checks');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents("{$dir}/{$session->id}.csv", $csv);

        return $session;
    }

    public function test_the_page_offers_it_support_the_skus_the_check_could_not_find(): void
    {
        Setting::set(ProductRequestItSupportMapping::SETTING, 'it@example.test');
        $check = $this->check("SKU,Status,Product ID,Title,Published\nGAT-1,Available,1,Bag,TRUE\nGaay,Not Available,,,\nhbdq,Not Available,,,\n");

        $this->actingAs($this->user)->get(route('sku-checker.show', $check))
            ->assertOk()
            ->assertSee('Email IT support (2 not mapped)')
            ->assertViewHas('itSupport', fn ($it) => $it['skus'] === ['Gaay', 'hbdq']
                && $it['to'] === 'it@example.test'
                && $it['subject'] === "[SKU check #{$check->id}] 2 SKUs are not mapped in Cegid for Bluesalon"
                && str_contains($it['message'], 'The 2 SKUs listed below are not mapped in Cegid for Bluesalon'));

        // Everything found: nothing to send.
        $all = $this->check("SKU,Status\nGAT-1,Available\n", 0);
        $this->actingAs($this->user)->get(route('sku-checker.show', $all))
            ->assertOk()->assertDontSee('Email IT support')->assertViewHas('itSupport', fn ($it) => $it === null);
    }

    public function test_it_support_gets_the_note_and_the_not_mapped_csv(): void
    {
        Notification::fake();
        $check = $this->check("SKU,Status\nGAT-1,Available\nGaay,Not Available\nhbdq,Not Available\n");

        $this->actingAs($this->user)->post(route('sku-checker.it-support', $check), [
            'it_support_to'      => 'it@example.test, desk@example.test',
            'it_support_message' => "Please map these.\nThanks",
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $sent = null;
        Notification::assertSentOnDemand(SkuCheckItSupport::class, function ($n, $channels, $notifiable) use (&$sent) {
            $sent = $n;

            return $channels === ['mail'] && $notifiable->routes['mail'] === ['it@example.test', 'desk@example.test'];
        });

        $this->assertSame(['Gaay', 'hbdq'], $sent->skus);

        $mail = $sent->toMail(new AnonymousNotifiable());
        $html = (string) $mail->render();

        $this->assertSame("[SKU check #{$check->id}] 2 SKUs are not mapped in Cegid for Bluesalon", $mail->subject);
        $this->assertSame([['checker@example.test', 'Checker']], $mail->replyTo);
        $this->assertSame([], $mail->cc);
        $this->assertStringContainsString('Please map these.<br />', $html);
        $this->assertStringContainsString('from the SKU Checker', $html);
        // Nothing to click into: IT support works from the attachment.
        $this->assertStringNotContainsString(route('sku-checker.show', $check), $html);
        $this->assertStringNotContainsString('Manage everything at', $html);
        $this->assertStringContainsString('The full list is in the attached CSV', $html);
        $this->assertSame("sku-check-{$check->id}-not-mapped.csv", $mail->rawAttachments[0]['name']);
        $this->assertStringContainsString("Gaay,\"Not mapped\"", $mail->rawAttachments[0]['data']);
        $this->assertStringNotContainsString('GAT-1', $mail->rawAttachments[0]['data']);
    }

    public function test_a_bad_address_or_someone_elses_check_is_refused(): void
    {
        Notification::fake();
        $check = $this->check("SKU,Status\nGaay,Not Available\n", 1);

        $this->actingAs($this->user)->post(route('sku-checker.it-support', $check), [
            'it_support_to'      => 'it support',
            'it_support_message' => 'Please map these.',
        ])->assertSessionHasErrors('it_support_to');

        $other = User::create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'password', 'is_active' => true]);
        $this->actingAs($other)->post(route('sku-checker.it-support', $check), [
            'it_support_to'      => 'it@example.test',
            'it_support_message' => 'Please map these.',
        ])->assertForbidden();

        Notification::assertSentOnDemandTimes(SkuCheckItSupport::class, 0);
    }
}
