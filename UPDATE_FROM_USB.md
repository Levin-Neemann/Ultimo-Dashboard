# Update vom USB-Stick auf den Ubuntu-Apache-Server

Diese Anleitung ist fuer den Fall gedacht, dass Apache bereits laeuft und eine alte Version des Dashboards schon auf dem Server liegt. Ziel: neue Dateien vom USB-Stick kopieren, aber Server-Konfiguration, `.env`, Cache und gespeicherte Layouts behalten.

## 1. USB-Stick finden

USB-Stick einstecken und auf dem Server anzeigen lassen:

```bash
lsblk
```

Typische Mount-Pfade sind zum Beispiel:

```text
/media/dein-user/USB-STICK-NAME
/mnt/usb
```

Falls der Stick nicht automatisch gemountet wurde:

```bash
sudo mkdir -p /mnt/usb
sudo mount /dev/sdX1 /mnt/usb
```

`/dev/sdX1` durch das passende Geraet aus `lsblk` ersetzen.

## 2. Projektpfade pruefen

Server-Zielpfad der bestehenden Apache-Seite, zum Beispiel:

```bash
cd /var/www/ultimo-dashboard
pwd
ls
```

Auf dem USB-Stick sollte der Projektordner Dateien wie diese enthalten:

```bash
ls /media/dein-user/USB-STICK-NAME/Ultimo\ Dashboard
```

Erwartete Dateien:

```text
index.php
admin.php
app.js
admin.js
styles.css
admin.css
api/
config/
```

## 3. Backup der alten Version erstellen

Vor dem Kopieren:

```bash
sudo tar -czf /var/backups/ultimo-dashboard-$(date +%Y%m%d-%H%M).tar.gz /var/www/ultimo-dashboard
```

## 4. Neue Dateien kopieren

Empfohlen mit `rsync`. Wichtig: `.env`, `storage/cache` und `storage/layouts` werden nicht vom USB-Stick ueberschrieben.

```bash
sudo rsync -av \
  --exclude ".env" \
  --exclude "storage/cache/" \
  --exclude "storage/layouts/" \
  "/media/dein-user/USB-STICK-NAME/Ultimo Dashboard/" \
  /var/www/ultimo-dashboard/
```

Wenn der USB-Stick unter `/mnt/usb` liegt:

```bash
sudo rsync -av \
  --exclude ".env" \
  --exclude "storage/cache/" \
  --exclude "storage/layouts/" \
  "/mnt/usb/Ultimo Dashboard/" \
  /var/www/ultimo-dashboard/
```

## 5. Rechte setzen

Nach dem Kopieren:

```bash
sudo chown -R www-data:www-data /var/www/ultimo-dashboard/storage
sudo find /var/www/ultimo-dashboard/storage -type d -exec chmod 775 {} \;
sudo find /var/www/ultimo-dashboard/storage -type f -exec chmod 664 {} \;
```

Falls die Projektdateien selbst einem Admin-Benutzer gehoeren sollen:

```bash
sudo chown -R root:root /var/www/ultimo-dashboard
sudo chown -R www-data:www-data /var/www/ultimo-dashboard/storage
```

## 6. Apache neu laden

```bash
sudo apache2ctl configtest
sudo systemctl reload apache2
```

## 7. Pruefen

Im Browser:

```text
http://server-name-oder-ip/
http://server-name-oder-ip/admin.php
```

Auf dem Server:

```bash
curl http://localhost/api/config.php
curl http://localhost/api/layouts.php
```

`admin.php` ist der neue Layout Editor. Wenn er angezeigt wird, ist der Editor auf dem Server angekommen.

## 8. Layout Editor nutzen

1. `http://server-name-oder-ip/admin.php` oeffnen.
2. Bestehende Ansicht waehlen oder `Neu` klicken.
3. Name, ID, Filter und Aktualisierung setzen.
4. Widgets ein-/ausblenden, sortieren und Groesse waehlen.
5. `Speichern` klicken.
6. Mit `Vorschau` die Ansicht testen.

Nicht mehr benoetigte Ansichten koennen im Editor mit `Loeschen` entfernt werden. Mindestens eine Ansicht bleibt erhalten.

Die gespeicherten Layouts landen auf dem Server in:

```text
/var/www/ultimo-dashboard/storage/layouts/dashboard-layouts.json
```

## Fehleranalyse

Apache-Fehlerlog:

```bash
sudo tail -f /var/log/apache2/error.log
```

Falls ein eigener Virtual Host Logfiles hat:

```bash
sudo tail -f /var/log/apache2/ultimo-dashboard-error.log
```

Haeufige Probleme:

- `admin.php` zeigt 404: Die neue Datei wurde nicht in den Apache-DocumentRoot kopiert.
- Speichern meldet Rechtefehler: `storage/layouts` ist nicht fuer `www-data` beschreibbar.
- Dashboard zeigt API-Key-Fehler: `.env` wurde nicht gefunden oder nicht korrekt gelesen.
- Alte Seite bleibt sichtbar: Browser-Cache leeren oder pruefen, ob Apache wirklich auf denselben Ordner zeigt.
