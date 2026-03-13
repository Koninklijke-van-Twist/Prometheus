<?php


/**
 * Variabelen
 */

// Centrale UI-configuratie voor makkelijk tunen van kleuren/drempels.
$prometheusConfig = [
    'colorStyle' => [
        'bg' => '#ffffff',
        'ink' => '#0b2f57',
        'brand' => '#00529B',
        'brandSoft' => '#d9ebf8',
        'line' => '#c7d9ea',
        'card' => '#ffffff',
        'muted' => '#2a4f78',
        'okBackground' => '#e6f2fb',
        'okBorder' => '#a9ccec',
        'okText' => '#00529B',

        'pageGlowOne' => '#edf6fd',
        'pageGlowTwo' => '#e4f8fc',
        'heroGradientStart' => '#00529B',
        'heroGradientEnd' => '#0099cc',
        'heroBorder' => '#0a6fb3',
        'groupTitle' => '#00529B',
        'cardHoverBorder' => '#6db8df',
        'cardHoverShadow' => 'rgba(0, 82, 155, 0.14)',
        'cardTopAccentDefault' => '#0099cc',

        'actionChipBackground' => '#fff4db',
        'actionChipText' => '#8a5b14',
        'actionChipBorder' => '#eccf9a',

        'warnBackground' => '#edf7ff',
        'warnBorder' => '#a9ccec',
        'warnText' => '#0b2f57',

        'subtleBorder' => '#d6e6f4',
        'subtleCardBackground' => '#ffffff',
        'tableLine' => '#dbe8f4',
        'barTrack' => '#e8f1f9',
        'buttonText' => '#ffffff',
    ],
    'statusStyles' => [
        'normal' => [
            'label' => 'Normal',
            'background' => '#e7f5ea',
            'text' => '#21653e',
            'border' => '#b8dec5',
        ],
        'caution' => [
            'label' => 'Caution',
            'background' => '#fff4db',
            'text' => '#8a5b14',
            'border' => '#eccf9a',
        ],
        'alert' => [
            'label' => 'Alert',
            'background' => '#fde8e8',
            'text' => '#8f1c1c',
            'border' => '#e5b1b1',
        ],
        'severe' => [
            'label' => 'Severe',
            'background' => '#fde8e8',
            'text' => '#8f1c1c',
            'border' => '#e5b1b1',
        ],
    ],
    'unknownStatusStyle' => [
        'label' => 'Onbekend',
        'background' => '#edf1f2',
        'text' => '#405558',
        'border' => '#c8d4d6',
    ],
    // Element-kleuren voor stoffenkaartjes (niet gebonden aan bedrijfshuisstijl).
    'elementColors' => [
        'ag' => ['accent' => '#c0c0c0', 'bg' => '#f7f7f7', 'text' => '#2f3a45'],
        'al' => ['accent' => '#a9b0b8', 'bg' => '#f3f5f7', 'text' => '#2d3945'],
        'b' => ['accent' => '#d27fa6', 'bg' => '#fdf2f7', 'text' => '#4c2f40'],
        'ba' => ['accent' => '#b7c9a8', 'bg' => '#f5f9f2', 'text' => '#33432f'],
        'ca' => ['accent' => '#d9d2b6', 'bg' => '#fbfaf4', 'text' => '#4c4535'],
        'cr' => ['accent' => '#8ea6b4', 'bg' => '#f1f6f9', 'text' => '#2f4250'],
        'cu' => ['accent' => '#b87333', 'bg' => '#fbf2ea', 'text' => '#4c2f1a'],
        'fe' => ['accent' => '#b7410e', 'bg' => '#fdf0ea', 'text' => '#4e2717'],
        'k' => ['accent' => '#7f5aa2', 'bg' => '#f4effa', 'text' => '#3d2951'],
        'mg' => ['accent' => '#7fb069', 'bg' => '#f2f8ef', 'text' => '#334a2b'],
        'mo' => ['accent' => '#5d6d7e', 'bg' => '#f1f4f7', 'text' => '#2f3944'],
        'na' => ['accent' => '#f4d35e', 'bg' => '#fff9e7', 'text' => '#4f420f'],
        'ni' => ['accent' => '#6e7f8d', 'bg' => '#f2f5f7', 'text' => '#2e3b45'],
        'p' => ['accent' => '#e67e22', 'bg' => '#fdf2e8', 'text' => '#553010'],
        'pb' => ['accent' => '#4a4a4a', 'bg' => '#f2f2f2', 'text' => '#212121'],
        'si' => ['accent' => '#c2b280', 'bg' => '#faf7ef', 'text' => '#4b4330'],
        'sn' => ['accent' => '#bdc3c7', 'bg' => '#f6f8f9', 'text' => '#33414c'],
        'ti' => ['accent' => '#9aa3ad', 'bg' => '#f3f5f7', 'text' => '#333d47'],
        'v' => ['accent' => '#4d908e', 'bg' => '#edf7f6', 'text' => '#1f4a49'],
        'zn' => ['accent' => '#7f8c8d', 'bg' => '#f2f5f5', 'text' => '#2d3b3c'],
    ],
    'unknownElementColor' => [
        'accent' => '#9aa6b2',
        'bg' => '#f4f7f9',
        'text' => '#2f3d4a',
    ],
    // Bar-kleur per percentage van totale gemeten stoffen.
    'substanceBarThresholds' => [
        ['min' => 35.0, 'color' => '#00529B'],
        ['min' => 15.0, 'color' => '#0099cc'],
        ['min' => 0.0, 'color' => '#33ccff'],
    ],
];
