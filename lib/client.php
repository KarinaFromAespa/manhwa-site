<?php
// SiteClient: the one client the pages use. Read order:
//  1. Catalog mode (data/catalog.json exists): everything comes from
//     the catalog the scraper builds — merged titles and chapters,
//     no network, no source names in any output.
//  2. Live fallback: the first source's adapter (direct or service
//     mode). Only ever one source at a time, never a fan-out.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/adapters/adapter.php';
require_once __DIR__ . '/adapters/comix.php';
require_once __DIR__ . '/adapters/madara.php';

class SiteClient
{
    private ComixAdapter $comix;
    private array $registry = [];
    private array $adapters = [];

    public function __construct()
    {
        $file = __DIR__ . '/sources.php';
        if (is_file($file)) {
            $list = require $file;
            if (is_array($list)) {
                $this->registry = $list;
            }
        }
        $this->comix = new ComixAdapter($this->registryEntry('comix'));
    }

    public function catalogMode(): bool
    {
        return Catalog::available();
    }

    public function serviceConfigured(): bool
    {
        return $this->comix->serviceConfigured();
    }

    public function registryCount(): array
    {
        $free = 0;
        $login = 0;
        $live = 0;
        foreach ($this->registry as $s) {
            if (($s['access'] ?? '') === 'login') {
                $login++;
            } else {
                $free++;
            }
            if ($this->adapterFor($s) !== null) {
                $live++;
            }
        }
        return ['total' => count($this->registry), 'free' => $free, 'login' => $login, 'live' => $live];
    }

    // --- Reads (catalog first, live fallback) ---

    public function listTitles(int $limit = 24, int $page = 1, string $search = '', string $genre = ''): array
    {
        if ($this->catalogMode()) {
            $items = ($search !== '' || $genre !== '')
                ? Catalog::search($search, $genre)
                : Catalog::titles();
            $total = count($items);
            $page = max(1, $page);
            $slice = array_slice($items, ($page - 1) * $limit, $limit);
            return ['ok' => true, 'data' => $slice, 'total' => $total, 'page' => $page];
        }
        return $this->comix->list($limit, $page, $search);
    }

    public function getTitle(string $id): array
    {
        if ($this->catalogMode()) {
            $t = Catalog::title($id);
            if ($t === null) {
                return ['ok' => false, 'error' => ['kind' => 'not_found', 'message' => 'Title not found.']];
            }
            return ['ok' => true, 'data' => [
                'slug' => $t['id'],
                'title' => $t['title'],
                'cover' => $t['cover'],
                'synopsis' => $t['synopsis'],
                'status' => $t['status'],
                'genres' => $t['genres'],
                'updated' => $t['updated'],
                'copies' => $t['copies'],
                'alt_titles' => $t['alt_titles'] ?? [],
            ]];
        }
        return $this->comix->detail($id);
    }

    public function getChapters(string $id, ?string $scan = null): array
    {
        if ($this->catalogMode()) {
            if ($scan === 'all') {
                $rows = Catalog::mergedChapters($id);
            } else {
                $scans = Catalog::scanlators($id);
                $wanted = $scan ?? ($scans[0]['source'] ?? null);
                $found = null;
                foreach ($scans as $s) {
                    if ($s['source'] === $wanted) {
                        $found = $s['source'];
                        break;
                    }
                }
                $rows = Catalog::sourceChapters($id, $found ?? ($scans[0]['source'] ?? ''));
                // Per-source rows have no merged copies; shape them
                // like merged rows for the pages.
                $rows = array_map(
                    fn($r) => [
                        'key' => $r['key'],
                        'number' => $r['number'],
                        'title' => $r['title'],
                        'date' => $r['date'],
                        'alt' => $r['alt'],
                        'copies' => [['label' => 'Copy 1', 'images' => $r['images'], 'updated' => 0, 'source' => $found ?? '', 'id' => $r['id']]],
                    ],
                    $rows
                );
            }
            return ['ok' => true, 'data' => array_map(
                fn($r) => [
                    'id' => $r['key'],
                    'title' => $r['title'],
                    'number' => $r['number'],
                    'date' => $r['date'],
                    'copies' => count($r['copies']),
                    'alt' => $r['alt'],
                ],
                $rows
            )];
        }
        return $this->comix->chapters($id);
    }

