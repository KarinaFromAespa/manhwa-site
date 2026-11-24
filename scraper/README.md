# Catalog scraper

Hourly batch scraper for manhwafreedev. Python 3, **standard
library only** (urllib, html.parser) — GitHub Actions needs no
setup step beyond checkout.

## Links-only model

The scraper collects **links, never image data**: title metadata
(title, cover link, synopsis, status, genres) and the chapter list
(number, title, link, id, date). It never fetches chapter pages.
Chapter images are resolved live by the reader (`lib/client.php`)
from the chapter link via the source adapter, using the file cache
so repeat opens are fast; a chapter that happens to already carry
image URLs in the catalog uses those first. Covers are links too:
the site proxies them through `img.php` (which sends a mapped
Referer when a CDN needs one) and the browser falls back to
loading them directly.

## What it does

1. Reads the source set from `docs/manhwa-sources.json` and filters
   it to the v1 set: confirmed, free, no-login, English,
   Madara-theme sources (see `V1_FILTER` in `scrape.py`). comix is
   excluded (its API needs a signature no server can compute);
   login-required sources are deferred (they need stored sessions).
2. For each source, fetches `/manga/` pages — Madara orders that
   archive newest-first, and each list item carries its two latest
   chapters with dates. The hourly run reads pages 1–2 (the
   recently-updated window); the seed run (`--seed`) walks the
   whole directory from a stored cursor, so the catalog reaches
   hundreds of titles.
3. Change detection costs zero requests for unchanged titles: the
   list item's latest chapter number + date is compared with
   `state.json`; only new or changed titles get a title-page fetch.
4. For new/changed titles it fetches the title page (metadata +
   chapter list). Titles with no chapter list (`no_chapters` in
   state) are retried every run, up to 10 per source.
5. Writes `data/catalog.json` (index) + `data/titles/<id>.json`
   (per-title detail) exactly per `data/catalog-schema.md`.
   **Per-source chapter lists are written as scraped; the site
   merges chapters across sources at read time** (`lib/catalog.php`).
6. Extends `data/image-hosts.json` with image CDNs seen (hosts that
   are not the source's own host or a subdomain of it — those are
   already allowed by the image proxy) and `data/image-referers.json`
   with host → [origins to try as Referer] for CDNs that
   hotlink-protect (recorded from the pages that link the images).

## Run it

```sh
python3 scraper/scrape.py                    # hourly run
python3 scraper/scrape.py --sources zinmanga # one source only
python3 scraper/scrape.py --dry-run          # scrape, write nothing
python3 scraper/scrape.py --seed --max-seconds 900 --max-requests 600  # seed pass
python3 scraper/scrape.py --max-seconds 300 --max-requests 400
```

## Budgets (private repo: 2,000 Actions min/month, 720 runs)

- Wall-clock cap 100 s, request cap 150 per hourly run (flags
  override). Per-request timeout 10 s, 0.5 s delay between
  requests to the same host, real browser User-Agent.
- Per source per run: 2 list pages (hourly) or up to 30 directory
  pages (seed, from the stored cursor); a new/changed title costs
  one title-page fetch. No chapter-page fetches at all.
- When a cap is hit the run stops gracefully and still writes a
  valid partial catalog. Coverage grows over successive runs:
  steady state is a handful of requests per hour, because
  unchanged titles cost zero requests.
- Fair share: the remaining time/request budget is split evenly
  over the sources still to run, recomputed before each source, so
  the first source cannot starve the rest. A source that finishes
  early leaves its share to the ones after it.
- Seed runs are longer by design (weekly workflow, 25 min cap) and
  resume from the cursor, so a big directory is covered over a few
  seed runs.

## State (`state.json`)

- `sources.<id>.attempts`: `[timestamp, ok]` pairs for the last 30
  days. Reliability = success rate over that window (null until a
  run has been attempted). A source that fails its list fetch is
  skipped for that run; its old data is carried forward.
- `sources.<id>.seed_page`: next `/manga/` page for the seed pass;
  `seed_completed_at` when a pass reached the end of a directory.
- `titles.<id>.sources.<source>`: last-seen chapter number/date,
  chapter count, chapter-list hash, `no_chapters` when a title page
  had no chapter list (AJAX-loaded lists — retried every run).
