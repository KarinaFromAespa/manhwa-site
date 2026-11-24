<?php
require_once __DIR__ . '/lib/client.php';
require_once __DIR__ . '/lib/layout.php';

$client = new SiteClient();
$q = trim($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
// Genre filter: validated against the catalog, so a mistyped or
// stale genre in the URL is ignored instead of emptying the list.
$genre = Catalog::canonicalGenre($_GET['genre'] ?? '');

$res = $client->listTitles(24, $page, $q, $genre);

if ($q !== '' && $genre !== '') {
    pageHead('Search: ' . $q . ' in ' . $genre);
    echo '<h1>Search results for &ldquo;' . h($q) . '&rdquo; in ' . h($genre) . '</h1>';
} elseif ($q !== '') {
    pageHead('Search: ' . $q);
    echo '<h1>Search results for &ldquo;' . h($q) . '&rdquo;</h1>';
} elseif ($genre !== '') {
    pageHead('Genre: ' . $genre);
    echo '<h1>Genre: ' . h($genre) . '</h1>';
} else {
    pageHead('Browse');
    echo '<h1>Browse</h1>';
}

// Genre filter bar: a compact select (same pattern as the scanlator
// selector), auto-submits on change, keeps the search query, and
// resets to page 1.
$genres = Catalog::genres();
echo '<form class="genre-filter" action="index.php" method="get">';
if ($q !== '') {
    echo '<input type="hidden" name="q" value="' . h($q) . '">';
}
echo '<label for="genre-select">Genre</label>';
echo '<select id="genre-select" name="genre" onchange="this.form.submit()">';
echo '<option value="">All genres</option>';
foreach ($genres as $g) {
    $selected = ($genre !== '' && strcasecmp($g['name'], $genre) === 0) ? ' selected' : '';
    echo '<option value="' . h($g['name']) . '"' . $selected . '>' . h($g['name']) . ' (' . $g['count'] . ')</option>';
}
echo '</select>';
if ($genre !== '') {
    $clear = 'index.php' . ($q !== '' ? '?q=' . rawurlencode($q) : '');
    echo '<a href="' . h($clear) . '">Clear</a>';
}
echo '</form>';

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
        echo '<div class="pager">';
        if ($page > 1) {
            echo '<a href="index.php?page=' . ($page - 1) . $extra . '">&larr; Previous</a>';
        }
        if ($hasMore) {
            echo '<a href="index.php?page=' . ($page + 1) . $extra . '">Next &rarr;</a>';
        }
        echo '</div>';
    }
}

pageFoot();
