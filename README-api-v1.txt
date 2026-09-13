Activiteitenweger API v1 - volgende stap

Bestanden:
- public/index.php       centrale router
- src/common.php         JSON/base64/UUID helpers
- src/db.php             mysqli connectie via ../database.php
- src/auth.php           Ed25519 request-auth + replaybeveiliging
- src/handlers.php       vault, me, devices, records, sync, delete vault
- migration.sql          request_nonces tabel
- tools/test-client.php  CLI testclient met echte Ed25519 signing

Installatie op app-server:

1. Backup huidige index.php:
   cp /var/www/activiteitenweger/public/index.php /var/www/activiteitenweger/public/index.php.bak

2. Kopieer public/ src/ tools/ naar /var/www/activiteitenweger/
   database.php blijft staan waar hij nu staat.

3. Voer migratie uit:
   mysql activiteitenweger < migration.sql

4. Rechten:
   chown -R root:www-data /var/www/activiteitenweger/src /var/www/activiteitenweger/public
   find /var/www/activiteitenweger/src /var/www/activiteitenweger/public -type d -exec chmod 750 {} \;
   find /var/www/activiteitenweger/src /var/www/activiteitenweger/public -type f -exec chmod 640 {} \;
   chmod 640 /var/www/activiteitenweger/database.php

5. Controleer sodium/curl:
   php -m | grep -E 'sodium|curl|mysqli'

6. Syntax:
   find /var/www/activiteitenweger -name '*.php' -print0 | xargs -0 -n1 php -l

7. Test:
   curl https://app.dikkenberg.net/api/v1/health

   cd /var/www/activiteitenweger
   php tools/test-client.php create
   php tools/test-client.php me
   php tools/test-client.php devices
   php tools/test-client.php add-record
   php tools/test-client.php sync
   php tools/test-client.php delete-vault

LET OP:
- tools/test-state.json bevat TEST private keys en wordt chmod 600.
- Het bestand staat buiten public/, maar verwijder het na testen als de delete-vault test niet is uitgevoerd.
- POST /api/v1/vaults is publiek om een nieuwe vault te kunnen maken; rate limiting op HAProxy/Nginx volgt nog.
- Pairing R/RW en sleutelrotatie zijn nog NIET in deze batch opgenomen. Eerst ligt hiermee de geauthenticeerde sync-laag vast.

Request signing protocol:
Headers:
  X-AW-Device-Id: <uuid>
  X-AW-Timestamp: <unix seconds>
  X-AW-Nonce: <16 random bytes, Base64URL zonder =>
  X-AW-Signature: <Ed25519 detached signature, Base64URL zonder =>

Te ondertekenen bytes (UTF-8):
  AW-REQUEST-V1\n
  METHOD\n
  REQUEST_URI_INCLUSIEF_QUERY\n
  TIMESTAMP\n
  NONCE_HEADER_EXACT\n
  SHA256_HEX_VAN_RAW_BODY

Record signing protocol:
  AW-RECORD-V1\n
  VAULT_UUID\n
  RECORD_UUID\n
  REVISION\n
  KEY_EPOCH\n
  DELETED_0_OF_1\n
  NONCE_BASE64URL_OF_LEEG\n
  SHA256_HEX_VAN_CIPHERTEXT_OF_LEEG

API:
  GET    /api/v1/health                   openbaar
  POST   /api/v1/vaults                   openbaar, maakt owner/RW device
  GET    /api/v1/me                       signed R/RW
  GET    /api/v1/devices                  signed RW
  POST   /api/v1/records                  signed RW + record signature
  GET    /api/v1/sync?since=0&limit=100   signed R/RW
  DELETE /api/v1/vaults/{vaultId}         signed OWNER, body {"confirm":"DELETE"}
