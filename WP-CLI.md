# WP-CLI - Nostr Signer Commands

Kurzueberblick ueber die verfuegbaren WP-CLI-Kommandos des Plugins. Alle Befehle aendern kryptografische Daten - teste sie zuerst in Staging, sichere zuvor ein Datenbank-Backup und halte Geheimnisse nicht in der Shell-History.

1) backup

Usage:

  wp nostrsigner backup <file>

Beschreibung:
  Exportiert alle verschluesselten `nsec`-Werte (Blog + Benutzer) als JSON in `<file>`.

Beispiel:

  wp nostrsigner backup /tmp/nostr-backup.json

Inhalt: JSON mit `generated_at`, `blog` und `users`.

2) keygen

Usage:

  wp nostrsigner keygen

Beschreibung:
  Erzeugt einen neuen 32-Byte-Schluessel (hex). Verwende ihn als neuen `NOSTR_SIGNER_KEY_V{n}` fuer KEK-Rotation oder als Master-Key fuer Legacy-Daten.

3) recrypt

Usage:

  wp nostrsigner recrypt <old_key> <new_key>

Beschreibung:
  Rekryptiert alle gespeicherten `nsec`-Werte vom alten Master-Key auf einen neuen. Nur fuer Altsysteme ohne Envelope-Format noetig.

Beispiel:

  wp nostrsigner recrypt a1b2...f3 a4b5...e6

4) rotate

Usage:

  wp nostrsigner rotate [--limit=<anzahl>] [--reset]

Beschreibung:
  Fuehrt den Re-Wrap-Durchlauf fuer das aktuelle Envelope-Schema aus. `--limit` steuert die Batch-Groesse (Standard 200). `--reset` setzt den Rotationsstatus zurueck und startet erneut bei Seite 1.

Beispiel:

  wp nostrsigner rotate --limit=500

Die Ausgabe nennt, wie viele Envelopes in diesem Batch verarbeitet wurden. Wenn weitere Batches ausstehen, fuehre den Befehl erneut aus oder warte auf den Cronjob `nostr_signer_rotate_event`.

5) restore

Usage:

  wp nostrsigner restore <file>

Beschreibung:
  Stellt ein Backup-JSON wieder her und schreibt `npub` / `encrypted_nsec` fuer Blog und Benutzer zurueck.

Beispiel:

  wp nostrsigner restore /tmp/nostr-backup.json

Sicherheits-Checkliste vor kritischen Befehlen

- Immer ein vollstaendiges, verschluesseltes DB-Backup anlegen.
- Geheimnisse nur kurzzeitig im Speicher halten, bevorzugt ueber Secrets-Manager einspielen.
- Rotation zuerst in einer Staging-Umgebung testen und Stichproben pruefen.
- Nutzer informieren, wenn eine Schluesselrotation oder ein Notfall-Rewrap erfolgt ist.
- Nach Abschluss alte KEKs aus `wp-config.php` und externen Secret-Stores entfernen.