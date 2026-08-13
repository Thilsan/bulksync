<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\EphemeralChat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Chat must leave no readable trace on this server: messages are sealed in the
 * browser, and the buffer they pass through is a cache entry that expires.
 *
 * These tests cover the delivery path, that nothing reaches a table, and — the
 * guarantee that matters most — that the server refuses to accept a message it
 * could read.
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
            // A published key, so this person can be written to. The real one is
            // generated in the browser; only its shape matters to the server.
            'chat_public_key' => json_encode([
                'kty' => 'EC',
                'crv' => 'P-256',
                'x'   => 'f83OJ3D2xF1Bg8vub9tLe1gHMzV76e8Tus9uPHvRVEU',
                'y'   => 'x_FEzRu9m36HLN_tue659LNpXW6pCyStikYjKIWI5a0',
            ]),
            'chat_key_at' => now(),
        ]);
    }

    /**
     * A stand-in for what the browser produces.
     *
     * The server never opens one, so a test does not need real ciphertext — only
     * something with the right shape. That is precisely what send() checks.
     */
    private function envelope(string $ciphertext = 'c2VhbGVkLW1lc3NhZ2U='): array
    {
        return [
            'v'  => 1,
            'iv' => 'YWJjZGVmZ2hpamts',   // 12 bytes, base64
            'ct' => $ciphertext,
        ];
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

    public function test_a_sealed_message_reaches_the_other_person(): void
    {
        $ann = $this->user('ann');
        $bob = $this->user('bob');

        $sealed = $this->envelope('bm90LXJlYWRhYmxl');

        $this->actingAs($ann)
            ->postJson(route('chat.send', $bob), ['body' => $sealed])
            ->assertOk();

        $delivered = $this->actingAs($bob)
            ->getJson(route('chat.messages', $ann))
            ->assertOk()
            ->assertJsonPath('messages.0.from', $ann->id)
            ->json('messages.0.body');

        // Handed over exactly as sealed — the server neither reads nor rewrites it.
        $this->assertSame($sealed, json_decode($delivered, true));
    }

    /**
     * The guarantee the whole feature rests on.
     *
     * If this ever passes plaintext through, messages are readable on the server
     * and the encryption is decoration.
     */
    public function test_the_server_refuses_a_message_it_could_read(): void
    {
        $ann = $this->user('ann');
        $bob = $this->user('bob');

        $this->actingAs($ann)
            ->postJson(route('chat.send', $bob), ['body' => 'this is plain text'])
            ->assertStatus(422);

        $this->assertSame([], EphemeralChat::transcript($ann->id, $bob->id), 'Nothing should have been stored.');
    }

    /** @return list<array{0: string, 1: mixed}> */
    public static function malformedEnvelopes(): array
    {
        return [
            'plain string'      => ['body' => 'hello'],
            'missing version'   => ['body' => ['iv' => 'YWJjZGVmZ2hpamts', 'ct' => 'abc']],
            'wrong version'     => ['body' => ['v' => 2, 'iv' => 'YWJjZGVmZ2hpamts', 'ct' => 'abc']],
            'missing nonce'     => ['body' => ['v' => 1, 'ct' => 'abc']],
            'nonce too short'   => ['body' => ['v' => 1, 'iv' => 'abc', 'ct' => 'abc']],
            'missing ciphertext' => ['body' => ['v' => 1, 'iv' => 'YWJjZGVmZ2hpamts']],
            'empty ciphertext'  => ['body' => ['v' => 1, 'iv' => 'YWJjZGVmZ2hpamts', 'ct' => '']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedEnvelopes')]
    public function test_anything_that_is_not_a_sealed_envelope_is_rejected(mixed $body): void
    {
        $ann = $this->user('ann');
        $bob = $this->user('bob');

        $this->actingAs($ann)
            ->postJson(route('chat.send', $bob), ['body' => $body])
            ->assertStatus(422);

        $this->assertSame([], EphemeralChat::transcript($ann->id, $bob->id));
    }

    /**
     * Over-length envelopes are refused, never trimmed.
     *
     * Trimming ciphertext does not shorten a message, it destroys it — the
     * recipient would get an envelope that cannot be opened, for no visible
     * reason.
     */
    public function test_an_oversized_envelope_is_refused_rather_than_truncated(): void
    {
        $ann = $this->user('ann');
        $bob = $this->user('bob');

        $this->actingAs($ann)
            ->postJson(route('chat.send', $bob), [
                'body' => $this->envelope(str_repeat('A', EphemeralChat::MAX_LENGTH + 1)),
            ])
            ->assertStatus(422);

        $this->assertSame([], EphemeralChat::transcript($ann->id, $bob->id));

        // And the store refuses one directly, so nothing can slip past the route.
        $this->assertNull(
            EphemeralChat::send($ann->id, $bob->id, str_repeat('B', EphemeralChat::MAX_LENGTH + 1)),
        );
    }

    // ── Keys ─────────────────────────────────────────────────────────────────

    public function test_a_browser_publishes_its_public_key(): void
    {
        $ann = $this->user('ann');
        $ann->update(['chat_public_key' => null, 'chat_key_at' => null]);

        $this->actingAs($ann)->postJson(route('chat.keys.publish'), [
            'key' => [
                'kty' => 'EC',
                'crv' => 'P-256',
                'x'   => 'f83OJ3D2xF1Bg8vub9tLe1gHMzV76e8Tus9uPHvRVEU',
                'y'   => 'x_FEzRu9m36HLN_tue659LNpXW6pCyStikYjKIWI5a0',
            ],
        ])->assertOk();

        $ann->refresh();

        $this->assertTrue($ann->canReceiveChat());
        $this->assertNotNull($ann->chat_key_at);
        $this->assertSame('P-256', json_decode($ann->chat_public_key, true)['crv']);
    }

    /**
     * A private scalar must never be stored, even if a client sends one.
     *
     * 'd' is the private half of an EC key. Keeping it would hand the server the
     * ability to decrypt everything, which is the one thing it must not have.
     */
    public function test_publishing_keeps_only_the_public_half(): void
    {
        $ann = $this->user('ann');

        $this->actingAs($ann)->postJson(route('chat.keys.publish'), [
            'key' => [
                'kty' => 'EC',
                'crv' => 'P-256',
                'x'   => 'f83OJ3D2xF1Bg8vub9tLe1gHMzV76e8Tus9uPHvRVEU',
                'y'   => 'x_FEzRu9m36HLN_tue659LNpXW6pCyStikYjKIWI5a0',
                'd'   => 'THIS-IS-A-PRIVATE-KEY-AND-MUST-NOT-BE-STORED',
            ],
        ])->assertOk();

        $ann->refresh();

        $this->assertStringNotContainsString('THIS-IS-A-PRIVATE-KEY', $ann->chat_public_key);
        $this->assertArrayNotHasKey('d', json_decode($ann->chat_public_key, true));
    }

    public function test_a_key_on_the_wrong_curve_is_rejected(): void
    {
        $ann = $this->user('ann');

        $this->actingAs($ann)->postJson(route('chat.keys.publish'), [
            'key' => ['kty' => 'EC', 'crv' => 'P-521', 'x' => 'aaa', 'y' => 'bbb'],
        ])->assertStatus(422);

        $this->actingAs($ann)->postJson(route('chat.keys.publish'), [
            'key' => ['kty' => 'RSA', 'crv' => 'P-256', 'x' => 'aaa', 'y' => 'bbb'],
        ])->assertStatus(422);
    }

    public function test_the_inbox_carries_each_persons_key_so_a_browser_can_encrypt(): void
    {
        $ann     = $this->user('ann');
        $bob     = $this->user('bob');
        $keyless = $this->user('cat');
        $keyless->update(['chat_public_key' => null]);

        $people = $this->actingAs($ann)->getJson(route('chat.inbox'))->assertOk()->json('people');

        $byName = collect($people)->keyBy('name');

        $this->assertSame('P-256', $byName['bob']['key']['crv']);
        $this->assertNull($byName['cat']['key'], 'Someone with no key must be reported as having none.');
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

    /**
     * A drained buffer is reported, but is not an instruction to forget anything.
     *
     * The browser owns the history; the server saying "I no longer have this"
     * only means there is nothing left to deliver.
     */
    public function test_a_drained_buffer_is_reported_without_pretending_it_never_happened(): void
    {
        $ann = $this->user('ann');
        $bob = $this->user('bob');

        EphemeralChat::send($ann->id, $bob->id, 'said out loud, written nowhere');
        EphemeralChat::clear($ann->id, $bob->id);

        $this->actingAs($bob)
            ->getJson(route('chat.messages', $ann) . '?after=1')
            ->assertOk()
            ->assertJsonPath('expired', true)
            ->assertJsonPath('messages', []);
    }

    public function test_clearing_drains_the_servers_buffer(): void
    {
        $ann = $this->user('ann');
        $bob = $this->user('bob');

        EphemeralChat::send($ann->id, $bob->id, 'forget I said that');

        $this->actingAs($bob)->deleteJson(route('chat.clear', $ann))->assertOk();

        // Gone from the server. Each browser's own copy is removed client-side,
        // and neither person can reach into the other's.
        $this->assertSame([], EphemeralChat::transcript($ann->id, $bob->id));
    }

    /**
     * The delivery window has to be long enough to be useful.
     *
     * It was 15 minutes, which meant stepping away from your desk lost whatever
     * arrived while you were gone. The browsers keep the history now, so this
     * value only governs delivery — but it still must not be tiny.
     */
    public function test_the_delivery_window_survives_someone_leaving_their_desk(): void
    {
        $this->assertGreaterThanOrEqual(
            3600,
            EphemeralChat::BUFFER_TTL,
            'A delivery buffer under an hour drops messages for anyone briefly away.',
        );
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

    public function test_an_empty_body_is_rejected(): void
    {
        $ann = $this->user('ann');
        $bob = $this->user('bob');

        $this->actingAs($ann)
            ->postJson(route('chat.send', $bob), ['body' => ''])
            ->assertStatus(422);

        $this->actingAs($ann)
            ->postJson(route('chat.send', $bob), [])
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

    public function test_unread_counts_drop_once_the_messages_are_collected(): void
    {
        $ann = $this->user('ann');
        $bob = $this->user('bob');

        EphemeralChat::send($ann->id, $bob->id, 'one');
        EphemeralChat::send($ann->id, $bob->id, 'two');

        $this->assertSame(2, EphemeralChat::unreadCount($bob->id, $ann->id));
        $this->assertSame(0, EphemeralChat::unreadCount($ann->id, $bob->id), 'Your own messages are not unread.');

        // Reading is the poll collecting them, not the page being opened — the
        // page renders no messages, so opening it proves nothing was read.
        $this->actingAs($bob)->getJson(route('chat.messages', $ann))->assertOk();

        $this->assertSame(0, EphemeralChat::unreadCount($bob->id, $ann->id));
    }

    public function test_the_inbox_endpoint_feeds_the_floating_widget(): void
    {
        $ann = $this->user('ann');
        $bob = $this->user('bob');

        EphemeralChat::send($bob->id, $ann->id, 'ping');

        $this->actingAs($ann)
            ->getJson(route('chat.inbox'))
            ->assertOk()
            ->assertJsonPath('unread', 1)
            ->assertJsonPath('people.0.name', 'bob')
            ->assertJsonPath('people.0.unread', 1)
            ->assertJsonPath('people.0.initials', 'b');
    }

    /**
     * Polling the inbox must not make you look available for chat.
     *
     * The widget is on every page, so if this heartbeat counted, everyone would
     * permanently read as online and the dot would mean nothing.
     */
    public function test_the_inbox_poll_does_not_mark_you_online(): void
    {
        $ann = $this->user('ann');
        $bob = $this->user('bob');

        $this->actingAs($ann)->getJson(route('chat.inbox'))->assertOk();

        $this->assertFalse(EphemeralChat::isOnline($ann->id));
    }

    /**
     * A browser holding a copy of a finished conversation must not merge it into
     * the next one — message ids restart at 1, so they would interleave.
     */
    public function test_a_rebuilt_conversation_tells_the_client_to_replace_its_copy(): void
    {
        $ann = $this->user('ann');
        $bob = $this->user('bob');

        EphemeralChat::send($ann->id, $bob->id, 'first conversation');
        $firstEpoch = EphemeralChat::epoch($ann->id, $bob->id);

        $this->assertNotNull($firstEpoch);

        // The conversation ends and a new one starts — ids begin at 1 again.
        EphemeralChat::clear($ann->id, $bob->id);
        EphemeralChat::send($ann->id, $bob->id, 'second conversation');
        $secondEpoch = EphemeralChat::epoch($ann->id, $bob->id);

        $this->assertNotSame($firstEpoch, $secondEpoch, 'A rebuilt transcript needs a new epoch.');

        // Bob's browser still holds message 1 from the old epoch.
        $response = $this->actingAs($bob)
            ->getJson(route('chat.messages', $ann) . '?after=1&epoch=' . $firstEpoch)
            ->assertOk()
            ->assertJsonPath('reset', true)
            ->assertJsonPath('epoch', $secondEpoch);

        // It gets the whole new transcript, not "nothing after id 1".
        $this->assertSame('second conversation', $response->json('messages.0.body'));
    }

    public function test_a_continuing_conversation_is_not_reset(): void
    {
        $ann = $this->user('ann');
        $bob = $this->user('bob');

        EphemeralChat::send($ann->id, $bob->id, 'one');
        $epoch = EphemeralChat::epoch($ann->id, $bob->id);
        EphemeralChat::send($ann->id, $bob->id, 'two');

        $response = $this->actingAs($bob)
            ->getJson(route('chat.messages', $ann) . '?after=1&epoch=' . $epoch)
            ->assertOk()
            ->assertJsonPath('reset', false);

        // Only the message it had not seen.
        $this->assertCount(1, $response->json('messages'));
        $this->assertSame('two', $response->json('messages.0.body'));
    }

    public function test_sending_returns_the_epoch_so_the_sender_can_stamp_its_copy(): void
    {
        $ann = $this->user('ann');
        $bob = $this->user('bob');

        $response = $this->actingAs($ann)
            ->postJson(route('chat.send', $bob), ['body' => $this->envelope()])
            ->assertOk();

        $this->assertNotNull($response->json('epoch'));
        $this->assertSame(EphemeralChat::epoch($ann->id, $bob->id), $response->json('epoch'));
    }

    // ── Read receipts ────────────────────────────────────────────────────────

    public function test_a_message_is_not_read_until_they_collect_it(): void
    {
        $ann = $this->user('ann');
        $bob = $this->user('bob');

        EphemeralChat::send($ann->id, $bob->id, 'first');
        EphemeralChat::send($ann->id, $bob->id, 'second');

        // Ann polls her own conversation: nothing read yet.
        $this->actingAs($ann)
            ->getJson(route('chat.messages', $bob))
            ->assertOk()
            ->assertJsonPath('peerRead', 0);

        // Bob's open conversation collects them.
        $this->actingAs($bob)->getJson(route('chat.messages', $ann))->assertOk();

        // Now Ann sees how far he read.
        $this->actingAs($ann)
            ->getJson(route('chat.messages', $bob))
            ->assertOk()
            ->assertJsonPath('peerRead', 2);
    }

    /**
     * Merely having the app open must not mark anything read.
     *
     * The widget polls the inbox from every page. If that counted, everything
     * would show as read the moment it was sent and the receipt would be a lie.
     */
    public function test_the_inbox_poll_does_not_mark_messages_read(): void
    {
        $ann = $this->user('ann');
        $bob = $this->user('bob');

        EphemeralChat::send($ann->id, $bob->id, 'unread so far');

        $this->actingAs($bob)->getJson(route('chat.inbox'))->assertOk();

        $this->assertSame(0, EphemeralChat::readPointer($ann->id, $bob->id));
        $this->assertSame(1, EphemeralChat::unreadCount($bob->id, $ann->id), 'It should still be waiting for him.');
    }

    public function test_the_read_pointer_is_per_direction(): void
    {
        $ann = $this->user('ann');
        $bob = $this->user('bob');

        EphemeralChat::send($ann->id, $bob->id, 'from ann');
        $this->actingAs($bob)->getJson(route('chat.messages', $ann))->assertOk();

        // Bob read Ann's message; Ann has read nothing of Bob's.
        $this->assertSame(1, EphemeralChat::readPointer($ann->id, $bob->id), 'Bob has read up to 1.');
        $this->assertSame(0, EphemeralChat::readPointer($bob->id, $ann->id), 'Ann has read nothing.');
    }

    /**
     * The reason this feature exists at all.
     *
     * Two separate promises checked together: what a person typed cannot appear
     * in a table, and neither can it appear in the delivery buffer — because the
     * server was never given it in readable form.
     */
    public function test_nothing_a_conversation_says_is_written_to_the_database(): void
    {
        $ann = $this->user('ann');
        $bob = $this->user('bob');

        $typed = 'this must never appear in a table';

        // What the browser actually sends: the typed text, sealed. Standing in
        // for AES-GCM output, which the server cannot tell apart from this.
        $sealed = $this->envelope(base64_encode('~~~sealed~~~' . strrev($typed)));

        $this->actingAs($ann)->postJson(route('chat.send', $bob), ['body' => $sealed])->assertOk();

        // Walk every table in the schema looking for the typed text.
        $tables = collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'table'"))
            ->pluck('name')
            ->reject(fn ($table) => str_starts_with($table, 'sqlite_'));

        foreach ($tables as $table) {
            $columns = collect(DB::select("PRAGMA table_info({$table})"))->pluck('name');

            foreach ($columns as $column) {
                $hit = DB::table($table)->where($column, 'like', "%{$typed}%")->exists();

                $this->assertFalse($hit, "Chat text leaked into {$table}.{$column}.");
            }
        }

        // Nor is it readable in the buffer the message did pass through.
        $buffered = EphemeralChat::transcript($ann->id, $bob->id);

        $this->assertCount(1, $buffered);
        $this->assertStringNotContainsString($typed, $buffered[0]['body']);
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
