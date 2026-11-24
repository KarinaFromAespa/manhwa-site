<?php
require_once __DIR__ . '/lib/client.php';
require_once __DIR__ . '/lib/layout.php';

$slug = $_GET['slug'] ?? '';
if (!SiteClient::validSlug($slug)) {
    pageHead('Not found');
    echo '<p class="muted">Invalid title.</p>';
    pageFoot();
    exit;
}

$client = new SiteClient();
$detail = $client->getTitle($slug);

if (!$detail['ok']) {
    pageHead('Error');
    errorBox($detail['error']);
    pageFoot();
    exit;
}

$m = $detail['data'];
pageHead($m['title']);

// Scanlator selection: ranked list, default = best-ranked.
// 'all' keeps the merged union view. Names come from the registry
// and are shown in the dropdown only — never in the footer or
// error messages.
$scanlators = $client->getScanlators($slug)['data'] ?? [];
$scanParam = trim($_GET['scan'] ?? '');
$scan = null;
if ($scanParam === 'all') {
    $scan = 'all';
} elseif ($scanParam !== '') {
    foreach ($scanlators as $s) {
        if ($s['source'] === $scanParam) {
            $scan = $scanParam;
            break;
        }
    }
}
if ($scan === null) {
    $scan = $scanlators[0]['source'] ?? 'all';
}

$cover = $m['cover'] !== '' ? 'img.php?u=' . rawurlencode($m['cover']) : 'assets/no-cover.svg';
$direct = $m['cover'] !== '' ? ' data-direct="' . h($m['cover']) . '" onerror="this.onerror=null;this.src=this.dataset.direct"' : '';
echo '<div class="hero">'
    . '<img class="hero-cover" referrerpolicy="no-referrer" src="' . h($cover) . '"' . $direct . ' alt="">'
    . '<div class="hero-info"><h1>' . h($m['title']) . '</h1>';
if ($m['status'] !== '') {
    echo '<span class="status-badge">' . h($m['status']) . '</span>';
}
$updated = isset($m['updated']) ? Catalog::relativeTime($m['updated']) : '';
if ($updated !== '') {
    echo '<p class="muted">Updated ' . h($updated) . '</p>';
}
if (!empty($m['copies']) && $m['copies'] > 1) {
    echo '<p class="muted">' . (int) $m['copies'] . ' scanlators carry this title.</p>';
}
if ($m['genres'] !== []) {
    echo '<p class="genres">' . implode('', array_map(fn($g) => '<span class="genre">' . h($g) . '</span>', $m['genres'])) . '</p>';
}
if ($m['synopsis'] !== '') {
    $syn = trim($m['synopsis']);
    $syn = preg_replace('/^.+?\bis a (?:Manga|Manhwa|Manhua)(?:\/(?:Manga|Manhwa|Manhua))* in english language,.*?english chapters have been \w*(?:\x{2026}|\.)?\s*/isu', '', $syn);
    $syn = trim($syn ?? '');
    if ($syn !== '') {
        echo '<p class="synopsis">' . h($syn) . '</p>';
    }
}
echo '</div></div>';

// Scanlator dropdown (a drop box, not tabs): the chapter list and
// the reader follow the choice.
if ($scanlators !== []) {
    $all = $client->getChapters($slug, 'all');
    $allCount = $all['ok'] ? count($all['data']) : 0;
    echo '<form class="scanlator" action="manga.php" method="get">'
        . '<input type="hidden" name="slug" value="' . h($slug) . '">'
        . '<label for="scan">Scanlator</label>'
        . '<select name="scan" id="scan" onchange="this.form.submit()">';
    foreach ($scanlators as $s) {
        $sel = ($scan === $s['source']) ? ' selected' : '';
        echo '<option value="' . h($s['source']) . '"' . $sel . '>'
            . h($s['name']) . ' (' . (int) $s['chapters'] . ' chapters)</option>';
    }
    echo '<option value="all"' . ($scan === 'all' ? ' selected' : '') . '>'
        . 'All versions (' . (int) $allCount . ' chapters)</option>';
    echo '</select><button type="submit">Go</button></form>';
}

$chapters = $client->getChapters($slug, $scan);
echo '<h2>Chapters</h2>';
if (!$chapters['ok']) {
    errorBox($chapters['error']);
} else {
    $list = $chapters['data'];
    if ($list === []) {
        echo '<p class="muted">No chapters listed.</p>';
    } else {
        $scanQs = ($scan !== 'all') ? '&amp;scan=' . rawurlencode($scan) : '';
        echo '<ul class="chapters">';
        foreach ($list as $c) {
            $num = $c['number'];
            $title = trim($c['title'] ?? '');
            $extra = '';
            if ($num !== '') {
                $label = 'Chapter ' . $num;
                if ($title !== '') {
                    if (preg_match('/^chapter\s*(\d+(?:\.\d+)?)(?:\s*[:\-–—]?\s*(.*))?$/i', $title, $m) && (float) $m[1] === (float) $num) {
                        $extra = $m[2] ?? '';
                    } else {
                        $extra = $title;
                    }
                }
            } else {
                $label = $title !== '' ? $title : 'Chapter ' . $c['id'];
            }
            if (!empty($c['alt'])) {
                $label .= ' (alt)';
            }
            echo '<li><a class="chapter-link" href="read.php?slug=' . rawurlencode($slug) . '&amp;chapter=' . rawurlencode($c['id']) . $scanQs . '">'
                . '<span class="chapter-num">' . h($label) . '</span>'
                . ($extra !== '' ? '<span class="chapter-title">' . h($extra) . '</span>' : '')
                . '</a>' . ($c['date'] !== '' ? '<span class="muted"> ' . h($c['date']) . '</span>' : '');
            if (!empty($c['copies']) && $c['copies'] > 1) {
                echo ' <span class="muted">(' . (int) $c['copies'] . ' copies)</span>';
            }
            echo '</li>';
        }
        echo '</ul>';
    }
}

pageFoot();
