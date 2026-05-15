# CLZ App Migration nach Metanet/Plesk

Dieses Paket ist der Startpunkt fuer den Umbau der bestehenden Google-Apps-Script-App in eine eigenstaendige PHP/MySQL-App fuer Metanet Reseller Hosting mit Plesk.

## Zielbild

- Frontend bleibt eine PWA mit HTML/CSS/JavaScript.
- `google.script.run(...)` wird durch `fetch(...)` gegen eigene PHP-Endpunkte ersetzt.
- Google Sheets werden durch MySQL/MariaDB Tabellen ersetzt.
- Elvanto API-Aufrufe laufen nur serverseitig.
- Import und Cache-Rebuild laufen ueber Admin-Button und optional Plesk-Cronjob.
- Deployment per Git in Plesk oder per SFTP/FTP.

## Warum PHP statt Google Apps Script

Die bestehende App benutzt Apps-Script-spezifische Dienste:

- `HtmlService`
- `SpreadsheetApp`
- `CacheService`
- `PropertiesService`
- `LockService`
- `Session.getActiveUser()`
- `google.script.run` im Browser

Diese Dienste gibt es auf Metanet/Plesk nicht. Deshalb wird die App portiert:

- `SpreadsheetApp` -> MySQL Tabellen
- `CacheService` -> DB-Cache oder Dateicache
- `PropertiesService` -> `.env` plus Tabelle `app_settings`
- `LockService` -> DB-Locks
- `Session.getActiveUser()` -> eigenes Login
- `google.script.run` -> `fetch('/api/...')`

## Ordner

- `docs/` Schrittplan und Mapping
- `database/` SQL-Schema fuer MySQL/MariaDB
- `public/` oeffentlicher Webroot fuer Plesk
- `src/` PHP-App-Code ausserhalb des Webroots
- `scripts/` CLI-Scripts fuer Import/Cron

## Erste lokale Naechste Schritte

1. In Plesk Domain/Subdomain anlegen, z. B. `app.deinedomain.ch`.
2. Datenbank und Datenbank-Benutzer erstellen.
3. Dateien hochladen oder Git-Deployment verbinden.
4. `.env` aus `.env.example` erstellen.
5. `database/schema.sql` via phpMyAdmin importieren.
6. `/api/ping.php` pruefen.
7. Danach Import-Scripts portieren und aktivieren.

## Google Login

Die App unterstuetzt Google OAuth Login ueber PHP-Sessions.

In der Google Cloud Console eine OAuth-Client-ID vom Typ `Web application` erstellen und diese Redirect-URI eintragen:

```text
https://app.clzspiez.ch/api/auth/google-callback.php
```

Danach in `.env` setzen:

```text
APP_URL=https://app.clzspiez.ch
GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
APP_ADMIN_EMAILS=deine.admin.mail@example.ch
```

Der erste Login legt den User automatisch in der Tabelle `users` an. E-Mails in `APP_ADMIN_EMAILS` erhalten die Rolle `admin`, alle anderen `member`.
