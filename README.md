# Acronis Tenant Manager

Acronis Tenant Manager is een zelfstandig PHP voorbeeldscript waarmee bestaande Acronis-tenants via de TransIP API kunnen worden beheerd.

Het script bestaat uit één bestand: `index.php`.

Met de webinterface kun je:

- de pakketgrootte, het opslaggebruik en het aantal EDR-endpoints bekijken;
- een Acronis-tenant upgraden of downgraden;
- de storage-add-on toevoegen of verwijderen;
- Acronis EDR toevoegen of verwijderen.

## Waarom dit script?

Het upgraden en downgraden van een bestaande Acronis tenant en het toevoegen of verwijderen van Acronis EDR verloopt momenteel via de API.

De officiële API-documentatie vind je hier:

https://api.transip.nl/rest/docs.html#acronis

## Vereisten

- een webserver met HTTPS;
- PHP 8.0 of nieuwer;
- de PHP-extensies cURL, JSON en Session;
- een TransIP-account waarvoor de API is geactiveerd;
- een geldige Access Token met de juiste IP-whitelisting.

De handleiding voor het activeren van de API en het aanmaken van een Access Token vind je hier:

https://www.transip.nl/knowledgebase/api/77-de-transip-rest-api-gebruiken#De-API-inschakelen-toegang-en-whitelisting

## Installatie

1. Download `index.php` uit deze repository.
2. Plaats het bestand in een afzonderlijke map op je webserver.
3. Open het script via HTTPS.
4. Voer een tijdelijke TransIP Access Token in.
5. Selecteer de gewenste Acronis-tenant.
6. Controleer vóór iedere wijziging de tenantnaam en volledige UUID.
7. Verwijder na gebruik de Access Token via de knop in de interface.
8. Trek de Access Token daarna ook in via het TransIP-controlepaneel.

Het script heeft geen database, Composer-installatie of aanvullende bestanden nodig.

Beperk de toegang tot de map bij voorkeur met webserverauthenticatie, een VPN of een IP-allowlist. Verwijder het script van de webserver wanneer je het niet meer gebruikt.

## Belangrijk bij wijzigingen

Het verwerken van pakket- en add-onwijzigingen kan enige tijd duren.

Voer daarom niet meerdere wijzigingen direct na elkaar uit voor dezelfde tenant. Controleer na iedere aanvraag eerst of de API de nieuwe tenantstatus teruggeeft voordat je een volgende pakket- of add-onwijziging uitvoert.

Pakket- en add-onwijzigingen kunnen gevolgen hebben voor diensten en kosten. Controleer iedere aanvraag zorgvuldig.

## Beveiliging

- De Access Token wordt uitsluitend in de server-side PHP-sessie bewaard.
- De token wordt niet in de URL, HTML, JavaScript of browsercookie opgenomen.
- Wijzigingen gebruiken POST, CSRF-bescherming en eenmalige formuliertokens.
- Tenant-UUID’s, pakketten en add-ons worden server-side gecontroleerd.
- Gebruik bij voorkeur een tijdelijke Access Token met een zo kort mogelijke geldigheidsduur.
- Deel een Access Token nooit via openbare kanalen.
- Trek de Access Token direct in wanneer je klaar bent.

## Eigen verantwoordelijkheid

Dit script is uitsluitend bedoeld als voorbeeld. TransIP BV biedt dit script aan "zoals het is" en is niet aansprakelijk voor eventuele fouten, defecten of andere problemen die voortvloeien uit het gebruik van dit script.

Het gebruik van dit script is op eigen risico. De officiële TransIP API-documentatie is altijd leidend.

## Copyright

Copyright (c) 2026 TransIP BV. Alle rechten voorbehouden.
