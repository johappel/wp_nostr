# AGENTS.md

## Anweisungen für den KI-Code-Agenten

### 1. Globale Anweisungen

**Wichtige Anweisung:** Alle deine Antworten, Kommentare und der generierte Code (sofern Kommentare enthalten sind) müssen auf **Deutsch** verfasst sein. Die gesamte Kommunikation für dieses Projekt erfolgt in deutscher Sprache.

### 2. Projektübersicht

**Projektname:** WordPress Plugin "Nostr Signer"

**Ziel:** Erstelle ein WordPress-Plugin, das eine sichere serverseitige Infrastruktur zum Signieren von Nostr-Events bereitstellt. Das Plugin muss Schlüsselpaare für jeden Benutzer sowie ein globales Schlüsselpaar für den gesamten Blog verwalten. Private Schlüssel (`nsec`) müssen in der Datenbank verschlüsselt gespeichert werden. Der Master-Schlüssel für diese Verschlüsselung muss in der `wp-config.php` hinterlegt werden. Eine minimalistische JavaScript-Anwendung auf einer Admin-Seite soll die Funktionalität über REST-API-Endpunkte demonstrieren.

### 3. Technische Spezifikationen

#### 3.1. Plugin-Grundgerüst & Konfiguration

1.  **Plugin-Datei:** Erstelle eine Haupt-Plugin-Datei `nostr-signer.php` mit dem Standard-WordPress-Plugin-Header.
2.  **Abhängigkeiten:** Integriere eine PHP-Bibliothek für Nostr-Funktionen (z. B. `fedimint/nostr-php`) mittels Composer. Stelle sicher, dass der `vendor/autoload.php` korrekt eingebunden wird.
3.  **Master-Schlüssel-Handling:**
    *   Das Plugin muss eine Konstante namens `NOSTR_SIGNER_MASTER_KEY` in der `wp-config.php` voraussetzen.
    *   Implementiere eine `admin_notices`-Prüfung, die eine permanente Warnung anzeigt, falls `!defined('NOSTR_SIGNER_MASTER_KEY')`. Die Warnung soll den Admin anleiten, die Konstante mit einem sicheren, zufälligen Wert zu definieren.
    *   Die Kernfunktionalität des Plugins (insb. Ver- und Entschlüsselung) muss deaktiviert sein, solange die Konstante nicht gesetzt ist.

#### 3.2. Kryptografie-Modul

Erstelle eine PHP-Klasse oder Helper-Datei für die folgenden kryptografischen Operationen:

1.  **Verschlüsselungsfunktion `nostr_signer_encrypt(string $plaintext): string`:**
    *   Nutzt `openssl_encrypt` mit dem Algorithmus `AES-256-CBC`.
    *   Verwendet den `NOSTR_SIGNER_MASTER_KEY` als Schlüssel.
    *   Generiert bei jedem Aufruf einen neuen, zufälligen Initialisierungsvektor (IV) der korrekten Länge.
    *   Kombiniert den IV mit dem verschlüsselten Text und gibt das Ergebnis als `base64_encode()`-String zurück (z.B. `base64_encode($iv . $encrypted_text)`).
2.  **Entschlüsselungsfunktion `nostr_signer_decrypt(string $ciphertext): string|false`:**
    *   Nimmt den Base64-kodierten String entgegen.
    *   Dekodiert den String, extrahiert den IV und den verschlüsselten Text.
    *   Nutzt `openssl_decrypt` mit den extrahierten Daten und dem `NOSTR_SIGNER_MASTER_KEY`.
    *   Gibt den entschlüsselten Klartext (`nsec`) oder `false` bei einem Fehler zurück.

#### 3.3. Schlüsselmanagement

1.  **Benutzerschlüssel:**
    *   Verwende den `user_register`-Hook, um bei der Registrierung eines neuen Benutzers automatisch ein Nostr-Schlüsselpaar zu generieren.
    *   Speichere den `npub` im Klartext in der `user_meta` unter dem Schlüssel `nostr_npub`.
    *   Verschlüssele den `nsec` mit `nostr_signer_encrypt()` und speichere ihn in der `user_meta` unter dem Schlüssel `nostr_encrypted_nsec`.
