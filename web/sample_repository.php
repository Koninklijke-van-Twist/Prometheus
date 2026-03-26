<?php


/**
 * Functies
 */

/**
 * Shared helpers to read and normalize oil sample JSON files.
 */
function getConfiguredSamplePath(): string
{
    foreach (getConfiguredSampleSources() as $source) {
        if ((string) ($source['type'] ?? '') !== 'json') {
            continue;
        }

        $path = trim((string) ($source['path'] ?? ''));
        if ($path !== '') {
            return rtrim($path, "\\/");
        }
    }

    return '';
}

function getConfiguredSampleSources(): array
{
    $rawSources = $GLOBALS['samplePaths'] ?? null;
    $normalizedSources = [];

    if (is_array($rawSources)) {
        foreach ($rawSources as $index => $rawSource) {
            if (!is_array($rawSource)) {
                continue;
            }

            $name = trim((string) ($rawSource['name'] ?? 'Bron ' . ((int) $index + 1)));
            $type = strtolower(trim((string) ($rawSource['type'] ?? 'json')));
            $path = trim((string) ($rawSource['path'] ?? ''));

            if ($name === '' || $path === '' || !in_array($type, ['json', 'pdf'], true)) {
                continue;
            }

            $normalizedSources[] = [
                'name' => $name,
                'type' => $type,
                'path' => rtrim($path, "\\/"),
            ];
        }
    }

    return $normalizedSources;
}

function listSampleJsonFiles(string $samplePath): array
{
    if ($samplePath === '' || !is_dir($samplePath)) {
        return [];
    }

    $pattern = $samplePath . DIRECTORY_SEPARATOR . '*.json';
    $files = glob($pattern);

    if ($files === false) {
        return [];
    }

    usort($files, static function (string $a, string $b): int {
        return filemtime($b) <=> filemtime($a);
    });

    return $files;
}

function parseSampleJsonFile(string $filePath): array
{
    $records = parseSampleJsonRecords($filePath);
    if ($records === []) {
        return [];
    }

    return $records[0];
}

function parseSampleJsonRecords(string $filePath): array
{
    if (!is_file($filePath) || !is_readable($filePath)) {
        return [];
    }

    $raw = file_get_contents($filePath);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    if (defined('JSON_THROW_ON_ERROR')) {
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return [];
        }
    } else {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
    }

    if (isset($decoded['Columns']) && is_array($decoded['Columns']) && isset($decoded['Results']) && is_array($decoded['Results'])) {
        return sampleMapColumnsResultsToRows($decoded['Columns'], $decoded['Results']);
    }

    if (array_is_list($decoded)) {
        $rows = [];
        foreach ($decoded as $entry) {
            if (is_array($entry)) {
                $rows[] = $entry;
            }
        }

        return $rows;
    }

    return is_array($decoded) ? [$decoded] : [];
}

function parseSampleJsonRecordsBySource(string $filePath, string $sourceName): array
{
    $normalizedSourceName = strtolower(trim($sourceName));

    switch ($normalizedSourceName) {
        case 'mobil':
            return parseSampleJsonRecords($filePath);
        default:
            // Default parser; bronnaam wordt bewust doorgegeven zodat we vendorspecifiek kunnen uitbreiden.
            return parseSampleJsonRecords($filePath);
    }
}

function sampleMapColumnsResultsToRows(array $columns, array $results): array
{
    $rows = [];

    foreach ($results as $resultRow) {
        if (!is_array($resultRow)) {
            continue;
        }

        if (!array_is_list($resultRow)) {
            $rows[] = $resultRow;
            continue;
        }

        $mapped = [];
        foreach ($columns as $index => $columnName) {
            if (!is_string($columnName) || trim($columnName) === '') {
                continue;
            }

            $mapped[$columnName] = $resultRow[$index] ?? null;
        }

        if ($mapped !== []) {
            $rows[] = $mapped;
        }
    }

    return $rows;
}

