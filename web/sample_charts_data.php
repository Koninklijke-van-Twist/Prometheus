<?php

declare(strict_types=1);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/odata.php';
require_once __DIR__ . '/sample_repository.php';
require_once __DIR__ . '/graphhelper.php';

header('Content-Type: application/json; charset=utf-8');

$fileParam = isset($_GET['file']) ? basename((string) $_GET['file']) : '';
$recordParam = isset($_GET['record']) ? (int) $_GET['record'] : 0;
$recordParam = max(0, $recordParam);
$samplePathResolved = getConfiguredSamplePath();

if ($fileParam === '') {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'message' => 'Geen sample geselecteerd.',
    ]);
    exit;
}

$fullPath = $samplePathResolved !== '' ? $samplePathResolved . DIRECTORY_SEPARATOR . $fileParam : '';
if ($samplePathResolved === '' || !is_file($fullPath)) {
    http_response_code(404);
    echo json_encode([
        'ok' => false,
        'message' => 'Samplebestand niet gevonden.',
    ]);
    exit;
}

$rows = parseSampleJsonRecords($fullPath);
$row = (isset($rows[$recordParam]) && is_array($rows[$recordParam])) ? $rows[$recordParam] : [];
if ($row === []) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'message' => 'Samplebestand is leeg of ongeldig JSON.',
    ]);
    exit;
}

$assetId = trim((string) ($row['Asset ID'] ?? ''));
$historyRows = graphLoadAssetHistoryRows($samplePathResolved, $assetId);
$charts = graphBuildDashboardCharts($historyRows);

echo json_encode([
    'ok' => true,
    'assetId' => $assetId,
    'historyCount' => count($historyRows),
    'charts' => $charts,
], JSON_UNESCAPED_UNICODE);