2.  **Blog-Schlüssel:**
    *   Verwende den `register_activation_hook`, um bei der Aktivierung des Plugins ein globales Blog-Schlüsselpaar zu generieren.
    *   Führe eine Prüfung durch, um sicherzustellen, dass die Schlüssel nur einmal erstellt werden.
    *   Speichere den `npub` im Klartext in der `wp_options`-Tabelle unter dem Schlüssel `nostr_blog_npub`.
    *   Verschlüssele den `nsec` und speichere ihn in der `wp_options`-Tabelle unter dem Schlüssel `nostr_blog_encrypted_nsec`.

#### 3.4. REST-API-Endpunkt

1.  **Registrierung:** Registriere über den `rest_api_init`-Hook den folgenden Endpunkt:
    *   **Namespace:** `nostr-signer/v1`
    *   **Route:** `/sign-event`
    *   **Methode:** `POST`
2.  **Sicherheit:**
    *   **Permission Callback:** Der Endpunkt darf nur für authentifizierte WordPress-Benutzer (`is_user_logged_in`) zugänglich sein.
    *   **Nonce-Verifizierung:** Der Endpunkt muss zwingend einen WordPress-Nonce im `X-WP-Nonce`-Header der Anfrage erwarten und diesen validieren.
3.  **Logik der Callback-Funktion:**
    *   Die Funktion akzeptiert einen JSON-Body mit zwei Parametern:
        *   `event_data` (object): Das unvollständige Nostr-Event-Objekt (ohne `id`, `sig`, `pubkey`).
        *   `key_type` (string): Der Wert muss entweder `'user'` oder `'blog'` sein.
    *   **Ablauf:**
        1.  Validiere den Nonce. Bei Fehlschlag: Abbruch mit Fehler.
        2.  Je nach `key_type`, lade den entsprechenden verschlüsselten `nsec` (`nostr_encrypted_nsec` aus `user_meta` für den aktuellen Benutzer oder `nostr_blog_encrypted_nsec` aus `wp_options`).
        3.  Entschlüssele den `nsec` mit `nostr_signer_decrypt()`. **Der entschlüsselte `nsec` darf nur im Arbeitsspeicher gehalten werden.**
        4.  Verwende die Nostr-Bibliothek, um das `event_data` mit dem entschlüsselten `nsec` zu vervollständigen und zu signieren.
        5.  **Wichtig:** Unmittelbar nach der Signierung, lösche die Variable mit dem Klartext-`nsec` (`unset($nsec)`).
        6.  Gib das vollständige, signierte Event-Objekt als JSON zurück.
        7.  Implementiere eine robuste Fehlerbehandlung für alle Schritte.

#### 3.5. Admin-Frontend (JavaScript-Beispielanwendung)

1.  **Admin-Seite:** Erstelle eine einfache Admin-Seite via `add_menu_page()`.
2.  **HTML-Struktur:** Platziere auf dieser Seite:
    *   Ein `<textarea id="nostr-event-content">` für den Event-Inhalt.
    *   Zwei Radio-Buttons, um den `key_type` (`user` oder `blog`) auszuwählen.
    *   Einen `<button id="sign-button">`.
    *   Einen `<pre><code id="signed-event-output"></code></pre>`-Block zur Anzeige des Ergebnisses.
3.  **JavaScript-Einbindung:**
    *   Erstelle eine JS-Datei (`admin.js`) und binde sie nur auf dieser Admin-Seite mit `wp_enqueue_script` ein.
    *   Verwende `wp_localize_script`, um folgende PHP-Daten an das JS-Skript zu übergeben: `apiUrl` (die URL zum REST-Endpunkt) und `nonce` (generiert mit `wp_create_nonce()`).
