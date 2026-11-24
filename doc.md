# manhwafreedev

A small PHP website that browses, searches, and reads manhwa from
many sources. Plain PHP 8, no database, no Composer, no Node, no
external dependencies. File-based caching only.

How it works: a scheduled fetcher builds a catalog
(`data/catalog.json` + `data/titles/<id>.json`) and the site reads
it. One title is one page even when many sources carry it; chapters
are the union across sources, and alternate copies are labelled
"Copy 1", "Copy 2" — source names never appear anywhere a visitor
can see. See `docs/architecture.md` for the full design and
`data/catalog-schema.md` for the catalog format.

## Honest status (2026-11-23)

There is currently **no catalog and no working live fetcher**. The
facts:

- The catalog has not been built yet (the hourly fetcher is a
  separate piece of work), so `data/catalog.json` is an empty
  placeholder and pages fall back to live fetching.
- Live fetching fails: source APIs require signatures only their
  own websites can compute, and automated browsers are blocked.
  The first adapter's API still answers `403 missing_token`
  (verified live 2026-11-23).

Until the catalog exists or a fetch service is configured, this
site shows clear errors on every page instead of fake or empty
data. See `yubkzrmdwv.php` on the live site for the live diagnostic.

## Deploy (InfinityFree, manhwa.freedev.app)

1. Upload the **contents** of this folder (not the folder itself)
   into the `htdocs` directory of the hosting account
   `if0_43513996` (domain `manhwa.freedev.app`), either:
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
   (omit it entirely if no service is configured — the site then
   runs in direct mode and shows the honest error).
4. Open `https://manhwa.freedev.app/` — the home page lists titles
   when the catalog exists, and shows the error box when it does not.
5. Open `https://manhwa.freedev.app/yubkzrmdwv.php` to confirm the
   configuration: PHP version, cache writable, catalog state,
   registry counts, and the result of one direct request.

Workflow: every change to the code goes to the git repo first, then
the updated files are uploaded to `htdocs`. The live site is never
edited directly.

## Environment variables

| Variable | Meaning | Default |
| --- | --- | --- |
| `CX_SITE_NAME` | Name shown in the header/title | `manhwafreedev` |
| `CX_SERVICE_URL` | Base URL of the fetch service (empty = direct mode) | _(empty)_ |
| `CX_SERVICE_TOKEN` | Bearer token for the fetch service | _(empty)_ |
| `CX_CACHE_DIR` | Directory for cached responses/images | `cache/` |
| `CX_CACHE_TTL` | Cache lifetime in seconds | `900` |
| `CX_DATA_DIR` | Directory holding the catalog | `data/` |

No secrets are stored in the code. The service token is only ever
sent server-side in an `Authorization` header and never appears in
any page, API response, or error message.

## Sources and adapters

- `docs/manhwa-sources.json` is the source list (200 sites: free
  with no login, and free after login; paywalled sites excluded;
  adult titles included and tagged).
- `tools/build-sources-registry.py` generates `lib/sources.php`
  (the server-side registry) from that list. Re-run it whenever
  the list changes: `python3 tools/build-sources-registry.py`.
- `lib/adapters/adapter.php` defines the adapter interface
  (`list` / `detail` / `chapters` / `chapter`) and the shared
  plumbing (curl, file cache, HTML parsing, normalizers).
- `lib/adapters/comix.php` is the first source's adapter (API
  client, service mode + direct mode).
- `lib/adapters/madara.php` is a generic adapter for sites
  running the Madara theme (46 registry sources). Its selectors
  were verified live against one real Madara site on 2026-11-23
  (list, title page, chapter list, chapter images); many Madara
  sites are protected, so it fails honestly on those.
- `lib/client.php` (`SiteClient`) routes every read: catalog
  first, live adapters as fallback. Only ever one live source at
  a time, never a fan-out.
- `lib/catalog.php` reads the catalog and merges chapters at read
  time (union by chapter number; copies ranked by availability,
  freshness, reliability, quality, language).

## Fetch-service contract

If `CX_SERVICE_URL` is set, the first adapter forwards its API
requests to that service instead of calling the source directly:

- **Request:** `GET {CX_SERVICE_URL}{path}?{query}` with header
  `Authorization: Bearer {CX_SERVICE_TOKEN}` and
  `Accept: application/json`. `{path}` is the source API path, e.g.
  `/manga?limit=24&page=1`, `/manga/{slug}`,
  `/manga/{slug}/chapters`, `/manga/{slug}/chapters/{id}`.
- **Response:** HTTP 200 with the raw source JSON for that endpoint
  as the body. An `{ok: true, data: ...}` envelope is also accepted.
  Any non-200 status is treated as "service unavailable".

The service is expected to do whatever is needed on its side to get
past signature and bot checks (for example, driving a real browser
session). This site does not care how — it only needs the JSON.

## Pages

| Page | Purpose |
| --- | --- |
| `index.php` | Browse latest titles + search (`?q=`, `?page=`), with "updated X ago" per title in catalog mode |
| `manga.php?slug=...` | Title details and merged chapter list, with copy counts |
| `read.php?slug=...&chapter=...&copy=0` | Chapter reader (images via `img.php`), with a neutral "try another copy" switcher |
| `img.php?u=...` | Image proxy — allowlisted hosts only (registry site hosts + `data/image-hosts.json`), cached on disk for 7 days |
| `api.php?action=list\|detail\|chapters\|chapter` | JSON endpoint using the same client |
| `yubkzrmdwv.php` | Diagnostics: PHP version, cache, catalog, registry counts, service, one direct-fetch test |

## Notes and assumptions

- Verified live 2026-11-23: the first adapter's API returns
  `403 {"message": "Missing token.", "code": "missing_token"}`;
  its homepage returns the real page (200).
- Madara selectors verified live against one real Madara site
  (list items, title page, chapter list, chapter images with the
  real URL in `data-src`).
- Assumed, not yet verified against live responses: the first
  adapter's search parameter (`?search=` on `/manga`), the detail
  endpoint (`/manga/{slug}`), the chapter list
  (`/manga/{slug}/chapters`), the chapter endpoint, and the exact
  JSON key names. The client normalizes several plausible shapes
  and all endpoints are defined in one place in
  `lib/adapters/comix.php`, so correcting them is a one-file change
  once a working fetcher returns real payloads.
- Liveness of the 200 registry sites was not verified; domains
  rotate and some entries may be down or moved. The catalog
  builder is expected to drop dead sources from its output.