function firstNotEmpty(array $row, array $keys): ?string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row)) {
            continue;
        }

        $value = trim((string) $row[$key]);
        if ($value !== '') {
            return $value;
        }
    }

    return null;
}

function sampleDateParts(?string $input): ?array
{
    if ($input === null || trim($input) === '') {
        return null;
    }

    try {
        $dt = new DateTimeImmutable($input);
    } catch (Exception $e) {
        return null;
    }

    return [
        'groupKey' => $dt->format('Y-m-d'),
        'display' => $dt->format('d-m-Y'),
        'sort' => $dt->format('YmdHis')
    ];
}

function sampleDateMeta(?string $input): array
{
    $value = trim((string) $input);
    if ($value === '' || $value === '-') {
        return [
            'display' => '-',
            'daysSince' => null,
        ];
    }

    try {
        $dt = new DateTimeImmutable($value);
    } catch (Exception $e) {
        return [
            'display' => $value,
            'daysSince' => null,
        ];
    }

    $dateOnly = $dt->setTime(0, 0, 0);
    $todayMidnight = new DateTimeImmutable('today');
    $secondsDiff = $todayMidnight->getTimestamp() - $dateOnly->getTimestamp();
    $daysSince = (int) floor($secondsDiff / 86400);

    return [
        'display' => $dateOnly->format('d-m-Y'),
        'daysSince' => $daysSince >= 0 ? $daysSince : null,
    ];
}

function determineInProgressDateStageLabel(array $row): string
{
    $stages = [
        'Sampled' => ['Date Sampled', 'DateSampled'],
        'Reported' => ['Date Reported', 'DateReported'],
        'Registered' => ['Date Registered', 'DateRegistered'],
        'Received' => ['Date Received', 'DateReceived'],
    ];

    $label = '';
    foreach ($stages as $stageLabel => $keys) {
        $value = firstNotEmpty($row, $keys);
        if ($value !== null && trim($value) !== '' && trim($value) !== '-') {
            $label = $stageLabel;
        }
    }

    return $label;
}

function hasActionRequiredComment(?string $comment): bool
{
    if ($comment === null || trim($comment) === '') {
        return false;
    }

    $upper = strtoupper($comment);

    if (strpos($upper, 'ACTION REQUIRED') === false) {
        return false;
    }

    if (strpos($upper, 'NO ACTION REQUIRED') !== false) {
        return false;
    }

    // Some reports contain a typo in the negative phrase.
    if (strpos($upper, 'NO ACTION REQURED') !== false) {
        return false;
    }

    return true;
}

function extractComponentNumber(?string $unitId): string
{
    if ($unitId === null) {
        return '-';
    }

    $trimmed = trim($unitId);
    if ($trimmed === '' || $trimmed === '-') {
        return '-';
    }

    $parts = explode('/', $trimmed);
    $candidate = trim((string) end($parts));

    return $candidate !== '' ? $candidate : $trimmed;
}

function formatWorkOrder(?string $workId): string
{
    if ($workId === null) {
        return '-';
    }

    $trimmed = trim($workId);
    if ($trimmed === '' || $trimmed === '-') {
        return '-';
    }

    if (stripos($trimmed, 'WO') === 0) {
        return $trimmed;
    }

    if (strpos($trimmed, '40') === 0) {
        return $trimmed;
    }

    return 'WO' . $trimmed;
}

