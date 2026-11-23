<?php
// Image proxy. Only static.comix.to is allowed; anything else is rejected
// so this cannot be abused as an open proxy. Proxied images are cached
// on disk to avoid re-downloading them on every page view.
require_once __DIR__ . '/lib/config.php';

$url = $_GET['u'] ?? '';
$parts = parse_url($url);
if (
    $url === '' || $parts === false
    || ($parts['scheme'] ?? '') !== 'https'
    || ($parts['host'] ?? '') !== 'static.comix.to'
    || !isset($parts['path'])
) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid image URL.';
    exit;
}

$key = md5($url);
$cacheFile = $CACHE_DIR . '/img_' . $key;
$metaFile = $cacheFile . '.meta';

// Serve from cache when fresh.
if (is_file($cacheFile) && is_file($metaFile) && filemtime($cacheFile) > time() - $CACHE_TTL) {
    $mime = trim((string) @file_get_contents($metaFile));
    if ($mime !== '') {
        header('Content-Type: ' . $mime);
        header('Cache-Control: public, max-age=86400');
        readfile($cacheFile);
        exit;
    }
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
]);
$body = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$mime = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($body === false || $code !== 200) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Could not fetch the image.';
    exit;
}

// Only serve real images.
if (!str_starts_with($mime, 'image/')) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->buffer($body) ?: '';
    if (!str_starts_with($mime, 'image/')) {
        http_response_code(502);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'The remote file is not an image.';
        exit;
    }
}

if (!is_dir($CACHE_DIR) && !@mkdir($CACHE_DIR, 0755, true)) {
    // Caching is best-effort; still serve the image.
    header('Content-Type: ' . $mime);
    echo $body;
    exit;
}
@file_put_contents($cacheFile, $body, LOCK_EX);
@file_put_contents($metaFile, $mime, LOCK_EX);

header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=86400');
echo $body;
