# Plesk Checkliste

## 1. Domain vorbereiten

1. In Plesk eine Domain oder Subdomain anlegen, z. B. `app.clzspiez.ch`.
2. Hosting aktivieren.
3. PHP-Unterstuetzung aktivieren.
4. Dokumentenstamm auf den Ordner `public` setzen.
5. Let's Encrypt SSL aktivieren.
6. HTTPS-Weiterleitung aktivieren.

## 2. Datenbank anlegen

1. Plesk -> Datenbanken -> Datenbank hinzufuegen.
2. Datenbankname z. B. `clz_app`.
3. Datenbank-Benutzer erstellen.
4. Starkes Passwort speichern.
5. phpMyAdmin oeffnen.
6. `database/schema.sql` importieren.

## 3. Dateien hochladen

Empfohlen: Git-Deployment in Plesk.

Alternative: SFTP/FTP.

Beim Upload muss der Inhalt von `metanet-migration` auf dem Hosting liegen. Der oeffentliche Dokumentenstamm zeigt auf:

```text
metanet-migration/public
```

## 4. Konfiguration

Aus `.env.example` eine Datei `.env` erstellen:

```text
APP_ENV=production
APP_DEBUG=0
APP_TIMEZONE=Europe/Zurich

DB_HOST=localhost
DB_NAME=...
DB_USER=...
DB_PASS=...

ELVANTO_API_KEY=...
ELVANTO_SUBDOMAIN=clz
APP_ADMIN_EMAILS=...
APP_SESSION_DAYS=30
ADMIN_IMPORT_TOKEN=...
```

Der Elvanto API-Key gehoert nur auf den Server, nie ins Frontend.
Der `ADMIN_IMPORT_TOKEN` schuetzt Admin-Import-Endpunkte. Er sollte lang und zufaellig sein.
`APP_SESSION_DAYS` legt fest, wie lange ein Login gueltig bleibt; die App erneuert die Session bei API-Nutzung automatisch.

## 5. Erster Test

Im Browser aufrufen:

```text
https://deine-domain.ch/api/ping.php
```

Erwartete Antwort:

```json
{"ok":true,"service":"clz-app"}
```

Danach:

```text
https://deine-domain.ch/api/db-check.php
```

Erwartete Antwort:

```json
{"ok":true,"database":"connected"}
```

## 6. Cronjobs

Auf deinem Hosting ist das App-Verzeichnis:

```text
/home/httpd/vhosts/ypg.ch/app.clzspiez.ch
```

Plesk -> Geplante Aufgaben -> Befehl ausfuehren:

```text
cd /home/httpd/vhosts/ypg.ch/app.clzspiez.ch && /opt/plesk/php/8.2/bin/php scripts/import_people.php
cd /home/httpd/vhosts/ypg.ch/app.clzspiez.ch && /opt/plesk/php/8.2/bin/php scripts/import_families.php
cd /home/httpd/vhosts/ypg.ch/app.clzspiez.ch && /opt/plesk/php/8.2/bin/php scripts/import_groups.php
cd /home/httpd/vhosts/ypg.ch/app.clzspiez.ch && /opt/plesk/php/8.2/bin/php scripts/import_calendar.php
cd /home/httpd/vhosts/ypg.ch/app.clzspiez.ch && /opt/plesk/php/8.2/bin/php scripts/import_service_details.php
cd /home/httpd/vhosts/ypg.ch/app.clzspiez.ch && /opt/plesk/php/8.2/bin/php scripts/import_songs.php
```

Admin-Import per Browser/API:

```text
https://deine-domain.ch/api/admin/import-people.php?token=DEIN_ADMIN_IMPORT_TOKEN
https://deine-domain.ch/api/admin/import-songs.php?token=DEIN_ADMIN_IMPORT_TOKEN
```

Empfohlener Rhythmus:

- Personen: nachts oder manuell
- Kalender/Services: alle 15-60 Minuten oder nachts plus Admin-Button
- Songs: nachts oder manuell nach Aenderungen in Elvanto
- Cache-Rebuild: nach jedem Import

## 7. Abnahme

1. Kontakte laden.
2. Suche testen.
3. Personendetail testen.
4. Familie und Gruppe testen.
5. Kalender laden.
6. Gottesdienst-Details, Mitarbeitende und Ablauf testen.
7. CSV/PDF/Druckansichten testen.
8. Mobile Home-Screen/PWA testen.
