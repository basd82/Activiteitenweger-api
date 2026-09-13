# API v1

Base path:

```text
/api/v1
```

Alle responses zijn JSON met `Content-Type: application/json; charset=utf-8` en `Cache-Control: no-store`.

## Conventies

- UUID's moeten geldige RFC 4122-achtige UUID's zijn volgens de servervalidatie.
- Tijden in responses zijn UTC.
- Binaire velden worden in responses als Base64URL zonder padding verzonden.
- Request-bodyvelden accepteren tijdens de huidige ontwikkelfase zowel Base64 als Base64URL.
- Maximale JSON-requestbody: 512 KiB.
- Maximale ciphertext per record: 256 KiB.
- `sync.limit` moet tussen 1 en 500 liggen.

## Authenticatie

Alle niet-openbare endpoints gebruiken Ed25519 request signing.

Vereiste headers:

```text
X-AW-Device-Id: <device UUID>
X-AW-Vault-Id: <vault UUID>
X-AW-Timestamp: <Unix timestamp in seconds>
X-AW-Nonce: <16 random bytes, Base64URL zonder padding>
X-AW-Signature: <Ed25519 detached signature, Base64URL zonder padding>
```

De timestamp mag maximaal 300 seconden afwijken van de servertijd.

Vanaf server 1.2.0 selecteert `X-AW-Vault-Id` de grant/vaultcontext. Voor backwards compatibility mag de header ontbreken wanneer het device exact één actieve vaultgrant heeft. Bij meerdere actieve grants is de header verplicht en geeft ontbreken `400 vault_context_required`.

De exacte bytes die worden ondertekend zijn UTF-8:

```text
AW-REQUEST-V1
METHOD
REQUEST_URI_INCLUSIEF_QUERY
TIMESTAMP
NONCE_HEADER_EXACT
SHA256_HEX_VAN_RAW_BODY
```

Er staat na elke regel behalve de laatste een newline (`\n`).

Voorbeeld voor:

```text
GET /api/v1/sync?since=0&limit=100
```

met lege body:

```text
AW-REQUEST-V1\n
GET\n
/api/v1/sync?since=0&limit=100\n
<TIMESTAMP>\n
<NONCE>\n
e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855
```

De nonce wordt na geldige authenticatie gehasht opgeslagen in `request_nonces`. Hergebruik van dezelfde nonce voor hetzelfde device geeft `401 replayed_request`.

## Record signing

Een record heeft naast de request-signature ook een eigen Ed25519-signature.

Canonical record bytes:

```text
AW-RECORD-V1
VAULT_UUID
RECORD_UUID
REVISION
KEY_EPOCH
DELETED_0_OF_1
NONCE_BASE64URL_OF_LEEG
SHA256_HEX_VAN_CIPHERTEXT_OF_LEEG
```

Ook hier worden de regels met `\n` verbonden.

Bij een tombstone:

- `deleted = true`;
- `ciphertext = null`;
- `nonce = null`;
- de nonce-regel in de canonical string is leeg;
- de ciphertext-hash is SHA-256 van de lege byte-string.

## GET /health

Openbaar.

```http
GET /api/v1/health
```

Succes:

```json
{
  "status": "ok",
  "database": "ok",
  "apiVersion": 1,
  "serverVersion": "1.2.0"
}
```

Alle JSON-responses bevatten daarnaast de HTTP-header:

```text
X-AW-Server-Version: 1.2.0
```

`apiVersion` is de protocol-major en blijft `1` zolang `/api/v1` backwards-compatible blijft. `serverVersion` is de semantische softwareversie van de server en mag dus binnen API v1 oplopen.

Status: `200`.

Als de database niet bereikbaar is: `503`.

---

## POST /vaults

Openbaar. Maakt een nieuwe vault, owner-device, key epoch 1 en de eerste key-envelope in één database-transactie.

Request:

```json
{
  "vaultId": "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa",
  "deviceId": "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb",
  "authPublicKey": "<32-byte Ed25519 public key>",
  "encryptionPublicKey": "<32-byte X25519 public key>",
  "keyEpoch": 1,
  "keyEnvelope": "<encrypted key envelope>",
  "keyEnvelopeNonce": null
}
```

