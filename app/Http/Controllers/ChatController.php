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
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Direct chat between two signed-in people.
 *
 * Nothing here touches the database except to look up who exists — a message
 * lives only in the delivery buffer in App\Support\EphemeralChat, and the
 * lasting copy belongs to each person's browser.
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
            'maxLength'  => EphemeralChat::MAX_LENGTH,
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
         * Either a body or a file is enough — an image on its own is a message.
         */
        $validator = Validator::make($request->all(), [
            'body'    => ['nullable', 'string', 'max:' . EphemeralChat::MAX_LENGTH],
            'files'   => ['nullable', 'array', 'max:' . EphemeralChat::MAX_FILES_PER_MESSAGE],
            // 'file' rather than 'image': anything may be sent, and the type is
            // read from the contents when it is stored.
            'files.*' => ['file', 'max:' . (int) (EphemeralChat::maxUploadBytes() / 1024)],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $body    = (string) $request->input('body', '');
        $uploads = $request->file('files', []);

        if (trim($body) === '' && $uploads === []) {
            return response()->json(['message' => 'Nothing to send.'], 422);
        }

        /*
         * Files are written first, then named by the message. If the message
         * itself cannot be stored the files are removed again, so a failed send
         * never leaves anything behind on disk.
         */
        $stored = [];

        foreach ($uploads as $upload) {
            $file = EphemeralChat::storeFile($upload);

            if ($file === null) {
                EphemeralChat::discardFiles($stored);

                return response()->json([
                    'message' => 'That file could not be attached — it may be too large, or storage is full.',
                ], 422);
            }

            $stored[] = $file;
        }

        $message = EphemeralChat::send($user->id, $peer->id, $body, $stored);

        if ($message === null) {
            EphemeralChat::discardFiles($stored);

            return response()->json(['message' => 'Message could not be sent — try again.'], 409);
        }

        // The epoch goes back too: this send may be what created the transcript,
        // and the sender's local copy needs to be stamped with it.
        return response()->json([
            'sent'  => $message,
            'epoch' => EphemeralChat::epoch($user->id, $peer->id),
        ]);
    }

    /**
     * Serve one attachment from a conversation.
     *
     * Two things this must never become: a way to read files it was not meant to,
     * and a way to run someone else's markup on this origin.
     *
     * The first is handled by looking the token up inside the caller's own
     * conversation — a token that is not named by a message they can see does not
     * resolve, so there is nothing to enumerate and no path to traverse.
     *
     * The second is handled by the headers. Only a short allowlist of image types
     * is shown inline; everything else is forced to download, because a file that
     * renders in the browser can carry script — an HTML file or an SVG most
     * obviously. nosniff stops the browser second-guessing the type we declare.
     */
    public function download(#[CurrentUser] User $user, User $peer, string $token): BinaryFileResponse
    {
        $this->guard($user, $peer);

        // The token shape is checked before it is used for anything at all.
        abort_unless(preg_match('/^[a-f0-9]{32}$/', $token) === 1, 404);

        $file = EphemeralChat::findFile($user->id, $peer->id, $token);

        abort_if($file === null, 404, 'That file is no longer available.');

        $path = EphemeralChat::filesPath($token);

        abort_unless(is_file($path), 404, 'That file is no longer available.');

        $inline = in_array($file['mime'], EphemeralChat::INLINE_IMAGE_MIMES, true);

        return response()->file($path, [
            'Content-Type'              => $inline ? $file['mime'] : 'application/octet-stream',
            'Content-Disposition'       => ($inline ? 'inline' : 'attachment')
                . '; filename="' . $file['name'] . '"',
            'X-Content-Type-Options'    => 'nosniff',
            // Not something to keep: the file is gone within the delivery window.
            'Cache-Control'             => 'private, max-age=300, no-transform',
            'Content-Security-Policy'   => "default-src 'none'; sandbox",
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
