<?php
// Shared page chrome. No logic here, just markup. Nothing rendered
// here names a source site.
function pageHead(string $title, array $genres = [], string $genre = '', bool $adultOnly = false): void
{
    global $SITE_NAME;
    $full = $title !== '' ? $title . ' — ' . $SITE_NAME : $SITE_NAME;
    $cssVer = @filemtime(__DIR__ . '/../assets/style.css');
    $cssHref = 'assets/style.css' . ($cssVer ? '?v=' . $cssVer : '');
    // Genre select (browse page only): sits beside the search box.
    // The selected genre is shown here, so the page heading stays
    // "Browse" and never repeats it.
    $genreSelect = '';
    if ($genres !== []) {
        $q = trim($_GET['q'] ?? '');
        $genreSelect = '<form class="genre-select" action="index.php" method="get">';
        if ($q !== '') {
            $genreSelect .= '<input type="hidden" name="q" value="' . h($q) . '">';
        }
        if ($adultOnly) {
            $genreSelect .= '<input type="hidden" name="adult" value="1">';
        }
        $genreSelect .= '<select name="genre" onchange="this.form.submit()">';
        $genreSelect .= '<option value="">All genres</option>';
        foreach ($genres as $g) {
            $selected = ($genre !== '' && strcasecmp($g['name'], $genre) === 0) ? ' selected' : '';
            $genreSelect .= '<option value="' . h($g['name']) . '"' . $selected . '>' . h($g['name']) . ' (' . $g['count'] . ')</option>';
        }
        $genreSelect .= '</select>';
        // 18+ toggle beside the genre dropdown: shows only adult
        // titles while on, and hides them again when pressed a
        // second time. A plain link (not a form submit), so it
        // never submits the genre form; it keeps the search and
        // genre, and drops the page, so the toggle always lands
        // on page 1 of the other set.
        $toggleParams = [];
        if ($q !== '') {
            $toggleParams[] = 'q=' . rawurlencode($q);
        }
        if ($genre !== '') {
            $toggleParams[] = 'genre=' . rawurlencode($genre);
        }
        if (!$adultOnly) {
            $toggleParams[] = 'adult=1';
        }
        $toggleHref = 'index.php' . ($toggleParams !== [] ? '?' . implode('&', $toggleParams) : '');
        $genreSelect .= '<a class="adult-toggle' . ($adultOnly ? ' active' : '') . '" href="' . h($toggleHref) . '" aria-pressed="' . ($adultOnly ? 'true' : 'false') . '" title="' . ($adultOnly ? 'Show all titles (hide adult)' : 'Show only adult (18+) titles') . '">18+</a>';
        if ($genre !== '') {
            $clearParams = [];
            if ($q !== '') {
                $clearParams[] = 'q=' . rawurlencode($q);
            }
            if ($adultOnly) {
                $clearParams[] = 'adult=1';
            }
            $clear = 'index.php' . ($clearParams !== [] ? '?' . implode('&', $clearParams) : '');
            $genreSelect .= '<a href="' . h($clear) . '">Clear</a>';
        }
        $genreSelect .= '</form>';
    }
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . h($full) . '</title>'
        . '<link rel="stylesheet" href="' . $cssHref . '"></head><body>'
        . '<header class="site-header"><div class="wrap">'
        . '<a class="brand" href="index.php">' . h($SITE_NAME) . '</a>'
        . '<form class="search" action="index.php" method="get">'
        . '<input type="search" name="q" placeholder="Search titles…" value="' . h($_GET['q'] ?? '') . '">'
        . ($genre !== '' ? '<input type="hidden" name="genre" value="' . h($genre) . '">' : '')
        . ($adultOnly ? '<input type="hidden" name="adult" value="1">' : '')
        . '<button type="submit">Search</button></form>'
        . $genreSelect
        . '</div></header><main class="wrap">';
}

function pageFoot(): void
{
    echo '</main><footer class="site-footer"><div class="wrap">'
        . 'This site stores no comics itself.'
        . '</div></footer></body></html>';
}

// Error box used everywhere a fetch fails. Never render a failed fetch
// as an empty list. Messages are generic and never name a source.
function errorBox(array $error): void
{
    echo '<div class="error-box"><strong>Could not load data.</strong>'
        . '<p>' . h($error['message'] ?? 'Unknown error.') . '</p>'
        . '<p class="error-kind">Error: ' . h($error['kind'] ?? 'unknown') . '</p></div>';
}
