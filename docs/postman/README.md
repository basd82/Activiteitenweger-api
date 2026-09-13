# Postman

Deze directory bevat de Postman-collectie voor de Activiteitenweger API.

## Bestand

- `Activiteitenweger-API.postman_collection.json` — stateful smoke/integratietest voor API v1 / server 1.2.0.

## Belangrijk: geen externe npm-packages

De eerdere aanpak met `pm.require('npm:tweetnacl@...')` werkt niet in iedere Postman-installatie. Deze collectie gebruikt daarom dezelfde aanpak als de eerdere werkende regressietest v2: native Web Crypto voor Ed25519/SHA-256 en `require('buffer')` als ingebouwde module.

## Gebruik

Importeer zowel `Activiteitenweger-API.postman_collection.json` als `Activiteitenweger.postman_environment.json` in een actuele Postman-versie en voer de **hele collectie in volgorde** uit met de Collection Runner.

De collectie gebruikt standaard:

```text
https://app.dikkenberg.net
```

als `baseUrl`. Pas de collection variable aan wanneer je een test- of acceptatieomgeving gebruikt.

## Wat de suite test

1. healthcheck, API-versie en serverversie;
2. tijdelijke test-vault aanmaken;
3. Ed25519-signed `/me` en `/devices`;
4. record revision 1 aanmaken;
5. cursor-sync vanaf 0;
6. hetzelfde record naar revision 2 wijzigen;
7. bewust nogmaals revision 2 aanbieden en `409 revision_conflict` eisen;
8. conflictmetadata controleren: current/expected revision, delete-status en server-`updatedAt`;
9. incrementeel synchroniseren vanaf de opgeslagen cursor;
10. revision 3 als tombstone schrijven;
11. tombstone via sync controleren;
12. de tijdelijke test-vault verwijderen.

De requests gebruiken **geen externe npm-packages**. Voor Ed25519-signing en SHA-256 gebruikt de collectie de native Web Crypto API (`crypto.subtle`), plus alleen de ingebouwde Postman/Node-module `buffer`.

## Cryptografische scope

Deze collectie test de **serverlaag**: request signing, record signatures, revisions, cursors, conflictbeveiliging en tombstones.

Voor record-ciphertext gebruikt de suite willekeurige bytes. De API hoort ciphertext immers niet te kunnen ontsleutelen. De daadwerkelijke XChaCha20-Poly1305 encryptie/decryptie van app-payloads wordt in de app/clienttests getest.

## Belangrijk

Voer de collectie als geheel uit. Latere requests gebruiken collection variables die door eerdere requests zijn aangemaakt.

Wanneer een run vóór de laatste request stopt, kan de tijdelijke test-vault blijven bestaan. Start dan een nieuwe volledige run of verwijder de test-vault met de opgeslagen collection variables voordat je die state wist.


## Pairing-suite

De pairing-collectie maakt een tijdelijke owner-vault en controleert in volgorde:

1. R-koppelinvite maken en claimen;
2. R-device kan lezen maar krijgt `403 write_access_required` bij schrijven;
3. RW-koppelinvite maken en claimen;
4. owner ziet alle devices en rechten;
5. owner trekt RW-toegang in;
6. het ingetrokken device krijgt daarna `401 vault_access_revoked`;
7. test-vault wordt opgeruimd.

Ook deze suite gebruikt uitsluitend native Web Crypto en `require('buffer')`; er zijn **geen externe npm-packages** nodig.
