<?php

declare(strict_types=1);

/**
 * Generic helpers to build time-series chart data from sample JSON files.
 */
function graphNormalizeNumber($value): ?float
{
    if ($value === null) {
        return null;
    }

    if (is_int($value) || is_float($value)) {
        return (float) $value;
    }

    if (!is_string($value)) {
        return null;
    }

    $trimmed = trim($value);
    if ($trimmed === '' || $trimmed === '-') {
        return null;
    }

    $normalized = str_replace(',', '.', $trimmed);
    if (!preg_match('/-?\d+(?:\.\d+)?/', $normalized, $matches)) {
        return null;
    }

    return (float) $matches[0];
}

function graphFindFirstNumeric(array $row, array $candidateKeys): ?float
{
    foreach ($candidateKeys as $key) {
        if (!array_key_exists($key, $row)) {
            continue;
        }

        $numeric = graphNormalizeNumber($row[$key]);
        if ($numeric !== null) {
            return $numeric;
        }
    }

    return null;
}

function graphLoadAssetHistoryRows(string $samplePath, string $assetId): array
{
    $assetIdTrimmed = trim($assetId);
    if ($assetIdTrimmed === '' || $assetIdTrimmed === '-') {
        return [];
    }

    $historyRows = [];

    foreach (listSampleJsonFiles($samplePath) as $filePath) {
        $row = parseSampleJsonFile($filePath);
        if ($row === []) {
            continue;
        }

        $rowAssetId = trim((string) ($row['Asset ID'] ?? ''));
        if ($rowAssetId !== $assetIdTrimmed) {
            continue;
        }

        $dateRaw = trim((string) ($row['Date Sampled'] ?? ''));
        $dateParts = sampleDateParts($dateRaw);

        if ($dateParts === null) {
            $dt = (new DateTimeImmutable())->setTimestamp((int) filemtime($filePath));
            $dateParts = [
                'groupKey' => $dt->format('Y-m-d'),
                'display' => $dt->format('d-m-Y'),
                'sort' => $dt->format('YmdHis'),
            ];
        }

        $historyRows[] = [
            'dateSort' => (string) ($dateParts['sort'] ?? ''),
            'dateLabel' => (string) ($dateParts['display'] ?? ''),
            'row' => $row,
        ];
    }

    usort($historyRows, static function (array $a, array $b): int {
        return strcmp((string) $a['dateSort'], (string) $b['dateSort']);
    });

    return $historyRows;
}

function graphBuildChartData(array $historyRows, array $seriesDefinitions): array
{
    $labels = array_map(static function (array $item): string {
        return (string) ($item['dateLabel'] ?? '');
    }, $historyRows);

    $datasets = [];

    foreach ($seriesDefinitions as $definition) {
        if (!is_array($definition)) {
            continue;
        }

        $label = (string) ($definition['label'] ?? 'Serie');
        $keys = $definition['keys'] ?? [];
        $color = (string) ($definition['color'] ?? '#00529B');
        if (!is_array($keys) || $keys === []) {
            continue;
        }

        $values = [];
        foreach ($historyRows as $item) {
            $row = (array) ($item['row'] ?? []);
            $values[] = graphFindFirstNumeric($row, $keys);
        }

        $datasets[] = [
            'label' => $label,
            'data' => $values,
            'borderColor' => $color,
            'backgroundColor' => $color,
        ];
    }

    return [
        'labels' => $labels,
        'datasets' => $datasets,
        'points' => count($labels),
    ];
}

function graphDashboardDefinitions(): array
{
    return [
        'viscosity' => [
            'title' => 'Viscosity @ 100C over tijd',
            'series' => [
                [
                    'label' => 'Viscosity @ 100C',
                    'keys' => ['Viscosity @ 100C', 'Viscosity @100C', 'Viscosity@100C'],
                    'color' => '#00529B',
                ],
            ],
        ],
        'wear' => [
            'title' => 'Wear',
            'series' => [
                ['label' => 'Ag', 'keys' => ['Ag (Silver)'], 'color' => '#c0c0c0'],
                ['label' => 'Al', 'keys' => ['Al (Aluminum)'], 'color' => '#8b98a5'],
                ['label' => 'Cr', 'keys' => ['Cr (Chromium)'], 'color' => '#4f6d7a'],
                ['label' => 'Cu', 'keys' => ['Cu (Copper)'], 'color' => '#b87333'],
                ['label' => 'Fe', 'keys' => ['Fe (Iron)'], 'color' => '#b7410e'],
                ['label' => 'Ni', 'keys' => ['Ni (Nickel)'], 'color' => '#6e7f8d'],
                ['label' => 'Pb', 'keys' => ['Pb (Lead)'], 'color' => '#4a4a4a'],
                ['label' => 'Sn', 'keys' => ['Sn (Tin)'], 'color' => '#a7afb8'],
                ['label' => 'Ti', 'keys' => ['Ti (Titanium)'], 'color' => '#74818e'],
            ],
        ],
        'contaminants' => [
            'title' => 'Contaminants',
            'series' => [
                ['label' => 'Na', 'keys' => ['Na (Sodium)'], 'color' => '#f4d35e'],
                ['label' => 'K', 'keys' => ['K (Potassium)'], 'color' => '#7f5aa2'],
                ['label' => 'Si', 'keys' => ['Si (Silicon)'], 'color' => '#c2b280'],
                ['label' => 'V', 'keys' => ['V (Vanadium)'], 'color' => '#4d908e'],
            ],
        ],
        'oxidationSoot' => [
            'title' => 'Oxidation en Soot',
            'series' => [
                [
                    'label' => 'Oxidation (Ab/cm)',
                    'keys' => ['Oxidation (Ab/cm)', 'Oxidation (Abs/cm)- no Ref', 'Oxidation abs/0.1mm'],
                    'color' => '#9c4dcc',
                ],
                [
                    'label' => 'Soot (Wt%)',
                    'keys' => ['Soot (Wt%)', 'Soot'],
                    'color' => '#2f3d4a',
                ],
            ],
        ],
    ];
}

function graphBuildDashboardCharts(array $historyRows): array
{
    $charts = [];
    foreach (graphDashboardDefinitions() as $key => $definition) {
        $series = (array) ($definition['series'] ?? []);
        $charts[$key] = graphBuildChartData($historyRows, $series);
    }

    return $charts;
}