    // Ranked scanlators for a title (catalog mode only). Empty
    // outside catalog mode — the dropdown is hidden there.
    public function getScanlators(string $id): array
    {
        if (!$this->catalogMode()) {
            return ['ok' => true, 'data' => []];
        }
        return ['ok' => true, 'data' => Catalog::scanlators($id)];
    }

    public function getChapter(string $id, string $chapterKey, int $copy = 0, ?string $scan = null): array
    {
        if (!$this->catalogMode()) {
            return $this->comix->chapter($id, $chapterKey);
        }
        if ($scan !== null && $scan !== '' && $scan !== 'all') {
            return $this->getChapterFromScanlator($id, $chapterKey, $scan);
        }
        $res = Catalog::chapterImages($id, $chapterKey, $copy);
        if (!$res['ok'] || !empty($res['data']['images'])) {
            // Catalog images when present (no live request), or the
            // catalog error when the chapter is not listed at all.
            return $res;
        }
        // Links-only catalog: a chapter with no stored images is
        // resolved live from its chapter link, copies in rank order,
        // until one yields images. The adapter caches the result, so
        // repeat opens are fast. Comix chapters are skipped — their
        // payloads are encrypted and no live fetch can read them.
        $list = $res['data']['copy_list'] ?? [];
        $start = (int) ($res['data']['copy'] ?? 0);
        $order = array_merge(
            [$start],
            array_values(array_filter(
                array_keys($list),
                fn($i) => $i !== $start
            ))
        );
        $fetchError = null;
        foreach ($order as $i) {
            $c = $list[$i] ?? null;
            if ($c === null || ($c['source'] ?? '') === '' || ($c['id'] ?? '') === '') {
                continue;
            }
            if (($c['source'] ?? '') === 'comix') {
                continue;
            }
            $adapter = $this->adapterFor($this->registryEntry($c['source']));
            if (!($adapter instanceof MadaraAdapter)) {
                continue;
            }
            $live = $adapter->chapter($id, $c['id'], 8);
            if ($live['ok'] && !empty($live['data']['images'] ?? [])) {
                $res['data']['images'] = $live['data']['images'];
                $res['data']['copy'] = $i;
                $res['data']['label'] = 'Copy ' . ($i + 1);
                $res['data']['live'] = true;
                return $res;
            }
            if (!$live['ok'] && isset($live['error'])) {
                $fetchError = $live['error'];
            }
        }
        // No copy yielded images. When the live fetch itself failed,
        // report that cause (Cloudflare, 403, timeout) instead of
        // the generic "no images" message.
        if ($fetchError !== null) {
            $err = ['ok' => false, 'error' => $fetchError];
            if (($fetchError['kind'] ?? '') === 'cloudflare') {
                // Frame fallback: the reader embeds this chapter URL
                // so the user solves the challenge in their own
                // browser (the clearance stays there; the server
                // never sees or stores it).
                $c = $list[$start] ?? $list[0] ?? null;
                if ($c !== null) {
                    $site = (string) ($this->registryEntry($c['source'] ?? '')['site'] ?? '');
                    $cid = (string) ($c['id'] ?? '');
                    if ($site !== '' && $cid !== '') {
                        $err['chapter_url'] = rtrim($site, '/') . '/' . trim($cid, '/') . '/';
                    }
                }
            }
            return $err;
        }
        return $res;
    }

