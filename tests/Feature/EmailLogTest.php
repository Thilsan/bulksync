<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\ProductRequest;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Brand managers said they were getting the wrong emails, and the only way to
 * see what anyone had actually been sent was tinker on the server.
 */
class EmailLogTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $name, array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => $name, 'email' => str($name)->slug() . '@example.test',
            'password' => 'password', 'is_active' => true,
        ], $attributes));
    }

    private function request(): ProductRequest
    {
        $store = Store::create(['name' => 'Bluesalon', 'shopify_domain' => 'bs.myshopify.com', 'is_active' => true]);

        return ProductRequest::create([
            'reference' => 'PCR-2026-00335', 'user_id' => $this->user('Raiser')->id, 'store_id' => $store->id,
            'request_type' => 'new_brand', 'brand' => 'BMW Fragrances', 'category' => 'Beauty',
            'status' => ProductRequest::PUBLISHED, 'priority' => 'medium',
        ]);
    }

    private function notification(string $subject): Notification
    {
        return new class($subject) extends Notification {
            public function __construct(private string $subject) {}

            public function via(object $notifiable): array
            {
                return ['mail'];
            }

            public function toMail(object $notifiable): MailMessage
            {
                return (new MailMessage)->subject($this->subject)->line('More of the balance has been mapped.');
            }
        };
    }

    public function test_every_email_is_logged_with_who_what_and_which_request(): void
    {
        $request = $this->request();
        $nada    = $this->user('Nada Rezeg');

        $nada->notify($this->notification('[PCR-2026-00335] BMW Fragrances — balance mapped'));

        $log = EmailLog::sole();
        $this->assertSame($nada->id, $log->user_id);
        $this->assertSame('nada-rezeg@example.test', $log->to_address);
        $this->assertSame('[PCR-2026-00335] BMW Fragrances — balance mapped', $log->subject);
        $this->assertSame($request->id, $log->product_request_id);
        $this->assertSame(EmailLog::SENT, $log->status);
        $this->assertNotNull($log->sent_at);
        $this->assertStringContainsString('More of the balance has been mapped.', $log->body);
    }

    public function test_mail_to_someone_without_an_account_is_logged_too(): void
    {
        Mail::raw('Test message', fn ($m) => $m->to('outside@example.test')->subject('SMTP test'));

        $log = EmailLog::sole();
        $this->assertNull($log->user_id);
        $this->assertSame('outside@example.test', $log->to_address);
        $this->assertNull($log->product_request_id);
    }

    public function test_the_log_is_kept_bounded(): void
    {
        $stuck = EmailLog::create(['to_address' => 'a@example.test', 'status' => EmailLog::SENDING]);
        $stuck->forceFill(['created_at' => now()->subHour()])->save();

        $old = EmailLog::create(['to_address' => 'b@example.test', 'status' => EmailLog::SENT, 'body' => '<p>hi</p>']);
        $old->forceFill(['created_at' => now()->subDays(EmailLog::KEEP_BODY_DAYS + 1)])->save();

        $ancient = EmailLog::create(['to_address' => 'c@example.test', 'status' => EmailLog::SENT]);
        $ancient->forceFill(['created_at' => now()->subDays(EmailLog::KEEP_DAYS + 1)])->save();

        $fresh = EmailLog::create(['to_address' => 'd@example.test', 'status' => EmailLog::SENDING]);

        EmailLog::prune();

        $this->assertSame(EmailLog::FAILED, $stuck->refresh()->status, 'A message never confirmed did not arrive.');
        $this->assertNull($old->refresh()->body, 'Bodies go first.');
        $this->assertNull(EmailLog::find($ancient->id));
        $this->assertSame(EmailLog::SENDING, $fresh->refresh()->status, 'One being sent right now is left alone.');
    }

    public function test_a_super_admin_can_search_the_log_and_read_an_email(): void
    {
        $this->request();
        $admin = $this->user('Root', ['is_super_admin' => true]);
        $nada  = $this->user('Nada Rezeg');
        $other = $this->user('Someone Else');

        $nada->notify($this->notification('[PCR-2026-00335] BMW Fragrances — balance mapped'));
        $other->notify($this->notification('Your weekly digest'));

        $this->actingAs($admin)->get(route('super-admin.emails.index', ['q' => 'PCR-2026-00335']))
            ->assertOk()
            ->assertSee('BMW Fragrances — balance mapped')
            ->assertDontSee('Your weekly digest');

        $log = EmailLog::where('user_id', $nada->id)->sole();

        $this->actingAs($admin)->get(route('super-admin.emails.show', $log))
            ->assertOk()
            ->assertSee('PCR-2026-00335')
            ->assertSee(route('super-admin.emails.body', $log), false);

        // The body is the email's own HTML, so it is served walled off.
        $this->actingAs($admin)->get(route('super-admin.emails.body', $log))
            ->assertOk()
            ->assertSee('More of the balance has been mapped.')
            ->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'; img-src * data:; style-src 'unsafe-inline'");
    }

    public function test_only_super_admins_can_see_it(): void
    {
        $this->actingAs($this->user('Brand Manager'))
            ->get(route('super-admin.emails.index'))
            ->assertForbidden();
    }
}
