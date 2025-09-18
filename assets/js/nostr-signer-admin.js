(function () {
    const signButton = document.getElementById("sign-button");
    const textarea = document.getElementById("nostr-event-content");
    const output = document.getElementById("signed-event-output");

    if (!signButton || !textarea || !output) {
        return;
    }

    function renderMessage(message) {
        output.textContent = message;
    }

    signButton.addEventListener("click", async (event) => {
        event.preventDefault();

        if (!window.NostrSignerAdmin || !NostrSignerAdmin.masterReady || !NostrSignerAdmin.libraryReady) {
            renderMessage("Signierung ist aktuell nicht verfuegbar. Bitte pruefen Sie die Plugin-Konfiguration.");
            return;
        }

        const content = textarea.value.trim();
        if (!content) {
            renderMessage("Bitte geben Sie einen Inhalt fuer das Event ein.");
            return;
        }

        const keyTypeInput = document.querySelector('input[name="key_type"]:checked');
        const keyType = keyTypeInput ? keyTypeInput.value : "user";

        const eventPayload = {
            kind: 1,
            created_at: Math.floor(Date.now() / 1000),
            tags: [],
            content,
        };

        try {
            const response = await fetch(NostrSignerAdmin.restUrl, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-WP-Nonce": NostrSignerAdmin.nonce,
                },
                body: JSON.stringify({
                    event_data: JSON.stringify(eventPayload),
                    key_type: keyType,
                }),
                credentials: "same-origin",
            });

            if (!response.ok) {
                const errorData = await response.json().catch(() => ({}));
                const message = errorData.message || "Signierung fehlgeschlagen.";
                renderMessage(message);
                return;
            }

            const data = await response.json();
            renderMessage(JSON.stringify(data, null, 2));
        } catch (err) {
            renderMessage("Ein unerwarteter Fehler ist aufgetreten: " + err.message);
        }
    });
})();