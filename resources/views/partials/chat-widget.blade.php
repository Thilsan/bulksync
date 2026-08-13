{{--
    Floating chat, present on every page.

    Where the messages are:

    1. This browser keeps the history, in localStorage, via window.chatHistory
       (partials/chat-runtime.blade.php). It is not deleted when the server's
       delivery buffer expires — that is the whole arrangement. It goes when the
       person clears the conversation or signs out.
    2. The server only buffers a message long enough to hand it over, and never
       writes it to the database.

    So a conversation you were part of stays readable to you, while the company's
    server holds no record of it.
--}}
<div x-data="chatWidget({
        meId: {{ auth()->id() }},
        unread: {{ (int) ($chatUnreadCount ?? 0) }},
        maxLength: {{ \App\Support\EphemeralChat::MAX_PLAINTEXT }},
        localKeep: {{ \App\Support\EphemeralChat::LOCAL_KEEP }},
        urls: {
            inbox: '{{ route('chat.inbox') }}',
            keys:  '{{ route('chat.keys.publish') }}',
            base:  '{{ url('chat') }}',
            page:  '{{ route('chat.index') }}',
        },
     })"
     x-init="start()"
     @keydown.escape.window="open && close()"
     class="print:hidden">

    {{-- Panel. Sits above the launcher, full-height on a phone. --}}
    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 translate-y-3 scale-95"
         x-transition:enter-end="opacity-100 translate-y-0 scale-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100 translate-y-0 scale-100"
         x-transition:leave-end="opacity-0 translate-y-3 scale-95"
         class="fixed bottom-24 right-5 z-50 flex w-[22rem] max-w-[calc(100vw-2.5rem)] flex-col
                overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-2xl ring-1 ring-black/5
                max-sm:inset-x-3 max-sm:bottom-20 max-sm:w-auto max-sm:max-w-none"
         style="height: min(30rem, calc(100vh - 9rem))">

        {{-- Header: doubles as the back button once a conversation is open. --}}
        <div class="flex shrink-0 items-center gap-2.5 border-b border-gray-200 px-3 py-2.5">
            <button type="button" x-show="peer" x-cloak @click="closeConversation()"
                    class="-ml-1 rounded-lg p-1.5 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600"
                    aria-label="Back to people">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                </svg>
            </button>

            <template x-if="peer">
                <div class="relative shrink-0">
                    <div class="flex h-8 w-8 items-center justify-center rounded-full bg-brand-50 text-[11px] font-semibold text-brand-700"
                         x-text="peer.initials"></div>
                    <span class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full ring-2 ring-white"
                          :class="peer.online ? 'bg-emerald-500' : 'bg-gray-300'"></span>
                </div>
            </template>

            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold text-gray-900"
                   x-text="peer ? peer.name : 'Chat'"></p>
                <p class="truncate text-[11px]" :class="peer && peer.online ? 'text-emerald-600' : 'text-gray-400'">
                    <template x-if="!peer">
                        <span x-text="cryptoError || 'Encrypted end-to-end · kept in your browser'"
                              :class="cryptoError && 'text-red-600'"></span>
                    </template>
                    <template x-if="peer">
                        <span>
                            <span x-show="typing" class="text-gray-500">typing…</span>
                            <span x-show="!typing" x-text="peer.online ? 'Online' : 'Offline'"></span>
                        </span>
                    </template>
                </p>
            </div>

            <button type="button" x-show="peer" x-cloak @click="clearConversation()"
                    class="shrink-0 rounded-lg px-2 py-1 text-[11px] font-medium text-gray-400 transition-colors hover:bg-red-50 hover:text-red-600"
                    title="Delete from this browser. The other person keeps their own copy.">
                Clear
            </button>

            {{-- A chat that makes a noise needs a way to stop it. Remembered per browser. --}}
            <button type="button" @click="toggleMute()"
                    class="shrink-0 rounded-lg p-1.5 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600"
                    :title="muted ? 'Sound off — click to turn on' : 'Sound on — click to mute'"
                    :aria-pressed="muted">
                <svg x-show="!muted" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.536 8.464a5 5 0 010 7.072M17.95 6.05a8 8 0 010 11.9M5 9v6h2.5l4.5 4V5L7.5 9H5z"/>
                </svg>
                <svg x-show="muted" x-cloak class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 9v6h2.5l4.5 4V5L7.5 9H5zM17 9l4 4m0-4l-4 4"/>
                </svg>
            </button>

            <button type="button" @click="close()"
                    class="-mr-1 shrink-0 rounded-lg p-1.5 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600"
                    aria-label="Close chat">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        {{-- People list --}}
        <div x-show="!peer" class="flex-1 overflow-y-auto">
            <template x-for="person in people" :key="person.id">
                <button type="button" @click="openConversation(person)"
                        class="flex w-full items-center gap-3 border-b border-gray-100 px-3 py-2.5 text-left transition-colors last:border-b-0 hover:bg-gray-50">
                    <div class="relative shrink-0">
                        <div class="flex h-9 w-9 items-center justify-center rounded-full bg-brand-50 text-xs font-semibold text-brand-700"
                             x-text="person.initials"></div>
                        <span class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full ring-2 ring-white"
                              :class="person.online ? 'bg-emerald-500' : 'bg-gray-300'"></span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <p class="truncate text-sm font-medium text-gray-900" x-text="person.name"></p>
                            <span x-show="person.unread > 0"
                                  class="inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-brand-600 px-1 text-[10px] font-semibold text-white"
                                  x-text="person.unread > 9 ? '9+' : person.unread"></span>
                        </div>
                        <p class="truncate text-[11px] text-gray-500" x-text="person.subtitle"></p>
                    </div>
                </button>
            </template>

            <div x-show="people.length === 0" class="px-4 py-8 text-center text-sm text-gray-500">
                No one else to talk to yet.
            </div>
        </div>

        {{-- Conversation --}}
        <template x-if="peer">
            <div class="flex min-h-0 flex-1 flex-col">
                {{-- The fingerprint of their key. Two people reading the same eight
                     bytes to each other is the only way to be sure this server did
                     not hand over a substituted key. --}}
                <div x-show="fingerprint" x-cloak
                     class="flex shrink-0 items-center gap-1.5 border-b border-gray-100 bg-gray-50 px-3 py-1.5">
                    <svg class="h-3 w-3 shrink-0 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                    </svg>
                    <span class="text-[10px] text-gray-500">Their key</span>
                    <span class="font-mono text-[10px] tracking-wide text-gray-700" x-text="fingerprint"></span>
                </div>

                <div x-ref="scroller" class="flex-1 space-y-1.5 overflow-y-auto bg-gray-50/60 px-3 py-3">
                    <div x-show="messages.length === 0" class="flex h-full flex-col items-center justify-center px-6 text-center">
                        <p class="text-xs text-gray-400">No messages. Nothing said here is written down.</p>
                    </div>

                    {{-- Keyed by uid, not id: server ids restart at 1 each time the
                         buffer is rebuilt, so a long history has several id 1s. --}}
                    <template x-for="message in messages" :key="message.uid">
                        <div class="flex" :class="message.from === meId ? 'justify-end' : 'justify-start'">
                            {{-- An envelope that would not open is shown as such.
                                 Dropping it would quietly hide that something was
                                 said and could not be read. --}}
                            <div x-show="message.unreadable"
                                 class="max-w-[80%] rounded-2xl rounded-bl-md border border-dashed border-amber-300 bg-amber-50 px-3 py-1.5 text-[12px] italic text-amber-700">
                                Cannot be decrypted — sent to a key this browser no longer has.
                            </div>

                            <div x-show="!message.unreadable"
                                 class="max-w-[80%] rounded-2xl px-3 py-1.5 text-[13px] shadow-sm"
                                 :class="message.from === meId
                                     ? 'rounded-br-md bg-brand-600 text-white'
                                     : 'rounded-bl-md border border-gray-200 bg-white text-gray-800'">
                                <p class="whitespace-pre-wrap break-words" x-text="message.body"></p>
                                <p class="mt-0.5 flex items-center justify-end gap-1 text-[9px]"
                                   :class="message.from === meId ? 'text-white/60' : 'text-gray-400'">
                                    <span x-text="time(message.at)"></span>

                                    {{-- Receipts, on our own messages only. One tick
                                         means the server took it; two mean their open
                                         conversation collected it. --}}
                                    <template x-if="message.from === meId">
                                        <span class="inline-flex items-center" :class="message.read && 'text-sky-200'"
                                              :title="message.read ? 'Read' : 'Sent'">
                                            <svg class="h-2.5 w-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 13l4 4L16 7"/>
                                            </svg>
                                            <svg x-show="message.read" class="-ml-1.5 h-2.5 w-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 13l4 4L16 7"/>
                                            </svg>
                                        </span>
                                    </template>
                                </p>
                            </div>
                        </div>
                    </template>
                </div>

                <div class="shrink-0 border-t border-gray-200 p-2">
                    <p x-show="error" x-cloak class="mb-1.5 rounded-md bg-red-50 px-2 py-1 text-[11px] text-red-700" x-text="error"></p>

                    {{-- Nobody can write to someone who has no published key. Said
                         up front rather than after they have typed a message. --}}
                    <p x-show="peer && !peer.key" x-cloak class="mb-1.5 rounded-md bg-amber-50 px-2 py-1 text-[11px] text-amber-800">
                        No encryption key for <span x-text="peer?.name"></span> yet — they need to open the app once.
                    </p>

                    <form @submit.prevent="send()" class="flex items-end gap-1.5">
                        <textarea x-ref="input" x-model="draft" rows="1" :maxlength="maxLength"
                                  :disabled="peer && !peer.key"
                                  @input="grow(); announceTyping()"
                                  @keydown.enter.exact.prevent="send()"
                                  :placeholder="peer && !peer.key ? 'Cannot encrypt to them yet' : 'Message…'"
                                  class="max-h-24 min-h-[2.25rem] flex-1 resize-none rounded-lg border border-gray-200 px-2.5 py-1.5 text-[13px] text-gray-800 placeholder-gray-400 focus:border-brand-400 focus:outline-none focus:ring-1 focus:ring-brand-400"></textarea>

                        <button type="submit" :disabled="sending || draft.trim() === '' || (peer && !peer.key)"
                                class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-brand-600 text-white transition-colors hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-40"
                                aria-label="Send">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                            </svg>
                        </button>
                    </form>

                    {{-- The character budget only appears when it starts to matter. --}}
                    <p x-show="draft.length > maxLength * 0.8" x-cloak class="mt-1 text-right text-[10px] text-gray-400">
                        <span x-text="maxLength - draft.length"></span> characters left
                    </p>
                </div>
            </div>
        </template>
    </div>

    {{-- Launcher --}}
    {{--
        Toasts: who wrote, not what they wrote. Stacked above the launcher and
        below the panel, so an arriving message is visible from any page without
        covering the one you are reading. Clicking one opens that conversation.
    --}}
    <div x-show="toasts.length > 0 && !open" x-cloak
         class="fixed bottom-24 right-5 z-50 flex w-72 max-w-[calc(100vw-2.5rem)] flex-col gap-2">
        <template x-for="person in toasts" :key="'toast-' + person.id">
            <div x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 translate-x-4"
                 x-transition:enter-end="opacity-100 translate-x-0"
                 class="flex items-center gap-3 rounded-xl border border-gray-200 bg-white px-3 py-2.5 shadow-lg ring-1 ring-black/5">
                <button type="button" @click="openFromToast(person)" class="flex min-w-0 flex-1 items-center gap-3 text-left">
                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-50 text-[11px] font-semibold text-brand-700"
                         x-text="person.initials"></div>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold text-gray-900" x-text="person.name"></p>
                        <p class="text-[11px] text-gray-500">New message</p>
                    </div>
                </button>
                <button type="button" @click="dismiss(person.id)"
                        class="shrink-0 rounded-md p-1 text-gray-300 transition-colors hover:bg-gray-100 hover:text-gray-500"
                        aria-label="Dismiss">
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
        </template>
    </div>

    {{-- Launcher --}}
    <button type="button" @click="toggle()"
            class="fixed bottom-5 right-5 z-50 grid h-14 w-14 place-items-center rounded-full bg-brand-600 text-white shadow-lg
                   ring-1 ring-black/5 transition-all hover:bg-brand-700 hover:shadow-xl focus:outline-none focus:ring-2 focus:ring-brand-400 focus:ring-offset-2"
            :class="ring && 'chat-ring'"
            :aria-expanded="open" aria-label="Chat">
        <svg x-show="!open" class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.9 9.9 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
        </svg>
        <svg x-show="open" x-cloak class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
        </svg>

        {{-- A waiting count sits on the bubble even when the panel is shut. --}}
        <span x-show="unread > 0 && !open" x-cloak
              class="absolute -right-0.5 -top-0.5 grid h-5 min-w-5 place-items-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white ring-2 ring-white"
              x-text="unread > 9 ? '9+' : unread"></span>
    </button>
