<?php
require_once __DIR__ . '/lib/comix.php';
require_once __DIR__ . '/lib/layout.php';

$slug = $_GET['slug'] ?? '';
if (!ComixClient::validSlug($slug)) {
    pageHead('Not found');
    echo '<p class="muted">Invalid title.</p>';
    pageFoot();
    exit;
}

$client = new ComixClient();
$detail = $client->getManga($slug);

if (!$detail['ok']) {
    pageHead('Error');
    errorBox($detail['error']);
    pageFoot();
    exit;
}

$m = $detail['data'];
pageHead($m['title']);

$cover = $m['cover'] !== '' ? 'img.php?u=' . rawurlencode($m['cover']) : 'assets/no-cover.svg';
echo '<div class="detail">'
    . '<img class="detail-cover" src="' . h($cover) . '" alt="">'
    . '<div><h1>' . h($m['title']) . '</h1>';
if ($m['status'] !== '') {
    echo '<p><strong>Status:</strong> ' . h($m['status']) . '</p>';
}
if ($m['genres'] !== []) {
    echo '<p class="genres">' . implode('', array_map(fn($g) => '<span class="genre">' . h($g) . '</span>', $m['genres'])) . '</p>';
}
if ($m['synopsis'] !== '') {
    echo '<p class="synopsis">' . h($m['synopsis']) . '</p>';
}
echo '</div></div>';

$chapters = $client->getChapters($slug);
echo '<h2>Chapters</h2>';
if (!$chapters['ok']) {
    errorBox($chapters['error']);
} else {
    $list = $chapters['data'];
    if ($list === []) {
        echo '<p class="muted">No chapters listed.</p>';
    } else {
        echo '<ul class="chapters">';
        foreach ($list as $c) {
            $label = $c['number'] !== '' ? 'Chapter ' . $c['number'] : ($c['title'] !== '' ? $c['title'] : 'Chapter ' . $c['id']);
            echo '<li><a href="read.php?slug=' . rawurlencode($slug) . '&amp;chapter=' . rawurlencode($c['id']) . '">'
                . h($label)
                . ($c['title'] !== '' && $c['number'] !== '' ? ' — ' . h($c['title']) : '')
                . '</a>' . ($c['date'] !== '' ? '<span class="muted"> ' . h($c['date']) . '</span>' : '') . '</li>';
        }
        echo '</ul>';
    }
}

pageFoot();
