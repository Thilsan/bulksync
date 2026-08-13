<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\EphemeralChat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Chat leaves no trace in the database: a message lives in a cache-backed
 * delivery buffer that expires, and the lasting copy belongs to each person's
 * browser.
 *
 * These tests cover the delivery path, the read receipts, and — the promise the
 * feature rests on — that nothing a conversation says reaches a table.
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

        // Attachments do have to hit a real filesystem — the code moves uploaded
        // files and later unlinks them. A disposable directory per test keeps that
        // out of real storage and makes leftovers detectable.
        config(['chat.files_path' => sys_get_temp_dir() . '/bulksync-chat-test-' . getmypid()]);

        $this->emptyFilesDir();
    }

    protected function tearDown(): void
    {
        $this->emptyFilesDir();

        parent::tearDown();
    }

    private function emptyFilesDir(): void
    {
        $root = EphemeralChat::filesPath();

        if (is_dir($root)) {
            foreach (glob("{$root}/*") ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
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

    /**
     * An over-long message is refused, not silently shortened.
     *
     * Delivering half of what someone wrote, without telling them, is worse than
     * refusing it.
     */
    public function test_an_over_long_message_is_refused_rather_than_truncated(): void
    {
        $ann = $this->user('ann');
        $bob = $this->user('bob');

        $this->actingAs($ann)
            ->postJson(route('chat.send', $bob), ['body' => str_repeat('x', EphemeralChat::MAX_LENGTH + 1)])
            ->assertStatus(422);

        $this->assertSame([], EphemeralChat::transcript($ann->id, $bob->id));

        // And the store refuses one directly, so nothing can slip past the route.
        $this->assertNull(
            EphemeralChat::send($ann->id, $bob->id, str_repeat('y', EphemeralChat::MAX_LENGTH + 1)),
        );

        // Exactly at the limit is fine.
        $this->assertNotNull(
            EphemeralChat::send($ann->id, $bob->id, str_repeat('z', EphemeralChat::MAX_LENGTH)),
        );
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
            ->postJson(route('chat.send', $bob), ['body' => 'opening line'])
            ->assertOk();

        $this->assertNotNull($response->json('epoch'));
        $this->assertSame(EphemeralChat::epoch($ann->id, $bob->id), $response->json('epoch'));
    }

    // ── Attachments ──────────────────────────────────────────────────────────

    public function test_an_image_can_be_sent_and_fetched_by_the_recipient(): void
    {
        $ann = $this->user('ann');
        $bob = $this->user('bob');

        $sent = $this->actingAs($ann)
            ->post(route('chat.send', $bob), [
                'body'  => 'the shoot reference',
                'files' => [UploadedFile::fake()->image('reference.jpg', 40, 40)],
            ])
            ->assertOk()
            ->json('sent');

        $this->assertCount(1, $sent['files']);
        $this->assertSame('reference.jpg', $sent['files'][0]['name']);
        $this->assertTrue($sent['files'][0]['image'], 'A jpeg should be marked as showable inline.');

        $token = $sent['files'][0]['token'];

        // The recipient can fetch it, and it comes back as an image.
        $response = $this->actingAs($bob)
            ->get(route('chat.files.download', ['peer' => $ann, 'token' => $token]))
            ->assertOk();

        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_a_file_can_be_sent_with_no_message_at_all(): void
    {
        $ann = $this->user('ann');
        $bob = $this->user('bob');

        $sent = $this->actingAs($ann)
            ->post(route('chat.send', $bob), ['files' => [UploadedFile::fake()->create('skus.csv', 8)]])
            ->assertOk()
            ->json('sent');

        $this->assertSame('', $sent['body']);
        $this->assertCount(1, $sent['files']);
    }

    /**
     * A file must not render in the browser unless it is a known image type.
     *
     * An HTML file or an SVG served inline runs on this origin — which would turn
     * "share a file with a colleague" into cross-site scripting.
     */
    public function test_anything_that_is_not_a_known_image_is_forced_to_download(): void
    {
        $ann = $this->user('ann');
        $bob = $this->user('bob');

        $dangerous = UploadedFile::fake()->createWithContent(
            'payload.html',
            '<script>alert(document.cookie)</script>',
        );

        $sent = $this->actingAs($ann)
            ->post(route('chat.send', $bob), ['files' => [$dangerous]])
            ->assertOk()
            ->json('sent');

        $this->assertFalse($sent['files'][0]['image'], 'HTML must never be marked as an inline image.');

        $response = $this->actingAs($bob)
            ->get(route('chat.files.download', ['peer' => $ann, 'token' => $sent['files'][0]['token']]))
            ->assertOk();

        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    /** An SVG is a document that can carry script, so it downloads too. */
    public function test_an_svg_is_not_shown_inline(): void
    {
        $ann = $this->user('ann');
        $bob = $this->user('bob');

        $svg = UploadedFile::fake()->createWithContent(
            'logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        );

        $sent = $this->actingAs($ann)
            ->post(route('chat.send', $bob), ['files' => [$svg]])
            ->assertOk()
            ->json('sent');

        $this->assertFalse($sent['files'][0]['image']);

        $this->actingAs($bob)
            ->get(route('chat.files.download', ['peer' => $ann, 'token' => $sent['files'][0]['token']]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/octet-stream');
    }

    /** The property that keeps a conversation private. */
    public function test_someone_outside_the_conversation_cannot_fetch_the_file(): void
    {
        $ann = $this->user('ann');
        $bob = $this->user('bob');
        $eve = $this->user('eve');

        $token = $this->actingAs($ann)
            ->post(route('chat.send', $bob), ['files' => [UploadedFile::fake()->image('private.png')]])
            ->assertOk()
            ->json('sent.files.0.token');

        // Eve knows the token but it is not named in any conversation of hers.
        $this->actingAs($eve)
            ->get(route('chat.files.download', ['peer' => $ann, 'token' => $token]))
            ->assertNotFound();

        $this->actingAs($eve)
            ->get(route('chat.files.download', ['peer' => $bob, 'token' => $token]))
            ->assertNotFound();

        // And a signed-out request gets nowhere either.
        auth()->logout();
        $this->get(route('chat.files.download', ['peer' => $ann, 'token' => $token]))
            ->assertRedirect(route('login'));
    }

    /**
     * A token is not a path.
     *
     * The shape is checked before the value is used for anything, so traversal
     * cannot reach outside the upload directory.
     */
    public function test_a_token_cannot_be_used_to_reach_another_file(): void
    {
        $ann = $this->user('ann');
        $bob = $this->user('bob');

        foreach ([
            '../../../.env',
            '..%2F..%2F.env',
            'not-a-token',
            str_repeat('z', 32),          // right length, wrong alphabet
            str_repeat('a', 31),          // right alphabet, wrong length
        ] as $attempt) {
            $this->actingAs($ann)
                ->get(url("chat/{$bob->id}/files/" . $attempt))
                ->assertNotFound();
        }
    }

    public function test_more_files_than_allowed_is_rejected(): void
    {
        $ann = $this->user('ann');
        $bob = $this->user('bob');

        $tooMany = array_map(
            fn ($i) => UploadedFile::fake()->image("shot-{$i}.png"),
            range(1, EphemeralChat::MAX_FILES_PER_MESSAGE + 1),
        );

        $this->actingAs($ann)
            ->post(route('chat.send', $bob), ['files' => $tooMany])
            ->assertStatus(422);

        $this->assertSame([], EphemeralChat::transcript($ann->id, $bob->id));
    }

    public function test_a_file_over_the_limit_is_rejected(): void
    {
        $ann = $this->user('ann');
        $bob = $this->user('bob');

        $oversized = UploadedFile::fake()->create(
            'huge.bin',
            (int) (EphemeralChat::maxUploadBytes() / 1024) + 64,
        );

        $this->actingAs($ann)
            ->post(route('chat.send', $bob), ['files' => [$oversized]])
            ->assertStatus(422);

        $this->assertSame([], EphemeralChat::transcript($ann->id, $bob->id));
    }

    /**
     * Clearing a conversation takes its files off disk.
     *
     * Otherwise every cleared conversation leaves its attachments behind until
     * the scheduled sweep — on a server whose disk has filled twice.
     */
    public function test_clearing_a_conversation_deletes_its_files(): void
    {
        $ann = $this->user('ann');
        $bob = $this->user('bob');

        $token = $this->actingAs($ann)
            ->post(route('chat.send', $bob), ['files' => [UploadedFile::fake()->image('bin-me.png')]])
            ->assertOk()
            ->json('sent.files.0.token');

        $path = EphemeralChat::filesPath($token);
        $this->assertFileExists($path);

        $this->actingAs($bob)->deleteJson(route('chat.clear', $ann))->assertOk();

        $this->assertFileDoesNotExist($path, 'Clearing must not leave the file on disk.');
    }

    /** Files that fall off the end of the buffer go with it. */
    public function test_a_file_is_deleted_when_its_message_falls_out_of_the_buffer(): void
    {
        $ann = $this->user('ann');
        $bob = $this->user('bob');

        $token = $this->actingAs($ann)
            ->post(route('chat.send', $bob), ['files' => [UploadedFile::fake()->image('oldest.png')]])
            ->assertOk()
            ->json('sent.files.0.token');

        $path = EphemeralChat::filesPath($token);
        $this->assertFileExists($path);

        // Push it past the cap, so the message naming it is dropped.
        foreach (range(1, EphemeralChat::MAX_MESSAGES) as $i) {
            EphemeralChat::send($ann->id, $bob->id, "filler {$i}");
        }

        $this->assertFileDoesNotExist($path, 'A dropped message must take its file with it.');
        $this->assertNull(EphemeralChat::findFile($bob->id, $ann->id, $token));
    }

    public function test_a_failed_send_does_not_leave_files_behind(): void
    {
        $ann = $this->user('ann');
        $bob = $this->user('bob');

        // Over the character limit, so the message is refused after the file
        // has already been written to disk.
        $this->actingAs($ann)
            ->post(route('chat.send', $bob), [
                'body'  => str_repeat('x', EphemeralChat::MAX_LENGTH + 1),
                'files' => [UploadedFile::fake()->image('orphan.png')],
            ])
            ->assertStatus(422);

        $onDisk = glob(EphemeralChat::filesPath() . '/*') ?: [];

        $this->assertSame([], $onDisk, 'A rejected send must not leave an orphan on disk.');
    }

    public function test_the_upload_limit_respects_php_s_own_ceiling(): void
    {
        // Whatever the app would like, PHP decides what actually arrives.
        $this->assertLessThanOrEqual(EphemeralChat::MAX_FILE_BYTES, EphemeralChat::maxUploadBytes());
        $this->assertGreaterThan(0, EphemeralChat::maxUploadBytes());
    }

    public function test_a_filename_cannot_carry_a_path_or_run_off_the_end(): void
    {
        $ann = $this->user('ann');
        $bob = $this->user('bob');

        $sent = $this->actingAs($ann)
            ->post(route('chat.send', $bob), [
                'files' => [UploadedFile::fake()->createWithContent('../../etc/passwd', 'x')],
            ])
            ->assertOk()
            ->json('sent');

        $name = $sent['files'][0]['name'];

        $this->assertStringNotContainsString('/', $name);
        $this->assertStringNotContainsString('..', $name);
        $this->assertLessThanOrEqual(120, mb_strlen($name));
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
     * A message passes through the delivery buffer in plain text — that is the
     * accepted scope — but it must never be written to a table, where it would
     * outlive the conversation and be queryable for ever.
     */
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
