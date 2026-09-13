# Installatiehandleiding

Deze handleiding beschrijft een nieuwe installatie van de **Activiteitenweger API** op een Linux-server met Nginx, PHP-FPM en MySQL.

Bijbehorende app:

- GitHub: [basd82/Activiteitenweger](https://github.com/basd82/Activiteitenweger)
- API-repository: [basd82/Activiteitenweger-api](https://github.com/basd82/Activiteitenweger-api)

De huidige app verwacht de API onder:

```text
https://app.dikkenberg.net/api/v1
```

## 1. Vereisten

Benodigd:

- Linux;
- Git;
- Nginx;
- PHP 8.1 of nieuwer;
- PHP-FPM;
- PHP-extensies `mysqli` en `sodium`;
- MySQL/InnoDB;
- HTTPS aan de publieke kant.

Controle:

```bash
php -v
php -m | grep -E 'mysqli|sodium'
mysql --version
nginx -v
git --version
```

## 2. Repository installeren

De productie-layout gebruikt:

```text
/var/www/activiteitenweger
```

Clone de API:

```bash
cd /var/www
git clone git@github.com:basd82/Activiteitenweger-api.git activiteitenweger
cd /var/www/activiteitenweger
```

Bij een HTTPS-clone kan uiteraard ook de HTTPS-URL van GitHub worden gebruikt.

De relevante layout is daarna:

```text
/var/www/activiteitenweger/
├── database.php.example
├── public/
│   └── index.php
├── src/
├── sql/
│   └── install.sql
├── tools/
│   └── test-client.php
└── docs/
```

Alleen `public/` mag als webroot worden gebruikt.

## 3. Database aanmaken

Open MySQL met een beheerdersaccount:

```bash
mysql
```

Voorbeeld voor een nieuwe database en runtime-user:

```sql
CREATE DATABASE activiteitenweger
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_0900_ai_ci;

CREATE USER 'activiteitenweger'@'localhost'
  IDENTIFIED BY 'VUL_HIER_EEN_STERK_UNIEK_WACHTWOORD_IN';

GRANT SELECT, INSERT, UPDATE, DELETE
  ON activiteitenweger.*
  TO 'activiteitenweger'@'localhost';

FLUSH PRIVILEGES;
```

De runtime-user krijgt bewust geen schemawijzigingsrechten.

Importeer het schema met een account dat wél tabellen en constraints mag maken:

```bash
mysql activiteitenweger < sql/install.sql
```

Controleer daarna bijvoorbeeld:

```bash
mysql -e 'SHOW TABLES FROM activiteitenweger'
```

Verwacht onder andere:

```text
vaults
devices
key_epochs
device_key_envelopes
pairing_invites
records
sync_events
request_nonces
```

## 4. database.php maken

Maak de lokale configuratie vanuit het voorbeeld:

```bash
cd /var/www/activiteitenweger
cp database.php.example database.php
```

Pas daarna alleen `database.php` aan:

```php
<?php

$dbserver = 'localhost';
$dbuser = 'activiteitenweger';
$dbww = 'JOUW_ECHTE_DATABASE_WACHTWOORD';
$db = 'activiteitenweger';
```

Belangrijk:

- `database.php` staat in `.gitignore`;
- `database.php.example` bevat uitsluitend voorbeeldwaarden en blijft wel in Git;
- commit nooit het echte wachtwoord.

Aanbevolen rechten:

```bash
chown root:www-data /var/www/activiteitenweger/database.php
chmod 640 /var/www/activiteitenweger/database.php
```

Controleer voor de zekerheid:

```bash
git status --short
git check-ignore -v database.php
```

`database.php` hoort door `.gitignore` te worden gematcht.

## 5. Bestandsrechten

Voor een standaard Nginx/PHP-FPM-installatie met groep `www-data`:

```bash
cd /var/www/activiteitenweger

chown -R root:www-data public src

find public src -type d -exec chmod 750 {} \;
find public src -type f -exec chmod 640 {} \;

chmod 640 database.php
```

De SQL-, docs- en tools-directory hoeven niet via de webserver leesbaar te zijn zolang de webroot correct op `public/` staat.

## 6. PHP syntax controleren

Voer vóór de eerste webtest uit:

```bash
cd /var/www/activiteitenweger

find public src tools -name '*.php' -print0 \
  | xargs -0 -n1 php -l
```

Alle bestanden moeten eindigen met:

```text
No syntax errors detected
```

## 7. Nginx configureren

De document root moet exact zijn:

```text
/var/www/activiteitenweger/public
```

Een minimale serverconfiguratie voor een backend die HTTP ontvangt kan er als volgt uitzien:

```nginx
server {
    listen 80;
    server_name app.dikkenberg.net;

    root /var/www/activiteitenweger/public;
    index index.php;

    location / {
        try_files $uri /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php-fpm.sock;
    }

    location ~ /\. {
        deny all;
    }
}
```

De exacte PHP-FPM socket verschilt per distributie en PHP-versie. Controleer bijvoorbeeld:

```bash
ls -l /run/php/
```

Gebruik vervolgens de aanwezige socket, bijvoorbeeld:

```text
/run/php/php8.5-fpm.sock
```

Test en reload Nginx:

```bash
nginx -t
systemctl reload nginx
```

Als TLS op een externe load balancer of reverse proxy eindigt, kan Nginx intern HTTP blijven gebruiken. Publiek verkeer naar de API moet wel via HTTPS lopen.

## 8. Healthcheck

Test eerst lokaal op de webserver:

```bash
curl -sS -H 'Host: app.dikkenberg.net' \
  http://127.0.0.1/api/v1/health
```

Daarna via de publieke route:

```bash
curl -fsS https://app.dikkenberg.net/api/v1/health
```

Verwachte response:

```json
{
  "status": "ok",
  "database": "ok",
  "apiVersion": 1,
  "serverVersion": "1.1.0"
}
```

## 9. Functionele smoke-test

De meegeleverde PHP-testclient gebruikt echte Ed25519 request- en recordsignatures.

Start een volledige testflow:

```bash
cd /var/www/activiteitenweger

php tools/test-client.php create
php tools/test-client.php me
php tools/test-client.php devices
php tools/test-client.php add-record
php tools/test-client.php sync
php tools/test-client.php delete-vault
```

Tijdens de test kan dit bestand ontstaan:

```text
tools/test-state.json
```

Dat bestand bevat private testsleutels en een test-VaultKey. Het wordt door `.gitignore` uitgesloten en krijgt door de testclient mode `0600`.

Controle:

```bash
git check-ignore -v tools/test-state.json
```

Na een succesvolle `delete-vault` verwijdert de testclient het bestand zelf.

## 10. Git-beveiligingscontrole

Voer vóór commits regelmatig uit:

```bash
git status --short
git ls-files | grep -E '(^|/)(database\.php|test-state\.json)$' && \
  echo 'LET OP: geheim bestand staat tracked' || true
```

Controleer daarnaast dat geen private key-, certificaat-, dump- of backupbestanden zijn toegevoegd.

De `.gitignore` sluit onder andere uit:

- `/database.php`;
- `/tools/test-state.json`;
- `.env`-bestanden;
- private key/certificaatformaten;
- backup- en patchbestanden;
- database dumps;
- logs en tijdelijke bestanden;
- IDE- en macOS-metadata.

Let op: een bestand dat al eerder in Git is opgenomen, wordt niet vanzelf untracked door een nieuwe `.gitignore`-regel. Gebruik dan expliciet `git rm --cached <bestand>` of verwijder het uit de repository.

## 11. App koppelen

De mobiele/desktop client staat in:

[https://github.com/basd82/Activiteitenweger](https://github.com/basd82/Activiteitenweger)

De huidige app gebruikt:

```text
https://app.dikkenberg.net/api/v1
```

Na een groene healthcheck en smoke-test kan de app:

- een vault aanmaken;
- records end-to-end versleuteld schrijven;
- synchroniseren;
- records wijzigen/verwijderen;
- de volledige eigen vault verwijderen.

Pairing, multi-client grants, revoke en key rotation zijn nog vervolgstappen en staan beschreven in [ROADMAP.md](ROADMAP.md).

## 12. Bijwerken

Gebruik voor bestaande installaties de aparte [updatehandleiding](UPDATE.md).

Kort samengevat:

```bash
cd /var/www/activiteitenweger
git fetch --all --prune
git pull --ff-only
```

Controleer daarna `CHANGELOG.md` en `sql/migrations/` op databasepatches die bij de nieuwe release horen, voer de PHP syntaxcheck uit en controleer `/api/v1/health`.

Server 1.1.0 bevat geen database-schemawijziging en vereist geen SQL-migratie.

## 13. Niet in Git zetten

Nooit committen:

```text
database.php
tools/test-state.json
.env*
private keys
TLS private keys/certificaatbundels
productiedatabase-dumps
backups van productieconfiguratie
bestanden met echte VaultKeys
```

Zie ook:

- [Deployment](DEPLOYMENT.md)
- [Security](SECURITY.md)
- [Database](DATABASE.md)
- [API-protocol](API.md)
