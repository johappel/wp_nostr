# Nostr App Builder

Dieser Leitfaden zeigt, wie du auf Basis des WordPress-Plugins **Nostr Signer** in wenigen Schritten eine eigene Nostr-Webanwendung erstellen oder eine bestehende Oberfläche erweitern kannst. Er nutzt die im Plugin ausgelieferte JavaScript-Hilfsbibliothek `assets/js/nostr-app.js`, die bereits in den Demoseiten (`includes/Frontend/DemoPage.php`, `assets/test_nostr_app.html`, `assets/spa-demo.html`) eingesetzt wird.

## 1. Architekturüberblick

- **Serverseitig** stellt das Plugin abgesicherte REST-Endpunkte bereit (`/wp-json/nostr-signer/v1/sign-event`, `/wp-json/nostr-signer/v1/me`, `/wp-json/nostr-signer/v1/import-key`, …). Signaturen erfolgen in PHP, wodurch private Schlüssel nie den Server verlassen.
- **Clientseitig** kapselt `nostr-app.js` die Kommunikation mit diesen Endpunkten und kombiniert sie mit einem `nostr-tools` SimplePool. Du erhältst so eine einheitliche API für:
  - Konfiguration & Authentifizierung (`configureNostr`)
  - Events signieren und optional veröffentlichen (`nostr_send`)
  - Events laden (`nostr_fetch`)
  - Profilinformationen abrufen (`nostr_me`)
  - Live-Streams abonnieren (`nostr_onEvent`)

## 2. Voraussetzungen

1. **Plugin-Konfiguration:** In `wp-config.php` müssen `NOSTR_SIGNER_ACTIVE_KEY_VERSION` und die dazugehörigen `NOSTR_SIGNER_KEY_Vx` Konstanten gesetzt sein.
2. **Benutzerrechte:** Die Shortcuts arbeiten mit WordPress Nonces und benötigen eingeloggte Benutzer. Für Demo/Test kannst du die bereitgestellte Route `/nostr-signer` nutzen (siehe `DemoPage.php`).
3. **Asset-Bundling:** Standardmäßig wird `nostr-app.js` als ES-Modul ausgeliefert. Binde es direkt aus dem Plugin-Verzeichnis oder kopiere es in deinen Build-Prozess.

```html
<script type="module">
  import { configureNostr, nostr_send } from '/wp-content/plugins/nostr-signer/assets/js/nostr-app.js';
  // ...
</script>
```

## 3. Schnellstart in drei Schritten

1. **Konfiguration laden:**
   ```js
   import { configureNostr } from './assets/js/nostr-app.js';

   const client = configureNostr({
     defaultRelays: ['wss://relay.damus.io', 'wss://relay.snort.social'],
     signUrl: '/wp-json/nostr-signer/v1/sign-event',
     meUrl: '/wp-json/nostr-signer/v1/me',
     nonce: window.NostrSignerConfig?.nonce,
   });
   ```
2. **Event vorbereiten:**
   ```js
   const event = {
     kind: 1,
     created_at: Math.floor(Date.now() / 1000),
     tags: [],
     content: 'Hallo Nostr!',
   };
   ```
3. **Signieren & optional senden:**
   ```js
   import { nostr_send } from './assets/js/nostr-app.js';

   const { event: signed, results } = await nostr_send(event, 'user', ['wss://relay.damus.io']);
   console.log('ID', signed.id, 'Relays', results);
   ```

## 4. Die Shortcut-Funktionen im Detail

### 4.1 `configureNostr(options)`

Initialisiert eine geteilte Client-Instanz. Mehrfachaufrufe aktualisieren nur die Konfiguration.

Wichtige Optionen:
- `defaultRelays`: Array mit Relay-URLs.
- `signUrl`, `meUrl`: REST-Endpunkte des Plugins.
- `nonce`: WordPress REST Nonce (z.?B. via `wp_create_nonce('wp_rest')`).
- `onRelayStatus`: Callback für Verbindungsstatus (siehe SPA-Demo).

### 4.2 `nostr_send(eventData, keyType = 'user', relays, options = {})`

Kombiniert Signatur und optional Veröffentlichung.
- `keyType`: `'user'` oder `'blog'`, entsprechend dem gewünschten Schlüssel im Plugin.
- `relays`: `undefined`, einzelne URL oder Array. Wird `undefined` übergeben, nutzt die Funktion `defaultRelays`.
- `options.publish`: `false` deaktiviert den Broadcast (liefert nur das signierte Event).
- `options.publishOptions`: Timeout, Status-Callback usw.
- `options.signPayload`: zusätzlicher Payload, der an den REST-Endpoint gesendet wird (z.?B. eigene Metadaten).

### 4.3 `nostr_fetch(filter = {}, relays, options = {})`

Lädt Events per `SimplePool`.
- `filter`: Nostr-Filterobjekt. Der Helper bereinigt Werte vor dem Request.
- `relays`: Liste der zu kontaktierenden Relays.
- `options.fetchOptions.timeout`: Timeout in Millisekunden (Standard 5000).

