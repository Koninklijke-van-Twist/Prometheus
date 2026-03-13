<?php

declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

/**
 * Includes/requires
 */
require_once __DIR__ . '/MobilApiClient.php';

/**
 * Variabelen
 */
$client = null;

/**
 * Functies
 */

function mobil_send_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

function mobil_send_fetch_html(array $payload, bool $isError = false): void
{
    $action = isset($_GET['action']) ? (string) $_GET['action'] : '';
    $view = isset($_GET['view']) ? (string) $_GET['view'] : '';
    $authEnv = isset($_GET['authenv']) ? (string) $_GET['authenv'] : '';
    $autoUrl = '?action=' . rawurlencode($action !== '' ? $action : 'fetch') . '&view=' . rawurlencode($view !== '' ? $view : 'html');
    if ($authEnv !== '') {
        $autoUrl .= '&authenv=' . rawurlencode($authEnv);
    }

    $statusText = (string) ($payload['status'] ?? ($isError ? 'error' : 'ok'));
    $message = (string) ($payload['message'] ?? '');
    $mode = (string) ($payload['mode'] ?? '');
    $currentChunkStart = (string) (($payload['current_chunk']['start'] ?? ''));
    $currentChunkEnd = (string) (($payload['current_chunk']['end'] ?? ''));
    $accountName = (string) ($payload['account_name'] ?? '');
    $accountIndex = (int) ($payload['account_index'] ?? -1);
    $accountsCount = (int) ($payload['accounts_count'] ?? 0);
    $stepIndex = (int) ($payload['step_index'] ?? 0);
    $completedSeen = (int) ($payload['completed_seen'] ?? 0);
    $completedSaved = (int) ($payload['completed_saved'] ?? 0);
    $backfillCompleted = (bool) ($payload['backfill_completed'] ?? false);
    $nextFetchUrl = $payload['next_fetch_url'] ?? null;
    $perf = $payload['performance_stats'] ?? [];
    $rawResponse = $payload['raw_response'] ?? [];
    $rawResponseText = json_encode($rawResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if (!is_string($rawResponseText)) {
        $rawResponseText = '[]';
    }
    $cacheFileInUse = (string) ($payload['cache_file_in_use'] ?? '');
    $reqCount = (int) ($perf['request_count'] ?? 0);
    $totalMs = (float) ($perf['total_ms'] ?? 0.0);
    $avgMs = (float) ($perf['avg_ms'] ?? 0.0);

    $shouldRefresh = !$backfillCompleted;
    if ($isError) {
        $shouldRefresh = true;
    }
    if ($nextFetchUrl === null && !$isError && $backfillCompleted) {
        $shouldRefresh = false;
    }

    http_response_code($isError ? 500 : 200);
    header('Content-Type: text/html; charset=utf-8');

    $title = $isError ? 'Mobil fetch fout' : 'Mobil fetch voortgang';
    $statusColor = $isError ? '#8f1c1c' : ($backfillCompleted ? '#21653e' : '#1d4f8f');
    $statusBg = $isError ? '#fde8e8' : ($backfillCompleted ? '#e7f5ea' : '#e8f1fb');

    echo '<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
    if ($shouldRefresh) {
        echo '<meta http-equiv="refresh" content="1;url=' . htmlspecialchars($autoUrl, ENT_QUOTES, 'UTF-8') . '">';
    }
    echo '<style>body{font-family:Verdana,Geneva,Tahoma,sans-serif;background:#f4f8fc;color:#123;padding:20px}';
    echo '.card{max-width:960px;margin:0 auto;background:#fff;border:1px solid #d4e1ee;border-radius:12px;padding:16px}';
    echo '.status{display:inline-block;padding:8px 10px;border-radius:8px;font-weight:700}';
    echo '.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;margin-top:14px}';
    echo '.item{border:1px solid #e2ebf5;border-radius:8px;padding:10px;background:#fafcff}';
    echo '.label{font-size:12px;color:#4a6681;text-transform:uppercase;letter-spacing:.05em}';
    echo '.value{font-size:15px;font-weight:700;color:#123;margin-top:4px;word-break:break-word}';
    echo '.storage{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:14px}';
    echo '.storage ul{margin:0;padding-left:20px}';
    echo '.storage li{font-size:13px;color:#36506a;word-break:break-word}';
    echo '.raw{margin-top:14px;border:1px solid #e2ebf5;border-radius:8px;background:#fbfdff;padding:10px}';
    echo '.raw pre{margin:0;max-height:220px;overflow:auto;font-size:12px;line-height:1.35;white-space:pre-wrap;word-break:break-word}';
    echo '.hint{margin-top:14px;font-size:13px;color:#36506a}</style></head><body>';
    echo '<div class="card">';
    echo '<div class="status" style="color:' . htmlspecialchars($statusColor, ENT_QUOTES, 'UTF-8') . ';background:' . htmlspecialchars($statusBg, ENT_QUOTES, 'UTF-8') . ';">';
    echo htmlspecialchars(strtoupper($statusText), ENT_QUOTES, 'UTF-8');
    echo '</div>';
    if ($message !== '') {
        echo '<p><strong>Bericht:</strong> ' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
    }
    echo '<div class="grid">';
    echo '<div class="item"><div class="label">Mode</div><div class="value">' . htmlspecialchars($mode !== '' ? $mode : '-', ENT_QUOTES, 'UTF-8') . '</div></div>';
    echo '<div class="item"><div class="label">Step</div><div class="value">' . htmlspecialchars((string) $stepIndex, ENT_QUOTES, 'UTF-8') . '</div></div>';
    echo '<div class="item"><div class="label">Periode</div><div class="value">' . htmlspecialchars(($currentChunkStart !== '' ? $currentChunkStart : '-') . ' t/m ' . ($currentChunkEnd !== '' ? $currentChunkEnd : '-'), ENT_QUOTES, 'UTF-8') . '</div></div>';
    echo '<div class="item"><div class="label">Account</div><div class="value">' . htmlspecialchars(($accountIndex >= 0 ? ($accountIndex + 1) . '/' . $accountsCount . ' - ' : '') . ($accountName !== '' ? $accountName : '-'), ENT_QUOTES, 'UTF-8') . '</div></div>';
    echo '<div class="item"><div class="label">Completed gezien</div><div class="value">' . htmlspecialchars((string) $completedSeen, ENT_QUOTES, 'UTF-8') . '</div></div>';
    echo '<div class="item"><div class="label">Completed opgeslagen</div><div class="value">' . htmlspecialchars((string) $completedSaved, ENT_QUOTES, 'UTF-8') . '</div></div>';
    echo '<div class="item"><div class="label">API calls</div><div class="value">' . htmlspecialchars((string) $reqCount, ENT_QUOTES, 'UTF-8') . '</div></div>';
    echo '<div class="item"><div class="label">API tijd</div><div class="value">' . htmlspecialchars(number_format($totalMs / 1000, 2, ',', '.') . 's totaal / ' . number_format($avgMs / 1000, 2, ',', '.') . 's avg', ENT_QUOTES, 'UTF-8') . '</div></div>';
    echo '</div>';
    echo '<div class="storage">';
    echo '<ul><li><strong>Cache-bestanden:</strong> ' . htmlspecialchars($cacheFileInUse !== '' ? $cacheFileInUse : '-', ENT_QUOTES, 'UTF-8') . '</li></ul>';
    echo '</div>';
    echo '<div class="raw"><div class="label">Raw Response</div><pre>' . htmlspecialchars($rawResponseText, ENT_QUOTES, 'UTF-8') . '</pre></div>';
    if ($shouldRefresh) {
        echo '<p class="hint">Automatisch verder over 1 seconde...</p>';
    } else {
        echo '<p class="hint">Backfill gereed. Automatische refresh gestopt.</p>';
    }
    echo '</div></body></html>';
}

require_once __DIR__ . '/auth.php';

function mobil_exception_payload(Throwable $e): array
{
    $chain = [];
    $current = $e;
    while ($current instanceof Throwable) {
        $chain[] = [
            'class' => get_class($current),
            'message' => $current->getMessage(),
            'code' => $current->getCode(),
            'file' => $current->getFile(),
            'line' => $current->getLine(),
        ];
        $current = $current->getPrevious();
    }

    return [
        'class' => get_class($e),
        'message' => $e->getMessage(),
        'code' => $e->getCode(),
        'chain' => $chain,
    ];
}

/**
 * Page load
 */
try {
    $mobilConfig = [];
    if (isset($GLOBALS['mobilApiAuth']) && is_array($GLOBALS['mobilApiAuth'])) {
        $mobilConfig = $GLOBALS['mobilApiAuth'];
    }

    $environment = isset($_GET['environment'])
        ? strtolower(trim((string) $_GET['environment']))
        : (string) ($mobilConfig['environment'] ?? 'prd');
    if ($environment === '') {
        $environment = 'prd';
    }

    $authEnv = isset($_GET['authenv'])
        ? strtolower(trim((string) $_GET['authenv']))
        : (string) ($mobilConfig['authEnvironment'] ?? 'acc');
    if ($authEnv === '') {
        $authEnv = 'acc';
    }

    $authMode = isset($_GET['authmode'])
        ? strtolower(trim((string) $_GET['authmode']))
        : (string) ($mobilConfig['authMode'] ?? 'username');
    if ($authMode === '') {
        $authMode = 'username';
    }

    $username = isset($_GET['username'])
        ? trim((string) $_GET['username'])
        : (string) ($mobilConfig['username'] ?? '');
    $password = isset($_GET['password'])
        ? trim((string) $_GET['password'])
        : (string) ($mobilConfig['password'] ?? '');
    $apiKey = isset($_GET['apikey'])
        ? trim((string) $_GET['apikey'])
        : (string) ($mobilConfig['apiKey'] ?? '');
    $authEmail = isset($_GET['email'])
        ? trim((string) $_GET['email'])
        : (string) ($mobilConfig['authEmail'] ?? '');
    $authUserId = isset($_GET['userid'])
        ? trim((string) $_GET['userid'])
        : (string) ($mobilConfig['authUserId'] ?? '');
    $inProgressCacheFile = isset($_GET['inprogresscache'])
        ? trim((string) $_GET['inprogresscache'])
        : (string) ($mobilConfig['inProgressCacheFile'] ?? '');

    $client = new MobilApiClient([
        'environment' => $environment,
        'authEnvironment' => $authEnv,
        'authMode' => $authMode,
        'username' => $username,
        'password' => $password,
        'inProgressCacheFile' => $inProgressCacheFile,
        'apiKey' => $apiKey,
        'authEmail' => $authEmail,
        'authUserId' => $authUserId,
    ]);

    $action = isset($_GET['action']) ? strtolower(trim((string) $_GET['action'])) : '';
    $view = isset($_GET['view']) ? strtolower(trim((string) $_GET['view'])) : '';
    $isHtmlView = $view === 'html';

    if ($action === 'fetch') {
        @ini_set('max_execution_time', '1800');
        @set_time_limit(1800);
        $result = $client->fetchCompletedReportsIncremental();
        $result['performance_stats'] = $client->getPerformanceStats();
        $result['raw_response'] = $client->getRawResponses();
        $result['cache_file_in_use'] = $client->getInProgressCacheFilePath();
        if ($isHtmlView) {
            mobil_send_fetch_html($result, false);
        } else {
            mobil_send_json($result);
        }
        return;
    }

    mobil_send_json([
        'status' => 'ok',
        'message' => 'Mobil API endpoint ready',
        'actions' => [
            'fetch' => '?action=fetch',
        ],
        'auth_environment_default' => $authEnv,
        'hint' => 'Override auth environment with ?authenv=acc or ?authenv=prd',
        'auth_mode_default' => $authMode,
    ]);
} catch (Throwable $e) {
    $lastHttpExchange = ($client instanceof MobilApiClient) ? $client->getLastHttpExchange() : [];
    $performanceStats = ($client instanceof MobilApiClient) ? $client->getPerformanceStats() : [];
    $rawResponse = ($client instanceof MobilApiClient) ? $client->getRawResponses() : [];
    $view = isset($_GET['view']) ? strtolower(trim((string) $_GET['view'])) : '';
    $isHtmlView = $view === 'html';

    $errorPayload = [
        'status' => 'error',
        'message' => $e->getMessage(),
        'exception' => mobil_exception_payload($e),
        'action' => isset($_GET['action']) ? (string) $_GET['action'] : '',
        'last_http_exchange' => $lastHttpExchange,
        'performance_stats' => $performanceStats,
        'raw_response' => $rawResponse,
    ];

    if ($isHtmlView && (isset($_GET['action']) && strtolower((string) $_GET['action']) === 'fetch')) {
        mobil_send_fetch_html($errorPayload, true);
        return;
    }

    mobil_send_json($errorPayload, 500);
}
