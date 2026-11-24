<?php
// Image proxy. Only hosts on the allowlist are fetched, so this cannot
// be abused as an open proxy. Allowed hosts: every registered source's
// site host (including its subdomains, where image CDNs usually live),
// plus the image hosts in data/image-hosts.json (image CDNs on
// unrelated domains, which the scraper records). Proxied images are
// cached on disk for 7 days.
//
// Some image CDNs hotlink-protect: they return 403 unless the request
// carries a Referer from a site that uses them. data/image-referers.json
// maps such a host to the origins to try (recorded by the scraper from
// the pages that link the images). The proxy tries each mapped origin
// in order, then no Referer, until one returns a real image.
require_once __DIR__ . '/lib/config.php';

function imageHostsAllowed(): array
{
    static $hosts = null;
    if ($hosts !== null) {
        return $hosts;
    }
    $hosts = [];
    $regFile = __DIR__ . '/lib/sources.php';
    if (is_file($regFile)) {
        $list = require $regFile;
        if (is_array($list)) {
            foreach ($list as $s) {
                if (!empty($s['host'])) {
                    $hosts[] = strtolower((string) $s['host']);
                }
            }
        }
    }
    $extraFile = __DIR__ . '/data/image-hosts.json';
    if (is_file($extraFile)) {
        $extra = json_decode((string) @file_get_contents($extraFile), true);
        if (is_array($extra['hosts'] ?? null)) {
            foreach ($extra['hosts'] as $h) {
                $hosts[] = strtolower((string) $h);
            }
        }
    }
    $hosts = array_values(array_unique(array_filter($hosts)));
    return $hosts;
}

// Host -> [origins to try as Referer], in order. Empty when the file
// is missing or has no entry for the host.
function imageReferers(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [];
    $file = __DIR__ . '/data/image-referers.json';
    if (is_file($file)) {
        $data = json_decode((string) @file_get_contents($file), true);
        if (is_array($data)) {
            foreach ($data as $host => $origins) {
                if (!is_array($origins)) {
                    $origins = [$origins];
                }
                $clean = [];
                foreach ($origins as $o) {
                    $o = trim((string) $o);
                    if ($o !== '' && !in_array($o, $clean, true)) {
                        $clean[] = $o;
                    }
                }
                if ($clean !== []) {
                    $map[strtolower((string) $host)] = $clean;
                }
            }
        }
    }
    return $map;
}

$url = $_GET['u'] ?? '';
$parts = parse_url($url);
$host = strtolower((string) ($parts['host'] ?? ''));
$allowed = imageHostsAllowed();
$hostOk = in_array($host, $allowed, true);
if (!$hostOk) {
    // A source's own subdomains (its image CDNs) are allowed too.
    foreach ($allowed as $base) {
        if (str_ends_with($host, '.' . $base)) {
            $hostOk = true;
            break;
        }
    }
}
if (
    $url === '' || $parts === false
    || ($parts['scheme'] ?? '') !== 'https'
    || $host === ''
    || !$hostOk
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
if (is_file($cacheFile) && is_file($metaFile) && filemtime($cacheFile) > time() - $IMG_CACHE_TTL) {
    $mime = trim((string) @file_get_contents($metaFile));
    if ($mime !== '') {
        header('Content-Type: ' . $mime);
        header('Cache-Control: public, max-age=86400');
        readfile($cacheFile);
        exit;
    }
}

// Try each mapped Referer for this host in order, then no Referer.
// A CDN that hotlink-protects answers 403 to the wrong (or missing)
// Referer and 200 to the right one; the first success wins and is
// cached, so the retries are paid once per image at most.
$candidates = imageReferers()[ $host ] ?? [];
$candidates[] = null;
$body = false;
$code = 0;
$mime = '';
foreach ($candidates as $referer) {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
    ];
    if ($referer !== null) {
        $opts[CURLOPT_REFERER] = $referer;
    }
    curl_setopt_array($ch, $opts);
    $attempt = curl_exec($ch);
    $attemptCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $attemptMime = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if ($attempt === false || $attemptCode !== 200) {
        continue;
    }
    if (!str_starts_with($attemptMime, 'image/')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $attemptMime = $finfo->buffer($attempt) ?: '';
        if (!str_starts_with($attemptMime, 'image/')) {
            continue;
        }
    }
    $body = $attempt;
    $code = $attemptCode;
    $mime = $attemptMime;
    break;
}

if ($body === false || $code !== 200) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Could not fetch the image.';
    exit;
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
