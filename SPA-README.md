# SPA Demo — Bedienung und Integration

Diese Datei beschreibt die kleine Single-Page-Demo `assets/spa-demo.html` und das zugehörige JavaScript `assets/js/spa-demo-app.js`. Ziel der Demo: Ein Nostr-Event im Browser zu erstellen, serverseitig signieren zu lassen und optional an einen Relay zu publizieren.

Die SPA ist bewusst minimal gehalten und eignet sich als Ausgangspunkt für eigene Integrationen.

**Wichtig:** Die SPA ist keine Trusted-Client-Lösung für private Schlüssel. Private Keys (`nsec`) verbleiben auf dem Server — die SPA fordert Signaturen über die REST-API an.

## Dateien
- `assets/spa-demo.html` — die HTML-Seite mit Formular
- `assets/js/spa-demo-app.js` — Modul, das REST-Aufrufe macht und Relays kontaktiert

## Formularfelder (Erklärung)
- `Relay-URL` (`#relay-url`): WebSocket-URL des gewünschten Relays (z. B. `wss://relay.damus.io`). Wird nur benötigt, wenn Sie nach dem Signieren direkt publizieren wollen.
- `Signieren mit` (`#key-type`): `user` oder `blog` — welche Schlüsselart der Server zum Signieren verwenden soll.
- `Kind` (`#event-kind`): Numerischer Nostr-Event-Typ (z. B. `0` für Profil-Metadata, `1` für Text-Note). Standard ist `1`.
- `Tags` (`#event-tags`): Tags können entweder als JSON-Array (z. B. `[["r","https://example.org"]]`) oder als einfache, zeilengetrennte Paare im Format `tag,value` eingegeben werden. Die SPA parst beide Formate.
- `Inhalt` (`#event-content`): Der `content`-Text des Events.

## Ablauf intern
1. Der Benutzer füllt `kind`, `tags` und `content` aus.
```markdown
# SPA Demo — Bedienung und Integration

Diese Datei beschreibt die kleine Single-Page-Demo `assets/spa-demo.html` und das zugehörige JavaScript `assets/js/spa-demo-app.js`. Ziel der Demo: Ein Nostr-Event im Browser zu erstellen, serverseitig signieren zu lassen und optional an einen Relay zu publizieren.

Die SPA ist bewusst minimal gehalten und eignet sich als Ausgangspunkt für eigene Integrationen.

**Wichtig:** Die SPA ist keine Trusted-Client-Lösung für private Schlüssel. Private Keys (`nsec`) verbleiben auf dem Server — die SPA fordert Signaturen über die REST-API an.

## Dateien
- `assets/spa-demo.html` — die HTML-Seite mit Formular
- `assets/js/spa-demo-app.js` — Modul, das REST-Aufrufe macht und Relays kontaktiert

## Formularfelder (Erklärung)
- `Relay-URL` (`#relay-url`): WebSocket-URL des gewünschten Relays (z. B. `wss://relay.damus.io`). Wird nur benötigt, wenn du nach dem Signieren direkt publizieren willst.
- `Signieren mit` (`#key-type`): `user` oder `blog` — welche Schlüsselart der Server zum Signieren verwenden soll.
- `Kind` (`#event-kind`): Numerischer Nostr-Event-Typ (z. B. `0` für Profil-Metadata, `1` für Text-Note). Standard ist `1`.
- `Tags` (`#event-tags`): Tags können entweder als JSON-Array (z. B. `[["r","https://example.org"]]`) oder als einfache, zeilengetrennte Paare im Format `tag,value` eingegeben werden. Die SPA parst beide Formate.
- `Inhalt` (`#event-content`): Der `content`-Text des Events.

## Ablauf intern
1. Der Benutzer füllt `kind`, `tags` und `content` aus.
2. Die SPA baut ein Event-Objekt `{ kind, created_at, tags, content }`.
3. Die SPA sendet das Objekt an den REST-Endpunkt `POST /nostr-signer/v1/sign-event` (Header `X-WP-Nonce` wird benötigt).
4. Der Server entschlüsselt den passenden `nsec`, signiert das Event und gibt das signierte Event zurück (`id`, `sig`, `pubkey`).
5. Optional verbindet die SPA einen Relay via `nostr-tools` und publisht das signierte Event.

## Nonce & Konfiguration

Die SPA erwartet die globale Variable `window.NostrSignerConfig` mit mindestens diesen Werten:

{
  apiBase: '/wp-json',
  nonce: '<wp_rest_nonce>',
  meUrl: '/wp-json/nostr-signer/v1/me',
  signUrl: '/wp-json/nostr-signer/v1/sign-event'
}

In WordPress kann das beim Enqueue eines Scripts wie folgt injiziert werden:

```php
wp_enqueue_script('nostr-spa-demo', plugin_dir_url(__FILE__) . 'assets/js/spa-demo-app.js', [], null, true);
wp_localize_script('nostr-spa-demo', 'NostrSignerConfig', [
  'apiBase' => rest_url(),
  'nonce'   => wp_create_nonce('wp_rest'),
  'meUrl'   => rest_url('nostr-signer/v1/me'),
  'signUrl' => rest_url('nostr-signer/v1/sign-event'),
]);
```

## Tag-Formate (Beispiele)

- JSON-Array (voll flexibel):

  [["r","https://example.org"], ["e", "<event-id>"], ["p", "<pubkey-hex>"]]

- Zeilenbasiert (einfaches Eingabeformat):

  r,https://example.org
  e,<event-id>

Die SPA konvertiert das Eingabeformat automatisch in ein Array von Arrays.

