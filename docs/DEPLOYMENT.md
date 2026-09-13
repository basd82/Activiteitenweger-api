# Deployment

Deze handleiding beschrijft de huidige eenvoudige PHP/MySQL-deployment van de Activiteitenweger API.

## Aanbevolen layout

```text
/var/www/activiteitenweger/
├── database.php
├── public/
│   └── index.php
├── src/
│   ├── auth.php
│   ├── common.php
│   ├── db.php
│   └── handlers.php
├── sql/
│   ├── install.sql
│   └── migrations/
└── tools/
    └── test-client.php
```

De webserver-root moet zijn:

```text
/var/www/activiteitenweger/public
```

Daardoor zijn `database.php`, bronbestanden, SQL en testtools niet rechtstreeks via HTTP bereikbaar.

## Vereisten

Controleer minimaal:

```bash
php -v
php -m | grep -E 'mysqli|sodium'
mysql --version
```

De applicatie vereist:

- PHP 8.1+;
- `mysqli`;
- `sodium`;
- MySQL/InnoDB;
- HTTPS voor publiek verkeer.

## Databaseconfiguratie

Maak buiten de webroot:

```text
/var/www/activiteitenweger/database.php
```

op basis van `database.php.example`.

Voorbeeld:

```php
<?php
$dbserver = 'localhost';
$dbuser = 'activiteitenweger';
$dbww = 'sterk-uniek-wachtwoord';
$db = 'activiteitenweger';
```

Aanbevolen bestandsrechten:

```bash
chown root:www-data /var/www/activiteitenweger/database.php
chmod 640 /var/www/activiteitenweger/database.php
```

Commit dit bestand nooit.

## Nieuwe database

Voor een lege database:

```bash
mysql activiteitenweger < sql/install.sql
```

Controleer daarna de tabellen en foreign keys voordat de API publiek wordt gebruikt.

## Bestaande database

Nieuwe schemawijzigingen staan als genummerde patches onder `sql/migrations/`. Voer alleen migraties uit die bij de nieuwe release horen en nog niet op de betreffende database zijn toegepast.

De historische rootfile `migration.sql` voegt alleen de request-nonce tabel toe aan een oudere installatie en wordt niet gebruikt als algemeen migratieframework.

Voer migraties altijd eerst uit op een backup/testomgeving. Zie [UPDATE.md](UPDATE.md) en [DATABASE.md](DATABASE.md).

## Bestandsrechten

Voorbeeld voor een deployment waarbij de webservergroep `www-data` is:

```bash
chown -R root:www-data /var/www/activiteitenweger/src /var/www/activiteitenweger/public

find /var/www/activiteitenweger/src /var/www/activiteitenweger/public \
  -type d -exec chmod 750 {} \;

find /var/www/activiteitenweger/src /var/www/activiteitenweger/public \
  -type f -exec chmod 640 {} \;
```

Pas eigenaar/groep aan wanneer jouw PHP-FPM/webserver anders draait.

## PHP syntaxcheck

Voor deploy:

```bash
find /var/www/activiteitenweger -name '*.php' -print0 \
  | xargs -0 -n1 php -l
```

## Webserver

De applicatie heeft één front controller:

```text
public/index.php
```

De webserver moet requests onder `/api/v1/` naar dit bestand laten lopen.

De TLS-terminatie mag voor een reverse proxy of load balancer plaatsvinden, mits publiek verkeer uitsluitend via HTTPS wordt aangeboden.

## Healthcheck

Na deployment:

```bash
curl -fsS https://app.dikkenberg.net/api/v1/health
```

Verwacht:

```json
{
  "status": "ok",
  "database": "ok",
  "apiVersion": 1,
  "serverVersion": "1.1.0"
}
```

Een databaseprobleem geeft een `503`.

## CLI smoke test

De testclient ondersteunt de huidige basisflow:

```bash
cd /var/www/activiteitenweger

php tools/test-client.php create
php tools/test-client.php me
php tools/test-client.php devices
php tools/test-client.php add-record
php tools/test-client.php sync
php tools/test-client.php delete-vault
```

Let op: test-state kan private testkeys bevatten. Bewaar dit niet in Git en laat het niet achter op een publiek toegankelijke locatie.

## Logging

De API stuurt onverwachte fouten naar PHP `error_log`.

De HTTP-response bevat bewust geen exceptiontekst of SQL-details:

```json
{
  "error": "internal_error"
}
```

of:

```json
{
  "error": "database_error"
}
```

Productielogs moeten alleen voor beheerders leesbaar zijn.

## Databasegebruiker

De runtime databaseuser hoort geen algemene serverbeheerrechten te hebben.

Voor de huidige API zijn applicatierechten nodig voor:

- `SELECT`;
- `INSERT`;
- `UPDATE`;
- `DELETE`.

Schemawijzigingen/migraties kunnen met een apart beheerdersaccount worden uitgevoerd.

## Backup

Een backupstrategie moet rekening houden met:

- database;
- configuratie buiten Git;
- herstel van het schema en de ciphertext;
- het feit dat de server geen VaultKeys bezit.

Een serverbackup alleen is dus niet voldoende om client-side keys te herstellen als alle geautoriseerde devices verloren gaan.

## Deploymentchecklist

Voor livegang:

- PHP syntaxcheck schoon;
- database schema/migraties uitgevoerd;
- `database.php` buiten webroot en niet in Git;
- webroot exact op `public/`;
- HTTPS actief;
- healthcheck groen;
- smoke-test groen;
- logs niet publiek leesbaar;
- geen test private keys of test-state achtergelaten;
- geen database dumps in de webroot;
- rate limiting toevoegen voordat open create-vault grootschalig publiek gebruikt wordt.

## Rollback

Bij een code-only release:

1. bewaar de vorige release buiten de webroot of via Git;
2. rol code terug;
3. herstart/reload PHP-FPM alleen indien nodig;
4. controleer health + smoke test.

Bij een schemawijziging moet vóór deployment expliciet een rollback/forward-fix plan worden opgesteld. Verwijder of verander geen encrypted productiegegevens zonder gecontroleerde migratie.
