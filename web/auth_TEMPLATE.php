<?php
/**
 * Auth-template voor Prometheus.
 *
 * Mímir heeft voorrang. Laat de Business Central-gegevens hier altijd naast staan:
 * als Mímir uitvalt, haalt Prometheus dezelfde data direct bij BC op.
 *
 *   $mimirApi  = 'mimir_…';  // verplicht om Mímir te activeren
 *   $mimirBase = 'https://sleutels.kvt.nl/mimir/api'; // optioneel
 *
 * Met $mimirApi gezet gaan fetches eerst naar Mímir en daarna naar de BC-variabelen
 * hieronder. Zonder $mimirApi wordt alleen de BC-route gebruikt.
 */

// --- Mímir (aanbevolen) ---
// $mimirApi  = 'mimir_…';
// $mimirBase = 'https://sleutels.kvt.nl/mimir/api';

// --- Business Central (directe route, én fallback als Mímir faalt) ---
// $base is de company-prefix die index.php gebruikt. $auth en $environment
// horen daarbij. $baseUrl + $auth_list worden ook geaccepteerd.
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD'];
$auth_list = [
    'Production' => $auth,
];
$baseUrl = 'https://my-bc-domain.com:7148/';
$base = $baseUrl . $environment . "/ODataV4/Company('Bedrijfsnaam')/";
