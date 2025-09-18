# Nostr Signer — WordPress Plugin

Dieses Plugin stellt eine sichere, serverseitige Infrastruktur bereit, um Nostr-Events innerhalb eines WordPress-Blogs zu signieren. Es verwaltet pro-Benutzer-Schlüsselpaare sowie ein globales Blog-Schlüsselpaar und bietet REST-API-Endpunkte, die von beliebigen Nostr-Apps konsumiert werden können.

Wichtig: Alle `nsec`-Private-Keys werden verschlüsselt in der Datenbank gespeichert. Der Master-Schlüssel für diese Verschlüsselung MUSS als Konstante `NOSTR_SIGNER_MASTER_KEY` in der `wp-config.php` hinterlegt werden.

## Inhalt
- **Installation & Aktivierung**
- **Konfiguration**
- **Sicherheitsprinzipien**
- **REST API** (`/nostr-signer/v1/sign-event`, `/nostr-signer/v1/me`, `/import-key`)
- **Beispiele** (curl, fetch, JavaScript mit `nostr-tools`)
- **Admin-Frontend / Demo** (`assets/test.html`)
- **NIP-05 & .well-known/nostr.json**

## Installation

1. Plugin in das WordPress `plugins`-Verzeichnis kopieren oder per Composer/ZIP installieren.
2. In `wp-config.php` die Konstante definieren (Beispiel):

```php
define('NOSTR_SIGNER_MASTER_KEY', 'BitteMitSicheremZufallswertErsetzen');
```

3. Plugin im WordPress-Admin aktivieren. Beim Aktivieren werden (falls noch nicht vorhanden) die Blog-Schlüssel erzeugt und in den `wp_options` gespeichert (der `nsec` ist verschlüsselt).

Hinweis: Falls `NOSTR_SIGNER_MASTER_KEY` nicht gesetzt ist, zeigt das Plugin im Admin einen Hinweis an und deaktiviert kryptografische Funktionen.

## Konfiguration

- `NOSTR_SIGNER_MASTER_KEY` in `wp-config.php` (unbedingt fest und sicher halten).
- Standardmäßig werden pro Benutzer `nostr_npub` (klartext) und `nostr_encrypted_nsec` (verschlüsselt) in `user_meta` abgelegt.
- Blog-Keys werden unter `nostr_blog_npub` und `nostr_blog_encrypted_nsec` in `wp_options` gespeichert.

## Sicherheitsprinzipien

- Der Master-Schlüssel darf ausschließlich in `wp-config.php` existieren.
- Private Schlüssel (`nsec`) werden in der DB nur verschlüsselt abgelegt und niemals an den Client gesendet.
- Entschlüsselte `nsec`-Werte werden serverseitig nur für die Dauer der Signatur im Arbeitsspeicher gehalten und sofort `unset()`-t.
- REST-Endpunkte verlangen Authentifizierung und WordPress-Nonce (CSRF-Schutz).

## REST API — Übersicht

Namespace: `nostr-signer/v1`

- `POST /sign-event` — Signiert ein (unvollständiges) Nostr-Event mit dem `user`- oder `blog`-Key.
- `GET /me` — Liefert Metadaten zu aktuellem Benutzer und Blog (npub/hex, avatar, blog-name, etc.).
- `POST /import-key` — (Admin/Profil) Sichere Import-Route für bestehende `nsec`-Keys (Client-seitige Verschlüsselung mit temporärem Schlüssel, serverseitige Re-Verschlüsselung mit Master-Key).

Alle POST-Routen erfordern:
- Authentifizierten WordPress-Benutzer (eingeloggter Benutzer).
- Einen gültigen WordPress-Nonce im Header `X-WP-Nonce`.

Antworten liefern JSON; Fehler werden als `WP_Error` mit HTTP-Statuscodes zurückgegeben.

### `POST /nostr-signer/v1/sign-event`

Beschreibung: Signiert ein Event-Objekt und (optional) sendet es an konfigurierte Relays.

Erwarteter Request-Body (JSON):

{
  "event": { /* unvollständiges Event ohne id, sig, pubkey */ },
  "key_type": "user" | "blog",
  "broadcast": true|false (optional)
}

Pflichtfelder im `event`-Objekt sind nicht streng — das Plugin fügt `created_at`, `kind` (Standard 1) und `r`-Tag (Autoren-URL) hinzu, falls fehlend.

Beispiel-Ablauf (serverseitig):
- Nonce prüfen
- Verschlüsselten `nsec` aus `user_meta` oder `wp_options` laden
- Entschlüsseln mit dem Master-Key (`Crypto::decrypt`)
- Event signieren (`NostrService::signEvent`)
- Klartext-`nsec` sofort `unset()`
- Signiertes Event als JSON zurückgeben

