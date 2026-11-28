<?php
// Per-source adapter interface and shared plumbing (curl, file cache,
// HTML parsing, normalizers). Adapters return
// ['ok' => true, 'data' => ...] or
// ['ok' => false, 'error' => ['kind' => ..., 'message' => ...]].
// Error messages are rendered to visitors, so they must never name a
// source site. A failed fetch is never rendered as an empty list.
require_once __DIR__ . '/../config.php';

interface SourceAdapter
{
    public function id(): string;
    public function list(int $limit, int $page, string $search): array;
    public function detail(string $slug): array;
    public function chapters(string $slug): array;
    public function chapter(string $slug, string $chapterId): array;
    public function testDirect(): array;
}

abstract class BaseAdapter implements SourceAdapter
{
    protected array $source;
    protected string $cacheDir;
    protected int $cacheTtl;

    public function __construct(array $source = [])
    {
        global $CACHE_DIR, $CACHE_TTL;
        $this->source = $source;
        $this->cacheDir = (string) $CACHE_DIR;
        $this->cacheTtl = (int) $CACHE_TTL;
    }

    public function baseUrl(): string
    {
        return rtrim((string) ($this->source['site'] ?? ''), '/');
    }

    // --- HTTP ---

    // Low-level request. Returns ['code', 'body', 'cookies',
    // 'errno', 'location']. $post is null for GET, or form fields
    // for POST. $cookies are sent as a Cookie header. Set-Cookie
    // values from every response (including redirects) are merged,
    // later ones winning, so the caller can keep a session.
    protected function httpRequest(string $url, ?array $post = null, array $cookies = [], ?string $referer = null, int $timeout = 15, bool $follow = true): array
    {
        $ch = curl_init($url);
        $headers = ['Accept: text/html,application/json;q=0.9,*/*;q=0.8'];
        if ($cookies !== []) {
            $pairs = [];
            foreach ($cookies as $name => $value) {
                $pairs[] = $name . '=' . $value;
            }
            $headers[] = 'Cookie: ' . implode('; ', $pairs);
        }
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADER => true,
        ];
        if ($post !== null) {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($post);
        }
        if ($referer !== null) {
            $opts[CURLOPT_REFERER] = $referer;
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);
        if ($raw === false || $errno !== 0) {
            return ['code' => 0, 'body' => '', 'cookies' => [], 'errno' => $errno, 'location' => ''];
        }
        $headersRaw = substr((string) $raw, 0, $headerSize);
        $body = substr((string) $raw, $headerSize);
        return ['code' => $code, 'body' => $body, 'cookies' => self::parseSetCookies($headersRaw), 'errno' => 0, 'location' => $location];
    }

    // name => value from Set-Cookie headers. Later values win.
    protected static function parseSetCookies(string $headersRaw): array
    {
        $cookies = [];
        foreach (preg_split('/\r?\n/', $headersRaw) as $line) {
            if (stripos($line, 'Set-Cookie:') !== 0) {
                continue;
            }
            $value = trim(substr($line, strlen('Set-Cookie:')));
            $semi = strpos($value, ';');
            $pair = $semi === false ? $value : substr($value, 0, $semi);
            $eq = strpos($pair, '=');
            if ($eq === false) {
                continue;
            }
            $name = trim(substr($pair, 0, $eq));
            $val = trim(substr($pair, $eq + 1));
            if ($name !== '') {
                $cookies[$name] = $val;
            }
        }
        return $cookies;
    }

    // Unlock cookies this visitor has stored (in their session) for
    // a chapter URL. Empty when there is no session or no unlock.
    protected function storedUnlockCookies(string $url): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return [];
        }
        $all = $_SESSION['mhcl_unlocked'] ?? [];
        if (!is_array($all)) {
            return [];
        }
        $entry = $all[$url] ?? null;
        return is_array($entry) ? $entry : [];
    }

    // A password-locked chapter page (the mhcl lock form), or null.
    // The form is a plain POST with mhcl_password + mhcl_unlock_nonce
    // and a math question in the text ("Password is ? [ 12+3 = ?]").
    // Returns the question ("" when it cannot be read), the
    // one-time token, and the URL, so the reader can show an unlock
    // popup and the unlock can post back to the same page.
    public static function parseLockPage(string $body, string $url): ?array
    {
        if (!str_contains($body, 'mhcl_password') || !str_contains($body, 'mhcl_unlock_nonce')) {
            return null;
        }
        if (!preg_match('/name="mhcl_unlock_nonce"\s+value="([^"]+)"/', $body, $m)) {
            return null;
        }
        $question = '';
        if (preg_match('/\[\s*([0-9]+\s*[+\-x*×]\s*[0-9]+)\s*=\s*\?\s*\]/u', $body, $q)) {
            $question = preg_replace('/\s+/', '', $q[1]) . ' = ?';
        }
        return ['question' => $question, 'nonce' => $m[1], 'url' => $url];
    }

    // Unlock a password-locked chapter page with the password the
    // visitor entered. The token is fetched fresh from the lock page
    // (it is per page load), then posted with the password; the
    // source answers with an unlock cookie (mhcl_unlocked) when the
    // password is right, and re-renders the lock form when it is
    // wrong. Returns ['ok' => true, 'cookies' => [...]] or an
    // error. The caller stores the cookies in the visitor's
    // session so later fetches of this chapter send them.
    public function unlockChapterUrl(string $url, string $password): array
    {
        $password = trim($password);
        if ($password === '') {
            return self::err('bad_request', 'Enter the password.');
        }
        $get = $this->httpRequest($url, null, $this->storedUnlockCookies($url), null, 15);
        if (($get['errno'] ?? 0) !== 0) {
            return self::err('network', 'The source could not be reached.');
        }
        $lock = self::parseLockPage((string) ($get['body'] ?? ''), $url);
        if ($lock === null) {
            return self::ok(['cookies' => [], 'already' => true]);
        }
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        $post = $this->httpRequest($url, [
            'mhcl_unlock_nonce' => $lock['nonce'],
            '_wp_http_referer' => $path,
            'mhcl_password' => $password,
        ], $get['cookies'] ?? [], $url, 15, false);
        if (($post['errno'] ?? 0) !== 0) {
            return self::err('network', 'The source could not be reached.');
        }
        $postBody = (string) ($post['body'] ?? '');
        $stillLocked = self::parseLockPage($postBody, $url);
        if ($stillLocked !== null) {
            return [
                'ok' => false,
                'error' => [
                    'kind' => 'wrong_password',
                    'message' => 'Wrong password. Try again.',
                    'lock' => $stillLocked,
                ],
            ];
        }
        $cookies = $post['cookies'] ?? [];
        if (($cookies['mhcl_unlocked'] ?? '') === '') {
            return self::err('bad_response', 'The source did not unlock the chapter.');
        }
        return self::ok(['cookies' => $cookies]);
    }

    protected function fetch(string $url, int $timeout = 15): array
    {
        $res = $this->httpRequest($url, null, $this->storedUnlockCookies($url), null, $timeout);
        if (($res['errno'] ?? 0) !== 0) {
            if (($res['errno'] ?? 0) === CURLE_OPERATION_TIMEOUTED) {
                return self::err('timeout', 'The request timed out.');
            }
            return self::err('network', 'The source could not be reached.');
        }
        $body = (string) ($res['body'] ?? '');
        $code = (int) ($res['code'] ?? 0);
        // A Cloudflare JS challenge often returns HTTP 200, not 403,
        // with a 'Just a moment' page and no real content. Treat a
        // challenge body as a block at any HTTP code, so the reader
        // reports the cause instead of 'no images'. Only the
        // challenge-page markers count: 'Just a moment' (the
        // challenge title) and 'cf-chl' (the challenge form).
        // 'cf-turnstile' and 'challenges.cloudflare.com' are NOT
        // checked -- normal chapter pages embed a Turnstile widget
        // and contain those strings while loading fine, so they
        // false-positive and hide readable chapters.
        if (str_contains($body, 'Just a moment') || str_contains($body, 'cf-chl')) {
            return self::err('cloudflare', 'Access is being challenged and this server cannot pass the check. The content cannot be loaded right now.');
        }
        // A password-locked chapter: the source serves the lock form
        // (HTTP 403) instead of the chapter. Return a structured
        // error so the reader can show an unlock popup with the
        // question, instead of a generic 403.
        $lock = self::parseLockPage($body, $url);
        if ($lock !== null) {
            return [
                'ok' => false,
                'error' => [
                    'kind' => 'locked',
                    'message' => 'This chapter is locked on the source. Enter the password to unlock it.',
                    'lock' => $lock,
                    'source' => $this->id(),
                ],
            ];
        }
        if ($code === 403) {
            return self::err('http_403', 'The source returned HTTP 403 (Forbidden).');
        }
        if ($code !== 200) {
            return self::err('http_' . $code, 'The source returned HTTP ' . $code . '.');
        }
        return self::ok($body);
    }

    // --- File cache (successful responses only) ---

    protected function cacheGet(string $key): ?array
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

    protected function cacheSet(string $key, array $value): void
    {
        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0755, true)) {
            return;
        }
        @file_put_contents(
            $this->cacheDir . '/' . $key . '.json',
            json_encode(['expires' => time() + $this->cacheTtl, 'payload' => $value], JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    protected function cacheKey(string $method, string $parts): string
    {
        return md5($this->id() . ':' . $method . ':' . $parts);
    }

    // --- HTML parsing ---

    protected static function xpath(string $html): ?DOMXPath
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        if (!$loaded) {
            return null;
        }
        return new DOMXPath($doc);
    }

    protected static function text(?DOMNode $node): string
    {
        if ($node === null) {
            return '';
        }
        return trim(preg_replace('/\s+/', ' ', $node->textContent ?? ''));
    }

    protected static function attr(?DOMNode $node, string $name): string
    {
        if ($node instanceof DOMElement && $node->hasAttribute($name)) {
            return trim($node->getAttribute($name));
        }
        return '';
    }

    // Image URL from an <img>: lazy-loaded images keep the real URL in
    // data-src while src is a placeholder.
    protected static function imgSrc(?DOMNode $node): string
    {
        $src = self::attr($node, 'data-src');
        if ($src === '') {
            $src = self::attr($node, 'data-lazy-src');
        }
        if ($src === '') {
            $src = self::attr($node, 'src');
        }
        if (str_contains($src, 'loading') && str_ends_with(strtolower($src), '.svg')) {
            return '';
        }
        return $src;
    }

    // --- Normalizers: tolerate upstream key names varying ---

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

    public static function ok($data): array
    {
        return ['ok' => true, 'data' => $data];
    }

    public static function err(string $kind, string $message): array
    {
        return ['ok' => false, 'error' => ['kind' => $kind, 'message' => $message]];
    }
}
