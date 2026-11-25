# Catalog schema

The catalog is built by the scheduled fetcher (see
`docs/architecture.md`, section 3C) and read by `lib/catalog.php`.
The site never fetches on the request path when a catalog exists.

## data/catalog.json

Index of every canonical title. One entry per title, never one per
source.

```json
{
  "version": 1,
  "generated_at": 1763980800,
  "titles": [
    {
      "id": "solo-leveling",
      "title": "Solo Leveling",
      "cover": "https://.../cover.jpg",
      "status": "Ongoing",
      "genres": ["Action", "Fantasy"],
      "updated": 1763980800,
      "copies": 3,
      "adult": false
    }
  ]
}
```

- `id`: canonical slug (lowercase letters, digits, hyphens). The
  same title on several sources shares one id.
- `updated`: unix timestamp of the freshest copy the fetcher saw.
  Rendered as "updated X ago".
- `copies`: how many sources carry this title. Rendered only as a
  count — source names are never shown.
- `adult`: true for adult-only titles (kept, tagged, never filtered
  out).

## data/titles/<id>.json

Full detail for one title: metadata plus the per-source chapter
lists the merge reads from. The site merges chapters at read time;
the fetcher must not merge.

```json
{
  "id": "solo-leveling",
  "title": "Solo Leveling",
  "alt_titles": ["Na Honjaman Level-Up"],
  "author": "Chugong",
  "cover": "https://.../cover.jpg",
  "synopsis": "...",
  "status": "Ongoing",
  "genres": ["Action"],
  "adult": false,
  "sources": [
    {
      "source": "asura-scans",
      "lang": "en",
      "fetched_at": 1763980800,
      "reliability": 0.95,
      "quality": "high",
      "chapters": [
        {
          "number": "12",
          "title": "Chapter 12",
          "id": "ch-12",
          "date": "2026-11-23",
          "images": ["https://.../1.jpg", "https://.../2.jpg"],
          "fetched_at": 1763980800
        }
      ]
    }
  ]
}
```

- `source`: registry id from `lib/sources.php` (never rendered).
- `reliability`: 0–1 measured fetch success rate over 30 days, or
  null when unknown. `quality`: `high`, `standard`, `low`, or null.
  Both feed the per-chapter ranking; the registry values are the
  fallback when a source entry omits them.
- `chapters[].images`: full image URLs for that chapter, in reading
  order. A chapter with no usable images still lists (its copies
  rank last), so the chapter list stays complete.
- `chapters[].number`: the chapter number as printed ("12", "12.5").
  The reader normalizes it ("007" → "7", "12.50" → "12.5").

### Merge rules (implemented in lib/catalog.php)

- Chapters group by normalized number across all sources; the
  union is shown, deduplicated by number.
- Within a number, copies rank by: has images, then freshest
  `fetched_at`, then reliability, then quality, then language
  (English first). Copies are labelled "Copy 1", "Copy 2" — never
  with source names.
- Same number, different chapter title means different content:
  the smaller group becomes a separate "(alt)" row instead of
  being dropped.

## data/source-patterns.json

Per-source fetch patterns, seeded from the keiyoushi
extensions-source repo and updated by the fetcher as runs verify
them. Both the fetcher (chapter lists) and the reader (live
chapter images) read it: the recorded mechanism is tried first,
then the others, so a stale record never breaks a fetch. Deployed
to the live site with the catalog.

```json
{
  "version": 1,
  "generated_at": 1764028800,
  "sources": {
    "zinmanga-net": {
      "chapters": "api",
      "chapters_api": "https://www.zinmanga.net/api/comics/{slug}/chapters?per_page=100&order=desc",
      "images": "cdn",
      "image_hosts": ["cdn.zinmanga1.com"],
      "image_referer": "https://www.zinmanga.net/",
      "theme": "madara",
      "extension": "src/en/zinmanganet/src/eu/kanade/tachiyomi/extension/en/zinmanganet/ZinmangaNet.kt",
      "notes": "Chapters via JSON API. Chapter images are CDN URLs in <img data-src> tags; the CDN 403s when the Referer is a chapter page.",
      "detected_at": 1764028800
    }
  }
}
```

- `chapters`: how the chapter list is obtained — `api`,
  `manga-page`, `manga-ajax`, `manga-ajax-paginated`,
  `admin-ajax`, `spa`.
- `images`: how chapter images are obtained — `api`, `cdn`,
  `html`, `spa`.
- `chapters_api` / `images_api`: URL templates with `{slug}` and
  `{chapter}` placeholders, when the source has one.
- `image_hosts`: CDN hosts seen for this source (covers and
  chapter images); `image_referer` is the Referer the image proxy
  must send for them.
- `extension`: the keiyoushi extension file the pattern was read
  from — the reference for endpoints, selectors, headers and
  quirks.

## data/image-hosts.json

Allowlist for the image proxy (`img.php`), in addition to every
registered source's own site host. The fetcher extends it with the
image CDNs it sees (a source's static host, for example). Seeded
with the first adapter's known image host.

```json
{ "hosts": ["static.comix.to"] }
```

## data/image-referers.json

Referer map for the image proxy. Some image CDNs hotlink-protect:
they answer 403 unless the request carries a Referer from a site
that uses them (verified: `cdn.zinmanga1.com` answers 403 with no
Referer or with `https://mangazin.org/`, and 200 with
`https://www.zinmanga.net/`). The fetcher records, per image host,
the origins of the sources whose pages link images on it; `img.php`
tries them in order, then no Referer, until one returns a real
image. Existing entries keep their order; new origins append.

```json
{
  "cdn.zinmanga1.com": [
    "https://www.zinmanga.net/",
    "https://mangazin.org/"
  ]
}
```