function formatAccountDisplay(?string $accountName, ?string $accountId): string
{
    $name = trim((string) $accountName);
    $id = trim((string) $accountId);

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

function normalizeSampleSummary(string $filePath, array $row, int $recordIndex = 0): array
{
    $sampleId = firstNotEmpty($row, ['LIMS Sample ID', 'Sample Bottle ID']) ?? pathinfo($filePath, PATHINFO_FILENAME);
    $dateRaw = firstNotEmpty($row, ['Date Sampled', 'Date Reported', 'Date Received']);
    $dateParts = sampleDateParts($dateRaw);

    if ($dateParts === null) {
        $dt = (new DateTimeImmutable())->setTimestamp((int) filemtime($filePath));
        $dateParts = [
            'groupKey' => $dt->format('Y-m-d'),
            'display' => $dt->format('d-m-Y'),
            'sort' => $dt->format('YmdHis')
        ];
    }

    $comments = firstNotEmpty($row, ['Comments']) ?? '';

    $unitId = firstNotEmpty($row, ['Unit ID']) ?? '-';
    $unitDescription = firstNotEmpty($row, ['Unit Description', 'UnitDescription', 'Asset Name', 'AssetDescription']) ?? '-';
    $workId = firstNotEmpty($row, ['Work ID']) ?? '-';
    $accountName = firstNotEmpty($row, ['Account Name']) ?? '-';
    $accountId = firstNotEmpty($row, ['Account ID']) ?? '-';
    $sampler = firstNotEmpty($row, ['Sampler']) ?? '-';
    $dateSampledMeta = sampleDateMeta(firstNotEmpty($row, ['Date Sampled']));
    $dateReceivedMeta = sampleDateMeta(firstNotEmpty($row, ['Date Received']));

    return [
        'file' => basename($filePath),
        'recordIndex' => $recordIndex,
        'path' => $filePath,
        'sampleId' => $sampleId,
        'accountName' => $accountName,
        'accountId' => $accountId,
        'accountDisplay' => formatAccountDisplay($accountName, $accountId),
        'sampler' => $sampler,
        'assetId' => firstNotEmpty($row, ['Asset ID']) ?? '-',
        'unitId' => $unitId,
        'unitDescription' => $unitDescription,
        'componentNumber' => extractComponentNumber($unitId),
        'workId' => $workId,
        'workOrder' => formatWorkOrder($workId),
        'assetName' => firstNotEmpty($row, ['Asset Name']) ?? '-',
        'assetClass' => firstNotEmpty($row, ['Asset Class']) ?? '-',
        'sampleStatus' => firstNotEmpty($row, ['Sample Status']) ?? '-',
        'reportStatus' => firstNotEmpty($row, ['Report Status']) ?? '-',
        'contaminationRating' => firstNotEmpty($row, ['Contamination Rating']) ?? '-',
        'actionRequired' => hasActionRequiredComment($comments),
        'dateGroup' => $dateParts['groupKey'],
        'dateDisplay' => $dateParts['display'],
        'dateSort' => $dateParts['sort'],
        'dateSampledDisplay' => (string) ($dateSampledMeta['display'] ?? '-'),
        'dateSampledDaysSince' => $dateSampledMeta['daysSince'] ?? null,
        'dateReceivedDisplay' => (string) ($dateReceivedMeta['display'] ?? '-'),
        'dateReceivedDaysSince' => $dateReceivedMeta['daysSince'] ?? null,
        'raw' => $row
    ];
}

function loadSampleSummaries(string $samplePath, string $sourceName = 'Mobil'): array
{
    $summaries = [];

    foreach (listSampleJsonFiles($samplePath) as $filePath) {
        $rows = parseSampleJsonRecordsBySource($filePath, $sourceName);
        foreach ($rows as $recordIndex => $row) {
            if (!is_array($row) || $row === []) {
                continue;
            }

            $summaries[] = normalizeSampleSummary($filePath, $row, (int) $recordIndex);
        }
    }

    usort($summaries, static function (array $a, array $b): int {
        return strcmp($b['dateSort'], $a['dateSort']);
    });

    return $summaries;
}

function groupSummariesByDate(array $summaries): array
{
    $groups = [];

    foreach ($summaries as $summary) {
        $groups[$summary['dateGroup']]['label'] = $summary['dateDisplay'];
        $groups[$summary['dateGroup']]['items'][] = $summary;
    }

    krsort($groups);

    return $groups;
}

function formatValueForView($value): string
{
    if (is_array($value) || is_object($value)) {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '-';
    }

    $text = trim((string) $value);

    return $text === '' ? '-' : $text;
}

function getConfiguredInProgressCachePath(): string
{
    $config = $GLOBALS['mobilApiAuth'] ?? [];
    if (!is_array($config)) {
        $config = [];
    }

    $customFile = isset($config['inProgressCacheFile']) ? trim((string) $config['inProgressCacheFile']) : '';
    if ($customFile !== '') {
        return $customFile;
    }

    $cacheDir = isset($config['cacheDir']) ? trim((string) $config['cacheDir']) : '';
    if ($cacheDir === '') {
        $cacheDir = __DIR__ . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'mobil';
    }

    return rtrim($cacheDir, "\\/") . DIRECTORY_SEPARATOR . 'in-progress-reports.json';
}

function loadInProgressSummariesFromCache(string $cacheFile): array
{
    $rows = loadInProgressRowsFromCache($cacheFile);

    $summaries = [];
    foreach ($rows as $row) {
        if (!is_array($row) || $row === []) {
            continue;
        }

        $summaries[] = normalizeInProgressSummary($row);
    }

    usort($summaries, static function (array $a, array $b): int {
        return strcmp((string) ($b['dateSort'] ?? ''), (string) ($a['dateSort'] ?? ''));
    });

    return $summaries;
}

function loadInProgressRowsFromCache(string $cacheFile): array
{
    if ($cacheFile === '' || !is_file($cacheFile) || !is_readable($cacheFile)) {
        return [];
    }

    $raw = file_get_contents($cacheFile);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    if (isset($decoded['data']) && is_array($decoded['data'])) {
        return $decoded['data'];
    }

    if (array_is_list($decoded)) {
        return $decoded;
    }

    return [];
}

function findInProgressRowInCache(string $cacheFile, string $workflowReportId): array
{
    $workflowReportId = trim($workflowReportId);
    if ($workflowReportId === '') {
        return [];
    }

    foreach (loadInProgressRowsFromCache($cacheFile) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $candidate = firstNotEmpty($row, ['WorkFlowReportID', 'WorkflowReportID', 'Workflow Report ID']);
        if ($candidate !== null && trim($candidate) === $workflowReportId) {
            return $row;
        }
    }

    return [];
}

function normalizeInProgressSummary(array $row): array
{
    $sampleId = firstNotEmpty($row, ['LIMS Sample ID', 'Sample Bottle ID', 'LIMSSampleID', 'SampleBottleID', 'SampleBottleBarcodeID', 'Sample ID']) ?? '-';
    if ($sampleId === '-' || $sampleId === '') {
        $workflowReportId = firstNotEmpty($row, ['WorkFlowReportID', 'WorkflowReportID', 'Workflow Report ID']);
        if ($workflowReportId !== null && $workflowReportId !== '') {
            $sampleId = 'WF-' . $workflowReportId;
        }
    }
    $dateRaw = firstNotEmpty($row, [
        'Date Reported',
        'Date Sampled',
        'Date Received',
        'DateReported',
        'DateSampled',
        'DateReceived',
        'CreatedDate',
    ]);
    $dateParts = sampleDateParts($dateRaw);
    if ($dateParts === null) {
        $now = new DateTimeImmutable();
        $dateParts = [
            'groupKey' => $now->format('Y-m-d'),
            'display' => $now->format('d-m-Y'),
            'sort' => $now->format('YmdHis'),
        ];
    }

    $comments = firstNotEmpty($row, ['Comments']) ?? '';
    $unitId = firstNotEmpty($row, ['Unit ID', 'UnitID']) ?? '-';
    $unitDescription = firstNotEmpty($row, ['Unit Description', 'UnitDescription', 'Asset Description', 'AssetDescription']) ?? '-';
    $workId = firstNotEmpty($row, ['Work ID', 'WorkID']) ?? '-';
    $accountName = firstNotEmpty($row, ['Account Name', 'AccountName', 'ClientName']) ?? '-';
    $accountId = firstNotEmpty($row, ['Account ID', 'AccountID', 'ClientID', 'ClientId']) ?? '-';
    $sampler = firstNotEmpty($row, ['Sampler', 'ReportAuthorUsername']) ?? '-';
    $sampleStatus = firstNotEmpty($row, ['Sample Status', 'SampleStatus']) ?? '-';
    $lifeCycleRaw = firstNotEmpty($row, ['LifeCycleStage', 'LifecycleStage']) ?? '';
    $lifeCycle = normalizeLifeCycleStageLabel($lifeCycleRaw);
    $progressDateLabel = determineInProgressDateStageLabel($row);
    $progressLabel = $progressDateLabel !== '' ? $progressDateLabel : $sampleStatus;
    if ($lifeCycle !== '' && $lifeCycle !== '-') {
        if ($progressDateLabel === '') {
            $progressLabel = $sampleStatus !== '-' ? ($sampleStatus . ' / ' . $lifeCycle) : $lifeCycle;
        }
    }

    $dateSampledMeta = sampleDateMeta(firstNotEmpty($row, ['Date Sampled', 'DateSampled']));
    $dateReceivedMeta = sampleDateMeta(firstNotEmpty($row, ['Date Received', 'DateReceived']));

    return [
        'file' => '',
        'recordIndex' => 0,
        'path' => '',
        'sampleId' => $sampleId,
        'accountName' => $accountName,
        'accountId' => $accountId,
        'accountDisplay' => formatAccountDisplay($accountName, $accountId),
        'sampler' => $sampler,
        'assetId' => firstNotEmpty($row, ['Asset ID', 'AssetId']) ?? '-',
        'unitId' => $unitId,
        'unitDescription' => $unitDescription,
        'componentNumber' => extractComponentNumber($unitId),
        'workId' => $workId,
        'workOrder' => formatWorkOrder($workId),
        'assetName' => firstNotEmpty($row, ['Asset Name', 'AssetDescription']) ?? '-',
        'assetClass' => firstNotEmpty($row, ['Asset Class', 'AssetClass']) ?? '-',
        'sampleStatus' => $sampleStatus,
        'reportStatus' => '-',
        'progressStatus' => $progressLabel,
        'workflowReportId' => firstNotEmpty($row, ['WorkFlowReportID', 'WorkflowReportID', 'Workflow Report ID']) ?? '',
        'dateRegisteredRaw' => firstNotEmpty($row, ['DateRegistered']) ?? '-',
        'daysSinceSampled' => (int) (firstNotEmpty($row, ['DaysSinceSampled']) ?? '0'),
        'contaminationRating' => firstNotEmpty($row, ['Contamination Rating', 'SampleStatus']) ?? '-',
        'actionRequired' => hasActionRequiredComment($comments),
        'dateGroup' => $dateParts['groupKey'],
        'dateDisplay' => $dateParts['display'],
        'dateSort' => $dateParts['sort'],
        'dateSampledDisplay' => (string) ($dateSampledMeta['display'] ?? '-'),
        'dateSampledDaysSince' => $dateSampledMeta['daysSince'] ?? null,
        'dateReceivedDisplay' => (string) ($dateReceivedMeta['display'] ?? '-'),
        'dateReceivedDaysSince' => $dateReceivedMeta['daysSince'] ?? null,
        'isInProgress' => true,
        'raw' => $row,
    ];
}

function normalizeLifeCycleStageLabel(string $value): string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return '';
    }

    if (ctype_digit($trimmed)) {
        $map = [
            '0' => 'Registered',
            '1' => 'In Testing',
            '2' => 'Completed',
        ];

        return $map[$trimmed] ?? ('Stage ' . $trimmed);
    }

    return $trimmed;
}

