<?php
// Thin JSON endpoint exposing the same client the pages use.
// GET api.php?action=list&limit=24&page=1&q=...
// GET api.php?action=detail&slug=...
// GET api.php?action=chapters&slug=...
// GET api.php?action=chapter&slug=...&chapter=...
// Response: {"ok": true, "data": ...} or
// {"ok": false, "error": {"kind": ..., "message": ...}}.
// The service token is never included in any response.
require_once __DIR__ . '/lib/comix.php';

header('Content-Type: application/json; charset=utf-8');

$client = new ComixClient();
$action = $_GET['action'] ?? '';
$slug = $_GET['slug'] ?? '';

switch ($action) {
    case 'list':
        $res = $client->listManga(
            (int) ($_GET['limit'] ?? 24),
            (int) ($_GET['page'] ?? 1),
            trim($_GET['q'] ?? '')
        );
        break;
    case 'detail':
        $res = ComixClient::validSlug($slug) ? $client->getManga($slug) : ['ok' => false, 'error' => ['kind' => 'bad_request', 'message' => 'Invalid slug.']];
        break;
    case 'chapters':
        $res = ComixClient::validSlug($slug) ? $client->getChapters($slug) : ['ok' => false, 'error' => ['kind' => 'bad_request', 'message' => 'Invalid slug.']];
        break;
    case 'chapter':
        $chapterId = $_GET['chapter'] ?? '';
        $res = (ComixClient::validSlug($slug) && ComixClient::validSlug($chapterId))
            ? $client->getChapter($slug, $chapterId)
            : ['ok' => false, 'error' => ['kind' => 'bad_request', 'message' => 'Invalid slug or chapter.']];
        break;
    default:
        $res = ['ok' => false, 'error' => ['kind' => 'bad_request', 'message' => 'Unknown action. Use list, detail, chapters, or chapter.']];
        break;
}

echo json_encode($res, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
