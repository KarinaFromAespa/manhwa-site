<?php
require_once __DIR__ . '/lib/client.php';
require_once __DIR__ . '/lib/layout.php';

$slug = $_GET['slug'] ?? '';
$chapterId = $_GET['chapter'] ?? '';
$copy = max(0, (int) ($_GET['copy'] ?? 0));
$scanParam = trim($_GET['scan'] ?? '');
if (!SiteClient::validSlug($slug) || $chapterId === '' || !SiteClient::validSlug($chapterId)) {
    pageHead('Not found');
    echo '<p class="muted">Invalid chapter.</p>';
    pageFoot();
    exit;
}

// Scanlator mode when a scanlator is chosen; otherwise the merged
// ("All versions") view. Prev/next follow the same list.
$scanlatorMode = $scanParam !== '' && $scanParam !== 'all';
$scan = $scanlatorMode ? $scanParam : null;

$client = new SiteClient();
$chapter = $client->getChapter($slug, $chapterId, $copy, $scan);

pageHead('Chapter ' . $chapterId);
echo '<p><a href="manga.php?slug=' . rawurlencode($slug) . '">&larr; Back to title</a></p>';

if (!$chapter['ok']) {
    errorBox($chapter['error']);
    pageFoot();
    exit;
}

$data = $chapter['data'];

// Chapter payloads from some sources are encrypted ({"e": "..."}) and
// only the source's own website can decrypt them. A server without a
// real browser session cannot produce the page images.
if (is_array($data) && isset($data['e'])) {
    echo '<div class="error-box"><strong>This chapter cannot be read here yet.</strong>'
        . '<p>The chapter pages are encrypted, and only the source website can decrypt them. '
        . 'This site has no working fetcher that can do that step, so the images cannot be shown.</p></div>';
    pageFoot();
    exit;
}

$images = [];
if (is_array($data)) {
    $images = $data['images'] ?? $data['pages'] ?? $data['data'] ?? [];
}
if (!is_array($images) || $images === []) {
    echo '<div class="error-box"><strong>No page images in this chapter.</strong>'
        . '<p>The chapter response did not contain any readable images.</p></div>';
    pageFoot();
    exit;
}

// Who is supplying this chapter, and the "try another copy" link.
$scanQs = $scanlatorMode ? '&amp;scan=' . rawurlencode($scan) : '';
if (!empty($data['scan'])) {
    echo '<p class="muted">Reading from ' . h($data['scan_name'] ?? $data['scan']) . '</p>';
    $nextScan = $data['next_scan'] ?? null;
    $nextChapter = $data['next_chapter'] ?? null;
    if ($nextScan !== null && $nextChapter !== null) {
        $nextQs = $nextScan === 'all' ? '' : '&amp;scan=' . rawurlencode($nextScan);
        echo '<p class="muted"><a href="read.php?slug=' . rawurlencode($slug)
            . '&amp;chapter=' . rawurlencode($nextChapter) . $nextQs . '">Try another copy</a></p>';
    }
} else {
    $copies = (int) ($data['copies'] ?? 1);
    $copyIndex = (int) ($data['copy'] ?? 0);
    if ($copies > 1) {
        $next = ($copyIndex + 1) % $copies;
        echo '<p class="muted">Copy ' . ($copyIndex + 1) . ' of ' . $copies
            . ' &middot; <a href="read.php?slug=' . rawurlencode($slug)
            . '&amp;chapter=' . rawurlencode($chapterId)
            . '&amp;copy=' . $next . '">Try another copy</a></p>';
    }
}

// Prev/next chapter navigation (same list as this chapter came from).
$navPrev = null;
$navNext = null;
$list = $client->getChapters($slug, $scanlatorMode ? $scan : 'all');
if ($list['ok']) {
    $rows = $list['data'];
    foreach ($rows as $i => $r) {
        if ($r['id'] === $chapterId) {
            if ($i > 0) {
                $navNext = $rows[$i - 1]; // list is newest-first
            }
            if ($i + 1 < count($rows)) {
                $navPrev = $rows[$i + 1];
            }
            break;
        }
    }
}
$nav = function ($row, string $label) use ($slug, $scanQs) {
    if ($row === null) {
        return '<span class="reader-nav-disabled">' . h($label) . '</span>';
    }
    $text = $row['number'] !== '' ? 'Chapter ' . $row['number'] : $row['title'];
    return '<a href="read.php?slug=' . rawurlencode($slug)
        . '&amp;chapter=' . rawurlencode($row['id']) . $scanQs . '">'
        . h($label) . ' ' . h($text) . '</a>';
};
echo '<nav class="reader-nav">'
    . $nav($navPrev, '&larr; Previous')
    . $nav($navNext, 'Next &rarr;')
    . '</nav>';

echo '<div class="reader">';
foreach ($images as $img) {
    $src = is_array($img) ? ($img['url'] ?? $img['src'] ?? '') : (string) $img;
    if ($src === '') {
        continue;
    }
    echo '<img loading="lazy" referrerpolicy="no-referrer" src="img.php?u=' . rawurlencode($src) . '" data-direct="' . h($src) . '" onerror="this.onerror=null;this.src=this.dataset.direct" alt="">';
}
echo '</div>';

echo '<nav class="reader-nav">'
    . $nav($navPrev, '&larr; Previous')
    . $nav($navNext, 'Next &rarr;')
    . '</nav>';
echo '<p><a href="manga.php?slug=' . rawurlencode($slug) . '">&larr; Back to title</a></p>';

pageFoot();
