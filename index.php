<?php
require_once __DIR__ . '/lib/client.php';
require_once __DIR__ . '/lib/layout.php';

$client = new SiteClient();
$q = trim($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
// Genre filter: validated against the catalog, so a mistyped or
// stale genre in the URL is ignored instead of emptying the list.
$genre = Catalog::canonicalGenre($_GET['genre'] ?? '');
// Adult toggle: only the exact value '1' turns 18+ on, so a
// mistyped or empty adult param just shows the normal browse.
$adultOnly = ($_GET['adult'] ?? '') === '1';

$res = $client->listTitles(24, $page, $q, $genre, $adultOnly);

// The heading never names the genre: the selected genre is already
// shown in the header's genre select, so the page stays "Browse".
$genres = Catalog::genres();
pageHead($q !== '' ? 'Search: ' . $q : 'Browse', $genres, $genre, $adultOnly);
if ($q !== '') {
    echo '<h1>Search results for &ldquo;' . h($q) . '&rdquo;</h1>';
} else {
    echo '<h1>Browse</h1>';
}

if (!$res['ok']) {
    errorBox($res['error']);
} else {
    $items = $res['data'];
    if ($items === []) {
        echo '<p class="muted">No titles found.</p>';
    } else {
        echo '<div class="grid">';
        foreach ($items as $m) {
            $cover = $m['cover'] !== '' ? 'img.php?u=' . rawurlencode($m['cover']) : 'assets/no-cover.svg';
            $direct = $m['cover'] !== '' ? ' data-direct="' . h($m['cover']) . '" onerror="this.onerror=null;this.src=this.dataset.direct"' : '';
            echo '<a class="card" href="manga.php?slug=' . rawurlencode($m['id']) . '">'
                . '<img loading="lazy" referrerpolicy="no-referrer" src="' . h($cover) . '"' . $direct . ' alt="">'
                . '<span class="card-title">' . h($m['title']) . '</span>'
                . ($m['status'] !== '' ? '<span class="card-status">' . h($m['status']) . '</span>' : '');
            $updated = isset($m['updated']) ? Catalog::relativeTime($m['updated']) : '';
            if ($updated !== '') {
                echo '<span class="card-updated">Updated ' . h($updated) . '</span>';
            }
            echo '</a>';
        }
        echo '</div>';
        $total = $res['total'] ?? null;
        $hasMore = $total === null ? true : $total > $page * 24;
        $extra = '';
        if ($q !== '') {
            $extra .= '&amp;q=' . rawurlencode($q);
        }
        if ($genre !== '') {
            $extra .= '&amp;genre=' . rawurlencode($genre);
        }
        if ($adultOnly) {
            $extra .= '&amp;adult=1';
        }
        echo '<div class="pager">';
        if ($page > 1) {
            echo '<a href="index.php?page=' . ($page - 1) . $extra . '">&larr; Previous</a>';
        } else {
            echo '<span class="pager-disabled">&larr; Previous</span>';
        }
        // Numbered pages: first, last, and a window of 2 on each side
        // of the current page, with an ellipsis where pages are
        // skipped. Page size is 24, matching listTitles.
        $perPage = 24;
        $totalPages = $total !== null ? max(1, (int) ceil($total / $perPage)) : null;
        $pages = [];
        if ($totalPages !== null) {
            $want = [1, $totalPages];
            for ($pn = $page - 2; $pn <= $page + 2; $pn++) {
                if ($pn >= 1 && $pn <= $totalPages) {
                    $want[] = $pn;
                }
            }
            $want = array_unique($want);
            sort($want, SORT_NUMERIC);
            $prevPn = 0;
            foreach ($want as $pn) {
                if ($prevPn !== 0 && $pn - $prevPn > 1) {
                    $pages[] = 0; // ellipsis
                }
                $pages[] = $pn;
                $prevPn = $pn;
            }
        } else {
            // Total unknown: show a small window around the current
            // page, extending forward only when there is a next page.
            for ($pn = max(1, $page - 2); $pn <= $page; $pn++) {
                $pages[] = $pn;
            }
            if ($hasMore) {
                $pages[] = $page + 1;
            }
        }
        foreach ($pages as $pn) {
            if ($pn === 0) {
                echo '<span class="pager-ellipsis">&hellip;</span>';
            } elseif ($pn === $page) {
                echo '<span class="pager-current">' . $pn . '</span>';
            } else {
                echo '<a href="index.php?page=' . $pn . $extra . '">' . $pn . '</a>';
            }
        }
        if ($hasMore) {
            echo '<a href="index.php?page=' . ($page + 1) . $extra . '">Next &rarr;</a>';
        } else {
            echo '<span class="pager-disabled">Next &rarr;</span>';
        }
        echo '</div>';
    }
}

pageFoot();
