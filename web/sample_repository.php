<?php

declare(strict_types=1);

/**
 * Shared helpers to read and normalize oil sample JSON files.
 */
function getConfiguredSamplePath(): string
{
    if (!isset($GLOBALS['samplePath']) || !is_string($GLOBALS['samplePath']) || trim($GLOBALS['samplePath']) === '') {
        return '';
    }

    return rtrim(trim($GLOBALS['samplePath']), "\\/");
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

    if (is_array($decoded) && isset($decoded[0]) && is_array($decoded[0])) {
        return $decoded[0];
    }

    return is_array($decoded) ? $decoded : [];
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

function normalizeSampleSummary(string $filePath, array $row): array
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
    $workId = firstNotEmpty($row, ['Work ID']) ?? '-';
    $accountName = firstNotEmpty($row, ['Account Name']) ?? '-';
    $accountId = firstNotEmpty($row, ['Account ID']) ?? '-';
    $sampler = firstNotEmpty($row, ['Sampler']) ?? '-';

    return [
        'file' => basename($filePath),
        'path' => $filePath,
        'sampleId' => $sampleId,
        'accountName' => $accountName,
        'accountId' => $accountId,
        'accountDisplay' => formatAccountDisplay($accountName, $accountId),
        'sampler' => $sampler,
        'assetId' => firstNotEmpty($row, ['Asset ID']) ?? '-',
        'unitId' => $unitId,
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
        'raw' => $row
    ];
}

function loadSampleSummaries(string $samplePath): array
{
    $summaries = [];

    foreach (listSampleJsonFiles($samplePath) as $filePath) {
        $row = parseSampleJsonFile($filePath);
        if ($row === []) {
            continue;
        }

        $summaries[] = normalizeSampleSummary($filePath, $row);
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
