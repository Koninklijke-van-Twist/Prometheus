<?php

/**
 * OData-routing: Mímir als $mimirApi gezet is, BC als de key ontbreekt.
 * Run: php web/tests/test_mimir_odata_routing.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Includes/requires
 */
require_once dirname(__DIR__) . '/odata.php';

/**
 * Variabelen
 */
$failures = 0;
$mockPort = 18947;
$mockLog = sys_get_temp_dir() . '/prometheus-mimir-mock.log';
$mockScript = sys_get_temp_dir() . '/prometheus-mimir-mock.php';
$authPath = dirname(__DIR__) . '/auth.php';

/**
 * Functies
 */
function test_assert(string $name, bool $condition, string $detail = ''): void
{
    global $failures;
    if ($condition) {
        echo "OK  {$name}\n";
        return;
    }

    $failures++;
    echo "FAIL {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function test_write_mock(): void
{
    global $mockScript, $mockLog;
    $log = var_export($mockLog, true);
    $php = <<<'PHP'
<?php
$log = LOG_PATH;
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
file_put_contents($log, json_encode([
    'uri' => $uri,
    'method' => $method,
    'ua' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
    'authorization' => $authorization,
    'api_key' => (string) ($_SERVER['HTTP_X_API_KEY'] ?? ''),
], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
header('Content-Type: application/json');

if (str_contains($uri, '/mimir-redirect/api/')) {
    header('Location: http://127.0.0.1/should-not-follow', true, 302);
    echo json_encode(['error' => 'redirect']);
    exit;
}

if (str_contains($uri, '/mimir/api/companies.php')) {
    echo json_encode(['value' => [
        ['name' => 'Koninklijke van Twist', 'environment' => 'Production'],
        ['name' => "Van Twist's", 'environment' => 'Production'],
        ['name' => 'Hunter van Twist', 'environment' => 'Sandbox'],
    ]]);
    exit;
}

if (str_contains($uri, '/mimir/api/query.php')) {
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        $body = [];
    }
    echo json_encode(['value' => [[
        'No' => 'WO1',
        'Component_No' => 'C-10',
        'company' => (string) ($body['company'] ?? ''),
        'table' => (string) ($body['table'] ?? ''),
        'select' => $body['select'] ?? [],
        'filter' => (string) ($body['filter'] ?? ''),
        'max_age' => $body['max_age'] ?? null,
    ]]]);
    exit;
}

$user = '';
if (str_starts_with($authorization, 'Basic ')) {
    $decoded = base64_decode(substr($authorization, 6), true);
    if (is_string($decoded) && str_contains($decoded, ':')) {
        $user = explode(':', $decoded, 2)[0];
    }
}
echo json_encode(['value' => [[
    'Name' => 'BC Company',
    'via' => 'bc',
    'user' => $user,
]]]);
PHP;
    file_put_contents($mockScript, str_replace('LOG_PATH', $log, $php));
}

/**
 * Zet auth.php terug. Verwijdert het bestand alleen als deze test het zelf heeft aangemaakt.
 */
function test_restore_auth_php(string $path, bool $existedBefore, ?string $backup, bool $written): void
{
    if (!$written) {
        return;
    }

    if ($existedBefore) {
        if (!is_string($backup)) {
            return;
        }
        file_put_contents($path, $backup);
        return;
    }

    @unlink($path);
}

function test_mock_requests(): array
{
    global $mockLog;
    if (!is_file($mockLog)) {
        return [];
    }
    $rows = [];
    foreach (file($mockLog, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $rows[] = $decoded;
        }
    }
    return $rows;
}

function test_wait_for_port(int $port): bool
{
    for ($i = 0; $i < 50; $i++) {
        $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        if (is_resource($fp)) {
            fclose($fp);
            return true;
        }
        usleep(100000);
    }
    return false;
}

/**
 * Page load
 */
test_assert('mimir uit zonder key', odata_mimir_enabled() === false);
test_assert(
    'default Mímir-base',
    odata_mimir_base_url() === 'https://sleutels.kvt.nl/mimir/api'
);

$restoreProbe = sys_get_temp_dir() . '/prometheus-auth-restore-probe.php';
$restoreCreated = sys_get_temp_dir() . '/prometheus-auth-restore-created.php';
file_put_contents($restoreProbe, "<?php\n\$marker = 'original';\n");
test_restore_auth_php($restoreProbe, true, "<?php\n\$marker = 'original';\n", false);
test_assert(
    'restore laat bestaand bestand met rust als de test niet schreef',
    is_file($restoreProbe) && str_contains((string) file_get_contents($restoreProbe), 'original')
);
file_put_contents($restoreProbe, "<?php\n\$marker = 'replaced';\n");
test_restore_auth_php($restoreProbe, true, "<?php\n\$marker = 'original';\n", true);
test_assert(
    'restore zet backup terug nadat de test schreef',
    is_file($restoreProbe) && str_contains((string) file_get_contents($restoreProbe), 'original')
);
@unlink($restoreProbe);
file_put_contents($restoreCreated, "<?php\n\$marker = 'created';\n");
test_restore_auth_php($restoreCreated, false, null, true);
test_assert('restore verwijdert alleen een door de test aangemaakt bestand', !is_file($restoreCreated));
file_put_contents($restoreCreated, "<?php\n\$marker = 'keep';\n");
test_restore_auth_php($restoreCreated, true, null, true);
test_assert(
    'restore wist geen bestaand bestand zonder leesbare backup',
    is_file($restoreCreated) && str_contains((string) file_get_contents($restoreCreated), 'keep')
);
@unlink($restoreCreated);

$base = "http://bc.example/BC/ODataV4/Company('Koninklijke van Twist')/";
$filter = "No eq 'WO1' or No eq '123'";
$spaceUrl = $base . 'AppWerkorders?$select=No,Component_No&$top=1&$filter=' . rawurlencode($filter);
$parsedSpace = odata_mimir_parse_entity_url($spaceUrl);
test_assert(
    'AppWerkorders-URL met spatie in bedrijfsnaam',
    is_array($parsedSpace)
        && ($parsedSpace['company'] ?? '') === 'Koninklijke van Twist'
        && ($parsedSpace['entity'] ?? '') === 'AppWerkorders'
        && ($parsedSpace['query']['$select'] ?? '') === 'No,Component_No'
        && ($parsedSpace['query']['$filter'] ?? '') === $filter,
    json_encode($parsedSpace, JSON_UNESCAPED_UNICODE)
);
test_assert(
    'entity-URL is geen company-discovery',
    odata_mimir_parse_companies_url($spaceUrl) === null
);

$apostropheUrl = "http://bc.example/BC/ODataV4/Company('Van Twist''s')/AppWerkorders?\$select=No";
$parsedApostrophe = odata_mimir_parse_entity_url($apostropheUrl);
test_assert(
    'verdubbelde apostrof in company-prefix',
    is_array($parsedApostrophe)
        && ($parsedApostrophe['company'] ?? '') === "Van Twist's"
        && ($parsedApostrophe['entity'] ?? '') === 'AppWerkorders',
    json_encode($parsedApostrophe, JSON_UNESCAPED_UNICODE)
);

$encodedApostropheUrl = "http://bc.example/Production/ODataV4/Company('Van%20Twist%27%27s')/JobLedgerEntries?\$select=Job_No";
$parsedEncoded = odata_mimir_parse_entity_url($encodedApostropheUrl);
test_assert(
    'geëncodeerde apostrof in company-segment',
    is_array($parsedEncoded) && ($parsedEncoded['company'] ?? '') === "Van Twist's",
    json_encode($parsedEncoded, JSON_UNESCAPED_UNICODE)
);

$companiesUrl = 'https://bc.example/Production/ODataV4/Companies?$select=Name';
$parsedCompanies = odata_mimir_parse_companies_url($companiesUrl);
test_assert(
    'companies-URL levert environment',
    is_array($parsedCompanies) && ($parsedCompanies['environment'] ?? '') === 'Production',
    json_encode($parsedCompanies)
);
test_assert('companies-URL is geen entity', odata_mimir_parse_entity_url($companiesUrl) === null);

$companyUrl = 'https://bc.example/Sandbox/ODataV4/Company?$select=Name';
$parsedCompany = odata_mimir_parse_companies_url($companyUrl);
test_assert(
    'Company-discovery-URL levert environment',
    is_array($parsedCompany) && ($parsedCompany['environment'] ?? '') === 'Sandbox',
    json_encode($parsedCompany)
);

test_write_mock();
@unlink($mockLog);
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $mockPort, $mockScript],
    [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ],
    $pipes,
    sys_get_temp_dir()
);
$serverReady = is_resource($server) && test_wait_for_port($mockPort);
test_assert('mock-server start', $serverReady);

