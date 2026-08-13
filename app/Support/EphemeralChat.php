<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;

/**
 * Person-to-person chat where the server is only the postman.
 *
 * The history belongs to the people talking, not to this application. Each
 * browser keeps its own copy of a conversation in localStorage, and what lives
 * here is just the pending delivery: a short-lived buffer in the file-backed
 * 'chat' cache store, so a message written on one screen can be picked up on
 * another. There is no messages table and no model — nothing a conversation
 * says survives on this server past the buffer expiring, and none of it is ever
 * queryable from the database.
 *
 * Messages pass through in plain text. That is a deliberate scope decision, not
 * an oversight: while the buffer holds one, anyone who can read this server's
 * disk can read it. What the design does guarantee is that it is never written
 * to a table and never kept beyond the delivery window.
 *
 * The limits are sized for delivery, not for keeping a record:
 *
 *   - the buffer holds at most MAX_MESSAGES per pair, oldest dropped first
 *   - a message longer than MAX_LENGTH is refused
 *   - BUFFER_TTL is pushed forward on every send, so an active conversation
 *     stays deliverable and a finished one drains away
 *
 * Two consequences worth knowing, because they are properties of the design and
 * not bugs to be fixed here: a browser only ever holds what was actually
 * delivered to it, so two people's histories can differ; and a message sent to
 * someone who does not connect within BUFFER_TTL is never delivered at all.
 *
 * Together those bound what a pair of users can occupy to a few KB. The file
 * driver only reclaims an expired entry when that exact key is read again,
 * which on this server has twice meant a full disk — so the scheduled
 * 'prune-stale-chat' task in routes/console.php sweeps the directory by mtime
 * rather than trusting reads to happen.
 */
final class EphemeralChat
{
    /** Cache store from config/cache.php — never the default (database) store. */
    private const STORE = 'chat';

    /** Kept per conversation in the delivery buffer; the oldest fall off the top. */
    public const MAX_MESSAGES = 50;

    /** The longest message someone may send, in characters. */
    public const MAX_LENGTH = 2000;

    /**
     * How long an undelivered message waits.
     *
     * This is a delivery window, not a retention policy — the browsers keep the
     * history. Long enough that stepping away from your desk does not lose what
     * someone sent you; short enough that this server is never where a
     * conversation lives.
     */
    public const BUFFER_TTL = 28800;   // 8 hours

    /** How much of a conversation each browser keeps for itself. */
    public const LOCAL_KEEP = 500;

    /** A heartbeat is written on every poll, so this only needs to outlive one. */
    public const PRESENCE_TTL = 45;

    public const TYPING_TTL = 6;

    // ── Attachments ──────────────────────────────────────────────────────────

    /**
     * Where uploaded files go, under storage/app.
     *
     * Deliberately NOT under storage/app/public and never symlinked into the
     * document root: a file here can only be reached through the download route,
     * which checks that the caller is one of the two people in the conversation.
     * Nothing user-uploaded is ever served directly by the web server.
     */
    public const FILES_DIR = 'chat-files';

    /** Per file. The effective limit is also capped by PHP's own ini settings. */
    public const MAX_FILE_BYTES = 8388608;   // 8 MB

    public const MAX_FILES_PER_MESSAGE = 4;

    /**
     * A ceiling on the whole directory, checked before every upload.
     *
     * The per-file and TTL limits should make this unreachable. It exists because
     * this server's disk has filled twice, and a backstop that refuses new
     * uploads is far better than one that takes the application down with it.
     */
    public const MAX_TOTAL_FILE_BYTES = 524288000;   // 500 MB

    /**
     * Types shown inline in the conversation.
     *
     * An allowlist, not a blocklist, and SVG is absent on purpose: an SVG is a
     * document that can carry script, so rendering one inline from a colleague
     * would be a cross-site scripting hole. SVGs still send — they just download
     * instead of displaying.
     */
    public const INLINE_IMAGE_MIMES = [
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
    ];

    private static function store(): Repository
    {
        return Cache::store(self::STORE);
    }

    // ── Conversation identity ────────────────────────────────────────────────

    /**
     * One key per pair, whichever direction it is read from.
     *
     * Sorting the ids means "me to you" and "you to me" are the same
     * conversation rather than two half-transcripts.
     */
    private static function key(int $a, int $b): string
    {
        $ids = [$a, $b];
        sort($ids);

        return "convo:{$ids[0]}-{$ids[1]}";
    }

    // ── Reading ──────────────────────────────────────────────────────────────

