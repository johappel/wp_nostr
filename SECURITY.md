# Security Guide – Nostr Signer

Diese Datei fasst sicherheitsrelevante Empfehlungen, Backup- und Rotationsverfahren für den Master-Schlüssel (`NOSTR_SIGNER_MASTER_KEY`), die Key-Encryption-Keys (`NOSTR_SIGNER_KEY_Vx`) sowie die verschlüsselten `nsec`-Envelopes zusammen.

## Grundprinzipien

- Der Master-Key (`NOSTR_SIGNER_MASTER_KEY`) liegt ausschließlich in `wp-config.php` und dient für temporäre Import-Schlüssel sowie als Fallback zur Entschlüsselung alter Datensätze.
- Key-Encryption-Keys (`NOSTR_SIGNER_KEY_Vx`) verschlüsseln die eigentlichen Nostr-Secrets. Für jeden aktiven Wert existiert eine Version (`kv`) im Envelope.
- `nsec`-Werte befinden sich in der Datenbank nur verschlüsselt; Klartext verlässt den Server nie und wird nach der Nutzung mit `unset()` entfernt.
- Authentifizierung + Nonce-Schutz sind Pflicht für alle Signier- und Import-Endpunkte.

## Backup der verschlüsselten `nsec`-Werte

1. Regelmäßig Datenbank-Backups (inkl. `user_meta`, `wp_options`) anfertigen.
2. Backups verschlüsselt speichern und nur befugten Personen zugänglich machen.
3. Ohne gültige KEKs (und ggf. Master-Key für Legacy-Werte) lassen sich die gespeicherten Envelopes nicht entschlüsseln – sichere Aufbewahrung aller Schlüssel ist daher essenziell.

Empfehlungen:
- Tägliche Voll-Backups, differenzielle Backups nach Bedarf.
- Wiederherstellungen periodisch testen: Restore in isolierter Umgebung, Signatur-Endpunkt mit Testdaten prüfen.
- Backups immer gemeinsam mit einem Schlüssel-Inventar (Versionen, Aktiv-Status) dokumentieren.

## Kompromittierung eines KEK oder des Master-Schlüssels – Notfallplan

### Aktiver KEK kompromittiert
1. In `wp-config.php` einen neuen Eintrag `NOSTR_SIGNER_KEY_V{n}` hinzufügen und `NOSTR_SIGNER_ACTIVE_KEY_VERSION` auf die neue Nummer setzen (ggf. `NOSTR_SIGNER_MAX_KEY_VERSIONS` anpassen).
2. Cron-/CLI-Rewrap starten (`nostr_signer_rotate_event` oder `wp nostrsigner rotate`), damit alle Envelopes mit alten `kv`-Werten auf den neuen KEK umgewickelt werden.
3. Monitoring: Admin-Notice bzw. CLI-Ausgabe prüfen. Sobald keine Envelopes mehr auf den kompromittierten `kv` verweisen, den alten `NOSTR_SIGNER_KEY_Vx` aus allen Systemen entfernen.
4. Vorfall dokumentieren und betroffene Systeme/Benutzer informieren.

### Master-Key kompromittiert
1. `NOSTR_SIGNER_MASTER_KEY` in `wp-config.php` durch einen neuen Zufallswert ersetzen.
2. Legacy-Daten (altes CBC-Format) über `wp nostrsigner recrypt` oder den automatischen Rewrap migrieren; neue Envelopes nutzen automatisch den aktiven KEK.
3. Temporäre HMAC-Schlüssel für den Import verlieren nach der nächsten Session ihre Gültigkeit – optional alle Sessions abmelden.
4. Kompromittierten Master-Key aus allen Secret-Stores löschen.

> Hinweis: Ohne Zugriff auf den ursprünglichen KEK/Master-Key sind vorhandene Envelopes nicht wiederherstellbar. Benutzer müssen ihre privaten Schlüssel neu importieren.

## Regelmäßige Rotation der KEKs

1. Neuen KEK definieren (`NOSTR_SIGNER_KEY_V{n}`) und `NOSTR_SIGNER_ACTIVE_KEY_VERSION` erhöhen.
2. Cron-Job oder WP-CLI (`wp nostrsigner rotate`, `wp nostrsigner rewrap --limit=...`) ausführen, bis alle Envelopes auf zulässige Versionen (`NOSTR_SIGNER_MAX_KEY_VERSIONS`) gebracht wurden.
3. Admin-Notice „Alt-Key kann entfernt werden“ beachten oder CLI-Report prüfen.
4. Nicht mehr benötigte KEKs aus `wp-config.php` und externen Secrets entfernen.

Während der Rotation bleiben ältere Versionen lesbar, solange sie innerhalb des definierten Versionsfensters liegen.

## Minimaler CLI-/Automatisierungs-Workflow

Für individuelle Migrationsszenarien (z. B. außerplanmäßige Rotation oder Recovery) empfiehlt sich folgendes Muster:

1. Vollständiges Datenbank-Backup erstellen.
2. Alte und neue Schlüssel als Umgebungsvariablen nur für die Laufzeit des Prozesses verfügbar machen.
3. Über WP-CLI (`wp nostrsigner rotate`, `wp nostrsigner rewrap`, `wp nostrsigner recrypt`) oder ein eigenes PHP-Skript alle betroffenen Envelopes batchweise verarbeiten.
4. Nach Abschluss Stichproben testen (Signatur-Endpunkt, Key-Import) und Erfolg protokollieren.
5. Schlüsselmaterial und temporäre Dateien sofort vernichten.

Weiterführende Details zu den WP-CLI-Kommandos stehen in `WP-CLI.md`.
