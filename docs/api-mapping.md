# API-Mapping von Apps Script nach PHP

Die bestehenden Frontend-Aufrufe laufen weiter ueber `withGsRetry(fnName, args)`, aber nur noch als lokaler JavaScript-Adapter.
`withGsRetry` nutzt `fetch(...)` gegen PHP-Endpunkte. Es gibt kein `google.script.run` und kein Google Apps Script mehr im Browser.

## Kern

| Apps Script Funktion | PHP-Endpunkt | Status |
| --- | --- | --- |
| `app_ping` | `/api/ping.php` | angelegt |
| `app_bootstrap` | `/api/rpc.php` | implementiert |
| `app_loadFilterDefs` | `/api/filter-defs.php` | geplant |
| `app_loadDashboardStats` | `/api/rpc.php` | implementiert, eigener Endpunkt spaeter |
| `app_loadContactsLite` | `/api/contacts-lite.php` | geplant |
| `app_loadSongsLite` | `/api/rpc.php` | implementiert, Datenquelle `songs` |
| `app_getUserAccessLight` | `/api/user-access.php` | geplant |

## Kontakte

| Apps Script Funktion | PHP-Endpunkt | Datenquelle |
| --- | --- | --- |
| `personenUi_getMainDetails` | `/api/person-main.php` | `people` |
| `personenUi_getExtraDetails` | `/api/person-extra.php` | `people_extra_fields` |
| `personenUi_getFullDetails` | `/api/person-full.php` | `people`, `families`, `groups` |
| `personenUi_getFamily` | `/api/family.php` | `families`, `family_members` |
| `personenUi_getGroupByName` | `/api/group-by-name.php` | `groups`, `group_members` |
| `personenUi_extendedSearch` | `/api/contacts-search.php` | `people` |

## Kalender und Services

| Apps Script Funktion | PHP-Endpunkt | Datenquelle |
| --- | --- | --- |
| `getCalendarEventsRange` | `/api/calendar-events.php` | `calendar_events` |
| `kalenderUi_getServiceDetails` | `/api/service-details.php` | `services`, `service_*` |
| `kalenderUi_getServiceOverview` | `/api/service-overview.php` | `services` |
| `kalenderUi_getServiceStaff` | `/api/service-staff.php` | `service_volunteers` |
| `kalenderUi_getServiceFlow` | `/api/service-flow.php` | `service_plan_items` |
| `kalenderUi_refreshServiceDetails` | `/api/admin/import-service.php` | Elvanto API |
| `kalenderUi_getPersonServiceAssignments` | `/api/person-service-assignments.php` | `service_volunteers` |

## Tools/Admin

| Apps Script Funktion | PHP-Endpunkt | Zweck |
| --- | --- | --- |
| `tools_getSyncStatus` | `/api/admin/sync-status.php` | Importstatus |
| `tools_importPersonen` | `/api/admin/import-people.php` | Personenimport |
| `tools_importKalender` | `/api/admin/import-calendar.php` | Kalenderimport |
| `tools_importSongs` | `/api/admin/import-songs.php` und `/api/rpc.php` | Songimport |
| `tools_rebuildServerCaches` | `/api/admin/rebuild-cache.php` | Cache neu aufbauen |
| `tools_getCacheDiagnostics` | `/api/admin/cache-diagnostics.php` | Diagnose |
| `tools_loadUserSmartFilters` | `/api/rpc.php` | Smart-Filter laden |
| `tools_saveUserSmartFilters` | `/api/rpc.php` | Smart-Filter speichern |

## Prayer / Gebetsansicht

| Apps Script Funktion | PHP-Endpunkt | Datenquelle |
| --- | --- | --- |
| `personenUi_getPrayerDeck` | `/api/rpc.php` | `people` |
| `prayerDeck_getByPool` | `/api/rpc.php` | `prayer_pools`, `prayer_pool_members`, `people` |
| `prayer_startSession` | `/api/rpc.php` | `prayer_sessions` |
| `prayer_heartbeat` | `/api/rpc.php` | `prayer_sessions` |
| `prayer_endSession` | `/api/rpc.php` | `prayer_sessions`, `prayer_points` |
| `prayer_getLeaderboard` | `/api/rpc.php` | `prayer_points` |
| `prayerPools_get` | `/api/rpc.php` | `prayer_pools` |
| `prayerPools_getMembers` | `/api/rpc.php` | `prayer_pool_members` |
| `prayerPools_create` | `/api/rpc.php` | `prayer_pools` |
| `prayerPools_delete` | `/api/rpc.php` | `prayer_pools` |
| `prayerPools_addMembers` | `/api/rpc.php` | `prayer_pool_members` |
| `prayerPools_removeMembers` | `/api/rpc.php` | `prayer_pool_members` |

## Frontend-Adapter

Die Uebergangsstrategie ist, `withGsRetry` im Frontend intern umzubauen. Direkte Endpunkte werden bevorzugt; alte Funktionsnamen, deren Rueckgabeform noch nicht als eigener Endpunkt existiert, laufen temporaer ueber `/api/rpc.php`.

```js
const RPC_MAP = {
  app_ping: '/api/ping.php',
  app_bootstrap: '/api/rpc.php',
  app_loadContactsLite: '/api/rpc.php'
};

async function withGsRetry(fnName, args = [], options = {}) {
  const url = RPC_MAP[fnName] || `/api/rpc.php?fn=${encodeURIComponent(fnName)}`;
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'include',
    body: JSON.stringify({ args })
  });
  const data = await res.json();
  if (!res.ok || data?.ok === false) throw new Error(data?.error || 'API Fehler');
  return data;
}
```
