<?php
require_once __DIR__ . '/config.php';

// Escape for HTML output.
function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

// Client for the comix.to API. Two modes:
//  - Service mode: CX_SERVICE_URL is set. Requests are forwarded to that
//    service (see README.md for the contract) and its JSON is used as-is.
//  - Direct mode: plain PHP curl to https://comix.to/api/v1. This mode
//    currently fails: the API requires a "_" signature only the site's
//    own JS can compute, and Cloudflare blocks automated browsers.
// Every method returns ['ok' => true, 'data' => ...] or
// ['ok' => false, 'error' => ['kind' => ..., 'message' => ...]].
// A failed fetch is never rendered as an empty list.
class ComixClient
{
    private string $serviceUrl;
    private string $serviceToken;
    private string $cacheDir;
    private int $cacheTtl;

    public function __construct()
    {
        global $SERVICE_URL, $SERVICE_TOKEN, $CACHE_DIR, $CACHE_TTL;
        $this->serviceUrl = rtrim((string) $SERVICE_URL, '/');
        $this->serviceToken = (string) $SERVICE_TOKEN;
        $this->cacheDir = (string) $CACHE_DIR;
        $this->cacheTtl = (int) $CACHE_TTL;
    }

    public function serviceConfigured(): bool
    {
        return $this->serviceUrl !== '';
    }

    // --- Public API (endpoints are defined in this one place) ---

    // Verified: GET /api/v1/manga?limit=N returns plain JSON when not blocked.
    public function listManga(int $limit = 24, int $page = 1, string $search = ''): array
    {
        $params = ['limit' => max(1, min(50, $limit)), 'page' => max(1, $page)];
        if ($search !== '') {
            // Best-known/unverified: search is a query param on the same endpoint.
            $params['search'] = $search;
        }
        $res = $this->request('/manga', $params);
        if (!$res['ok']) {
            return $res;
        }
        return self::ok(self::normalizeList($res['data']));
    }

    // Assumed: GET /api/v1/manga/{slug}
    public function getManga(string $slug): array
    {
        $res = $this->request('/manga/' . rawurlencode($slug));
        if (!$res['ok']) {
            return $res;
        }
        return self::ok(self::normalizeManga($res['data']));
    }

    // Assumed: GET /api/v1/manga/{slug}/chapters
    public function getChapters(string $slug): array
    {
        $res = $this->request('/manga/' . rawurlencode($slug) . '/chapters');
        if (!$res['ok']) {
            return $res;
        }
        return self::ok(self::normalizeChapters($res['data']));
    }

    // Assumed: GET /api/v1/manga/{slug}/chapters/{id}. The payload is
    // encrypted ({"e": "..."}) and only the site's JS can decrypt it.
    public function getChapter(string $slug, string $chapterId): array
    {
        return $this->request(
            '/manga/' . rawurlencode($slug) . '/chapters/' . rawurlencode($chapterId)
        );
    }

    // One quick direct request, for status.php. Never hammered.
    public function testDirect(): array
    {
        return $this->requestDirect('/manga', ['limit' => 1]);
    }

    // --- Request plumbing ---

    private function request(string $path, array $params = []): array
    {
        $key = md5(($this->serviceConfigured() ? 'svc:' : 'direct:') . $path . '?' . http_build_query($params));
        $cached = $this->cacheGet($key);
        if ($cached !== null) {
            return $cached;
        }
        $res = $this->serviceConfigured()
            ? $this->requestService($path, $params)
            : $this->requestDirect($path, $params);
        if ($res['ok']) {
            $this->cacheSet($key, $res);
        }
        return $res;
    }

    private function requestService(string $path, array $params): array
    {
        $url = $this->serviceUrl . $path;
        if ($params) {
            $url .= '?' . http_build_query($params);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . $this->serviceToken,
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false || $err !== '') {
            return self::err('service_unavailable', 'The fetch service did not respond: ' . $err);
        }
        if ($code !== 200) {
            return self::err('service_unavailable', 'The fetch service returned HTTP ' . $code . '.');
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return self::err('bad_response', 'The fetch service returned data that is not JSON.');
        }
        // Accept either the raw upstream JSON or an {ok, data} envelope.
        if (array_key_exists('ok', $data)) {
            if ($data['ok']) {
                return self::ok($data['data'] ?? null);
            }
            $e = is_array($data['error'] ?? null) ? $data['error'] : [];
            return self::err(
                $e['kind'] ?? 'service_unavailable',
                $e['message'] ?? 'The fetch service reported an error.'
            );
        }
        return self::ok($data);
    }