    /**
     * Messages in a conversation after $afterId.
     *
     * The epoch identifies this particular buffer. Message ids restart at 1
     * every time one is rebuilt from nothing, so a browser needs a way to notice
     * that ids it has already seen now refer to different messages — otherwise
     * old and new interleave into nonsense. A changed epoch means "your id
     * cursor is meaningless, start counting again"; it does not mean the
     * browser's own history is wrong, only that it cannot be indexed by id.
     *
     * @return array{messages: list<array{id: int, from: int, body: string, at: int}>, exists: bool, epoch: string|null}
     */
    public static function since(int $userId, int $peerId, int $afterId = 0): array
    {
        $convo = self::store()->get(self::key($userId, $peerId));

        if (! is_array($convo)) {
            // Nothing there: either they have never spoken or the transcript has
            // already expired. The caller tells those apart by whether the
            // client was holding a message id.
            return ['messages' => [], 'exists' => false, 'epoch' => null];
        }

        $messages = array_values(array_filter(
            $convo['messages'] ?? [],
            fn ($message) => ($message['id'] ?? 0) > $afterId,
        ));

        return ['messages' => $messages, 'exists' => true, 'epoch' => $convo['epoch'] ?? null];
    }

    /** The epoch of the current transcript, or null if there isn't one. */
    public static function epoch(int $userId, int $peerId): ?string
    {
        $convo = self::store()->get(self::key($userId, $peerId));

        return is_array($convo) ? ($convo['epoch'] ?? null) : null;
    }

    /** The whole current transcript, for the initial page render. */
    public static function transcript(int $userId, int $peerId): array
    {
        return self::since($userId, $peerId)['messages'];
    }

    // ── Writing ──────────────────────────────────────────────────────────────

    /**
     * Append a message and return it as stored.
     *
     * Held under a lock because the file driver cannot read-modify-write
     * atomically: two people answering at the same instant would otherwise
     * each save a transcript missing the other's line.
     *
     * @param  list<array{token: string, name: string, mime: string, size: int, image: bool}>  $files
     * @return array{id: int, from: int, body: string, at: int, files: list<array>}|null  null if it could not be stored
     */
    public static function send(int $senderId, int $peerId, string $body, array $files = []): ?array
    {
        $body = trim($body);

        // One or the other is enough: a message can be just an image.
        if ($body === '' && $files === []) {
            return null;
        }

        /*
         * Refused rather than trimmed. Silently delivering half of what someone
         * wrote is worse than telling them it did not send — the route validates
         * the same limit first, so reaching this is a caller's mistake.
         */
        if (mb_strlen($body) > self::MAX_LENGTH) {
            return null;
        }

        $key = self::key($senderId, $peerId);

        $lock = self::store()->lock("lock:{$key}", 5);

        try {
            // A send that cannot get the lock within a second is dropped rather
            // than queued — the sender is watching, and a visible failure is
            // better than a message that lands somewhere in the transcript.
            $lock->block(1);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            return null;
        }

        try {
            $convo = self::store()->get($key);
            // A fresh buffer gets a new epoch, which is what tells a browser its
            // saved id cursor no longer lines up with what the server is handing
            // out. Its own history is untouched.
            $convo = is_array($convo)
                ? $convo
                : ['next_id' => 1, 'messages' => [], 'epoch' => bin2hex(random_bytes(8))];

            $message = [
                'id'    => $convo['next_id'],
                'from'  => $senderId,
                'body'  => $body,
                'at'    => time(),
                'files' => array_values($files),
            ];

            $convo['messages'][] = $message;
            $convo['next_id']++;

            // Trim from the front so a long conversation cannot grow without end.
            // Whatever falls off takes its files with it, or they would sit on
            // disk with nothing left pointing at them.
            if (count($convo['messages']) > self::MAX_MESSAGES) {
                $dropped = array_slice($convo['messages'], 0, count($convo['messages']) - self::MAX_MESSAGES);
                $convo['messages'] = array_slice($convo['messages'], -self::MAX_MESSAGES);

                self::deleteFilesIn($dropped);
            }

            // Every send pushes the expiry out, so an active conversation stays
            // deliverable and a finished one drains away.
            self::store()->put($key, $convo, self::BUFFER_TTL);

            return $message;
        } finally {
            $lock->release();
        }
    }

    /** Drop a transcript now, without waiting for it to expire. */
    public static function clear(int $userId, int $peerId): void
    {
        // Files first: once the buffer entry is gone there is nothing left that
        // names them, and they would linger until the scheduled sweep.
        self::deleteFilesIn(self::transcript($userId, $peerId));

        self::store()->forget(self::key($userId, $peerId));
    }

    // ── Attachments ──────────────────────────────────────────────────────────

