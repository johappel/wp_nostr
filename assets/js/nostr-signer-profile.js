(function () {
    const config = window.NostrSignerProfileData;
    if (!config || !config.enabled) {
        return;
    }

    const form = document.getElementById(config.formId || '');
    const button = document.getElementById(config.buttonId || '');
    const statusEl = document.getElementById(config.statusId || '');

    if (!form || !button || !statusEl) {
        return;
    }

    const fieldIds = config.fields || {};
    const getField = (key) => {
        const id = fieldIds[key];
        return id ? document.getElementById(id) : null;
    };

    const fields = {
        name: getField('name'),
        display_name: getField('display_name'),
        about: getField('about'),
        website: getField('website'),
        picture: getField('picture'),
        nip05: getField('nip05'),
    };

    const initialValues = config.initial || {};
    Object.keys(fields).forEach((key) => {
        const field = fields[key];
        if (field && typeof initialValues[key] === 'string' && field.value !== initialValues[key]) {
            field.value = initialValues[key];
        }
    });

    const setStatus = (message) => {
        statusEl.textContent = message;
    };

    const buildMetadata = () => {
        const metadata = {};
        if (fields.name && fields.name.value.trim()) {
            metadata.name = fields.name.value.trim();
        }
        if (fields.display_name && fields.display_name.value.trim()) {
            metadata.display_name = fields.display_name.value.trim();
        }
        if (fields.about && fields.about.value.trim()) {
            metadata.about = fields.about.value.trim();
        }
        if (fields.website && fields.website.value.trim()) {
            metadata.website = fields.website.value.trim();
        }
        if (fields.picture && fields.picture.value.trim()) {
            metadata.picture = fields.picture.value.trim();
        }
        if (fields.nip05 && fields.nip05.value.trim()) {
            metadata.nip05 = fields.nip05.value.trim();
        }
        if (!metadata.name && fields.display_name && fields.display_name.value.trim()) {
            metadata.name = fields.display_name.value.trim();
        }
        return metadata;
    };

    const postEvent = async (payload) => {
        const response = await fetch(config.signUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': config.nonce,
            },
            credentials: 'same-origin',
            body: JSON.stringify(payload),
        });

        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            const message = data && data.message ? data.message : 'Signierung fehlgeschlagen.';
            throw new Error(message);
        }
        return data;
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const metadata = buildMetadata();
        if (Object.keys(metadata).length === 0) {
            setStatus('Bitte fuellen Sie mindestens eines der Profilfelder aus.');
            return;
        }

        const tags = Array.isArray(config.tags) ? config.tags.slice() : [];

        const eventPayload = {
            kind: 0,
            created_at: Math.floor(Date.now() / 1000),
            tags,
            content: JSON.stringify(metadata, null, 2),
        };

        const payload = {
            event: eventPayload,
            key_type: config.keyType || 'user',
            broadcast: true,
        };

        try {
            button.disabled = true;
            setStatus('Signiere und veroeffentliche Nostr-Profil ...');
            const result = await postEvent(payload);
            if (result && result.event) {
                setStatus('Profil-Event signiert. Relay-Antworten: ' + JSON.stringify(result.relay_responses || {}, null, 2));
            } else {
                setStatus('Profil-Event signiert.');
            }
        } catch (err) {
            setStatus('Fehler: ' + (err instanceof Error ? err.message : String(err)));
        } finally {
            button.disabled = false;
        }
    });
})();