    private function requestDirect(string $path, array $params): array
    {
        global $COMIX_API;
        $url = $COMIX_API . $path;
        if ($params) {
            $url .= '?' . http_build_query($params);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false || $errno !== 0) {
            if ($errno === CURLE_OPERATION_TIMEOUTED) {
                return self::err('timeout', 'The request to comix.to timed out after 10 seconds.');
            }
            return self::err('network', 'Could not reach comix.to: ' . $err);
        }
        if ($code === 403 && str_contains($body, 'missing_token')) {
            return self::err(
                'missing_token',
                'comix.to refused the request: its API requires a "_" signature that only the site\'s own JavaScript can compute. No server-side fetcher exists for this yet.'
            );
        }
        if ($code === 403 && (str_contains($body, 'Just a moment') || str_contains($body, 'cf-chl') || str_contains($body, 'challenges.cloudflare.com'))) {
            return self::err(
                'cloudflare',
                'Cloudflare is challenging automated access to comix.to and never accepts the clearance this server gets. The site cannot fetch data right now.'
            );
        }
        if ($code === 403) {
            return self::err('http_403', 'comix.to returned HTTP 403 (Forbidden).');
        }
        if ($code !== 200) {
            return self::err('http_' . $code, 'comix.to returned HTTP ' . $code . '.');
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return self::err('bad_response', 'comix.to returned a 200 response that is not JSON.');
        }
        return self::ok($data);
    }

    // --- File cache (successful responses only) ---

    private function cacheGet(string $key): ?array
    {
        $file = $this->cacheDir . '/' . $key . '.json';
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $entry = json_decode($raw, true);
        if (!is_array($entry) || !isset($entry['expires'], $entry['payload'])) {
            return null;
        }
        if ($entry['expires'] < time()) {
            @unlink($file);
            return null;
        }
        return $entry['payload'];
    }

    private function cacheSet(string $key, array $value): void
    {
        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0755, true)) {
            return;
        }
        $file = $this->cacheDir . '/' . $key . '.json';
        @file_put_contents(
            $file,
            json_encode(['expires' => time() + $this->cacheTtl, 'payload' => $value], JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    // --- Normalizers: tolerate the API's exact key names varying ---

    public static function normalizeList($raw): array
    {
        $items = [];
        if (is_array($raw)) {
            $list = $raw['data'] ?? $raw['results'] ?? $raw;
            if (is_array($list)) {
                foreach ($list as $item) {
                    if (is_array($item)) {
                        $items[] = self::normalizeManga($item);
                    }
                }
            }
        }
        return $items;
    }

    public static function normalizeManga($raw): array
    {
        if (!is_array($raw)) {
            return ['slug' => '', 'title' => '', 'cover' => '', 'synopsis' => '', 'status' => '', 'genres' => []];
        }
        $item = $raw['data'] ?? $raw;
        if (!is_array($item)) {
            $item = $raw;
        }
        $genres = $item['genres'] ?? $item['tags'] ?? [];
        if (is_string($genres)) {
            $genres = array_map('trim', explode(',', $genres));
        }
        if (!is_array($genres)) {
            $genres = [];
        }
        return [
            'slug' => (string) ($item['slug'] ?? $item['id'] ?? ''),
            'title' => (string) ($item['title'] ?? $item['name'] ?? 'Untitled'),
            'cover' => (string) ($item['cover'] ?? $item['poster'] ?? $item['image'] ?? $item['cover_url'] ?? $item['poster_url'] ?? ''),
            'synopsis' => (string) ($item['synopsis'] ?? $item['description'] ?? $item['summary'] ?? ''),
            'status' => (string) ($item['status'] ?? ''),
            'genres' => array_values(array_filter(array_map('strval', $genres))),
        ];
    }

    public static function normalizeChapters($raw): array
    {
        $items = [];
        if (is_array($raw)) {
            $list = $raw['data'] ?? $raw['chapters'] ?? $raw;
            if (is_array($list)) {
                foreach ($list as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $items[] = [
                        'id' => (string) ($item['id'] ?? $item['chapter_id'] ?? $item['slug'] ?? $item['number'] ?? ''),
                        'title' => (string) ($item['title'] ?? $item['name'] ?? ''),
                        'number' => (string) ($item['number'] ?? $item['chapter'] ?? ''),
                        'date' => (string) ($item['created_at'] ?? $item['date'] ?? $item['updated_at'] ?? ''),
                    ];
                }
            }
        }
        return $items;
    }

    // --- Helpers ---

    public static function validSlug(string $s): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-_]*$/', $s);
    }

    private static function ok($data): array
    {
        return ['ok' => true, 'data' => $data];
    }

    private static function err(string $kind, string $message): array
    {
        return ['ok' => false, 'error' => ['kind' => $kind, 'message' => $message]];
    }
}
