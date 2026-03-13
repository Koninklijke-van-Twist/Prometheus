<?php

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

function isBlankSampleValue($value): bool
{
    if ($value === null) {
        return true;
    }

    if (is_string($value)) {
        return trim($value) === '';
    }

    if (is_array($value)) {
        return count($value) === 0;
    }

    return false;
}

function isSubstanceFieldName(string $fieldName): bool
{
    return preg_match('/^[A-Z][a-z]? \([^)]+\)$/', $fieldName) === 1;
}

function numericSampleValue($value): ?float
{
    if (isBlankSampleValue($value)) {
        return null;
    }

    if (is_numeric($value)) {
        return (float) $value;
    }

    return null;
}

function contaminationRatingMeta(string $rating): array
{
    return statusMeta($rating);
}

function reportStatusMeta(string $status): array
{
    return statusMeta($status);
}

function statusMeta(string $value): array
{
    $normalized = strtolower(trim($value));
    $config = $GLOBALS['prometheusConfig'] ?? [];
    $styles = $config['statusStyles'] ?? [];
    $unknownStyle = $config['unknownStatusStyle'] ?? [
        'label' => 'Onbekend',
        'background' => '#edf1f2',
        'text' => '#405558',
        'border' => '#c8d4d6',
    ];

    if ($normalized !== '' && isset($styles[$normalized]) && is_array($styles[$normalized])) {
        $style = $styles[$normalized];
        $label = (string) ($style['label'] ?? $value);
        $inlineStyle = 'background:' . ($style['background'] ?? '#edf1f2')
            . ';color:' . ($style['text'] ?? '#405558')
            . ';border-color:' . ($style['border'] ?? '#c8d4d6')
            . ';';

        return ['label' => $label, 'style' => $inlineStyle];
    }

    $fallbackLabel = $value !== '' ? $value : (string) ($unknownStyle['label'] ?? 'Onbekend');
    $fallbackStyle = 'background:' . ($unknownStyle['background'] ?? '#edf1f2')
        . ';color:' . ($unknownStyle['text'] ?? '#405558')
        . ';border-color:' . ($unknownStyle['border'] ?? '#c8d4d6')
        . ';';

    return ['label' => $fallbackLabel, 'style' => $fallbackStyle];
}

function substanceBarColor(float $barPercent): string
{
    $config = $GLOBALS['prometheusConfig'] ?? [];
    $thresholds = $config['substanceBarThresholds'] ?? [];

    foreach ($thresholds as $threshold) {
        if (!is_array($threshold)) {
            continue;
        }

        $min = isset($threshold['min']) ? (float) $threshold['min'] : 0.0;
        $color = (string) ($threshold['color'] ?? '');

        if ($color !== '' && $barPercent >= $min) {
            return $color;
        }
    }

    return '#7bbf8f';
}

function elementStyleForField(string $fieldName): array
{
    $config = $GLOBALS['prometheusConfig'] ?? [];
    $map = $config['elementColors'] ?? [];
    $unknown = $config['unknownElementColor'] ?? [
        'accent' => '#9aa6b2',
        'bg' => '#f4f7f9',
        'text' => '#2f3d4a',
    ];

    $symbol = '';
    if (preg_match('/^([A-Z][a-z]?) \([^)]+\)$/', $fieldName, $matches) === 1) {
        $symbol = (string) ($matches[1] ?? '');
    }

    $key = strtolower($symbol);
    $style = (is_array($map) && isset($map[$key]) && is_array($map[$key])) ? $map[$key] : $unknown;

    return [
        'symbol' => $symbol !== '' ? $symbol : '-',
        'accent' => (string) ($style['accent'] ?? $unknown['accent']),
        'bg' => (string) ($style['bg'] ?? $unknown['bg']),
        'text' => (string) ($style['text'] ?? $unknown['text']),
    ];
}

function elementDisplayName(string $fieldName): string
{
    if (preg_match('/^[A-Z][a-z]? \(([^)]+)\)$/', $fieldName, $matches) === 1) {
        $name = trim((string) ($matches[1] ?? ''));
        if ($name !== '') {
            return $name;
        }
    }

    return $fieldName;
}

