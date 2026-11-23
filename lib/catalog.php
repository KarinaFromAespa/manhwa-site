<?php
// Catalog mode: reads the catalog the scheduled scraper builds
// (data/catalog.json + data/titles/<id>.json). No network, no source
// names in any output — chapter copies are labelled "Copy 1", "Copy 2".
// When no catalog exists, the site falls back to live adapters.
require_once __DIR__ . '/config.php';

class Catalog
{
    private static ?array $index = null;
    private static array $titles = [];

    public static function dir(): string
    {
        global $DATA_DIR;
        return (string) $DATA_DIR;
    }

    public static function available(): bool
    {
        $idx = self::loadIndex();
        return $idx !== null && !empty($idx['titles']);
    }

    public static function info(): array
    {
        $idx = self::loadIndex();
        if ($idx === null) {
            return ['present' => false, 'generated_at' => null, 'count' => 0];
        }
        return [
            'present' => true,
            'generated_at' => $idx['generated_at'] ?? null,
            'count' => count($idx['titles'] ?? []),
        ];
    }

    // --- Index reads ---

    public static function titles(): array
    {
        $idx = self::loadIndex();
        if ($idx === null) {
            return [];
        }
        $out = [];
        foreach ($idx['titles'] ?? [] as $t) {
            if (is_array($t) && isset($t['id'], $t['title'])) {
                $out[] = self::normalizeIndexEntry($t);
            }
        }
        return $out;
    }

    public static function search(string $q): array
    {
        $q = mb_strtolower(trim($q));
        if ($q === '') {
            return self::titles();
        }
        $out = [];
        foreach (self::titles() as $t) {
            $hay = mb_strtolower($t['title']);
            foreach (self::altTitles($t['id']) as $alt) {
                $hay .= ' ' . mb_strtolower($alt);
            }
            if (str_contains($hay, $q)) {
                $out[] = $t;
            }
        }
        return $out;
    }

    // Full detail: index entry overlaid with data/titles/<id>.json
    // when the scraper has written it.
    public static function title(string $id): ?array
    {
        if (isset(self::$titles[$id])) {
            return self::$titles[$id];
        }
        $entry = null;
        foreach (self::titles() as $t) {
            if ($t['id'] === $id) {
                $entry = $t;
                break;
            }
        }
        if ($entry === null) {
            return null;
        }
        $file = self::dir() . '/titles/' . $id . '.json';
        if (is_file($file)) {
            $raw = @file_get_contents($file);
            $full = $raw !== false ? json_decode($raw, true) : null;
            if (is_array($full)) {
                $entry = array_merge($entry, [
                    'synopsis' => (string) ($full['synopsis'] ?? $entry['synopsis'] ?? ''),
                    'status' => (string) ($full['status'] ?? $entry['status']),
                    'genres' => is_array($full['genres'] ?? null) ? array_values(array_map('strval', $full['genres'])) : $entry['genres'],
                    'alt_titles' => is_array($full['alt_titles'] ?? null) ? array_values(array_map('strval', $full['alt_titles'])) : [],
                ]);
                $entry['_sources'] = is_array($full['sources'] ?? null) ? $full['sources'] : [];
            }
        }
        $entry['_sources'] = $entry['_sources'] ?? [];
        self::$titles[$id] = $entry;
        return $entry;
    }

    // --- Merged chapters ---

