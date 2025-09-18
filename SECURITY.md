# Security Guide — Nostr Signer

Diese Datei fasst sicherheitsrelevante Empfehlungen, Backup- und Rotationsverfahren für das `NOSTR_SIGNER_MASTER_KEY` und die verschlüsselten `nsec`-Werte zusammen.

## Grundprinzipien

- Der Master-Key (`NOSTR_SIGNER_MASTER_KEY`) darf NUR in `wp-config.php` stehen und niemals in Versionskontrolle (Git) oder in Log-Dateien erscheinen.
- Private Schlüssel (`nsec`) werden in der Datenbank nur verschlüsselt gespeichert. Die DB-Sicherung darf die verschlüsselten Werte enthalten, niemals Klartext-`nsec`.
- Bei jeder Operation, die einen entschlüsselten `nsec` benötigt, muss der Klartext nur kurz im RAM existieren und unmittelbar nach Nutzung gelöscht werden.

## Backup der verschlüsselten `nsec`-Werte

1. Regelmäßige Backups der WordPress-Datenbank inkl. `user_meta` und `wp_options` durchführen.
2. Sicherstellen, dass Backups verschlüsselt abgelegt und nur autorisierten Admins zugänglich sind.
3. Wichtiger Hinweis: Backups enthalten die `nsec`-Werte nur in verschlüsselter Form. Ohne den korrekten `NOSTR_SIGNER_MASTER_KEY` sind die `nsec`-Werte nicht nutzbar.

Empfohlener Backup-Workflow:

- Vollständige DB-Backups mindestens täglich; differenzielle Backups nach Bedarf.
- Verwende verschlüsselte Backup-Speicher (z. B. verschlüsselte S3-Buckets oder verschlüsselte Filesystem-Volumes).
- Teste Wiederherstellungen periodisch in einer sicheren Testumgebung (restore + Versuch, mit aktuellem Master-Key zu entschlüsseln).

## Kompromittierung des `NOSTR_SIGNER_MASTER_KEY` — Notfallplan

Wenn du annimmst, dass der Master-Key kompromittiert wurde, folge diesen Schritten:

1. Sofort den kompromittierten Master-Key in `wp-config.php` überschreiben (ersetzen) durch einen neuen, starken zufälligen Wert.
2. Erzeuge ein neues Master-Key-Paar: wähle `NEW_MASTER_KEY` und setze `NOSTR_SIGNER_MASTER_KEY = NEW_MASTER_KEY` in `wp-config.php`.
3. Nun müssen alle gespeicherten `nsec`-Werte neu verschlüsselt werden. Ablauf:
   - Entschlüssele jede gespeicherte `nsec` mit dem alten Master-Key (dies setzt voraus, dass du noch Zugriff auf den alten Key hast) — wenn der alte Key nicht mehr verfügbar ist, informiere Benutzer, dass ein Re-Import der privaten Schlüssel nötig ist.
   - Verschlüssele jedes `nsec` mit dem neuen `NOSTR_SIGNER_MASTER_KEY` und speichere die neuen verschlüsselten Werte in `user_meta`/`wp_options`.
   - Lösche temporäre Klartext-Variablen sofort nach Nutzung.
4. Informiere betroffene Benutzer über die Maßnahme und die nötigen Schritte (z. B. erneuter Login, optionaler Re-Import von `nsec` falls alte `nsec` nicht mehr verfügbar sind).
5. Rotations- und Forensik-Plan: Führe ein Audit-Log der betroffenen Aktionen und analysiere, wie der Schlüssel kompromittiert wurde.

Hinweis: Wenn der alte Master-Key verloren oder dauerhaft unbrauchbar ist, ist eine re-verschlüsselung nicht möglich; in diesem Fall müssen Benutzer ihre privaten `nsec`-Schlüssel neu importieren.

## Regelmäßige Rotation des `NOSTR_SIGNER_MASTER_KEY`

