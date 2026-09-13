# Roadmap

## Server 1.2.0 — pairingbasis

Geïmplementeerd:
- globale devices;
- many-to-many vault grants;
- R/RW;
- pairing invites/claim;
- device revoke en self-revoke;
- multi-vault requestcontext.

Nog te bouwen na deze fase:
- key rotation na revoke voor forward secrecy;
- audit/access events;
- generieke pushmeldingen.

Deze roadmap beschrijft de API-kant van Activiteitenweger.

## Fase 1 — Basis sync-laag

Status: **gereed**

- health endpoint;
- vault aanmaken;
- owner/RW-device aanmaken;
- Ed25519 request signing;
- timestampcontrole;
- nonce replaybeveiliging;
- `/me`;
- `/devices`;
- encrypted record upsert;
- record signatures;
- revisioncontrole;
- key epoch controle;
- sync cursor/events;
- tombstones;
- volledige vaultverwijdering;
- delete cascades;
- CLI smoke-test.

## Fase 2 — Schema geschikt maken voor multi-client gebruik

Status: **volgende grote backendstap**

Doel: één fysiek/logisch device moet toegang kunnen hebben tot meerdere vaults.

### Nieuwe globale devices

`devices` wordt onafhankelijk van een vault:

```text
device_id
auth_public_key
encryption_public_key
status
created_at
last_seen_at
```

### Nieuwe vault_devices grants

```text
vault_id
device_id
access_mode
is_owner
status
created_at
revoked_at
revoked_by
```

Daarna worden authentication en autorisatie gebaseerd op de combinatie van device + vault-context.

## Fase 3 — Pairing

Status: **gepland**

Functionaliteit:

- owner maakt pairing invite;
- toegang kiezen: R of RW;
- unieke korte code;
- QR-code/deeplink payload;
- app-to-app koppeling;
- invite expiry;
- claim door nieuw device;
- encrypted key package;
- geen plaintext VaultKey op de server;
- invite na claim niet opnieuw bruikbaar.

De server fungeert alleen als relay/opslag voor versleuteld sleutelmaterial.

## Fase 4 — Revoke

Status: **gepland**

Ondersteunen:

- owner revoke van gekoppeld device;
- self-revoke door behandelaar/device;
- revoke van één vault grant zonder andere vaults van hetzelfde device te beïnvloeden;
- status + timestamp;
- reden/actor in audit event.

## Fase 5 — Sterke revoke + key rotation

Status: **gepland**

Na revoke:

1. nieuwe key epoch;
2. nieuwe VaultKey;
3. envelopes voor alle nog geautoriseerde devices;
4. geen envelope voor revoked device;
5. nieuwe records alleen onder nieuwe epoch;
6. clients krijgen `stale_key_epoch` wanneer ze achterlopen.

Historische gegevens die een device vóór revoke al heeft ontsleuteld kunnen niet worden teruggenomen.

## Fase 6 — Audit en notificaties

Status: **gepland**

Audit-events voor minimaal:

- invite aangemaakt;
- invite geclaimd;
- pairing voltooid;
- grant gewijzigd;
- self-revoke;
- owner-revoke;
- key rotation;
- nieuw device;
- volledige vaultverwijdering.

De auditlaag moet append-only gedrag krijgen voor normale gebruikersflows.

Notificaties:

- cliënt ontvangt melding van revoke/self-revoke;
- mogelijk later device- of securitymeldingen.

## Fase 7 — Encrypted profielmetadata

Status: **gepland**

De huidige profielnaam leeft lokaal in de app.

Voor multi-device gebruik is een encrypted metadata-record nodig voor bijvoorbeeld:

- profielnaam;
- eventueel aanvullende niet-medische UI-metadata.

De server mag de profielnaam niet in plaintext hoeven opslaan.

## Fase 8 — Hardening

Status: **gepland**

- rate limiting;
- productie security headers waar van toepassing;
- formele API error catalog;
- migratieframework;
- geautomatiseerde integratietests;
- CI syntax/lint/tests;
- backup/restore test;
- observability zonder plaintext data;
- retentionbeleid voor servermetadata;
- expliciete limits per endpoint;
- abusebescherming voor openbare endpoints.

## Fase 9 — Releasebeheer

Status: **deels actief vanaf server 1.1.0**

Voor productie/stabiele releases:

- semantische serverversie naast de API-major — actief;
- changelog — actief;
- genummerde SQL-migraties — structuur actief, eerste schemawijziging volgt bij noodzaak;
- compatibility matrix app ↔ API;
- backwards-compatible API-evolutie binnen `/api/v1`;
- alleen breaking wijziging via nieuwe API major.

## Niet-doelen van de API

De API moet niet:

- activiteitinhoud analyseren;
- medische adviezen geven;
- belasting/scores medisch interpreteren;
- plaintext activiteiten opslaan;
- advertentieprofielen opbouwen uit activiteitgegevens.

De API is primair een beveiligde synchronisatie- en opslaglaag.