function getConfiguredSamplePdfPath(): string
{
    foreach (getConfiguredSampleSources() as $source) {
        if ((string) ($source['type'] ?? '') !== 'pdf') {
            continue;
        }

        $path = trim((string) ($source['path'] ?? ''));
        if ($path !== '') {
            return rtrim($path, "\\/");
        }
    }

    return '';
}

function listSamplePdfFiles(string $samplePathPdf): array
{
    if ($samplePathPdf === '' || !is_dir($samplePathPdf)) {
        return [];
    }

    $pattern = $samplePathPdf . DIRECTORY_SEPARATOR . '*.pdf';
    $files = glob($pattern);
    if ($files === false) {
        return [];
    }

    usort($files, static function (string $a, string $b): int {
        return filemtime($b) <=> filemtime($a);
    });

    return $files;
}

function normalizeSampleTypeFromFilename(string $sampleTypeRaw): string
{
    $normalized = strtolower(trim($sampleTypeRaw));
    $normalized = str_replace(['-', ' '], '', $normalized);

    if ($normalized === 'diesel') {
        return 'Diesel';
    }

    if ($normalized === 'koelvloeistof') {
        return 'Koelvloeistof';
    }

    return trim($sampleTypeRaw) !== '' ? trim($sampleTypeRaw) : '-';
}

