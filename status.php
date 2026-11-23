<?php
// Diagnostics. Shows configuration state and the result of one direct
// request to comix.to. Secret values are never displayed — only whether
// a service is configured or not.
require_once __DIR__ . '/lib/comix.php';
require_once __DIR__ . '/lib/layout.php';

$client = new ComixClient();
$cacheWritable = is_dir($CACHE_DIR) ? is_writable($CACHE_DIR) : @mkdir($CACHE_DIR, 0755, true);
$test = $client->testDirect();

pageHead('Status');

echo '<h1>Status</h1>';
echo '<table class="status-table">';
echo '<tr><th>PHP version</th><td>' . h(PHP_VERSION) . '</td></tr>';
echo '<tr><th>cURL available</th><td>' . (function_exists('curl_init') ? 'Yes' : 'No') . '</td></tr>';
echo '<tr><th>Cache directory</th><td>' . h($CACHE_DIR) . ' — ' . ($cacheWritable ? 'writable' : 'NOT writable') . '</td></tr>';
echo '<tr><th>Cache TTL</th><td>' . h((string) $CACHE_TTL) . ' seconds</td></tr>';
echo '<tr><th>Fetch service</th><td>' . ($client->serviceConfigured() ? 'Configured (URL set)' : 'Not configured — direct mode') . '</td></tr>';
echo '<tr><th>Direct fetch test</th><td>';
if ($test['ok']) {
    echo 'OK — comix.to answered a direct API request.';
} else {
    echo 'Failed — ' . h($test['error']['kind'] ?? 'unknown') . ': ' . h($test['error']['message'] ?? '');
}
echo '</td></tr>';
echo '</table>';

echo '<p class="muted">The direct-fetch test makes one request to /api/v1/manga?limit=1. '
    . 'As of 2026-11-23 there is no working fetcher: the API requires a "_" signature '
    . 'only the site\'s own JavaScript can compute, and Cloudflare blocks automated browsers. '
    . 'Until a fetch service is configured, browse/search pages will show that error honestly '
    . 'instead of pretending there are no results.</p>';

pageFoot();
