# Server bijwerken

Deze handleiding beschrijft het gecontroleerd bijwerken van een bestaande Activiteitenweger API-installatie.

## Versies

De server gebruikt twee verschillende versienummers:

- **API-versie**: protocol-major, bijvoorbeeld `1` voor `/api/v1`;
- **serverVersion**: semantische softwareversie, bijvoorbeeld `1.1.0`.

Een gewone serverupdate binnen een backwards-compatible API v1 verhoogt alleen `serverVersion`. Een breaking protocolwijziging vereist een nieuwe API-major.

Na deployment is de actieve softwareversie zichtbaar via:

```bash
curl -fsS https://app.dikkenberg.net/api/v1/health
```

en op iedere JSON-response in:

```text
X-AW-Server-Version
```

## Vooraf

Maak vóór een productie-update minimaal een databasebackup en bewaar de lokale `database.php`.

Voorbeeld:

```bash
cd /var/www/activiteitenweger

git status --short
git rev-parse HEAD

mysqldump --single-transaction --routines --triggers \\
  activiteitenweger > /root/activiteitenweger-before-update.sql
```

Controleer dat er geen lokale broncodewijzigingen staan die door `git pull` overschreven of vermengd kunnen worden.

## 1. Code ophalen

```bash
cd /var/www/activiteitenweger
git fetch --all --prune
git pull --ff-only
```

Gebruik in productie bij voorkeur een expliciete release/tag zodra releases getagd worden.

## 2. SQL-migraties controleren

Nieuwe databases worden altijd opgebouwd met:

```text
sql/install.sql
```

Wijzigingen voor bestaande databases komen als genummerde patches onder:

```text
sql/migrations/
```

Voer alleen migraties uit die bij de nieuwe release horen en nog niet op deze database zijn toegepast. Voer ze in oplopende volgorde uit met een databasebeheeraccount dat schemawijzigingen mag uitvoeren.

Voorbeeld:

```bash
mysql activiteitenweger < sql/migrations/002_voorbeeld.sql
```

Lees vóór uitvoering altijd de header/opmerkingen van de migratie en de betreffende release in `CHANGELOG.md`.

**Server 1.1.0 bevat geen database-schemawijziging en vereist dus geen SQL-migratie.**

## 3. PHP syntax controleren

```bash
cd /var/www/activiteitenweger

find public src tools -name '*.php' -print0 \\
  | xargs -0 -n1 php -l
```

Alle bestanden moeten zonder syntaxfouten eindigen.

## 4. PHP-FPM / OPcache

Wanneer productie OPcache gebruikt en gewijzigde PHP-bestanden niet direct worden herladen, reload PHP-FPM gecontroleerd. De exacte servicenaam verschilt per installatie, bijvoorbeeld:

```bash
systemctl reload php8.4-fpm
```

Voer dit alleen uit met de servicenaam die daadwerkelijk op de server bestaat.

## 5. Healthcheck en versie controleren

```bash
curl -i -fsS https://app.dikkenberg.net/api/v1/health
```

Voor server 1.1.0 moet onder andere zichtbaar zijn:

```text
X-AW-Server-Version: 1.1.0
```

met body:

```json
{
  "status": "ok",
  "database": "ok",
  "apiVersion": 1,
  "serverVersion": "1.1.0"
}
```

## 6. Functionele smoke-test

Gebruik daarna de testclient:

```bash
cd /var/www/activiteitenweger

php tools/test-client.php create
php tools/test-client.php me
php tools/test-client.php devices
php tools/test-client.php add-record
php tools/test-client.php conflict
php tools/test-client.php sync
php tools/test-client.php delete-vault
```

Controleer daarnaast bij een sync-gerelateerde release minimaal:

- record revision 1 kan worden aangemaakt;
- revision 2 kan hetzelfde record wijzigen;
- een tweede poging met dezelfde/oude revision geeft `409 revision_conflict`;
- `GET /sync?since=<cursor>` retourneert alleen veranderingen na de cursor;
- tombstones blijven via sync zichtbaar als deleted record.

## 7. Rollback

### Alleen code gewijzigd

Bij een code-only release kan naar de vorige bekende commit/tag worden teruggeschakeld en PHP-FPM zo nodig worden herladen.

### Database gewijzigd

Een reeds uitgevoerde database-migratie wordt niet automatisch teruggedraaid. Elke schemawijziging moet daarom vóór deployment een expliciet rollback- of forward-fix-plan hebben.

Herstel nooit zomaar een oude databasebackup over een live database terwijl clients ondertussen nieuwe versleutelde records hebben geschreven.

## Release 1.1.0

Voor de stap van de oorspronkelijke v1-server naar 1.1.0:

1. databasebackup maken;
2. code met `git pull --ff-only` ophalen;
3. **geen SQL-patch uitvoeren**;
4. PHP syntaxcheck uitvoeren;
5. PHP-FPM eventueel reloaden;
6. healthcheck controleren op `serverVersion: 1.1.0`;
7. smoke-test uitvoeren.

De database blijft schema versie 1.