    /**
     * Absolute path to the directory holding chat uploads.
     *
     * Overridable through config('chat.files_path') — there is no chat config
     * file, and none is needed: the point is that the test suite can redirect
     * this somewhere disposable instead of writing into real storage.
     */
    public static function filesPath(string $token = ''): string
    {
        $root = config('chat.files_path') ?: storage_path('app/' . self::FILES_DIR);

        return rtrim((string) $root, '/') . ($token === '' ? '' : "/{$token}");
    }

    /**
     * The largest file this installation can actually accept.
     *
     * PHP's own limits win whether we like it or not — a file over
     * upload_max_filesize never reaches the application, it just arrives empty.
     * Reporting the true ceiling lets the browser refuse it up front with an
     * explanation, instead of the upload appearing to do nothing.
     */
    public static function maxUploadBytes(): int
    {
        $limits = [self::MAX_FILE_BYTES];

        foreach (['upload_max_filesize', 'post_max_size'] as $setting) {
            $value = self::iniBytes((string) ini_get($setting));

            if ($value > 0) {
                $limits[] = $value;
            }
        }

        return min($limits);
    }

    /** "8M" and friends into bytes. */
    private static function iniBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '') {
            return 0;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g'     => $number * 1024 * 1024 * 1024,
            'm'     => $number * 1024 * 1024,
            'k'     => $number * 1024,
            default => $number,
        };
    }

    /** How much the upload directory is currently holding. */
    public static function totalFileBytes(): int
    {
        $root = self::filesPath();

        if (! is_dir($root)) {
            return 0;
        }

        $total = 0;

        foreach (glob($root . '/*') ?: [] as $file) {
            if (is_file($file)) {
                $total += (int) filesize($file);
            }
        }

        return $total;
    }

    /** Is there room for another upload of this size? */
    public static function hasRoomFor(int $bytes): bool
    {
        return (self::totalFileBytes() + $bytes) <= self::MAX_TOTAL_FILE_BYTES;
    }

    /**
     * Put an uploaded file on disk and describe it for the transcript.
     *
     * The stored name is a random token with no extension, so nothing about it
     * is caller-controlled and it can never be interpreted as a script by
     * anything. The original name travels as metadata only, for display and for
     * the download filename.
     *
     * @return array{token: string, name: string, mime: string, size: int, image: bool}|null
     */
    public static function storeFile(UploadedFile $file): ?array
    {
        $size = (int) $file->getSize();

        if ($size <= 0 || $size > self::maxUploadBytes() || ! self::hasRoomFor($size)) {
            return null;
        }

        $token = bin2hex(random_bytes(16));
        $root  = self::filesPath();

        if (! is_dir($root) && ! @mkdir($root, 0775, true) && ! is_dir($root)) {
            return null;
        }

        // The MIME is read from the file's own contents, never from the header the
        // browser supplied — that one is a claim, not a fact.
        $mime = $file->getMimeType() ?: 'application/octet-stream';

        try {
            $file->move($root, $token);
        } catch (\Throwable) {
            return null;
        }

        return [
            'token' => $token,
            'name'  => self::safeName($file->getClientOriginalName()),
            'mime'  => $mime,
            'size'  => $size,
            'image' => in_array($mime, self::INLINE_IMAGE_MIMES, true),
        ];
    }

    /**
     * A filename safe to show and to put in a download header.
     *
     * Any directory part is discarded and the result is bounded, so a name like
     * "../../.env" or a 4 KB string cannot travel with the message.
     */
    private static function safeName(?string $name): string
    {
        $name = basename(trim((string) $name));
        $name = preg_replace('/[\x00-\x1F\x7F"\\\\]+/u', '', $name) ?? '';
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            return 'file';
        }

        return mb_substr($name, 0, 120);
    }

    /**
     * Find a file in this conversation, by token.
     *
     * Authorisation and expiry both come out of this one lookup: a token is only
     * downloadable while it is named by a message in the caller's own
     * conversation. Once the buffer drains, the reference is gone and so is any
     * right to fetch it — no separate ownership table to keep in step.
     *
     * @return array{token: string, name: string, mime: string, size: int, image: bool}|null
     */
    public static function findFile(int $userId, int $peerId, string $token): ?array
    {
        foreach (self::transcript($userId, $peerId) as $message) {
            foreach ($message['files'] ?? [] as $file) {
                if (hash_equals((string) ($file['token'] ?? ''), $token)) {
                    return $file;
                }
            }
        }

        return null;
    }

    /**
     * Throw away files that were stored for a message that never happened.
     *
     * Uploads land on disk before the message naming them exists, so a send that
     * fails after that point has to clean up after itself — otherwise every
     * failed attempt leaves an orphan nothing will ever reference.
     *
     * @param  list<array{token: string}>  $files
     */
    public static function discardFiles(array $files): void
    {
        self::deleteFilesIn([['files' => $files]]);
    }

    /** Delete the files named by these messages. */
    private static function deleteFilesIn(array $messages): void
    {
        foreach ($messages as $message) {
            foreach ($message['files'] ?? [] as $file) {
                $token = (string) ($file['token'] ?? '');

                // Belt and braces: only ever unlink something that looks like one
                // of our tokens, so a corrupted entry cannot point at another path.
                if (preg_match('/^[a-f0-9]{32}$/', $token)) {
                    @unlink(self::filesPath($token));
                }
            }
        }
    }

    // ── Presence ─────────────────────────────────────────────────────────────

    /** Called on every poll; presence is a side effect of someone being there. */
    public static function heartbeat(int $userId): void
    {
        self::store()->put("online:{$userId}", time(), self::PRESENCE_TTL);
    }

    public static function isOnline(int $userId): bool
    {
        return self::store()->has("online:{$userId}");
    }

    /**
     * Which of these users are present, as id => bool.
     *
     * @param  list<int>  $userIds
     * @return array<int, bool>
     */
    public static function onlineMap(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $keys   = array_map(fn (int $id) => "online:{$id}", $userIds);
        $values = self::store()->many($keys);

        $online = [];

        foreach ($userIds as $id) {
            $online[$id] = ($values["online:{$id}"] ?? null) !== null;
        }

        return $online;
    }

    // ── Typing ───────────────────────────────────────────────────────────────

    public static function markTyping(int $userId, int $peerId): void
    {
        $key = self::key($userId, $peerId);

        self::store()->put("typing:{$key}:{$userId}", time(), self::TYPING_TTL);
    }

    public static function isTyping(int $peerId, int $userId): bool
    {
        $key = self::key($userId, $peerId);

        return self::store()->has("typing:{$key}:{$peerId}");
    }

    // ── Unread hints for the people list ─────────────────────────────────────

    /**
     * Remember how far this person has read.
     *
     * Also ephemeral: if the transcript expires, the pointer goes with it and
     * the conversation simply reads as empty rather than unread.
     */
    public static function markRead(int $userId, int $peerId, int $lastId): void
    {
        if ($lastId <= 0) {
            return;
        }

        $key = self::key($userId, $peerId);

        self::store()->put("seen:{$key}:{$userId}", $lastId, self::BUFFER_TTL);
    }

    /**
     * How far $peerId has read in their conversation with $userId.
     *
     * This is what read receipts are built on. The pointer only moves when
     * someone's open conversation collects messages, so it genuinely means "they
     * were looking at this" — not merely "it reached their device". There is no
     * separate delivered-but-unread signal to report, because nothing in this
     * design produces one.
     */
    public static function readPointer(int $userId, int $peerId): int
    {
        $key = self::key($userId, $peerId);

        return (int) self::store()->get("seen:{$key}:{$peerId}", 0);
    }

    /** How many messages from $peerId this person has not looked at yet. */
    public static function unreadCount(int $userId, int $peerId): int
    {
        $convo = self::store()->get(self::key($userId, $peerId));

        if (! is_array($convo)) {
            return 0;
        }

        $key  = self::key($userId, $peerId);
        $seen = (int) self::store()->get("seen:{$key}:{$userId}", 0);

        return count(array_filter(
            $convo['messages'] ?? [],
            fn ($message) => ($message['id'] ?? 0) > $seen && ($message['from'] ?? null) !== $userId,
        ));
    }

    /** Total waiting for this person, for the sidebar badge. */
    public static function totalUnread(int $userId): int
    {
        return self::peers($userId)->sum(fn (User $peer) => self::unreadCount($userId, $peer->id));
    }

    // ── People ───────────────────────────────────────────────────────────────

    /**
     * The people list with presence and what is waiting, ready for display.
     *
     * Anyone with unread messages sorts first, then whoever is online — so the
     * person you most likely need is at the top of a short list.
     *
     * @return \Illuminate\Support\Collection<int, array{user: User, online: bool, unread: int}>
     */
    public static function inbox(int $userId): \Illuminate\Support\Collection
    {
        $peers  = self::peers($userId);
        $online = self::onlineMap($peers->pluck('id')->all());

        return $peers->map(fn (User $peer) => [
            'user'   => $peer,
            'online' => $online[$peer->id] ?? false,
            'unread' => self::unreadCount($userId, $peer->id),
        ])->sortByDesc(fn ($row) => [$row['unread'] > 0, $row['online']])->values();
    }

    /**
     * Everyone this person can talk to: active accounts other than their own.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public static function peers(int $userId): \Illuminate\Support\Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereKeyNot($userId)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'pcr_role']);
    }
}
