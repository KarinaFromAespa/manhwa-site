<?php
// Generic adapter for sites running the Madara WordPress theme
// (46 of the 200 registry sources). Selectors verified live against
// a real Madara site on 2026-11-23: list items (.page-item-detail),
// title pages (.summary__content, .post-content_item rows,
// li.wp-manga-chapter links) and chapter pages (.reading-content
// img with the real URL in data-src). Many Madara sites are
// Cloudflare-protected, so this adapter fails honestly on those.
require_once __DIR__ . '/adapter.php';

class MadaraAdapter extends BaseAdapter
{
    public function id(): string
    {
        return 'madara:' . ($this->source['id'] ?? 'unknown');
    }

    public function list(int $limit = 24, int $page = 1, string $search = ''): array
    {
        $base = $this->baseUrl();
        if ($base === '') {
            return self::err('bad_request', 'This source has no site address configured.');
        }
        if ($search !== '') {
            $url = $base . '/?s=' . rawurlencode($search) . '&post_type=wp-manga';
        } else {
            $url = $base . '/manga/';
            if ($page > 1) {
                $url .= 'page/' . $page . '/';
            }
        }
        $key = $this->cacheKey('list', $url);
        $cached = $this->cacheGet($key);
        if ($cached !== null) {
            return $cached;
        }
        $res = $this->fetch($url);
        if (!$res['ok']) {
            return $res;
        }
        $out = self::ok($this->parseList($res['data'], $base, $limit));
        $this->cacheSet($key, $out);
        return $out;
    }

    public function detail(string $slug): array
    {
        $base = $this->baseUrl();
        if ($base === '') {
            return self::err('bad_request', 'This source has no site address configured.');
        }
        $key = $this->cacheKey('detail', $slug);
        $cached = $this->cacheGet($key);
        if ($cached !== null) {
            return $cached;
        }
        $res = $this->fetch($base . '/' . trim($slug, '/') . '/');
        if (!$res['ok']) {
            return $res;
        }
        $parsed = $this->parseDetail($res['data'], $base, $slug);
        if ($parsed === null) {
            return self::err('bad_response', 'The title page did not contain readable details.');
        }
        $out = self::ok($parsed);
        $this->cacheSet($key, $out);
        return $out;
    }

    public function chapters(string $slug): array
    {
        $detail = $this->detail($slug);
        if (!$detail['ok']) {
            return $detail;
        }
        return self::ok($detail['data']['chapters'] ?? []);
    }

    public function chapter(string $slug, string $chapterId, int $timeout = 15): array
    {
        $base = $this->baseUrl();
        if ($base === '') {
            return self::err('bad_request', 'This source has no site address configured.');
        }
        $key = $this->cacheKey('chapter', $chapterId);
        $cached = $this->cacheGet($key);
        if ($cached !== null) {
            return $cached;
        }
        // API first when the source records one (data/source-patterns.json).
        $pattern = $this->sourcePattern();
        $apiUrl = (string) ($pattern['images_api'] ?? '');
        if ($apiUrl !== '') {
            $images = $this->chapterImagesApi($apiUrl, $slug, $chapterId);
            if ($images !== []) {
                $out = self::ok(['images' => $images]);
                $this->cacheSet($key, $out);
                return $out;
            }
        }
        $res = $this->fetch($base . '/' . trim($chapterId, '/') . '/', $timeout);
        if (!$res['ok']) {
            return $res;
        }
        $images = $this->parseChapterImages($res['data'], $chapterId);
        if ($images === []) {
            return self::err('bad_response', 'The chapter page did not contain any readable images.');
        }
        $out = self::ok(['images' => $images]);
        $this->cacheSet($key, $out);
        return $out;
    }

    // Per-source fetch pattern (data/source-patterns.json), seeded
    // from the keiyoushi extension for the source and updated by
    // the scraper as runs verify it. Empty when unrecorded.
    private function sourcePattern(): array
    {
        static $patterns = null;
        if ($patterns === null) {
            $file = dirname(__DIR__, 2) . '/data/source-patterns.json';
            $raw = @file_get_contents($file);
            $decoded = $raw !== false ? json_decode($raw, true) : null;
            $patterns = is_array($decoded['sources'] ?? null) ? $decoded['sources'] : [];
        }
        $id = (string) ($this->source['id'] ?? '');
        return is_array($patterns[$id] ?? null) ? $patterns[$id] : [];
    }