Regels:

- `keyEpoch` moet exact `1` zijn;
- `authPublicKey` is exact 32 bytes;
- `encryptionPublicKey` is exact 32 bytes;
- `keyEnvelope` is minimaal 16 bytes en maximaal 4096 bytes;
- `keyEnvelopeNonce` is optioneel en maximaal 32 bytes.

Succes: `201`

```json
{
  "status": "ok",
  "vaultId": "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa",
  "deviceId": "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb",
  "access": "RW",
  "owner": true,
  "keyEpoch": 1
}
```

Bij een bestaand UUID: `409 already_exists`.

---

## GET /me

Signed R/RW.

```http
GET /api/v1/me
```

Response:

```json
{
  "deviceId": "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb",
  "vaultId": "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa",
  "access": "RW",
  "owner": true,
  "currentKeyEpoch": 1
}
```

---

## GET /devices

Signed **RW**.

```http
GET /api/v1/devices
```

Response:

```json
{
  "devices": [
    {
      "deviceId": "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb",
      "access": "RW",
      "owner": true,
      "status": "ACTIVE",
      "createdAt": "2026-09-13T07:00:00.000000Z",
      "lastSeenAt": "2026-09-13T07:10:00.000000Z",
      "revokedAt": null
    }
  ]
}
```

---

## POST /records

Signed **RW** plus geldige record-signature.

### UPSERT

```json
{
  "recordId": "cccccccc-cccc-4ccc-8ccc-cccccccccccc",
  "revision": 1,
  "keyEpoch": 1,
  "deleted": false,
  "ciphertext": "<ciphertext>",
  "nonce": "<24-byte nonce>",
  "recordSignature": "<64-byte Ed25519 signature>"
}
```

Voor een nieuw record moet `revision = 1` zijn. Voor een bestaand record moet de revision exact de vorige revision + 1 zijn.

`keyEpoch` moet gelijk zijn aan de actuele `current_key_epoch` van de vault.

Succes voor nieuw record: `201`.

Succes voor update: `200`.

```json
{
  "status": "ok",
  "recordId": "cccccccc-cccc-4ccc-8ccc-cccccccccccc",
  "revision": 1,
  "deleted": false,
  "eventId": 123
}
```

### DELETE / tombstone

Verwijderen van een record gebeurt via hetzelfde endpoint:

```json
{
  "recordId": "cccccccc-cccc-4ccc-8ccc-cccccccccccc",
  "revision": 2,
  "keyEpoch": 1,
  "deleted": true,
  "recordSignature": "<64-byte Ed25519 signature>"
}
```

Voor een tombstone zijn `ciphertext` en `nonce` niet nodig. De bestaande ciphertext wordt op de server verwijderd.

Belangrijke conflicts:

- `409 stale_key_epoch`;
- `409 revision_conflict`;
- `409 record_id_conflict`.

Bij een revision-conflict wordt de lokale wijziging **niet** opgeslagen. Voor een bestaand record bevat de response voldoende metadata om eerst opnieuw te synchroniseren:

```json
{
  "error": "revision_conflict",
  "recordId": "cccccccc-cccc-4ccc-8ccc-cccccccccccc",
  "currentRevision": 5,
  "expectedRevision": 6,
  "currentDeleted": false,
  "currentUpdatedAt": "2026-09-13T14:00:00.000000Z"
}
```

Een client mag na deze fout niet blind `expectedRevision` opnieuw versturen. Eerst moet de actuele serverstaat via `GET /sync` worden opgehaald en moet het conflict lokaal worden opgelost.

---

## GET /sync

Signed R/RW.

```http
GET /api/v1/sync?since=0&limit=100
```

Parameters:

- `since`: laatste bekende `event_id`, standaard `0`;
- `limit`: aantal sync-events in het cursorvenster, standaard `100`, maximaal `500`.

Response:

