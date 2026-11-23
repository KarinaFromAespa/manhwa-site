<?php
require_once __DIR__ . '/lib/comix.php';
require_once __DIR__ . '/lib/layout.php';

$client = new ComixClient();
$q = trim($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));

$res = $client->listManga(24, $page, $q);

pageHead($q !== '' ? 'Search: ' . $q : 'Browse');
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
            echo '<a class="card" href="manga.php?slug=' . rawurlencode($m['slug']) . '">'
                . '<img loading="lazy" src="' . h($cover) . '" alt="">'
                . '<span class="card-title">' . h($m['title']) . '</span>'
                . ($m['status'] !== '' ? '<span class="card-status">' . h($m['status']) . '</span>' : '')
                . '</a>';
        }
        echo '</div>';
        echo '<div class="pager">';
        if ($page > 1) {
            echo '<a href="index.php?page=' . ($page - 1) . ($q !== '' ? '&amp;q=' . rawurlencode($q) : '') . '">&larr; Previous</a>';
        }
        echo '<a href="index.php?page=' . ($page + 1) . ($q !== '' ? '&amp;q=' . rawurlencode($q) : '') . '">Next &rarr;</a>';
        echo '</div>';
    }
}

pageFoot();
