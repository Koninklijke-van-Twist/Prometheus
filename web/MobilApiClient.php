<?php


/**
 * Functies
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
        $this->cacheDir = (string) ($options['cacheDir'] ?? ($root . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'mobil'));

        $this->stateFile = $this->cacheDir . DIRECTORY_SEPARATOR . 'fetch_state.json';
        $this->inProgressCacheFile = (string) ($options['inProgressCacheFile'] ?? ($this->cacheDir . DIRECTORY_SEPARATOR . 'in-progress-reports.json'));

        $this->ensureDirectory($this->cacheDir);
    }

    public function fetchCompletedReportsIncremental(): array
    {
        $summary = [
            'status' => 'ok',
            'environment' => $this->environment,
            'mode' => 'in-progress-only',
            'source_endpoint' => '/sampleactivity/filter',
            'in_progress_count' => 0,
            'in_progress_cache_expires_at' => null,
            'started_at' => gmdate('c'),
            'finished_at' => null,
        ];

        $inProgress = $this->getInProgressSamples(true);
        $summary['in_progress_count'] = count($inProgress['data']);
        $summary['in_progress_cache_expires_at'] = $inProgress['expires_at'];
        $summary['cache_file'] = $this->inProgressCacheFile;

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
        $rows = $this->getSampleActivityAllResults();
        foreach ($rows as $row) {
            if (is_array($row)) {
                $allRows[] = $row;
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
            'PageSize' => 5000,
            'ClientIds' => [],
            'SampleStatuses' => [],
            'LifeCycleStages' => ['in-testing', 'registered'],
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

        return $this->extractRowsFromSampleActivityResponse($response);
    }

    private function extractRowsFromSampleActivityResponse(array $response): array
    {
        if (isset($response['samples']) && is_array($response['samples'])) {
            return $response['samples'];
        }

        if (isset($response['Samples']) && is_array($response['Samples'])) {
            return $response['Samples'];
        }

        if (isset($response['value']) && is_array($response['value'])) {
            return $response['value'];
        }

        if (isset($response['Results']) && is_array($response['Results'])) {
            if (array_is_list($response['Results'])) {
                return $response['Results'];
            }

            if (isset($response['Results']['Items']) && is_array($response['Results']['Items'])) {
                return $response['Results']['Items'];
            }

            if (isset($response['Results']['Data']) && is_array($response['Results']['Data'])) {
                return $response['Results']['Data'];
            }
        }

        if (isset($response['Data']) && is_array($response['Data'])) {
            if (array_is_list($response['Data'])) {
                return $response['Data'];
            }

            if (isset($response['Data']['Items']) && is_array($response['Data']['Items'])) {
                return $response['Data']['Items'];
            }
        }

        if (array_is_list($response)) {
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

