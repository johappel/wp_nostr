# WP-CLI — Nostr Signer Commands

Kurze Übersicht der verfügbaren WP-CLI-Commands für das Nostr Signer Plugin.

Wichtig: Diese Commands ändern kryptografische Daten. Führe sie nur auf vertrauenswürdigen Hosts aus, sichere vorher ein verschlüsseltes DB-Backup und vermeide, Schlüssel in Shell-History zu speichern.

1) backup

Usage:

  wp nostrsigner backup <file>

Beschreibung:
  Exportiert alle verschlüsselten `nsec`-Werte (Blog + Benutzer) in die angegebene JSON-Datei (`<file>`).

Beispiel:

  wp nostrsigner backup /tmp/nostr-backup.json

Inhalt: JSON mit `generated_at`, `blog` und `users`.

2) keygen

Usage:

  wp nostrsigner keygen

Beschreibung:
  Generiert einen empfohlenen neuen Master-Key (64 hex Zeichen) und gibt ihn auf der Konsole aus. Setze den Key manuell in `wp-config.php` bevor du `recrypt` ausführst.

3) recrypt

Usage:

  wp nostrsigner recrypt <old_key> <new_key>

Beschreibung:
  Rekryptiert alle gespeicherten, verschlüsselten `nsec`-Werte von `old_key` auf `new_key`.

Beispiel:

  wp nostrsigner recrypt a1b2...f3 a4b5...e6

Warnung:
  Beide Keys sind sensibel. Halte sie nicht lange in der Shell-History; verwende sichere Umgebungen oder Secrets-Manager.

4) rotate

Usage:

  wp nostrsigner rotate --old=<old_key> --new=<new_key>

Beschreibung:
  Wrapper, der `recrypt` mit den angegebenen Werten aufruft. Praktisch für automatisierte Abläufe.

5) restore

Usage:

  wp nostrsigner restore <file>

Beschreibung:
  Stellt ein Backup-JSON wieder her und schreibt `npub` / `encrypted_nsec` für Blog und Benutzer zurück.

Beispiel:

  wp nostrsigner restore /tmp/nostr-backup.json

Sicherheits-Checklist vor Ausführung

- Always make a full encrypted DB backup before recrypt/restore.
- Do not expose master keys in logs or shell history. Prefer secrets managers or temporary environment variables.
- Test recrypt in a staging environment first (use a copy of DB + keys).
- Inform affected users if you performed a key rotation or emergency re-encryption.

If you want, I can add a `--dry-run` option and batch/transaction support for `recrypt` to reduce risk for large sites.
