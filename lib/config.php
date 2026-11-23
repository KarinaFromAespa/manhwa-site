<?php
// Site configuration. Values come from environment variables; nothing
// secret is hardcoded here. A local config.local.php (git-ignored) may
// override these on the server if env vars are not available.

$SITE_NAME = getenv('CX_SITE_NAME') ?: 'manhwafreedev';
$SERVICE_URL = getenv('CX_SERVICE_URL') ?: '';
$SERVICE_TOKEN = getenv('CX_SERVICE_TOKEN') ?: '';
$CACHE_DIR = getenv('CX_CACHE_DIR') ?: __DIR__ . '/../cache';
$CACHE_TTL = (int) (getenv('CX_CACHE_TTL') ?: 900); // seconds, default 15 min
$DATA_DIR = getenv('CX_DATA_DIR') ?: __DIR__ . '/../data';
$IMG_CACHE_TTL = 604800; // seconds, 7 days for proxied images
$COMIX_BASE = 'https://comix.to';
$COMIX_API = $COMIX_BASE . '/api/v1';

// HTML-escape helper used by all rendered pages.
function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$localOverride = __DIR__ . '/config.local.php';
if (is_file($localOverride)) {
    require $localOverride;
}
