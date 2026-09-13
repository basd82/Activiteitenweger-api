# Changelog

Alle noemenswaardige wijzigingen aan de Activiteitenweger API worden hier bijgehouden.

## 1.1.0 — 2026-09-13

### Toegevoegd
- Semantische serverversie `1.1.0`.
- `serverVersion` in `GET /api/v1/health`.
- `X-AW-Server-Version` op alle JSON-responses.
- Uitgebreide metadata bij `409 revision_conflict`: `recordId`, `currentRevision`, `expectedRevision`, `currentDeleted` en `currentUpdatedAt`.
- Genummerde migratiestructuur onder `sql/migrations/`.
- Aparte updatehandleiding in `docs/UPDATE.md`.
- Stateful Postman-collectie onder `docs/postman/` voor health, signing, revisions, cursor-sync, conflicts en tombstones.
- Uitgebreide PHP-smoke-test met expliciete `revision_conflict`-test.

### Synchronisatie
- De bestaande revisioncontrole blijft leidend: een bestaand record accepteert alleen exact `huidige revision + 1`.
- De server bepaalt `updated_at`; clients gebruiken apparaatklokken niet voor conflictbeslissingen.
- Na `revision_conflict` moet een client eerst synchroniseren en het conflict oplossen; blind opnieuw schrijven is niet toegestaan.

### Database
- Geen schemawijziging in 1.1.0.
- `sql/install.sql` is gemarkeerd als databaseschema versie 1.
- Voor bestaande installaties hoeft voor 1.1.0 geen SQL-migratie uitgevoerd te worden.

## 1.0.0 — 2026-09-13

- Eerste API v1-basis met vaults, signed requests, encrypted records, revisioncontrole, cursorsync, tombstones en vaultverwijdering.
