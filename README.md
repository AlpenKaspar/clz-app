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
- `google.script.run` -> `fetch('/api/...')`; alte Frontend-Funktionsnamen duerfen nur als PHP-RPC-Router weiterleben.

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

## Import-Scripts fuer Plesk Cron

Auf Metanet/Plesk liegen die Scripts im App-Verzeichnis und koennen mit PHP 8.2 ausgefuehrt werden:

```text
cd /home/httpd/vhosts/ypg.ch/app.clzspiez.ch && /opt/plesk/php/8.2/bin/php scripts/import_people.php
cd /home/httpd/vhosts/ypg.ch/app.clzspiez.ch && /opt/plesk/php/8.2/bin/php scripts/import_families.php
cd /home/httpd/vhosts/ypg.ch/app.clzspiez.ch && /opt/plesk/php/8.2/bin/php scripts/import_groups.php
cd /home/httpd/vhosts/ypg.ch/app.clzspiez.ch && /opt/plesk/php/8.2/bin/php scripts/import_calendar.php
cd /home/httpd/vhosts/ypg.ch/app.clzspiez.ch && /opt/plesk/php/8.2/bin/php scripts/import_service_details.php
cd /home/httpd/vhosts/ypg.ch/app.clzspiez.ch && /opt/plesk/php/8.2/bin/php scripts/import_service_media.php
cd /home/httpd/vhosts/ypg.ch/app.clzspiez.ch && /opt/plesk/php/8.2/bin/php scripts/import_songs.php
```

`import_service_details.php` prueft standardmaessig nur einen Footprint der naechsten 3 Gottesdienste
und importiert Details nur, wenn sich Ablauf, Mitarbeitende, Zeiten, Dateien oder Notizen geaendert haben.
Ein kompletter Lauf ist weiterhin manuell moeglich:

```text
cd /home/httpd/vhosts/ypg.ch/app.clzspiez.ch && /opt/plesk/php/8.2/bin/php scripts/import_service_details.php --force
```

Der Songimport fuellt die Tabelle `songs` aus Elvanto und liefert die Daten wieder im alten Frontend-Format
(`songId`, `songTitle`, `arrangements`), damit die bestehende Songansicht weiter funktioniert.

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
APP_SUPER_ADMIN_EMAILS=deine.admin.mail@example.ch
APP_ADMIN_EMAILS=deine.admin.mail@example.ch
APP_SESSION_DAYS=30
```

Der erste Login legt den User automatisch in der Tabelle `users` an. E-Mails in `APP_SUPER_ADMIN_EMAILS` erhalten die Rolle `super_admin`, E-Mails in `APP_ADMIN_EMAILS` erhalten die Rolle `admin`, alle anderen starten sicherheitshalber als `guest`, bis sie freigegeben werden.
Nur Super-Admins koennen Benutzer und Rollen in der App unter `Mehr > Benutzer & Rollen` verwalten oder andere Benutzer simulieren. Admins duerfen Daten aktualisieren, aber keine Rollen vergeben. Beim spaeteren Google-Login
muss die Google-Adresse exakt mit dieser E-Mail uebereinstimmen.
Mindestens eine echte Verwaltungsadresse sollte deshalb in `APP_SUPER_ADMIN_EMAILS` stehen.

Neue Google-Logins mit Rolle `guest` erzeugen direkt eine Push-Benachrichtigung fuer aktive Super-Admins
mit Push-Subscription. Dafuer ist kein Cron noetig. Geburtstags-Pushes laufen weiter ueber die geplanten
Tasks `send_birthday_notifications.php` und `send_weekly_birthday_notifications.php`.

`APP_SESSION_DAYS` steuert, wie lange ein Login gueltig bleibt. Die App erneuert die Session bei normalen API-Aufrufen
und im Hintergrund automatisch, solange der Browser offen ist.

Die Login-Benutzer und Rollen stehen in MySQL in der Tabelle `users`. Die Listen `APP_SUPER_ADMIN_EMAILS` und `APP_ADMIN_EMAILS` liegen nur in `.env`
und dienen als Bootstrap/Override fuer Super-Admins und Admins. `.env` wird nicht nach Git committet und liegt nicht unter `public/`.
E-Mail-Adressen in `users` sind keine Secrets; OAuth-Secret, Elvanto-Key und Datenbankpasswort muessen in `.env` bleiben.

Falls dein User bereits vor dem Setzen von `APP_SUPER_ADMIN_EMAILS` erstellt wurde, reicht normalerweise ein Logout/Login,
weil die Rolle beim Laden der Session anhand der Listen aktualisiert wird. Alternativ kann die Rolle in phpMyAdmin
direkt gesetzt werden:

```sql
UPDATE users SET role = 'super_admin' WHERE email = 'deine.admin.mail@example.ch';
```

Wichtig: Die E-Mail in `APP_SUPER_ADMIN_EMAILS` oder `APP_ADMIN_EMAILS` muss exakt der Google-Login-Adresse entsprechen. Mehrere Adressen werden
kommagetrennt eingetragen, zum Beispiel:

```text
APP_SUPER_ADMIN_EMAILS=owner@example.ch
APP_ADMIN_EMAILS=admin1@example.ch,admin2@example.ch
```

## Lokal mit Docker testen

Auf Windows ist kein lokales PHP noetig, wenn Docker Desktop laeuft.

1. Lokale Env erstellen:

```powershell
Copy-Item .env.local.example .env
```

2. In `.env` mindestens setzen:

```text
APP_URL=http://localhost:8080
APP_SUPER_ADMIN_EMAILS=deine.admin.mail@example.ch
APP_ADMIN_EMAILS=deine.admin.mail@example.ch
GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
ELVANTO_API_KEY=...
```

3. In Google Cloud beim OAuth-Client zusaetzlich eintragen:

```text
Autorisierte JavaScript-Quelle: http://localhost:8080
Autorisierte Weiterleitungs-URI: http://localhost:8080/api/auth/google-callback.php
```

4. Container starten:

```powershell
docker compose up --build
```

5. Browser oeffnen:

```text
http://localhost:8080
```

Die lokale MariaDB ist von Windows aus auf Port `3307` erreichbar. Das Schema wird beim ersten Start automatisch aus `database/schema.sql` importiert. Wenn die DB neu aufgebaut werden soll:

```powershell
docker compose down -v
docker compose up --build
```
