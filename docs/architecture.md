# manhwafreedev — Architecture

Date: 2026-11-23. Status: proposal. The site is currently live on
InfinityFree (manhwa.freedev.app) as a comix-only direct-fetch site
with no working fetcher. This document decides how it becomes a
multi-source site that stays free to host.

## 1. Goals and constraints

- Free hosting only. No paid tier anywhere in the path.
- Sources: free manhwa sites only (no paywalls). Two groups: free with
  no login, and free after login. See `manhwa-sources.md`.
- The rendered site never names a source site (no names or URLs in
  page text, footer, or errors).
- One title = one page, even when many sources carry it.
- The site must stay fast and must not get itself IP-banned.

## 2. The core problem

Fetching is the hard part, not presenting. The facts from testing
(Oct–Nov 2026):

- Most manhwa sites are Cloudflare-protected, JS-rendered, or both.
  Datacenter IPs (InfinityFree, Oracle, GitHub Actions) get challenged.
- Some sites need a per-request signature computed by their own
  obfuscated JavaScript (comix.to's `_` parameter). A plain server
  cannot compute it.
- Automated browsers on datacenter IPs do get clearance cookies
  minted, but follow-up requests are not honored (Turnstile lifecycle
  breaks under automation). Nine stacks failed.
- A real browser on a residential IP passes far more often — that is
  the only approach that has ever worked against these sites.

So any architecture that fetches live from the web server on every
request will fail for protected sources and will be slow and
ban-prone for the rest. The fetch layer and the serving layer must be
separate.

## 3. Candidate architectures

### A. Direct fetch from the web server (what the site does now)

InfinityFree PHP fetches the source on every request, with a file
cache.

- Pros: simplest; always fresh; no moving parts.
- Cons: datacenter egress IP gets challenged; no JS execution, so
  JS-rendered pages return empty shells; no signature computation;
  slow first loads; one shared IP for all users, so a rate-limit or
  ban hits everyone at once; InfinityFree CPU/hit quotas.
- Verdict: only viable for unprotected plain-HTML sites. Fails for
  everything that matters. Keep as a last-resort fallback, not the
  backbone.

### B. Client-side fetch (the user's browser fetches the sources)

The server serves only the UI shell; the visitor's browser fetches
each source directly. A real residential browser passes Cloudflare
far more often, and the server does almost no work.

- Pros: no server IP to ban; scales for free; real-browser
  fingerprinting.
- Cons: CORS. Browsers block cross-origin fetches unless the source
  sends permissive CORS headers, and manhwa sites do not. The
  workarounds all reintroduce a server (a CORS proxy), which puts
  you back at architecture A/F. Also, fetching from the user's
  browser exposes the source list in the network tab (not UI text,
  but visible).
- Verdict: elegant, killed by CORS. Not viable without a proxy.

### C. Scheduled scraper + static data (batch)

A scheduled job scrapes the sources and writes the results as static
JSON into the repo (or an object store). The website only ever reads
local files — it never touches a source at request time.

- Where the job runs, free: GitHub Actions (2,000 free minutes/month,
  scheduled runs) or the Oracle Always Free VM (already running the
  Post Scheduler backend; cron is free there).
- Pros: fetching and serving are decoupled, so the site cannot be
  blocked or slowed by a source; the scraper can use a real browser
  (Playwright/Camoufox) and can hold sessions/cookies; the site is
  fast (local JSON reads); no egress from InfinityFree at all;
  data is versioned in git; one scrape serves all users.
- Cons: data is stale between runs (hourly/daily); new chapters lag;
  scraping still faces Cloudflare (but you control the environment
  and can retry/rotate); catalogs are large (store metadata and
  chapter lists only, never images); scrapers break when a site
  redesigns and need per-site maintenance.
- Verdict: the most robust free backbone for browse/search/details.
  Recommended as the primary architecture.

### D. On-demand fetch service on the Oracle VM

The user's existing Oracle Always Free VM runs a small service
(Node + headless browser) that the site calls on demand. The site
already supports this (`CX_SERVICE_URL`).

- Pros: live data, no staleness; the site needs zero changes; the
  VM is free and always on; one central place to fix scrapers.
- Cons: 1 GB RAM shared with the Post Scheduler backend; a
  headless browser is heavy; the VM's datacenter IP faces the same
  Cloudflare wall (the Oct 2026 evidence says automated browsers
  there fail the Turnstile lifecycle); single point of failure.
- Verdict: good for unprotected sites and as a live fallback; not a
  bypass for protected ones.

### E. The user's PC as the fetcher (local bridge)

A small service on the user's Windows PC (residential IP, real
browser — the itbrowser test) fetches sources and pushes the data
to the site, e.g. through a Cloudflare Tunnel (free) or by
committing the scraped JSON to the repo.

