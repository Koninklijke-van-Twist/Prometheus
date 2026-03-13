<?php
declare(strict_types=1);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/**
 * Includes/requires
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . "/logincheck.php";
require_once __DIR__ . "/odata.php";
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/sample_repository.php';

/**
 * Variabelen
 */
$second = 1;
$minute = $second * 60;
$hour = $minute * 60;
$day = $hour * 24;

$ttl = $hour;

/**
 * Functies
 */

function uiColor(string $key, string $fallback): string
{
    $config = $GLOBALS['prometheusConfig']['colorStyle'] ?? [];
    $value = $config[$key] ?? $fallback;

    return is_string($value) && trim($value) !== '' ? $value : $fallback;
}

function reportStatusMeta(string $status): array
{
    $normalized = strtolower(trim($status));
    $config = $GLOBALS['prometheusConfig'] ?? [];
    $styles = $config['statusStyles'] ?? [];
    $unknown = $config['unknownStatusStyle'] ?? [
        'label' => 'Onbekend',
        'background' => '#edf1f2',
        'text' => '#405558',
        'border' => '#c8d4d6',
    ];

    if ($normalized !== '' && isset($styles[$normalized]) && is_array($styles[$normalized])) {
        $style = $styles[$normalized];

        return [
            'key' => $normalized,
            'label' => (string) ($style['label'] ?? $status),
            'borderColor' => (string) ($style['border'] ?? '#c8d4d6'),
            'inlineStyle' => 'background:' . ($style['background'] ?? '#edf1f2')
                . ';color:' . ($style['text'] ?? '#405558')
                . ';border-color:' . ($style['border'] ?? '#c8d4d6')
                . ';',
        ];
    }

    return [
        'key' => '__unknown__',
        'label' => $status !== '' ? $status : (string) ($unknown['label'] ?? 'Onbekend'),
        'borderColor' => (string) ($unknown['border'] ?? '#c8d4d6'),
        'inlineStyle' => 'background:' . ($unknown['background'] ?? '#edf1f2')
            . ';color:' . ($unknown['text'] ?? '#405558')
            . ';border-color:' . ($unknown['border'] ?? '#c8d4d6')
            . ';',
    ];
}

function extractReportYearFromFile(string $fileName): ?int
{
    $baseName = basename($fileName);
    if (!preg_match('/\[?(\d{10,13})\]?(?=\.json$)/i', $baseName, $matches)) {
        return null;
    }

    $timestampRaw = $matches[1] ?? '';
    if ($timestampRaw === '' || !ctype_digit($timestampRaw)) {
        return null;
    }

    $timestamp = (int) $timestampRaw;
    if (strlen($timestampRaw) === 13) {
        $timestamp = (int) floor($timestamp / 1000);
    }

    if ($timestamp <= 0) {
        return null;
    }

    return (int) date('Y', $timestamp);
}

function formatDutchDateHeader(string $input, string $fallback = ''): string
{
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $input);
    if (!$dt) {
        return $fallback !== '' ? $fallback : $input;
    }

    $months = [
        1 => 'januari',
        2 => 'februari',
        3 => 'maart',
        4 => 'april',
        5 => 'mei',
        6 => 'juni',
        7 => 'juli',
        8 => 'augustus',
        9 => 'september',
        10 => 'oktober',
        11 => 'november',
        12 => 'december',
    ];

    $monthIndex = (int) $dt->format('n');
    $monthName = $months[$monthIndex] ?? $dt->format('m');

    return $dt->format('j') . ' ' . $monthName . ' ' . $dt->format('Y');
}

function cardSearchPayload(array $item, array $reportStatus, string $headerDate): string
{
    $parts = [
        (string) ($item['sampleId'] ?? ''),
        (string) ($item['sampler'] ?? ''),
        (string) ($reportStatus['label'] ?? ''),
        !empty($item['actionRequired']) ? 'action required' : '',
        (string) ($item['accountDisplay'] ?? ($item['accountName'] ?? '')),
        (string) ($item['componentNumber'] ?? ''),
        (string) ($item['workOrder'] ?? ''),
        (string) ($item['contaminationRating'] ?? ''),
        $headerDate,
    ];

    return strtolower(trim(implode(' ', $parts)));
}

function inProgressAccountLine(array $item): string
{
    $name = trim((string) ($item['accountName'] ?? ''));
    $id = trim((string) ($item['accountId'] ?? ''));

    if ($name !== '' && $id !== '') {
        return $name . ' (' . $id . ')';
    }

    if ($name !== '') {
        return $name;
    }

    if ($id !== '') {
        return $id;
    }

    return '-';
}

