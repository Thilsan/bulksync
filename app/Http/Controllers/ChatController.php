<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\EphemeralChat;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Direct chat between two signed-in people.
 *
 * Messages are end-to-end encrypted in the browser, so nothing here can read
 * one. This controller moves sealed envelopes around and stores the public keys
 * that let people address each other; the conversation itself lives and dies in
 * App\Support\EphemeralChat, as ciphertext.
 *
 * send() enforces that: a body which is not a sealed envelope is rejected, so a
 * bug in the client cannot quietly post readable text to the server.
 */
class ChatController extends Controller
{
    /** Who is around to talk to. */
    public function index(#[CurrentUser] User $user): View
    {
        $people = EphemeralChat::inbox($user->id);

        EphemeralChat::heartbeat($user->id);

        return view('chat.index', compact('people'));
    }

    /**
     * The people list as JSON, for the floating widget.
     *
     * Deliberately does not heartbeat: the widget polls this from every page in
     * the app, and answering it would mark someone "online" for chat while they
     * are busy doing something else entirely. Only an open conversation counts.
     */
    public function inbox(#[CurrentUser] User $user): JsonResponse
    {
        $people = EphemeralChat::inbox($user->id);

        return response()->json([
            'unread' => $people->sum('unread'),
            'people' => $people->map(fn ($row) => [
                'id'      => $row['user']->id,
                'name'    => $row['user']->name,
                'subtitle' => $row['user']->pcrRoleLabel() ?? $row['user']->email,
                'initials' => Str::of($row['user']->name)->explode(' ')->take(2)
                    ->map(fn ($word) => mb_substr($word, 0, 1))->implode(''),
                'online'  => $row['online'],
                'unread'  => $row['unread'],
                // Their public key travels with the list, so the browser can
                // encrypt to whoever you pick without a second request.
                'key'     => $row['user']->chat_public_key
                    ? json_decode($row['user']->chat_public_key, true)
                    : null,
            ])->values(),
        ]);
    }

    /**
     * Publish this browser's public key.
     *
     * Called on load. The private half is generated in the browser and never
     * sent — this is only the half other people need in order to write to you.
     *
     * Overwriting is allowed and expected: clearing your browser loses the
     * private key, so the next visit publishes a new pair. Old messages then
     * become unreadable, which is the honest consequence of the private key
     * having existed only on that machine.
     */
    public function publishKey(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'key'     => ['required', 'array'],
            'key.kty' => ['required', 'string', 'in:EC'],
            'key.crv' => ['required', 'string', 'in:P-256'],
            'key.x'   => ['required', 'string', 'max:128'],
            'key.y'   => ['required', 'string', 'max:128'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'That is not a usable public key.'], 422);
        }

        // Only the public fields are kept. If a client ever sent 'd' — the
        // private scalar — by mistake, it is dropped here rather than stored.
        $key = $validator->validated()['key'];

        $public = [
            'kty' => $key['kty'],
            'crv' => $key['crv'],
            'x'   => $key['x'],
            'y'   => $key['y'],
        ];

        $encoded = json_encode($public);

        // Only write when it actually changed, so chat_key_at means "rotated".
        if ($user->chat_public_key !== $encoded) {
            $user->update([
                'chat_public_key' => $encoded,
                'chat_key_at'     => now(),
            ]);
        }

        return response()->json(['published' => true]);
    }