Erfolgs-Antwort (Beispiel):

{
  "event": { /* signiertes Event inklusive id, sig, pubkey, tags */ },
  "broadcast": true,
  "relay_responses": [ /* optional: relay ack/nack responses */ ],
  "key_type": "user"
}

### `GET /nostr-signer/v1/me`

Gibt Metadaten über den aktuellen Benutzer und das Blog zurück, z. B. `npub` und `hex`-Formate, Avatar-URL, Blog-Name.

Beispiel (Teil):

{
  "user": { "pubkey": { "npub": "npub1...", "hex": "..." }, "id": 12, ... },
  "blog": { "pubkey": { "npub": "npub1...", "hex": "..." }, "home_url": "..." }
}

## Beispiele

Die folgenden Beispiele zeigen, wie beliebige Nostr-Apps oder Clients die REST-API dieses Plugins nutzen können.

Wichtig: Diese Beispiele setzen voraus, dass der aufrufende Client bereits in WordPress eingeloggt ist (Session-Cookies) und einen gültigen `X-WP-Nonce` besitzt. In einem WordPress-Admin-Umfeld wird der Nonce typischerweise mit `wp_create_nonce('wp_rest')` erzeugt und an das JavaScript via `wp_localize_script` übergeben.

### 1) CURL (Server-seitig) — Signatur mit Benutzer-Key

curl -i -X POST 'https://example.org/wp-json/nostr-signer/v1/sign-event' \
  -H 'Content-Type: application/json' \
  -H 'X-WP-Nonce: <WP_NONCE_HIER>' \
  --data-raw '{
    "event": {
      "kind": 1,
      "created_at": 1690000000,
      "tags": [],
      "content": "Hallo von meinem Nostr-Client"
    },
    "key_type": "user",
    "broadcast": false
  }'

Antwort: JSON mit dem signierten Event (siehe oben).

### 2) Browser `fetch` (Frontend) — Nutzung aus einer Web-App

Beispiel-Code (vereinfachtes Snippet):

const resp = await fetch('/wp-json/nostr-signer/v1/sign-event', {
  method: 'POST',
  credentials: 'include',
  headers: {
    'Content-Type': 'application/json',
    'X-WP-Nonce': wpNonce
  },
  body: JSON.stringify({ event: eventPayload, key_type: 'user' })
});
const data = await resp.json();

`eventPayload` sollte `kind`, `created_at`, `content` und optional `tags` enthalten. Nach erfolgreichem Aufruf enthält `data.event` die `id`, `sig` und `pubkey`.

### 3) JavaScript-Integration mit `nostr-tools`

In einem Client, der `nostr-tools` verwendet, ist das Signieren normalerweise clientseitig möglich. Wenn du jedoch aus Sicherheitsgründen die Server-Signatur nutzen willst (z. B. damit der private Schlüssel auf dem Server bleibt), baut die App ein Event, sendet es an den `sign-event`-Endpunkt und erhält das signierte Event zurück.

Beispiel (pseudocode):

// 1) Event-Objekt bauen
const event = {
  kind: 1,
  created_at: Math.floor(Date.now() / 1000),
  tags: [],
  content: 'Hello from my app'
};

// 2) Signatur beim Server anfordern
const resp = await fetch(apiUrl + '/nostr-signer/v1/sign-event', {
  method: 'POST',
  credentials: 'include',
  headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
  body: JSON.stringify({ event, key_type: 'blog', broadcast: true })
});
const result = await resp.json();

// 3) Ergebnis verarbeiten
// result.event enthält das signierte Event — kann jetzt direkt an Relays gesendet werden.

Wenn du `nostr-tools` clientseitig verwendest, kannst du das vom Server signierte Event zur Relay-Publikation verwenden oder das Event selbst an Relays senden.

### 4) Import eines bestehenden `nsec` (sicherer Workflow)

Kurzbeschreibung: Der `nsec` wird clientseitig mit einem temporären Schlüssel verschlüsselt und dann an den Server gesendet. Der Server entschlüsselt den temporären Layer und verschlüsselt anschließend mit dem permanenten `NOSTR_SIGNER_MASTER_KEY`.

Schritte (vereinfacht):

1. Admin/Profil-Seite fordert einen temporären Schlüssel an (serverseitig generiert anhand der Session-Token).
2. Client verschlüsselt `nsec` mit diesem temporären Schlüssel und schickt `encrypted_nsec` + `npub` an `/import-key`.
3. Server entschlüsselt mit dem temporären Schlüssel, reverschlüsselt mit `NOSTR_SIGNER_MASTER_KEY` und speichert die Daten in `user_meta`.

