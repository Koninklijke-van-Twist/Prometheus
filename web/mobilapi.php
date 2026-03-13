<?php

declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

/**
 * Mobil REST API synchronizer.
 *
 * Features:
 * - Fetch completed reports incrementally since last run.
 * - First run performs yearly backfill.
 * - Store completed reports as immutable JSON files in /web/mobilreports.
 * - Cache in-progress samples for 1 hour.
 * - HTTP endpoint with ?action=fetch.
 */
final class MobilApiClient
{
    private string $environment;
    private string $authEnvironment;
    private string $baseUrl;
    private string $apiOrigin;
    private string $username;
    private string $password;
    private string $apiKey;
    private string $authEmail;
    private string $authUserId;
    private string $authMode;
    private string $authEndpoint;
    private int $timeoutSeconds;
    private string $reportsDir;
    private string $cacheDir;
    private string $stateFile;
    private string $inProgressCacheFile;
    private ?string $bearerToken = null;
    private ?int $tokenExpiresAt = null;
    private array $lastHttpExchange = [];
    private array $rawResponses = [];
    private int $httpRequestCount = 0;
    private float $httpDurationTotalMs = 0.0;
    private array $httpEndpointStats = [];

    public function __construct(array $options = [])
    {
        $this->environment = (string) ($options['environment'] ?? 'prd');
        $this->authEnvironment = (string) ($options['authEnvironment'] ?? 'acc');
        $this->baseUrl = rtrim((string) ($options['baseUrl'] ?? 'https://api.ucld.us/env/' . $this->environment), '/');
        $this->apiOrigin = $this->resolveApiOrigin($this->baseUrl);

        $this->username = (string) ($options['username'] ?? 'ict@kvt.nl');
        $this->password = (string) ($options['password'] ?? 'asdLKJfhg1982!');
        $this->apiKey = trim((string) ($options['apiKey'] ?? ''));
        $this->authEmail = trim((string) ($options['authEmail'] ?? ''));
        $this->authUserId = trim((string) ($options['authUserId'] ?? ''));
        $this->authMode = strtolower(trim((string) ($options['authMode'] ?? 'username')));
        if ($this->authMode === '') {
            $this->authMode = 'username';
        }

        // Override this if your tenant uses another auth route.
        $this->authEndpoint = (string) ($options['authEndpoint'] ?? '/authenticate');
        $this->timeoutSeconds = max(10, (int) ($options['timeoutSeconds'] ?? 60));

        $root = __DIR__;
        $this->reportsDir = (string) ($options['reportsDir'] ?? ($root . DIRECTORY_SEPARATOR . 'mobilreports'));
        $this->cacheDir = (string) ($options['cacheDir'] ?? ($root . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'mobil'));

        $this->stateFile = $this->cacheDir . DIRECTORY_SEPARATOR . 'fetch_state.json';
        $this->inProgressCacheFile = $this->cacheDir . DIRECTORY_SEPARATOR . 'in_progress_cache.json';

        $this->ensureDirectory($this->reportsDir);
        $this->ensureDirectory($this->cacheDir);
    }

    public function fetchCompletedReportsIncremental(): array
    {
        $state = $this->readState();
        $lastFetchAt = $state['last_completed_fetch_at'] ?? null;
        $isInitialBackfill = !is_string($lastFetchAt) || trim($lastFetchAt) === '';

        if ($isInitialBackfill) {
            return $this->fetchCompletedReportsInitialWeeklyStep($state);
        }

        $ranges = $this->buildFetchRanges($lastFetchAt);
        $summary = [
            'status' => 'ok',
            'environment' => $this->environment,
            'mode' => 'incremental',
            'ranges' => $ranges,
            'sampledata_chunk_mode' => [],
            'accounts_count' => 0,
            'completed_seen' => 0,
            'completed_saved' => 0,
            'completed_skipped_missing_keys' => 0,
            'completed_skipped_existing' => 0,
            'completed_failed_detail' => 0,
            'in_progress_count' => 0,
            'in_progress_cache_expires_at' => null,
            'started_at' => gmdate('c'),
            'finished_at' => null,
        ];

        $accounts = $this->getMyAccounts();
        $summary['accounts_count'] = count($accounts);

        $samplesByNaturalKey = [];
        foreach ($ranges as $range) {
            $chunkResult = $this->getSampleDataAdaptive($accounts, $range['start'], $range['end']);
            $rows = $chunkResult['rows'];
            $summary['sampledata_chunk_mode'][] = [
                'start' => $range['start'],
                'end' => $range['end'],
                'mode' => $chunkResult['mode'],
                'chunks' => $chunkResult['chunks'],
            ];

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                if (!$this->isCompletedSample($row)) {
                    continue;
                }

                $summary['completed_seen']++;

                $accountId = $this->extractAccountId($row);
                $limsSampleId = $this->extractLimsSampleId($row);

                if ($accountId === '' || $limsSampleId === '') {
                    $summary['completed_skipped_missing_keys']++;
                    continue;
                }

                $naturalKey = strtolower($accountId . '|' . $limsSampleId);
                if (!isset($samplesByNaturalKey[$naturalKey])) {
                    $samplesByNaturalKey[$naturalKey] = [];
                }
                $samplesByNaturalKey[$naturalKey][] = $row;
            }
        }

        foreach ($samplesByNaturalKey as $sampleRows) {
            if (!is_array($sampleRows) || $sampleRows === [] || !isset($sampleRows[0]) || !is_array($sampleRows[0])) {
                continue;
            }

            $sample = $sampleRows[0];
            $accountId = $this->extractAccountId($sample);
            $limsSampleId = $this->extractLimsSampleId($sample);
            $filePath = $this->buildReportFilePath($accountId, $limsSampleId);

            if (is_file($filePath)) {
                $summary['completed_skipped_existing']++;
                continue;
            }

            $saved = $this->storeCompletedReport($accountId, $limsSampleId, $sampleRows, json_encode($sampleRows, JSON_UNESCAPED_UNICODE));
            if ($saved) {
                $summary['completed_saved']++;
            }
        }

        $inProgress = $this->getInProgressSamples(true);
        $summary['in_progress_count'] = count($inProgress['data']);
        $summary['in_progress_cache_expires_at'] = $inProgress['expires_at'];

        $state['last_completed_fetch_at'] = gmdate('c');
        $state['last_completed_fetch_unix'] = time();
        $state['last_completed_saved_total'] = (int) ($state['last_completed_saved_total'] ?? 0) + (int) $summary['completed_saved'];
        $this->writeState($state);

        $summary['finished_at'] = gmdate('c');