```json
{
  "records": [
    {
      "recordId": "cccccccc-cccc-4ccc-8ccc-cccccccccccc",
      "keyEpoch": 1,
      "revision": 2,
      "cryptoVersion": 1,
      "ciphertext": "<ciphertext of null>",
      "nonce": "<nonce of null>",
      "writerDeviceId": "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb",
      "recordSignature": "<signature>",
      "deleted": false,
      "updatedAt": "2026-09-13T07:15:00.000000Z",
      "deletedAt": null
    }
  ],
  "nextCursor": 123,
  "hasMore": false,
  "currentKeyEpoch": 1
}
```

Wanneer er geen nieuwe events zijn:

```json
{
  "records": [],
  "nextCursor": 123,
  "hasMore": false,
  "currentKeyEpoch": 1
}
```

De server retourneert de **actuele staat** van records die door events binnen het geselecteerde cursorvenster geraakt zijn. Het is dus geen volledige historische event-feed.

---

## DELETE /vaults/{vaultId}

Signed **owner/RW**.

Request:

```http
DELETE /api/v1/vaults/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa
Content-Type: application/json
```

Body:

```json
{
  "confirm": "DELETE"
}
```

Het vault-id in het pad moet gelijk zijn aan de vault van het geauthenticeerde owner-device.

Succes:

```json
{
  "status": "deleted"
}
```

Door de foreign-key cascades worden vaultgebonden records, sync-events, key epochs, envelopes, pairing-invites en vault-device grants verwijderd. Globale device-identiteiten blijven bestaan wanneer ze nog aan een andere vault gekoppeld zijn; volledig verweesde device-identiteiten worden daarna opgeruimd.

## HTTP-statuscodes

Veelgebruikte statuscodes:

| Status | Betekenis |
|---|---|
| `200` | succesvol |
| `201` | resource aangemaakt |
| `400` | ongeldige request/body/signature |
| `401` | request-authenticatie mislukt |
| `403` | onvoldoende rechten |
| `404` | niet gevonden / niet zichtbaar binnen auth-context |
| `409` | conflict, stale epoch of revision-conflict |
| `413` | requestbody te groot |
| `500` | interne/databasefout |
| `503` | healthcheck: database niet beschikbaar |

## Pairing

### POST /pairing/invites

Signed **owner/RW**. De owner-app genereert lokaal een willekeurig pairing secret van 16 bytes en versleutelt een key package lokaal. De server ontvangt de VaultKey nooit.

Request:

```json
{
  "inviteId": "dddddddd-dddd-4ddd-8ddd-dddddddddddd",
  "access": "R",
  "pairingSecretHash": "<32-byte SHA-256>",
  "keyPackageCiphertext": "<E2E encrypted package>",
  "keyPackageNonce": "<24-byte nonce>",
  "expiresInSeconds": 600
}
```

`access` is `R` of `RW`. De expiry moet 60–3600 seconden zijn.

### POST /pairing/claim

Openbaar, maar vereist bezit van het 128-bit pairing secret.

```json
{
  "pairingSecret": "<16 random bytes>",
  "deviceId": "eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee",
  "authPublicKey": "<32-byte Ed25519 public key>",
  "encryptionPublicKey": "<32-byte X25519 public key>"
}
```

Succes geeft de grant en het versleutelde key package terug. De nieuwe app decrypt dit lokaal met het pairing secret.

### DELETE /pairing/invites/{inviteId}

Signed **owner/RW**. Trekt een nog niet gebruikte invite in.

### DELETE /devices/{deviceId}

Signed **owner/RW**. Trekt de grant van een niet-owner device voor de huidige vault in.

### DELETE /me/access

Signed R/RW. Een niet-owner device trekt zijn eigen grant voor de huidige vault in.

## Multi-device model

`devices` bevat vanaf schema 2 alleen globale cryptografische device-identiteit. Toegang staat in `vault_devices` met per vault:

- `access_mode`: R/RW;
- `is_owner`;
- `status`: ACTIVE/REVOKED;
- revoke-auditvelden.

Een R-device kan `/sync` lezen maar `POST /records` geeft `403 write_access_required`.
