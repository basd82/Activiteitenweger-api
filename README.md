# Activiteitenweger API

Backend voor de **Activiteitenweger**-app. De API bewaart en synchroniseert versleutelde vault-records, maar hoort de inhoud van activiteiten niet te kunnen ontsleutelen.

## Repositories

- **App:** [basd82/Activiteitenweger](https://github.com/basd82/Activiteitenweger)
- **API:** [basd82/Activiteitenweger-api](https://github.com/basd82/Activiteitenweger-api)

De app en API worden afzonderlijk ontwikkeld, maar delen hetzelfde protocol en datamodel.

## Status

API-versie: **v1**  
Serverversie: **1.1.0**

Werkend in de huidige versie:

- healthcheck;
- aanmaken van een nieuwe vault met owner/RW-device;
- Ed25519 request-authenticatie;
- timestamp- en nonce-gebaseerde replaybeveiliging;
- ophalen van de eigen device/vault-context;
- ophalen van devices binnen de huidige vault;
- toevoegen, wijzigen en verwijderen van versleutelde records;
- revision-conflictcontrole;
- cursor-gebaseerde synchronisatie;
- conflictmetadata voor veilige client-side conflictafhandeling;
- serverversie via health-response en `X-AW-Server-Version`;
- tombstones voor verwijderde records;
- volledige verwijdering van een vault door de owner;
- key epochs en versleutelde key-envelopes in het datamodel.

Nog **niet** functioneel beschikbaar:

- pairing via code/QR/link;
- device grants voor meerdere vaults per device;
- koppelen van een behandelaar aan meerdere cliënten;
- self-revoke en owner-revoke;
- key rotation na revoke;
- audit/access-events en notificaties;
- rate limiting op de publieke create-vault endpoint.

Zie [docs/ROADMAP.md](docs/ROADMAP.md) voor de geplande vervolgstappen.

## Architectuur

De API is bewust klein gehouden en gebruikt geen PHP-framework.

```text
public/index.php
    │
    ├── src/common.php
    ├── src/db.php
    ├── src/auth.php
    └── src/handlers.php
            │
            └── MySQL
```

De webroot hoort op `public/` te staan. Bestanden zoals `database.php`, `src/`, `sql/` en `tools/` horen dus niet rechtstreeks via de webserver bereikbaar te zijn.

## Vereisten

- PHP 8.1 of nieuwer;
- extensies: `mysqli` en `sodium`;
- MySQL met InnoDB;
- HTTPS op de publieke endpoint;
- een databasegebruiker met alleen de benodigde rechten op de applicatiedatabase.

De productie-URL die door de huidige app wordt gebruikt is:

```text
https://app.dikkenberg.net/api/v1
```

## Installatie

Voor een volledige installatie vanaf een lege server/database: **[docs/INSTALL.md](docs/INSTALL.md)**.

Kort samengevat: maak eerst de database aan en voer daarna het huidige schema uit:

```bash
mysql activiteitenweger < sql/install.sql
```

Maak vervolgens `database.php` in de projectroot op basis van `database.php.example`:

```php
<?php
$dbserver = 'localhost';
$dbuser = 'activiteitenweger';
$dbww = '...';
$db = 'activiteitenweger';
```

Dit bestand staat in `.gitignore` en mag niet worden gecommit.

Voor een bestaande installatie waarop alleen de replaybeveiliging nog ontbreekt, bestaat ook `migration.sql`. Voor een nieuwe installatie is `sql/install.sql` leidend.

Controleer daarna:

```bash
php -m | grep -E 'mysqli|sodium'
find . -name '*.php' -print0 | xargs -0 -n1 php -l
curl https://app.dikkenberg.net/api/v1/health
```

Verwachte health-response:

```json
{
  "status": "ok",
  "database": "ok",
  "apiVersion": 1
}
```

## API-overzicht

| Methode | Endpoint | Toegang |
|---|---|---|
| `GET` | `/api/v1/health` | openbaar |
| `POST` | `/api/v1/vaults` | openbaar |
| `GET` | `/api/v1/me` | signed R/RW |
| `GET` | `/api/v1/devices` | signed RW |
| `POST` | `/api/v1/records` | signed RW + recordsignature |
| `GET` | `/api/v1/sync?since=0&limit=100` | signed R/RW |
| `DELETE` | `/api/v1/vaults/{vaultId}` | signed owner/RW |

De volledige request- en responsebeschrijving staat in [docs/API.md](docs/API.md).

## Beveiligingsmodel

De API gebruikt twee afzonderlijke Ed25519-signatuurlagen:

1. **Request signing** bewijst welk device de HTTP-request heeft verstuurd en beschermt samen met timestamp + nonce tegen replay.
2. **Record signing** bindt een recordrevision aan vault, record-id, key epoch, delete-status, nonce en ciphertext-hash.

De server bewaart ciphertext en cryptografische metadata. De VaultKey hoort alleen beschikbaar te zijn op geautoriseerde clients en wordt als versleutelde envelope opgeslagen.

Zie [docs/SECURITY.md](docs/SECURITY.md) voor de beveiligingsgrenzen en bekende nog openstaande onderdelen.

## Synchronisatie

Elke succesvolle UPSERT of DELETE maakt een rij in `sync_events`. Clients synchroniseren met een monotone `event_id`-cursor:

```text
GET /api/v1/sync?since=<cursor>&limit=<1..500>
```

De response bevat de actuele records die geraakt zijn door events in het cursorvenster, plus:

- `nextCursor`;
- `hasMore`;
- `currentKeyEpoch`.

## Database

Het huidige schema bevat:

- `vaults`
- `devices`
- `key_epochs`
- `device_key_envelopes`
- `pairing_invites`
- `records`
- `sync_events`
- `request_nonces`

Belangrijk: het huidige `devices`-model koppelt een device rechtstreeks aan één vault. Dit wordt vóór de echte pairing-functionaliteit vervangen door een globaal device-model met een many-to-many `vault_devices`-relatie.

Zie [docs/DATABASE.md](docs/DATABASE.md).

## Testclient

`tools/test-client.php` kan de v1-flow met echte Ed25519-signing testen.

Voorbeeld:

```bash
php tools/test-client.php create
php tools/test-client.php me
php tools/test-client.php devices
php tools/test-client.php add-record
php tools/test-client.php conflict
php tools/test-client.php sync
php tools/test-client.php delete-vault
```

De testclient kan lokaal private testkeys opslaan. Behandel eventuele test-state daarom als geheim en commit die bestanden nooit.

## Documentatie

- [Installatiehandleiding](docs/INSTALL.md)
- [Updatehandleiding](docs/UPDATE.md)
- [Changelog](CHANGELOG.md)
- [API-protocol](docs/API.md)
- [Database en datamodel](docs/DATABASE.md)
- [Security](docs/SECURITY.md)
- [Deployment](docs/DEPLOYMENT.md)
- [Roadmap](docs/ROADMAP.md)

## Auteursrecht en licentie

Copyright © 2026 Bas van den Dikkenberg.

De Activiteitenweger API is vrije/open-sourcesoftware en wordt uitgebracht onder de **GNU General Public License versie 3.0 (GPL-3.0-only)**.

Je mag de software gebruiken, bestuderen, wijzigen en verspreiden onder de voorwaarden van GPLv3. Er wordt geen garantie gegeven, voor zover wettelijk toegestaan.

Zie [LICENSE](LICENSE) voor de volledige licentietekst.