    // Known image hosts: the proxy allowlist plus this source's
    // recorded image hosts. Used by the CDN extractor.
    private function knownImageHosts(): array
    {
        static $hosts = null;
        if ($hosts === null) {
            $hosts = [];
            $file = dirname(__DIR__, 2) . '/data/image-hosts.json';
            $raw = @file_get_contents($file);
            $decoded = $raw !== false ? json_decode($raw, true) : null;
            if (is_array($decoded['hosts'] ?? null)) {
                $hosts = array_map('strval', $decoded['hosts']);
            }
        }
        $pattern = $this->sourcePattern();
        if (is_array($pattern['image_hosts'] ?? null)) {
            $hosts = array_values(array_unique(array_merge(
                $hosts, array_map('strval', $pattern['image_hosts'])
            )));
        }
        return $hosts;
    }

    // Chapter images from a JSON API (images_api template with
    // {slug} and {chapter} placeholders). Returns [] when the API
    // has no usable image list.
    private function chapterImagesApi(string $template, string $slug, string $chapterId): array
    {
        $chapter = $this->chapterNumber($chapterId);
        $url = str_replace(['{slug}', '{chapter}'], [$slug, $chapter], $template);
        $res = $this->fetch($url, 10);
        if (!$res['ok']) {
            return [];
        }
        $data = json_decode((string) $res['data'], true);
        if (!is_array($data)) {
            return [];
        }
        return $this->imageUrlsFromJson($data);
    }

    // Chapter number from a chapter id path (manga/<slug>/chapter-211).
    private function chapterNumber(string $chapterId): string
    {
        if (preg_match('/chapter-(\d+(?:\.\d+)?)/', $chapterId, $m)) {
            return $m[1];
        }
        $parts = array_values(array_filter(explode('/', trim($chapterId, '/'))));
        $last = end($parts);
        return is_numeric($last) ? (string) $last : '';
    }

    // Image URLs anywhere in decoded JSON, in encounter order.
    private function imageUrlsFromJson(mixed $node): array
    {
        $out = [];
        if (is_string($node)) {
            if (preg_match('#^https?://[^\\s"\']+\\.(webp|jpg|jpeg|png|gif)(\\?[^\\s"\']*)?$#i', $node)) {
                $out[] = $node;
            }
            return $out;
        }
        if (is_array($node)) {
            foreach ($node as $value) {
                foreach ($this->imageUrlsFromJson($value) as $url) {
                    if (!in_array($url, $out, true)) {
                        $out[] = $url;
                    }
                }
            }
        }
        return $out;
    }

    public function testDirect(): array
    {
        $base = $this->baseUrl();
        if ($base === '') {
            return self::err('bad_request', 'This source has no site address configured.');
        }
        $res = $this->fetch($base . '/manga/');
        if (!$res['ok']) {
            return $res;
        }
        if (!str_contains($res['data'], 'page-item-detail')) {
            return self::err('bad_response', 'The source answered but its listing did not look like the expected layout.');
        }
        return self::ok(true);
    }

    // --- Parsing ---

    private function parseList(string $html, string $base, int $limit): array
    {
        $xp = self::xpath($html);
        if ($xp === null) {
            return [];
        }
        $items = [];
        $nodes = $xp->query("//div[contains(@class,'page-item-detail')]");
        if ($nodes === false) {
            return [];
        }
        foreach ($nodes as $node) {
            if (count($items) >= $limit) {
                break;
            }
            // Title link: the <a> in .post-title. Some Madara sites
            // serve titles under /webtoon/ instead of /manga/.
            $link = $xp->query(".//div[contains(@class,'post-title')]//a", $node)->item(0);
            if ($link === null) {
                $link = $xp->query(".//a[contains(@href,'/manga/')]", $node)->item(0);
            }
            $href = self::attr($link, 'href');
            if ($href === '') {
                continue;
            }
            $title = self::text($link);
            if ($title === '') {
                continue;
            }
            $img = $xp->query('.//img', $node)->item(0);
            $items[] = [
                'slug' => trim((string) parse_url($this->resolveUrl($base, $href), PHP_URL_PATH), '/'),
                'title' => $title,
                'cover' => self::imgSrc($img),
                'synopsis' => '',
                'status' => '',
                'genres' => [],
            ];
        }
        return $items;
    }

