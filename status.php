<?php
// Diagnostics. Shows configuration state, catalog state, and the
// result of one direct request. Secret values and source names are
// never displayed.
require_once __DIR__ . '/lib/client.php';
require_once __DIR__ . '/lib/layout.php';

$client = new SiteClient();
$cacheWritable = is_dir($CACHE_DIR) ? is_writable($CACHE_DIR) : @mkdir($CACHE_DIR, 0755, true);
$test = $client->testDirect();
$info = Catalog::info();
$counts = $client->registryCount();

pageHead('Status');

echo '<h1>Status</h1>';
echo '<table class="status-table">';
echo '<tr><th>PHP version</th><td>' . h(PHP_VERSION) . '</td></tr>';
echo '<tr><th>cURL available</th><td>' . (function_exists('curl_init') ? 'Yes' : 'No') . '</td></tr>';
echo '<tr><th>Cache directory</th><td>' . h($CACHE_DIR) . ' — ' . ($cacheWritable ? 'writable' : 'NOT writable') . '</td></tr>';
echo '<tr><th>Cache TTL</th><td>' . h((string) $CACHE_TTL) . ' seconds</td></tr>';
echo '<tr><th>Fetch service</th><td>' . ($client->serviceConfigured() ? 'Configured (URL set)' : 'Not configured — direct mode') . '</td></tr>';
echo '<tr><th>Catalog</th><td>';
if ($info['present']) {
    $age = Catalog::relativeTime($info['generated_at']);
    echo 'Present — ' . (int) $info['count'] . ' titles'
        . ($age !== '' ? ', built ' . h($age) : '');
} else {
    echo 'Not present — the catalog has not been built yet, so pages fall back to live fetching.';
}
echo '</td></tr>';
echo '<tr><th>Sources in registry</th><td>' . (int) $counts['total']
    . ' (' . (int) $counts['free'] . ' free, ' . (int) $counts['login']
    . ' login-required, ' . (int) $counts['live'] . ' with a live adapter)</td></tr>';
echo '<tr><th>Direct fetch test</th><td>';
if ($test['ok']) {
    echo 'OK — a direct API request was answered.';
} else {
    echo 'Failed — ' . h($test['error']['kind'] ?? 'unknown') . ': ' . h($test['error']['message'] ?? '');
}
echo '</td></tr>';
echo '</table>';

echo '<p class="muted">The direct-fetch test makes one request to the first source. '
    . 'There is currently no working fetcher: source APIs require signatures only their own '
    . 'websites can compute, and automated browsers are blocked. Until the catalog is built '
    . 'or a fetch service is configured, browse/search pages will show that error honestly '
    . 'instead of pretending there are no results.</p>';

pageFoot();
