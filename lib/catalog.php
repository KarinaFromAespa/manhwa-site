<?php
// Catalog mode: reads the catalog the scheduled fetcher builds
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

    /** Genre names that denote adult/mature content. Adult titles
     *  have their own 18+ section now, so these are never offered
     *  as filter options. Matched case-insensitively. */
    private const ADULT_GENRES = ['adult', 'mature', 'smut', 'uncensored', 'hentai'];

    /** Genres present in the catalog, with title counts, most
     *  used first. Used for the genre filter on the browse page.
     *  Adult-coded genres are excluded: adult titles live in the
     *  18+ section, not in the genre filter. */
    public static function genres(): array
    {
        $counts = [];
        foreach (self::titles() as $t) {
            foreach ($t['genres'] ?? [] as $g) {
                $g = trim((string) $g);
                if ($g === '' || in_array(mb_strtolower($g), self::ADULT_GENRES, true)) {
                    continue;
                }
                $counts[$g] = ($counts[$g] ?? 0) + 1;
            }
        }
        $list = [];
        foreach ($counts as $name => $count) {
            $list[] = ['name' => $name, 'count' => $count];
        }
        usort($list, static function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
        return $list;
    }

    /** Canonical genre name for a requested value (case-insensitive),
     *  or '' when it is not a genre in the catalog. */
    public static function canonicalGenre(string $genre): string
    {
        $want = mb_strtolower(trim($genre));
        if ($want === '') {
            return '';
        }
        foreach (self::genres() as $g) {
            if (mb_strtolower($g['name']) === $want) {
                return $g['name'];
            }
        }
        return '';
    }

    public static function search(string $q, string $genre = ''): array
    {
        $q = mb_strtolower(trim($q));
        $genre = trim($genre);
        $genreLower = mb_strtolower($genre);
        if ($q === '' && $genreLower === '') {
            return self::titles();
        }
        $stop = ['the', 'a', 'an', 'of', 'and', 'in', 'on', 'to', 'for', 'with', 'by', 'at', 'from', 'is', 'are', 'was', 'were', 'be', 'been', 'it', 'its', 'this', 'that', 'as', 'or', 'but', 'not', 'no', 'my', 'your', 'his', 'her', 'their', 'our'];
        $words = array_values(array_filter(
            preg_split('/\s+/', $q) ?: [],
            static fn($w) => $w !== '' && !in_array($w, $stop, true)
        ));
        $out = [];
        foreach (self::titles() as $t) {
            // Genre filter: exact match against the title's genres
            // (case-insensitive). A title with no genres never
            // matches a genre filter.
            if ($genreLower !== '') {
                $hasGenre = false;
                foreach ($t['genres'] ?? [] as $g) {
                    if (mb_strtolower(trim((string) $g)) === $genreLower) {
                        $hasGenre = true;
                        break;
                    }
                }
                if (!$hasGenre) {
                    continue;
                }
            }
            $hay = mb_strtolower($t['title']);
            foreach (self::altTitles($t['id']) as $alt) {
                $hay .= ' ' . mb_strtolower($alt);
            }
            if ($words === []) {
                $match = str_contains($hay, $q);
            } else {
                // Match on whole word stems, so "reincarnated" also
                // finds "reincarnation" and "reincarnating", but a
                // query word must match a whole word in the title or
                // an alternative title — never a substring inside a
                // longer unrelated word ("realm" must not match
                // "realmente").
                $hayWords = array_filter(preg_split('/[^a-z0-9]+/', $hay) ?: []);
                $haySet = array_flip(array_map([self::class, 'stemWord'], $hayWords));
                $hayWordSet = array_flip($hayWords);
                $match = true;
                foreach ($words as $w) {
                    $stem = self::stemWord($w);
                    if (!isset($haySet[$stem]) && !isset($hayWordSet[$w])) {
                        $match = false;
                        break;
                    }
                }
            }
            if ($match) {
                $out[] = $t;
            }
        }
        return $out;
    }

    private static function stemWord(string $w): string
    {
        $w = mb_strtolower($w);
        // Strip one common inflectional suffix so word forms of the
        // same root match: "reincarnated", "reincarnating" and
        // "reincarnation" all reduce to "reincarnat". Longest suffix
        // first; "ion" must come before "tion"/"ation" or the stem
        // loses its "t". The length guard keeps short words (e.g.
        // "king") from being stripped.
        $suffixes = ['ing', 'ed', 'ion', 'ation', 'tion', 'ies', 'es', 'e', 's', 'ly', 'er', 'est', 'ment', 'ness', 'ity'];
        foreach ($suffixes as $suf) {
            $len = mb_strlen($suf);
            if (mb_strlen($w) > $len + 3 && str_ends_with($w, $suf)) {
                return mb_substr($w, 0, -$len);
            }
        }
        return $w;
    }

    // Full detail: index entry overlaid with data/titles/<id>.json
    // when the fetcher has written it.
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

    // --- Scanlators (one entry per source carrying the title) ---

    // Ranked scanlator list for a title: name (from the registry),
    // chapter count, freshness, reliability, quality, language.
    // Ranked by availability (has chapters) → freshness → reliability
    // → quality → language, so the first entry is the default pick.
    // Names are shown in the scanlator dropdown only — never in the
    // footer or error messages.
    public static function scanlators(string $id): array
    {
        $title = self::title($id);
        if ($title === null) {
            return [];
        }
        $registry = self::registryById();
        $out = [];
        foreach ($title['_sources'] as $src) {
            if (!is_array($src) || empty($src['source'])) {
                continue;
            }
            $sid = (string) $src['source'];
            $reg = $registry[$sid] ?? [];
            // Count the rows the title page actually renders for
            // this source, not the raw stored entries. A source can
            // store two entries for one chapter (a release-date
            // link, a duplicate), and the dropdown must agree with
            // the list it labels, or the count lies.
            $chapters = count(self::sourceChapters($id, $sid));
            $out[] = [
                'source' => $sid,
                'name' => (string) ($reg['name'] ?? $sid),
                'chapters' => $chapters,
                'fetched_at' => (int) (($src['fetched_at'] ?? $src['scraped_at'] ?? 0)),
                'reliability' => isset($src['reliability']) ? (float) $src['reliability'] : (isset($reg['reliability']) ? (float) $reg['reliability'] : null),
                'quality' => (string) ($src['quality'] ?? $reg['quality'] ?? ''),
                'lang' => (string) ($src['lang'] ?? $reg['lang'] ?? ''),
            ];
        }
        $qualityRank = ['high' => 3, 'standard' => 2, 'low' => 1];
        $langRank = ['en' => 0, 'all' => 1];
        usort($out, function ($a, $b) use ($qualityRank, $langRank) {
            $ha = $a['chapters'] > 0 ? 1 : 0;
            $hb = $b['chapters'] > 0 ? 1 : 0;
            if ($ha !== $hb) {
                return $hb <=> $ha;
            }
            if ($a['fetched_at'] !== $b['fetched_at']) {
                return $b['fetched_at'] <=> $a['fetched_at'];
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
            if ($la !== $lb) {
                return $la <=> $lb;
            }
            return strcmp($a['name'], $b['name']);
        });
        return $out;
    }

    // One source's chapter list, keyed like the merged rows
    // (number, or number-altN when one source lists two parts under
    // the same number). Newest first.
    public static function sourceChapters(string $id, string $sourceId): array
    {
        $title = self::title($id);
        if ($title === null) {
            return [];
        }
        $flat = [];
        foreach ($title['_sources'] as $src) {
            if (!is_array($src) || (string) ($src['source'] ?? '') !== $sourceId) {
                continue;
            }
            foreach ($src['chapters'] ?? [] as $ch) {
                if (!is_array($ch)) {
                    continue;
                }
                $number = self::normalizeNumber((string) ($ch['number'] ?? ''));
                if ($number === '') {
                    continue;
                }
                $flat[] = [
                    'number' => $number,
                    'title' => (string) ($ch['title'] ?? ''),
                    'id' => (string) ($ch['id'] ?? ''),
                    'date' => (string) ($ch['date'] ?? ''),
                    'images' => is_array($ch['images'] ?? null) ? array_values(array_filter(array_map('strval', $ch['images']))) : [],
                ];
            }
        }
        // Subgroup by chapter title: same number, different content
        // is a genuinely different chapter, not a duplicate.
        $groups = [];
        foreach ($flat as $c) {
            $groups[$c['number']][] = $c;
        }
        $rows = [];
        foreach ($groups as $number => $copies) {
            $subs = [];
            foreach ($copies as $c) {
                $subs[self::normalizeTitle($c['title'])][] = $c;
            }
            uasort($subs, fn($a, $b) => count($b) <=> count($a));
            $altIndex = 0;
            foreach ($subs as $sub) {
                $top = $sub[0];
                $rows[] = [
                    'key' => $altIndex > 0 ? $number . '-alt' . $altIndex : (string) $number,
                    'number' => (string) $number,
                    'title' => $top['title'],
                    'date' => $top['date'],
                    'alt' => $altIndex > 0,
                    'id' => $top['id'],
                    'images' => $top['images'],
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

    // One chapter of one source, by its key. Null when absent.
    public static function sourceChapter(string $id, string $sourceId, string $key): ?array
    {
        foreach (self::sourceChapters($id, $sourceId) as $row) {
            if ($row['key'] === $key) {
                return $row;
            }
        }
        return null;
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
            $sourceFetched = (int) (($src['fetched_at'] ?? $src['scraped_at'] ?? 0));
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
                    'source' => (string) ($src['source'] ?? ''),
                    'fetched_at' => (int) (($ch['fetched_at'] ?? $ch['scraped_at'] ?? $sourceFetched)),
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
                            'updated' => $c['fetched_at'],
                            'source' => $c['source'],
                            'id' => $c['id'],
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
                // Ranked copies with their source + chapter path, so
                // the client can fetch a chapter live when the
                // catalog has no images for it. Never rendered.
                'copy_list' => array_map(
                    fn($c) => [
                        'source' => $c['source'],
                        'id' => $c['id'],
                        'images' => $c['images'],
                    ],
                    $copies
                ),
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
            if ($a['fetched_at'] !== $b['fetched_at']) {
                return $b['fetched_at'] <=> $a['fetched_at'];
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
        // Strip the "Chapter N" prefix, including a dash decimal
        // ("Chapter 25-6" is ch 25.6, same as "Chapter 25.6"), so
        // the two spellings group as one chapter instead of two
        // "(alt)" rows. Single digit only, so a range ("Chapter
        // 25-26") keeps its suffix and still groups separately.
        // "Chap" is a prefix too (BunManga writes "Chap 1-5").
        $t = (string) preg_replace('/^(chapter|chap|ch\.?)\s*\d+(\.\d+|-\d(?!\d))?\s*[-–—:.]?\s*/i', '', $t);
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

    // Alternative names, English only, for the title page. Sources
    // sometimes mash several alternative names into one entry with
    // no delimiter, so a long entry is split on the bullet, on
    // , / |, and on a lowercase letter running straight into an
    // uppercase one. Names in a non-Latin script (CJK, Hangul,
    // Hiragana, Katakana, Cyrillic, Arabic) are dropped.
    public static function englishAltTitles(array $alts): array
    {
        $out = [];
        foreach ($alts as $alt) {
            $parts = preg_split('/\s*[•,|\/]\s*/u', (string) $alt) ?: [];
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p === '') {
                    continue;
                }
                $pieces = [$p];
                if (mb_strlen($p) > 60) {
                    $pieces = preg_split('/(?<=[a-z])(?=[A-Z])/u', $p) ?: [$p];
                }
                foreach ($pieces as $piece) {
                    $piece = trim($piece);
                    if (mb_strlen($piece) < 2) {
                        continue;
                    }
                    if (preg_match('/[^\x{0000}-\x{024F}\x{1E00}-\x{1EFF}]/u', $piece)) {
                        continue;
                    }
                    $out[] = $piece;
                }
            }
        }
        return array_values(array_unique($out));
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