    // One row per chapter number (union across sources), each with its
    // ranked copies. Same number, different content becomes an "(alt)"
    // row instead of silently dropping one.
    public static function mergedChapters(string $id): array
    {
        $title = self::title($id);
        if ($title === null) {
            return [];
        }
        $registry = self::registryById();
        $groups = [];
        foreach ($title['_sources'] as $src) {
            if (!is_array($src) || empty($src['chapters']) || !is_array($src['chapters'])) {
                continue;
            }
            $reg = $registry[(string) ($src['source'] ?? '')] ?? [];
            $sourceScraped = (int) ($src['scraped_at'] ?? 0);
            foreach ($src['chapters'] as $ch) {
                if (!is_array($ch)) {
                    continue;
                }
                $number = self::normalizeNumber((string) ($ch['number'] ?? ''));
                if ($number === '') {
                    continue;
                }
                $images = $ch['images'] ?? [];
                if (!is_array($images)) {
                    $images = [];
                }
                $images = array_values(array_filter(array_map('strval', $images)));
                $groups[$number][] = [
                    'number' => $number,
                    'title' => (string) ($ch['title'] ?? ''),
                    'id' => (string) ($ch['id'] ?? ''),
                    'date' => (string) ($ch['date'] ?? ''),
                    'images' => $images,
                    'scraped_at' => (int) ($ch['scraped_at'] ?? $sourceScraped),
                    'reliability' => isset($src['reliability']) ? (float) $src['reliability'] : (isset($reg['reliability']) ? (float) $reg['reliability'] : null),
                    'quality' => (string) ($src['quality'] ?? $reg['quality'] ?? ''),
                    'lang' => (string) ($src['lang'] ?? $reg['lang'] ?? ''),
                ];
            }
        }
        $rows = [];
        foreach ($groups as $number => $copies) {
            // Subgroup by chapter title: same number, different content
            // is a genuinely different chapter, not a duplicate copy.
            $subs = [];
            foreach ($copies as $c) {
                $subs[self::normalizeTitle($c['title'])][] = $c;
            }
            uasort($subs, fn($a, $b) => count($b) <=> count($a));
            $altIndex = 0;
            foreach ($subs as $sub) {
                $ranked = self::rankCopies($sub);
                $isAlt = $altIndex > 0;
                // Alt keys use a "-altN" suffix so they stay URL-safe
                // and pass the site's slug validation.
                $key = $isAlt ? $number . '-alt' . $altIndex : (string) $number;
                $top = $ranked[0];
                $rows[] = [
                    'key' => $key,
                    'number' => (string) $number,
                    'title' => $top['title'],
                    'date' => $top['date'],
                    'alt' => $isAlt,
                    'copies' => array_map(
                        fn($c, $i) => [
                            'label' => 'Copy ' . ($i + 1),
                            'images' => $c['images'],
                            'updated' => $c['scraped_at'],
                        ],
                        $ranked,
                        array_keys($ranked)
                    ),
                ];
                $altIndex++;
            }
        }
        usort($rows, function ($a, $b) {
            $na = is_numeric($a['number']) ? (float) $a['number'] : -1;
            $nb = is_numeric($b['number']) ? (float) $b['number'] : -1;
            if ($na !== $nb) {
                return $nb <=> $na;
            }
            return strcmp($a['key'], $b['key']);
        });
        return $rows;
    }

    // Images for one merged chapter row and copy index.
    public static function chapterImages(string $id, string $chapterKey, int $copy = 0): array
    {
        foreach (self::mergedChapters($id) as $row) {
            if ($row['key'] !== $chapterKey) {
                continue;
            }
            $copies = $row['copies'];
            if ($copies === []) {
                return ['ok' => false, 'error' => ['kind' => 'no_images', 'message' => 'This chapter has no readable images yet.']];
            }
            $copy = max(0, min(count($copies) - 1, $copy));
            return ['ok' => true, 'data' => [
                'images' => $copies[$copy]['images'],
                'copies' => count($copies),
                'copy' => $copy,
                'label' => $copies[$copy]['label'],
                'number' => $row['number'],
                'title' => $row['title'],
                'alt' => $row['alt'],
            ]];
        }
        return ['ok' => false, 'error' => ['kind' => 'not_found', 'message' => 'Chapter not found.']];
    }

    // --- Ranking: availability, freshness, reliability, quality, language ---

