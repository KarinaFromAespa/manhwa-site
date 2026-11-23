# Catalog scraper

Hourly batch scraper for manhwafreedev. Python 3, **standard
library only** (urllib, html.parser) — GitHub Actions needs no
setup step beyond checkout.

## What it does

1. Reads the source set from `docs/manhwa-sources.json` and filters
   it to the v1 set: confirmed, free, no-login, English,
   Madara-theme sources (see `V1_FILTER` in `scrape.py`). comix is
   excluded (its API needs a signature no server can compute);
   login-required sources are deferred (they need stored sessions).
2. For each source, fetches `/manga/` pages 1–2 — Madara orders
   that archive newest-first, and each list item carries its two
   latest chapters with dates.
3. Change detection costs zero requests for unchanged titles: the
   list item's latest chapter number + date is compared with
   `state.json`; only new or changed titles get a title-page fetch.
4. For new/changed titles it fetches the title page (metadata +
   chapter list) and chapter pages for new chapters only (newest
   first, capped). Chapters with no images still list — the site
   ranks those copies last.
5. Writes `data/catalog.json` (index) + `data/titles/<id>.json`
   (per-title detail) exactly per `data/catalog-schema.md`.
   **Per-source chapter lists are written as scraped; the site
   merges chapters across sources at read time** (`lib/catalog.php`).
6. Extends `data/image-hosts.json` with image CDNs seen (hosts that
   are not the source's own host or a subdomain of it — those are
   already allowed by the image proxy).

## Run it

```sh
python3 scraper/scrape.py                    # hourly run
python3 scraper/scrape.py --sources zinmanga # one source only
python3 scraper/scrape.py --dry-run          # scrape, write nothing
python3 scraper/scrape.py --max-seconds 300 --max-requests 400
```

## Budgets (private repo: 2,000 Actions min/month, 720 runs)

- Wall-clock cap 100 s, request cap 150 per run (flags override).
- Per-request timeout 10 s, 0.5 s delay between requests to the
  same host, real browser User-Agent.
- Per source per run: 2 list pages, 30 title pages, 40 chapter
  pages; per new title, images for the 5 newest new chapters;
  20 imageless chapters backfilled (oldest first).
- When a cap is hit the run stops gracefully and still writes a
  valid partial catalog. Coverage grows over successive hourly
  runs: steady state is a handful of requests per hour, because
  unchanged titles cost zero requests.
- Fair share: the remaining time/request budget is split evenly
  over the sources still to run, recomputed before each source, so
  the first source cannot starve the rest. A source that finishes
  early leaves its share to the ones after it.

## State (`state.json`)

- `sources.<id>.attempts`: `[timestamp, ok]` pairs for the last 30
  days. Reliability = success rate over that window (null until a
  run has been attempted). A source that fails its list fetch is
  skipped for that run; its old data is carried forward.
- `titles.<id>.sources.<source>`: last-seen chapter number/date,
  chapter count, chapter-list hash, imageless chapter numbers
  (oldest first, for backfill), `no_chapters` when a title page
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

## Adding sources

Edit `docs/manhwa-sources.json` and regenerate the registry
(`tools/build-sources-registry.py`). The scraper picks up any
source matching the v1 filter on the next run. A source that is
Cloudflare-challenged from datacenter IPs goes in `V1_EXCLUDE`
with the date and reason (see `dragontea`). Non-Madara themes need
an adapter + parser before they can join.

## Limitations (v1)

- Only Madara-theme sources. MangaThemesia, KeyoApp and the rest
  are not scraped yet.
- Only titles that appear on a source's `/manga/` pages 1–2 (the
  recently-updated window). Titles are carried forward once added,
  so the catalog grows over time, but a title that never surfaces
  in the window is never added.
- Chapter lists come from the server-rendered title page, or from
  the site's chapters JSON API when the page embeds one
  (zinmanga.net loads its list from `/api/comics/<slug>/chapters`,
  10/page, capped at 10 pages = 100 chapters per title). A site
  whose list is loaded some other way shows its titles with no
  chapters until an adapter for it exists; those titles are
  retried every run (`no_chapters` in state).
- Chapter images are fetched for new chapters only (newest first,
  capped) and backfilled a few per run. A long new title starts
  with images on its newest chapters only.
- No login-required sources, no comix, no JS rendering. Anything
  Cloudflare-challenged from a datacenter IP is out until a
  residential fetcher (PC bridge) exists.
