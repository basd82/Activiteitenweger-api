# Database

## Huidig schema

Database schema version: **1**

Het actuele installatieschema staat in `sql/install.sql`.

Tabellen:

- `vaults`
- `devices`
- `key_epochs`
- `device_key_envelopes`
- `pairing_invites`
- `records`
- `sync_events`
- `request_nonces`

Alle applicatietijden worden als UTC behandeld.

## Relaties

```text
vaults
 ├── devices
 │    └── request_nonces
 ├── key_epochs
 │    └── device_key_envelopes
 ├── pairing_invites
 ├── records
 │    └── sync_events
 └── device_key_envelopes
```

Belangrijk: dit beschrijft het **huidige** schema. Voor echte multi-client pairing wordt het device-model aangepast; zie "Geplande migratie".

## vaults

Primaire sleutel:

```text
vault_id CHAR(36)
```

Belangrijkste velden:

- `current_key_epoch`
- `created_at`
- `updated_at`

Een vault is de cryptografische en synchronisatie-eenheid.

## devices

Huidige primaire sleutel:

```text
device_id CHAR(36)
```

Belangrijkste velden:

- `vault_id`
- `access_mode`: `R` of `RW`
- `is_owner`
- `status`: `ACTIVE` of `REVOKED`
- `auth_public_key` — Ed25519
- `encryption_public_key` — X25519
- encrypted label-velden
- `created_at`
- `last_seen_at`
- `revoked_at`

### Huidige beperking

Een device hoort nu rechtstreeks bij precies één vault. Dat is voldoende voor de eerste geauthenticeerde sync-laag, maar niet voor een behandelaar die vanaf één apparaat meerdere cliëntvaults moet kunnen openen.

Daarom wordt dit model vóór pairing vervangen.

## key_epochs

Composite primary key:

```text
(vault_id, epoch)
```

Een key epoch maakt toekomstige sleutelrotatie mogelijk.

Velden:

- `vault_id`
- `epoch`
- `created_by_device_id`
- `created_at`
- `retired_at`

Bij verwijdering van het creator-device wordt `created_by_device_id` op `NULL` gezet.

## device_key_envelopes

Composite primary key:

```text
(vault_id, epoch, device_id)
```

Deze tabel bevat per device een encrypted envelope voor de VaultKey van een bepaalde epoch.

De API bewaart:

- `envelope_ciphertext`
- optionele `envelope_nonce`
- `crypto_version`

De server hoeft de envelope niet te kunnen ontsleutelen.

## pairing_invites

De tabel bestaat al als voorbereidende datastructuur, maar pairing-endpoints zijn nog niet geïmplementeerd.

Belangrijkste velden:

- `invite_id`
- `vault_id`
- `created_by_device_id`
- `access_mode`
- `status`: `PENDING`, `CLAIMED`, `REVOKED`, `EXPIRED`
- `invite_public_key`
- encrypted key package
- `key_epoch`
- `expires_at`
- `claimed_at`
- `claimed_by_device_id`

## records

Primaire sleutel:

```text
record_id CHAR(36)
```

De server bewaart geen plaintext activiteitpayload.

Belangrijkste velden:

- `vault_id`
- `key_epoch`
- `revision`
- `crypto_version`
- `ciphertext`
- `nonce`
- `writer_device_id`
- `signature`
- `is_deleted`
- timestamps

Bij een tombstone:

- `is_deleted = 1`
- `ciphertext = NULL`
- `nonce = NULL`
- `deleted_at` is gevuld

De revision blijft oplopen.

## sync_events

Primaire sleutel:

```text
event_id BIGINT UNSIGNED AUTO_INCREMENT
```

Elke succesvolle recordmutatie maakt één sync-event:

- `UPSERT`
- `DELETE`

Unieke combinatie:

```text
(vault_id, record_id, revision)
```

Clients gebruiken `event_id` als cursor.

De sync-endpoint geeft niet noodzakelijk één response-item per event terug. Het cursorvenster bepaalt welke records geraakt zijn; de response bevat daarvan de actuele recordstaat.

## request_nonces

Composite primary key:

```text
(device_id, nonce_hash)
```

De server bewaart de SHA-256 hash van de 16-byte request nonce.

Doel:

- replay van dezelfde signed request detecteren;
- nonce zelf niet onnodig opslaan.

Oude nonces worden tijdens geauthenticeerde requests in batches verwijderd.

## Delete-regels

De huidige foreign keys zijn zo ingericht dat volledige vaultverwijdering werkt.

Belangrijkste regels:

- `devices.vault_id -> vaults`: CASCADE
- `key_epochs.vault_id -> vaults`: CASCADE
- `device_key_envelopes.device_id -> devices`: CASCADE
- `device_key_envelopes.(vault_id, epoch) -> key_epochs`: CASCADE
- `pairing_invites.vault_id -> vaults`: CASCADE
- `pairing_invites.creator/claimed_device -> devices`: SET NULL
- `records.vault_id -> vaults`: CASCADE
- `records.(vault_id, key_epoch) -> key_epochs`: CASCADE
- `records.writer_device_id -> devices`: SET NULL
- `sync_events.vault_id -> vaults`: CASCADE
- `sync_events.record_id -> records`: CASCADE
- `request_nonces.device_id -> devices`: CASCADE

## Geplande device-migratie

Voor multi-client gebruik wordt het huidige device-model vervangen door:

### devices

Globale device-identiteit:

```text
device_id
auth_public_key
encryption_public_key
status
created_at
last_seen_at
```

Geen `vault_id`, `access_mode` of ownerstatus meer op het globale device.

### vault_devices

Many-to-many grant:

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

Verwachte composite key:

```text
(vault_id, device_id)
```

Hiermee kan:

- één cliëntvault meerdere devices hebben;
- één behandelaar-device meerdere cliëntvaults openen;
- revoke per cliëntrelatie plaatsvinden;
- self-revoke één vault loskoppelen zonder andere vaults te beïnvloeden.

### Gevolgen voor andere tabellen

Na deze migratie moeten in elk geval worden herzien:

- request-auth lookup;
- `device_key_envelopes`;
- pairing;
- devices endpoint;
- revoke;
- ownercontrole;
- audit events;
- key rotation.

## Installatie versus migraties

`sql/install.sql` beschrijft een nieuwe installatie van het **huidige** schema.

`migration.sql` is een historische gerichte migratie die `request_nonces` toevoegt aan een bestaande installatie.

Nieuwe schemawijzigingen worden vanaf nu als genummerde patches onder `sql/migrations/` toegevoegd, bijvoorbeeld:

```text
sql/migrations/
  002_global_devices_vault_devices.sql
  003_pairing_grants.sql
```

Schema versie 1 is de huidige baseline uit `sql/install.sql`; daarvoor is geen aparte migratie nodig.

Iedere toekomstige schemawijziging moet tegelijk:

1. een nieuwe genummerde migratie voor bestaande installaties krijgen;
2. in `sql/install.sql` worden verwerkt voor verse installaties;
3. in `CHANGELOG.md` en `docs/UPDATE.md` worden genoemd.

Zie ook `sql/migrations/README.md`.