    /**
     * The full-page view of one conversation.
     *
     * Deliberately renders no messages. The transcript belongs to the browser,
     * which loads it from its own storage — the server only knows what it still
     * has buffered, which is not the same thing and would show a truncated
     * conversation for a moment before the first poll corrected it.
     */
    public function show(#[CurrentUser] User $user, User $peer): View
    {
        $this->guard($user, $peer);

        EphemeralChat::heartbeat($user->id);

        return view('chat.show', [
            'peer'       => $peer,
            'peerOnline' => EphemeralChat::isOnline($peer->id),
            'maxLength'  => EphemeralChat::MAX_PLAINTEXT,
            // Their public key, so the page can encrypt without waiting on a poll.
            'peerKey'    => $peer->chat_public_key ? json_decode($peer->chat_public_key, true) : null,
        ]);
    }

    /**
     * Poll for anything new.
     *
     * Doubles as the presence heartbeat — an open chat window is what "online"
     * means here, so there is nothing else to keep alive.
     */
    public function messages(Request $request, #[CurrentUser] User $user, User $peer): JsonResponse
    {
        $this->guard($user, $peer);

        $after = max(0, (int) $request->query('after', 0));

        /*
         * If the caller's copy belongs to an older transcript, its message ids
         * mean nothing here — ids restart at 1 whenever a conversation is
         * rebuilt. Send the whole current transcript and tell the client to
         * replace what it has rather than merge into it.
         */
        $reset = $after > 0
            && ($clientEpoch = $request->query('epoch'))
            && $clientEpoch !== EphemeralChat::epoch($user->id, $peer->id);

        $result = EphemeralChat::since($user->id, $peer->id, $reset ? 0 : $after);

        EphemeralChat::heartbeat($user->id);

        $highest = collect($result['messages'])->max('id') ?? 0;
        EphemeralChat::markRead($user->id, $peer->id, $highest);

        return response()->json([
            'messages' => $result['messages'],
            'epoch'    => $result['epoch'],
            'reset'    => (bool) $reset,
            'online'   => EphemeralChat::isOnline($peer->id),
            'typing'   => EphemeralChat::isTyping($peer->id, $user->id),
            // How far they have read, for the receipts on our own messages.
            'peerRead' => EphemeralChat::readPointer($user->id, $peer->id),
            // The client was holding messages the server no longer has, so the
            // transcript expired underneath it. Say so rather than silently
            // showing a conversation that no longer exists.
            'expired'  => $after > 0 && ! $result['exists'],
        ]);
    }

    public function send(Request $request, #[CurrentUser] User $user, User $peer): JsonResponse
    {
        $this->guard($user, $peer);

        /*
         * Validated by hand rather than with $request->validate(), because this
         * app only renders exceptions as JSON under api/* (see bootstrap/app.php).
         * A thrown ValidationException here would redirect, and the composer is
         * a fetch() call that needs a status and a message it can show.
         *
         * The shape rules are the point, not politeness: a body must be a sealed
         * envelope — version, nonce, ciphertext — and nothing else is accepted.
         * If the browser code ever regressed and posted what someone typed, this
         * refuses it rather than storing readable text on the server.
         */
        $validator = Validator::make($request->all(), [
            'body'      => ['required', 'array'],
            'body.v'    => ['required', 'integer', 'in:1'],
            'body.iv'   => ['required', 'string', 'min:16', 'max:32'],
            'body.ct'   => ['required', 'string', 'min:1', 'max:' . EphemeralChat::MAX_LENGTH],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Message rejected: it was not sealed for the recipient.',
            ], 422);
        }

        // Stored as the compact JSON it arrived as. The server has no key and no
        // reason to look inside.
        $envelope = $validator->validated()['body'];

        $message = EphemeralChat::send($user->id, $peer->id, json_encode([
            'v'  => 1,
            'iv' => $envelope['iv'],
            'ct' => $envelope['ct'],
        ]));

        if ($message === null) {
            return response()->json(['message' => 'Message could not be sent — try again.'], 409);
        }

        // The epoch goes back too: this send may be what created the transcript,
        // and the sender's local copy needs to be stamped with it.
        return response()->json([
            'sent'  => $message,
            'epoch' => EphemeralChat::epoch($user->id, $peer->id),
        ]);
    }

    public function typing(#[CurrentUser] User $user, User $peer): JsonResponse
    {
        $this->guard($user, $peer);

        EphemeralChat::markTyping($user->id, $peer->id);

        return response()->json(['ok' => true]);
    }

    /** Either side can end a conversation for both of them. */
    public function clear(#[CurrentUser] User $user, User $peer): JsonResponse
    {
        $this->guard($user, $peer);

        EphemeralChat::clear($user->id, $peer->id);

        return response()->json(['cleared' => true]);
    }

    private function guard(User $user, User $peer): void
    {
        abort_if($peer->id === $user->id, 404, 'You cannot chat with yourself.');
        abort_unless($peer->is_active, 404, 'That person is no longer active.');
    }
}