function mapInProgressRowToDetailRow(array $row): array
{
    $mapped = $row;

    $mapped['Account Name'] = (string) ($row['Account Name'] ?? $row['AccountName'] ?? '');
    $mapped['Account ID'] = (string) ($row['Account ID'] ?? $row['AccountID'] ?? '');
    $mapped['Asset ID'] = (string) ($row['AssetID'] ?? '');
    $mapped['Asset Name'] = (string) ($row['AssetDescription'] ?? '');
    $mapped['Asset Class'] = (string) ($row['AssetClassName'] ?? '');
    $mapped['Unit ID'] = (string) ($row['UnitID'] ?? '');
    $mapped['Work ID'] = (string) (($row['Work_Id'] ?? $row['WorkOrderID'] ?? ''));
    $mapped['Sampler'] = (string) ($row['Sampler'] ?? '');
    $mapped['LIMS Sample ID'] = (string) (($row['LIMSSampleID'] ?? $row['SampleBottleBarcodeID'] ?? ''));
    $mapped['Sample Bottle ID'] = (string) ($row['SampleBottleBarcodeID'] ?? '');
    $mapped['Sample Status'] = (string) ($row['SampleStatus'] ?? 'In progress');
    $mapped['Report Status'] = '-';
    $mapped['Date Sampled'] = (string) ($row['DateSampled'] ?? '');
    $mapped['Date Reported'] = (string) ($row['DateReported'] ?? '');
    $mapped['Date Received'] = (string) ($row['DateReceived'] ?? '');
    $mapped['Date Registered'] = (string) ($row['DateRegistered'] ?? '');
    $mapped['WorkFlow Report ID'] = (string) ($row['WorkFlowReportID'] ?? '');
    $mapped['LifeCycle Stage'] = normalizeLifeCycleStageLabel((string) ($row['LifeCycleStage'] ?? ''));
    $mapped['Days Since Sampled'] = (string) ($row['DaysSinceSampled'] ?? '');
    $mapped['Comments'] = 'In progress sample. Resultaten zijn nog niet beschikbaar.';

    return $mapped;
}

function buildSampleDetailUrl(array $item): string
{
    if (!empty($item['isInProgress'])) {
        $workflowReportId = trim((string) ($item['workflowReportId'] ?? ''));
        if ($workflowReportId === '') {
            return '#';
        }

        return 'sample.php?inprogress=1&workflowreportid=' . rawurlencode($workflowReportId);
    }

    $file = trim((string) ($item['file'] ?? ''));
    $recordIndex = (int) ($item['recordIndex'] ?? 0);
    if ($file === '') {
        return '#';
    }

    return 'sample.php?file=' . rawurlencode($file) . '&record=' . $recordIndex;
}

function timelineItemLabel(array $item): string
{
    $date = trim((string) ($item['dateDisplay'] ?? '-'));
    $sampleId = trim((string) ($item['sampleId'] ?? '-'));
    $status = trim((string) (($item['isInProgress'] ?? false)
        ? ($item['progressStatus'] ?? $item['sampleStatus'] ?? 'In progress')
        : ($item['reportStatus'] ?? $item['sampleStatus'] ?? '-')));

    if ($status === '') {
        $status = '-';
    }

    return $date . ' | ' . $sampleId . ' | ' . $status;
}

