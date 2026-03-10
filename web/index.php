<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/sample_repository.php';

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
            'label' => (string) ($style['label'] ?? $status),
            'borderColor' => (string) ($style['border'] ?? '#c8d4d6'),
            'inlineStyle' => 'background:' . ($style['background'] ?? '#edf1f2')
                . ';color:' . ($style['text'] ?? '#405558')
                . ';border-color:' . ($style['border'] ?? '#c8d4d6')
                . ';',
        ];
    }

    return [
        'label' => $status !== '' ? $status : (string) ($unknown['label'] ?? 'Onbekend'),
        'borderColor' => (string) ($unknown['border'] ?? '#c8d4d6'),
        'inlineStyle' => 'background:' . ($unknown['background'] ?? '#edf1f2')
            . ';color:' . ($unknown['text'] ?? '#405558')
            . ';border-color:' . ($unknown['border'] ?? '#c8d4d6')
            . ';',
    ];
}

$samplePathResolved = getConfiguredSamplePath();
$summaries = loadSampleSummaries($samplePathResolved);
$groups = groupSummariesByDate($summaries);

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
            max-width: 1120px;
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
            flex-direction: column;
            gap: 6px;
            align-items: flex-end;
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
            <p>Bekijk beschikbare samples en klik door voor detailinformatie per sample.</p>
        </section>

        <?php if (!$pathOk): ?>
            <div class="warn">Het ingestelde sample pad bestaat niet:
                <code><?= htmlspecialchars($samplePathResolved, ENT_QUOTES, 'UTF-8') ?></code>
            </div>
        <?php elseif (count($summaries) === 0): ?>
            <div class="warn">Er zijn geen leesbare JSON-samples gevonden in
                <code><?= htmlspecialchars($samplePathResolved, ENT_QUOTES, 'UTF-8') ?></code>.
            </div>
        <?php else: ?>
            <?php foreach ($groups as $group): ?>
                <section class="group">
                    <h2><?= htmlspecialchars($group['label'], ENT_QUOTES, 'UTF-8') ?> (<?= count($group['items']) ?>)</h2>
                    <div class="grid">
                        <?php foreach ($group['items'] as $item): ?>
                            <?php $reportStatus = reportStatusMeta((string) ($item['reportStatus'] ?? '')); ?>
                            <a class="card" href="sample.php?file=<?= urlencode($item['file']) ?>"
                                style="border-color: <?= htmlspecialchars($reportStatus['borderColor'], ENT_QUOTES, 'UTF-8') ?>; --card-top-accent: <?= htmlspecialchars($reportStatus['borderColor'], ENT_QUOTES, 'UTF-8') ?>;">
                                <div class="card-head">
                                    <strong><?= htmlspecialchars($item['sampleId'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <div class="chip-stack">
                                        <span class="chip"
                                            style="<?= htmlspecialchars($reportStatus['inlineStyle'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($reportStatus['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php if (!empty($item['actionRequired'])): ?>
                                            <span class="chip chip-action">Action Required</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="row">Account: <?= htmlspecialchars($item['accountName'], ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="row">Asset ID: <?= htmlspecialchars($item['assetId'], ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="row">Asset class: <?= htmlspecialchars($item['assetClass'], ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <div class="row">Contamination status:
                                    <?= htmlspecialchars($item['contaminationRating'], ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>

</html>