- Pros: residential IP passes Cloudflare far more often than any
  datacenter option; a real browser.
- Cons: the PC must be on; home upload bandwidth; a public site
  depending on a home PC is fragile (dynamic IP, sleep, reboots);
  exposing a home service needs care (tunnel, auth).
- Verdict: the strongest fetcher per-request, but the weakest
  backbone. Use as a booster for protected sources, not the primary.

### F. Cloudflare Workers (serverless edge)

A Worker fetches upstream, parses with HTMLRewriter, caches in the
Cache API/KV, and returns JSON. Free: 100k requests/day.

- Pros: free, global, fast, nothing to maintain; caching cuts
  upstream hits.
- Cons: 10 ms CPU per request on the free tier (tight for parsing
  big pages); no headless browser, so no JS-rendered pages and no
  Turnstile; the Worker's egress IP is still a Cloudflare datacenter
  IP, so protected sites challenge it; a fan-out search (1 search =
  10 upstream fetches) burns the quota fast.
- Verdict: good as a smart cache/proxy for unprotected sites; not a
  bypass for protected ones.

### G. Hybrid (recommended)

Combine C (primary) + D (live fallback) + A (last resort) + E
(optional booster):

1. Scheduled fetcher (GitHub Actions, hourly) builds the catalog:
   per-title metadata, chapter lists, and a source index — written
   as JSON into the repo. This is what browse/search/details read.
2. The Oracle VM service stays as the live fallback for details
   and for sources the batch job has not covered yet.
3. Direct fetch from InfinityFree stays as the last resort for
   unprotected sites.
4. The PC bridge (itbrowser test, if it passes) becomes the fetcher
   for protected sources inside the batch job.

Why this wins: the site never depends on any single fetch path; the
common path (reading local JSON) cannot be blocked; the expensive
paths (browser scraping) happen once per hour, not once per visitor;
and every layer is free.

## 4. Free-tier limits that shape the design

| Service | Free allowance | What it is used for |
| --- | --- | --- |
| InfinityFree | 5 GB disk, 50k hits/day, PHP 8.4, no cron, no SSH | Serving the site only |
| Oracle Always Free VM | 1 GB RAM, 1 vCPU, always on | Post Scheduler backend + optional live fetch service |
| GitHub Actions | 2,000 min/month, scheduled runs | Hourly catalog fetcher |
| Cloudflare Workers | 100k req/day, 10 ms CPU/req | Optional cache/proxy layer |
| Cloudflare Tunnel | Free | Exposing the PC bridge safely, if used |

Rules that follow: never fetch on the request path if a cached copy
exists; never fan out to N sources per visitor request; keep the
catalog small (metadata only); respect rate limits per source.

## 5. Data model

- `catalog.json`: one entry per canonical title.
- `titles/<canonical-id>.json`: metadata + chapter list (merged,
  see section 6) + per-chapter image source references.
- `sources.json`: the source registry (id, language, access:
  free/login, theme, reliability score, last successful scrape).
  This file is internal; its contents are never rendered.
- Images are never stored. Chapter images are resolved at read
  time (section 7).

## 6. Same manhwa on multiple sources: what to show

### Identity: how we know it is the same title

Match on a canonical key: normalized title (lowercase, strip
punctuation/whitespace, fold common variants like "Season 1") +
author/artist + year when available. Alternative titles listed by a
source are folded into the same key. Matching is fuzzy and kept in
a mapping table so a wrong merge can be corrected by hand.

### What the user sees: one canonical title

Search results and the home page show ONE row per title, never one
row per source. Duplicates are noise, users think in titles, and
it keeps the source layer invisible (as required).

### Chapters: the union, not one source's list

The title page shows the union of chapters across all sources that
carry it, deduplicated by chapter number. If source A has chapters
1–50 and source B has 1–60, the page shows 1–60. This is the single
biggest UX win of multi-source: the most complete chapter list
anywhere, in one place.

### Which source supplies a given chapter: ranked per chapter

For each chapter, pick the supplying source by this ranking, in
order:

1. Availability — the source must actually have that chapter
   (with retrievable images). A source that lacks it cannot supply
   it, however good it is otherwise.
