<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\MailConfigurator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::create([
            'name'           => 'Super Admin',
            'email'          => 'super@example.test',
            'password'       => 'password',
            'is_active'      => true,
            'is_super_admin' => true,
        ]);
    }

    public function test_smtp_settings_saved_in_the_ui_override_the_env_config(): void
    {
        $admin = $this->superAdmin();

        $this->assertSame('Server .env', MailConfigurator::effective()['source']);

        $this->actingAs($admin)->put(route('settings.mail.update'), [
            'mail_enabled'      => 1,
            'mail_host'         => 'send.smtp.com',
            'mail_port'         => 2525,
            'mail_username'     => 'ecommerce@abuissa.com',
            'mail_password'     => 'sekrit',
            'mail_scheme'       => 'auto',
            'mail_from_address' => 'ecommerce@abuissa.com',
            'mail_from_name'    => 'AI E-Commerce Studio',
        ])->assertRedirect();

        MailConfigurator::flush();
        MailConfigurator::apply();

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('send.smtp.com', config('mail.mailers.smtp.host'));
        $this->assertSame(2525, config('mail.mailers.smtp.port'));
        $this->assertSame('AI E-Commerce Studio', config('mail.from.name'));

        // "auto" must leave the scheme null so Symfony negotiates STARTTLS —
        // forcing smtps on port 2525 would simply fail to connect.
        $this->assertNull(config('mail.mailers.smtp.scheme'));

        $this->assertSame('Settings', MailConfigurator::effective()['source']);
    }

    public function test_ssl_option_sets_the_implicit_tls_scheme(): void
    {
        $this->actingAs($this->superAdmin())->put(route('settings.mail.update'), [
            'mail_enabled' => 1,
            'mail_host'    => 'smtp.example.com',
            'mail_port'    => 465,
            'mail_scheme'  => 'smtps',
        ])->assertRedirect();

        MailConfigurator::flush();
        MailConfigurator::apply();

        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
    }

    public function test_leaving_it_disabled_does_not_touch_the_env_config(): void
    {
        $before = config('mail.default');

        $this->actingAs($this->superAdmin())->put(route('settings.mail.update'), [
            'mail_host' => 'send.smtp.com',   // stored, but not enabled
        ])->assertRedirect();

        MailConfigurator::flush();
        MailConfigurator::apply();

        $this->assertSame($before, config('mail.default'));
        $this->assertSame('Server .env', MailConfigurator::effective()['source']);
    }

    public function test_enabling_without_a_host_is_rejected(): void
    {
        $this->actingAs($this->superAdmin())->put(route('settings.mail.update'), [
            'mail_enabled' => 1,
            'mail_host'    => '',
        ])->assertSessionHasErrors('mail_host');

        $this->assertSame('', Setting::get('mail_enabled'));
    }

    public function test_a_blank_password_keeps_the_stored_one(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->put(route('settings.mail.update'), [
            'mail_enabled'  => 1,
            'mail_host'     => 'send.smtp.com',
            'mail_password' => 'original-secret',
        ]);

        // Saving again without retyping it must not wipe the credential.
        $this->actingAs($admin)->put(route('settings.mail.update'), [
            'mail_enabled'  => 1,
            'mail_host'     => 'send.smtp.com',
            'mail_port'     => 587,
            'mail_password' => '',
        ]);

        $this->assertSame('original-secret', Setting::get('mail_password'));
    }

    public function test_the_password_is_never_rendered_back_to_the_page(): void
    {
        $admin = $this->superAdmin();

        Setting::set('mail_enabled', '1');
        Setting::set('mail_host', 'send.smtp.com');
        Setting::set('mail_password', 'do-not-leak-me');
        MailConfigurator::flush();

        $this->actingAs($admin)->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Mail (SMTP)')
            ->assertDontSee('do-not-leak-me');
    }

    public function test_the_test_button_sends_immediately(): void
    {
        // Left on the test suite's array transport on purpose: Mail::fake()
        // records Mailables and ignores Mail::raw(), so it would prove nothing.
        $this->actingAs($this->superAdmin())->post(route('settings.mail.test'), [
            'test_email' => 'someone@abuissa.com',
        ])->assertRedirect()->assertSessionHas('success');

        $messages = Mail::mailer()->getSymfonyTransport()->messages();

        $this->assertCount(1, $messages);
        $this->assertSame('someone@abuissa.com', $messages[0]->getOriginalMessage()->getTo()[0]->getAddress());
        $this->assertStringContainsString('SMTP test', $messages[0]->getOriginalMessage()->getSubject());
    }

    public function test_the_test_button_says_so_when_mail_is_only_logging(): void
    {
        config(['mail.default' => 'log']);

        $this->actingAs($this->superAdmin())->post(route('settings.mail.test'), [
            'test_email' => 'someone@abuissa.com',
        ])->assertRedirect()->assertSessionHas('warning');

        // Nothing pretends to have been sent.
        $this->assertSame('log', config('mail.default'));
    }

    public function test_only_super_admins_can_change_mail_settings(): void
    {
        $user = User::create([
            'name' => 'Regular', 'email' => 'reg@example.test', 'password' => 'password',
            'is_active' => true, 'is_super_admin' => false,
        ]);

        $this->actingAs($user)->put(route('settings.mail.update'), [
            'mail_enabled' => 1, 'mail_host' => 'evil.example.com',
        ])->assertForbidden();

        $this->actingAs($user)->post(route('settings.mail.test'), [
            'test_email' => 'reg@example.test',
        ])->assertForbidden();

        $this->assertSame('', Setting::get('mail_host'));
    }
}