function deduplicateDetailFields(array $row): array
{
    $result = [];
    $canonicalMap = [];

    foreach ($row as $key => $value) {
        $field = trim((string) $key);
        if ($field === '') {
            continue;
        }

        $canonical = preg_replace('/[^a-z0-9]/', '', strtolower($field));
        if ($canonical === null || $canonical === '') {
            $canonical = strtolower($field);
        }

        if (!isset($canonicalMap[$canonical])) {
            $canonicalMap[$canonical] = $field;
            $result[$field] = $value;
            continue;
        }

        $existingField = $canonicalMap[$canonical];
        $currentIsReadable = strpos($field, ' ') !== false;
        $existingIsReadable = strpos($existingField, ' ') !== false;

        // Prefer readable labels (with spaces), e.g. "Account Name" over "AccountName".
        if ($currentIsReadable && !$existingIsReadable) {
            unset($result[$existingField]);
            $canonicalMap[$canonical] = $field;
            $result[$field] = $value;
        }
    }

    return $result;
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

$samplePathResolved = getConfiguredSamplePath();
$isInProgress = isset($_GET['inprogress']) && (string) $_GET['inprogress'] === '1';
$workflowReportIdParam = isset($_GET['workflowreportid']) ? trim((string) $_GET['workflowreportid']) : '';
$fileParam = isset($_GET['file']) ? basename((string) $_GET['file']) : '';
$recordParam = isset($_GET['record']) ? (int) $_GET['record'] : 0;
$recordParam = max(0, $recordParam);
$fullPath = $samplePathResolved !== '' ? $samplePathResolved . DIRECTORY_SEPARATOR . $fileParam : '';

$summary = null;
$row = [];
$substanceItems = [];
$filledFields = [];
$blankFields = [];
$contaminationRating = '';
$contaminationRatingStyle = 'background:#edf1f2;color:#405558;border-color:#c8d4d6;';
$reportStatusLabel = '-';
$reportStatusStyle = 'background:#edf1f2;color:#405558;border-color:#c8d4d6;';
$fuelType = '-';
$summaryAccountLine = '-';
$summaryAssetClass = '-';
$summaryComments = '-';
$error = null;
$hideResultsSections = false;
$componentTimelineItems = [];

$ui = [
    'bg' => uiColor('bg', '#f5f4ef'),
    'ink' => uiColor('ink', '#122229'),
    'brand' => uiColor('brand', '#0f5f57'),
    'line' => uiColor('line', '#cfd9d6'),
    'card' => uiColor('card', '#ffffff'),
    'muted' => uiColor('muted', '#4b605f'),
    'warnBackground' => uiColor('warnBackground', '#fff6e3'),
    'warnBorder' => uiColor('warnBorder', '#efdbaf'),
    'warnText' => uiColor('warnText', '#66522b'),
    'subtleBorder' => uiColor('subtleBorder', '#dbe5e2'),
    'subtleCardBackground' => uiColor('subtleCardBackground', '#fbfcfc'),
    'tableLine' => uiColor('tableLine', '#e2e8e6'),
    'barTrack' => uiColor('barTrack', '#e6eded'),
    'buttonText' => uiColor('buttonText', '#ffffff'),
    'cardTopAccentDefault' => uiColor('cardTopAccentDefault', '#0099cc'),
];

if ($isInProgress) {
    if ($workflowReportIdParam === '') {
        $error = 'Geen in-progress sample geselecteerd.';
    } else {
        $inProgressRow = findInProgressRowInCache(getConfiguredInProgressCachePath(), $workflowReportIdParam);
        if ($inProgressRow === []) {
            $error = 'In-progress sample niet gevonden in cache.';
        } else {
            $row = mapInProgressRowToDetailRow($inProgressRow);
            $summary = normalizeInProgressSummary($inProgressRow);
            $summary['sampleId'] = (string) ($summary['sampleId'] ?? ('WF-' . $workflowReportIdParam));
            $reportStatusLabel = (string) ($summary['progressStatus'] ?? 'In progress');
            $progressMeta = reportStatusMeta($reportStatusLabel);
            $reportStatusStyle = (string) ($progressMeta['style'] ?? $reportStatusStyle);
            $summaryAccountLine = (string) ($summary['accountDisplay'] ?? '-');
            $summaryAssetClass = (string) ($summary['assetClass'] ?? '-');
            $summaryComments = 'Dit is een in-progress sample. Resultaten zijn nog niet beschikbaar.';
            $fuelType = '-';
            ksort($row);
            $row = deduplicateDetailFields($row);

            foreach ($row as $key => $value) {
                if (isBlankSampleValue($value)) {
                    $blankFields[$key] = $value;
                } else {
                    $filledFields[$key] = $value;
                }
            }

            $hideResultsSections = true;
            $contaminationRating = '-';
        }
    }
} elseif ($fileParam === '') {
    $error = 'Geen sample geselecteerd.';
} elseif (!is_file($fullPath)) {
    $error = 'Samplebestand niet gevonden.';
} else {
    $rows = parseSampleJsonRecords($fullPath);
    if (isset($rows[$recordParam]) && is_array($rows[$recordParam])) {
        $row = $rows[$recordParam];
    } else {
        $row = [];
    }
    if ($row === []) {
        $error = 'Samplebestand is leeg of ongeldig JSON.';
    } else {
        $summary = normalizeSampleSummary($fullPath, $row, $recordParam);
        ksort($row);
        $contaminationRating = trim((string) ($row['Contamination Rating'] ?? ''));
        $ratingMeta = contaminationRatingMeta($contaminationRating);
        $contaminationRatingStyle = (string) $ratingMeta['style'];
        $contaminationRating = $ratingMeta['label'];
        $reportMeta = reportStatusMeta((string) ($summary['reportStatus'] ?? ''));
        $reportStatusLabel = (string) ($reportMeta['label'] ?? '-');
        $reportStatusStyle = (string) ($reportMeta['style'] ?? $reportStatusStyle);
        $fuelType = trim((string) ($row['Fuel Type'] ?? ''));
        if ($fuelType === '') {
            $fuelType = '-';
        }

        $accountName = trim((string) ($row['Account Name'] ?? ''));
        $accountId = trim((string) ($row['Account ID'] ?? ''));
        if ($accountName !== '' && $accountId !== '') {
            $summaryAccountLine = $accountName . ' (' . $accountId . ')';
        } elseif ($accountName !== '') {
            $summaryAccountLine = $accountName;
        } elseif ($accountId !== '') {
            $summaryAccountLine = $accountId;
        }

        $summaryAssetClass = trim((string) ($row['Asset Class'] ?? ''));
        if ($summaryAssetClass === '') {
            $summaryAssetClass = '-';
        }

        $summaryComments = trim((string) ($row['Comments'] ?? ''));
        if ($summaryComments === '') {
            $summaryComments = '-';
        }

        $substanceFields = [];

        foreach ($row as $key => $value) {
            $fieldName = (string) $key;

            if (isSubstanceFieldName($fieldName)) {
                $substanceFields[$fieldName] = $value;
                continue;
            }

            if (isBlankSampleValue($value)) {
                $blankFields[$key] = $value;
            } else {
                $filledFields[$key] = $value;
            }
        }

        $numericTotal = 0.0;
        foreach ($substanceFields as $value) {
            $numeric = numericSampleValue($value);
            if ($numeric !== null && $numeric > 0) {
                $numericTotal += $numeric;
            }
        }

        foreach ($substanceFields as $fieldName => $value) {
            $numeric = numericSampleValue($value);
            $percent = 0.0;
            if ($numeric !== null && $numeric > 0 && $numericTotal > 0) {
                $percent = ($numeric / $numericTotal) * 100;
            }

            $substanceItems[] = [
                'key' => $fieldName,
                'value' => $value,
                'numeric' => $numeric,
                'percent' => $percent,
            ];
        }

        usort($substanceItems, static function (array $a, array $b): int {
            $aNumeric = $a['numeric'];
            $bNumeric = $b['numeric'];

            if ($aNumeric === null && $bNumeric !== null) {
                return 1;
            }

            if ($aNumeric !== null && $bNumeric === null) {
                return -1;
            }

            if ($aNumeric !== null && $bNumeric !== null && $aNumeric !== $bNumeric) {
                return $bNumeric <=> $aNumeric;
            }

            return strcmp((string) $a['key'], (string) $b['key']);
        });
    }
}

if ($error === null && is_array($summary)) {
    $currentComponent = trim((string) ($summary['componentNumber'] ?? ''));
    if ($currentComponent !== '' && $currentComponent !== '-') {
        $allTimelineItems = [];

        foreach (loadSampleSummaries($samplePathResolved) as $completedItem) {
            if (!is_array($completedItem)) {
                continue;
            }

            if (trim((string) ($completedItem['componentNumber'] ?? '')) !== $currentComponent) {
                continue;
            }

            $completedItem['isInProgress'] = false;
            $allTimelineItems[] = $completedItem;
        }

        foreach (loadInProgressSummariesFromCache(getConfiguredInProgressCachePath()) as $inProgressItem) {
            if (!is_array($inProgressItem)) {
                continue;
            }

            if (trim((string) ($inProgressItem['componentNumber'] ?? '')) !== $currentComponent) {
                continue;
            }

            $inProgressItem['isInProgress'] = true;
            $allTimelineItems[] = $inProgressItem;
        }

        usort($allTimelineItems, static function (array $a, array $b): int {
            return strcmp((string) ($a['dateSort'] ?? ''), (string) ($b['dateSort'] ?? ''));
        });

        foreach ($allTimelineItems as $item) {
            $isCurrent = false;
            if (!empty($item['isInProgress'])) {
                $candidateWorkflowReportId = trim((string) ($item['workflowReportId'] ?? ''));
                $isCurrent = $isInProgress && $candidateWorkflowReportId !== '' && $candidateWorkflowReportId === $workflowReportIdParam;
            } else {
                $candidateFile = trim((string) ($item['file'] ?? ''));
                $candidateRecord = (int) ($item['recordIndex'] ?? 0);
                $isCurrent = !$isInProgress && $candidateFile === $fileParam && $candidateRecord === $recordParam;
            }

            $componentTimelineItems[] = [
                'isCurrent' => $isCurrent,
                'url' => buildSampleDetailUrl($item),
                'label' => timelineItemLabel($item),
            ];
        }
    }
}
?>
<!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Prometheus | Sample detail</title>
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
            --line:
                <?= htmlspecialchars($ui['line'], ENT_QUOTES, 'UTF-8') ?>
            ;
            --card:
                <?= htmlspecialchars($ui['card'], ENT_QUOTES, 'UTF-8') ?>
            ;
            --muted:
                <?= htmlspecialchars($ui['muted'], ENT_QUOTES, 'UTF-8') ?>
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
            background: var(--bg);
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
            gap: 12px;
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 12px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .brand img {
            height: 36px;
            width: auto;
        }

        .btn {
            color:
                <?= htmlspecialchars($ui['buttonText'], ENT_QUOTES, 'UTF-8') ?>
            ;
            background: var(--brand);
            text-decoration: none;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 14px;
            border: 1px solid var(--brand);
        }

        .card {
            margin-top: 16px;
            background: var(--card);
            border: 1px solid var(--line);
            border-top-width: 4px;
            border-top-color: var(--card-top-accent, var(--card-top-accent-default));
            border-radius: 12px;
            padding: 14px;
        }

        .rating-pill {
            border-radius: 999px;
            padding: 6px 10px;
            font-size: 12px;
            font-weight: 700;
            border: 1px solid transparent;
            white-space: nowrap;
        }

        .report-pill {
            padding: 8px 14px;
            font-size: 14px;
            font-weight: 800;
        }

        .header-line {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }

        .card-headline {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .kv {
            border: 1px solid
                <?= htmlspecialchars($ui['subtleBorder'], ENT_QUOTES, 'UTF-8') ?>
            ;
            border-radius: 10px;
            padding: 9px;
            background:
                <?= htmlspecialchars($ui['subtleCardBackground'], ENT_QUOTES, 'UTF-8') ?>
            ;
        }

        .kv b {
            display: block;
            font-size: 12px;
            color: var(--muted);
            margin-bottom: 3px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            font-size: 14px;
        }

        th,
        td {
            border-bottom: 1px solid
                <?= htmlspecialchars($ui['tableLine'], ENT_QUOTES, 'UTF-8') ?>
            ;
            text-align: left;
            padding: 8px 6px;
            vertical-align: top;
        }

        th {
            width: 28%;
            color: var(--muted);
            font-weight: 600;
        }

        .warn {
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
            margin-top: 16px;
        }

        .sub-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }

        .sub-card {
            border: 1px solid
                <?= htmlspecialchars($ui['subtleBorder'], ENT_QUOTES, 'UTF-8') ?>
            ;
            border-top-width: 4px;
            border-top-color: var(--el-accent,
                    <?= htmlspecialchars($ui['subtleBorder'], ENT_QUOTES, 'UTF-8') ?>
                );
            border-radius: 10px;
            background: var(--el-bg,
                    <?= htmlspecialchars($ui['subtleCardBackground'], ENT_QUOTES, 'UTF-8') ?>
                );
            padding: 10px;
        }

        .sub-card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .sub-symbol {
            font-size: 12px;
            font-weight: 700;
            border-radius: 999px;
            padding: 2px 8px;
            border: 1px solid var(--el-accent,
                    <?= htmlspecialchars($ui['subtleBorder'], ENT_QUOTES, 'UTF-8') ?>
                );
            color: var(--el-text, var(--ink));
            background: rgba(255, 255, 255, 0.72);
        }

        .sub-name {
            font-size: 13px;
            color: var(--muted);
            margin-bottom: 4px;
        }

        .sub-value {
            font-size: 18px;
            font-weight: 700;
            line-height: 1.2;
        }

        .sub-percent {
            margin-top: 6px;
            font-size: 12px;
            color: var(--muted);
        }

        .bar {
            margin-top: 8px;
            height: 6px;
            border-radius: 999px;
            background:
                <?= htmlspecialchars($ui['barTrack'], ENT_QUOTES, 'UTF-8') ?>
            ;
            overflow: hidden;
        }

        .bar-fill {
            height: 100%;
            border-radius: 999px;
            background: #7bbf8f;
        }

        .charts-grid {
            margin-top: 10px;
            display: grid;
            grid-template-columns: 1fr;
            gap: 14px;
        }

        .chart-card {
            border: 1px solid
                <?= htmlspecialchars($ui['subtleBorder'], ENT_QUOTES, 'UTF-8') ?>
            ;
            border-radius: 10px;
            padding: 10px;
            background:
                <?= htmlspecialchars($ui['subtleCardBackground'], ENT_QUOTES, 'UTF-8') ?>
            ;
        }

        .chart-title {
            margin: 0 0 8px;
            font-size: 14px;
            color: var(--muted);
        }

        .chart-wrap {
            position: relative;
            height: 260px;
        }

        .chart-card.chart-card-compact .chart-wrap {
            height: auto;
            min-height: 0;
        }

        .chart-loading {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-size: 13px;
            color: var(--muted);
        }

        .spinner {
            width: 16px;
            height: 16px;
            border-radius: 999px;
            border: 2px solid #cbd9e7;
            border-top-color: var(--brand);
            animation: spin 0.8s linear infinite;
        }

        .is-hidden {
            display: none !important;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .chart-empty {
            font-size: 13px;
            color: var(--muted);
            padding: 8px 0 2px;
        }

        .chart-empty.subtle {
            opacity: 0.78;
            font-size: 12px;
        }

        .timeline-wrap {
            margin-top: 14px;
            border-top: 1px solid
                <?= htmlspecialchars($ui['tableLine'], ENT_QUOTES, 'UTF-8') ?>
            ;
            padding-top: 12px;
        }

        .timeline-title {
            margin: 0 0 8px;
            font-size: 13px;
            color: var(--muted);
            font-weight: 700;
        }

        .timeline-list {
            margin: 0;
            padding: 0;
            list-style: none;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .timeline-item,
        .timeline-current {
            display: inline-block;
            border-radius: 999px;
            padding: 6px 10px;
            font-size: 12px;
            border: 1px solid
                <?= htmlspecialchars($ui['subtleBorder'], ENT_QUOTES, 'UTF-8') ?>
            ;
            text-decoration: none;
        }

        .timeline-item {
            background: #fff;
            color: var(--brand);
        }

        .timeline-item:hover {
            background:
                <?= htmlspecialchars($ui['subtleCardBackground'], ENT_QUOTES, 'UTF-8') ?>
            ;
        }

        .timeline-current {
            background:
                <?= htmlspecialchars($ui['brand'], ENT_QUOTES, 'UTF-8') ?>
            ;
            color:
                <?= htmlspecialchars($ui['buttonText'], ENT_QUOTES, 'UTF-8') ?>
            ;
            border-color:
                <?= htmlspecialchars($ui['brand'], ENT_QUOTES, 'UTF-8') ?>
            ;
            cursor: default;
        }

        @media (max-width: 700px) {
            th {
                width: 40%;
            }

            .top {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>

<body>
    <div class="wrap">
        <header class="top">
            <div class="brand">
                <img src="images/kvtlogo.png" alt="Koninklijke van Twist logo icoon">
                <div>
                    <div><strong>Prometheus</strong></div>
                    <div style="font-size:14px;color:#4b605f;">Sample detail</div>
                </div>
            </div>
            <a class="btn" href="index.php">Terug naar overzicht</a>
        </header>

        <?php if ($error !== null): ?>
            <div class="warn"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php else: ?>
            <section class="card">
                <div class="header-line">
                    <h1 style="margin:0;"><?= htmlspecialchars($summary['sampleId'], ENT_QUOTES, 'UTF-8') ?></h1>
                    <div class="rating-pill report-pill"
                        style="<?= htmlspecialchars($reportStatusStyle, ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($reportStatusLabel, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                </div>
                <div class="summary-grid">
                    <div class="kv"><b>Datum</b><?= htmlspecialchars($summary['dateDisplay'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="kv"><b>Account</b><?= htmlspecialchars($summaryAccountLine, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <div class="kv"><b>Asset ID</b><?= htmlspecialchars($summary['assetId'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="kv"><b>Asset name</b><?= htmlspecialchars($summary['assetName'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <div class="kv"><b>Asset class</b><?= htmlspecialchars($summary['assetClass'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <div class="kv">
                        <b>Componentnummer</b><?= htmlspecialchars($summary['componentNumber'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <div class="kv">
                        <b>Werkorder</b><?= htmlspecialchars((string) ($summary['workOrder'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <div class="kv"><b>Fuel Type</b><?= htmlspecialchars($fuelType, ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </section>

            <?php if ($isInProgress): ?>
                <section class="card">
                    <h2 style="margin:0;">Status van nog te behandelen sample</h2>
                    <table>
                        <tbody>
                            <tr>
                                <th>Workflow Report ID</th>
                                <td><?= htmlspecialchars((string) ($row['WorkFlow Report ID'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                                </td>
                            </tr>
                            <tr>
                                <th>LifeCycle Stage</th>
                                <td><?= htmlspecialchars((string) ($row['LifeCycle Stage'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                            <tr>
                                <th>Date Registered</th>
                                <td><?= htmlspecialchars((string) ($row['Date Registered'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                            <tr>
                                <th>Days Since Sampled</th>
                                <td><?= htmlspecialchars((string) ($row['Days Since Sampled'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            <?php endif; ?>

            <section class="card">
                <h2 style="margin:0;">Component</h2>
                <table>
                    <tbody>
                        <tr>
                            <th>Componentnummer</th>
                            <td><?= htmlspecialchars((string) ($summary['componentNumber'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="timeline-wrap">
                    <h3 class="timeline-title">Gerelateerde rapporten (chronologisch)</h3>
                    <?php if (count($componentTimelineItems) > 0): ?>
                        <ul class="timeline-list">
                            <?php foreach ($componentTimelineItems as $timelineItem): ?>
                                <li>
                                    <?php if (!empty($timelineItem['isCurrent'])): ?>
                                        <span
                                            class="timeline-current"><?= htmlspecialchars((string) $timelineItem['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php else: ?>
                                        <a class="timeline-item"
                                            href="<?= htmlspecialchars((string) $timelineItem['url'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $timelineItem['label'], ENT_QUOTES, 'UTF-8') ?></a>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="chart-empty subtle">Geen andere rapportages gevonden voor dit componentnummer.</div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card">
                <h2 style="margin:0;">Samenvatting</h2>
                <table>
                    <tbody>
                        <tr>
                            <th>Account</th>
                            <td><?= htmlspecialchars($summaryAccountLine, ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                        <tr>
                            <th>Asset Class</th>
                            <td><?= htmlspecialchars($summaryAssetClass, ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                        <tr>
                            <th>Contamination Rating</th>
                            <td><?= htmlspecialchars($contaminationRating, ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                        <tr>
                            <th>Comments</th>
                            <td>
                                <pre
                                    style="margin:0;white-space:pre-wrap;font-family:inherit;"><?= htmlspecialchars($summaryComments, ENT_QUOTES, 'UTF-8') ?></pre>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="charts-grid">
                    <div class="chart-card" data-chart-key="viscosity">
                        <h3 class="chart-title">Viscosity @ 100C</h3>
                        <div class="chart-wrap">
                            <div class="chart-loading"><span class="spinner"></span>Grafiek laden...</div>
                            <canvas id="viscosityChart" class="is-hidden"></canvas>
                            <div class="chart-empty is-hidden"></div>
                        </div>
                    </div>

                    <div class="chart-card" data-chart-key="wear">
                        <h3 class="chart-title">Wear</h3>
                        <div class="chart-wrap">
                            <div class="chart-loading"><span class="spinner"></span>Grafiek laden...</div>
                            <canvas id="wearChart" class="is-hidden"></canvas>
                            <div class="chart-empty is-hidden"></div>
                        </div>
                    </div>

                    <div class="chart-card" data-chart-key="contaminants">
                        <h3 class="chart-title">Contaminants</h3>
                        <div class="chart-wrap">
                            <div class="chart-loading"><span class="spinner"></span>Grafiek laden...</div>
                            <canvas id="contaminantsChart" class="is-hidden"></canvas>
                            <div class="chart-empty is-hidden"></div>
                        </div>
                    </div>

                    <div class="chart-card" data-chart-key="oxidationSoot">
                        <h3 class="chart-title">Oxidation en Soot</h3>
                        <div class="chart-wrap">
                            <div class="chart-loading"><span class="spinner"></span>Grafiek laden...</div>
                            <canvas id="oxidationSootChart" class="is-hidden"></canvas>
                            <div class="chart-empty is-hidden"></div>
                        </div>
                    </div>
                </div>
            </section>

            <?php if (!$hideResultsSections): ?>
                <section class="card">
                    <div class="card-headline">
                        <h2 style="margin:0;">Stoffen (<?= count($substanceItems) ?>)</h2>
                        <div class="rating-pill"
                            style="<?= htmlspecialchars($contaminationRatingStyle, ENT_QUOTES, 'UTF-8') ?>">
                            Contamination Rating: <?= htmlspecialchars($contaminationRating, ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    </div>
                    <div class="sub-grid">
                        <?php foreach ($substanceItems as $item): ?>
                            <?php
                            $barPercent = max(0.0, min(100.0, (float) $item['percent']));
                            $barColor = substanceBarColor($barPercent);
                            $elementStyle = elementStyleForField((string) $item['key']);
                            $elementName = elementDisplayName((string) $item['key']);
                            ?>
                            <div class="sub-card"
                                style="--el-accent: <?= htmlspecialchars($elementStyle['accent'], ENT_QUOTES, 'UTF-8') ?>; --el-bg: <?= htmlspecialchars($elementStyle['bg'], ENT_QUOTES, 'UTF-8') ?>; --el-text: <?= htmlspecialchars($elementStyle['text'], ENT_QUOTES, 'UTF-8') ?>;">
                                <div class="sub-card-head">
                                    <div class="sub-name"><?= htmlspecialchars($elementName, ENT_QUOTES, 'UTF-8') ?></div>
                                    <span
                                        class="sub-symbol"><?= htmlspecialchars($elementStyle['symbol'], ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                                <div class="sub-value">
                                    <?= htmlspecialchars(formatValueForView($item['value']), ENT_QUOTES, 'UTF-8') ?>
                                    <small>ppm</small>
                                </div>
                                <div class="sub-percent"><?= number_format($barPercent, 1, ',', '.') ?>% van totaal gemeten stoffen
                                </div>
                                <div class="bar">
                                    <div class="bar-fill"
                                        style="width: <?= number_format($barPercent, 2, '.', '') ?>%; background: <?= htmlspecialchars($barColor, ENT_QUOTES, 'UTF-8') ?>;">
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <section class="card">
                <h2 style="margin:0;">Ingevulde velden (<?= count($filledFields) ?>)</h2>
                <table>
                    <tbody>
                        <?php foreach ($filledFields as $key => $value): ?>
                            <tr>
                                <th><?= htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8') ?></th>
                                <td>
                                    <pre
                                        style="margin:0;white-space:pre-wrap;font-family:inherit;"><?= htmlspecialchars(formatValueForView($value), ENT_QUOTES, 'UTF-8') ?></pre>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if (count($blankFields) > 0): ?>
                    <details style="margin-top:14px;">
                        <summary style="cursor:pointer;font-weight:600;">Blanco velden (<?= count($blankFields) ?>)</summary>
                        <table>
                            <tbody>
                                <?php foreach ($blankFields as $key => $value): ?>
                                    <tr>
                                        <th><?= htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8') ?></th>
                                        <td>
                                            <pre
                                                style="margin:0;white-space:pre-wrap;font-family:inherit;"><?= htmlspecialchars(formatValueForView($value), ENT_QUOTES, 'UTF-8') ?></pre>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </details>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
    <?php if ($error === null): ?>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
        <script>
            (function ()
            {
                const chartCanvasIds = {
                    viscosity: 'viscosityChart',
                    wear: 'wearChart',
                    contaminants: 'contaminantsChart',
                    oxidationSoot: 'oxidationSootChart'
                };

                const setChartState = function (key, state, message)
                {
                    const card = document.querySelector('[data-chart-key="' + key + '"]');
                    if (!card)
                    {
                        return;
                    }

                    card.classList.toggle('chart-card-compact', state === 'empty' || state === 'error');

                    const loading = card.querySelector('.chart-loading');
                    const empty = card.querySelector('.chart-empty');
                    const canvas = card.querySelector('canvas');

                    if (loading)
                    {
                        loading.classList.toggle('is-hidden', state !== 'loading');
                    }

                    if (empty)
                    {
                        empty.classList.toggle('is-hidden', state !== 'empty' && state !== 'error');
                        empty.classList.add('subtle');
                        if (state === 'empty' || state === 'error')
                        {
                            empty.textContent = message || 'Geen historische data beschikbaar voor dit Asset ID.';
                        }
                    }

                    if (canvas)
                    {
                        canvas.classList.toggle('is-hidden', state !== 'ready');
                    }
                };

                const hasRenderableData = function (chartData)
                {
                    if (!chartData || !Array.isArray(chartData.labels) || chartData.labels.length === 0)
                    {
                        return false;
                    }

                    const datasets = chartData.datasets || [];
                    for (let i = 0; i < datasets.length; i++)
                    {
                        const values = datasets[i].data || [];
                        for (let j = 0; j < values.length; j++)
                        {
                            if (values[j] !== null && values[j] !== undefined)
                            {
                                return true;
                            }
                        }
                    }

                    return false;
                };

                const countKnownDataPoints = function (chartData)
                {
                    if (!chartData || !Array.isArray(chartData.labels) || chartData.labels.length === 0)
                    {
                        return 0;
                    }

                    const datasets = chartData.datasets || [];
                    let count = 0;

                    for (let i = 0; i < chartData.labels.length; i++)
                    {
                        let knownAtIndex = false;
                        for (let d = 0; d < datasets.length; d++)
                        {
                            const values = datasets[d].data || [];
                            if (values[i] !== null && values[i] !== undefined)
                            {
                                knownAtIndex = true;
                                break;
                            }
                        }

                        if (knownAtIndex)
                        {
                            count++;
                        }
                    }

                    return count;
                };

                const buildLineChart = function (canvasId, chartData)
                {
                    const canvas = document.getElementById(canvasId);
                    if (!canvas || !chartData || !Array.isArray(chartData.labels) || chartData.labels.length === 0)
                    {
                        return;
                    }

                    const datasets = (chartData.datasets || []).map(function (dataset)
                    {
                        return {
                            label: dataset.label || 'Serie',
                            data: dataset.data || [],
                            borderColor: dataset.borderColor || '#00529B',
                            backgroundColor: dataset.backgroundColor || '#00529B',
                            spanGaps: true,
                            pointRadius: 3,
                            pointHoverRadius: 4,
                            tension: 0.25,
                            fill: false,
                        };
                    });

                    new Chart(canvas, {
                        type: 'line',
                        data: {
                            labels: chartData.labels,
                            datasets: datasets,
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            interaction: {
                                mode: 'nearest',
                                intersect: false,
                            },
                            scales: {
                                x: {
                                    ticks: {
                                        maxRotation: 0,
                                        autoSkip: true,
                                    },
                                },
                                y: {
                                    beginAtZero: false,
                                },
                            },
                            plugins: {
                                legend: {
                                    display: true,
                                    position: 'bottom',
                                },
                            },
                        },
                    });
                };

                Object.keys(chartCanvasIds).forEach(function (key)
                {
                    setChartState(key, 'loading');
                });

                const dataUrl = <?= json_encode($isInProgress
                    ? ('sample_charts_data.php?inprogress=1&workflowreportid=' . rawurlencode($workflowReportIdParam))
                    : ('sample_charts_data.php?file=' . rawurlencode($fileParam) . '&record=' . (int) $recordParam), JSON_UNESCAPED_UNICODE) ?>;

                fetch(dataUrl, { cache: 'no-store' })
                    .then(function (response)
                    {
                        if (!response.ok)
                        {
                            throw new Error('Kan grafiekdata niet laden (' + response.status + ').');
                        }

                        return response.json();
                    })
                    .then(function (payload)
                    {
                        if (!payload || payload.ok !== true)
                        {
                            throw new Error((payload && payload.message) ? payload.message : 'Onbekende fout bij laden van grafieken.');
                        }

                        const charts = payload.charts || {};
                        Object.keys(chartCanvasIds).forEach(function (key)
                        {
                            const card = document.querySelector('[data-chart-key="' + key + '"]');
                            const titleText = card ? ((card.querySelector('.chart-title') || {}).textContent || key) : key;
                            const chartData = charts[key] || null;
                            if (!hasRenderableData(chartData))
                            {
                                setChartState(key, 'empty', 'Grafiek voor ' + titleText + ' is nog niet beschikbaar.');
                                return;
                            }

                            if (countKnownDataPoints(chartData) < 2)
                            {
                                setChartState(key, 'empty', 'Grafiek voor ' + titleText + ' is nog niet beschikbaar.');
                                return;
                            }

                            buildLineChart(chartCanvasIds[key], chartData);
                            setChartState(key, 'ready');
                        });
                    })
                    .catch(function (error)
                    {
                        const message = error && error.message ? error.message : 'Onbekende fout bij laden van grafieken.';
                        Object.keys(chartCanvasIds).forEach(function (key)
                        {
                            setChartState(key, 'error', message);
                        });
                    });
            })();
        </script>
    <?php endif; ?>
</body>

</html>