    // Read one chapter from one scanlator: catalog images first,
    // else live from the chapter link via that scanlator's adapter.
    private function getChapterFromScanlator(string $id, string $chapterKey, string $scan): array
    {
        $scans = Catalog::scanlators($id);
        $pos = null;
        $current = null;
        foreach ($scans as $i => $s) {
            if ($s['source'] === $scan) {
                $pos = $i;
                $current = $s;
                break;
            }
        }
        if ($current === null) {
            return ['ok' => false, 'error' => ['kind' => 'not_found', 'message' => 'Title not found.']];
        }
        $row = Catalog::sourceChapter($id, $scan, $chapterKey);
        if ($row === null) {
            return ['ok' => false, 'error' => ['kind' => 'not_found', 'message' => 'Chapter not found.']];
        }
        $images = $row['images'];
        $live = false;
        $fetchError = null;
        if (empty($images) && $scan !== 'comix' && ($row['id'] ?? '') !== '') {
            $adapter = $this->adapterFor($this->registryEntry($scan));
            if ($adapter instanceof MadaraAdapter) {
                $fetched = $adapter->chapter($id, $row['id'], 8);
                if ($fetched['ok'] && !empty($fetched['data']['images'] ?? [])) {
                    $images = $fetched['data']['images'];
                    $live = true;
                } elseif (!$fetched['ok'] && isset($fetched['error'])) {
                    $fetchError = $fetched['error'];
                }
            }
        }
        // The live fetch failed: report its cause (Cloudflare, 403,
        // timeout) instead of the generic "no images" message, so
        // the reader shows why this chapter cannot be read.
        if ($images === [] && $fetchError !== null) {
            $err = ['ok' => false, 'error' => $fetchError];
            if (($fetchError['kind'] ?? '') === 'cloudflare') {
                // Frame fallback: the reader embeds this chapter URL
                // so the user solves the challenge in their own
                // browser (the clearance stays there; the server
                // never sees or stores it).
                $site = (string) ($this->registryEntry($scan)['site'] ?? '');
                $cid = (string) ($row['id'] ?? '');
                if ($site !== '' && $cid !== '') {
                    $err['chapter_url'] = rtrim($site, '/') . '/' . trim($cid, '/') . '/';
                    $err['scan'] = $scan;
                    $err['scan_name'] = $current['name'];
                }
            }
            return $err;
        }
        // Next scanlator carrying this chapter number, for the
        // "try another copy" link; 'all' when no other scanlator
        // has it (falls back to the merged view).
        $nextScan = 'all';
        $nextChapter = $row['number'];
        $n = count($scans);
        for ($k = 1; $k < $n; $k++) {
            $cand = $scans[($pos + $k) % $n];
            foreach (Catalog::sourceChapters($id, $cand['source']) as $r) {
                if ($r['number'] === $row['number']) {
                    $nextScan = $cand['source'];
                    $nextChapter = $r['key'];
                    break 2;
                }
            }
        }
        return ['ok' => true, 'data' => [
            'images' => $images,
            'copies' => $n,
            'copy' => $pos,
            'label' => $current['name'],
            'number' => $row['number'],
            'title' => $row['title'],
            'alt' => $row['alt'],
            'scan' => $scan,
            'scan_name' => $current['name'],
            'next_scan' => $nextScan,
            'next_chapter' => $nextChapter,
            'live' => $live,
        ]];
    }

    // One quick direct request, for yubkzrmdwv.php. Never hammered.
    public function testDirect(): array
    {
        return $this->comix->testDirect();
    }

    // --- Adapters ---

    // Adapter for one registry entry, or null when no live adapter
    // exists for that source's theme. Catalog-only sources return null.
    public function adapterFor(array $source): ?SourceAdapter
    {
        $id = (string) ($source['id'] ?? '');
        if (array_key_exists($id, $this->adapters)) {
            return $this->adapters[$id];
        }
        $adapter = null;
        if ($id === 'comix') {
            $adapter = $this->comix;
        } elseif (($source['theme'] ?? '') === 'madara') {
            $adapter = new MadaraAdapter($source);
        }
        $this->adapters[$id] = $adapter;
        return $adapter;
    }

    public function registryEntry(string $id): array
    {
        foreach ($this->registry as $s) {
            if (($s['id'] ?? '') === $id) {
                return $s;
            }
        }
        return [];
    }

    // Canonical ids and adapter slugs: path segments of letters,
    // digits, hyphens, underscores and slashes. Never "..".
    public static function validSlug(string $s): bool
    {
        if ($s === '' || str_contains($s, '..') || str_contains($s, '//')) {
            return false;
        }
        return (bool) preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-_\/]*$/', $s);
    }
}
