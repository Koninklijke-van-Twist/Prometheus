# Prometheus

## Mímir

Zet in `web/auth.php` (niet in git), naast elkaar:

```php
$mimirApi  = 'mimir_…';
// optioneel:
$mimirBase = 'https://sleutels.kvt.nl/mimir/api';

// Business Central blijft verplicht naast $mimirApi (fallback als Mímir uitvalt):
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => '…', 'pass' => '…'];
$base = "https://my-bc-domain.com:7148/Production/ODataV4/Company('Bedrijfsnaam')/";
// ook geldig: $baseUrl en $auth_list
```

Met `$mimirApi` gezet gaan OData-fetches eerst naar Mímir. Faalt die aanroep (verbinding/timeout, non-2xx, ongeldige JSON of een Mímir-foutpayload), dan haalt Prometheus dezelfde data op via de oude Business Central-route (`$base` of `$baseUrl`, `$auth` / `$auth_list`, `$environment`, lokale odata-filecache) en slaat Mímir voor de rest van dat PHP-proces over. Laat die BC-gegevens in `auth.php` naast `$mimirApi` staan; ontbreken ze, dan komt de oorspronkelijke Mímir-fout terug. Zonder `$mimirApi` blijft alleen de directe BC-route actief.

Dit geldt voor gewone webverzoeken (`web/index.php` laadt `auth.php` vóór `odata.php`) en voor CLI/cron. Er is geen apart nightly-script; een CLI-entry moet dezelfde `web/auth.php` laden. De Mímir-timeout is 90 seconden onder de web-SAPI en 600 seconden onder `cli` (`php_sapi_name` / `PHP_SAPI`).