        return $summary;
    }

    private function fetchCompletedReportsInitialWeeklyStep(array $state): array
    {
        $today = new DateTimeImmutable(gmdate('Y-m-d'));

        $cursorEndRaw = (string) ($state['initial_backfill_cursor_end'] ?? '');
        $cursorEnd = DateTimeImmutable::createFromFormat('Y-m-d', $cursorEndRaw);
        if (!$cursorEnd instanceof DateTimeImmutable) {
            $cursorEnd = $today;
        }

        $chunkStart = $cursorEnd->modify('first day of this month');
        $chunkEnd = $cursorEnd;

        $accounts = $this->getMyAccounts();
        $accountNames = $this->extractAccountCriteriaFromAccounts($accounts);
        if (count($accountNames) === 0) {
            $fallbackAccounts = $this->getAccounts('');
            $accountNames = $this->extractAccountCriteriaFromAccounts($fallbackAccounts);
        }

        if (count($accountNames) === 0) {
            throw new RuntimeException('No account names available for initial weekly backfill.');
        }

        $accountIndex = (int) ($state['initial_backfill_account_index'] ?? 0);
        if ($accountIndex < 0) {
            $accountIndex = 0;
        }

        $weekSeenTotal = (int) ($state['initial_backfill_week_seen_total'] ?? 0);
        $emptyWeekStreak = (int) ($state['initial_backfill_empty_week_streak'] ?? 0);
        $emptyWeeksToStop = isset($_GET['emptyMonthsToStop']) && ctype_digit((string) $_GET['emptyMonthsToStop'])
            ? max(1, (int) $_GET['emptyMonthsToStop'])
            : 8;

        if ($accountIndex >= count($accountNames)) {
            if ($weekSeenTotal === 0 && $emptyWeekStreak >= $emptyWeeksToStop) {
                $state['last_completed_fetch_at'] = gmdate('c');
                $state['last_completed_fetch_unix'] = time();
                unset(
                    $state['initial_backfill_cursor_end'],
                    $state['initial_backfill_step_index'],
                    $state['initial_backfill_account_index'],
                    $state['initial_backfill_week_seen_total'],
                    $state['initial_backfill_empty_week_streak']
                );

                $inProgress = $this->getInProgressSamples(true);
                $summary = [
                    'status' => 'ok',
                    'environment' => $this->environment,
                    'mode' => 'initial-weekly-step',
                    'backfill_completed' => true,
                    'current_chunk' => [
                        'start' => $chunkStart->format('Y-m-d'),
                        'end' => $chunkEnd->format('Y-m-d'),
                    ],
                    'in_progress_count' => count($inProgress['data']),
                    'in_progress_cache_expires_at' => $inProgress['expires_at'],
                    'next_fetch_url' => null,
                    'empty_week_streak' => $emptyWeekStreak,
                    'empty_weeks_to_stop' => $emptyWeeksToStop,
                    'started_at' => gmdate('c'),
                    'finished_at' => gmdate('c'),
                ];

                $this->writeState($state);
                return $summary;
            }

            $weekSeenTotal = 0;
            $accountIndex = 0;
            $cursorEnd = $chunkStart->modify('-1 day');
            $chunkStart = $cursorEnd->modify('first day of this month');
            $chunkEnd = $cursorEnd;
        }

        $accountName = $accountNames[$accountIndex];

        $summary = [
            'status' => 'ok',
            'environment' => $this->environment,
            'mode' => 'initial-monthly-step',
            'current_chunk' => [
                'start' => $chunkStart->format('Y-m-d'),
                'end' => $chunkEnd->format('Y-m-d'),
            ],
            'accounts_count' => count($accountNames),
            'account_index' => $accountIndex,
            'account_name' => $accountName,
            'completed_seen' => 0,
            'completed_saved' => 0,
            'completed_skipped_missing_keys' => 0,
            'completed_skipped_existing' => 0,
            'completed_failed_detail' => 0,
            'in_progress_count' => 0,
            'in_progress_cache_expires_at' => null,
            'backfill_cursor_end' => $cursorEnd->format('Y-m-d'),
            'next_fetch_url' => '?action=fetch',
            'started_at' => gmdate('c'),
            'finished_at' => null,
        ];

        $rows = $this->getSampleDataForAccountBatch([$accountName], $chunkStart->format('Y-m-d'), $chunkEnd->format('Y-m-d'));
        $summary['sampledata_chunk_mode'] = [
            [
                'start' => $chunkStart->format('Y-m-d'),
                'end' => $chunkEnd->format('Y-m-d'),
                'mode' => 'single-account-month',
                'chunks' => 1,
            ],
        ];

        $samplesByNaturalKey = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            if (!$this->isCompletedSample($row)) {
                continue;
            }

            $summary['completed_seen']++;

            $accountId = $this->extractAccountId($row);
            $limsSampleId = $this->extractLimsSampleId($row);

            if ($accountId === '' || $limsSampleId === '') {
                $summary['completed_skipped_missing_keys']++;
                continue;
            }

            $naturalKey = strtolower($accountId . '|' . $limsSampleId);
            if (!isset($samplesByNaturalKey[$naturalKey])) {
                $samplesByNaturalKey[$naturalKey] = [];
            }
            $samplesByNaturalKey[$naturalKey][] = $row;
        }

        foreach ($samplesByNaturalKey as $sampleRows) {
            if (!is_array($sampleRows) || $sampleRows === [] || !isset($sampleRows[0]) || !is_array($sampleRows[0])) {
                continue;
            }

            $sample = $sampleRows[0];
            $accountId = $this->extractAccountId($sample);
            $limsSampleId = $this->extractLimsSampleId($sample);
            $filePath = $this->buildReportFilePath($accountId, $limsSampleId);

            if (is_file($filePath)) {
                $summary['completed_skipped_existing']++;
                continue;
            }

            $saved = $this->storeCompletedReport($accountId, $limsSampleId, $sampleRows, json_encode($sampleRows, JSON_UNESCAPED_UNICODE));
            if ($saved) {
                $summary['completed_saved']++;
            }
        }

        $stepIndex = (int) ($state['initial_backfill_step_index'] ?? 0) + 1;
        $state['initial_backfill_step_index'] = $stepIndex;
        $summary['step_index'] = $stepIndex;

        $state['last_completed_saved_total'] = (int) ($state['last_completed_saved_total'] ?? 0) + (int) $summary['completed_saved'];

        $weekSeenTotal += (int) $summary['completed_seen'];
        $state['initial_backfill_week_seen_total'] = $weekSeenTotal;

        $nextAccountIndex = $accountIndex + 1;
        if ($nextAccountIndex >= count($accountNames)) {
            $summary['week_finished'] = true;
            $summary['week_seen_total'] = $weekSeenTotal;

            if ($weekSeenTotal === 0) {
                $emptyWeekStreak++;
            } else {
                $emptyWeekStreak = 0;
            }

            if ($weekSeenTotal === 0 && $emptyWeekStreak >= $emptyWeeksToStop) {
                $state['last_completed_fetch_at'] = gmdate('c');
                $state['last_completed_fetch_unix'] = time();
                unset(
                    $state['initial_backfill_cursor_end'],
                    $state['initial_backfill_step_index'],
                    $state['initial_backfill_account_index'],
                    $state['initial_backfill_week_seen_total'],
                    $state['initial_backfill_empty_week_streak']
                );

                $inProgress = $this->getInProgressSamples(true);
                $summary['in_progress_count'] = count($inProgress['data']);
                $summary['in_progress_cache_expires_at'] = $inProgress['expires_at'];
                $summary['backfill_completed'] = true;
                $summary['next_fetch_url'] = null;
                $summary['empty_week_streak'] = $emptyWeekStreak;
                $summary['empty_weeks_to_stop'] = $emptyWeeksToStop;
            } else {
                $nextCursorEnd = $chunkStart->modify('-1 day');
                $state['initial_backfill_cursor_end'] = $nextCursorEnd->format('Y-m-d');
                $state['initial_backfill_account_index'] = 0;
                $state['initial_backfill_week_seen_total'] = 0;
                $state['initial_backfill_empty_week_streak'] = $emptyWeekStreak;
                $summary['backfill_completed'] = false;
                $summary['next_chunk_start'] = $nextCursorEnd->modify('first day of this month')->format('Y-m-d');
                $summary['next_chunk_end'] = $nextCursorEnd->format('Y-m-d');
                $summary['next_account_index'] = 0;
                $summary['empty_week_streak'] = $emptyWeekStreak;
                $summary['empty_weeks_to_stop'] = $emptyWeeksToStop;
            }
        } else {
            $state['initial_backfill_cursor_end'] = $cursorEnd->format('Y-m-d');
            $state['initial_backfill_account_index'] = $nextAccountIndex;
            $summary['backfill_completed'] = false;
            $summary['week_finished'] = false;
            $summary['next_account_index'] = $nextAccountIndex;
            $summary['next_account_name'] = $accountNames[$nextAccountIndex];
            $summary['week_seen_total'] = $weekSeenTotal;
            $summary['empty_week_streak'] = $emptyWeekStreak;
            $summary['empty_weeks_to_stop'] = $emptyWeeksToStop;
        }

        $this->writeState($state);
        $summary['finished_at'] = gmdate('c');

        return $summary;
    }

    public function getInProgressSamples(bool $refresh = false): array
    {
        $ttlSeconds = 3600;
        $now = time();

        if (!$refresh && is_file($this->inProgressCacheFile)) {
            $cachedRaw = file_get_contents($this->inProgressCacheFile);
            if ($cachedRaw !== false && trim($cachedRaw) !== '') {
                $cached = json_decode($cachedRaw, true);
                if (is_array($cached)) {
                    $expiresAt = (int) ($cached['expires_at_unix'] ?? 0);
                    if ($expiresAt > $now && isset($cached['data']) && is_array($cached['data'])) {
                        return [
                            'cached' => true,
                            'expires_at' => gmdate('c', $expiresAt),
                            'expires_at_unix' => $expiresAt,
                            'data' => $cached['data'],
                        ];
                    }
                }
            }
        }

        $allRows = [];
        try {
            $rows = $this->getSampleActivityAllResults();
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                if ($this->isCompletedSample($row)) {
                    continue;
                }

                $allRows[] = $row;
            }
        } catch (Throwable $e) {
            // Fallback only when bulk endpoint is unavailable.
            $accounts = $this->getMyAccounts();
            foreach ($accounts as $account) {
                $clientId = $this->extractFirstNonEmpty($account, [
                    'ClientID',
                    'ClientId',
                    'clientId',
                    'clientid',
                    'Id',
                    'ID',
                    'id',
                ]);

                if ($clientId === '' || !$this->isGuid($clientId)) {
                    continue;
                }

                $rows = $this->getSampleActivityByAccount($clientId);
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    if ($this->isCompletedSample($row)) {
                        continue;
                    }

                    $allRows[] = $row;
                }
            }
        }

        // Remove duplicates on account + sample ID when possible.
        $dedup = [];
        $result = [];
        foreach ($allRows as $row) {
            $accountId = $this->extractAccountId($row);
            $limsSampleId = $this->extractLimsSampleId($row);
            $key = strtolower($accountId . '|' . $limsSampleId);

            if ($accountId !== '' && $limsSampleId !== '' && isset($dedup[$key])) {
                continue;
            }

            if ($accountId !== '' && $limsSampleId !== '') {
                $dedup[$key] = true;
            }

            $result[] = $row;
        }

        $expiresAt = $now + $ttlSeconds;
        $payload = [
            'cached_at' => gmdate('c', $now),
            'cached_at_unix' => $now,
            'expires_at' => gmdate('c', $expiresAt),
            'expires_at_unix' => $expiresAt,
            'data' => $result,
        ];

        $tmp = $this->inProgressCacheFile . '.tmp';
        file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_UNICODE));
        rename($tmp, $this->inProgressCacheFile);

        return [
            'cached' => false,
            'expires_at' => $payload['expires_at'],
            'expires_at_unix' => $expiresAt,
            'data' => $result,
        ];
    }

    public function getSampleActivityAllResults(): array
    {
        $payload = [
            'Page' => 1,
            'PageSize' => 1000,
            'ClientIds' => [],
            'SampleStatuses' => [],
            'LifeCycleStages' => [],
            'ServiceLevels' => [],
            'AssetClassId' => '',
            'AssetTag' => '',
            'AssetDescription' => '',
            'Manufacturer' => '',
            'Model' => '',
            'ModelYear' => '',
            'UnitID' => '',
            'SerialNumber' => '',
            'SampleBottleID' => '',
            'TestedLubricant' => '',
            'LIMSSampleID' => '',
            'Schedule' => '',
            'DateReportedFrom' => '',
            'DateReportedThru' => '',
            'IncludeAllAccounts' => true,
            'IncludeAllAssignedAccounts' => false,
        ];

        $response = $this->request('POST', '/sampleactivity/filter', [], $payload);

        if (isset($response['value']) && is_array($response['value'])) {
            return $response['value'];
        }

        if (isset($response['Results']) && is_array($response['Results'])) {
            return $response['Results'];
        }

        if (isset($response['Data']) && is_array($response['Data'])) {
            return $response['Data'];
        }

        if (is_array($response)) {
            if (array_is_list($response)) {
                return $response;
            }
        }

        return [];
    }

    private function getSampleDataAdaptive(array $accounts, string $startDate, string $endDate): array
    {
        $attempts = ['full', 'month', 'week'];
        $lastError = null;

        foreach ($attempts as $mode) {
            try {
                if ($mode === 'full') {
                    return [
                        'mode' => 'full',
                        'chunks' => 1,
                        'rows' => $this->getSampleData($accounts, $startDate, $endDate),
                    ];
                }

                $chunkRanges = $this->buildChunkedRanges($startDate, $endDate, $mode);
                $allRows = [];
                foreach ($chunkRanges as $chunk) {
                    $rows = $this->getSampleData($accounts, $chunk['start'], $chunk['end']);
                    foreach ($rows as $row) {
                        $allRows[] = $row;
                    }
                }

                return [
                    'mode' => $mode,
                    'chunks' => count($chunkRanges),
                    'rows' => $allRows,
                ];
            } catch (Throwable $e) {
                $lastError = $e;
            }
        }

        if ($lastError instanceof Throwable) {
            throw $lastError;
        }

        throw new RuntimeException('Could not fetch sample data for range.');
    }

    private function buildChunkedRanges(string $startDate, string $endDate, string $mode): array
    {
        $start = date_create_immutable($startDate);
        $end = date_create_immutable($endDate);

        if (!$start instanceof DateTimeImmutable || !$end instanceof DateTimeImmutable) {
            throw new RuntimeException('Invalid date range for chunking.');
        }

        if ($start > $end) {
            return [];
        }

        $ranges = [];
        $cursor = $start;
        while ($cursor <= $end) {
            if ($mode === 'month') {
                $chunkEnd = $cursor->modify('last day of this month');
            } else {
                $chunkEnd = $cursor->modify('+6 days');
            }

            if ($chunkEnd > $end) {
                $chunkEnd = $end;
            }

            $ranges[] = [
                'start' => $cursor->format('Y-m-d'),
                'end' => $chunkEnd->format('Y-m-d'),
            ];

            $cursor = $chunkEnd->modify('+1 day');
        }

        return $ranges;
    }

    public function getMyAccounts(): array
    {
        $response = $this->request('GET', '/account/getaccounts');

        if (isset($response['value']) && is_array($response['value'])) {
            return $response['value'];
        }

        if (is_array($response)) {
            return $response;
        }

        return [];
    }

    public function runAuthCheck(): array
    {
        $authPayloads = $this->buildAuthPayloads();

        $candidates = $this->buildAuthEndpointCandidates();
        $bodyTypes = ['json', 'form'];
        $authAttempts = [];
        $token = '';
        $authSuccess = null;

        foreach ($candidates as $path) {
            foreach ($authPayloads as $authPayload) {
                foreach ($bodyTypes as $bodyType) {
                    $payloadKey = implode(',', array_keys($authPayload));
                    try {
                        $response = $this->rawRequest('POST', $path, [], $authPayload, false, '', [], $bodyType, true, true);
                        $extracted = $this->extractToken($response);
                        if ($extracted !== '') {
                            $token = $extracted;
                            $authSuccess = [
                                'endpoint' => $path,
                                'body_type' => $bodyType,
                                'payload_keys' => $payloadKey,
                            ];
                            break 3;
                        }

                        $authAttempts[] = [
                            'ok' => false,
                            'endpoint' => $path,
                            'body_type' => $bodyType,
                            'payload_keys' => $payloadKey,
                            'message' => 'No token found in response body',
                            'response_summary' => $this->summarizeAuthResponse($response),
                        ];
                    } catch (Throwable $e) {
                        $authAttempts[] = [
                            'ok' => false,
                            'endpoint' => $path,
                            'body_type' => $bodyType,
                            'payload_keys' => $payloadKey,
                            'message' => $e->getMessage(),
                        ];
                    }
                }
            }
        }

        if ($token === '') {
            return [
                'status' => 'error',
                'stage' => 'authentication',
                'message' => 'No authentication token could be retrieved.',
                'auth_attempts' => $authAttempts,
            ];
        }

        $probeAttempts = [];
        $workingHeader = null;
        foreach ($this->buildAuthHeaderVariants($token) as $variant) {
            $label = (string) ($variant['label'] ?? 'unknown');
            $headers = (array) ($variant['headers'] ?? []);

            try {
                $this->rawRequest('GET', '/account/getaccounts', [], null, false, '', $headers);
                $workingHeader = $label;
                break;
            } catch (Throwable $e) {
                $probeAttempts[] = [
                    'ok' => false,
                    'header_variant' => $label,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'status' => $workingHeader !== null ? 'ok' : 'error',
            'environment' => $this->environment,
            'auth_environment' => $this->authEnvironment,
            'auth_mode' => $this->authMode,
            'stage' => $workingHeader !== null ? 'ready' : 'authorization_header',
            'token_preview' => $this->maskToken($token),
            'auth_success' => $authSuccess,
            'working_header_variant' => $workingHeader,
            'auth_attempts_sample' => array_slice($authAttempts, -10),
            'probe_attempts' => $probeAttempts,
        ];
    }

    public function runPostmanAuthCheckExact(): array
    {
        $url = $this->apiOrigin . '/env/' . $this->authEnvironment . '/authentication';
        $body = [
            'UserName' => $this->username,
            'Password' => $this->password,
        ];

        $response = $this->sendRawHttpRequest('POST', $url, [
            'Content-Type: application/json',
            'Accept: */*',
        ], json_encode($body, JSON_UNESCAPED_UNICODE) ?: '{}');

        $decoded = json_decode((string) ($response['body'] ?? ''), true);

        return [
            'status' => 'ok',
            'request' => [
                'method' => 'POST',
                'url' => $url,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => '*/*',
                ],
                'body' => $body,
            ],
            'response' => [
                'http_status' => (int) ($response['status'] ?? 0),
                'headers' => $response['headers'] ?? [],
                'body_raw' => (string) ($response['body'] ?? ''),
                'body_json' => is_array($decoded) ? $decoded : null,
                'curl_error' => (string) ($response['curl_error'] ?? ''),
            ],
        ];
    }

    public function getLastHttpExchange(): array
    {
        return $this->lastHttpExchange;
    }

    public function getRawResponses(): array
    {
        return $this->rawResponses;
    }

    public function getPerformanceStats(): array
    {
        $endpoints = [];
        foreach ($this->httpEndpointStats as $endpoint => $stats) {
            if (!is_array($stats)) {
                continue;
            }

            $count = (int) ($stats['count'] ?? 0);
            $totalMs = (float) ($stats['total_ms'] ?? 0.0);
            $avgMs = $count > 0 ? round($totalMs / $count, 2) : 0.0;

            $endpoints[] = [
                'endpoint' => (string) $endpoint,
                'count' => $count,
                'total_ms' => round($totalMs, 2),
                'avg_ms' => $avgMs,
                'max_ms' => round((float) ($stats['max_ms'] ?? 0.0), 2),
                'last_status' => (int) ($stats['last_status'] ?? 0),
            ];
        }

        usort($endpoints, static function (array $a, array $b): int {
            return ((float) ($b['total_ms'] ?? 0)) <=> ((float) ($a['total_ms'] ?? 0));
        });

        return [
            'request_count' => $this->httpRequestCount,
            'total_ms' => round($this->httpDurationTotalMs, 2),
            'avg_ms' => $this->httpRequestCount > 0 ? round($this->httpDurationTotalMs / $this->httpRequestCount, 2) : 0.0,
            'endpoints' => $endpoints,
        ];
    }

    public function getInProgressCacheFilePath(): string
    {
        return $this->inProgressCacheFile;
    }

    public function getAccounts(string $searchTerm = ''): array
    {
        $query = $searchTerm !== '' ? ['searchterm' => $searchTerm] : ['searchterm' => ''];
        $response = $this->request('GET', '/account/getaccounts', $query);

        if (isset($response['value']) && is_array($response['value'])) {
            return $response['value'];
        }

        if (is_array($response)) {
            return $response;
        }

        return [];
    }

    public function getSampleActivityByAccount(string $clientId): array
    {
        $response = $this->request('GET', '/sampleactivity/filter/accountid/' . rawurlencode($clientId));

        if (isset($response['value']) && is_array($response['value'])) {
            return $response['value'];
        }

        if (is_array($response)) {
            return $response;
        }

        return [];
    }

    public function getSampleData(array $accounts, string $startDate, string $endDate): array
    {
        $accountFilters = $this->extractAccountCriteriaFromAccounts($accounts);

        // Fallback: if assigned accounts payload had no names, fetch broad account list once.
        if (count($accountFilters) === 0) {
            $fallbackAccounts = $this->getAccounts('');
            $accountFilters = $this->extractAccountCriteriaFromAccounts($fallbackAccounts);
        }

        if (count($accountFilters) === 0) {
            throw new RuntimeException('No account names available for samplereport/getsampledata filter.');
        }

        $allRows = [];
        $accountBatches = array_chunk($accountFilters, 25);
        foreach ($accountBatches as $batch) {
            $rows = $this->getSampleDataForAccountBatch($batch, $startDate, $endDate);
            foreach ($rows as $row) {
                $allRows[] = $row;
            }
        }

        return $allRows;
    }

    private function getSampleDataForAccountBatch(array $accountsBatch, string $startDate, string $endDate): array
    {
        $payload = [
            'StartUtcDateReported' => $startDate,
            'EndUtcDateReported' => $endDate,
            'SampleStatus' => ['Completed'],
            'AssetClasses' => [],
            'Accounts' => array_values($accountsBatch),
            'Assets' => [],
            'IncludeWorkFlowData' => true,
            'IncludeComments' => true,
            'IncludeChildren' => false,
            'Lubricant' => '',
        ];

        try {
            $response = $this->request('POST', '/samplereport/getsampledata', [], $payload);
            return $this->extractRowsFromSampleDataResponse($response);
        } catch (Throwable $e) {
            if (count($accountsBatch) > 1 && $this->isEndpointTimeoutError($e)) {
                $mid = (int) floor(count($accountsBatch) / 2);
                $left = array_slice($accountsBatch, 0, $mid);
                $right = array_slice($accountsBatch, $mid);

                $rows = [];
                if (count($left) > 0) {
                    $rows = array_merge($rows, $this->getSampleDataForAccountBatch($left, $startDate, $endDate));
                }
                if (count($right) > 0) {
                    $rows = array_merge($rows, $this->getSampleDataForAccountBatch($right, $startDate, $endDate));
                }

                return $rows;
            }

            throw $e;
        }
    }

    private function extractRowsFromSampleDataResponse(array $response): array
    {
        if (isset($response['Columns']) && is_array($response['Columns']) && isset($response['Results']) && is_array($response['Results'])) {
            return $this->mapColumnsResultsToRows($response['Columns'], $response['Results']);
        }

        if (isset($response['value']) && is_array($response['value'])) {
            return $response['value'];
        }

        if (isset($response['Results']) && is_array($response['Results'])) {
            return $response['Results'];
        }

        if (isset($response['Data']) && is_array($response['Data'])) {
            return $response['Data'];
        }

        if (array_is_list($response)) {
            return $response;
        }

        return [];
    }

    private function mapColumnsResultsToRows(array $columns, array $results): array
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

    private function extractAccountCriteriaFromAccounts(array $accounts): array
    {
        $accountFilters = [];
        $blocked = [
            'all accounts',
            'all assigned accounts',
            'all_clients',
            'my_clients',
        ];

        foreach ($accounts as $account) {
            if (!is_array($account)) {
                continue;
            }

            $name = $this->extractFirstNonEmpty($account, [
                'Name',
                'name',
                'AccountName',
                'Account Name',
                'ClientName',
                'Client Name',
                'DisplayName',
                'Label',
            ]);

            if ($name !== '') {
                $normalized = strtolower(trim($name));
                if (in_array($normalized, $blocked, true)) {
                    continue;
                }

                $accountFilters[] = $name;
            }
        }

        $accountFilters = array_values(array_unique($accountFilters));

        return $accountFilters;
    }

    public function getCompletedSampleByWorkflowReportId(string $workflowReportId): array
    {
        $response = $this->request('GET', '/sample/workflowreportid/' . rawurlencode($workflowReportId));

        if (is_array($response)) {
            return $response;
        }

        return [];
    }

    public function getCompletedSampleByBottleId(string $bottleId): array
    {
        $response = $this->request('GET', '/sample/bottleid/' . rawurlencode($bottleId));

        if (is_array($response)) {
            return $response;
        }

        return [];
    }

    private function buildFetchRanges(?string $lastFetchAt): array
    {
        $ranges = [];

        if ($lastFetchAt !== null && trim($lastFetchAt) !== '') {
            $start = date_create_immutable($lastFetchAt);
            if ($start instanceof DateTimeImmutable) {
                $ranges[] = [
                    'start' => $start->format('Y-m-d'),
                    'end' => gmdate('Y-m-d'),
                    'kind' => 'incremental',
                ];

                return $ranges;
            }
        }

        $currentYear = (int) gmdate('Y');
        $fromYear = (int) ($_GET['fromYear'] ?? 2018);
        $fromYear = max(2000, min($fromYear, $currentYear));

        $start = DateTimeImmutable::createFromFormat('Y-m-d', sprintf('%04d-01-01', $fromYear));
        $today = new DateTimeImmutable(gmdate('Y-m-d'));

        if ($start instanceof DateTimeImmutable) {
            $cursor = $start;
            while ($cursor <= $today) {
                $monthEnd = $cursor->modify('last day of this month');
                if ($monthEnd > $today) {
                    $monthEnd = $today;
                }

                $ranges[] = [
                    'start' => $cursor->format('Y-m-d'),
                    'end' => $monthEnd->format('Y-m-d'),
                    'kind' => 'initial-backfill-month',
                ];

                $cursor = $monthEnd->modify('+1 day');
            }
        }

        return $ranges;
    }

    private function storeCompletedReport(string $accountId, string $limsSampleId, array $report, ?string $rawReportJson = null): bool
    {
        $filePath = $this->buildReportFilePath($accountId, $limsSampleId);
        if (is_file($filePath)) {
            return false;
        }

        $payload = $report;

        // Keep compatibility with existing local files (array with first record).
        if (!array_key_exists(0, $payload)) {
            $payload = [$payload];
        }

        $json = null;
        if (is_string($rawReportJson) && trim($rawReportJson) !== '') {
            $rawCandidate = trim($rawReportJson);
            json_decode($rawCandidate, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $json = $rawCandidate;
            }
        }

        if ($json === null) {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                throw new RuntimeException('Could not encode report JSON');
            }
        }

        $tmp = $filePath . '.tmp';
        file_put_contents($tmp, $json);
        rename($tmp, $filePath);

        return true;
    }

    private function buildReportFilePath(string $accountId, string $limsSampleId): string
    {
        $safeAccountId = $this->sanitizeFilePart($accountId);
        $safeSampleId = $this->sanitizeFilePart($limsSampleId);
        $timestamp = (string) ((int) floor(microtime(true) * 1000));

        $fileName = $safeAccountId . '-' . $safeSampleId . '[' . $timestamp . '].json';

        return $this->reportsDir . DIRECTORY_SEPARATOR . $fileName;
    }

    private function getLastResponseBodyRaw(): ?string
    {
        if (!isset($this->lastHttpExchange['response']) || !is_array($this->lastHttpExchange['response'])) {
            return null;
        }

        $bodyRaw = $this->lastHttpExchange['response']['body_raw'] ?? null;
        if (!is_string($bodyRaw)) {
            return null;
        }

        $trimmed = trim($bodyRaw);
        return $trimmed !== '' ? $trimmed : null;
    }

    private function sanitizeFilePart(string $value): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '_', trim($value));
        if (!is_string($clean) || $clean === '') {
            return 'unknown';
        }

        return $clean;
    }

    private function isCompletedSample(array $row): bool
    {
        $status = strtolower($this->extractFirstNonEmpty($row, [
            'Sample Status',
            'sampleStatus',
            'Status',
            'Report Status',
            'reportStatus',
        ]));

        if ($status === '') {
            return false;
        }

        return strpos($status, 'complete') !== false || strpos($status, 'closed') !== false || $status === 'reported';
    }

    private function extractAccountId(array $row): string
    {
        return $this->extractFirstNonEmpty($row, [
            'Account ID',
            'AccountId',
            'accountId',
            'ClientID',
            'ClientId',
            'clientId',
            'clientid',
        ]);
    }

    private function extractLimsSampleId(array $row): string
    {
        return $this->extractFirstNonEmpty($row, [
            'LIMS Sample ID',
            'LIMS Sample Id',
            'LimsSampleId',
            'limsSampleId',
            'Sample ID',
            'SampleId',
            'sampleId',
            'Sample Bottle ID',
            'BottleId',
        ]);
    }

    private function extractFirstNonEmpty(array $row, array $keys): string
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

        // Case-insensitive fallback.
        $map = [];
        foreach ($row as $k => $v) {
            if (!is_string($k)) {
                continue;
            }

            $map[strtolower($k)] = trim((string) $v);
        }

        foreach ($keys as $key) {
            $candidate = $map[strtolower((string) $key)] ?? '';
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    private function readState(): array
    {
        if (!is_file($this->stateFile)) {
            return [];
        }

        $raw = file_get_contents($this->stateFile);
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    private function writeState(array $state): void
    {
        $tmp = $this->stateFile . '.tmp';
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new RuntimeException('Could not encode state JSON');
        }

        file_put_contents($tmp, $json);
        rename($tmp, $this->stateFile);
    }

    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!mkdir($path, 0777, true) && !is_dir($path)) {
            throw new RuntimeException('Could not create directory: ' . $path);
        }
    }

    private function getBearerToken(): string
    {
        $now = time();

        if ($this->bearerToken !== null && $this->tokenExpiresAt !== null && $this->tokenExpiresAt > ($now + 30)) {
            return $this->bearerToken;
        }

        $postmanToken = $this->authenticatePostmanStyle();
        if ($postmanToken !== '') {
            $this->bearerToken = $postmanToken;
            $this->tokenExpiresAt = $now + 3600;
            return $postmanToken;
        }

        $authPayloads = $this->buildAuthPayloads();

        $candidates = $this->buildAuthEndpointCandidates();
        $bodyTypes = ['json', 'form'];

        $lastError = null;
        foreach ($candidates as $path) {
            foreach ($authPayloads as $authPayload) {
                foreach ($bodyTypes as $bodyType) {
                    try {
                        $response = $this->rawRequest('POST', $path, [], $authPayload, false, '', [], $bodyType, true, true);
                        $token = $this->extractToken($response);
                        if ($token !== '') {
                            $this->bearerToken = $token;
                            $this->tokenExpiresAt = $this->extractTokenExpiry($response, $now);
                            return $token;
                        }
                    } catch (Throwable $e) {
                        $lastError = $e;
                    }
                }
            }
        }

        if ($lastError instanceof Throwable) {
            throw new RuntimeException('Authentication failed: ' . $lastError->getMessage(), 0, $lastError);
        }

        throw new RuntimeException('Authentication failed: no token returned');
    }

    private function authenticatePostmanStyle(): string
    {
        $url = $this->apiOrigin . '/env/' . $this->authEnvironment . '/authentication';
        $body = [
            'UserName' => $this->username,
            'Password' => $this->password,
        ];

        $response = $this->sendRawHttpRequest('POST', $url, [
            'Content-Type: application/json',
            'Accept: */*',
        ], json_encode($body, JSON_UNESCAPED_UNICODE) ?: '{}');

        $status = (int) ($response['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            return '';
        }

        $rawBody = trim((string) ($response['body'] ?? ''));
        $token = $this->normalizeTokenCandidate($rawBody);

        return $this->looksLikeToken($token) ? $token : '';
    }

    private function extractToken(array $response): string
    {
        $candidates = [
            'token',
            'Token',
            'authenticationToken',
            'AuthenticationToken',
            'sessionToken',
            'SessionToken',
            'apiToken',
            'ApiToken',
            'access_token',
            'AccessToken',
            'authToken',
            'AuthToken',
            'jwt',
            'JWT',
        ];

        foreach ($candidates as $candidate) {
            $token = $this->normalizeTokenCandidate($this->findStringByKeyRecursive($response, strtolower($candidate)));
            if ($token !== '') {
                return $token;
            }
        }

        // Some tenants return the token inside a generic "message" field.
        if (isset($response['message']) && is_string($response['message'])) {
            $messageToken = $this->normalizeTokenCandidate($response['message']);
            if ($this->looksLikeToken($messageToken)) {
                return $messageToken;
            }
        }

        if (isset($response['_raw']) && is_string($response['_raw'])) {
            $raw = $this->normalizeTokenCandidate($response['_raw']);
            if ($this->looksLikeToken($raw)) {
                return $raw;
            }
        }

        if (isset($response['_headers']) && is_array($response['_headers'])) {
            $tokenFromHeaders = $this->extractTokenFromHeaders($response['_headers']);
            if ($tokenFromHeaders !== '') {
                return $tokenFromHeaders;
            }
        }

        $fallbackToken = $this->findPotentialTokenRecursive($response);
        if ($fallbackToken !== '') {
            return $fallbackToken;
        }

        return '';
    }

    private function request(string $method, string $path, array $query = [], ?array $jsonBody = null): array
    {
        $token = $this->getBearerToken();

        $lastError = null;
        foreach ($this->buildAuthHeaderVariants($token) as $variant) {
            $headers = (array) ($variant['headers'] ?? []);
            try {
                return $this->rawRequest($method, $path, $query, $jsonBody, false, '', $headers);
            } catch (Throwable $e) {
                $lastError = $e;
                if (!$this->looksLikeUnauthorized($e)) {
                    throw $e;
                }
            }
        }

        if ($lastError instanceof Throwable) {
            throw $lastError;
        }

        throw new RuntimeException('Request failed without response');
    }

    private function rawRequest(
        string $method,
        string $path,
        array $query = [],
        ?array $jsonBody = null,
        bool $withAuthHeader = true,
        string $bearerToken = '',
        array $extraHeaders = [],
        string $bodyType = 'json',
        bool $allowPlainTextResponse = false,
        bool $captureResponseHeaders = false
    ): array {
        $url = $this->normalizeUrl($path);
        $startedAt = microtime(true);
        if (count($query) > 0) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Could not initialize cURL');
        }

        $responseHeaders = [];

        $headers = [
            'Accept: application/json',
        ];

        if ($withAuthHeader) {
            $headers[] = 'Authorization: Bearer ' . $bearerToken;
        }

        foreach ($extraHeaders as $header) {
            if (is_string($header) && trim($header) !== '') {
                $headers[] = $header;
            }
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
        ];

        $options[CURLOPT_HEADERFUNCTION] = static function ($ch, string $headerLine) use (&$responseHeaders): int {
            $length = strlen($headerLine);
            $headerLine = trim($headerLine);
            if ($headerLine === '' || strpos($headerLine, ':') === false) {
                return $length;
            }

            [$name, $value] = explode(':', $headerLine, 2);
            $name = strtolower(trim($name));
            $value = trim($value);

            if ($name !== '') {
                if (!isset($responseHeaders[$name])) {
                    $responseHeaders[$name] = [];
                }
                $responseHeaders[$name][] = $value;
            }

            return $length;
        };

        if ($jsonBody !== null) {
            if ($bodyType === 'form') {
                $options[CURLOPT_POSTFIELDS] = http_build_query($jsonBody);
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            } else {
                $json = json_encode($jsonBody, JSON_UNESCAPED_UNICODE);
                if ($json === false) {
                    throw new RuntimeException('Could not encode request body to JSON');
                }

                $options[CURLOPT_POSTFIELDS] = $json;
                $headers[] = 'Content-Type: application/json';
            }

            $options[CURLOPT_HTTPHEADER] = $headers;
        }

        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            $durationMs = (microtime(true) - $startedAt) * 1000;
            $this->recordHttpMetric($url, 0, $durationMs);
            $exchange = [
                'request' => [
                    'method' => strtoupper($method),
                    'url' => $url,
                    'headers' => $headers,
                    'body' => $jsonBody,
                ],
                'response' => [
                    'http_status' => 0,
                    'headers' => $responseHeaders,
                    'body_raw' => '',
                    'curl_error' => $error,
                ],
            ];
            $this->setLastHttpExchange($exchange);
            $this->addRawResponse($exchange);
            throw new RuntimeException('cURL error: ' . $error);
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $durationMs = (microtime(true) - $startedAt) * 1000;
        $this->recordHttpMetric($url, $statusCode, $durationMs);

        $exchange = [
            'request' => [
                'method' => strtoupper($method),
                'url' => $url,
                'headers' => $headers,
                'body' => $jsonBody,
            ],
            'response' => [
                'http_status' => $statusCode,
                'headers' => $responseHeaders,
                'body_raw' => (string) $raw,
                'curl_error' => '',
            ],
        ];
        $this->setLastHttpExchange($exchange);
        $this->addRawResponse($exchange);

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException('HTTP ' . $statusCode . ' from Mobil API: ' . $raw);
        }

        if (trim($raw) === '') {
            $empty = [];
            if ($captureResponseHeaders && count($responseHeaders) > 0) {
                $empty['_headers'] = $responseHeaders;
                $empty['_status'] = $statusCode;
            }

            return $empty;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            if ($allowPlainTextResponse) {
                $payload = ['_raw' => trim($raw)];
                if ($captureResponseHeaders && count($responseHeaders) > 0) {
                    $payload['_headers'] = $responseHeaders;
                    $payload['_status'] = $statusCode;
                }

                return $payload;
            }

            throw new RuntimeException('Invalid JSON response from Mobil API');
        }

        if ($captureResponseHeaders && count($responseHeaders) > 0) {
            $decoded['_headers'] = $responseHeaders;
            $decoded['_status'] = $statusCode;
        }

        return $decoded;
    }

    private function resolveApiOrigin(string $url): string
    {
        $parts = parse_url($url);
        $scheme = isset($parts['scheme']) ? (string) $parts['scheme'] : 'https';
        $host = isset($parts['host']) ? (string) $parts['host'] : 'api.ucld.us';
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

        return $scheme . '://' . $host . $port;
    }

    private function normalizeUrl(string $path): string
    {
        $trimmed = trim($path);
        if ($trimmed === '') {
            return $this->baseUrl;
        }

        if (stripos($trimmed, 'http://') === 0 || stripos($trimmed, 'https://') === 0) {
            return $trimmed;
        }

        return $this->baseUrl . '/' . ltrim($trimmed, '/');
    }

    private function buildAuthEndpointCandidates(): array
    {
        $custom = trim($this->authEndpoint);
        $envPath = '/env/' . $this->environment;
        $authEnvPath = '/env/' . $this->authEnvironment;
        $list = [
            $custom,
            $this->apiOrigin . $authEnvPath . '/authentication',
            $this->apiOrigin . $authEnvPath . '/authenticate',
            $this->apiOrigin . '/env/' . $this->environment . '/authentication',
            $this->apiOrigin . '/env/' . $this->environment . '/authenticate',
            $this->apiOrigin . '/authenticate',
            $this->apiOrigin . '/authentication',
            $this->apiOrigin . '/token',
            $this->apiOrigin . '/auth/authenticate',
            $this->apiOrigin . '/api/authenticate',
            $this->apiOrigin . $envPath . '/authenticate',
            $this->apiOrigin . $envPath . '/authentication',
            $this->apiOrigin . $envPath . '/token',
            '/authenticate',
            '/authentication',
            '/token',
            '/auth/authenticate',
            '/api/authenticate',
        ];

        $result = [];
        foreach ($list as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }

            $candidate = trim($candidate);
            if ($candidate === '' || isset($result[$candidate])) {
                continue;
            }

            $result[$candidate] = true;
        }

        return array_keys($result);
    }

    private function buildAuthPayloads(): array
    {
        $payloads = [];

        if ($this->authMode === 'apikey' && $this->apiKey !== '') {
            if ($this->authEmail !== '') {
                $payloads[] = ['apikey' => $this->apiKey, 'email' => $this->authEmail];
                $payloads[] = ['ApiKey' => $this->apiKey, 'Email' => $this->authEmail];
            }

            if ($this->authUserId !== '') {
                $payloads[] = ['apikey' => $this->apiKey, 'userid' => $this->authUserId];
                $payloads[] = ['ApiKey' => $this->apiKey, 'UserId' => $this->authUserId];
            }
        }

        if ($this->authMode !== 'apikey') {
            $payloads[] = ['UserName' => $this->username, 'Password' => $this->password];
            $payloads[] = ['Username' => $this->username, 'Password' => $this->password];
            $payloads[] = ['username' => $this->username, 'password' => $this->password];
        }

        // Fallback: if API key is provided, also try it after username mode attempts.
        if ($this->apiKey !== '') {
            if ($this->authEmail !== '') {
                $payloads[] = ['apikey' => $this->apiKey, 'email' => $this->authEmail];
            }
            if ($this->authUserId !== '') {
                $payloads[] = ['apikey' => $this->apiKey, 'userid' => $this->authUserId];
            }
        }

        $unique = [];
        $result = [];
        foreach ($payloads as $payload) {
            $key = json_encode($payload);
            if (!is_string($key) || isset($unique[$key])) {
                continue;
            }
            $unique[$key] = true;
            $result[] = $payload;
        }

        return $result;
    }

    private function buildAuthHeaderVariants(string $token): array
    {
        return [
            ['label' => 'Authorization raw token', 'headers' => ['Authorization: ' . $token]],
            ['label' => 'Authorization Bearer token', 'headers' => ['Authorization: Bearer ' . $token]],
            ['label' => 'Authorization Token token', 'headers' => ['Authorization: Token ' . $token]],
            ['label' => 'X-Auth-Token', 'headers' => ['X-Auth-Token: ' . $token]],
            ['label' => 'Token header', 'headers' => ['Token: ' . $token]],
        ];
    }

    private function normalizeTokenCandidate(string $value): string
    {
        $token = trim($value);
        $length = strlen($token);
        if ($length >= 2 && $token[0] === '"' && $token[$length - 1] === '"') {
            $token = trim(substr($token, 1, -1));
        }

        return trim($token);
    }

    private function maskToken(string $token): string
    {
        $len = strlen($token);
        if ($len <= 10) {
            return str_repeat('*', $len);
        }

        return substr($token, 0, 6) . str_repeat('*', max(1, $len - 10)) . substr($token, -4);
    }

    private function extractTokenFromHeaders(array $headers): string
    {
        $candidateNames = ['authorization', 'x-auth-token', 'token'];

        foreach ($candidateNames as $name) {
            if (!isset($headers[$name]) || !is_array($headers[$name])) {
                continue;
            }

            foreach ($headers[$name] as $value) {
                if (!is_string($value)) {
                    continue;
                }

                $token = $this->extractTokenFromHeaderValue($value);
                if ($token !== '') {
                    return $token;
                }
            }
        }

        return '';
    }

    private function extractTokenFromHeaderValue(string $headerValue): string
    {
        $value = trim($headerValue);
        if ($value === '') {
            return '';
        }

        if (stripos($value, 'bearer ') === 0) {
            $value = trim(substr($value, 7));
        }

        $value = $this->normalizeTokenCandidate($value);
        if ($this->looksLikeToken($value)) {
            return $value;
        }

        return '';
    }

    private function findPotentialTokenRecursive($value): string
    {
        if (is_string($value)) {
            $candidate = $this->normalizeTokenCandidate($value);
            if ($this->looksLikeToken($candidate)) {
                return $candidate;
            }

            return '';
        }

        if (!is_array($value)) {
            return '';
        }

        foreach ($value as $key => $child) {
            if (is_string($key) && strpos($key, '_') === 0) {
                continue;
            }

            $found = $this->findPotentialTokenRecursive($child);
            if ($found !== '') {
                return $found;
            }
        }

        return '';
    }

    private function summarizeAuthResponse(array $response): array
    {
        $keys = [];
        foreach ($response as $key => $value) {
            if (!is_string($key) || strpos($key, '_') === 0) {
                continue;
            }
            $keys[] = $key;
        }

        $rawPreview = '';
        if (isset($response['_raw']) && is_string($response['_raw'])) {
            $rawPreview = substr(trim($response['_raw']), 0, 120);
        }

        $headerNames = [];
        if (isset($response['_headers']) && is_array($response['_headers'])) {
            $headerNames = array_keys($response['_headers']);
            sort($headerNames);
        }

        $messagePreview = '';
        $messageLength = 0;
        if (isset($response['message']) && is_string($response['message'])) {
            $message = trim($response['message']);
            $messageLength = strlen($message);
            $messagePreview = substr($message, 0, 120);
        }

        return [
            'status_code' => (int) ($response['_status'] ?? 0),
            'json_keys' => $keys,
            'raw_preview' => $rawPreview,
            'message_preview' => $messagePreview,
            'message_length' => $messageLength,
            'header_names' => $headerNames,
        ];
    }

    private function sendRawHttpRequest(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $startedAt = microtime(true);
        $ch = curl_init($url);
        if ($ch === false) {
            $this->recordHttpMetric($url, 0, (microtime(true) - $startedAt) * 1000);
            return [
                'status' => 0,
                'headers' => [],
                'body' => '',
                'curl_error' => 'Could not initialize cURL',
            ];
        }

        $responseHeaders = [];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $headerLine) use (&$responseHeaders): int {
                $length = strlen($headerLine);
                $line = trim($headerLine);
                if ($line === '' || strpos($line, ':') === false) {
                    return $length;
                }

                [$name, $value] = explode(':', $line, 2);
                $name = strtolower(trim($name));
                $value = trim($value);

                if (!isset($responseHeaders[$name])) {
                    $responseHeaders[$name] = [];
                }
                $responseHeaders[$name][] = $value;

                return $length;
            },
        ];

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $curlError = '';
        if ($raw === false) {
            $curlError = curl_error($ch);
            $raw = '';
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $this->recordHttpMetric($url, $statusCode, (microtime(true) - $startedAt) * 1000);

        $result = [
            'status' => $statusCode,
            'headers' => $responseHeaders,
            'body' => (string) $raw,
            'curl_error' => $curlError,
        ];

        $exchange = [
            'request' => [
                'method' => strtoupper($method),
                'url' => $url,
                'headers' => $headers,
                'body_raw' => $body,
            ],
            'response' => [
                'http_status' => $statusCode,
                'headers' => $responseHeaders,
                'body_raw' => (string) $raw,
                'curl_error' => $curlError,
            ],
        ];
        $this->setLastHttpExchange($exchange);
        $this->addRawResponse($exchange);

        return $result;
    }

    private function recordHttpMetric(string $url, int $statusCode, float $durationMs): void
    {
        $this->httpRequestCount++;
        $this->httpDurationTotalMs += max(0.0, $durationMs);

        $endpoint = (string) parse_url($url, PHP_URL_PATH);
        if ($endpoint === '') {
            $endpoint = $url;
        }

        if (!isset($this->httpEndpointStats[$endpoint])) {
            $this->httpEndpointStats[$endpoint] = [
                'count' => 0,
                'total_ms' => 0.0,
                'max_ms' => 0.0,
                'last_status' => 0,
            ];
        }

        $this->httpEndpointStats[$endpoint]['count'] = (int) $this->httpEndpointStats[$endpoint]['count'] + 1;
        $this->httpEndpointStats[$endpoint]['total_ms'] = (float) $this->httpEndpointStats[$endpoint]['total_ms'] + max(0.0, $durationMs);
        $this->httpEndpointStats[$endpoint]['max_ms'] = max((float) $this->httpEndpointStats[$endpoint]['max_ms'], max(0.0, $durationMs));
        $this->httpEndpointStats[$endpoint]['last_status'] = $statusCode;
    }

    private function setLastHttpExchange(array $exchange): void
    {
        $this->lastHttpExchange = $this->sanitizeSensitiveData($exchange);
    }

    private function addRawResponse(array $exchange): void
    {
        $this->rawResponses[] = $this->sanitizeSensitiveData($exchange);
    }

    private function sanitizeSensitiveData($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        $sensitiveKeys = ['password', 'pass', 'apikey', 'authorization', 'token'];
        $result = [];

        foreach ($value as $key => $child) {
            $normalizedKey = is_string($key) ? strtolower($key) : '';

            if (in_array($normalizedKey, $sensitiveKeys, true)) {
                if (is_string($child)) {
                    $result[$key] = $this->maskSecret($child);
                } else {
                    $result[$key] = '***';
                }
                continue;
            }

            if ($normalizedKey === 'headers' && is_array($child)) {
                $headers = [];
                foreach ($child as $headerKey => $headerValue) {
                    $headerName = is_string($headerKey) ? strtolower($headerKey) : '';
                    $isAuthHeader = strpos($headerName, 'authorization') !== false
                        || strpos($headerName, 'token') !== false
                        || strpos($headerName, 'apikey') !== false;

                    if ($isAuthHeader) {
                        if (is_array($headerValue)) {
                            $headers[$headerKey] = array_map(function ($item) {
                                return is_string($item) ? $this->maskSecret($item) : '***';
                            }, $headerValue);
                        } else {
                            $headers[$headerKey] = is_string($headerValue) ? $this->maskSecret($headerValue) : '***';
                        }
                    } else {
                        $headers[$headerKey] = $this->sanitizeSensitiveData($headerValue);
                    }
                }

                $result[$key] = $headers;
                continue;
            }

            $result[$key] = $this->sanitizeSensitiveData($child);
        }

        return $result;
    }

    private function maskSecret(string $value): string
    {
        $trimmed = trim($value);
        $len = strlen($trimmed);
        if ($len <= 8) {
            return str_repeat('*', max(1, $len));
        }

        return substr($trimmed, 0, 3) . str_repeat('*', $len - 6) . substr($trimmed, -3);
    }

    private function looksLikeToken(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        if (strlen($value) < 16) {
            return false;
        }

        if (strpos($value, ' ') !== false) {
            return false;
        }

        return preg_match('/^[A-Za-z0-9._\-+=\/:]{16,}$/', $value) === 1;
    }

    private function findStringByKeyRecursive(array $payload, string $needle): string
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && strtolower($key) === $needle && is_string($value) && trim($value) !== '') {
                return trim($value);
            }

            if (is_array($value)) {
                $found = $this->findStringByKeyRecursive($value, $needle);
                if ($found !== '') {
                    return $found;
                }
            }
        }

        return '';
    }

    private function extractTokenExpiry(array $response, int $now): int
    {
        $ttlKeys = ['expiresIn', 'expires_in', 'tokenExpiresIn', 'TokenExpiresIn'];
        foreach ($ttlKeys as $key) {
            $ttlRaw = $this->findStringByKeyRecursive($response, strtolower($key));
            if ($ttlRaw !== '' && ctype_digit($ttlRaw)) {
                $ttl = max(60, (int) $ttlRaw);
                return $now + $ttl;
            }
        }

        return $now + 3600;
    }

    private function looksLikeUnauthorized(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        return strpos($message, 'http 401') !== false || strpos($message, 'http 403') !== false;
    }

    private function isEndpointTimeoutError(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        return strpos($message, 'http 504') !== false
            || strpos($message, 'endpoint request timed out') !== false
            || strpos($message, 'maximum execution time') !== false;
    }

    private function isGuid(string $value): bool
    {
        return preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', trim($value)) === 1;
    }
}

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
    $reportsDirInUse = (string) ($payload['reports_dir_in_use'] ?? '');
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
    echo '<ul><li><strong>Permanente bestanden:</strong> ' . htmlspecialchars($reportsDirInUse !== '' ? $reportsDirInUse : '-', ENT_QUOTES, 'UTF-8') . '</li></ul>';
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

