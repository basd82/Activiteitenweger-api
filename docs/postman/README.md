# Postman

Deze directory bevat de Postman-collectie voor de Activiteitenweger API.

## Bestand

- `Activiteitenweger-API.postman_collection.json` — stateful smoke/integratietest voor API v1 / server 1.1.0.

## Gebruik

Importeer de collectie in een actuele Postman-versie en voer de **hele collectie in volgorde** uit met de Collection Runner.

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

De requests gebruiken Postman scripts en `pm.require('npm:tweetnacl@1.0.3')` voor Ed25519-signing. Postman ondersteunt externe npm-packages via `pm.require`.

## Cryptografische scope

Deze collectie test de **serverlaag**: request signing, record signatures, revisions, cursors, conflictbeveiliging en tombstones.

Voor record-ciphertext gebruikt de suite willekeurige bytes. De API hoort ciphertext immers niet te kunnen ontsleutelen. De daadwerkelijke XChaCha20-Poly1305 encryptie/decryptie van app-payloads wordt in de app/clienttests getest.

## Belangrijk

Voer de collectie als geheel uit. Latere requests gebruiken collection variables die door eerdere requests zijn aangemaakt.

Wanneer een run vóór de laatste request stopt, kan de tijdelijke test-vault blijven bestaan. Start dan een nieuwe volledige run of verwijder de test-vault met de opgeslagen collection variables voordat je die state wist.
