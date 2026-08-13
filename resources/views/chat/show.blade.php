@extends('layouts.app')
@section('title', 'Chat · ' . $peer->name)
@section('page-title', 'Chat')

@section('content')
{{--
    The whole conversation is one Alpine component polling a JSON endpoint every
    two seconds — the same shape as the notification bell in the layout, because
    this app has no websocket process to talk to. Two seconds is fast enough to
    feel live for a handful of internal users and cheap enough that an idle
    window costs nothing but a cache read.
--}}
<div class="mx-auto flex h-[calc(100vh-9rem)] max-w-3xl flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm"
     x-data="chat({
         peerId: {{ $peer->id }},
         meId: {{ auth()->id() }},
         online: {{ $peerOnline ? 'true' : 'false' }},
         messages: @js($messages),
         urls: {
             poll:   '{{ route('chat.messages', $peer) }}',
             send:   '{{ route('chat.send', $peer) }}',
             typing: '{{ route('chat.typing', $peer) }}',
             clear:  '{{ route('chat.clear', $peer) }}',
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
                <span x-show="!peerTyping" x-text="online ? 'Online' : 'Offline — messages will not reach them'"></span>
            </p>
        </div>

        <button type="button" @click="clearConversation()"
                class="rounded-lg border border-gray-200 px-2.5 py-1.5 text-xs font-medium text-gray-500 transition-colors hover:border-red-200 hover:bg-red-50 hover:text-red-600"
                title="Delete this conversation for both of you">
            Clear
        </button>
    </div>

    {{-- Transcript --}}
    <div x-ref="scroller" class="flex-1 space-y-2 overflow-y-auto bg-gray-50/60 px-4 py-4">

        <template x-if="messages.length === 0">
            <div class="flex h-full flex-col items-center justify-center text-center">
                <svg class="h-8 w-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.9 9.9 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                </svg>
                <p class="mt-2 text-sm text-gray-500">No messages.</p>
                <p class="text-xs text-gray-400">Nothing said here is written down.</p>
            </div>
        </template>

        {{-- Shown when the transcript expired while the window was open. --}}
        <template x-if="expired">
            <div class="flex items-center gap-3 py-2">
                <div class="h-px flex-1 bg-gray-200"></div>
                <span class="text-[11px] font-medium uppercase tracking-wide text-gray-400">Conversation cleared</span>
                <div class="h-px flex-1 bg-gray-200"></div>
            </div>
        </template>

        <template x-for="message in messages" :key="message.id">
            <div class="flex" :class="message.from === meId ? 'justify-end' : 'justify-start'">
                <div class="max-w-[75%] rounded-2xl px-3.5 py-2 text-sm shadow-sm"
                     :class="message.from === meId
                         ? 'rounded-br-md bg-brand-600 text-white'
                         : 'rounded-bl-md border border-gray-200 bg-white text-gray-800'">
                    <p class="whitespace-pre-wrap break-words" x-text="message.body"></p>
                    <p class="mt-1 text-right text-[10px]"
                       :class="message.from === meId ? 'text-white/60' : 'text-gray-400'"
                       x-text="time(message.at)"></p>
                </div>
            </div>
        </template>
    </div>

    {{-- Composer --}}
    <div class="border-t border-gray-200 px-3 py-3">
        <div x-show="error" x-cloak class="mb-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700" x-text="error"></div>

        <form @submit.prevent="send()" class="flex items-end gap-2">
            <textarea x-ref="input" x-model="draft" rows="1"
                      maxlength="{{ $maxLength }}"
                      @input="grow(); announceTyping()"
                      @keydown.enter.exact.prevent="send()"
                      placeholder="Write a message… (Enter sends, Shift+Enter for a new line)"
                      class="max-h-32 min-h-[2.5rem] flex-1 resize-none rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-800 placeholder-gray-400 focus:border-brand-400 focus:outline-none focus:ring-1 focus:ring-brand-400"></textarea>

            <button type="submit" :disabled="sending || draft.trim() === ''"
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
            expired: false,
            draft: '',
            sending: false,
            error: '',
            lastTypingPing: 0,
            csrf: document.querySelector('meta[name="csrf-token"]').content,

            start() {
                this.$nextTick(() => this.toBottom());
                this.poll();
                setInterval(() => this.poll(), 2000);
            },

            get lastId() {
                return this.messages.length ? this.messages[this.messages.length - 1].id : 0;
            },

            async poll() {
                // A tab in the background still polls: presence and the unread
                // badge both depend on it, and the cost is one cache read.
                try {
                    const response = await fetch(`${this.urls.poll}?after=${this.lastId}`, {
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

                    // The transcript aged out from under us — drop what we are
                    // holding rather than showing messages the server forgot.
                    if (data.expired) {
                        this.messages = [];
                        this.expired = true;
                        return;
                    }

                    if (data.messages.length) {
                        const wasAtBottom = this.atBottom();
                        this.messages.push(...data.messages);
                        this.expired = false;
                        // Only follow the conversation if they were already at the
                        // bottom; yanking the view while someone reads back is worse
                        // than making them scroll down themselves.
                        if (wasAtBottom) this.$nextTick(() => this.toBottom());
                    }
                } catch (e) {
                    // A dropped poll is not worth surfacing — the next one is 2s away.
                }
            },

            async send() {
                const body = this.draft.trim();
                if (body === '' || this.sending) return;

                this.sending = true;
                this.error = '';

                try {
                    const response = await fetch(this.urls.send, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': this.csrf,
                        },
                        body: JSON.stringify({ body }),
                    });

                    if (!response.ok) {
                        this.error = 'Message not sent. Try again.';
                        return;
                    }

                    const data = await response.json();

                    // Append the stored message rather than the draft, so the id
                    // and timestamp are the server's and the next poll will not
                    // hand us a duplicate.
                    this.messages.push(data.sent);
                    this.expired = false;
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

            async clearConversation() {
                if (!confirm('Delete this conversation for both of you? It cannot be recovered.')) return;

                await fetch(this.urls.clear, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
                }).catch(() => {});

                this.messages = [];
                this.expired = false;
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