Ja, der Master-Key kann regelmäßig rotiert werden — das erfordert aber ein definiertes Verfahren:

Anforderungen für Rotation:

- Temporärer Zugriff auf den aktuellen (alten) Master-Key, um bestehende `nsec` zu entschlüsseln.
- Ein sicheres Verfahren, um den neuen Master-Key in `wp-config.php` zu setzen (z. B. durch CI/CD Secrets Management oder manuelles Setzen auf dem Host).
- Ein Skript oder Routine, die alle `nsec`-Einträge entschlüsselt und mit dem neuen Master-Key wieder verschlüsselt.

Vorgeschlagener Rotationsablauf:

1. Generiere einen neuen starken Master-Key (`NEW_MASTER_KEY`).
2. Setze `NOSTR_SIGNER_MASTER_KEY = NEW_MASTER_KEY` in `wp-config.php` (z. B. durch Deployment-Tooling).
3. Führe ein Migrationsskript aus, das für jede `user_meta`/`wp_options`-Spalte mit einem verschlüsselten `nsec`:
   - entschlüsselt mit dem alten Master-Key,
   - verschlüsselt mit dem neuen Master-Key,
   - schreibt das Ergebnis zurück.
4. Validierung: Teste stichprobenartig, dass einige Benutzer sich weiterhin signieren lassen können (Signatur-Endpoint aufrufen und Ergebnis prüfen).
5. Alte Schlüssel sicher vernichten (z. B. aus CI/CD Secrets, temporären Dateien oder Logs entfernen).

Automatisierung: Für produktive Umgebungen empfiehlt sich ein Skript (CLI/PHP) mit folgenden Eigenschaften:

- Backup aller aktuellen verschlüsselten Werte vor der Rotation.
- Transaktionsartige Migration (z. B. Batch-Verarbeitung, atomare Updates per DB-Transactions wo möglich).
- Fehler-Handling: Bei Migrationsfehlern sollte das Skript die Situation protokollieren und den Prozess abbrechen, damit kein inkonsistenter Zustand entsteht.

## Minimaler CLI-Migrations-Skript (Konzept)

Dieses Repository enthält kein fertiges Tool zum Rotieren des Master-Keys. Das folgende Konzept gibt jedoch die nötigen Schritte wieder:

1. Backup der DB.
2. Setze Umgebung so, dass der alte Master-Key und der neue Master-Key verfügbar sind (z. B. in Umgebungsvariablen für das Skript, aber NICHT dauerhaft in Versionierung).
3. Skript iteriert über alle Benutzer, entschlüsselt `nostr_encrypted_nsec` mit `OLD_MASTER_KEY`, verschlüsselt mit `NEW_MASTER_KEY`, speichert zurück.
4. Validierungsphase und Abschluss-Report.

Hinweis zur Sicherheit: Halte sowohl alte als auch neue Master-Key-Werte nur temporär im Speicher und lösche sie unmittelbar nach Verwendung.

## Empfehlung: Secrets-Management

- Verwende einen Secret-Manager (z. B. HashiCorp Vault, AWS Secrets Manager, Azure Key Vault) statt harte Kodierung in Deployments.
- Rotationsprozesse sollten über das Secrets-Management orchestriert werden (z. B. Rotation-Trigger, Rollback-Pläne).

## Weitere Hardening-Maßnahmen (kurz)

- Rate-Limit REST-Endpoints (transient- oder redis-basiert).
- Begrenze die Zugriffsrechte für Import/Blog-Key-Operationen auf Admin-Rollen.
- Implementiere Audit-Logs für Signatur- und Import-Aktionen.
- Schütze Backup-Zugriff mit MFA und Netzwerkzugriffsregeln.

---
Wenn du willst, kann ich ein konkretes PHP-CLI-Migrationsskript erstellen, das die Schlüsselrotation durchführt, plus ein optionales Admin-UI, um Rotation oder Re-Import-Prozesse zu steuern.
