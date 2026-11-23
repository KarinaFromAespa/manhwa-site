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

    public function listTitles(int $limit = 24, int $page = 1, string $search = ''): array
    {
        if ($this->catalogMode()) {
            $items = $search !== '' ? Catalog::search($search) : Catalog::titles();
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
            ]];
        }
        return $this->comix->detail($id);
    }

    public function getChapters(string $id): array
    {
        if ($this->catalogMode()) {
            $rows = Catalog::mergedChapters($id);
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

    public function getChapter(string $id, string $chapterKey, int $copy = 0): array
    {
        if ($this->catalogMode()) {
            return Catalog::chapterImages($id, $chapterKey, $copy);
        }
        return $this->comix->chapter($id, $chapterKey);
    }

    // One quick direct request, for status.php. Never hammered.
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