    private function parseDetail(string $html, string $base, string $slug): ?array
    {
        $xp = self::xpath($html);
        if ($xp === null) {
            return null;
        }
        $title = self::text($xp->query("//div[contains(@class,'post-title')]//h1")->item(0));
        if ($title === '') {
            $title = self::text($xp->query('//h1')->item(0));
        }
        if ($title === '') {
            $og = $xp->query("//meta[@property='og:title']")->item(0);
            $title = self::attr($og, 'content');
        }
        if ($title === '') {
            return null;
        }
        $cover = self::imgSrc($xp->query("//div[contains(@class,'summary_image')]//img")->item(0));
        $synopsis = self::text($xp->query("//div[contains(@class,'summary__content')]")->item(0));
        $status = '';
        $genres = [];
        $rows = $xp->query("//div[contains(@class,'post-content_item')]");
        if ($rows !== false) {
            foreach ($rows as $row) {
                $heading = strtolower(self::text($xp->query(".//div[contains(@class,'summary-heading')]", $row)->item(0)));
                $content = $xp->query(".//div[contains(@class,'summary-content')]", $row)->item(0);
                if (str_contains($heading, 'status')) {
                    $status = self::text($content);
                } elseif (str_contains($heading, 'genre')) {
                    if ($content !== null) {
                        foreach ($xp->query('.//a', $content) as $a) {
                            $g = self::text($a);
                            if ($g !== '') {
                                $genres[] = $g;
                            }
                        }
                    }
                }
            }
        }
        return [
            'slug' => trim($slug, '/'),
            'title' => $title,
            'cover' => $cover,
            'synopsis' => $synopsis,
            'status' => $status,
            'genres' => $genres,
            'chapters' => $this->parseChapters($xp, $base),
        ];
    }

    private function parseChapters(DOMXPath $xp, string $base): array
    {
        $items = [];
        $nodes = $xp->query("//li[contains(@class,'wp-manga-chapter')]//a");
        if ($nodes === false) {
            return [];
        }
        foreach ($nodes as $a) {
            $href = self::attr($a, 'href');
            if ($href === '') {
                continue;
            }
            $text = self::text($a);
            $number = '';
            if (preg_match('/chapter[\s\-_]?(\d+(?:\.\d+)?)/i', $text, $m)) {
                $number = $m[1];
            } elseif (preg_match('/chapter[\-_]?(\d+(?:\.\d+)?)/i', $href, $m)) {
                $number = $m[1];
            }
            $li = $a->parentNode;
            $date = '';
            if ($li !== null) {
                $date = self::text($xp->query(".//span[contains(@class,'chapter-release-date')]", $li)->item(0));
            }
            $items[] = [
                'id' => trim((string) parse_url($this->resolveUrl($base, $href), PHP_URL_PATH), '/'),
                'title' => $text,
                'number' => $number,
                'date' => $date,
            ];
        }
        return $items;
    }

    // Chapter images from a chapter page, in the order the source's
    // recorded pattern uses (data/source-patterns.json): the recorded
    // mechanism first (stable choice), then the rest, so a stale
    // record never breaks the read.
    // - html: <img> tags in the chapter container (the common Madara
    //   case; the srcs usually point at a CDN already).
    // - cdn: image URLs on known image hosts embedded in the page
    //   outside <img> tags (JS arrays, data attributes), filtered
    //   to this chapter's number.
    // - spa: the page's embedded JSON state (__NEXT_DATA__ or
    //   <script type="application/json">).
    private function parseChapterImages(string $html, string $chapterId): array
    {
        $pattern = $this->sourcePattern();
        $order = ['html', 'cdn', 'spa'];
        $mode = (string) ($pattern['images'] ?? '');
        if ($mode !== '' && $mode !== 'api' && in_array($mode, $order, true)) {
            $order = array_values(array_unique(array_merge([$mode], $order)));
        }
        foreach ($order as $kind) {
            $images = match ($kind) {
                'cdn' => $this->parseChapterImagesCdn($html, $chapterId),
                'spa' => $this->parseChapterImagesSpa($html),
                default => $this->parseChapterImagesHtml($html),
            };
            if ($images !== []) {
                return $images;
            }
        }
        return [];
    }

