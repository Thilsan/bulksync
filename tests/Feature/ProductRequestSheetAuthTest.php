<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\OneDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The tracking sheet signs into its own Azure app, under the account that owns
 * the sheet — separate from the shared credentials every other OneDrive feature
 * runs on, so rotating either cannot break the other.
 */
class ProductRequestSheetAuthTest extends TestCase
{
    use RefreshDatabase;

    private const PROFILE = OneDriveService::PRODUCT_REQUEST_PROFILE;

    private function admin(bool $superAdmin = true): User
    {
        return User::create([
            'name'           => $superAdmin ? 'Super Admin' : 'Ordinary',
            'email'          => ($superAdmin ? 'super' : 'ordinary') . '@example.test',
            'password'       => 'password',
            'is_active'      => true,
            'is_super_admin' => $superAdmin,
        ]);
    }

    public function test_the_sheet_app_is_saved_separately_from_the_shared_one(): void
    {
        Setting::set('onedrive_client_id', 'shared-app');
        Setting::set('onedrive_tenant_id', 'shared-tenant');

        $this->actingAs($this->admin())
            ->put(route('settings.sheet-app'), [
                'pcr_onedrive_tenant_id'     => 'their-tenant',
                'pcr_onedrive_client_id'     => 'their-app',
                'pcr_onedrive_client_secret' => 'their-secret',
            ])
            ->assertRedirect();

        $this->assertSame('their-app', Setting::get(self::PROFILE . '_client_id'));
        $this->assertSame('their-tenant', Setting::get(self::PROFILE . '_tenant_id'));

        // The shared app is untouched — that is the whole point of the split.
        $this->assertSame('shared-app', Setting::get('onedrive_client_id'));
        $this->assertSame('shared-tenant', Setting::get('onedrive_tenant_id'));
    }

    /** A blank secret means "keep the saved one" — it is never shown back to be retyped. */
    public function test_a_blank_secret_keeps_the_saved_one(): void
    {
        Setting::set(self::PROFILE . '_client_id', 'their-app');
        Setting::set(self::PROFILE . '_client_secret', 'saved-secret');

        $this->actingAs($this->admin())
            ->put(route('settings.sheet-app'), [
                'pcr_onedrive_client_id'     => 'their-app',
                'pcr_onedrive_client_secret' => '',
            ])
            ->assertRedirect();

        $this->assertSame('saved-secret', Setting::get(self::PROFILE . '_client_secret'));
    }

    /**
     * A token consented under one app is worthless to another, so changing the
     * app drops the connection rather than leaving the next sync to fail.
     */
    public function test_changing_the_app_drops_the_stored_connection(): void
    {
        Setting::set(self::PROFILE . '_client_id', 'old-app');
        Setting::set(self::PROFILE . '_refresh_token', 'old-token');
        Setting::set(self::PROFILE . '_account', 'shahid@example.test');

        $this->actingAs($this->admin())
            ->put(route('settings.sheet-app'), ['pcr_onedrive_client_id' => 'new-app'])
            ->assertRedirect()
            ->assertSessionHas('warning');

        // Cleared, however the settings table spells empty — the app checks filled().
        $this->assertTrue(blank(Setting::get(self::PROFILE . '_refresh_token')));
        $this->assertTrue(blank(Setting::get(self::PROFILE . '_account')));
    }

    /**
     * Azure shows the secret's ID and its value side by side, and the value only
     * once — so the ID is what is still on screen later. Microsoft answers that
     * with AADSTS7000215 at sign-in time; catching it at save time is kinder.
     */
    public function test_a_secret_id_pasted_as_the_secret_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->put(route('settings.sheet-app'), [
                'pcr_onedrive_client_id'     => 'their-app',
                'pcr_onedrive_client_secret' => 'd4c1a2b3-5e6f-7a8b-9c0d-1e2f3a4b5c6d',
            ])
            ->assertSessionHasErrors('pcr_onedrive_client_secret');

        $this->assertTrue(blank(Setting::get(self::PROFILE . '_client_secret')));
    }

    /** A real secret value is nothing like a GUID, and is saved untouched. */
    public function test_a_real_secret_value_is_accepted(): void
    {
        $this->actingAs($this->admin())
            ->put(route('settings.sheet-app'), [
                'pcr_onedrive_client_id'     => 'their-app',
                'pcr_onedrive_client_secret' => '  abc8Q~Zx1.9_kLmN-OpQrStUvWxYz0123456789  ',
            ])
            ->assertRedirect();

        // Trimmed: a stray space copied out of the portal is not part of the secret.
        $this->assertSame('abc8Q~Zx1.9_kLmN-OpQrStUvWxYz0123456789', Setting::get(self::PROFILE . '_client_secret'));
    }

    public function test_only_a_super_admin_can_touch_the_sheet_app(): void
    {
        $user = $this->admin(superAdmin: false);

        $this->actingAs($user)->put(route('settings.sheet-app'), ['pcr_onedrive_client_id' => 'x'])->assertForbidden();
        $this->actingAs($user)->get(route('product-request-sheet.auth.redirect'))->assertForbidden();
        $this->actingAs($user)->post(route('product-request-sheet.auth.disconnect'))->assertForbidden();
    }

    public function test_connecting_needs_the_client_id_first(): void
    {
        $this->actingAs($this->admin())
            ->get(route('product-request-sheet.auth.redirect'))
            ->assertRedirect(route('settings.index'))
            ->assertSessionHasErrors();
    }

    public function test_the_sign_in_goes_to_the_sheet_apps_own_tenant(): void
    {
        Setting::set(self::PROFILE . '_client_id', 'their-app');
        Setting::set(self::PROFILE . '_tenant_id', 'their-tenant');
        Setting::set('onedrive_client_id', 'shared-app');

        $response = $this->actingAs($this->admin())->get(route('product-request-sheet.auth.redirect'));

        $target = $response->headers->get('Location');

        $this->assertStringContainsString('login.microsoftonline.com/their-tenant/', $target);
        $this->assertStringContainsString('client_id=their-app', $target);
        $this->assertStringNotContainsString('shared-app', $target, 'It must never sign in through the shared app.');
    }

    public function test_disconnecting_clears_the_token_but_keeps_the_app(): void
    {
        Setting::set(self::PROFILE . '_client_id', 'their-app');
        Setting::set(self::PROFILE . '_refresh_token', 'a-token');
        Setting::set(self::PROFILE . '_account', 'shahid@example.test');

        $this->actingAs($this->admin())
            ->post(route('product-request-sheet.auth.disconnect'))
            ->assertRedirect();

        $this->assertTrue(blank(Setting::get(self::PROFILE . '_refresh_token')));
        $this->assertSame('their-app', Setting::get(self::PROFILE . '_client_id'), 'Reconnecting should not need retyping the app.');
    }

    /** Nothing to sync with says so plainly, rather than failing on a token call. */
    public function test_the_sync_says_which_setting_is_missing(): void
    {
        $drive = new OneDriveService();
        $drive->asServiceAccount();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no Azure app configured/');

        $drive->resolveShareItem('https://example.test/sheet.xlsx');
    }

    public function test_an_unconnected_sheet_app_says_to_sign_in(): void
    {
        Setting::set(self::PROFILE . '_client_id', 'their-app');
        Setting::set(self::PROFILE . '_client_secret', 'their-secret');

        $drive = new OneDriveService();
        $drive->asServiceAccount();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Connect the sheet account/');

        $drive->resolveShareItem('https://example.test/sheet.xlsx');
    }
}
