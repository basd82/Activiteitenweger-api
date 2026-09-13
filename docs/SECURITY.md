# Security

## Doel

De Activiteitenweger API is ontworpen als synchronisatie- en opslaglaag voor end-to-end versleutelde gegevens. De server moet activiteiten kunnen opslaan, synchroniseren en verwijderen zonder de inhoud ervan te hoeven kennen.

## Vertrouwensgrens

De client is verantwoordelijk voor:

- genereren en bewaren van private Ed25519 signing keys;
- genereren en bewaren van private X25519 encryption keys;
- genereren en bewaren van de VaultKey;
- versleutelen en ontsleutelen van recordinhoud;
- controleren van gedeelde toegang en lokaal ontsleutelen van pairing-keypackages.

De server bewaart:

- publieke device keys;
- versleutelde key-envelopes;
- versleutelde records;
- nonces;
- signatures;
- minimale synchronisatiemetadata;
- device- en vault-identifiers;
- timestamps en toegangsstatus.

De server hoort de VaultKey en plaintext activiteiten niet te ontvangen.

## Cryptografie

### Request authentication

Authenticatie gebeurt met Ed25519 detached signatures over een canonical request string:

```text
AW-REQUEST-V1
METHOD
REQUEST_URI_INCLUSIEF_QUERY
TIMESTAMP
NONCE_HEADER_EXACT
SHA256_HEX_VAN_RAW_BODY
```

Daarmee zijn methode, pad/query, timestamp, nonce en body cryptografisch aan de request gebonden.

### Replaybeveiliging

Een request bevat:

- een Unix timestamp;
- maximaal 300 seconden klokafwijking;
- een cryptografisch willekeurige nonce van exact 16 bytes.

Na succesvolle signature-validatie wordt de SHA-256 hash van de nonce per device opgeslagen. Een duplicate primary key resulteert in `replayed_request`.

Nonce-rijen ouder dan tien minuten worden tijdens requests in beperkte batches opgeruimd.

### Record signatures

Elk record wordt apart ondertekend:

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

De API accepteert alleen een recordsignature die geldig is voor de Ed25519 public key van het geauthenticeerde device.

### Record encryption

De huidige client gebruikt XChaCha20-Poly1305 voor de inhoud van activiteiten.

De API behandelt ciphertext als opaque bytes. Voor een niet-verwijderd record verwacht de server een nonce van exact 24 bytes.

## Sleutelmodel

Per vault is er een `current_key_epoch`.

Een record mag alleen met de huidige epoch worden geschreven. Een verouderde epoch geeft:

```json
{
  "error": "stale_key_epoch",
  "currentKeyEpoch": 2
}
```

`device_key_envelopes` bevat per device en epoch een versleutelde sleutel-envelop. De server hoeft die envelope niet te kunnen ontsleutelen.

## Autorisatie

Huidige access modes:

- `R`: lezen/synchroniseren;
- `RW`: lezen en schrijven.

Een owner is altijd bedoeld als RW.

Huidige endpointrechten:

| Endpoint | Recht |
|---|---|
| health | openbaar |
| create vault | openbaar |
| me | R/RW |
| devices | RW |
| pairing invite maken/intrekken | owner + RW |
| pairing claim | pairing secret |
| device revoke | owner + RW |
| self-revoke | non-owner R/RW |
| records | RW |
| sync | R/RW |
| delete vault | owner + RW |

## Volledige verwijdering

De owner kan een vault volledig verwijderen met:

```text
DELETE /api/v1/vaults/{vaultId}
```

plus expliciete JSON-confirmatie.

Het schema gebruikt cascades zodat vault-gerelateerde gegevens worden verwijderd. Waar device-verwijzing historisch betekenis kan hebben, gebruikt het schema `SET NULL` zolang de vault zelf blijft bestaan.

## Metadata die de server wel kan zien

End-to-end encryptie verbergt niet alle metadata. De server kan onder andere zien:

- vault- en device-UUID's;
- welke device een record schreef;
- record-UUID's;
- revisions;
- recordgroottes;
- key epoch;
- timestamps;
- syncfrequentie;
- delete-status;
- access mode en ownerstatus.

Dit moet in de uiteindelijke privacydocumentatie van de app expliciet worden meegenomen.

## Nog openstaande beveiligingsonderdelen

De volgende onderdelen zijn bewust nog niet als af beschouwd:

- rate limiting op `POST /api/v1/vaults` en `POST /api/v1/pairing/claim`;
- automatische key rotation na revoke;
- audit/access-events;
- notificatie van revoke;
- periodieke opschoning van verlopen pairing invites;
- productie-hardening en security review.

## Revoke en geplande key rotation

Revoke vindt vanaf schema 2 op **vault-device niveau** plaats. Het globaal blokkeren van een device trekt daardoor niet automatisch toegang tot andere vaults in.

Bij sterke revoke:

1. de grant wordt ingetrokken;
2. een nieuwe key epoch wordt aangemaakt;
3. actieve devices krijgen een nieuwe encrypted VaultKey-envelope;
4. het ingetrokken device krijgt geen envelope voor de nieuwe epoch;
5. toekomstige records worden alleen met de nieuwe epoch geschreven.

Revoke kan reeds ontvangen plaintext of sleutels op een eerder geautoriseerd apparaat niet terughalen.

## Secrets in deze repository

Niet committen:

- `database.php`;
- private keys;
- VaultKeys;
- echte key-envelopes uit productie;
- test-state met private sleutels;
- certificaten en TLS private keys;
- dumps met productiegegevens.

De voorbeeldconfiguratie mag uitsluitend dummywaarden bevatten.

## Incidenten

Bij vermoeden van sleutel- of servercompromittering:

1. maak geen gevoelige logs of dumps publiek;
2. bepaal of alleen servermetadata of ook clientkeys zijn geraakt;
3. roteer servercredentials;
4. revoke getroffen device grants zodra die functionaliteit beschikbaar is;
5. roteer de betreffende vault key epoch wanneer clientkeys mogelijk zijn uitgelekt;
6. documenteer welke metadata of ciphertext toegankelijk is geweest.

## Security versus medisch gebruik

De API beschermt gegevensopslag en synchronisatie. Dat zegt niets over de medische betekenis of betrouwbaarheid van de ingevoerde activiteiten of scores. De Activiteitenweger is een registratie- en inzichtshulpmiddel en geen medisch beslissysteem.