Wichtig: Der temporäre Schlüssel ist nur für die Dauer des Imports gültig; `NOSTR_SIGNER_MASTER_KEY` wird NIE an den Client gesendet.

## Admin Demo & Beispielseite

Es gibt eine einfache Demo-Seite unter `assets/test.html`, die die `/me`- und `/sign-event`-Endpunkte verwendet. Die Seite zeigt, wie Nonce und URLs an das Frontend übergeben werden und wie `fetch`-Requests aufgebaut sind.

## Praktische Integrations-Beispiele

Die folgenden Snippets helfen beim schnellen Einbinden in eigene Apps.

1) Vollständiges curl-Beispiel mit erwarteter Antwort (vereinfachtes Beispiel):

Request:

curl -s -X POST 'https://example.org/wp-json/nostr-signer/v1/sign-event' \
  -H 'Content-Type: application/json' \
  -H 'X-WP-Nonce: abcd1234' \
  --data-raw '{"event":{"kind":1,"created_at":1690000000,"tags":[],"content":"Hallo"},"key_type":"user"}'

Antwort (200):

{
  "event": {
    "id": "e7c9...",
    "pubkey": "02ab...",
    "sig": "...",
    "kind": 1,
    "created_at": 1690000000,
    "tags": [["r","https:\/\/example.org\/user\/alice"]],
    "content": "Hallo"
  },
  "broadcast": false,
  "relay_responses": [],
  "key_type": "user"
}

2) Fetch mit Problemen behandeln (Frontend):

async function signViaServer(eventPayload, apiUrl, nonce) {
  const resp = await fetch(apiUrl + '/nostr-signer/v1/sign-event', {
    method: 'POST',
    credentials: 'include',
    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
    body: JSON.stringify({ event: eventPayload, key_type: 'user' })
  });

  if (!resp.ok) {
    const err = await resp.json().catch(() => ({ message: 'Unbekannter Fehler' }));
    throw new Error(err.message || 'Serverfehler');
  }

  return resp.json();
}

3) Vollständiger Flow mit `nostr-tools` (Client publisht signiertes Event):

// (a) Baue das Event
const event = { kind: 1, created_at: Math.floor(Date.now() / 1000), tags: [], content: 'Hello' };

// (b) Fordere Signatur vom Server an
const signed = await signViaServer(event, 'https://example.org/wp-json', wpNonce);

// (c) Verwende nostr-tools, um das signierte Event an einen Relay zu senden
import { relayInit } from 'nostr-tools';
const relay = relayInit('wss://relay.example.org');
await relay.connect();
const pub = relay.publish(signed.event);
pub.on('ok', () => console.log('Event auf Relay angekommen'));
pub.on('failed', (reason) => console.error('Relay fehlgeschlagen', reason));

4) Nutzung von `/me` zum Füllen der UI

Das `GET /nostr-signer/v1/me` liefert `npub` und `hex` Schlüssel des Nutzers und des Blogs. Beispiel:

const me = await fetch('/wp-json/nostr-signer/v1/me', { credentials: 'include', headers: { 'X-WP-Nonce': wpNonce } }).then(r => r.json());
// me.user.pubkey.npub, me.user.pubkey.hex, me.blog.pubkey.npub

5) Profil (kind:0) veröffentlichen

Auf der Autorenprofilseite kann das Plugin ein `kind: 0` Event erstellen, das Metadaten des Autors enthält. Der oben beschriebene `/sign-event` Endpunkt akzeptiert nun komplette Event-Objekte inklusive `kind: 0`.

Hinweis: Der Server fügt automatisch ein `r`-Tag mit der Author-URL hinzu, wenn es nicht vorhanden ist.

## SPA Integration: Nonce & Config injizieren

Damit die SPA (`assets/spa-demo.html` + `assets/js/spa-nostr-app.js`) in WordPress funktioniert, muss das Theme/Plugin beim Enqueue des Scripts eine kleine Konfiguration in `window.NostrSignerConfig` injizieren. Beispiel im Plugin/Theme-PHP:

```php
wp_enqueue_script('nostr-spa-demo', plugin_dir_url(__FILE__) . 'assets/js/spa-nostr-app.js', [], null, true);
wp_localize_script('nostr-spa-demo', 'NostrSignerConfig', [
  'apiBase' => rest_url(),
  'nonce'   => wp_create_nonce('wp_rest'),
  'meUrl'   => rest_url('nostr-signer/v1/me'),
  'signUrl' => rest_url('nostr-signer/v1/sign-event'),
]);
```

Hinweis: `nostr-tools` ist ein Node/ESM-Paket. Für die SPA solltest du eine gebündelte Version (z. B. via Rollup/Webpack/Vite) erzeugen oder eine Browser-fertige Variante verwenden. Die Demo geht davon aus, dass `nostr-tools` als ESM importierbar ist.

