<?php
// Unlock cookies (mhcl_unlocked) live in the visitor's session,
// keyed by chapter URL, so a chapter they unlocked stays open
// while they read. Start the session before any output.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
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

// Unlock submission: the visitor solved the source's password
// question for a locked chapter. Re-derive the lock (fresh
// question + token), post their answer, and on success go back
// to the chapter, which now opens with the unlock cookie.
$unlockError = null;
$unlockLock = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'unlock') {
    $password = (string) ($_POST['password'] ?? '');
    $again = $client->getChapter($slug, $chapterId, $copy, $scan);
    if ($again['ok']) {
        header('Location: read.php?slug=' . rawurlencode($slug) . '&chapter=' . rawurlencode($chapterId) . ($scanlatorMode ? '&scan=' . rawurlencode($scanParam) : '') . ($copy > 0 ? '&copy=' . $copy : ''));
        exit;
    }
    $err = $again['error'] ?? [];
    if (($err['kind'] ?? '') === 'locked' && isset($err['lock']['url'], $err['source'])) {
        $unlocked = $client->unlockChapter((string) $err['source'], (string) $err['lock']['url'], $password);
        if ($unlocked['ok']) {
            header('Location: read.php?slug=' . rawurlencode($slug) . '&chapter=' . rawurlencode($chapterId) . ($scanlatorMode ? '&scan=' . rawurlencode($scanParam) : '') . ($copy > 0 ? '&copy=' . $copy : ''));
            exit;
        }
        $unlockError = $unlocked['error'] ?? null;
        $unlockLock = $unlockError['lock'] ?? ($err['lock'] ?? null);
    } else {
        $unlockError = $err;
    }
}

$chapter = $client->getChapter($slug, $chapterId, $copy, $scan);

pageHead('Chapter ' . $chapterId);
echo '<p><a href="manga.php?slug=' . rawurlencode($slug) . '">&larr; Back to title</a></p>';

if (!$chapter['ok']) {
    $err = $chapter['error'] ?? [];
    if (($err['kind'] ?? '') === 'locked' && isset($err['lock'])) {
        // Locked chapter: show the unlock popup with the source's
        // question instead of a bare error. The visitor solves it;
        // the site only posts their answer.
        $lock = $err['lock'];
        $question = (string) ($lock['question'] ?? '');
        $wrong = $unlockError !== null && ($unlockError['kind'] ?? '') === 'wrong_password';
        if ($wrong && $unlockLock !== null) {
            $lock = $unlockLock;
            $question = (string) ($lock['question'] ?? '');
        }
        echo '<div class="error-box unlock-box"><strong>This chapter is locked.</strong>'
            . '<p>The source asks a question before it will show this chapter. '
            . 'Answer it and the chapter will open.</p>'
            . ($question !== '' ? '<p class="unlock-question">Password is ? [ ' . h($question) . ' ]</p>' : '')
            . ($wrong ? '<p class="unlock-error">Wrong password. Try again.</p>' : '')
            . ($unlockError !== null && !$wrong ? '<p class="unlock-error">' . h((string) ($unlockError['message'] ?? 'The unlock did not work. Try again.')) . '</p>' : '')
            . '<form method="post" action="read.php?slug=' . rawurlencode($slug)
            . '&amp;chapter=' . rawurlencode($chapterId)
            . ($scanlatorMode ? '&amp;scan=' . rawurlencode($scanParam) : '')
            . ($copy > 0 ? '&amp;copy=' . $copy : '') . '">'
            . '<input type="hidden" name="action" value="unlock">'
            . '<label for="unlock-password">Password</label>'
            . '<input type="text" id="unlock-password" name="password" required autocomplete="off" inputmode="numeric" placeholder="Enter password">'
            . '<button type="submit">Unlock</button>'
            . '</form></div>';
        pageFoot();
        exit;
    }
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
    . $nav($navPrev, '← Previous')
    . $nav($navNext, 'Next →')
    . '</nav>';

echo '<div class="reader">';
foreach ($images as $img) {
    $src = is_array($img) ? ($img['url'] ?? $img['src'] ?? '') : (string) $img;
    if ($src === '') {
        continue;
    }
    echo '<img loading="lazy" referrerpolicy="no-referrer" src="img.php?u=' . rawurlencode($src) . '" data-direct="' . h($src) . '" onerror="readerImgError(this)" alt="">';
}
echo '</div>';
// When a source's image server is down, every page image fails
// (first the proxy, then the direct fallback) and the reader would
// otherwise show only a blank area. Track failures: once every
// image has failed, say so plainly instead of leaving a blank page.
echo '<div id="reader-load-error" class="error-box" hidden><strong>The page images could not be loaded.</strong>'
    . '<p>The source this copy comes from is not serving its images right now. '
    . 'Try another copy if one is listed above, or try again later.</p></div>';
echo '<script>'
    . 'function readerImgError(img){'
    . 'if(!img.dataset.directFailed){img.dataset.directFailed="1";img.src=img.dataset.direct;return;}'
    . 'img.dataset.failed="1";img.style.display="none";'
    . 'var imgs=document.querySelectorAll(".reader img");'
    . 'var failed=0;imgs.forEach(function(i){if(i.dataset.failed==="1")failed++;});'
    . 'if(imgs.length&&failed===imgs.length){document.getElementById("reader-load-error").hidden=false;}'
    . '}'
    . '</script>';

echo '<nav class="reader-nav">'
    . $nav($navPrev, '← Previous')
    . $nav($navNext, 'Next →')
    . '</nav>';
echo '<p><a href="manga.php?slug=' . rawurlencode($slug) . '">&larr; Back to title</a></p>';

// Back-to-top floating button. Only rendered when the chapter loaded,
// so it never appears on an error page. Shown once the reader has
// scrolled down a bit, hidden again near the top.
echo '<button id="toTopFab" class="fab" type="button" aria-label="Back to top" title="Back to top">&uarr;</button>';
echo '<script>'
    . '(function(){'
    . 'var fab=document.getElementById("toTopFab");'
    . 'if(!fab)return;'
    . 'function toggle(){fab.classList.toggle("visible",window.scrollY>400);}'
    . 'window.addEventListener("scroll",toggle,{passive:true});'
    . 'toggle();'
    . 'fab.addEventListener("click",function(){window.scrollTo({top:0,behavior:"smooth"});});'
    . '})();'
    . '</script>';

pageFoot();