4.  **JavaScript-Logik:**
    *   Erstelle einen Event-Listener für den Klick auf den Button.
    *   Bei Klick: Erstelle ein einfaches Nostr-Event-Objekt (`kind: 1`, `created_at`, etc.) mit dem Inhalt aus der Textarea.
    *   Sende eine `fetch`-Anfrage (POST) an die `apiUrl`.
    *   Füge den `Content-Type: application/json` und den `X-WP-Nonce` Header hinzu.
    *   Sende das Event-Objekt und den ausgewählten `key_type` im Body.
    *   Zeige das zurückgegebene, signierte Event im Ausgabe-Block an oder eine Fehlermeldung.


#### 3.6. Sicherer Import von bestehenden `nsec`

Implementieren Sie eine Funktion zum sicheren Importieren eines vorhandenen `nsec` auf einer Admin User-Profilseite oder einer dedizierten Plugin-Seite.

1.  **Server-Side (PHP):**
    *   Erstellen Sie eine Admin-Seite (für blog) und erweitern Sie die Benutzerprofilseite um ein Formular mit einem Eingabefeld für den `nsec`. Im Benutzerprofil sollte mein npub angezeigt sein.
    
    *   Wenn diese Seite geladen wird, generieren Sie einen temporären, sitzungsbasierten Verschlüsselungsschlüssel mit `hash_hmac('sha256', wp_get_session_token(), NOSTR_SIGNER_MASTER_KEY)`.
    *   Übergeben Sie diesen temporären Schlüssel via `wp_localize_script` an das Frontend-JavaScript. **Senden Sie niemals den `NOSTR_SIGNER_MASTER_KEY`!**

2.  **Client-Side (JavaScript):**
    *   Binden Sie die Bibliotheken `nostr-tools` und eine AES-Crypto-Implementierung (z.B. über die Web Crypto API) ein.
    *   Wenn der Benutzer das Formular abschickt:
        1.  Verhindern Sie den Standard-Submit.
        2.  Validieren Sie den `nsec` und leiten Sie den `npub` mit `nostr-tools` ab.
        3.  Verschlüsseln Sie den `nsec`-String mit dem vom Server erhaltenen temporären Schlüssel (AES-256-CBC).
        4.  Senden Sie den **verschlüsselten `nsec`** und den **Klartext-`npub`** an einen neuen REST-API-Endpunkt.

3.  **Neuer REST-API-Endpunkt (PHP):**
    *   **Route:** `/import-key`
    *   **Methode:** `POST`
    *   **Sicherheit:** Benötigt Login und Nonce-Verifizierung.
    *   **Logik:**
        1.  Regenerieren Sie serverseitig exakt denselben temporären Schlüssel wie beim Seitenaufbau.
        2.  Entschlüsseln Sie den empfangenen `nsec` mit diesem temporären Schlüssel.
        3.  **Wichtig:** Verschlüsseln Sie den nun im Klartext vorliegenden `nsec` **sofort neu**, aber dieses Mal mit dem permanenten `NOSTR_SIGNER_MASTER_KEY`.
        4.  Speichern Sie den neu verschlüsselten `nsec` und den `npub` in den `user_meta` des aktuellen Benutzers.
        5.  Löschen Sie die Klartext-Variable des `nsec` aus dem Speicher.



### 4. Zusammenfassung der Sicherheitsanforderungen

*   **Master-Schlüssel:** Darf NUR in `wp-config.php` stehen.
*   **Datenfluss:** Der private Schlüssel (`nsec`) darf niemals, weder verschlüsselt noch unverschlüsselt, an den Client (Browser) gesendet werden.
*   **Memory Management:** Die Lebensdauer des entschlüsselten `nsec` im serverseitigen Arbeitsspeicher muss auf das absolute Minimum beschränkt sein.
*   **Zugriffsschutz:** Der API-Endpunkt muss gegen unbefugten Zugriff und CSRF-Angriffe geschützt sein.