- `map`: `"source-id|slug" -> canonical id`, so ids stay stable
  across runs even when a title's name spelling changes.

## Canonical ids (`overrides.json`)

A title's id is its normalized title slug (accents folded,
punctuation stripped, "Season N" / "Part N" folded away). When two
different titles collide on one slug, the second gets an author
suffix. Wrong merges/splits are corrected by hand in
`overrides.json`: map a normalized slug (or `slug|author-slug`) to
the canonical id it should use. The runner consults aliases before
assigning a new id.

## Fetch patterns (API / CDN / HTML / SPA)

Sources expose their data in different ways, and the fetcher uses
whichever each source offers — API first where it exists, then CDN
links, then HTML parsing, then the page's embedded SPA state. The
pattern for each source is recorded in
`data/source-patterns.json` so the choice is stable across runs:
a recorded mechanism is tried first, and only replaced when a run
proves a different one works. The record is seeded from the
keiyoushi extensions-source repo (`~/workspace/ext-src`), which
already implements per-source fetching — read that source's
extension there instead of re-deriving the pattern from scratch.

- **API**: a JSON API for the chapter list (zinmanga-net:
  `/api/comics/<slug>/chapters?per_page=100&order=desc`, rate
  limit 60/min on `/api/`). The title page embeds the API URL.
- **CDN**: chapter images are CDN links (zinmanga-net's images
  live on `cdn.zinmanga1.com`, in `<img data-src>` tags). The
  image proxy sends a mapped Referer per host
  (`data/image-referers.json`) because some CDNs 403 without the
  right Referer — zinmanga-net's CDN 403s when the Referer is a
  chapter page, so the Referer must be the site origin.
- **HTML**: the server-rendered Madara markup (chapter list as
  `li.wp-manga-chapter`, images as `<img>` in `.reading-content` /
  `div.page-break`, `data-src` first). Most Madara sources.
- **SPA**: the page is a JS shell and the data lives in its
  embedded state (`__NEXT_DATA__` or `<script
  type="application/json">`), or the endpoints the SPA itself
  calls. No v1 source is confirmed SPA yet; the extractor runs
  last in the fallback chain and only records the pattern when it
  actually finds chapters/images.

Chapter-list mechanisms (Madara `ChapterMode`, from the theme):
`manga-page` (server-rendered), `manga-ajax` (POST
`{manga}/ajax/chapters/`, empty body, XHR headers),
`manga-ajax-paginated` (POST `{manga}/ajax/chapters/?t={page}`
until 404), `admin-ajax` (POST `/wp-admin/admin-ajax.php` with
`action=manga_get_chapters&manga=<post id>`), `api`, `spa`.
`detail()` tries the server-rendered list first (free — the title
page is already fetched), then the recorded mode, then the rest,
and reports which mode supplied the list so the runner can record
it. A title whose list comes from no mechanism is seeded from the
list item's latest chapters (real chapter links the reader can
open) and retried every run.

## Adding sources

Edit `docs/manhwa-sources.json` and regenerate the registry
(`tools/build-sources-registry.py`). The scraper picks up any
source matching the v1 filter on the next run. A source that is
Cloudflare-challenged or 403 from datacenter IPs goes in
`V1_EXCLUDE` with the date and reason (see `dragontea`).
Non-Madara themes need an adapter + parser before they can join.

## Limitations (v1)

- Only Madara-theme sources. MangaThemesia, KeyoApp and the rest
  are not scraped yet.
- The hourly run only sees titles on a source's `/manga/` pages
  1–2 (the recently-updated window). The seed pass covers the whole
  directory over successive runs.
- Chapter lists come from the server-rendered title page, or from
  the site's chapters JSON API when the page embeds one
  (zinmanga.net loads its list from `/api/comics/<slug>/chapters`,
  10/page, capped at 10 pages = 100 chapters per title). A site
  whose list is loaded some other way shows its titles with no
  chapters until an adapter for it exists; those titles are
  retried every run (`no_chapters` in state).
- Chapter images are never fetched by the scraper; the reader
  resolves them live. A chapter whose source is down at read time
  shows the honest "no images" box until the source answers.
- No login-required sources, no comix, no JS rendering. Anything
  Cloudflare-challenged from a datacenter IP is out until a
  residential fetcher (PC bridge) exists.
