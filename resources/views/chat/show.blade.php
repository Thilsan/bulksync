@extends('layouts.app')
@section('title', 'Chat · ' . $peer->name)
@section('page-title', 'Chat')

@section('content')
{{--
    The full-page view of one conversation. The floating widget in the layout is
    the same feature in a smaller box; both read and write the same local history
    through window.chatHistory, so a conversation looks identical either way.

    Polls a JSON endpoint every two seconds — the same shape as the notification
    bell in the layout, because this app has no websocket process to talk to.

    The transcript is NOT rendered server-side. It cannot be: the history lives in
    this browser, and the server only knows what it still has buffered for
    delivery. The component loads from localStorage on init instead.
--}}
<div class="mx-auto flex h-[calc(100vh-9rem)] max-w-3xl flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm"
     x-data="chat({
         peerId: {{ $peer->id }},
         meId: {{ auth()->id() }},
         online: {{ $peerOnline ? 'true' : 'false' }},
         localKeep: {{ \App\Support\EphemeralChat::LOCAL_KEEP }},
         maxLength: {{ $maxLength }},
         peerKey: @js($peerKey),
         urls: {
             poll:   '{{ route('chat.messages', $peer) }}',
             send:   '{{ route('chat.send', $peer) }}',
             typing: '{{ route('chat.typing', $peer) }}',
             clear:  '{{ route('chat.clear', $peer) }}',
             keys:   '{{ route('chat.keys.publish') }}',
         },
     })"
     x-init="start()">

    {{-- Header --}}
    <div class="flex items-center gap-3 border-b border-gray-200 px-4 py-3">
        <a href="{{ route('chat.index') }}"
           class="-ml-1 rounded-lg p-1.5 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600" title="Back to people">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
            </svg>
        </a>

        <div class="relative shrink-0">
            <div class="flex h-9 w-9 items-center justify-center rounded-full bg-brand-50 text-xs font-semibold text-brand-700">
                {{ \Illuminate\Support\Str::of($peer->name)->explode(' ')->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('') }}
            </div>
            <span class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full ring-2 ring-white"
                  :class="online ? 'bg-emerald-500' : 'bg-gray-300'"></span>
        </div>

        <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-semibold text-gray-900">{{ $peer->name }}</p>
            <p class="truncate text-xs" :class="online ? 'text-emerald-600' : 'text-gray-400'">
                <span x-show="peerTyping" x-cloak class="text-gray-500">typing…</span>
                <span x-show="!peerTyping" x-text="online ? 'Online' : 'Offline'"></span>
            </p>
        </div>

        <button type="button" @click="clearConversation()"
                class="rounded-lg border border-gray-200 px-2.5 py-1.5 text-xs font-medium text-gray-500 transition-colors hover:border-red-200 hover:bg-red-50 hover:text-red-600"
                title="Delete from this browser. The other person keeps their own copy.">
            Clear
        </button>
    </div>

    {{-- Their key's fingerprint. Comparing these out loud is the only defence
         against this server handing over a substituted key. --}}
    <div x-show="fingerprint" x-cloak
         class="flex shrink-0 items-center gap-2 border-b border-gray-100 bg-gray-50 px-4 py-2">
        <svg class="h-3.5 w-3.5 shrink-0 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
        </svg>
        <span class="text-[11px] text-gray-500">Encrypted end-to-end. Their key</span>
        <span class="font-mono text-[11px] tracking-wide text-gray-700" x-text="fingerprint"></span>
    </div>

    <div x-show="cryptoError" x-cloak class="border-b border-red-100 bg-red-50 px-4 py-2 text-xs text-red-700" x-text="cryptoError"></div>

    {{-- Transcript --}}
    <div x-ref="scroller" class="flex-1 space-y-2 overflow-y-auto bg-gray-50/60 px-4 py-4">

        <template x-if="messages.length === 0">
            <div class="flex h-full flex-col items-center justify-center text-center">
                <svg class="h-8 w-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.9 9.9 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                </svg>
                <p class="mt-2 text-sm text-gray-500">No messages.</p>
                <p class="text-xs text-gray-400">This conversation is kept in your browser, not on the server.</p>
            </div>
        </template>

        {{-- Keyed by uid, not id: server ids restart at 1 each time the delivery
             buffer is rebuilt, so a long history contains several id 1s. --}}
        <template x-for="message in messages" :key="message.uid">
            <div class="flex" :class="message.from === meId ? 'justify-end' : 'justify-start'">
                {{-- Shown rather than hidden: an envelope that will not open still
                     means something was said, and silently dropping it would be a
                     lie about the conversation. --}}
                <div x-show="message.unreadable"
                     class="max-w-[75%] rounded-2xl rounded-bl-md border border-dashed border-amber-300 bg-amber-50 px-3.5 py-2 text-xs italic text-amber-700">
                    Cannot be decrypted — sealed to a key this browser no longer has.
                </div>

                <div x-show="!message.unreadable"
                     class="max-w-[75%] rounded-2xl px-3.5 py-2 text-sm shadow-sm"
                     :class="message.from === meId
                         ? 'rounded-br-md bg-brand-600 text-white'
                         : 'rounded-bl-md border border-gray-200 bg-white text-gray-800'">
                    <p class="whitespace-pre-wrap break-words" x-text="message.body"></p>
                    <p class="mt-1 flex items-center justify-end gap-1 text-[10px]"
                       :class="message.from === meId ? 'text-white/60' : 'text-gray-400'">
                        <span x-text="time(message.at)"></span>

                        {{-- One tick: the server took it. Two: their open
                             conversation collected it. --}}
                        <template x-if="message.from === meId">
                            <span class="inline-flex items-center" :class="message.read && 'text-sky-200'"
                                  :title="message.read ? 'Read' : 'Sent'">
                                <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 13l4 4L16 7"/>
                                </svg>
                                <svg x-show="message.read" class="-ml-1.5 h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 13l4 4L16 7"/>
                                </svg>
                            </span>
                        </template>
                    </p>
                </div>
            </div>
        </template>
    </div>

    {{-- Composer --}}
    <div class="border-t border-gray-200 px-3 py-3">
        <div x-show="error" x-cloak class="mb-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700" x-text="error"></div>

        @if(! $peerKey)
            <div class="mb-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                {{ $peer->name }} has not opened the app since encryption was switched on, so there is no key to
                encrypt to yet. Nothing can be sent until they do — a message cannot be sent in the clear.
            </div>
        @endif

        <form @submit.prevent="send()" class="flex items-end gap-2">
            <textarea x-ref="input" x-model="draft" rows="1"
                      maxlength="{{ $maxLength }}"
                      @input="grow(); announceTyping()"
                      @keydown.enter.exact.prevent="send()"
                      @disabled(! $peerKey)
                      placeholder="{{ $peerKey ? 'Write a message… (Enter sends, Shift+Enter for a new line)' : 'Cannot encrypt to them yet' }}"
                      class="max-h-32 min-h-[2.5rem] flex-1 resize-none rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-800 placeholder-gray-400 focus:border-brand-400 focus:outline-none focus:ring-1 focus:ring-brand-400 disabled:bg-gray-50"></textarea>

            <button type="submit" :disabled="sending || draft.trim() === ''" @disabled(! $peerKey)
                    class="inline-flex h-10 shrink-0 items-center gap-2 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-40">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                </svg>
                Send
            </button>
        </form>
    </div>