$mimirApi = 'mimir_test_key';
$mimirBase = 'http://127.0.0.1:' . $mockPort . '/mimir/api';
unset($GLOBALS['auth_list'], $GLOBALS['environment'], $GLOBALS['auth'], $GLOBALS['baseUrl'], $GLOBALS['base']);

$authExistedBefore = is_file($authPath);
$authBackup = null;
if ($authExistedBefore) {
    $authRaw = file_get_contents($authPath);
    $authBackup = is_string($authRaw) ? $authRaw : null;
}
$authWritten = false;
$createdCache = [];

try {
    test_assert('Mímir aan met key en zonder BC-globals', odata_mimir_enabled() === true);

    $untranslated = false;
    try {
        odata_get_all('AppWerkorders?$select=No', [], 30);
    } catch (Exception $error) {
        $untranslated = str_contains($error->getMessage(), 'kon niet worden vertaald');
    }
    test_assert('URL zonder company-segment faalt duidelijk', $untranslated);

    $beforeCache = glob(dirname(__DIR__) . '/cache/odata/*.json') ?: [];
    $rows = odata_get_all($spaceUrl, [], 60);
    test_assert(
        'AppWerkorders via Mímir zonder BC-creds',
        is_array($rows[0] ?? null)
            && ($rows[0]['company'] ?? '') === 'Koninklijke van Twist'
            && ($rows[0]['table'] ?? '') === 'AppWerkorders'
            && ($rows[0]['filter'] ?? '') === $filter
            && ($rows[0]['max_age'] ?? null) === 60
            && ($rows[0]['Component_No'] ?? '') === 'C-10'
            && in_array('No', $rows[0]['select'] ?? [], true)
            && in_array('Component_No', $rows[0]['select'] ?? [], true),
        json_encode($rows, JSON_UNESCAPED_UNICODE)
    );

    $zeroTtl = odata_get_all($spaceUrl, [], 0);
    test_assert(
        'ttl 0 wordt max_age 3600',
        ($zeroTtl[0]['max_age'] ?? null) === 3600,
        json_encode($zeroTtl, JSON_UNESCAPED_UNICODE)
    );

    $afterCache = glob(dirname(__DIR__) . '/cache/odata/*.json') ?: [];
    test_assert('Mímir slaat Prometheus-filecache over', count($afterCache) === count($beforeCache));

    $companyRows = odata_get_all('https://bc.example/Sandbox/ODataV4/Company?$select=Name', [], 30);
    $companyNames = array_map(static function (array $row): string {
        return (string) ($row['Name'] ?? '');
    }, $companyRows);
    test_assert(
        'company-discovery-URL gaat naar Mímir, niet naar BC-host',
        $companyNames === ['Hunter van Twist'],
        json_encode($companyNames, JSON_UNESCAPED_UNICODE)
    );

    $productionRows = odata_get_all('https://bc.example/Production/ODataV4/Companies?$select=Name', [], 30);
    $productionNames = array_map(static function (array $row): string {
        return (string) ($row['Name'] ?? '');
    }, $productionRows);
    test_assert(
        'Companies-URL filtert op environment',
        $productionNames === ['Koninklijke van Twist', "Van Twist's"],
        json_encode($productionNames, JSON_UNESCAPED_UNICODE)
    );

    $savedBase = $mimirBase;
    $mimirBase = 'http://127.0.0.1:' . $mockPort . '/mimir-redirect/api';
    $redirectThrew = false;
    try {
        odata_mimir_companies_as_rows(null);
    } catch (Exception $error) {
        $redirectThrew = str_contains($error->getMessage(), 'HTTP 302');
    }
    $mimirBase = $savedBase;
    test_assert('Mímir-redirect wordt niet gevolgd', $redirectThrew);

    $requests = test_mock_requests();
    $hitBcHost = false;
    $sawMimirUa = false;
    $sawApiKey = false;
    foreach ($requests as $request) {
        if (str_contains((string) ($request['uri'] ?? ''), 'bc.example')) {
            $hitBcHost = true;
        }
        if (($request['ua'] ?? '') === 'Prometheus-MimirClient/1.0' && str_contains((string) ($request['uri'] ?? ''), '/mimir/api/')) {
            $sawMimirUa = true;
        }
        if (($request['api_key'] ?? '') === 'mimir_test_key' && str_starts_with((string) ($request['authorization'] ?? ''), 'Bearer mimir_test_key')) {
            $sawApiKey = true;
        }
    }
    test_assert('geen request naar de BC-host', $hitBcHost === false);
    test_assert('Mímir-client user-agent', $sawMimirUa);
    test_assert('Mímir-key als Bearer en X-API-Key', $sawApiKey);

    $mimirApi = '';
    $environment = 'Production';
    $auth = [
        'mode' => 'basic',
        'user' => 'bcuser',
        'pass' => 'bcpass',
    ];
    if ($authExistedBefore && !is_string($authBackup)) {
        throw new RuntimeException('Bestaande auth.php kon niet worden gelezen; test wijzigt het bestand niet.');
    }
    $authWritten = true;
    file_put_contents($authPath, "<?php\n\$environment = 'Production';\n\$mimirApi = '';\n");
    @unlink($mockLog);

    test_assert('Mímir uit na lege key', odata_mimir_enabled() === false);
    $bcBase = 'http://127.0.0.1:' . $mockPort . "/BC/ODataV4/Company('KVT')/";
    $bcUrl = $bcBase . 'AppWerkorders?$select=No,Component_No&$top=1&$filter=' . rawurlencode("No eq 'WO1'");
    $bcBefore = glob(dirname(__DIR__) . '/cache/odata/*.json') ?: [];
    $bcRows = odata_get_all($bcUrl, $auth, 30);
    $bcAfter = glob(dirname(__DIR__) . '/cache/odata/*.json') ?: [];
    $createdCache = array_values(array_diff($bcAfter, $bcBefore));
    test_assert(
        'zonder Mímir blijft BC-fetch werken',
        is_array($bcRows[0] ?? null) && ($bcRows[0]['via'] ?? '') === 'bc' && ($bcRows[0]['user'] ?? '') === 'bcuser',
        json_encode($bcRows)
    );
    $bcRequests = test_mock_requests();
    $bcHitMimir = false;
    $bcHitEntity = false;
    foreach ($bcRequests as $request) {
        $uri = (string) ($request['uri'] ?? '');
        if (str_contains($uri, '/mimir/')) {
            $bcHitMimir = true;
        }
        if (str_contains($uri, '/AppWerkorders')) {
            $bcHitEntity = true;
        }
    }
    test_assert('BC-fetch raakt Mímir niet', $bcHitMimir === false);
    test_assert('BC-fetch gaat naar de company-URL', $bcHitEntity);
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    test_restore_auth_php($authPath, $authExistedBefore, $authBackup, $authWritten);
    foreach ($createdCache as $cacheFile) {
        if (is_string($cacheFile) && is_file($cacheFile)) {
            @unlink($cacheFile);
        }
    }
    @unlink($mockScript);
    @unlink($mockLog);
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} test(s) failed\n");
    exit(1);
}

echo "all mimir routing tests passed\n";
exit(0);
