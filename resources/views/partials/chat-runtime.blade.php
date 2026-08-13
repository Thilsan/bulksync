{{--
    The browser's own chat history.

    This is where a conversation actually lives. The server (App\Support\
    EphemeralChat) only buffers a message long enough to hand it over; the record
    belongs to the two people talking, and each of their browsers keeps its own
    copy here in localStorage.

    The whole reason this is a shared file rather than inlined twice is the merge
    below. Server message ids restart at 1 every time the delivery buffer is
    rebuilt, so ids alone cannot identify a message across a browser's lifetime.
    Everything stored is therefore keyed by "epoch:id", and the id cursor used
    for polling is tracked per epoch. Getting that subtly wrong in two places
    would show people each other's messages in the wrong order, or twice.
--}}
<script>
    window.chatHistory = (function () {
        const PREFIX = 'bulksync.chat.';

        function key(meId, peerId) {
            return `${PREFIX}${meId}.${peerId}`;
        }

        /**
         * What this browser knows about one conversation.
         *
         * `epoch` and `cursor` describe how far we have read from the server's
         * current buffer. `messages` is the history and outlives any number of
         * epochs — that is the point.
         */
        function load(meId, peerId) {
            const empty = { messages: [], epoch: null, cursor: 0 };

            try {
                const raw = localStorage.getItem(key(meId, peerId));
                if (!raw) return empty;

                const saved = JSON.parse(raw);

                return {
                    messages: Array.isArray(saved.messages) ? saved.messages : [],
                    epoch: saved.epoch ?? null,
                    cursor: saved.cursor ?? 0,
                };
            } catch (e) {
                // Corrupt or unreadable — better to start over than to throw on
                // every poll for the rest of the session.
                return empty;
            }
        }

        function save(meId, peerId, state, keep) {
            try {
                localStorage.setItem(key(meId, peerId), JSON.stringify({
                    epoch: state.epoch,
                    cursor: state.cursor,
                    // Capped, so one browser cannot fill its storage quota and
                    // start throwing on every conversation.
                    messages: state.messages.slice(-keep),
                }));
            } catch (e) {
                // Quota exceeded, or storage blocked in a private window. The
                // conversation still works; it just will not survive a reload.
            }
        }

        function forget(meId, peerId) {
            try { localStorage.removeItem(key(meId, peerId)); } catch (e) {}
        }

        /** Every conversation this browser is holding, for sign-out. */
        function forgetAll() {
            try {
                Object.keys(localStorage)
                    .filter(k => k.startsWith(PREFIX))
                    .forEach(k => localStorage.removeItem(k));
            } catch (e) {}
        }

        /**
         * Fold a poll's response into the local history.
         *
         * Returns the new state plus how many messages were actually added, so
         * the caller knows whether to scroll or save.
         *
         * `reset` means the server rebuilt its buffer, so our cursor refers to
         * ids that have been handed out again. We keep every message we already
         * have and start counting from zero — the epoch in each uid is what stops
         * the reused ids from colliding.
         */
        function merge(state, payload) {
            const epoch = payload.epoch ?? state.epoch;
            const seen = new Set(state.messages.map(m => m.uid));

            const incoming = (payload.messages || [])
                .map(m => ({ ...m, uid: `${epoch}:${m.id}` }))
                .filter(m => !seen.has(m.uid));

            const messages = state.messages.concat(incoming);

            // Arrival order is normally chronological, but a reset can hand us a
            // batch that overlaps what we already had; sort so the transcript
            // always reads in the order things were said.
            messages.sort((a, b) => (a.at - b.at) || a.uid.localeCompare(b.uid));

            // The cursor only means anything within the current epoch.
            const highest = (payload.messages || []).reduce((max, m) => Math.max(max, m.id), 0);
            const cursor = payload.reset ? highest : Math.max(state.cursor, highest);

            return {
                state: { messages, epoch, cursor },
                added: incoming.length,
            };
        }

        /**
         * Mark our own messages as read, up to the pointer the server reports.
         *
         * Only messages belonging to the CURRENT epoch can be compared: ids
         * restart whenever the buffer is rebuilt, so a pointer of 3 says nothing
         * about a message with id 3 from an earlier one. Those keep whatever
         * status they already had.
         *
         * Once read, always read — the flag is stored, so it survives the buffer
         * draining and the pointer going back to zero.
         */
        function applyRead(state, meId, upTo) {
            if (!upTo || !state.epoch) return { state, changed: false };

            let changed = false;

            const messages = state.messages.map(message => {
                if (message.read || message.from !== meId) return message;
                if (message.uid !== `${state.epoch}:${message.id}`) return message;
                if (message.id > upTo) return message;

                changed = true;
                return { ...message, read: true };
            });

            return { state: changed ? { ...state, messages } : state, changed };
        }

        return { load, save, forget, forgetAll, merge, applyRead };
    })();
</script>
