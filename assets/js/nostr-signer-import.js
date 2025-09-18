(function () {
    const config = window.NostrSignerImportData;
    if (!config) {
        return;
    }

    const form = document.getElementById(config.formId || '');
    const input = document.getElementById(config.inputId || '');
    const statusEl = document.getElementById(config.statusId || '');
    const npubDisplay = document.getElementById(config.npubDisplay || '');

    if (!form || !input || !statusEl) {
        return;
    }

    const hasSubtleCrypto = typeof window.crypto !== 'undefined' && typeof window.crypto.subtle !== 'undefined';
    if (!hasSubtleCrypto) {
        statusEl.textContent = 'Web Crypto API ist nicht verfuegbar. Import nicht moeglich.';
        form.querySelector('button[type="submit"]').disabled = true;
        return;
    }

    if (!config.enabled) {
        statusEl.textContent = 'Import derzeit deaktiviert. Bitte pruefen Sie Master-Key und Bibliothek.';
        form.querySelector('button[type="submit"]').disabled = true;
        return;
    }

    const setStatus = (message) => {
        statusEl.textContent = message;
    };

    const hexToBytes = (hex) => {
        if (!/^([0-9a-f]{2})+$/i.test(hex)) {
            throw new Error('Temporaerer Schluessel hat ein ungueltiges Format.');
        }
        const bytes = new Uint8Array(hex.length / 2);
        for (let i = 0; i < bytes.length; i++) {
            bytes[i] = parseInt(hex.substr(i * 2, 2), 16);
        }
        return bytes;
    };

    const bytesToBase64 = (bytes) => {
        let binary = '';
        bytes.forEach((b) => {
            binary += String.fromCharCode(b);
        });
        return window.btoa(binary);
    };

    const deriveNpub = (nsec) => {
        if (!window.NostrTools || !window.NostrTools.nip19) {
            throw new Error('nostr-tools wurde nicht geladen.');
        }

        const decoded = window.NostrTools.nip19.decode(nsec);
        if (!decoded || decoded.type !== 'nsec') {
            throw new Error('Der angegebene Schluessel ist kein gueltiger nsec.');
        }

        let privateKeyHex;
        const data = decoded.data;
        if (typeof data === 'string') {
            privateKeyHex = data;
        } else if (data instanceof Uint8Array) {
            privateKeyHex = Array.from(data).map((b) => b.toString(16).padStart(2, '0')).join('');
        } else if (Array.isArray(data)) {
            privateKeyHex = data.map((b) => Number(b).toString(16).padStart(2, '0')).join('');
        } else {
            throw new Error('Der private Schluessel konnte nicht verarbeitet werden.');
        }

        const pubkeyHex = window.NostrTools.getPublicKey(privateKeyHex);
        const npub = window.NostrTools.nip19.npubEncode(pubkeyHex);
        return { npub, privateKeyHex };
    };

    const encryptNsec = async (nsec) => {
        const keyBytes = hexToBytes(config.tempKeyHex);
        const iv = window.crypto.getRandomValues(new Uint8Array(16));
        const encoder = new TextEncoder();
        const key = await window.crypto.subtle.importKey(
            'raw',
            keyBytes,
            { name: 'AES-CBC' },
            false,
            ['encrypt']
        );

        const ciphertext = await window.crypto.subtle.encrypt(
            { name: 'AES-CBC', iv },
            key,
            encoder.encode(nsec)
        );

        const cipherBytes = new Uint8Array(ciphertext);
        const combined = new Uint8Array(iv.length + cipherBytes.length);
        combined.set(iv, 0);
        combined.set(cipherBytes, iv.length);
        return bytesToBase64(combined);
    };

    const submitButton = form.querySelector('button[type="submit"]');

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const nsec = (input.value || '').trim();
        if (!nsec) {
            setStatus('Bitte geben Sie einen nsec ein.');
            input.focus();
            return;
        }

        if (!nsec.startsWith('nsec')) {
            setStatus('Das Feld enthaelt keinen gueltigen nsec.');
            return;
        }

        try {
            submitButton.disabled = true;
            setStatus('Validiere und verschluessle Schluessel ...');

            const { npub } = deriveNpub(nsec);
            const encrypted = await encryptNsec(nsec);

            const response = await fetch(config.restUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': config.nonce,
                },
                body: JSON.stringify({
                    target: config.target,
                    encrypted_nsec: encrypted,
                    npub,
                }),
                credentials: 'same-origin',
            });

            const data = await response.json();
            if (!response.ok) {
                throw new Error(data && data.message ? data.message : 'Unbekannter Fehler beim Import.');
            }

            setStatus('Schluessel erfolgreich importiert.');
            if (npubDisplay && data.npub) {
                npubDisplay.textContent = data.npub;
            }
            input.value = '';
        } catch (error) {
            setStatus('Fehler: ' + (error instanceof Error ? error.message : String(error)));
        } finally {
            submitButton.disabled = false;
        }
    });
})();
