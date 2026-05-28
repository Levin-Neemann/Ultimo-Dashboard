# Ultimo Dashboard

Ein schlankes Live-Dashboard fuer Ultimo-Stoermeldungen. Es zeigt offene,
kritische, ueberfaellige und neu eingegangene Meldungen als TV- oder
Browser-Ansicht und bietet eine kleine Admin-Oberflaeche, um Ansichten fuer
verschiedene Abteilungen zusammenzustellen.

Das Projekt ist bewusst einfach gehalten: PHP liefert die Daten aus der Ultimo
REST API, JavaScript rendert die Oberflaeche, und Layouts sowie Cache-Dateien
liegen lokal im `storage`-Verzeichnis.

## Was kann das Dashboard?

- Live-Uebersicht ueber Ultimo-Jobs, Prioritaeten, Status, Standorte und Anlagen
- vorgefertigte Ansichten fuer Management, Druck, Konfektion und IT
- eigene Dashboard-Layouts ueber `admin.php`
- Filter nach einzelner Ultimo-Abteilung, interner Abteilungsgruppe oder Skill
- lokale Caches, damit mehrere TV-Clients nicht staendig die Ultimo API belasten
- passwortgeschuetzte Admin-Tools fuer Cache-Status, aktive Clients und Cache-Reset

## Schnellstart

### Voraussetzungen

- PHP 8.1 oder neuer
- Apache, Laragon, XAMPP oder ein vergleichbarer lokaler Webserver
- ein gueltiger Ultimo API-Key fuer euer Ultimo-System

### Lokal starten

1. Repository in ein Webserver-Verzeichnis legen, zum Beispiel:

   ```text
   C:\laragon\www\Ultimo Dashboard
   ```

2. Im Projektordner eine Datei `.env` anlegen:

   ```env
   ULTIMO_BASE_URL=https://neemann.ultimo.net/api/v1
   ULTIMO_API_KEY=dein-api-key
   REFRESH_SECONDS=60
   DASHBOARD_CACHE_SECONDS=300
   LOOKUP_CACHE_SECONDS=86400
   APP_TIMEZONE=Europe/Berlin
   ADMIN_PASSWORD=ein-sicheres-admin-passwort
   ```

3. Apache starten.

4. Dashboard im Browser oeffnen:

   ```text
   http://localhost/Ultimo%20Dashboard/
   ```

   Je nach Laragon- oder Virtual-Host-Setup kann die URL auch so aussehen:

   ```text
   http://ultimo-dashboard.test/
   ```

5. Admin-Oberflaeche oeffnen:

   ```text
   http://localhost/Ultimo%20Dashboard/admin.php
   ```

## Wichtige URLs

| URL | Zweck |
| --- | --- |
| `/` | Hauptdashboard |
| `/?view=management` | Management-Ansicht |
| `/?view=druck` | Druck-Ansicht |
| `/?view=konfektion` | Konfektion-Ansicht |
| `/?view=it` | IT-Ansicht |
| `/admin.php` | Layout-Editor und Admin-Tools |
| `/api/config.php` | Frontend-Konfiguration |
| `/api/dashboard.php` | Dashboard-Daten als JSON |
| `/api/layouts.php` | gespeicherte Layouts und Widget-Definitionen |

## Konfiguration

Die Anwendung liest ihre Einstellungen aus `.env`. Diese Datei gehoert nicht ins
Git-Repository, weil sie API-Keys und Passwoerter enthalten kann.

| Variable | Bedeutung |
| --- | --- |
| `ULTIMO_BASE_URL` | Basis-URL der Ultimo REST API |
| `ULTIMO_API_KEY` | API-Key fuer Live-Daten aus Ultimo |
| `REFRESH_SECONDS` | Standard-Aktualisierung der Dashboard-Ansicht |
| `DASHBOARD_CACHE_SECONDS` | Cache-Dauer fuer fertige Dashboard-Antworten |
| `LOOKUP_CACHE_SECONDS` | Cache-Dauer fuer Stammdaten wie Abteilungen, Anlagen und Prioritaeten |
| `APP_TIMEZONE` | Zeitzone fuer Datums- und Zeitberechnungen |
| `ADMIN_PASSWORD` | Passwort fuer die Admin-Tools in `admin.php` |

Der API-Key wird nur serverseitig verwendet. Er wird in PHP als `ApiKey`-Header
an Ultimo gesendet und nicht an den Browser ausgeliefert.