    private static function rankCopies(array $copies): array
    {
        $qualityRank = ['high' => 3, 'standard' => 2, 'low' => 1];
        $langRank = ['en' => 0, 'all' => 1];
        usort($copies, function ($a, $b) use ($qualityRank, $langRank) {
            $ha = !empty($a['images']) ? 1 : 0;
            $hb = !empty($b['images']) ? 1 : 0;
            if ($ha !== $hb) {
                return $hb <=> $ha;
            }
            if ($a['scraped_at'] !== $b['scraped_at']) {
                return $b['scraped_at'] <=> $a['scraped_at'];
            }
            $ra = $a['reliability'] ?? 0.5;
            $rb = $b['reliability'] ?? 0.5;
            if ($ra !== $rb) {
                return $rb <=> $ra;
            }
            $qa = $qualityRank[strtolower($a['quality'])] ?? 2;
            $qb = $qualityRank[strtolower($b['quality'])] ?? 2;
            if ($qa !== $qb) {
                return $qb <=> $qa;
            }
            $la = $langRank[strtolower($a['lang'])] ?? 2;
            $lb = $langRank[strtolower($b['lang'])] ?? 2;
            return $la <=> $lb;
        });
        return $copies;
    }

    // --- Helpers ---

    public static function normalizeNumber(string $n): string
    {
        $n = trim($n);
        $n = (string) preg_replace('/^(chapter|ch\.?|ep\.?|episode)\s*/i', '', $n);
        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $n, $m)) {
            // "007" -> "7", "12.50" -> "12.5", "12.0" -> "12".
            $n = (string) ((int) $m[1]);
            if (isset($m[2]) && $m[2] !== '') {
                $frac = rtrim($m[2], '0');
                if ($frac !== '') {
                    $n .= '.' . $frac;
                }
            }
        }
        return $n;
    }

    private static function normalizeTitle(string $t): string
    {
        $t = mb_strtolower(trim($t));
        $t = (string) preg_replace('/^(chapter|ch\.?)\s*\d+(\.\d+)?\s*[-–—:.]?\s*/i', '', $t);
        return (string) preg_replace('/[^a-z0-9]+/', ' ', $t);
    }

    public static function relativeTime(?int $ts): string
    {
        if ($ts === null || $ts <= 0) {
            return '';
        }
        $diff = time() - $ts;
        if ($diff < 60) {
            return 'just now';
        }
        if ($diff < 3600) {
            $m = (int) ($diff / 60);
            return $m . ($m === 1 ? ' minute ago' : ' minutes ago');
        }
        if ($diff < 86400) {
            $h = (int) ($diff / 3600);
            return $h . ($h === 1 ? ' hour ago' : ' hours ago');
        }
        if ($diff < 86400 * 30) {
            $d = (int) ($diff / 86400);
            return $d . ($d === 1 ? ' day ago' : ' days ago');
        }
        return date('Y-m-d', $ts);
    }

    private static function normalizeIndexEntry(array $t): array
    {
        return [
            'id' => (string) $t['id'],
            'title' => (string) $t['title'],
            'cover' => (string) ($t['cover'] ?? ''),
            'status' => (string) ($t['status'] ?? ''),
            'genres' => is_array($t['genres'] ?? null) ? array_values(array_map('strval', $t['genres'])) : [],
            'updated' => isset($t['updated']) ? (int) $t['updated'] : null,
            'copies' => isset($t['copies']) ? (int) $t['copies'] : 0,
            'adult' => !empty($t['adult']),
            'synopsis' => '',
            'alt_titles' => [],
        ];
    }

    private static function altTitles(string $id): array
    {
        $t = self::title($id);
        return $t['alt_titles'] ?? [];
    }

    private static function registryById(): array
    {
        static $byId = null;
        if ($byId === null) {
            $byId = [];
            $file = __DIR__ . '/sources.php';
            if (is_file($file)) {
                $list = require $file;
                if (is_array($list)) {
                    foreach ($list as $s) {
                        if (is_array($s) && isset($s['id'])) {
                            $byId[$s['id']] = $s;
                        }
                    }
                }
            }
        }
        return $byId;
    }

    private static function loadIndex(): ?array
    {
        if (self::$index !== null) {
            return self::$index;
        }
        $file = self::dir() . '/catalog.json';
        if (!is_file($file)) {
            self::$index = null;
            return null;
        }
        $raw = @file_get_contents($file);
        $data = $raw !== false ? json_decode($raw, true) : null;
        if (!is_array($data) || !isset($data['titles']) || !is_array($data['titles'])) {
            self::$index = null;
            return null;
        }
        self::$index = $data;
        return $data;
    }
}