function parseSamplePdfFilenameMeta(string $filePath): array
{
    $fileName = basename($filePath);
    $baseName = preg_replace('/\.pdf$/i', '', $fileName) ?? $fileName;
    $baseName = preg_replace('/\.pdf$/i', '', $baseName) ?? $baseName;

    $parts = explode('_', (string) $baseName, 4);
    $dateRaw = trim((string) ($parts[0] ?? ''));
    $sampleTypeRaw = trim((string) ($parts[1] ?? ''));
    $workOrderRaw = trim((string) ($parts[2] ?? ''));
    $descriptionRaw = trim((string) ($parts[3] ?? ''));

    $dateDisplay = '-';
    $dateGroup = '';
    $dateSort = '';

    if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $dateRaw, $dateMatches)) {
        $isoDate = $dateMatches[3] . '-' . $dateMatches[2] . '-' . $dateMatches[1];
        $meta = sampleDateParts($isoDate);
        if ($meta !== null) {
            $dateDisplay = (string) ($meta['display'] ?? '-');
            $dateGroup = (string) ($meta['groupKey'] ?? '');
            $dateSort = (string) ($meta['sort'] ?? '');
        }
    }

    if ($dateSort === '') {
        $fallback = (new DateTimeImmutable())->setTimestamp((int) filemtime($filePath));
        $dateDisplay = $fallback->format('d-m-Y');
        $dateGroup = $fallback->format('Y-m-d');
        $dateSort = $fallback->format('YmdHis');
    }

    $sampleType = normalizeSampleTypeFromFilename($sampleTypeRaw);
    $workOrder = formatWorkOrder($workOrderRaw);
    $description = $descriptionRaw !== '' ? $descriptionRaw : '-';

    return [
        'file' => $fileName,
        'path' => $filePath,
        'sampleId' => pathinfo((string) $baseName, PATHINFO_FILENAME),
        'sampleType' => $sampleType,
        'sampleTypeKey' => strtolower($sampleType),
        'workOrder' => $workOrder,
        'description' => $description,
        'dateGroup' => $dateGroup,
        'dateDisplay' => $dateDisplay,
        'dateSort' => $dateSort,
    ];
}

function loadSamplePdfSummaries(string $samplePathPdf): array
{
    $summaries = [];

    foreach (listSamplePdfFiles($samplePathPdf) as $filePath) {
        $summaries[] = parseSamplePdfFilenameMeta($filePath);
    }

    usort($summaries, static function (array $a, array $b): int {
        return strcmp((string) ($b['dateSort'] ?? ''), (string) ($a['dateSort'] ?? ''));
    });

    return $summaries;
}
