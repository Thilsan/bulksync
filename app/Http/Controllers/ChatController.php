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
 * Nothing here touches the database except to look up who exists — the
 * conversation itself lives and dies in App\Support\EphemeralChat.
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
            ])->values(),
        ]);
    }

    public function show(#[CurrentUser] User $user, User $peer): View
    {
        $this->guard($user, $peer);

        $messages = EphemeralChat::transcript($user->id, $peer->id);

        EphemeralChat::heartbeat($user->id);
        EphemeralChat::markRead($user->id, $peer->id, collect($messages)->max('id') ?? 0);

        return view('chat.show', [
            'peer'         => $peer,
            'messages'     => $messages,
            'peerOnline'   => EphemeralChat::isOnline($peer->id),
            'idleMinutes'  => (int) (EphemeralChat::IDLE_TTL / 60),
            'maxMessages'  => EphemeralChat::MAX_MESSAGES,
            'maxLength'    => EphemeralChat::MAX_LENGTH,
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
         */
        $validator = Validator::make($request->all(), [
            'body' => ['required', 'string', 'max:' . EphemeralChat::MAX_LENGTH],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first('body')], 422);
        }

        $message = EphemeralChat::send($user->id, $peer->id, $validator->validated()['body']);

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
