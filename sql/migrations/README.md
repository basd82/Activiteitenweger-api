# Database-migraties

Deze directory bevat SQL-patches voor **bestaande** Activiteitenweger-databases.

## Regels

- Een verse installatie gebruikt altijd `../install.sql`.
- Iedere schemawijziging krijgt een nieuw oplopend nummer.
- Een bestaande migratie wordt na publicatie niet inhoudelijk herschreven.
- Migraties worden in oplopende volgorde uitgevoerd.
- Iedere schemawijziging moet tegelijk worden verwerkt in `../install.sql`.
- `CHANGELOG.md` en `docs/UPDATE.md` vermelden welke migraties bij een release horen.
- Maak vóór een productiemigratie een databasebackup.
- Gebruik voor schemawijzigingen een apart databasebeheeraccount; de runtime-user hoeft geen DDL-rechten te hebben.

Naamgeving:

```text
002_beschrijving.sql
003_beschrijving.sql
004_beschrijving.sql
```

## Huidige status

Database schema version: **2**

Server **1.2.0** gebruikt `002_global_devices_pairing.sql` om een bestaande schema-1 database naar het globale device- en pairingmodel te migreren.
