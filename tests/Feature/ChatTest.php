<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\EphemeralChat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Chat is the one feature here that must leave no trace: the transcript lives in
 * a cache store and is meant to be unrecoverable once it expires. These tests
 * cover the delivery path and, just as importantly, that nothing reaches a table.
 */
class ChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // In production this store is file-backed; pointing it at the array
        // driver keeps the suite from writing real transcripts to storage.
        config(['cache.stores.chat' => ['driver' => 'array', 'serialize' => false]]);
        Cache::purge('chat');
    }

    private function user(string $name): User
    {
        return User::create([
            'name'      => $name,
            'email'     => "{$name}@example.test",
            'password'  => 'password',
            'is_active' => true,
        ]);
    }

    public function test_the_people_list_shows_colleagues_and_what_is_waiting(): void
    {
        $ann      = $this->user('ann');
        $bob      = $this->user('bob');
        $disabled = $this->user('zoe');
        $disabled->update(['is_active' => false]);

        EphemeralChat::send($bob->id, $ann->id, 'need the Nike SKUs');

        $this->actingAs($ann)
            ->get(route('chat.index'))
            ->assertOk()
            ->assertSee('bob')
            ->assertSee(route('chat.show', $bob))
            ->assertDontSee('zoe')                          // disabled accounts are not listed
            ->assertDontSee(route('chat.show', $ann));      // nor can you open a chat with yourself
    }

    public function test_a_message_reaches_the_other_person(): void
    {
        $ann = $this->user('ann');
        $bob = $this->user('bob');

        $this->actingAs($ann)
            ->postJson(route('chat.send', $bob), ['body' => 'are you on the Nike shoot?'])
            ->assertOk()
            ->assertJsonPath('sent.body', 'are you on the Nike shoot?');

        $this->actingAs($bob)
            ->getJson(route('chat.messages', $ann))
            ->assertOk()
            ->assertJsonPath('messages.0.body', 'are you on the Nike shoot?')
            ->assertJsonPath('messages.0.from', $ann->id);
    }

    public function test_polling_only_returns_what_the_client_has_not_seen(): void
    {
        $ann = $this->user('ann');
        $bob = $this->user('bob');

        EphemeralChat::send($ann->id, $bob->id, 'first');
        EphemeralChat::send($ann->id, $bob->id, 'second');

        $response = $this->actingAs($bob)
            ->getJson(route('chat.messages', $ann) . '?after=1')
            ->assertOk();

        $this->assertCount(1, $response->json('messages'));
        $this->assertSame('second', $response->json('messages.0.body'));
    }

    public function test_an_expired_transcript_is_reported_rather_than_silently_empty(): void
    {
        $ann = $this->user('ann');
        $bob = $this->user('bob');

        EphemeralChat::send($ann->id, $bob->id, 'said out loud, written nowhere');
        EphemeralChat::clear($ann->id, $bob->id);

        // The client is still holding message 1, so the server should say the
        // conversation went away instead of returning a plausible empty list.
        $this->actingAs($bob)
            ->getJson(route('chat.messages', $ann) . '?after=1')
            ->assertOk()
            ->assertJsonPath('expired', true)
            ->assertJsonPath('messages', []);
    }

    public function test_either_side_can_clear_the_conversation_for_both(): void
    {
        $ann = $this->user('ann');
        $bob = $this->user('bob');

        EphemeralChat::send($ann->id, $bob->id, 'forget I said that');

        $this->actingAs($bob)->deleteJson(route('chat.clear', $ann))->assertOk();

        $this->assertSame([], EphemeralChat::transcript($ann->id, $bob->id));
    }

    public function test_presence_follows_whoever_is_polling(): void
    {
        $ann = $this->user('ann');
        $bob = $this->user('bob');

        // Nobody has polled yet, so Ann cannot be online.
        $this->actingAs($bob)
            ->getJson(route('chat.messages', $ann))
            ->assertJsonPath('online', false);

        $this->actingAs($ann)->getJson(route('chat.messages', $bob));

        $this->actingAs($bob)
            ->getJson(route('chat.messages', $ann))
            ->assertJsonPath('online', true);
    }

    public function test_typing_is_visible_only_to_the_person_being_typed_to(): void
    {
        $ann = $this->user('ann');
        $bob = $this->user('bob');

        $this->actingAs($ann)->postJson(route('chat.typing', $bob))->assertOk();

        $this->actingAs($bob)
            ->getJson(route('chat.messages', $ann))
            ->assertJsonPath('typing', true);

        // Ann is the one typing — her own window should not show the indicator.
        $this->actingAs($ann)
            ->getJson(route('chat.messages', $bob))
            ->assertJsonPath('typing', false);
    }

    public function test_a_conversation_cannot_grow_without_bound(): void
    {
        $ann = $this->user('ann');
        $bob = $this->user('bob');

        foreach (range(1, EphemeralChat::MAX_MESSAGES + 10) as $i) {
            EphemeralChat::send($ann->id, $bob->id, "line {$i}");
        }

        $transcript = EphemeralChat::transcript($ann->id, $bob->id);

        $this->assertCount(EphemeralChat::MAX_MESSAGES, $transcript);
        $this->assertSame('line ' . (EphemeralChat::MAX_MESSAGES + 10), end($transcript)['body']);
    }

    public function test_an_over_long_message_is_rejected_by_validation(): void
    {
        $ann = $this->user('ann');
        $bob = $this->user('bob');

        $this->actingAs($ann)
            ->postJson(route('chat.send', $bob), ['body' => str_repeat('x', EphemeralChat::MAX_LENGTH + 1)])
            ->assertStatus(422);

        $this->actingAs($ann)
            ->postJson(route('chat.send', $bob), ['body' => ''])
            ->assertStatus(422);
    }

    public function test_you_cannot_chat_with_yourself_or_a_disabled_account(): void
    {
        $ann      = $this->user('ann');
        $disabled = $this->user('zoe');
        $disabled->update(['is_active' => false]);

        $this->actingAs($ann)->get(route('chat.show', $ann))->assertNotFound();
        $this->actingAs($ann)->get(route('chat.show', $disabled))->assertNotFound();
    }

    public function test_chat_requires_signing_in(): void
    {
        $bob = $this->user('bob');

        $this->get(route('chat.index'))->assertRedirect(route('login'));

        // Not a 401: this app only renders exceptions as JSON under api/*, so a
        // signed-out poll is redirected like any other request. The chat window
        // watches for that redirect and reloads into the login page.
        $this->getJson(route('chat.messages', $bob))->assertRedirect(route('login'));
    }

    public function test_unread_counts_drop_once_the_conversation_is_opened(): void
    {
        $ann = $this->user('ann');
        $bob = $this->user('bob');

        EphemeralChat::send($ann->id, $bob->id, 'one');
        EphemeralChat::send($ann->id, $bob->id, 'two');

        $this->assertSame(2, EphemeralChat::unreadCount($bob->id, $ann->id));
        $this->assertSame(0, EphemeralChat::unreadCount($ann->id, $bob->id), 'Your own messages are not unread.');

        $this->actingAs($bob)->get(route('chat.show', $ann))->assertOk();

        $this->assertSame(0, EphemeralChat::unreadCount($bob->id, $ann->id));
    }

    /** The reason this feature exists at all. */
    public function test_nothing_a_conversation_says_is_written_to_the_database(): void
    {
        $ann = $this->user('ann');
        $bob = $this->user('bob');

        $secret = 'this must never appear in a table';

        $this->actingAs($ann)->postJson(route('chat.send', $bob), ['body' => $secret])->assertOk();

        // Walk every table in the schema looking for the message text.
        $tables = collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'table'"))
            ->pluck('name')
            ->reject(fn ($table) => str_starts_with($table, 'sqlite_'));

        foreach ($tables as $table) {
            $columns = collect(DB::select("PRAGMA table_info({$table})"))->pluck('name');

            foreach ($columns as $column) {
                $hit = DB::table($table)->where($column, 'like', "%{$secret}%")->exists();

                $this->assertFalse($hit, "Chat text leaked into {$table}.{$column}.");
            }
        }
    }

    public function test_the_chat_store_is_never_the_database_store(): void
    {
        // Guards the config: chat on the default store would put transcripts in
        // the cache table, which is exactly what this feature promises not to do.
        $this->assertNotSame(
            'database',
            config('cache.stores.chat.driver'),
            'The chat cache store must not use the database driver.',
        );
    }
}
