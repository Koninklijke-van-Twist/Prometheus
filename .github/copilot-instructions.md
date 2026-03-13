# Copilot Instructions

## Doel
Houd wijzigingen klein, duidelijk en onderhoudbaar.

## Richtlijnen
- Plaats herhaalde berekeningen, herhaalde logica en terugkerende code zoveel mogelijk in generieke functies of helpers.
- Vermijd duplicatie: gebruik bestaande functies waar mogelijk en centraliseer gedeelde businessregels op één plek.
- Pas bij wijzigingen eerst de centrale functie aan en laat alle aanroepen die gebruiken.
- Houd nieuwe code consistent met de bestaande stijl van het project.
- Voeg geen onnodige complexiteit toe; kies de eenvoudigste oplossing die het probleem oplost.
- Voeg op elke pagina netjes de favicon toe
- de deployment kopieert de /web/ folder naar de webserver toe -- /web/ is dus de root van de website
- Zet aanpasbare UI-instellingen (zoals kleuren, statuskleuren, drempels, visualisatie-config) centraal in `web/config.php` in plaats van hardcoded waarden in pagina-bestanden.
- Gebruik voor algemene UI-kleuren (alles met een kleur dat geen eigen status/drempel-config heeft) altijd `web/config.php` -> `colorStyle`.

## PHP Structuurconventie
- Refactor PHP-bestanden naar vaste secties met duidelijke blokcommentaren.
- Gebruik onderstaande secties in exact deze volgorde (namen mogen functioneel gelijkwaardig zijn, zoals `Includes` of `Requires`, maar de volgorde blijft verplicht):
	1. `Includes/requires`
	2. `Constants`
	3. `Variabelen`
	4. `Functies`
	5. `Page load`
- Voeg een sectie alleen toe als die sectie daadwerkelijk inhoud heeft.
- Gebruik exact dit commentaarformaat voor secties:

```php
/**
 * Includes/requires
 */
```

- `Page load` bevat alle top-level uitvoerlogica (code die direct draait buiten functies/classes).
- Plaats herbruikbare logica altijd in functies; houd `Page load` zo dun mogelijk.
- Wijzig bij structuurrefactors geen functionaliteit: alleen ordenen, verplaatsen en labelen.
- Houd bestaande naamgeving, gedrag en output intact tijdens deze refactors.
- Klassenregel: elke class moet in een eigen bestand staan (1 class per file).
- Gebruik bij classes bestandsnamen die overeenkomen met de classnaam (bij voorkeur exact dezelfde naam, inclusief hoofdletters).
- Zet in bestanden met een class alleen class-gerelateerde logica; verplaats page-load routing/uitvoer naar een apart endpoint-bestand dat de class inlaadt.
- Voeg geen lege secties toe om een template op te vullen; alleen secties met echte inhoud zijn toegestaan.
