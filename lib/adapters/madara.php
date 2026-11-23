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

    public function chapter(string $slug, string $chapterId): array
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
        $res = $this->fetch($base . '/' . trim($chapterId, '/') . '/');
        if (!$res['ok']) {
            return $res;
        }
        $images = $this->parseChapterImages($res['data']);
        if ($images === []) {
            return self::err('bad_response', 'The chapter page did not contain any readable images.');
        }
        $out = self::ok(['images' => $images]);
        $this->cacheSet($key, $out);
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
            $link = $xp->query(".//a[contains(@href,'/manga/')]", $node)->item(0);
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

    private function parseChapterImages(string $html): array
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
