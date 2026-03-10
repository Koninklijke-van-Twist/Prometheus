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