    private function parseChapterImagesHtml(string $html): array
    {
        $xp = self::xpath($html);
        if ($xp === null) {
            return [];
        }
        $nodes = $xp->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' reading-content ')]//img");
        if ($nodes === false || $nodes->length === 0) {
            $nodes = $xp->query("//div[contains(@class,'page-break')]//img");
        }
        if ($nodes === false) {
            return [];
        }
        $images = [];
        foreach ($nodes as $img) {
            $src = self::imgSrc($img);
            if ($src !== '' && str_starts_with($src, 'http')) {
                $images[] = $src;
            }
        }
        return array_values(array_unique($images));
    }

    // CDN links embedded in the page (JS arrays, data attributes)
    // rather than <img> tags. Only URLs on known image hosts whose
    // path carries this chapter's number (or the title slug), so a
    // cover or a related title's thumbnail is not mistaken for a
    // page image. Sorted by the page number in the URL.
    private function parseChapterImagesCdn(string $html, string $chapterId): array
    {
        $hosts = $this->knownImageHosts();
        if ($hosts === []) {
            return [];
        }
        $hostAlt = implode('|', array_map(
            fn($h) => preg_quote($h, '#'), $hosts
        ));
        if (!preg_match_all(
            '#https?://(?:' . $hostAlt . ')/[^\\s"\'<>]+\\.(?:webp|jpg|jpeg|png|gif)(?:\\?[^\\s"\'<>]*)?#i',
            $html, $m
        )) {
            return [];
        }
        $number = $this->chapterNumber($chapterId);
        $slug = '';
        $parts = array_values(array_filter(explode('/', trim($chapterId, '/'))));
        if (count($parts) >= 2) {
            $slug = $parts[count($parts) - 2];
        }
        $found = [];
        foreach (array_unique($m[0]) as $url) {
            $path = parse_url($url, PHP_URL_PATH) ?? '';
            $isChapter = ($number !== '' && str_contains($path, '/' . $number . '/'))
                || ($number !== '' && str_contains($path, '/' . $number . '.'))
                || ($slug !== '' && str_contains($path, '/' . $slug . '/'));
            if (!$isChapter) {
                continue;
            }
            $found[] = $url;
        }
        usort($found, function ($a, $b) {
            $na = $this->pageIndex($a);
            $nb = $this->pageIndex($b);
            if ($na !== $nb) {
                return $na <=> $nb;
            }
            return strcmp($a, $b);
        });
        return array_values(array_unique($found));
    }

    // Trailing page number in an image URL (/211/12.webp -> 12).
    private function pageIndex(string $url): int
    {
        if (preg_match('#/(\\d+)\\.(?:webp|jpg|jpeg|png|gif)(?:\\?|$)#i', $url, $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    // SPA state: image URLs inside __NEXT_DATA__ or
    // <script type="application/json"> blocks.
    private function parseChapterImagesSpa(string $html): array
    {
        $blobs = [];
        if (preg_match(
            '#<script[^>]*id=["\']__NEXT_DATA__["\'][^>]*>(.*?)</script>#s',
            $html, $m
        )) {
            $blobs[] = $m[1];
        }
        if (preg_match_all(
            '#<script[^>]*type=["\']application/json["\'][^>]*>(.*?)</script>#s',
            $html, $m
        )) {
            $blobs = array_merge($blobs, $m[1]);
        }
        foreach ($blobs as $blob) {
            $data = json_decode($blob, true);
            if (!is_array($data)) {
                continue;
            }
            $images = $this->imageUrlsFromJson($data);
            if ($images !== []) {
                return $images;
            }
        }
        return [];
    }

    private function resolveUrl(string $base, string $href): string
    {
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }
        if (str_starts_with($href, '//')) {
            return 'https:' . $href;
        }
        if (str_starts_with($href, '/')) {
            $parts = parse_url($base);
            return ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . $href;
        }
        return rtrim($base, '/') . '/' . ltrim($href, '/');
    }
}
