<?php
// Thin JSON endpoint exposing the same client the pages use.
// GET api.php?action=list&limit=24&page=1&q=...
// GET api.php?action=detail&slug=...
// GET api.php?action=chapters&slug=...
// GET api.php?action=chapter&slug=...&chapter=...&copy=0
// Response: {"ok": true, "data": ...} or
// {"ok": false, "error": {"kind": ..., "message": ...}}.
// The service token is never included in any response, and no
// response ever names a source site.
require_once __DIR__ . '/lib/client.php';

header('Content-Type: application/json; charset=utf-8');

$client = new SiteClient();
$action = $_GET['action'] ?? '';
$slug = $_GET['slug'] ?? '';
$chapterId = $_GET['chapter'] ?? '';
$copy = max(0, (int) ($_GET['copy'] ?? 0));
$valid = SiteClient::validSlug($slug) && ($chapterId === '' || SiteClient::validSlug($chapterId));

switch ($action) {
    case 'list':
        $res = $client->listTitles(
            (int) ($_GET['limit'] ?? 24),
            (int) ($_GET['page'] ?? 1),
            trim($_GET['q'] ?? '')
        );
        break;
    case 'detail':
        $res = SiteClient::validSlug($slug) ? $client->getTitle($slug) : ['ok' => false, 'error' => ['kind' => 'bad_request', 'message' => 'Invalid slug.']];
        break;
    case 'chapters':
        $res = SiteClient::validSlug($slug) ? $client->getChapters($slug) : ['ok' => false, 'error' => ['kind' => 'bad_request', 'message' => 'Invalid slug.']];
        break;
    case 'chapter':
        $res = $valid
            ? $client->getChapter($slug, $chapterId, $copy)
            : ['ok' => false, 'error' => ['kind' => 'bad_request', 'message' => 'Invalid slug or chapter.']];
        break;
    default:
        $res = ['ok' => false, 'error' => ['kind' => 'bad_request', 'message' => 'Unknown action. Use list, detail, chapters, or chapter.']];
        break;
}

echo json_encode($res, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
