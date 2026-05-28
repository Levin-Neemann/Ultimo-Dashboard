# Ultimo Dashboard

Live-Dashboard fuer Ultimo-Stoermeldungen mit Management- und Department-Sicht. Die Anwendung laeuft direkt unter Apache/Laragon und ruft die echte Ultimo REST API ueber lokale PHP-Endpunkte ab; es gibt keine Mockdaten.

## Start

1. `.env.example` nach `.env` kopieren.
2. `ULTIMO_API_KEY` in `.env` setzen.
3. Projekt in Laragon unter `C:\laragon\www` bereitstellen oder als Virtual Host auf dieses Verzeichnis zeigen lassen.
4. In Laragon Apache starten.
5. Danach im Browser die lokale URL des Projekts oeffnen, zum Beispiel `http://ultimo-dashboard.test/` oder `http://localhost/Ultimo%20Dashboard/`.

## API

Die Anwendung nutzt die in der OpenAPI-Dokumentation freigegebenen Endpunkte:

- `GET https://neemann.ultimo.net/api/v1/object/Job`
- `GET https://neemann.ultimo.net/api/v1/object/Department`
- `GET https://neemann.ultimo.net/api/v1/object/Priority`
- `GET https://neemann.ultimo.net/api/v1/object/ProgressStatus`
- weitere Stammdaten-Endpunkte fuer lesbare Labels wie Equipment, Standort, Mitarbeiter und Kostenstelle

Der API-Key wird serverseitig in `api/dashboard.php` als Header `ApiKey` gesendet und nicht an den Browser weitergegeben.

## Konfiguration

```env
ULTIMO_BASE_URL=https://neemann.ultimo.net/api/v1
ULTIMO_API_KEY=...
REFRESH_SECONDS=60
DASHBOARD_CACHE_SECONDS=300
LOOKUP_CACHE_SECONDS=86400
APP_TIMEZONE=Europe/Berlin
```

`DASHBOARD_CACHE_SECONDS` steuert, wie lange eine fertige Dashboard-Antwort pro Ansicht lokal wiederverwendet wird. Der Standard von 300 Sekunden reduziert die Ultimo-Job-Abfragen auf maximal 2 Requests alle 5 Minuten je Ansicht, egal wie viele TVs dieselbe Ansicht anzeigen.

`LOOKUP_CACHE_SECONDS` steuert den Cache fuer Stammdaten wie Abteilungen, Anlagen, Mitarbeiter und Prioritaeten. Der Standard von 86400 Sekunden aktualisiert diese Daten einmal taeglich.

## Struktur

- `index.php`, `app.js`, `styles.css`: Frontend fuer die TV-Ansicht
- `admin.php`, `admin.js`, `admin.css`: Layout-Editor fuer Dashboard-Ansichten
- `api/config.php`: liefert Frontend-Konfiguration
- `api/dashboard.php`: liefert Dashboard-Daten und nutzt einen serverseitigen Snapshot-Cache gegen zu viele Ultimo-Requests
- `api/layouts.php`: liest und speichert Dashboard-Layouts
- `api/bootstrap.php`: gemeinsame PHP-Helfer fuer API, Auswertung und Caching
- `config/department-groups.php`: buendelt mehrere Ultimo-Abteilungen zu internen Dashboard-Bereichen

In der Abteilungsauswahl kann entweder eine einzelne Ultimo-Abteilung oder ein interner Bereich ausgewaehlt werden. Ein Bereich wie `Druck` kann mehrere Ultimo-Abteilungen enthalten, zum Beispiel `Druckanlagen` und `Druckerei`; der Server baut daraus automatisch einen Ultimo-Filter mit mehreren Department-IDs.

## Layouts

Dashboard-Layouts werden in `storage/layouts/dashboard-layouts.json` gespeichert. Das Verzeichnis muss fuer den Apache/PHP-Benutzer beschreibbar sein, auf Ubuntu typischerweise `www-data`.

Unter `admin.php` koennen Abteilungen eigene Ansichten anlegen. Pro Ansicht werden Name, URL-ID, Abteilungs-/Bereichsfilter, Aktualisierungsintervall, sichtbare Widgets, Reihenfolge und Widget-Breite gespeichert. Der Editor bietet ausserdem eine direkte Vorschau und kopiert den Link zur gewaehlten Dashboard-Ansicht.

Als Anzeigeoptionen stehen neben Kennzahlen, kritischen und letzten Meldungen auch Gruppierungen nach Abteilung, Prioritaet, Status, Standort, Anlage/Maschine, Kostenstelle, Faehigkeit, Auftragstyp, Fehlerart, Mitarbeiter und Dienstleister zur Auswahl. Die benoetigten Ultimo-Felder werden in der Job-Abfrage selektiert und die passenden Stammdaten ueber die Lookup-Endpunkte geladen.

Die Ansicht kann direkt per URL geoeffnet werden:

```text
http://server-ip/?view=it
http://server-ip/?view=druck
```

Der IT-Filter ist als virtuelle Ansicht vorbereitet und nutzt `SkillCategory=IT`, damit dafuer keine eigene Ultimo-Abteilung angelegt werden muss.

## Standortlogik

Fuer die Standortanzeige wird zuerst der genaue Ultimo-Raum (`Space`) genutzt. Wenn dort eine Raumnummer gepflegt ist, wird sie an die Beschreibung angehaengt. Ist kein Raum hinterlegt, nutzt das Dashboard die Prozessfunktion als Maschinenstandort. Nur wenn beides fehlt, wird der allgemeine Ultimo-Standort (`Site`) angezeigt.

## Hinweis

Der Schluessel `1CA4D0B384DE4969BE2018AE8F94DA49` aus dem Doku-Link ist nur fuer die Dokumentation, nicht fuer echte Datenabfragen. Fuer Live-Daten brauchst du einen gueltigen Ultimo-API-Key aus eurem System.