</div>

<style>
    /* One pulse when something arrives — enough to catch the eye at the edge of
       vision without becoming a permanently animated page element. */
    .chat-ring { animation: chat-ring 1.4s ease-out 1; }
    @keyframes chat-ring {
        0%   { box-shadow: 0 0 0 0 rgba(48, 131, 166, .55); }
        70%  { box-shadow: 0 0 0 14px rgba(48, 131, 166, 0); }
        100% { box-shadow: 0 0 0 0 rgba(48, 131, 166, 0); }
    }
    @media (prefers-reduced-motion: reduce) { .chat-ring { animation: none; } }
</style>

<script>
    function chatWidget(config) {
        return {
            ...config,
            open: false,
            people: [],
            peer: null,
            messages: [],
            epoch: null,
            draft: '',
            sending: false,
            typing: false,
            error: '',
            lastTypingPing: 0,
            timer: null,
            cursor: 0,
            // Last poll's unread count per person, so an increase can be spotted.
            seenUnread: {},
            toasts: [],
            ring: false,
            muted: false,
            cryptoError: '',
            fingerprint: '',
            csrf: document.querySelector('meta[name="csrf-token"]').content,

            // ── Local history (window.chatHistory) ──────────────────────────
            saveLocal() {
                if (!this.peer) return;

                window.chatHistory.save(this.meId, this.peer.id, {
                    messages: this.messages,
                    epoch: this.epoch,
                    cursor: this.cursor,
                }, this.localKeep);
            },

            /**
             * Unseal a batch, then fold it into the local history.
             *
             * Envelopes are opened here, once, and the history keeps plaintext —
             * so scrolling back does not re-run the crypto for every message.
             * Anything that will not open is kept as a visible placeholder rather
             * than dropped, because a message that silently vanishes is worse
             * than one you can see you cannot read.
             */
            async absorb(payload) {
                if (payload.messages?.length && this.peer?.key) {
                    payload = {
                        ...payload,
                        messages: await Promise.all(payload.messages.map(async message => {
                            const text = await window.chatCrypto.open(this.peer.key, this.envelopeOf(message));

                            return {
                                ...message,
                                body: text ?? null,
                                unreadable: text === null,
                            };
                        })),
                    };
                }

                return this.store(payload);
            },

            /** The stored body is compact JSON; tolerate it arriving either way. */
            envelopeOf(message) {
                if (message.body && typeof message.body === 'object') return message.body;

                try {
                    return JSON.parse(message.body);
                } catch (e) {
                    return null;
                }
            },

            /** Fold a decrypted response in, then persist and scroll if it added anything. */
            store(payload) {
                const wasAtBottom = this.atBottom();

                const { state, added } = window.chatHistory.merge({
                    messages: this.messages,
                    epoch: this.epoch,
                    cursor: this.cursor,
                }, payload);

                // Receipts applied after the merge, so a message that arrived and
                // was already read shows as read on its first render.
                const receipts = window.chatHistory.applyRead(state, this.meId, payload.peerRead);

                this.messages = receipts.state.messages;
                this.epoch = receipts.state.epoch;
                this.cursor = receipts.state.cursor;

                if (added > 0 || receipts.changed || payload.reset) {
                    this.saveLocal();
                    if (added > 0 && wasAtBottom) this.$nextTick(() => this.toBottom());
                }

                return added;
            },

            // ── Lifecycle ──────────────────────────────────────────────────
            start() {
                this.muted = localStorage.getItem('bulksync.chat.muted') === '1';

                /*
                 * Publish this browser's public key before anything else. Until
                 * it lands, colleagues have nothing to encrypt to — so a failure
                 * here is shown rather than swallowed.
                 */
                if (!window.chatCrypto.available()) {
                    this.cryptoError = 'This browser cannot encrypt messages, so chat is unavailable.';
                } else {
                    window.chatCrypto.start(this.urls.keys, this.csrf, this.meId)
                        .catch(() => { this.cryptoError = 'Could not set up encryption. Reload the page.'; });
                }

                window.tabBadge('chat', this.unread);
                this.$watch('unread', value => window.tabBadge('chat', value));

                this.refreshInbox();

                /*
                 * Kept running even while a conversation is open, because a
                 * message from a third person still has to announce itself.
                 * Paused on a hidden tab, matching the notification bell — there
                 * is nobody to announce it to, and the badge is recalculated the
                 * moment the tab comes back.
                 */
                setInterval(() => {
                    if (!document.hidden) this.refreshInbox();
                }, 5000);

                document.addEventListener('visibilitychange', () => {
                    if (!document.hidden) this.refreshInbox();
                });
            },

            toggle() {
                this.open ? this.close() : this.openPanel();
            },

            openPanel() {
                this.open = true;
                this.refreshInbox();
            },

            close() {
                this.open = false;
                this.closeConversation();
            },

            // ── People ─────────────────────────────────────────────────────
            async refreshInbox() {
                try {
                    const response = await fetch(this.urls.inbox, { headers: { 'Accept': 'application/json' } });
                    if (response.redirected || !response.ok) return;

                    const data = await response.json();

                    this.announceNewMessages(data.people);

                    this.people = data.people;
                    this.unread = data.unread;

                    // A peer can rotate keys mid-conversation (they cleared their
                    // browser). Pick up the new one so replies still reach them.
                    if (this.peer) {
                        const fresh = data.people.find(person => person.id === this.peer.id);
                        if (fresh) this.peer.key = fresh.key;
                    }
                } catch (e) {
                    // Offline or a dropped request; the next tick will catch up.
                }
            },

            // ── Announcing ─────────────────────────────────────────────────
            /**
             * Compare each person's unread count with the last poll's.
             *
             * Per person rather than on the total, so two people writing at once
             * are two separate announcements, and so a count going down (someone
             * read their messages elsewhere) is never mistaken for something new.
             */
            announceNewMessages(people) {
                const before = this.seenUnread;
                const after = {};
                let arrived = [];

                people.forEach(person => {
                    after[person.id] = person.unread;

                    // Undefined on the very first poll — a page load should badge
                    // what is waiting, not shout about messages from an hour ago.
                    if (before[person.id] !== undefined && person.unread > before[person.id]) {
                        arrived.push(person);
                    }
                });

                this.seenUnread = after;

                // The open conversation shows its own messages arriving; toasting
                // and beeping on top of that is just noise.
                arrived = arrived.filter(person => !this.peer || person.id !== this.peer.id);

                if (!arrived.length) return;

                this.ring = true;
                setTimeout(() => this.ring = false, 1400);
                this.beep();

                // Three at once is a summary, not three toasts — same rule the
                // notification bell uses.
                arrived.slice(0, 3).forEach(person => this.toast(person));
            },

            toast(person) {
                // Who wrote, never what they wrote: this pops up over whatever is
                // on screen, which may not be for the room to read.
                this.toasts.push(person);
                setTimeout(() => { this.toasts = this.toasts.filter(t => t.id !== person.id); }, 9000);
            },

            dismiss(id) {
                this.toasts = this.toasts.filter(t => t.id !== id);
            },

            openFromToast(person) {
                this.dismiss(person.id);
                this.open = true;
                this.openConversation(person);
            },

            toggleMute() {
                this.muted = !this.muted;
                try { localStorage.setItem('bulksync.chat.muted', this.muted ? '1' : '0'); } catch (e) {}
            },

            /**
             * A short tone, synthesised rather than downloaded.
             *
             * No audio file to serve, and nothing to load before the first
             * message can announce itself. Browsers refuse to make sound before
             * the page has been interacted with, so an early message may badge
             * silently — which is the correct trade for not being blocked.
             */
            beep() {
                if (this.muted) return;

                try {
                    const Ctx = window.AudioContext || window.webkitAudioContext;
                    if (!Ctx) return;

                    const ctx = new Ctx();
                    const osc = ctx.createOscillator();
                    const gain = ctx.createGain();

                    osc.connect(gain);
                    gain.connect(ctx.destination);
                    osc.type = 'sine';
                    osc.frequency.setValueAtTime(880, ctx.currentTime);
                    osc.frequency.setValueAtTime(1170, ctx.currentTime + 0.09);

                    // Ramped rather than switched on, so it reads as a soft note
                    // instead of a click.
                    gain.gain.setValueAtTime(0.0001, ctx.currentTime);
                    gain.gain.exponentialRampToValueAtTime(0.06, ctx.currentTime + 0.02);
                    gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.22);

                    osc.start();
                    osc.stop(ctx.currentTime + 0.24);
                    setTimeout(() => ctx.close().catch(() => {}), 500);
                } catch (e) {
                    // Audio unavailable or blocked — the badge and toast carry it.
                }
            },

            // ── Conversation ───────────────────────────────────────────────
            openConversation(person) {
                this.peer = person;
                this.error = '';
                this.draft = '';
                this.fingerprint = '';

                // This browser's history is the transcript. Show it at once, then
                // ask the server only for what it has not delivered yet.
                const local = window.chatHistory.load(this.meId, person.id);
                this.messages = local.messages;
                this.epoch = local.epoch;
                this.cursor = local.cursor;

                // Shown so two people can confirm out loud that they hold each
                // other's real keys, which is the only check on this server having
                // handed over a substituted one.
                window.chatCrypto.fingerprint(person.key)
                    .then(value => { this.fingerprint = value || ''; })
                    .catch(() => {});

                this.$nextTick(() => this.toBottom());

                this.pollConversation();
                this.timer = setInterval(() => this.pollConversation(), 2000);
            },

            closeConversation() {
                clearInterval(this.timer);
                this.timer = null;
                this.peer = null;
                this.messages = [];
                this.epoch = null;
                this.cursor = 0;
                this.typing = false;
                this.refreshInbox();
            },

            async pollConversation() {
                if (!this.peer) return;

                const query = new URLSearchParams({ after: this.cursor });
                if (this.epoch) query.set('epoch', this.epoch);

                try {
                    const response = await fetch(`${this.urls.base}/${this.peer.id}/messages?${query}`, {
                        headers: { 'Accept': 'application/json' },
                    });

                    // Session expired: the poll landed on the login page.
                    if (response.redirected && response.url.includes('/login')) {
                        window.location.reload();
                        return;
                    }
                    if (!response.ok) return;

                    const data = await response.json();

                    this.peer.online = data.online;
                    this.typing = data.typing;

                    /*
                     * Note what is NOT here: the server saying its buffer expired
                     * is not a reason to drop anything. The history is this
                     * browser's, and it outlives the server's copy on purpose.
                     * A reset only means our id cursor has to start over.
                     */
                    await this.absorb(data);
                } catch (e) {
                    // Ignored on purpose; polling again in 2s.
                }
            },

            async send() {
                const text = this.draft.trim();
                if (text === '' || this.sending || !this.peer) return;

                // No key, no message. There is deliberately no plaintext path to
                // fall back to: silently sending in the clear because encryption
                // was unavailable is the one failure that must never happen.
                if (!this.peer.key) {
                    this.error = `${this.peer.name} has not opened the app yet, so there is no key to encrypt to.`;
                    return;
                }

                this.sending = true;
                this.error = '';

                try {
                    const envelope = await window.chatCrypto.seal(this.peer.key, text);

                    const response = await fetch(`${this.urls.base}/${this.peer.id}/messages`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': this.csrf,
                        },
                        body: JSON.stringify({ body: envelope }),
                    });

                    if (!response.ok) {
                        const problem = await response.json().catch(() => ({}));
                        this.error = problem.message || 'Message not sent. Try again.';
                        return;
                    }

                    const data = await response.json();

                    /*
                     * Stored with the text we just typed. ECDH agrees one shared
                     * key per pair, so we could open our own envelope just as the
                     * recipient will — there is simply no reason to, when the
                     * plaintext is right here. store() rather than absorb() skips
                     * that pointless round trip, while still merging so the message
                     * gets a uid and the cursor moves past it; otherwise the next
                     * poll would hand it back and show it twice.
                     */
                    this.store({
                        messages: [{ ...data.sent, body: text }],
                        epoch: data.epoch,
                        reset: false,
                    });

                    this.draft = '';
                    this.$nextTick(() => { this.grow(); this.toBottom(); });
                } catch (e) {
                    this.error = 'Message not sent — encryption failed or you are offline.';
                } finally {
                    this.sending = false;
                    this.$refs.input?.focus();
                }
            },

            announceTyping() {
                const now = Date.now();
                if (now - this.lastTypingPing < 3000 || !this.peer) return;
                this.lastTypingPing = now;

                fetch(`${this.urls.base}/${this.peer.id}/typing`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
                }).catch(() => {});
            },

            /*
             * Clear is honest about its reach: it deletes this browser's history
             * and drains the server's buffer, but it cannot reach into the other
             * person's browser — their copy is theirs.
             */
            async clearConversation() {
                if (!this.peer) return;
                if (!confirm('Delete this conversation from this browser? The other person keeps their own copy.')) return;

                const peerId = this.peer.id;

                await fetch(`${this.urls.base}/${peerId}`, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
                }).catch(() => {});

                window.chatHistory.forget(this.meId, peerId);

                this.messages = [];
                this.epoch = null;
                this.cursor = 0;
            },

            // ── Small helpers ──────────────────────────────────────────────
            grow() {
                const el = this.$refs.input;
                if (!el) return;
                el.style.height = 'auto';
                el.style.height = Math.min(el.scrollHeight, 96) + 'px';
            },

            atBottom() {
                const el = this.$refs.scroller;
                return !el || el.scrollHeight - el.scrollTop - el.clientHeight < 60;
            },

            toBottom() {
                const el = this.$refs.scroller;
                if (el) el.scrollTop = el.scrollHeight;
            },

            time(unix) {
                return new Date(unix * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            },
        };
    }
</script>
