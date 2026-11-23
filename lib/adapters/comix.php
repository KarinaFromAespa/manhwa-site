<?php
// Adapter for the first source (id "comix"). Moved as-is from the old
// single-source client; only the rendered messages were made generic.
// Two modes:
//  - Service mode: CX_SERVICE_URL is set. Requests are forwarded to
//    that service (see README.md for the contract) and its JSON is
//    used as-is.
//  - Direct mode: plain PHP curl to the source API. This mode
//    currently fails: the API requires a "_" signature only the
//    source's own JS can compute, and automated browsers are blocked.
require_once __DIR__ . '/adapter.php';

class ComixAdapter extends BaseAdapter
{
    private string $serviceUrl;
    private string $serviceToken;

    public function __construct(array $source = [])
    {
        parent::__construct($source);
        global $SERVICE_URL, $SERVICE_TOKEN;
        $this->serviceUrl = rtrim((string) $SERVICE_URL, '/');
        $this->serviceToken = (string) $SERVICE_TOKEN;
    }

    public function id(): string
    {
        return 'comix';
    }

    public function serviceConfigured(): bool
    {
        return $this->serviceUrl !== '';
    }

    // Verified: GET /api/v1/manga?limit=N returns plain JSON when not blocked.
    public function list(int $limit = 24, int $page = 1, string $search = ''): array
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
    public function detail(string $slug): array
    {
        $res = $this->request('/manga/' . rawurlencode($slug));
        if (!$res['ok']) {
            return $res;
        }
        return self::ok(self::normalizeManga($res['data']));
    }

    // Assumed: GET /api/v1/manga/{slug}/chapters
    public function chapters(string $slug): array
    {
        $res = $this->request('/manga/' . rawurlencode($slug) . '/chapters');
        if (!$res['ok']) {
            return $res;
        }
        return self::ok(self::normalizeChapters($res['data']));
    }

    // Assumed: GET /api/v1/manga/{slug}/chapters/{id}. The payload is
    // encrypted ({"e": "..."}) and only the source's JS can decrypt it.
    public function chapter(string $slug, string $chapterId): array
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
        $key = $this->cacheKey('req', ($this->serviceConfigured() ? 'svc:' : 'direct:') . $path . '?' . http_build_query($params));
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
            return self::err('service_unavailable', 'The fetch service did not respond.');
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
                return self::err('timeout', 'The request timed out after 10 seconds.');
            }
            return self::err('network', 'The source could not be reached.');
        }
        if ($code === 403 && str_contains($body, 'missing_token')) {
            return self::err(
                'missing_token',
                'The request was refused: the source API requires a signature that only its own website can compute. No server-side fetcher exists for this yet.'
            );
        }
        if ($code === 403 && (str_contains($body, 'Just a moment') || str_contains($body, 'cf-chl') || str_contains($body, 'challenges.cloudflare.com'))) {
            return self::err(
                'cloudflare',
                'Access is being challenged and this server cannot pass the check. The content cannot be loaded right now.'
            );
        }
        if ($code === 403) {
            return self::err('http_403', 'The source returned HTTP 403 (Forbidden).');
        }
        if ($code !== 200) {
            return self::err('http_' . $code, 'The source returned HTTP ' . $code . '.');
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return self::err('bad_response', 'The source returned a 200 response that is not JSON.');
        }
        return self::ok($data);
    }
}
