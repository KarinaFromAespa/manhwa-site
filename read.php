<?php
require_once __DIR__ . '/lib/client.php';
require_once __DIR__ . '/lib/layout.php';

$slug = $_GET['slug'] ?? '';
$chapterId = $_GET['chapter'] ?? '';
$copy = max(0, (int) ($_GET['copy'] ?? 0));
if (!SiteClient::validSlug($slug) || $chapterId === '' || !SiteClient::validSlug($chapterId)) {
    pageHead('Not found');
    echo '<p class="muted">Invalid chapter.</p>';
    pageFoot();
    exit;
}

$client = new SiteClient();
$chapter = $client->getChapter($slug, $chapterId, $copy);

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

// Neutral mirror switcher: copies are numbered, never named.
$copies = (int) ($data['copies'] ?? 1);
$copyIndex = (int) ($data['copy'] ?? 0);
if ($copies > 1) {
    $next = ($copyIndex + 1) % $copies;
    echo '<p class="muted">Copy ' . ($copyIndex + 1) . ' of ' . $copies
        . ' &middot; <a href="read.php?slug=' . rawurlencode($slug)
        . '&amp;chapter=' . rawurlencode($chapterId)
        . '&amp;copy=' . $next . '">Try another copy</a></p>';
}

echo '<div class="reader">';
foreach ($images as $img) {
    $src = is_array($img) ? ($img['url'] ?? $img['src'] ?? '') : (string) $img;
    if ($src === '') {
        continue;
    }
    echo '<img loading="lazy" referrerpolicy="no-referrer" src="img.php?u=' . rawurlencode($src) . '" alt="">';
}
echo '</div>';
echo '<p><a href="manga.php?slug=' . rawurlencode($slug) . '">&larr; Back to title</a></p>';

pageFoot();