$client = null;
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
    $reportsDir = isset($_GET['reportsdir'])
        ? trim((string) $_GET['reportsdir'])
        : (string) ($mobilConfig['reportsDir'] ?? ($GLOBALS['samplePath'] ?? (__DIR__ . DIRECTORY_SEPARATOR . 'mobilreports')));

    $client = new MobilApiClient([
        'environment' => $environment,
        'authEnvironment' => $authEnv,
        'authMode' => $authMode,
        'username' => $username,
        'password' => $password,
        'reportsDir' => $reportsDir,
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
        $result['reports_dir_in_use'] = $reportsDir;
        $result['cache_file_in_use'] = $client->getInProgressCacheFilePath();
        if ($isHtmlView) {
            mobil_send_fetch_html($result, false);
        } else {
            mobil_send_json($result);
        }
        return;
    }

    if ($action === 'inprogress') {
        $result = $client->getInProgressSamples(false);
        mobil_send_json([
            'status' => 'ok',
            'cached' => $result['cached'],
            'expires_at' => $result['expires_at'],
            'count' => count($result['data']),
            'data' => $result['data'],
        ]);
        return;
    }

    if ($action === 'authcheck') {
        $result = $client->runPostmanAuthCheckExact();
        mobil_send_json($result);
        return;
    }

    mobil_send_json([
        'status' => 'ok',
        'message' => 'Mobil API endpoint ready',
        'actions' => [
            'fetch' => '?action=fetch',
            'inprogress' => '?action=inprogress',
            'authcheck' => '?action=authcheck',
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
