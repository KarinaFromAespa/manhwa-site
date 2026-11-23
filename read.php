<?php
require_once __DIR__ . '/lib/comix.php';
require_once __DIR__ . '/lib/layout.php';

$slug = $_GET['slug'] ?? '';
$chapterId = $_GET['chapter'] ?? '';
if (!ComixClient::validSlug($slug) || $chapterId === '' || !ComixClient::validSlug($chapterId)) {
    pageHead('Not found');
    echo '<p class="muted">Invalid chapter.</p>';
    pageFoot();
    exit;
}

$client = new ComixClient();
$chapter = $client->getChapter($slug, $chapterId);

pageHead('Chapter ' . $chapterId);
echo '<p><a href="manga.php?slug=' . rawurlencode($slug) . '">&larr; Back to title</a></p>';

if (!$chapter['ok']) {
    errorBox($chapter['error']);
    pageFoot();
    exit;
}

$data = $chapter['data'];
// Chapter payloads from comix.to are encrypted ({"e": "..."}) and only
// the site's own JavaScript can decrypt them. A server without a real
// browser session cannot produce the page images.
if (is_array($data) && isset($data['e'])) {
    echo '<div class="error-box"><strong>This chapter cannot be read here yet.</strong>'
        . '<p>comix.to encrypts its chapter pages, and only its own website can decrypt them. '
        . 'This site has no working fetcher that can do that step, so the images cannot be shown. '
        . 'The chapter is still readable on <a href="https://comix.to/">comix.to</a> itself.</p></div>';
    pageFoot();
    exit;
}

$images = [];
if (is_array($data)) {
    $images = $data['images'] ?? $data['pages'] ?? $data['data'] ?? [];
}
if (!is_array($images) || $images === []) {
    echo '<div class="error-box"><strong>No page images in this chapter.</strong>'
        . '<p>The chapter response did not contain any readable images. The fetch layer may be returning an unexpected format.</p></div>';
    pageFoot();
    exit;
}

echo '<div class="reader">';
foreach ($images as $img) {
    $src = is_array($img) ? ($img['url'] ?? $img['src'] ?? '') : (string) $img;
    if ($src === '') {
        continue;
    }
    echo '<img loading="lazy" src="img.php?u=' . rawurlencode($src) . '" alt="">';
}
echo '</div>';
echo '<p><a href="manga.php?slug=' . rawurlencode($slug) . '">&larr; Back to title</a></p>';

pageFoot();