function inProgressSinceLine(array $item): string
{
    $registered = trim((string) ($item['dateRegisteredRaw'] ?? ''));
    if ($registered === '') {
        $registered = trim((string) ($item['dateDisplay'] ?? '-'));
    }

    $days = (int) ($item['daysSinceSampled'] ?? 0);
    if ($days <= 0) {
        return $registered . ' - (onbekend sinds gesampled)';
    }

    $weeks = (int) floor($days / 7);
    if ($weeks >= 1) {
        return $registered . ' - (' . $days . ' dagen / ' . $weeks . ' weken geleden gesampled)';
    }

    return $registered . ' - (' . $days . ' dagen geleden gesampled)';
}

function getMobilCacheDirFromConfig(): string
{
    $config = $GLOBALS['mobilApiAuth'] ?? [];
    if (!is_array($config)) {
        $config = [];
    }

    $cacheDir = isset($config['cacheDir']) ? trim((string) $config['cacheDir']) : '';
    if ($cacheDir !== '') {
        return $cacheDir;
    }

    $inProgressCacheFile = isset($config['inProgressCacheFile']) ? trim((string) $config['inProgressCacheFile']) : '';
    if ($inProgressCacheFile !== '') {
        return dirname($inProgressCacheFile);
    }

    return __DIR__ . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'mobil';
}


function getDailyFetchStatePath(): string
{
    return rtrim(getMobilCacheDirFromConfig(), "\\/") . DIRECTORY_SEPARATOR . 'index_daily_fetch_state.json';
}

function readJsonStateFile(string $path): array
{
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function writeJsonStateFile(string $path, array $payload): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    $tmp = $path . '.tmp';
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return;
    }

    file_put_contents($tmp, $json);
    @rename($tmp, $path);
}

function buildMobilFetchUrl(): string
{
    $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '/web/index.php');
    $scriptDir = str_replace('\\', '/', dirname($scriptName));
    $scriptDir = rtrim($scriptDir, '/');
    if ($scriptDir === '' || $scriptDir === '.') {
        $scriptDir = '/';
    }

    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '127.0.0.1');
    $basePath = rtrim($scriptDir, '/');

    return $scheme . '://' . $host . $basePath . '/mobilapi.php?action=fetch';
}

function executeFetchAction(string $url): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'message' => 'Kon cURL niet initialiseren.'];
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        $responseBody = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false) {
            return [
                'ok' => false,
                'message' => 'Fetch-call mislukt: ' . ($curlError !== '' ? $curlError : 'onbekende cURL-fout'),
                'httpCode' => $httpCode,
                'responseBody' => '',
            ];
        }

        if ($httpCode >= 400) {
            return [
                'ok' => false,
                'message' => 'Fetch-call gaf HTTP ' . $httpCode . '.',
                'httpCode' => $httpCode,
                'responseBody' => (string) $responseBody,
            ];
        }

        $decoded = json_decode((string) $responseBody, true);
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'message' => 'Fetch-call gaf geen geldige JSON terug.',
                'httpCode' => $httpCode,
                'responseBody' => (string) $responseBody,
            ];
        }

        $status = strtolower(trim((string) ($decoded['status'] ?? '')));
        if ($status !== '' && $status !== 'ok') {
            return [
                'ok' => false,
                'message' => (string) ($decoded['message'] ?? 'Onbekende fout bij fetch.'),
                'httpCode' => $httpCode,
                'responseBody' => (string) $responseBody,
            ];
        }

        return [
            'ok' => true,
            'message' => 'Dagelijkse fetch uitgevoerd.',
            'httpCode' => $httpCode,
            'responseBody' => (string) $responseBody,
        ];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 300,
            'ignore_errors' => true,
        ],
    ]);
    $responseBody = @file_get_contents($url, false, $context);
    if ($responseBody === false) {
        return [
            'ok' => false,
            'message' => 'Fetch-call mislukt (geen cURL en file_get_contents faalde).',
            'httpCode' => 0,
            'responseBody' => '',
        ];
    }

    $decoded = json_decode($responseBody, true);
    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'message' => 'Fetch-call gaf geen geldige JSON terug.',
            'httpCode' => 0,
            'responseBody' => (string) $responseBody,
        ];
    }

    $status = strtolower(trim((string) ($decoded['status'] ?? '')));
    if ($status !== '' && $status !== 'ok') {
        return [
            'ok' => false,
            'message' => (string) ($decoded['message'] ?? 'Onbekende fout bij fetch.'),
            'httpCode' => 0,
            'responseBody' => (string) $responseBody,
        ];
    }

    return [
        'ok' => true,
        'message' => 'Dagelijkse fetch uitgevoerd.',
        'httpCode' => 0,
        'responseBody' => (string) $responseBody,
    ];
}