## Projektstruktur

```text
.
|-- index.php                  # Hauptdashboard
|-- app.js                     # Frontend-Logik fuer die Dashboard-Ansicht
|-- styles.css                 # Styling der Dashboard-Ansicht
|-- admin.php                  # Layout-Editor und Admin-Tools
|-- admin.js                   # Frontend-Logik fuer die Admin-Oberflaeche
|-- admin.css                  # Styling der Admin-Oberflaeche
|-- api/
|   |-- bootstrap.php          # gemeinsame PHP-Helfer, Caching, Ultimo-Aufrufe
|   |-- config.php             # sichere Frontend-Konfiguration
|   |-- dashboard.php          # Dashboard-Daten aus Ultimo
|   |-- layouts.php            # Layouts lesen, speichern und loeschen
|   |-- admin-actions.php      # Cache- und Admin-Aktionen
|   `-- clients.php            # aktive Dashboard-Clients
|-- config/
|   `-- department-groups.php  # interne Gruppen wie Druck und Konfektion
`-- storage/
    |-- cache/                 # lokale Cache-Dateien, nicht versioniert
    `-- layouts/               # gespeicherte Dashboard-Layouts, nicht versioniert
```

## Layouts bearbeiten

Layouts werden ueber `admin.php` gepflegt. Dort koennen neue Ansichten angelegt
und bestehende Ansichten angepasst werden.

Pro Ansicht werden gespeichert:

- Name und URL-ID
- Filter fuer Gesamtuebersicht, Abteilung, interne Gruppe oder Skill
- Aktualisierungsintervall
- sichtbare Widgets
- Reihenfolge und Breite der Widgets

Wenn noch keine Layout-Datei vorhanden ist, nutzt das Dashboard automatisch die
Standardansichten `management`, `druck`, `konfektion` und `it`.

## Abteilungsgruppen

Interne Dashboard-Bereiche werden in `config/department-groups.php` definiert.
Eine Gruppe kann mehrere Ultimo-Abteilungen enthalten. Beispiel:

```php
[
    'id' => 'druck',
    'name' => 'Druck',
    'departments' => [
        'Druckanlagen',
        'Druckerei',
    ],
]
```

Der Server loest diese Namen gegen die Ultimo-Stammdaten auf und baut daraus den
passenden API-Filter.

## Datenquellen

Das Dashboard arbeitet mit echten Ultimo-Daten. Die wichtigsten Endpunkte sind:

- `GET /object/Job`
- `GET /object/Department`
- `GET /object/Priority`
- `GET /object/ProgressStatus`
- weitere Lookup-Endpunkte fuer Equipment, Standort, Mitarbeiter, Kostenstelle,
  Auftragstyp, Fehlerart, Skill und Dienstleister

Es gibt keine Mockdaten. Ohne gueltigen `ULTIMO_API_KEY` kann das Dashboard zwar
geladen werden, aber keine Live-Daten anzeigen.

## Betrieb und Deployment

Fuer Linux/Apache gibt es eine ausfuehrlichere Anleitung in
`DEPLOYMENT_UBUNTU_APACHE.md`.

Wichtig fuer produktive Installationen:

- `storage/cache` muss fuer den Webserver-Benutzer beschreibbar sein
- `storage/layouts` muss fuer den Webserver-Benutzer beschreibbar sein
- `.env` darf nicht oeffentlich auslieferbar sein
- `.env`, Cache-Dateien und Layout-Dateien sind absichtlich in `.gitignore`
  ausgeschlossen

## Troubleshooting

| Problem | Moegliche Ursache |
| --- | --- |
| Dashboard zeigt keine Daten | `ULTIMO_API_KEY` fehlt, ist falsch oder hat keine Rechte |
| Admin-Tools lassen sich nicht entsperren | `ADMIN_PASSWORD` fehlt oder ist anders gesetzt als erwartet |
| Layouts werden nicht gespeichert | `storage/layouts` ist nicht beschreibbar |
| Daten wirken veraltet | Dashboard- oder Lookup-Cache ist noch gueltig |
| Abteilungsfilter greift nicht | Name in `department-groups.php` passt nicht zu den Ultimo-Stammdaten |

## Sicherheitshinweis

API-Keys, Passwoerter und echte `.env`-Dateien gehoeren nicht ins Repository.
Falls versehentlich ein Key committed wurde, sollte er in Ultimo rotiert und aus
der Git-Historie entfernt werden.