</div>

<script>
    function chat(config) {
        return {
            ...config,
            peerTyping: false,
            messages: [],
            epoch: null,
            cursor: 0,
            cryptoError: '',
            fingerprint: '',
            draft: '',
            sending: false,
            error: '',
            lastTypingPing: 0,
            csrf: document.querySelector('meta[name="csrf-token"]').content,

            start() {
                if (!window.chatCrypto.available()) {
                    this.cryptoError = 'This browser cannot encrypt messages, so chat is unavailable.';
                } else {
                    window.chatCrypto.start(this.urls.keys, this.csrf, this.meId)
                        .catch(() => { this.cryptoError = 'Could not set up encryption. Reload the page.'; });

                    window.chatCrypto.fingerprint(this.peerKey)
                        .then(value => { this.fingerprint = value || ''; })
                        .catch(() => {});
                }

                // The transcript comes from this browser, not from the page.
                const local = window.chatHistory.load(this.meId, this.peerId);
                this.messages = local.messages;
                this.epoch = local.epoch;
                this.cursor = local.cursor;

                this.$nextTick(() => this.toBottom());
                this.poll();
                setInterval(() => this.poll(), 2000);
            },

            saveLocal() {
                window.chatHistory.save(this.meId, this.peerId, {
                    messages: this.messages,
                    epoch: this.epoch,
                    cursor: this.cursor,
                }, this.localKeep);
            },

            /** Open the envelopes, then fold the plaintext into local history. */
            async absorb(payload) {
                if (payload.messages?.length && this.peerKey) {
                    payload = {
                        ...payload,
                        messages: await Promise.all(payload.messages.map(async message => {
                            const text = await window.chatCrypto.open(this.peerKey, this.envelopeOf(message));

                            return { ...message, body: text ?? null, unreadable: text === null };
                        })),
                    };
                }

                return this.store(payload);
            },

            envelopeOf(message) {
                if (message.body && typeof message.body === 'object') return message.body;

                try {
                    return JSON.parse(message.body);
                } catch (e) {
                    return null;
                }
            },

            store(payload) {
                const wasAtBottom = this.atBottom();

                const { state, added } = window.chatHistory.merge({
                    messages: this.messages,
                    epoch: this.epoch,
                    cursor: this.cursor,
                }, payload);

                const receipts = window.chatHistory.applyRead(state, this.meId, payload.peerRead);

                this.messages = receipts.state.messages;
                this.epoch = receipts.state.epoch;
                this.cursor = receipts.state.cursor;

                if (added > 0 || receipts.changed || payload.reset) {
                    this.saveLocal();
                    // Only follow the conversation if they were already at the
                    // bottom; yanking the view while someone reads back is worse
                    // than making them scroll down themselves. A receipt landing
                    // is never a reason to scroll.
                    if (added > 0 && wasAtBottom) this.$nextTick(() => this.toBottom());
                }

                return added;
            },

            async poll() {
                // A tab in the background still polls: presence and the unread
                // badge both depend on it, and the cost is one cache read.
                try {
                    const query = new URLSearchParams({ after: this.cursor });
                    if (this.epoch) query.set('epoch', this.epoch);

                    const response = await fetch(`${this.urls.poll}?${query}`, {
                        headers: { 'Accept': 'application/json' },
                    });

                    // An expired session redirects to the login page, which
                    // answers 200 with HTML. Reload so they get the login screen
                    // instead of a window that has quietly stopped updating.
                    if (response.redirected && response.url.includes('/login')) {
                        window.location.reload();
                        return;
                    }

                    if (!response.ok) return;

                    const data = await response.json();

                    this.online = data.online;
                    this.peerTyping = data.typing;

                    // The server's buffer expiring is not a reason to forget
                    // anything: this browser owns the history. A reset only means
                    // the id cursor has to start over.
                    await this.absorb(data);
                } catch (e) {
                    // A dropped poll is not worth surfacing — the next one is 2s away.
                }
            },

            async send() {
                const text = this.draft.trim();
                if (text === '' || this.sending) return;

                // No key, no message — there is deliberately no plaintext fallback.
                if (!this.peerKey) {
                    this.error = 'They have not opened the app yet, so there is no key to encrypt to.';
                    return;
                }

                this.sending = true;
                this.error = '';

                try {
                    const envelope = await window.chatCrypto.seal(this.peerKey, text);

                    const response = await fetch(this.urls.send, {
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

                    // Stored with the text we already have rather than decrypting
                    // our own envelope. Still merged, so it gets a uid and the
                    // cursor moves past it and the next poll cannot duplicate it.
                    this.store({ messages: [{ ...data.sent, body: text }], epoch: data.epoch, reset: false });
                    this.draft = '';
                    this.$nextTick(() => { this.grow(); this.toBottom(); });
                } catch (e) {
                    this.error = 'Message not sent — you may be offline.';
                } finally {
                    this.sending = false;
                    this.$refs.input.focus();
                }
            },

            announceTyping() {
                // One ping per typing window, not one per keystroke.
                const now = Date.now();
                if (now - this.lastTypingPing < 3000) return;
                this.lastTypingPing = now;

                fetch(this.urls.typing, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
                }).catch(() => {});
            },

            /*
             * Clears this browser's history and drains the server's buffer. It
             * cannot reach the other person's browser — their copy is theirs.
             */
            async clearConversation() {
                if (!confirm('Delete this conversation from this browser? The other person keeps their own copy.')) return;

                await fetch(this.urls.clear, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
                }).catch(() => {});

                window.chatHistory.forget(this.meId, this.peerId);

                this.messages = [];
                this.epoch = null;
                this.cursor = 0;
            },

            grow() {
                const el = this.$refs.input;
                el.style.height = 'auto';
                el.style.height = Math.min(el.scrollHeight, 128) + 'px';
            },

            atBottom() {
                const el = this.$refs.scroller;
                return el.scrollHeight - el.scrollTop - el.clientHeight < 60;
            },

            toBottom() {
                const el = this.$refs.scroller;
                el.scrollTop = el.scrollHeight;
            },

            time(unix) {
                return new Date(unix * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            },
        };
    }
</script>
@endsection