function ensureDailyFetchExecuted(): array
{
    $today = (new DateTimeImmutable('now'))->format('Y-m-d');
    $statePath = getDailyFetchStatePath();
    $lockPath = $statePath . '.lock';

    $lockHandle = @fopen($lockPath, 'c+');
    if ($lockHandle !== false) {
        @flock($lockHandle, LOCK_EX);
    }

    try {
        $state = readJsonStateFile($statePath);
        if (($state['last_success_date'] ?? '') === $today) {
            return ['attempted' => false, 'ok' => true, 'message' => 'Dagelijkse fetch was al succesvol uitgevoerd.'];
        }

        $attempt = (int) ($state['last_attempt_number'] ?? 0) + 1;
        $fetchResult = executeFetchAction(buildMobilFetchUrl());

        $newState = [
            'last_attempt_date' => $today,
            'last_attempt_at' => date('c'),
            'last_attempt_ok' => !empty($fetchResult['ok']),
            'last_attempt_message' => (string) ($fetchResult['message'] ?? ''),
            'last_attempt_number' => $attempt,
        ];

        if (!empty($fetchResult['ok'])) {
            $newState['last_success_date'] = $today;
            $newState['last_success_at'] = date('c');
        }

        writeJsonStateFile($statePath, $newState);

        return [
            'attempted' => true,
            'ok' => !empty($fetchResult['ok']),
            'message' => (string) ($fetchResult['message'] ?? ''),
            'attempt' => $attempt,
            'httpCode' => (int) ($fetchResult['httpCode'] ?? 0),
            'responseBody' => (string) ($fetchResult['responseBody'] ?? ''),
        ];
    } finally {
        if ($lockHandle !== false) {
            @flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }
}

/**
 * Page load
 */
ob_start();
register_shutdown_function(function () {
    $error = error_get_last();
    if (!$error || ($error['type'] ?? 0) !== E_ERROR) {
        return;
    }

    $message = (string) ($error['message'] ?? '');
    $isTimeout = stripos($message, 'Maximum execution time') !== false
        && stripos($message, '120') !== false
        && stripos($message, 'second') !== false;

    if (!$isTimeout) {
        return;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $refreshUrl = htmlspecialchars((string) ($_SERVER['REQUEST_URI'] ?? 'overzicht.php'), ENT_QUOTES, 'UTF-8');
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Retry-After: 5');

    echo '<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta http-equiv="refresh" content="5;url=' . $refreshUrl . '">';
    echo '<title>Even geduld</title></head><body style="font-family:Verdana,Geneva,Tahoma,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0">';
    echo '<div style="text-align:center;padding:24px">Er is meer tijd nodig om gegevens te laden.<br>De pagina wordt automatisch vernieuwd...</div>';
    echo '<script>setTimeout(function(){location.reload();},5000);</script>';
    echo '</body></html>';
});

$dailyFetch = ensureDailyFetchExecuted();
$dailyFetchWarning = '';
$dailyFetchRetryUrl = (string) ($_SERVER['REQUEST_URI'] ?? 'index.php');
if (empty($dailyFetch['ok'])) {
    $dailyFetchWarning = 'onderhanden analyses nog niet binnengehaald';
}

$samplePathResolved = getConfiguredSamplePath();
$summaries = loadSampleSummaries($samplePathResolved);

$availableYearMap = [];
foreach ($summaries as $index => $summary) {
    $year = extractReportYearFromFile((string) ($summary['file'] ?? ''));
    $summaries[$index]['fileYear'] = $year;
    if ($year !== null) {
        $availableYearMap[$year] = true;
    }
}

$availableYears = array_map('intval', array_keys($availableYearMap));
rsort($availableYears);
$latestYear = !empty($availableYears) ? max($availableYears) : null;

$requestedYearRaw = null;
if (isset($_GET['jaar']) && is_string($_GET['jaar'])) {
    $requestedYearRaw = trim($_GET['jaar']);
} elseif (isset($_GET['year']) && is_string($_GET['year'])) {
    // Backward compatibility for old links.
    $requestedYearRaw = trim($_GET['year']);
}

$selectedYear = $latestYear;
if ($requestedYearRaw !== null && $requestedYearRaw !== '' && ctype_digit($requestedYearRaw)) {
    $requestedYear = (int) $requestedYearRaw;
    if (in_array($requestedYear, $availableYears, true)) {
        $selectedYear = $requestedYear;
    }
}

$visibleSummaries = $summaries;
if ($selectedYear !== null) {
    $visibleSummaries = array_values(array_filter($summaries, static function (array $summary) use ($selectedYear): bool {
        return (int) ($summary['fileYear'] ?? 0) === $selectedYear;
    }));
}

$statusFilterMap = [];
$hasActionRequired = false;
foreach ($visibleSummaries as $summary) {
    $statusMeta = reportStatusMeta((string) ($summary['reportStatus'] ?? ''));
    $statusFilterMap[$statusMeta['key']] = [
        'key' => $statusMeta['key'],
        'label' => $statusMeta['label'],
        'inlineStyle' => $statusMeta['inlineStyle'],
    ];

    if (!empty($summary['actionRequired'])) {
        $hasActionRequired = true;
    }
}
$statusFilters = array_values($statusFilterMap);
usort($statusFilters, static function (array $a, array $b): int {
    return strcasecmp((string) $a['label'], (string) $b['label']);
});

$groups = groupSummariesByDate($visibleSummaries);
$currentYear = (int) date('Y');
$inProgressSummaries = [];
if ($selectedYear === $currentYear) {
    $inProgressCacheFile = getConfiguredInProgressCachePath();
    $inProgressSummaries = loadInProgressSummariesFromCache($inProgressCacheFile);
}

$currentUserEmail = '';
if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
    $currentUserEmail = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
}

$pathOk = $samplePathResolved !== '' && is_dir($samplePathResolved);

$ui = [
    'bg' => uiColor('bg', '#f5f4ef'),
    'ink' => uiColor('ink', '#122229'),
    'brand' => uiColor('brand', '#0f5f57'),
    'brandSoft' => uiColor('brandSoft', '#d8ede7'),
    'line' => uiColor('line', '#cfd9d6'),
    'card' => uiColor('card', '#ffffff'),
    'muted' => uiColor('muted', '#4b605f'),
    'okBackground' => uiColor('okBackground', '#e6f6ed'),
    'okBorder' => uiColor('okBorder', '#b9dec9'),
    'okText' => uiColor('okText', '#21583d'),
    'pageGlowOne' => uiColor('pageGlowOne', '#e5efe9'),
    'pageGlowTwo' => uiColor('pageGlowTwo', '#e8ede0'),
    'heroGradientStart' => uiColor('heroGradientStart', '#0f5f57'),
    'heroGradientEnd' => uiColor('heroGradientEnd', '#174f80'),
    'heroBorder' => uiColor('heroBorder', '#24595a'),
    'groupTitle' => uiColor('groupTitle', '#274d4a'),
    'cardHoverBorder' => uiColor('cardHoverBorder', '#8bb6ac'),
    'cardHoverShadow' => uiColor('cardHoverShadow', 'rgba(24, 51, 49, 0.09)'),
    'cardTopAccentDefault' => uiColor('cardTopAccentDefault', '#d5e0dd'),
    'warnBackground' => uiColor('warnBackground', '#fff6e3'),
    'warnBorder' => uiColor('warnBorder', '#efdbaf'),
    'warnText' => uiColor('warnText', '#66522b'),
    'actionChipBackground' => uiColor('actionChipBackground', '#fff1dd'),
    'actionChipText' => uiColor('actionChipText', '#8a4a10'),
    'actionChipBorder' => uiColor('actionChipBorder', '#eac08d'),
];
?>
<!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Prometheus | Overzicht oliesamples</title>
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
    <link rel="manifest" href="site.webmanifest">
    <link rel="icon" href="favicon.ico">
    <style>
        :root {
            --bg:
                <?= htmlspecialchars($ui['bg'], ENT_QUOTES, 'UTF-8') ?>
            ;
            --ink:
                <?= htmlspecialchars($ui['ink'], ENT_QUOTES, 'UTF-8') ?>
            ;
            --brand:
                <?= htmlspecialchars($ui['brand'], ENT_QUOTES, 'UTF-8') ?>
            ;
            --brand-soft:
                <?= htmlspecialchars($ui['brandSoft'], ENT_QUOTES, 'UTF-8') ?>
            ;
            --line:
                <?= htmlspecialchars($ui['line'], ENT_QUOTES, 'UTF-8') ?>
            ;
            --card:
                <?= htmlspecialchars($ui['card'], ENT_QUOTES, 'UTF-8') ?>
            ;
            --muted:
                <?= htmlspecialchars($ui['muted'], ENT_QUOTES, 'UTF-8') ?>
            ;
            --ok:
                <?= htmlspecialchars($ui['okBackground'], ENT_QUOTES, 'UTF-8') ?>
            ;
            --card-top-accent-default:
                <?= htmlspecialchars($ui['cardTopAccentDefault'], ENT_QUOTES, 'UTF-8') ?>
            ;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: "Segoe UI", "Trebuchet MS", sans-serif;
            color: var(--ink);
            background:
                radial-gradient(circle at 10% -5%,
                    <?= htmlspecialchars($ui['pageGlowOne'], ENT_QUOTES, 'UTF-8') ?>
                    0, transparent 35%),
                radial-gradient(circle at 90% 0%,
                    <?= htmlspecialchars($ui['pageGlowTwo'], ENT_QUOTES, 'UTF-8') ?>
                    0, transparent 30%),
                var(--bg);
        }

        .wrap {
            max-width: 1300px;
            margin: 0 auto;
            padding: 20px 16px 40px;
        }

        .top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 14px 16px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .brand img {
            height: 42px;
            width: auto;
        }

        .meta {
            color: var(--muted);
            font-size: 14px;
            text-align: right;
        }

        .hero {
            margin-top: 18px;
            background: linear-gradient(115deg,
                    <?= htmlspecialchars($ui['heroGradientStart'], ENT_QUOTES, 'UTF-8') ?>
                    0%,
                    <?= htmlspecialchars($ui['heroGradientEnd'], ENT_QUOTES, 'UTF-8') ?>
                    100%);
            color: #fff;
            border-radius: 14px;
            padding: 22px 20px;
            border: 1px solid
                <?= htmlspecialchars($ui['heroBorder'], ENT_QUOTES, 'UTF-8') ?>
            ;
        }

        .hero h1 {
            margin: 0 0 6px;
            font-size: clamp(1.3rem, 2vw, 1.9rem);
        }

        .hero p {
            margin: 0;
            opacity: 0.92;
        }

        .filters {
            margin-top: 14px;
            background: var(--card);
            border: 1px solid var(--line);
            border-top-width: 4px;
            border-radius: 12px;
            padding: 12px;
        }

        .filters-head {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            justify-content: space-between;
        }

        .filter-controls {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
            min-width: 900px;
        }

        .filters-head label {
            font-size: 13px;
            color: var(--muted);
            margin-right: 6px;
        }

        .filters-head select {
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 5px 8px;
            background: #fff;
            color: var(--ink);
            font: inherit;
        }

        .filters-head input[type="search"] {
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 6px 10px;
            background: #fff;
            color: var(--ink);
            font: inherit;
            min-width: min(860px, 100%);
        }

        .filter-list {
            margin-top: 10px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px 12px;
        }

        .filter-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            color: var(--ink);
            white-space: nowrap;
        }

        .filter-item input {
            margin: 0;
        }

        .filter-item .chip {
            font-size: 12px;
            line-height: 1.1;
        }

        .filters-hint {
            margin-top: 8px;
            font-size: 12px;
            color: var(--muted);
        }

        .group {
            margin-top: 24px;
        }

        .group h2 {
            margin: 0 0 10px;
            font-size: 1.05rem;
            color:
                <?= htmlspecialchars($ui['groupTitle'], ENT_QUOTES, 'UTF-8') ?>
            ;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 12px;
        }

        .card {
            display: block;
            text-decoration: none;
            color: inherit;
            background: var(--card);
            border: 1px solid var(--line);
            border-top-width: 4px;
            border-top-color: var(--card-top-accent, var(--card-top-accent-default));
            border-radius: 12px;
            padding: 12px;
            padding-bottom: 36px;
            position: relative;
            transition: transform 120ms ease, box-shadow 120ms ease, border-color 120ms ease;
        }

        .card:hover {
            transform: translateY(-2px);
            border-color:
                <?= htmlspecialchars($ui['cardHoverBorder'], ENT_QUOTES, 'UTF-8') ?>
            ;
            box-shadow: 0 8px 20px
                <?= htmlspecialchars($ui['cardHoverShadow'], ENT_QUOTES, 'UTF-8') ?>
            ;
        }

        .card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 8px;
        }

        .sample-title {
            display: flex;
            align-items: center;
            gap: 7px;
            flex-wrap: wrap;
        }

        .sampler-tag {
            font-size: 11px;
            border: 1px solid var(--line);
            border-radius: 999px;
            padding: 2px 8px;
            color: var(--muted);
            background: #fff;
        }

        .sampler-tag.match {
            background: var(--brand-soft);
            border-color: var(--brand);
            color: var(--brand);
            font-weight: 700;
        }

        .sampler-corner {
            position: absolute;
            right: 12px;
            bottom: 10px;
        }

        .chip {
            font-size: 12px;
            background: var(--ok);
            border: 1px solid
                <?= htmlspecialchars($ui['okBorder'], ENT_QUOTES, 'UTF-8') ?>
            ;
            border-radius: 999px;
            padding: 3px 8px;
            color:
                <?= htmlspecialchars($ui['okText'], ENT_QUOTES, 'UTF-8') ?>
            ;
            white-space: nowrap;
        }

        .chip-stack {
            display: flex;
            flex-direction: row;
            gap: 6px;
            align-items: center;
        }

        .chip-action {
            background:
                <?= htmlspecialchars($ui['actionChipBackground'], ENT_QUOTES, 'UTF-8') ?>
            ;
            color:
                <?= htmlspecialchars($ui['actionChipText'], ENT_QUOTES, 'UTF-8') ?>
            ;
            border: 1px solid
                <?= htmlspecialchars($ui['actionChipBorder'], ENT_QUOTES, 'UTF-8') ?>
            ;
        }

        .card strong {
            font-size: 1.02rem;
        }

        .row {
            margin-top: 6px;
            font-size: 0.92rem;
            color: var(--muted);
        }

        .warn {
            margin-top: 16px;
            background:
                <?= htmlspecialchars($ui['warnBackground'], ENT_QUOTES, 'UTF-8') ?>
            ;
            border: 1px solid
                <?= htmlspecialchars($ui['warnBorder'], ENT_QUOTES, 'UTF-8') ?>
            ;
            color:
                <?= htmlspecialchars($ui['warnText'], ENT_QUOTES, 'UTF-8') ?>
            ;
            border-radius: 10px;
            padding: 10px 12px;
        }

        .warn-action {
            display: inline-block;
            margin-left: 8px;
            color: inherit;
            font-weight: 700;
            text-decoration: underline;
        }

        .hidden {
            display: none !important;
        }

        @media (max-width: 700px) {
            .top {
                align-items: flex-start;
                flex-direction: column;
            }

            .meta {
                text-align: left;
            }
        }
    </style>