2. Freshness — prefer the source whose copy of that chapter was
   scraped most recently (stale copies rot: images get deleted,
   chapters get re-uploaded).
3. Reliability — the source's measured track record: scrape success
   rate and latency over the last 30 days. A source that is down
   half the time ranks below a slower but steady one.
4. Translation quality — proxy by source reputation tier (known
   scanlators with a history of clean releases rank above
   aggregators that machine-translate or mislabel). This is the
   only subjective input and it lives in the source registry, not
   in code, so it can be corrected without a deploy.
5. Language — prefer the site's UI language (English) unless the
   user has chosen otherwise.

Why this order: availability and freshness are objective and
per-chapter, so they dominate; reliability protects against
flapping sources; translation quality is a tiebreaker, not a
gate, because it cannot be measured automatically.

### If the user wants another copy: neutral mirrors

No source names may appear in the UI, so there is no
"read on Asura" button. Instead the reader offers a neutral
"try another copy" control that cycles to the next-ranked source
for that chapter, labelled only "Copy 1 / Copy 2". Same for the
title page: "also available in N copies" with no names.

### Conflicts

- Same chapter number, different content (split parts, different
  translations): keep both, labelled "Chapter 12" and
  "Chapter 12 (alt)". Do not silently drop one.
- Sources disagree on the latest chapter: the union takes the max;
  "updated X ago" comes from the freshest source.
- A source disappears or goes paywalled: it drops out of the
  ranking automatically (its data goes stale), and the title
  survives on the remaining sources. Nothing is ever deleted by
  hand in a panic; the registry marks it inactive.

## 7. Images

Chapter images are the hardest part and are never stored:

- The reader loads images through the site's own proxy
  (`img.php`, already built), which sets a browser User-Agent,
  follows redirects, and caches to disk. Image CDNs are usually
  less protected than the main site. Some CDNs hotlink-protect
  (403 unless the Referer is a site that uses them); the proxy
  sends a mapped Referer per image host from
  `data/image-referers.json` (host → origins to try, recorded by
  the scraper), falling back to no Referer.
- Chapter images are links only: the catalog stores no chapter
  images. The reader resolves them live from the chapter link
  via the source adapter when the catalog copy has none, and
  caches the result, so repeat opens are fast.
- If the proxy is blocked, fall back to loading the image directly
  in the visitor's browser with `referrerpolicy="no-referrer"`.
  Many hotlink protections check the Referer header; sending none
  bypasses them, and a real browser passes image-CDN checks more
  often than a server does.
- If both fail, the page shows a per-image retry ("try another
  copy"), not a broken page.

## 8. Caching and freshness

- Catalog JSON: rebuilt hourly by the scraper; the site reads it
  from disk with a 15-minute in-memory/file TTL as a guard.
- Title/chapter data: same as the catalog (hourly).
- Images: cached on disk for 7 days, evicted oldest-first when the
  cache passes a size cap.
- Staleness is shown, not hidden: "updated X ago" on every title
  page, from the freshest source that supplied it.

## 9. Failure modes

- One source down: it degrades silently (its chapters stay from
  the last good scrape until they go stale, then drop out of the
  ranking). The title survives on the other sources.
- All sources for a title down: the title page shows its last
  known chapter list with a "last updated X ago" note, and the
  reader reports per-chapter failure honestly.
- Everything down: the home page shows the same honest error box
  it shows today — never an empty list pretending there are no
  results.

## 10. What this means for the rework

- The site gains a source registry and a per-source adapter
  interface; comix becomes one adapter among many, not the site.
- The client gains a "catalog mode": when catalog JSON exists, all
  reads come from it; the service/direct modes become fallbacks.
- All rendered text drops source names (site name becomes
  "manhwafreedev"; errors become generic).
- New files: `lib/sources.php` (registry + ranking),
  `lib/catalog.php` (catalog reads), `data/` (catalog JSON, not
  committed by hand — written by the scraper).
- The scraper itself lives in the repo under `scraper/` (or in a
  separate workflow repo) and is a separate piece of work from the
  site rework.

## 11. Open decisions (for the user)

- Refresh cadence: hourly is the default; daily is cheaper on
  Actions minutes. Hourly is recommended while the catalog is
  small.
- Login-required sources: include them in the catalog later, once
  the no-login set works. They need stored sessions, which is a
  separate design (credentials in the scraper's secrets, never in
  the site).
- The PC bridge: only if the itbrowser test passes. If it fails,
  protected sources stay out of the catalog until another fetcher
  exists.
