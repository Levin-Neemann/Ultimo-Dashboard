# Deployment auf Ubuntu mit Apache

Diese Anleitung beschreibt ein einfaches Hosting des Ultimo Dashboards auf einem Ubuntu-Apache-Webserver.

## 1. Pakete installieren

Auf dem Server:

```bash
sudo apt update
sudo apt install apache2 php libapache2-mod-php php-curl php-mbstring unzip rsync
sudo a2enmod rewrite
sudo systemctl restart apache2
```

PHP 8.1 oder neuer ist empfohlen.

## 2. Projekt kopieren

Beispielpfad auf dem Server:

```bash
sudo mkdir -p /var/www/ultimo-dashboard
sudo chown -R "$USER":www-data /var/www/ultimo-dashboard
```

Projekt vom lokalen Rechner auf den Server kopieren, zum Beispiel:

```bash
rsync -av --delete \
  --exclude ".env" \
  --exclude "storage/cache/" \
  --exclude "storage/layouts/" \
  ./ user@server:/var/www/ultimo-dashboard/
```

Alternativ kann das Projekt per Git, SCP oder SFTP in `/var/www/ultimo-dashboard` abgelegt werden.

## 3. `.env` anlegen

Auf dem Server im Projektverzeichnis:

```bash
sudo nano /var/www/ultimo-dashboard/.env
```

Beispiel:

```env
ULTIMO_BASE_URL=https://neemann.ultimo.net/api/v1
ULTIMO_API_KEY=dein-echter-api-key
REFRESH_SECONDS=60
DASHBOARD_CACHE_SECONDS=300
LOOKUP_CACHE_SECONDS=86400
APP_TIMEZONE=Europe/Berlin
```

## 4. Schreibrechte setzen

Das Dashboard schreibt Cache- und Layout-Dateien nach `storage/`.
Mit `DASHBOARD_CACHE_SECONDS=300` fragt jede Dashboard-Ansicht Ultimo maximal alle 5 Minuten neu ab, auch wenn mehrere TVs dieselbe Ansicht anzeigen. `LOOKUP_CACHE_SECONDS=86400` aktualisiert Stammdaten einmal pro Tag.

```bash
sudo mkdir -p /var/www/ultimo-dashboard/storage/cache
sudo mkdir -p /var/www/ultimo-dashboard/storage/layouts
sudo chown -R www-data:www-data /var/www/ultimo-dashboard/storage
sudo find /var/www/ultimo-dashboard/storage -type d -exec chmod 775 {} \;
sudo find /var/www/ultimo-dashboard/storage -type f -exec chmod 664 {} \;
```

## 5. Apache Virtual Host einrichten

Neue Datei anlegen:

```bash
sudo nano /etc/apache2/sites-available/ultimo-dashboard.conf
```

Inhalt, Domain/IP bitte anpassen:

```apache
<VirtualHost *:80>
    ServerName dashboard.example.local
    DocumentRoot /var/www/ultimo-dashboard

    <Directory /var/www/ultimo-dashboard>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/ultimo-dashboard-error.log
    CustomLog ${APACHE_LOG_DIR}/ultimo-dashboard-access.log combined
</VirtualHost>
```

Site aktivieren und Apache neu laden:

```bash
sudo a2ensite ultimo-dashboard.conf
sudo apache2ctl configtest
sudo systemctl reload apache2
```

Falls die Standardseite stört:

```bash
sudo a2dissite 000-default.conf
sudo systemctl reload apache2
```

## 6. Firewall freigeben

Falls UFW aktiv ist:

```bash
sudo ufw allow "Apache Full"
sudo ufw status
```

## 7. Testen

Im Browser öffnen:

```text
http://dashboard.example.local/
http://dashboard.example.local/admin.php
```

Direkte Tests auf dem Server:

```bash
curl http://localhost/api/config.php
curl http://localhost/api/layouts.php
```

Wenn `hasApiKey` in `api/config.php` false ist, liegt die `.env` nicht korrekt oder Apache/PHP kann sie nicht lesen.

## 8. HTTPS aktivieren

Wenn der Server öffentlich oder im Firmennetz mit Zertifikat genutzt wird, HTTPS aktivieren. Mit Let's Encrypt zum Beispiel:

```bash
sudo apt install certbot python3-certbot-apache
sudo certbot --apache -d dashboard.example.local
```

Bei internen Domains kann stattdessen ein internes Firmenzertifikat im Virtual Host hinterlegt werden.

## 9. Updates einspielen

Neue Dateien ins Projekt kopieren und danach Rechte prüfen:

```bash
sudo chown -R www-data:www-data /var/www/ultimo-dashboard/storage
sudo systemctl reload apache2
```

Die Dateien in `storage/cache/` und `storage/layouts/` sollten bei Updates normalerweise erhalten bleiben, weil dort Cache und gespeicherte Layouts liegen.

## Fehleranalyse

Apache-Logs:

```bash
sudo tail -f /var/log/apache2/ultimo-dashboard-error.log
sudo tail -f /var/log/apache2/ultimo-dashboard-access.log
```

PHP-Syntax prüfen:

```bash
php -l /var/www/ultimo-dashboard/api/bootstrap.php
php -l /var/www/ultimo-dashboard/api/layouts.php
```

Typische Ursachen:

- `.env` fehlt oder enthält keinen `ULTIMO_API_KEY`.
- `storage/cache` oder `storage/layouts` ist nicht für `www-data` beschreibbar.
- `AllowOverride All` fehlt, dadurch greift `.htaccess` nicht.
- `php-curl` fehlt, dadurch können Ultimo-API-Aufrufe fehlschlagen.