</head>

<body>
    <div class="wrap">
        <header class="top">
            <div class="brand">
                <img src="images/kvtlogo_l.png" alt="Koninklijke van Twist logo">
                <div>
                    <div><strong>Prometheus</strong></div>
                    <div style="color:#4b605f;font-size:14px;">Dashboard oliesamples - Koninklijke van Twist</div>
                </div>
            </div>
            <div class="meta">
                <div>Totaal samples: <strong><?= count($summaries) ?></strong></div>
                <div>Bron:
                    <?= htmlspecialchars($samplePathResolved !== '' ? $samplePathResolved : 'niet ingesteld', ENT_QUOTES, 'UTF-8') ?>
                </div>
            </div>
        </header>

        <section class="hero">
            <h1>Overzicht op datum</h1>
            <p>Rapportages zijn gegroepeerd op de datum waarin ze van Mobil ontvangen zijn.</p>
        </section>

        <?php if ($dailyFetchWarning !== ''): ?>
            <div class="warn">
                <?= htmlspecialchars($dailyFetchWarning, ENT_QUOTES, 'UTF-8') ?>
                <a class="warn-action" href="<?= htmlspecialchars($dailyFetchRetryUrl, ENT_QUOTES, 'UTF-8') ?>">[Opnieuw proberen]</a>
            </div>
        <?php endif; ?>

        <?php if (count($summaries) > 0): ?>
            <section class="filters">
                <form method="get" class="filters-head" id="yearFilterForm">
                    <div class="filter-controls">
                        <label for="yearSelect">Jaar</label>
                        <select id="yearSelect" name="jaar" onchange="this.form.submit()">
                            <?php foreach ($availableYears as $year): ?>
                                <option value="<?= $year ?>" <?= $selectedYear === $year ? 'selected' : '' ?>><?= $year ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label for="overviewSearch">Zoeken</label>
                        <input type="search" id="overviewSearch"
                            placeholder="Zoek in sample, status, account, componentnummer, werkorder, contamination"
                            autocomplete="off">
                    </div>
                    <div style="font-size:13px;color:var(--muted);">
                        Zichtbaar in <?= htmlspecialchars((string) ($selectedYear ?? '-'), ENT_QUOTES, 'UTF-8') ?>:
                        <strong><?= count($visibleSummaries) ?></strong>
                    </div>
                </form>
                <?php if (!empty($statusFilters) || $hasActionRequired): ?>
                    <div class="filter-list" id="statusFilterList">
                        <?php foreach ($statusFilters as $statusFilter): ?>
                            <label class="filter-item">
                                <input type="checkbox" class="js-status-filter"
                                    value="<?= htmlspecialchars((string) $statusFilter['key'], ENT_QUOTES, 'UTF-8') ?>" checked>
                                <span class="chip"
                                    style="<?= htmlspecialchars((string) $statusFilter['inlineStyle'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $statusFilter['label'], ENT_QUOTES, 'UTF-8') ?></span>
                            </label>
                        <?php endforeach; ?>
                        <?php if ($hasActionRequired): ?>
                            <label class="filter-item">
                                <input type="checkbox" id="actionRequiredOnly">
                                <span class="chip chip-action">Alleen Action Required</span>
                            </label>
                        <?php endif; ?>
                    </div>
                    <div class="filters-hint">Vink statussen uit om ze te verbergen.</div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if (!$pathOk): ?>
            <div class="warn">Het ingestelde sample pad bestaat niet:
                <code><?= htmlspecialchars($samplePathResolved, ENT_QUOTES, 'UTF-8') ?></code>
            </div>
        <?php elseif (count($summaries) === 0): ?>
            <div class="warn">Er zijn geen leesbare JSON-samples gevonden in
                <code><?= htmlspecialchars($samplePathResolved, ENT_QUOTES, 'UTF-8') ?></code>.
            </div>
        <?php else: ?>
            <?php if (count($inProgressSummaries) > 0): ?>
                <section class="group js-group">
                    <h2>In progress (<?= count($inProgressSummaries) ?>)</h2>
                    <div class="grid">
                        <?php foreach ($inProgressSummaries as $item): ?>
                            <?php $reportStatus = reportStatusMeta((string) ($item['reportStatus'] ?? '')); ?>
                            <?php $progressMeta = reportStatusMeta((string) ($item['progressStatus'] ?? ($item['sampleStatus'] ?? 'In progress'))); ?>
                            <?php $searchPayload = cardSearchPayload($item, $progressMeta, 'in progress'); ?>
                            <?php $workflowReportId = trim((string) ($item['workflowReportId'] ?? '')); ?>
                            <?php if ($workflowReportId === '') {
                                continue;
                            } ?>
                            <?php
                            $sampler = trim((string) ($item['sampler'] ?? '-'));
                            if ($sampler === '') {
                                $sampler = '-';
                            }
                            $samplerLower = strtolower($sampler);
                            $samplerMatchesUser = $currentUserEmail !== '' && $samplerLower === $currentUserEmail;
                            ?>
                            <a class="card" href="sample.php?inprogress=1&workflowreportid=<?= urlencode($workflowReportId) ?>"
                                data-status="<?= htmlspecialchars($progressMeta['key'], ENT_QUOTES, 'UTF-8') ?>"
                                data-in-progress="1" data-action-required="<?= !empty($item['actionRequired']) ? '1' : '0' ?>"
                                data-search="<?= htmlspecialchars($searchPayload, ENT_QUOTES, 'UTF-8') ?>"
                                style="border-color: <?= htmlspecialchars($reportStatus['borderColor'], ENT_QUOTES, 'UTF-8') ?>; --card-top-accent: <?= htmlspecialchars($reportStatus['borderColor'], ENT_QUOTES, 'UTF-8') ?>;">
                                <div class="card-head">
                                    <div class="sample-title">
                                        <strong><?= htmlspecialchars($item['sampleId'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    </div>
                                    <div class="chip-stack">
                                        <?php if (!empty($item['actionRequired'])): ?>
                                            <span class="chip chip-action">Action Required</span>
                                        <?php endif; ?>
                                        <span class="chip"
                                            style="<?= htmlspecialchars($progressMeta['inlineStyle'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($progressMeta['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                </div>
                                <div class="row">Account:
                                    <?= htmlspecialchars(inProgressAccountLine($item), ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <div class="row">Componentnummer:
                                    <?= htmlspecialchars($item['componentNumber'], ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <?php if (trim((string) ($item['workOrder'] ?? '')) !== '' && trim((string) ($item['workOrder'] ?? '')) !== '-'): ?>
                                    <div class="row">Werkorder:
                                        <?= htmlspecialchars((string) $item['workOrder'], ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                <?php endif; ?>
                                <div class="row"><?= htmlspecialchars(inProgressSinceLine($item), ENT_QUOTES, 'UTF-8') ?></div>
                                <span class="sampler-tag sampler-corner<?= $samplerMatchesUser ? ' match' : '' ?>">
                                    <?= htmlspecialchars($sampler, ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
            <?php foreach ($groups as $groupKey => $group): ?>
                <?php $headerDate = formatDutchDateHeader((string) $groupKey, (string) $group['label']); ?>
                <section class="group js-group">
                    <h2><?= htmlspecialchars($headerDate, ENT_QUOTES, 'UTF-8') ?> (<?= count($group['items']) ?>)</h2>
                    <div class="grid">
                        <?php foreach ($group['items'] as $item): ?>
                            <?php $reportStatus = reportStatusMeta((string) ($item['reportStatus'] ?? '')); ?>
                            <?php $searchPayload = cardSearchPayload($item, $reportStatus, $headerDate); ?>
                            <?php
                            $sampler = trim((string) ($item['sampler'] ?? '-'));
                            if ($sampler === '') {
                                $sampler = '-';
                            }
                            $samplerLower = strtolower($sampler);
                            $samplerMatchesUser = $currentUserEmail !== '' && $samplerLower === $currentUserEmail;
                            ?>
                            <a class="card"
                                href="sample.php?file=<?= urlencode($item['file']) ?>&record=<?= (int) ($item['recordIndex'] ?? 0) ?>"
                                data-status="<?= htmlspecialchars($reportStatus['key'], ENT_QUOTES, 'UTF-8') ?>"
                                data-action-required="<?= !empty($item['actionRequired']) ? '1' : '0' ?>"
                                data-search="<?= htmlspecialchars($searchPayload, ENT_QUOTES, 'UTF-8') ?>"
                                style="border-color: <?= htmlspecialchars($reportStatus['borderColor'], ENT_QUOTES, 'UTF-8') ?>; --card-top-accent: <?= htmlspecialchars($reportStatus['borderColor'], ENT_QUOTES, 'UTF-8') ?>;">
                                <div class="card-head">
                                    <div class="sample-title">
                                        <strong><?= htmlspecialchars($item['sampleId'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    </div>
                                    <div class="chip-stack">
                                        <?php if (!empty($item['actionRequired'])): ?>
                                            <span class="chip chip-action">Action Required</span>
                                        <?php endif; ?>
                                        <span class="chip"
                                            style="<?= htmlspecialchars($reportStatus['inlineStyle'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($reportStatus['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                </div>
                                <div class="row">Account:
                                    <?= htmlspecialchars((string) ($item['accountDisplay'] ?? $item['accountName']), ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <div class="row">Componentnummer:
                                    <?= htmlspecialchars($item['componentNumber'], ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <div class="row">Werkorder:
                                    <?= htmlspecialchars($item['workOrder'], ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <div class="row">Contamination status:
                                    <?= htmlspecialchars($item['contaminationRating'], ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <span class="sampler-tag sampler-corner<?= $samplerMatchesUser ? ' match' : '' ?>">
                                    <?= htmlspecialchars($sampler, ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <script>
        (function ()
        {
            const statusCheckboxes = Array.from(document.querySelectorAll('.js-status-filter'));
            const actionOnlyCheckbox = document.getElementById('actionRequiredOnly');
            const searchInput = document.getElementById('overviewSearch');
            const cards = Array.from(document.querySelectorAll('.card[data-status]'));
            const groups = Array.from(document.querySelectorAll('.js-group'));

            if (cards.length === 0)
            {
                return;
            }

            const applyFilters = function ()
            {
                const activeStatuses = new Set(
                    statusCheckboxes.filter(function (checkbox)
                    {
                        return checkbox.checked;
                    }).map(function (checkbox)
                    {
                        return checkbox.value;
                    })
                );
                const actionOnly = actionOnlyCheckbox ? actionOnlyCheckbox.checked : false;
                const needle = searchInput ? searchInput.value.trim().toLowerCase() : '';

                cards.forEach(function (card)
                {
                    const status = card.dataset.status || '__unknown__';
                    const isInProgress = card.dataset.inProgress === '1';
                    const hasActionRequired = card.dataset.actionRequired === '1';
                    const statusVisible = isInProgress || statusCheckboxes.length === 0 ? true : activeStatuses.has(status);
                    const actionVisible = !actionOnly || hasActionRequired;
                    const haystack = (card.dataset.search || '').toLowerCase();
                    const searchVisible = needle === '' || haystack.indexOf(needle) !== -1;
                    card.classList.toggle('hidden', !(statusVisible && actionVisible && searchVisible));
                });

                groups.forEach(function (group)
                {
                    const visibleInGroup = group.querySelectorAll('.card[data-status]:not(.hidden)').length;
                    group.classList.toggle('hidden', visibleInGroup === 0);
                });
            };

            statusCheckboxes.forEach(function (checkbox)
            {
                checkbox.addEventListener('change', applyFilters);
            });

            if (actionOnlyCheckbox)
            {
                actionOnlyCheckbox.addEventListener('change', applyFilters);
            }

            if (searchInput)
            {
                searchInput.addEventListener('input', applyFilters);
            }

            applyFilters();
        })();
    </script>
</body>

</html>