## Sicherheitshinweise
- Der Nonce (`X-WP-Nonce`) darf nicht ausgelassen werden — WordPress blockiert sonst die Anfrage.
- `nsec`-Werte werden niemals an den Browser übermittelt.
- Verwende HTTPS und sichere Relays.
- Teste zuerst mit `broadcast=false`, bevor du Events automatisch an Relays sendest.

## Bundling von `nostr-tools`

`nostr-tools` ist ein npm/ESM Paket. WordPress liefert keine automatische Unterstützung für Node-Module; du solltest daher ein Build-Tool (z. B. Vite/Rollup/Webpack) verwenden, um eine browserfähige Bundle-Datei zu erzeugen, die `relayInit` zur Verfügung stellt. Alternativ kannst du eine serverseitig gebündelte Version des Skripts bereitstellen.

### Vite Build (Anleitung)

Die Repository-Root enthält ein kleines Vite-Setup, das `nostr-tools` bündelt und eine gebündelte Datei in `assets/dist` erzeugt. Das Postbuild-Skript kopiert die Datei nach `assets/dist/spa-nostr-app.bundle.js`.

1) Node.js installieren (empfohlen: 18+)

2) Installieren und Build in PowerShell:

```powershell
cd f:\code\nostr\wp_nostr
npm install
npm run build
```

3) Ergebnis: gebündelte Datei(en) in `assets/dist` — das Postbuild-Skript erstellt `assets/dist/spa-nostr-app.bundle.js`.

4) Im Plugin/Theme `wp_enqueue_script` anpassen, damit die gebündelte Datei geladen wird, und `wp_localize_script` wie in der README gezeigt nutzen.

## Konkrete Event-Beispiele

1) Kind 1 — einfache Text-Note

```json
{
  "event": {
    "kind": 1,
    "created_at": 1690000000,
    "tags": [],
    "content": "Hallo von meinem Nostr-Client"
  },
  "key_type": "user",
  "broadcast": false
}
```

2) Kind 0 — Profil-Metadaten (vereinfachtes Beispiel)

```json
{
  "event": {
    "kind": 0,
    "created_at": 1690000000,
    "tags": [],
    "content": "{ \"name\": \"Alice\", \"about\": \"Autorin\" }"
  },
  "key_type": "user",
  "broadcast": true
}
```

## Testbefehle und Debugging

- CURL (Signatur anfordern):

```powershell
curl -v -X POST 'https://example.org/wp-json/nostr-signer/v1/sign-event' ^
  -H 'Content-Type: application/json' ^
  -H 'X-WP-Nonce: <WP_NONCE>' ^
  --data-raw '{"event":{"kind":1,"created_at":1690000000,"tags":[],"content":"Test"},"key_type":"user"}'
```

- Fetch (Debugging im Browser, DevTools Console):

```js
fetch('/wp-json/nostr-signer/v1/sign-event', {
  method: 'POST',
  credentials: 'include',
  headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.NostrSignerConfig.nonce },
  body: JSON.stringify({ event:{ kind:1, created_at: Math.floor(Date.now()/1000), tags:[], content:'Hello' }, key_type:'user' })
}).then(r=>r.json()).then(console.log).catch(console.error);
```

### Tag-Validierung und Parsing-Fehler

- JSON-Fehler: Wenn `event.tags` als JSON eingegeben wird, prüfe die Konsole auf Syntaxfehler. Die SPA versucht zuerst `JSON.parse`.
- Zeilenformat: Achte auf Kommas; das Format ist `tag,value`. Beispiele:

  r,https://example.org
  e,0123456789abcdef

- Falls Tags unerwartet aussehen, validiere die Struktur mit `Array.isArray(tags)` und stelle sicher, dass jedes Tag ein Array ist (z. B. `['r','https://...']`).

## Vite Entwicklung (Dev Mode)

- Für lokale Entwicklung kannst du `npm run dev` verwenden und die Seite direkt mit dem Dev-Server laden. Beachte, dass die Dev-URL vom Plugin abweicht; für einfache Tests öffne `assets/spa-demo.html` lokal oder passe das `wp_enqueue` während der Entwicklung an.



## Beispiel-Workflow (Kurz)
1. Admin loggt sich ein und ruft die SPA auf.
2. SPA lädt `/me` (optional) um npub/infos anzuzeigen.
3. Benutzer baut Event (z. B. Kind 1, Text) und wählt `user`.
4. Benutzer klickt `Signieren & Publizieren`.
5. SPA sendet Event an `/sign-event`, erhält signiertes Event und publisht es an das Relay.

---
## Im Frontend verwenden 

Wenn du die SPA im Frontend statt im Admin verwenden willst, füge z.B. dieses Snippet in dein Plugin oder Theme:
<?php
function my_nostr_enqueue_spa() {
    $bundle = plugin_dir_path(__FILE__) . 'assets/dist/spa-nostr-app.bundle.js';
    if ( file_exists( $bundle ) ) {
        wp_enqueue_script(
            'nostr-signer-spa',
            plugin_dir_url(__FILE__) . 'assets/dist/spa-nostr-app.bundle.js',
            [],
            filemtime($bundle),
            true
        );
        wp_localize_script(
            'nostr-signer-spa',
            'NostrSignerConfig',
            [
                'apiBase' => rest_url(),
                'nonce'   => wp_create_nonce('wp_rest'),
                'meUrl'   => rest_url('nostr-signer/v1/me'),
                'signUrl' => rest_url('nostr-signer/v1/sign-event'),
            ]
        );
    }
}
add_action('wp_enqueue_scripts', 'my_nostr_enqueue_spa');
```