### 4.4 `nostr_me(options = {})`

Gibt die Session-/Profilinformationen zurück, wie sie im REST-Endpoint `/me` bereitgestellt werden. Praktisch für UI-Anzeigen (npub, NIP-05, Blog-Infos).

### 4.5 `nostr_onEvent(callback, relays, filter, options)`

Startet eine Subscription und liefert eine `unsubscribe()`-Funktion.
- `callback(event, relayUrl)`: wird für jedes Event aufgerufen.
- `filter`: normalisiertes Filterobjekt wie bei `nostr_fetch`.
- `options.onEose`, `options.onError`, `options.onClose`: Hooks für Relay-Ereignisse.
- `options.clientConfig`: überschreibt temporär die Client-Konfiguration (z.?B. andere Nonce).

## 5. Schritt-für-Schritt: Eigene Mini-App bauen

Die Demo `assets/test_nostr_app.html` dient als Blaupause. Folgender Ablauf hat sich bewährt:

1. **Grundgerüst erstellen**
   - HTML-Seite mit Eingabefeldern für Content, Relays, Filter und Buttons.
   - `window.NostrSignerConfig` mit Nonce und URLs injizieren (siehe `DemoPage::maybe_render_demo`).

2. **Client konfigurieren**
   - `configureNostr(window.NostrSignerConfig)` im Modul-Entry aufrufen.
   - Optional: eigenen `onRelayStatus`-Handler registrieren, um UI-Pills zu aktualisieren.

3. **Signieren & Veröffentlichen**
   - Button-Handler mit `nostr_send` verbinden.
   -,Zwischenergebnisse (ID, Sig, Pubkey) anzeigen.
   - Publish-Ergebnis mit Relaystatus listen (vgl. `spa-demo-app.js`, Methode `renderPublishResults`).

4. **Events lesen**
   - `nostr_fetch` für Initialbestand einsetzen.
   - Ergebnisse in einer Liste/Timeline rendern.

5. **Live-Updates**
   - `nostr_onEvent` nutzen, um neue Events einzuarbeiten.
   - Rückgabewert speichern und beim Navigationswechsel `unsubscribe()` ausführen (Speicherlecks vermeiden).

6. **Profilinformationen / Session**
   - Beim Start `nostr_me()` abrufen und npub/Blog-Infos in der UI anzeigen (siehe `renderProfile` in `spa-demo-app.js`).

7. **Fehlerbehandlung**
   - Responses der REST-Endpunkte werfen Exceptions mit WordPress-Fehlermeldungen. Nutze `try/catch`, um Toasts oder Inline-Hinweise anzuzeigen.

## 6. Erweiterte Rezepte

- **Eigene Relays verwalten:** Aktualisiere `state.relays` (siehe SPA) und rufe `client.updateConfig({ defaultRelays: neueListe })` auf.
- **Mehrere Clients:** Nutze `createNostrClient()` direkt, wenn du getrennte Konfigurationen (z.?B. unterschiedliche Nonces) parallel brauchst.
- **Custom Sign-Workflows:** Übergib in `options.signPayload` zusätzliche Parameter, die dein REST-Handler verarbeiten kann.
- **Progress UI:** Verwende `onRelayStatus` (Konfiguration) und `publishEvent`-Callbacks (siehe `nostr-app.js`) für Live-Indikatoren.
- **Integration in SPA-Frameworks:** Importiere die Module innerhalb deiner Build-Pipeline (Vite/Webpack). Achte darauf, dass `nostr-tools` nur einmal geladen wird (ESM).

## 7. Debugging & Sicherheit

- **Nonce abgelaufen:** Fange 401-Fehler ab, zeige Re-Login-Link (`window.NostrSignerConfig.loginUrl`).
- **Relay-Fehler:** Das Modul normalisiert Relay-URLs. Logge `options.onError` in `nostr_onEvent`, um Problemrelays zu identifizieren.
- **Key-Typen:** Validere `keyType` im UI (Radio Buttons oder Select). Der Server lehnt unbekannte Typen ab.
- **Speicherbereinigung:** Rufe `client.clearActiveSubscriptions()` vor Seitenwechseln auf oder nutze den Hook `window.addEventListener('beforeunload', …)` wie in `spa-demo-app.js`.

## 8. Referenzen & Beispiele

- **Minimaler Shortcut-Tester:** `assets/test_nostr_app.html`
- **Ausführliche SPA mit Publishing-Workflow:** `assets/spa-demo.html` + `assets/js/spa-demo-app.js`
- **Serverseitige Auslieferung:** `includes/Frontend/DemoPage.php`
- **Shortcut-Implementierung:** `assets/js/nostr-app.js`

Nutze diesen Leitfaden als Ausgangspunkt, um neue Komponenten (Feeds, Chat, Profil-Editoren) schnell auf das abgesicherte Signier-Backend des Plugins aufzusetzen.
