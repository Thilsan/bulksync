{{--
    Floating chat, present on every page.

    Two things worth knowing about how this stores messages:

    1. The server keeps a conversation only while it is active (see
       App\Support\EphemeralChat) and never writes it to the database.
    2. Each browser also keeps its own copy in localStorage, so a page load or a
       navigation does not wipe what you were reading. That copy lives on the
       person's own machine, is capped, and is cleared when they clear the
       conversation or sign out.

    Because ids restart at 1 whenever a transcript is rebuilt, every local copy
    is stamped with the server's epoch; a changed epoch means discard and reload.
--}}
<div x-data="chatWidget({
        meId: {{ auth()->id() }},
        unread: {{ (int) ($chatUnreadCount ?? 0) }},
        maxLength: {{ \App\Support\EphemeralChat::MAX_LENGTH }},
        localKeep: {{ \App\Support\EphemeralChat::MAX_MESSAGES }},
        idleMinutes: {{ (int) (\App\Support\EphemeralChat::IDLE_TTL / 60) }},
        urls: {
            inbox: '{{ route('chat.inbox') }}',
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
                        <span>Nothing is saved — <span x-text="idleMinutes"></span> min of quiet and it's gone</span>
                    </template>
                    <template x-if="peer">
                        <span>
                            <span x-show="typing" class="text-gray-500">typing…</span>
                            <span x-show="!typing" x-text="peer.online ? 'Online' : 'Offline — will not receive this'"></span>
                        </span>
                    </template>
                </p>
            </div>

            <button type="button" x-show="peer" x-cloak @click="clearConversation()"
                    class="shrink-0 rounded-lg px-2 py-1 text-[11px] font-medium text-gray-400 transition-colors hover:bg-red-50 hover:text-red-600"
                    title="Delete for both of you, and from this browser">
                Clear
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
                <div x-ref="scroller" class="flex-1 space-y-1.5 overflow-y-auto bg-gray-50/60 px-3 py-3">
                    <div x-show="messages.length === 0" class="flex h-full flex-col items-center justify-center px-6 text-center">
                        <p class="text-xs text-gray-400">No messages. Nothing said here is written down.</p>
                    </div>

                    <template x-for="message in messages" :key="message.id">
                        <div class="flex" :class="message.from === meId ? 'justify-end' : 'justify-start'">
                            <div class="max-w-[80%] rounded-2xl px-3 py-1.5 text-[13px] shadow-sm"
                                 :class="message.from === meId
                                     ? 'rounded-br-md bg-brand-600 text-white'
                                     : 'rounded-bl-md border border-gray-200 bg-white text-gray-800'">
                                <p class="whitespace-pre-wrap break-words" x-text="message.body"></p>
                                <p class="mt-0.5 text-right text-[9px]"
                                   :class="message.from === meId ? 'text-white/60' : 'text-gray-400'"
                                   x-text="time(message.at)"></p>
                            </div>
                        </div>
                    </template>
                </div>

                <div class="shrink-0 border-t border-gray-200 p-2">
                    <p x-show="error" x-cloak class="mb-1.5 rounded-md bg-red-50 px-2 py-1 text-[11px] text-red-700" x-text="error"></p>

                    <form @submit.prevent="send()" class="flex items-end gap-1.5">
                        <textarea x-ref="input" x-model="draft" rows="1" :maxlength="maxLength"
                                  @input="grow(); announceTyping()"
                                  @keydown.enter.exact.prevent="send()"
                                  placeholder="Message…"
                                  class="max-h-24 min-h-[2.25rem] flex-1 resize-none rounded-lg border border-gray-200 px-2.5 py-1.5 text-[13px] text-gray-800 placeholder-gray-400 focus:border-brand-400 focus:outline-none focus:ring-1 focus:ring-brand-400"></textarea>

                        <button type="submit" :disabled="sending || draft.trim() === ''"
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
    <button type="button" @click="toggle()"
            class="fixed bottom-5 right-5 z-50 grid h-14 w-14 place-items-center rounded-full bg-brand-600 text-white shadow-lg
                   ring-1 ring-black/5 transition-all hover:bg-brand-700 hover:shadow-xl focus:outline-none focus:ring-2 focus:ring-brand-400 focus:ring-offset-2"
            :aria-expanded="open" aria-label="Chat">
        <svg x-show="!open" class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.9 9.9 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
        </svg>
        <svg x-show="open" x-cloak class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
        </svg>

        <span x-show="unread > 0 && !open" x-cloak
              class="absolute -right-0.5 -top-0.5 grid h-5 min-w-5 place-items-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white ring-2 ring-white"
              x-text="unread > 9 ? '9+' : unread"></span>
    </button>
</div>

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
            csrf: document.querySelector('meta[name="csrf-token"]').content,

            // ── Local copy, kept on this machine only ───────────────────────
            storeKey(peerId) {
                return `bulksync.chat.${this.meId}.${peerId}`;
            },

            loadLocal(peerId) {
                try {
                    const raw = localStorage.getItem(this.storeKey(peerId));
                    if (!raw) return { epoch: null, messages: [] };
                    const saved = JSON.parse(raw);
                    return { epoch: saved.epoch ?? null, messages: saved.messages ?? [] };
                } catch (e) {
                    return { epoch: null, messages: [] };
                }
            },

            saveLocal() {
                if (!this.peer) return;
                try {
                    localStorage.setItem(this.storeKey(this.peer.id), JSON.stringify({
                        epoch: this.epoch,
                        // Capped so a browser cannot accumulate transcripts for ever.
                        messages: this.messages.slice(-this.localKeep),
                    }));
                } catch (e) {
                    // Storage full or blocked (private window) — the conversation
                    // still works, it just will not survive a reload.
                }
            },

            forgetLocal(peerId) {
                try { localStorage.removeItem(this.storeKey(peerId)); } catch (e) {}
            },

            // ── Lifecycle ──────────────────────────────────────────────────
            start() {
                this.refreshInbox();
                // Slow background tick for the badge; the conversation poll below
                // runs faster and only while one is actually open.
                setInterval(() => { if (!this.peer) this.refreshInbox(); }, 15000);
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

            get lastId() {
                return this.messages.length ? this.messages[this.messages.length - 1].id : 0;
            },

            // ── People ─────────────────────────────────────────────────────
            async refreshInbox() {
                try {
                    const response = await fetch(this.urls.inbox, { headers: { 'Accept': 'application/json' } });
                    if (response.redirected || !response.ok) return;

                    const data = await response.json();
                    this.people = data.people;
                    this.unread = data.unread;
                } catch (e) {
                    // Offline or a dropped request; the next tick will catch up.
                }
            },

            // ── Conversation ───────────────────────────────────────────────
            openConversation(person) {
                this.peer = person;
                this.error = '';
                this.draft = '';

                // Show this browser's own copy immediately, then reconcile with
                // the server — so opening a chat is never a blank box.
                const local = this.loadLocal(person.id);
                this.epoch = local.epoch;
                this.messages = local.messages;

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
                this.typing = false;
                this.refreshInbox();
            },

            async pollConversation() {
                if (!this.peer) return;

                const query = new URLSearchParams({ after: this.lastId });
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

                    // The transcript on the server was rebuilt, so ids we hold
                    // refer to a different conversation. Replace, do not merge.
                    if (data.reset) {
                        this.messages = data.messages;
                        this.epoch = data.epoch;
                        this.saveLocal();
                        this.$nextTick(() => this.toBottom());
                        return;
                    }

                    // Expired with nothing new: the conversation is over. Drop the
                    // local copy too, so it does not reappear on the next visit.
                    if (data.expired) {
                        this.messages = [];
                        this.epoch = null;
                        this.forgetLocal(this.peer.id);
                        return;
                    }

                    if (data.messages.length) {
                        const wasAtBottom = this.atBottom();
                        this.messages.push(...data.messages);
                        this.epoch = data.epoch ?? this.epoch;
                        this.saveLocal();
                        if (wasAtBottom) this.$nextTick(() => this.toBottom());
                    } else if (data.epoch && !this.epoch) {
                        this.epoch = data.epoch;
                        this.saveLocal();
                    }
                } catch (e) {
                    // Ignored on purpose; polling again in 2s.
                }
            },

            async send() {
                const body = this.draft.trim();
                if (body === '' || this.sending || !this.peer) return;

                this.sending = true;
                this.error = '';

                try {
                    const response = await fetch(`${this.urls.base}/${this.peer.id}/messages`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': this.csrf,
                        },
                        body: JSON.stringify({ body }),
                    });

                    if (!response.ok) {
                        const problem = await response.json().catch(() => ({}));
                        this.error = problem.message || 'Message not sent. Try again.';
                        return;
                    }

                    const data = await response.json();

                    this.messages.push(data.sent);
                    this.epoch = data.epoch ?? this.epoch;
                    this.saveLocal();

                    this.draft = '';
                    this.$nextTick(() => { this.grow(); this.toBottom(); });
                } catch (e) {
                    this.error = 'Message not sent — you may be offline.';
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

            async clearConversation() {
                if (!this.peer) return;
                if (!confirm('Delete this conversation for both of you? It cannot be recovered.')) return;

                const peerId = this.peer.id;

                await fetch(`${this.urls.base}/${peerId}`, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
                }).catch(() => {});

                this.messages = [];
                this.epoch = null;
                this.forgetLocal(peerId);
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