## NIP-05 / .well-known/nostr.json

Das Plugin stellt eine dynamische `/.well-known/nostr.json`-Schnittstelle bereit, damit Benutzer ihre NIP-05-Adresse verifizieren können. Nach Aktivierung muss der Webmaster die Permalinks einmal neu speichern, damit die Rewrite-Regel greift.

## Fehlerbehandlung

Das Plugin nutzt WordPress-Standardfehler (`WP_Error`) und gibt sinnvolle HTTP-Statuscodes zurück. Prüfen Sie immer den HTTP-Status und das JSON-Feld `message` bei Fehlern.

## Entwicklung & Tests

- Composer-Abhängigkeiten sind bereits im `vendor/`-Verzeichnis enthalten.
- JavaScript-Beispiele finden Sie in `assets/js/`.

## Anwendungstipps & Deployment

 - Teste zunächst lokal mit `assets/test.html` oder `assets/spa-demo.html` und dem ungebündelten `assets/js/spa-nostr-app.js`. Verwende dafür einen eingeloggten Admin-Account.
 - Für Production: Baue die SPA mit Vite (`npm run build`). Lade anschließend die Dateien des Ordners `assets/dist` auf den Server (das `postbuild.js` erstellt `assets/dist/spa-nostr-app.bundle.js`).
- Enqueue-Beispiel (Production) im Plugin/Theme:

```php
function nostr_enqueue_spa_prod() {
  $bundle = plugin_dir_path(__FILE__) . 'assets/dist/spa-nostr-app.bundle.js';
  if ( file_exists( $bundle ) ) {
    wp_enqueue_script(
      'nostr-signer-spa',
      plugin_dir_url(__FILE__) . 'assets/dist/spa-nostr-app.bundle.js',
      [],
      filemtime($bundle),
      true
    );
    wp_localize_script('nostr-signer-spa', 'NostrSignerConfig', [
      'apiBase' => rest_url(),
      'nonce'   => wp_create_nonce('wp_rest'),
      'meUrl'   => rest_url('nostr-signer/v1/me'),
      'signUrl' => rest_url('nostr-signer/v1/sign-event'),
    ]);
  }
}
add_action('wp_enqueue_scripts', 'nostr_enqueue_spa_prod');
```

## Troubleshooting — häufige Probleme

- **403 Nonce-Fehler**: Stellen Sie sicher, dass der Header `X-WP-Nonce` gesetzt ist und der Benutzer eingeloggt ist. In der SPA wird `NostrSignerConfig.nonce` für diesen Zweck injiziert.
- **Master-Key nicht gesetzt**: Wenn `NOSTR_SIGNER_MASTER_KEY` fehlt, werden Verschlüsselungs- und Signierfunktionen deaktiviert. Fügen Sie die Konstante in `wp-config.php` hinzu.
- **nostr-tools importiert nicht**: Vergewissern Sie sich, dass Sie die gebündelte SPA-Version verwenden oder `nostr-tools` via Bundler in das Bundle aufgenommen wurde.
- **Relay-Verbindung scheitert**: Prüfen Sie die Relay-URL (WebSocket, `wss://`) und CORS/Netzwerkregeln. Einige Relays benötigen zusätzliche Authentifizierung oder blockieren Clients.

## Security-Checkliste vor Produktivbetrieb

- `NOSTR_SIGNER_MASTER_KEY` muss in `wp-config.php` liegen und darf nicht in Versionierung (Git) aufgenommen werden.
- Backend- und Datenbankzugriff absichern (SSL, Firewalls, Backups).
- Prüfen Sie Zugriffsrechte: Nur autorisierte Administratoren sollten Schlüssel importieren oder Blog-Schlüssel rotieren.
- Logging: Überwachen Sie fehlgeschlagene Signatur- bzw. Import-Versuche (dieses Plugin gibt WP-Fehler zurück — erweitern Sie bei Bedarf um Logging).
- Minimale Lebenszeit des entschlüsselten `nsec` im RAM: das Plugin unsetzt die Klartext-Variable; bestätigen Sie dies in Betriebsprüfungen.



---
Plugin-Dateien (Kurzreferenz):
- `nostr-signer.php` — Haupt-Plugin-Bootstrap
- `includes/Crypto.php` — Verschlüsselungsfunktionen
- `includes/KeyManager.php` — Schlüsselverwaltung
- `includes/NostrService.php` — Wrapper für Nostr-Library
- `includes/Rest/SignEventController.php` — REST-API Endpoints
- `assets/test.html` — lokale Demo-Seite
