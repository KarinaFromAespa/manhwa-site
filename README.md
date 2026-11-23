# Comix Reader

A small PHP website that browses, searches, and reads manga from
[comix.to](https://comix.to). Plain PHP 8, no database, no Composer,
no Node, no external dependencies. File-based caching only.

## Honest status (2026-11-23)

There is currently **no working fetcher** for comix.to. The facts:

- The API (`/api/v1`) requires a `_` signature parameter that only the
  site's own obfuscated JavaScript can compute. Without it the API
  answers `403 {"code": "missing_token"}`. Verified live from this
  sandbox on 2026-11-23.
- Chapter payloads are encrypted (`{"e": "..."}`); only the site's
  JavaScript decrypts them, so no server without a real browser session
  can produce chapter images.
- Cloudflare blocks automated browsers: clearance cookies get minted
  but are never honored by follow-up requests. Nine different stacks
  were tried (headless/headed Chromium, stealth, Byparr, Camoufox,
  WARP, VPN, nodriver, free proxies) — all failed.

Until a fetch service exists, this site shows clear errors on every
page instead of fake or empty data. See `status.php` on the live site
for the live diagnostic.

## Deploy (InfinityFree, manhwa.freedev.app)

1. Upload the **contents** of this folder (not the folder itself) into
   the `htdocs` directory of the hosting account `if0_43513996`
   (domain `manhwa.freedev.app`), either:
   - via FTP (host/port/user shown on the account's **FTP Details**
     page in the InfinityFree dashboard; password is the hosting
     account password), or
   - via the dashboard's **File Manager**: open the account, go to
     `htdocs`, upload the files.
2. Make sure `cache/` is writable by PHP (it is created automatically
   on first use if the parent directory allows it).
3. Set the environment variables below. On InfinityFree, env vars are
   not available from the panel, so copy `lib/config.php` to
   `lib/config.local.php` (git-ignored) and set the variables there as
   plain assignments, e.g. `$SERVICE_URL = 'https://...';`
   (omit it entirely if no service is configured — the site then runs
   in direct mode and shows the honest error).
4. Open `https://manhwa.freedev.app/` — the home page lists titles
   when a fetcher works, and shows the error box when it does not.
5. Open `https://manhwa.freedev.app/status.php` to confirm the
   configuration: PHP version, cache writable, service configured,
   and the result of one direct request to comix.to.

Workflow: every change to the code goes to the git repo first, then
the updated files are uploaded to `htdocs`. The live site is never
edited directly.

## Environment variables

| Variable | Meaning | Default |
| --- | --- | --- |
| `CX_SITE_NAME` | Name shown in the header/title | `Comix` |
| `CX_SERVICE_URL` | Base URL of the fetch service (empty = direct mode) | _(empty)_ |
| `CX_SERVICE_TOKEN` | Bearer token for the fetch service | _(empty)_ |
| `CX_CACHE_DIR` | Directory for cached responses/images | `cache/` |
| `CX_CACHE_TTL` | Cache lifetime in seconds | `900` |

No secrets are stored in the code. The service token is only ever
sent server-side in an `Authorization` header and never appears in
any page, API response, or error message.

## Fetch-service contract

If `CX_SERVICE_URL` is set, every API request is forwarded to it
instead of calling comix.to directly:

- **Request:** `GET {CX_SERVICE_URL}{path}?{query}` with header
  `Authorization: Bearer {CX_SERVICE_TOKEN}` and
  `Accept: application/json`. `{path}` is the comix.to API path, e.g.
  `/manga?limit=24&page=1`, `/manga/{slug}`,
  `/manga/{slug}/chapters`, `/manga/{slug}/chapters/{id}`.
- **Response:** HTTP 200 with the raw comix.to JSON for that endpoint
  as the body. An `{ok: true, data: ...}` envelope is also accepted.
  Any non-200 status is treated as "service unavailable".

The service is expected to do whatever is needed on its side to get
past comix.to's signature and Cloudflare checks (for example, driving
a real browser session). This site does not care how — it only needs
the JSON.

## Pages

| Page | Purpose |
| --- | --- |
| `index.php` | Browse latest titles + search (`?q=`, `?page=`) |
| `manga.php?slug=...` | Title details and chapter list |
| `read.php?slug=...&chapter=...` | Chapter reader (images via `img.php`) |
| `img.php?u=...` | Image proxy — `static.comix.to` only, cached on disk |
| `api.php?action=list\|detail\|chapters\|chapter` | JSON endpoint using the same client |
| `status.php` | Diagnostics: PHP version, cache, service, one direct-fetch test |

## Notes and assumptions

- Verified live 2026-11-23: `GET /api/v1/manga?limit=1` returns
  `403 {"message": "Missing token.", "code": "missing_token"}`;
  `GET /` returns the real homepage (200).
- Assumed, not yet verified against live responses: the search
  parameter (`?search=` on `/manga`), the detail endpoint
  (`/manga/{slug}`), the chapter list (`/manga/{slug}/chapters`),
  the chapter endpoint, and the exact JSON key names. The client
  normalizes several plausible shapes and all endpoints are defined
  in one place in `lib/comix.php`, so correcting them is a one-file
  change once a working fetcher returns real payloads.
