# Nostr Signer — WordPress Plugin

Dieses Plugin stellt eine sichere, serverseitige Infrastruktur bereit, um Nostr-Events innerhalb eines WordPress-Blogs zu signieren. Es verwaltet pro-Benutzer-Schlüsselpaare sowie ein globales Blog-Schlüsselpaar und bietet REST-API-Endpunkte, die von beliebigen Nostr-Apps konsumiert werden können.

Wichtig: Alle `nsec`-Private-Keys werden verschl�sselt in der Datenbank gespeichert. Das Plugin nutzt ein Enveloping-Verfahren mit Key-Wrapping: In `wp-config.php` m�ssen der dauerhafte Master-Schl�ssel `NOSTR_SIGNER_MASTER_KEY` sowie mindestens ein Key-Encryption-Key (`NOSTR_SIGNER_KEY_Vx`) hinterlegt sein. Die aktive KEK-Version steuern Sie �ber `NOSTR_SIGNER_ACTIVE_KEY_VERSION`.

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
2. In `wp-config.php` die Schl�ssel-Konfiguration setzen (Beispiel):

```php
define('NOSTR_SIGNER_MASTER_KEY', 'base64:BitteMitSicheremZufallswertErsetzen=');
define('NOSTR_SIGNER_ACTIVE_KEY_VERSION', 2);
define('NOSTR_SIGNER_MAX_KEY_VERSIONS', 2);
define('NOSTR_SIGNER_KEY_V1', 'base64:AltKeyBasis64==');
define('NOSTR_SIGNER_KEY_V2', 'base64:AktiverKeyBasis64==');
```

3. Plugin im WordPress-Admin aktivieren. Beim Aktivieren werden (falls noch nicht vorhanden) die Blog-Schl�ssel erzeugt und in den `wp_options` gespeichert (der `nsec` ist verschl�sselt).

Hinweis: Falls `NOSTR_SIGNER_MASTER_KEY` oder die f�r die aktive Version ben�tigten `NOSTR_SIGNER_KEY_Vx`-Werte fehlen, deaktiviert das Plugin alle kryptografischen Funktionen und zeigt eine Admin-Notice.

## Konfiguration

- `NOSTR_SIGNER_MASTER_KEY` in `wp-config.php` (wird u. a. f�r tempor�re Importschl�ssel und Legacy-Daten ben�tigt).
- `NOSTR_SIGNER_ACTIVE_KEY_VERSION` (int >= 1) bestimmt, welcher KEK zum Verschl�sseln neuer Werte verwendet wird.
- `NOSTR_SIGNER_MAX_KEY_VERSIONS` legt fest, wie viele KEK-Versionen parallel akzeptiert werden (f�r Rotation).
- `NOSTR_SIGNER_KEY_V{n}` (z. B. `NOSTR_SIGNER_KEY_V1`, `NOSTR_SIGNER_KEY_V2`) enthalten jeweils einen 32-Byte-Kek in Base64/Hex/Raw.
- Standardm��ig werden pro Benutzer `nostr_npub` (klartext) und `nostr_encrypted_nsec` (verschl�sselt) in `user_meta` abgelegt.
- Blog-Keys werden unter `nostr_blog_npub` und `nostr_blog_encrypted_nsec` in `wp_options` gespeichert.
- Private Schlüssel (`nsec`) werden in der DB nur verschlüsselt abgelegt und niemals an den Client gesendet.
- Entschl�sseln des gespeicherten Envelopes (DEK wird mit dem aktiven KEK freigelegt; Legacy-Werte fallen auf den Master-Schl�ssel zur�ck)
- REST-Endpunkte verlangen Authentifizierung und WordPress-Nonce (CSRF-Schutz).

## REST API — Übersicht

Namespace: `nostr-signer/v1`

- `POST /sign-event` — Signiert ein (unvollständiges) Nostr-Event mit dem `user`- oder `blog`-Key.
- `GET /me` — Liefert Metadaten zu aktuellem Benutzer und Blog (npub/hex, avatar, blog-name, etc.).
- `POST /import-key` � (Admin/Profil) sichere Import-Route f�r bestehende `nsec`-Keys (Client-seitige Verschl�sselung mit tempor�rem Schl�ssel, serverseitige Re-Verschl�sselung mit dem aktiven KEK).

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
- Entschl�sseln des gespeicherten Envelopes (DEK wird mit dem aktiven KEK freigelegt; Legacy-Werte fallen auf den Master-Schl�ssel zur�ck).
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

Kurzbeschreibung: Der `nsec` wird clientseitig mit einem tempor�ren Schl�ssel verschl�sselt und dann an den Server gesendet. Der Server entschl�sselt den tempor�ren Layer und verpackt den Klartext sofort erneut in einen Envelope, der mit dem aktiven `NOSTR_SIGNER_KEY_V{NOSTR_SIGNER_ACTIVE_KEY_VERSION}` gesichert wird.

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

Damit die SPA (`assets/spa-demo.html` + `assets/js/spa-demo-app.js`) in WordPress funktioniert, muss das Theme/Plugin beim Enqueue des Scripts eine kleine Konfiguration in `window.NostrSignerConfig` injizieren. Beispiel im Plugin/Theme-PHP:

```php
wp_enqueue_script('nostr-spa-demo', plugin_dir_url(__FILE__) . 'assets/js/spa-demo-app.js', [], null, true);
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

 - Teste zunächst lokal mit `assets/test.html` oder `assets/spa-demo.html` und dem ungebündelten `assets/js/spa-demo-app.js`. Verwende dafür einen eingeloggten Admin-Account.
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
- **Schl�ssel-Config fehlt**: Wenn `NOSTR_SIGNER_MASTER_KEY` oder der aktive `NOSTR_SIGNER_KEY_Vx` nicht gesetzt sind, werden Verschl�sselungs- und Signierfunktionen deaktiviert. Erg�nzen Sie die Konstanten in `wp-config.php`.
- **nostr-tools importiert nicht**: Vergewissern Sie sich, dass Sie die gebündelte SPA-Version verwenden oder `nostr-tools` via Bundler in das Bundle aufgenommen wurde.
- **Relay-Verbindung scheitert**: Prüfen Sie die Relay-URL (WebSocket, `wss://`) und CORS/Netzwerkregeln. Einige Relays benötigen zusätzliche Authentifizierung oder blockieren Clients.

## Security-Checkliste vor Produktivbetrieb

- `NOSTR_SIGNER_MASTER_KEY` muss in `wp-config.php` liegen und darf nicht in Versionierung (Git) aufgenommen werden.
- Backend- und Datenbankzugriff absichern (SSL, Firewalls, Backups).
- Prüfen Sie Zugriffsrechte: Nur autorisierte Administratoren sollten Schlüssel importieren oder Blog-Schlüssel rotieren.
- Logging: Überwachen Sie fehlgeschlagene Signatur- bzw. Import-Versuche (dieses Plugin gibt WP-Fehler zurück — erweitern Sie bei Bedarf um Logging).
- Minimale Lebenszeit des entschlüsselten `nsec` im RAM: das Plugin unsetzt die Klartext-Variable; bestätigen Sie dies in Betriebsprüfungen.

Weitere, ausführliche Hinweise zu Backup, Rotation und Notfallmaßnahmen findest du in `SECURITY.md`.

## WP-CLI Kommandos

Das Plugin stellt eine Reihe von WP-CLI-Helpers bereit, um Backups, Rotation und Wiederherstellung zu unterstützen. Die Commands sind verfügbar, wenn sich die WP-Installation mit WP-CLI aufruft.

- `wp nostrsigner backup <file>` — Exportiert alle verschlüsselten `nsec`-Werte (Blog + Benutzer) in eine JSON-Datei.
- `wp nostrsigner keygen` � Erzeugt einen neuen 32-Byte-Schl�ssel (Base16). Nutzen Sie ihn z.?B. als neuen `NOSTR_SIGNER_KEY_V{n}` oder als Master-Key, bevor Sie Rotation/Import durchf�hren.
- `wp nostrsigner recrypt <old_key> <new_key>` — Rekryptiert alle gespeicherten `nsec`-Werte von `old_key` auf `new_key`.
- `wp nostrsigner rotate --old=<old_key> --new=<new_key>` — Wrapper, der `recrypt` mit den angegebenen Werten ausführt.
- `wp nostrsigner restore <file>` — Stellt die in `<file>` gespeicherte JSON-Backup-Datei wieder her (überschreibt Optionen und user_meta für verschlüsselte nsec).

Wichtig: Behandeln Sie alle Schl�ssel (Master und KEKs) vertraulich. Hinterlegen Sie neue Keys zuerst in `wp-config.php`, bevor Sie Re-Wrap-Vorg�nge ansto�en. Erstellen Sie ein Backup, bevor Sie Re-Encrypting/Rollback-Operationen starten.


## Wichtige Klarstellungen und Empfehlungen

- Authentifizierung: Die Signatur-API verwendet die WordPress-Authentifizierung (eingeloggte Benutzer) und Nonce-basierte CSRF-Prüfung. Das bedeutet, dass die Kontrolle über Signier-Anfragen an die WordPress-Benutzerkonten gebunden ist — nicht an Nostr-Identitäten.
- Keine Schlüssel an den Client: Weder verschlüsselte noch entschlüsselte `nsec`-Werte werden an den Browser gesendet. Alle Signieroperationen finden serverseitig statt.
- Rechte & Rollen: Standardmäßig dürfen eingeloggte Benutzer Signatur-Anfragen stellen. Wenn du restriktivere Regeln willst (z. B. nur Autoren/Admins für `blog`-Signaturen), erweitere die `permission_check()`-Logik oder prüfe Benutzer-Capabilities beim Anfordern von `key_type === 'blog'`.
- Audit & Logging: Implementiere ein Audit-Log für Signatur-Aktionen (wer hat wann signiert, welcher `key_type`, ob Broadcast erfolgte). Vermeide das Loggen sensibler Inhalte wie Klartext-`nsec`.
- Rate-Limiting: Füge serverseitiges Rate-Limiting pro Benutzer/IP hinzu, um Missbrauch zu begrenzen (z. B. transient-basierte Zähler oder Integration mit einem Reverse-Proxy / WAF).
- Input-Validierung: Validere `event`-Felder (z. B. `kind` als Integer, `tags` als Array von Arrays), um fehlerhafte Requests und mögliche Ausnutzungen der Nostr-Library zu verhindern.
- Schlüsselrotation & Backup: Dokumentiere Prozesse für Schlüssel-Rotation und sichere Backups der `wp_options`/`user_meta`-Werte (nur verschlüsselte `nsec` speichern). Plane einen Ablauf für den Fall, dass `NOSTR_SIGNER_MASTER_KEY` kompromittiert wird.
- Import-Flow: Der temporäre Schlüssel für den Import wird aus der Session abgeleitet und ist nur kurz gültig. 

TODO:
- Rate-Limits
- Backup/Rotation



---
Plugin-Dateien (Kurzreferenz):
- `nostr-signer.php` — Haupt-Plugin-Bootstrap
- `includes/Crypto.php` — Verschlüsselungsfunktionen
- `includes/KeyManager.php` — Schlüsselverwaltung
- `includes/NostrService.php` — Wrapper für Nostr-Library
- `includes/Rest/SignEventController.php` — REST-API Endpoints
- `assets/test.html` — lokale Demo-Seite


