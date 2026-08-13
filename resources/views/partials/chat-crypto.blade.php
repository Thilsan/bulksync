{{--
    End-to-end encryption for chat.

    The server relays sealed envelopes and stores everyone's public key. It holds
    no private key and therefore cannot read a message, and neither can anyone
    reading its disk or its database.

    How it works, in the order it happens:

      1. On first load this browser generates an ECDH P-256 key pair. The private
         key is created NON-EXTRACTABLE and kept in IndexedDB, so it cannot be
         read back out — not by this code, and not by injected script. It can only
         be *used*, and only on this origin.
      2. The public half is published to the server, for colleagues to encrypt to.
      3. For each conversation, ECDH agrees a shared secret with that person's
         public key. HKDF turns it into an AES-GCM key, cached in memory only.
      4. Each message gets a fresh 12-byte nonce and is sealed with AES-GCM,
         which authenticates as well as encrypts: a tampered envelope fails to
         open rather than decrypting to something else.

    What this does NOT protect against, stated plainly because encryption invites
    the assumption that it protects against everything:

      - This server delivers the JavaScript. Anyone who can change what it serves
        can serve code that captures messages before they are sealed. E2E here
        defends the stored and relayed data, not against a compromised server
        choosing to attack you.
      - Public keys are distributed by that same server, so it could substitute
        one and read what follows. Compare fingerprints in person to rule that
        out; the fingerprint is shown in the conversation header.
      - Whoever can run code on someone's machine, or use their unlocked browser
        profile, can use their key. Non-extractable means it cannot be copied,
        not that it cannot be used.
      - The history each browser keeps is stored decrypted, so it can be read.
--}}
<script>
    window.chatCrypto = (function () {
        const DB_NAME = 'bulksync-chat';
        const STORE = 'keys';

        /*
         * Keyed per signed-in account, not per browser.
         *
         * IndexedDB is scoped to the origin, so a single record would be shared by
         * everyone who signs in on this machine — the second person would inherit
         * the first person's private key, publish it as their own, and each could
         * read the other's messages. One record per user id prevents that.
         */
        const record = id => `identity:${id}`;

        // Derived AES keys, per peer public key. Memory only: never written down,
        // gone when the tab closes, cheap to derive again.
        const agreed = new Map();

        let identity = null;      // { privateKey: CryptoKey, publicJwk: {...} }
        let ready = null;         // the in-flight init(), so callers share one

        const subtle = window.crypto?.subtle;

        // ── IndexedDB, just enough of it ─────────────────────────────────────
        function db() {
            return new Promise((resolve, reject) => {
                const request = indexedDB.open(DB_NAME, 1);
                request.onupgradeneeded = () => {
                    if (!request.result.objectStoreNames.contains(STORE)) {
                        request.result.createObjectStore(STORE);
                    }
                };
                request.onsuccess = () => resolve(request.result);
                request.onerror = () => reject(request.error);
            });
        }

        function read(handle, key) {
            return new Promise((resolve, reject) => {
                const request = handle.transaction(STORE, 'readonly').objectStore(STORE).get(key);
                request.onsuccess = () => resolve(request.result ?? null);
                request.onerror = () => reject(request.error);
            });
        }

        function write(handle, key, value) {
            return new Promise((resolve, reject) => {
                const tx = handle.transaction(STORE, 'readwrite');
                tx.objectStore(STORE).put(value, key);
                tx.oncomplete = () => resolve();
                tx.onerror = () => reject(tx.error);
            });
        }

        // ── base64 ↔ bytes ───────────────────────────────────────────────────
        function toBase64(bytes) {
            let binary = '';
            new Uint8Array(bytes).forEach(b => { binary += String.fromCharCode(b); });
            return btoa(binary);
        }

        function fromBase64(text) {
            const binary = atob(text);
            const bytes = new Uint8Array(binary.length);
            for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
            return bytes;
        }

        // ── Identity ─────────────────────────────────────────────────────────
        /**
         * Load this browser's key pair, generating and publishing one if needed.
         *
         * The CryptoKey itself is stored in IndexedDB — structured clone keeps it
         * a live, still-non-extractable key across reloads, which is why the
         * private half never has to exist as bytes anywhere.
         */
        async function init(publishUrl, csrf, meId) {
            if (!subtle || !window.indexedDB) {
                throw new Error('This browser cannot encrypt chat messages.');
            }

            const handle = await db();
            const slot = record(meId);
            let saved = await read(handle, slot);

            if (!saved?.privateKey || !saved?.publicJwk) {
                const pair = await subtle.generateKey(
                    { name: 'ECDH', namedCurve: 'P-256' },
                    false,                      // private key not extractable
                    // deriveBits as well as deriveKey: the agreement below asks
                    // for raw bits and feeds them to HKDF, and WebCrypto refuses
                    // deriveBits on a key that was not created allowing it.
                    ['deriveKey', 'deriveBits'],
                );

                saved = {
                    privateKey: pair.privateKey,
                    publicJwk: await subtle.exportKey('jwk', pair.publicKey),
                };

                await write(handle, slot, saved);
            }

            identity = saved;

            // Published every load: cheap, and it repairs the case where the key
            // was generated but the publish request failed at the time.
            await fetch(publishUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify({
                    key: {
                        kty: identity.publicJwk.kty,
                        crv: identity.publicJwk.crv,
                        x: identity.publicJwk.x,
                        y: identity.publicJwk.y,
                    },
                }),
            });

            return identity;
        }

        function start(publishUrl, csrf, meId) {
            ready = ready || init(publishUrl, csrf, meId);
            return ready;
        }

        // ── Agreement ────────────────────────────────────────────────────────
        function peerId(jwk) {
            return `${jwk.crv}:${jwk.x}:${jwk.y}`;
        }

        /**
         * The AES key shared with one person.
         *
         * ECDH gives a raw secret, which is not a key — HKDF is what turns it
         * into one. The salt is fixed and the info string names the purpose, so
         * the same agreement could never be reused for something else later.
         */
        async function keyFor(peerJwk) {
            const cacheKey = peerId(peerJwk);
            if (agreed.has(cacheKey)) return agreed.get(cacheKey);

            await ready;

            const peerKey = await subtle.importKey(
                'jwk',
                { kty: peerJwk.kty, crv: peerJwk.crv, x: peerJwk.x, y: peerJwk.y },
                { name: 'ECDH', namedCurve: 'P-256' },
                false,
                [],
            );

            const shared = await subtle.deriveBits(
                { name: 'ECDH', public: peerKey },
                identity.privateKey,
                256,
            );

            const material = await subtle.importKey('raw', shared, 'HKDF', false, ['deriveKey']);

            const aes = await subtle.deriveKey(
                {
                    name: 'HKDF',
                    hash: 'SHA-256',
                    salt: new TextEncoder().encode('bulksync-chat-v1'),
                    info: new TextEncoder().encode('message-encryption'),
                },
                material,
                { name: 'AES-GCM', length: 256 },
                false,
                ['encrypt', 'decrypt'],
            );

            agreed.set(cacheKey, aes);
            return aes;
        }

        // ── Sealing ──────────────────────────────────────────────────────────
        async function seal(peerJwk, plaintext) {
            const aes = await keyFor(peerJwk);
            // Fresh nonce per message. Reusing one under the same key would leak
            // the relationship between two messages, so it is never derived.
            const iv = window.crypto.getRandomValues(new Uint8Array(12));

            const ct = await subtle.encrypt(
                { name: 'AES-GCM', iv },
                aes,
                new TextEncoder().encode(plaintext),
            );

            return { v: 1, iv: toBase64(iv), ct: toBase64(ct) };
        }

        /**
         * Open an envelope, or return null.
         *
         * Null rather than throwing, because an envelope that will not open is a
         * normal condition — it was sealed to a key this browser no longer has,
         * usually because storage was cleared and a new pair generated. The UI
         * shows those as unreadable instead of dropping them silently.
         */
        async function open(peerJwk, envelope) {
            try {
                if (!envelope || envelope.v !== 1 || !envelope.iv || !envelope.ct) return null;

                const aes = await keyFor(peerJwk);

                const plaintext = await subtle.decrypt(
                    { name: 'AES-GCM', iv: fromBase64(envelope.iv) },
                    aes,
                    fromBase64(envelope.ct),
                );

                return new TextDecoder().decode(plaintext);
            } catch (e) {
                return null;
            }
        }

        /**
         * A short fingerprint of a public key, for reading out loud.
         *
         * The only defence against this server handing you the wrong public key
         * is two people comparing these by some other means.
         */
        async function fingerprint(jwk) {
            if (!jwk) return null;

            const digest = await subtle.digest(
                'SHA-256',
                new TextEncoder().encode(peerId(jwk)),
            );

            return Array.from(new Uint8Array(digest))
                .slice(0, 8)
                .map(b => b.toString(16).padStart(2, '0'))
                .join('')
                .replace(/(.{4})/g, '$1 ')
                .trim()
                .toUpperCase();
        }

        /**
         * Drop the in-memory derived keys.
         *
         * The stored identity is deliberately left alone: it is scoped to this
         * account, so nobody else signing in here can reach it, and destroying it
         * on sign-out would make every message already sitting in the delivery
         * buffer permanently unreadable on the next sign-in.
         */
        function forgetDerived() {
            agreed.clear();
            identity = null;
            ready = null;
        }

        /** Destroy this account's key pair on this machine, on purpose. */
        async function wipe(meId) {
            forgetDerived();

            try {
                const handle = await db();
                await write(handle, record(meId), null);
            } catch (e) {}
        }

        function available() {
            return Boolean(subtle && window.indexedDB);
        }

        return {
            start, seal, open, fingerprint, wipe, forgetDerived, available,
            myPublicJwk: () => identity?.publicJwk ?? null,
        };
    })();
</script>
