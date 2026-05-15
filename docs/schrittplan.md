# Schritt-fuer-Schritt Plan

## Phase 1: Fundament

Status: begonnen

1. Projektstruktur fuer Plesk anlegen.
2. PHP-Bootstrap, `.env`-Konfiguration und JSON-API-Helfer erstellen.
3. MySQL-Schema fuer die bisherigen Google-Sheet-Daten definieren.
4. API-Mapping fuer alle bisherigen `google.script.run` Funktionen erstellen.

Ergebnis: Die App hat eine technische Basis, die auf Metanet/Plesk lauffaehig ist.

## Phase 2: Datenbank statt Google Sheets

1. Datenbank in Plesk erstellen.
2. `database/schema.sql` in phpMyAdmin importieren.
3. Tabellen fuer Personen, Familien, Gruppen, Kalender, Services, Songs, Caches und Einstellungen anlegen.
4. Bestehende Google-Sheet-Exportstruktur auf Tabellen mappen.

Ergebnis: Die App kann Daten dauerhaft in MySQL speichern.

## Phase 3: Elvanto Import portieren

1. `personen.txt` nach PHP portieren.
2. `familien.txt` nach PHP portieren.
3. `gruppe.txt` nach PHP portieren.
4. `jahreskalender.txt` nach PHP portieren.
5. Import-Endpunkte fuer Admin-Buttons bereitstellen.
6. CLI-Scripts fuer Plesk-Cron erstellen.

Ergebnis: Daten kommen serverseitig direkt aus Elvanto in MySQL.

## Phase 4: App-API nachbauen

1. `app_ping` -> `/api/ping.php`.
2. `app_bootstrap` -> `/api/bootstrap.php`.
3. `app_loadContactsLite` -> `/api/contacts-lite.php`.
4. `personenUi_getMainDetails` -> `/api/person-main.php`.
5. `personenUi_getExtraDetails` -> `/api/person-extra.php`.
6. `personenUi_getFamily` -> `/api/family.php`.
7. `getCalendarEventsRange` -> `/api/calendar-events.php`.
8. Service-Detailfunktionen -> `/api/service-*.php`.
9. Exportfunktionen -> `/api/export-*.php`.

Ergebnis: Das Frontend kann ohne Google Apps Script arbeiten.

## Phase 5: Frontend umstellen

1. Aus `Head.txt`, `Body.txt`, `PersonenSidebar.txt` eine normale `index.html` bauen.
2. Apps-Script-Template-Syntax entfernen:
   - `<?!= include('Head'); ?>`
   - `<?!= include('Body'); ?>`
3. `withGsRetry(...)` so umbauen, dass es `fetch(...)` nutzt.
4. API-Antworten kompatibel halten, damit moeglichst wenig UI-Code geaendert werden muss.
5. PWA-Manifest und Service Worker optional nachziehen.

Ergebnis: Browser laedt die App direkt von Metanet.

## Phase 6: Login und Berechtigungen

1. Google-Session durch eigenes Login ersetzen.
2. Tabellen `users`, `roles`, `user_smart_filters` nutzen.
3. Admin-Mails aus `.env` als Erstzugang erlauben.
4. Rollen aus der bisherigen Logik uebernehmen:
   - guest
   - user
   - admin

Ergebnis: Geschuetzte Daten sind nicht oeffentlich abrufbar.

## Phase 7: Plesk Deployment

1. Domain/Subdomain in Plesk erstellen.
2. Dokumentenstamm auf `public/` setzen.
3. Datenbank erstellen.
4. `.env` ausserhalb oder neben dem App-Code ablegen.
5. Dateien per Git oder SFTP hochladen.
6. `/api/ping.php` testen.
7. Cronjobs fuer Sync einrichten.

Ergebnis: Die App laeuft produktiv.

## Phase 8: Abnahme

1. Kontakte laden.
2. Details oeffnen.
3. Familien/Gruppen pruefen.
4. Kalenderzeitraum pruefen.
5. Gottesdienstablauf und Mitarbeitende pruefen.
6. Exporte pruefen.
7. Mobile PWA auf iPhone/Android testen.

Ergebnis: Google Apps Script kann abgeloest werden.

