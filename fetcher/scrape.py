#!/usr/bin/env python3
"""Hourly catalog fetcher for manhwafreedev (stdlib only).

Links-only model: the scraper collects title metadata (title, cover
link, synopsis, status, genres) and the chapter list (number, title,
link, id, date) — it never fetches chapter pages and never stores
image data, only links. Chapter images are resolved live by the
reader (lib/client.php) from the chapter link via the source
adapter, using the file cache so repeat opens are fast; a chapter
that happens to already carry image URLs in the catalog uses those
first. The same applies to covers: the site proxies them through
img.php (which sends a mapped Referer when a CDN needs one) and the
browser falls back to loading them directly.

Reads the source set from docs/manhwa-sources.json, scrapes the
Madara latest list of each source, fetches title pages for titles
that are new or changed since the last run, and writes the catalog
the site reads: data/catalog.json (index) + data/titles/<id>.json
(per-title detail) exactly per data/catalog-schema.md. Per-source
chapter lists are written as scraped; the site merges chapters
across sources at read time (lib/catalog.php) — this scraper never
merges.

Seed mode (--seed): crawls each source's whole /manga/ directory
(paginated, newest-first) instead of only the latest-updates
window, so the catalog reaches hundreds of titles instead of only
the recently-updated ones. A per-source cursor in state.json
remembers the next page, so a seed run resumes where the last one
stopped and a finished pass starts over.

Efficiency: the list items carry the two latest chapters with dates,
so a title whose latest chapter is unchanged costs zero requests
(the stored copy is carried forward). New/changed titles cost one
title-page fetch. The whole run is capped (wall clock + request
count) and stops gracefully, still writing a valid partial catalog.

State lives in fetcher/state.json: per-source reliability history
(30-day success rate), per-title last-seen chapter info, a
(source, slug) -> canonical-id map so ids stay stable across runs,
and the seed cursor per source.
"""
import argparse
import hashlib
import json
import re
import sys
import time
from pathlib import Path
from urllib.parse import urlparse

sys.path.insert(0, str(Path(__file__).resolve().parent))

from madara import MadaraSite
from normalize import (
    canonical_title_slug,
    is_adult,
    name_similarity,
    normalize_number,
    normalize_status,
    parse_date,
    slugify,
)

ROOT = Path(__file__).resolve().parent.parent

# --- V1 source set ---
# Only confirmed, free, no-login, English, Madara-theme sources.
# comix is excluded (its API needs a signature no server can
# compute); login-required sources are deferred (need sessions);
# non-Madara themes have no adapter yet.
V1_FILTER = {
    "confidence": "confirmed",
    "access": "free",
    "lang": "en",
    "theme": "madara",
}
# Proven unreachable from datacenter IPs (Cloudflare challenge or
# plain 403). Revisit if a residential fetcher (PC bridge) exists.
V1_EXCLUDE = {
    "dragontea": "Cloudflare-challenged from datacenter IPs (2026-11-23)",
    "manhuaus": "Cloudflare-challenged from datacenter IPs (2026-11-24)",
    "aqua-manga": "HTTP 403 from datacenter IPs (2026-11-24)",
    "manhua-zonghe": "HTTP 403 from datacenter IPs (2026-11-24)",
    "manhwa68": "HTTP 403 from datacenter IPs (2026-11-24)",
    "toongod": "HTTP 403 from datacenter IPs (2026-11-24)",
    "gingertoon": "/manga/ archive empty — 'There is no Manga yet' (2026-11-24)",
    "manhwa-toon": "/manga/ archive has no parseable list items (2026-11-24)",
    "manhwa18-today": "Cloudflare-challenged from datacenter IPs (2026-11-24; VPN exit 144.24.149.59 also 403)",
    "webtoonxyz": "HTTP 403 from datacenter IPs (2026-11-24; VPN exit also 403)",
    "aunt-manhwa": "SSL cert hostname mismatch (2026-11-24; unreachable via VPN too)",
    "manhwamanhua": "Connection reset from datacenter IPs (2026-11-24; unreachable via VPN too)",
}

# --- Budgets (private repo: 2,000 Actions min/month, 720 runs) ---
LIST_PAGES = 2            # /manga/ pages per source per run
SEED_PAGES = 30           # /manga/ pages per source per seed run
REQUEST_TIMEOUT = 10      # seconds per request
REQUEST_DELAY = 0.5       # seconds between requests to the same host
MAX_SECONDS = 300         # wall-clock cap for the whole run
MAX_REQUESTS = 500        # request cap for the whole run
MAX_TITLE_PAGES_PER_SOURCE = 30
HOLLOW_RETRY_PER_SOURCE = 10  # 0-chapter titles retried per run

STATE_DAYS = 30           # reliability window


# --- Registry ids (same scheme as tools/build-sources-registry.py) ---

def registry_ids(items):
    seen = {}
    out = {}
    for item in items:
        base = slugify(item["name"])
        n = seen.get(base, 0) + 1
        seen[base] = n
        source_id = base if n == 1 else f"{base}-{n}"
        out[source_id] = item
    return out


def load_v1_sources(root, only=None):
    data = json.loads((root / "docs" / "manhwa-sources.json").read_text())
    by_id = registry_ids(data)
    chosen = []
    for source_id, item in by_id.items():
        if only is not None:
            if source_id in only:
                chosen.append((source_id, item))
            continue
        if source_id == "comix":
            continue
        if source_id in V1_EXCLUDE:
            continue
        if all(item.get(k, "") == v for k, v in V1_FILTER.items()):
            chosen.append((source_id, item))
    return chosen


# --- Small JSON helpers ---

def load_json(path, default):
    try:
        return json.loads(Path(path).read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return default


def write_json_atomic(path, data):
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_suffix(path.suffix + ".tmp")
    tmp.write_text(
        json.dumps(data, ensure_ascii=False, indent=1) + "\n",
        encoding="utf-8",
    )
    tmp.replace(path)


def write_json_if_changed(path, data):
    text = json.dumps(data, ensure_ascii=False, indent=1) + "\n"
    path = Path(path)
    try:
        if path.read_text(encoding="utf-8") == text:
            return False
    except OSError:
        pass
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_suffix(path.suffix + ".tmp")
    tmp.write_text(text, encoding="utf-8")
    tmp.replace(path)
    return True


def chapter_hash(chapters):
    blob = "\n".join(
        f"{c.get('number', '')}|{c.get('id', '')}" for c in chapters
    )
    return hashlib.sha1(blob.encode("utf-8")).hexdigest()[:12]


# --- State ---

def load_state(path):
    state = load_json(path, None)
    if not isinstance(state, dict) or "sources" not in state:
        return {"version": 1, "sources": {}, "titles": {}, "map": {}}
    state.setdefault("titles", {})
    state.setdefault("map", {})
    return state


def prune_attempts(state, source_id, now):
    entry = state["sources"].setdefault(source_id, {})
    cutoff = now - STATE_DAYS * 86400
    attempts = [
        [ts, ok] for ts, ok in entry.get("attempts", [])
        if ts >= cutoff
    ]
    entry["attempts"] = attempts[-720:]
    return entry


def record_attempt(state, source_id, ok, now):
    entry = prune_attempts(state, source_id, now)
    entry["attempts"].append([now, bool(ok)])
    entry["last_run"] = now
    if ok:
        entry["last_ok"] = now
        entry["consecutive_failures"] = 0
    else:
        entry["consecutive_failures"] = entry.get("consecutive_failures", 0) + 1


def reliability(state, source_id):
    entry = state["sources"].get(source_id, {})
    attempts = entry.get("attempts", [])
    if not attempts:
        return None
    good = sum(1 for _, ok in attempts if ok)
    return round(good / len(attempts), 3)


class Budget:
    """Run-wide caps. When exceeded, the run stops gracefully and
    still writes a valid (partial) catalog."""

    def __init__(self, max_seconds, max_requests):
        self.started = time.monotonic()
        self.max_seconds = max_seconds
        self.max_requests = max_requests
        self.requests = 0
        self.stopped = False
        # Per-source fair share: a segment caps what the current
        # source may spend, so the first source cannot starve the
        # rest. begin_segment() (re)sets it; left() enforces both
        # the global cap and the segment cap.
        self.seg_deadline = None
        self.seg_request_cap = None

    def begin_segment(self, seconds, requests):
        self.seg_deadline = time.monotonic() + max(0.0, seconds)
        self.seg_request_cap = self.requests + max(0, requests)

    def remaining(self):
        secs = self.max_seconds - (time.monotonic() - self.started)
        reqs = self.max_requests - self.requests
        return max(0.0, secs), max(0, reqs)

    def left(self):
        return self.global_left() and self._segment_left()

    def global_left(self):
        """Global caps only — used by the runner before starting a
        source, when the previous source's segment is stale."""
        return (
            not self.stopped
            and time.monotonic() - self.started < self.max_seconds
            and self.requests < self.max_requests
        )

    def _segment_left(self):
        if (
            self.seg_deadline is not None
            and time.monotonic() >= self.seg_deadline
        ):
            return False
        if (
            self.seg_request_cap is not None
            and self.requests >= self.seg_request_cap
        ):
            return False
        return True

    def note_request(self):
        self.requests += 1


class Runner:
    def __init__(self, root, args):
        self.root = Path(root)
        self.args = args
        self.now = int(time.time())
        self.budget = Budget(args.max_seconds, args.max_requests)
        self.state = load_state(self.root / "fetcher" / "state.json")
        self.overrides = load_json(
            self.root / "fetcher" / "overrides.json", {"aliases": {}}
        )
        self.aliases = self.overrides.get("aliases", {})
        # Per-source fetch patterns (data/source-patterns.json):
        # which mechanism supplies the chapter list and the images,
        # seeded from the keiyoushi extension for each source and
        # updated as runs verify them. The choice is stable across
        # runs — a recorded mode is tried first, and only replaced
        # when it stops working.
        self.patterns = load_json(
            self.root / "data" / "source-patterns.json", {}
        )
        if not isinstance(self.patterns, dict):
            self.patterns = {}
        self.patterns.setdefault("version", 1)
        self.patterns.setdefault("sources", {})
        self.patterns_dirty = False
        self.stats = {
            "sources_ok": [], "sources_failed": {},
            "titles_seen": 0, "titles_new": 0, "titles_updated": 0,
            "titles_unchanged": 0, "chapters_new": 0,
            "title_pages": 0, "requests": 0,
        }
        self.image_hosts = set()
        self.image_referers = {}  # image host -> [origins to try]
        # Working catalog: index entries carried forward + updated.
        self.index_entries = {}
        self.title_files = {}   # cid -> full title dict (loaded lazily)
        self.touched = set()    # cids whose title file must be written
        self.slug_authors = {}  # canonical slug -> set of author slugs
        self.registry_adult = {}  # source id -> registry adult flag
        self._load_previous_catalog()

    # --- Previous catalog (base for the merge) ---

    def _load_previous_catalog(self):
        index = load_json(self.root / "data" / "catalog.json", None)
        if not isinstance(index, dict):
            return
        for entry in index.get("titles", []) or []:
            if not isinstance(entry, dict) or "id" not in entry:
                continue
            cid = entry["id"]
            title_file = self.root / "data" / "titles" / f"{cid}.json"
            if not title_file.is_file():
                # Detail file lost: drop the title so it re-scrapes.
                self._drop_title(cid)
                continue
            self.index_entries[cid] = entry

    def _drop_title(self, cid):
        self.index_entries.pop(cid, None)
        self.title_files.pop(cid, None)
        self.state["titles"].pop(cid, None)
        for key in [k for k, v in self.state.get("map", {}).items()
                    if v == cid]:
            del self.state["map"][key]

    def prev_title(self, cid):
        if cid in self.title_files:
            return self.title_files[cid]
        data = load_json(self.root / "data" / "titles" / f"{cid}.json", None)
        if isinstance(data, dict) and data.get("id") == cid:
            self.title_files[cid] = data
            return data
        return None

    # --- Canonical ids ---

    def resolve_id(self, title, author=""):
        slug = canonical_title_slug(title)
        author_slug = slugify(author) if author else ""
        alias = self.aliases.get(f"{slug}|{author_slug}") or self.aliases.get(slug)
        if alias:
            return alias
        if not author_slug:
            return slug
        authors = self.slug_authors.setdefault(slug, set())
        if not authors:
            authors.add(author_slug)
            return slug
        if author_slug in authors:
            return slug
        authors.add(author_slug)
        return f"{slug}-{author_slug}"

    def _note_site_requests(self, site, before):
        """Count what a site call actually spent. A title-page fetch
        can pull extra chapters-API pages, so count the site's own
        counter rather than assuming one request. At least one."""
        spent = site.requests - before
        for _ in range(max(1, spent)):
            self.budget.note_request()

    # --- Per-source run ---

    def run_source(self, source_id, item):
        pattern = (self.patterns.get("sources", {}) or {}).get(source_id, {})
        site = MadaraSite(
            source_id,
            item["site"].rstrip("/"),
            delay=REQUEST_DELAY,
            list_path=pattern.get("list_path", "manga"),
        )
        # Seed mode crawls the whole /manga/ directory from the
        # stored cursor; hourly mode only reads the latest window.
        seed = bool(getattr(self.args, "seed", False))
        if seed:
            start = int(
                (self.state.get("sources", {}).get(source_id, {}) or {})
                .get("seed_page", 1) or 1
            )
            pages = range(start, start + self.args.seed_pages)
        else:
            pages = range(1, self.args.list_pages + 1)
        items = []
        list_ok = False
        seed_exhausted = False
        next_page = None
        first_page = True
        for page in pages:
            if not self.budget.left():
                next_page = page
                break
            before = site.requests
            ok, page_items, kind, message = site.list_page(page)
            self._note_site_requests(site, before)
            if not ok:
                if first_page:
                    record_attempt(self.state, source_id, False, self.now)
                    self.stats["sources_failed"][source_id] = (
                        f"{kind}: {message}"
                    )
                    return
                next_page = page
                break  # later page failed; keep earlier items
            first_page = False
            list_ok = True
            seen = {it["slug"] for it in items}
            for it in page_items:
                if it["slug"] not in seen:
                    # Page tag: the seed cursor resumes at the first
                    # page with unprocessed items, so a run that
                    # stops mid-list never drops titles.
                    it["_page"] = page
                    items.append(it)
            if len(page_items) == 0:
                seed_exhausted = True
                next_page = None
                break
            next_page = page + 1
        if not list_ok:
            record_attempt(self.state, source_id, False, self.now)
            self.stats["sources_failed"][source_id] = "empty list"
            return
        record_attempt(self.state, source_id, True, self.now)
        self.stats["sources_ok"].append(source_id)
        seen_slugs = set()
        seed_resume_page = None
        for list_item in items:
            if not self.budget.left():
                # Resume at the first page with unprocessed items.
                seed_resume_page = list_item.get("_page")
                # Segment end is not global exhaustion: the next
                # source still gets its own share.
                if not self.budget.global_left():
                    self.budget.stopped = True
                break
            self.stats["titles_seen"] += 1
            seen_slugs.add(list_item["slug"])
            self.process_title(site, source_id, item, list_item)
        # Retry hollow titles (0 chapters) that did not surface in
        # the list window: their chapter lists may exist now (e.g.
        # the chapters API fallback was added after they were first
        # seen). Bounded per source, budget permitting.
        if self.budget.left():
            hollow = []
            for tstate in self.state.get("titles", {}).values():
                entry = (tstate.get("sources", {}) or {}).get(source_id)
                if entry and entry.get("no_chapters") and entry.get("slug"):
                    if entry["slug"] not in seen_slugs:
                        hollow.append(entry["slug"])
            for slug in hollow[:HOLLOW_RETRY_PER_SOURCE]:
                if not self.budget.left():
                    break
                self.process_title(site, source_id, item, {
                    "slug": slug, "title": "", "cover": "",
                    "latest": [],
                })
        # Seed cursor: resume where this run stopped; a finished
        # pass (empty directory page) restarts from page 1.
        if seed:
            entry = self.state["sources"].setdefault(source_id, {})
            if seed_exhausted:
                entry["seed_page"] = 1
                entry["seed_completed_at"] = self.now
            elif seed_resume_page is not None:
                entry["seed_page"] = seed_resume_page
            elif next_page is not None:
                entry["seed_page"] = next_page

    def process_title(self, site, source_id, source_item, list_item):
        """Returns "unchanged", "title_page", "title_page+chapters",
        or "failed". Change detection uses only the list item, so an
        unchanged title costs zero requests."""
        key = f"{source_id}|{list_item['slug']}"
        cid = self.state.get("map", {}).get(key)
        latest = list_item.get("latest") or []
        latest_num = latest[0].get("number", "") if latest else ""
        latest_date = (
            parse_date(latest[0].get("date", "")) if latest else ""
        )
        if cid:
            entry = self.state.get("titles", {}).get(cid, {}).get(
                "sources", {}
            ).get(source_id)
            if entry and not entry.get("no_chapters"):
                if (
                    entry.get("last_chapter") == latest_num
                    and entry.get("last_chapter_date") == latest_date
                ):
                    self.stats["titles_unchanged"] += 1
                    return "unchanged"
        if not self.budget.left():
            return "failed"
        self.stats["title_pages"] += 1
        before = site.requests
        mode = (self.patterns.get("sources", {}) or {}).get(
            source_id, {}
        ).get("chapters", "")
        ok, data, kind, message = site.detail(list_item["slug"], mode)
        self._note_site_requests(site, before)
        if not ok:
            return "failed"
        self._record_pattern(site, source_id, source_item, data)
        if cid is None:
            cid = self.resolve_id(data["title"], data.get("author", ""))
            self.state.setdefault("map", {})[key] = cid
            self.stats["titles_new"] += 1
        else:
            self.stats["titles_updated"] += 1
        return self.update_title(
            site, source_id, source_item, cid, data, list_item
        )

    def update_title(self, site, source_id, source_item, cid, data, list_item):
        now = self.now
        prev = self.prev_title(cid) or {}
        prev_sources = {
            s.get("source"): s
            for s in prev.get("sources", []) or []
            if isinstance(s, dict) and s.get("source")
        }
        old_entry = prev_sources.get(source_id)
        old_by_id = {}
        if old_entry:
            for ch in old_entry.get("chapters", []) or []:
                if ch.get("id"):
                    old_by_id[ch["id"]] = ch
        chapters = []
        new_numbers = []
        for ch in data.get("chapters", []) or []:
            num = normalize_number(str(ch.get("number", "")))
            if not num:
                continue
            record = {
                "number": num,
                "title": str(ch.get("title", "")),
                "id": str(ch.get("id", "")),
                "date": parse_date(str(ch.get("date", ""))),
                "images": [],
                "scraped_at": now,
            }
            if record["id"] in old_by_id:
                old = old_by_id[record["id"]]
                record["images"] = list(old.get("images", []) or [])
                record["scraped_at"] = int(old.get("scraped_at", now))
            else:
                new_numbers.append(num)
            chapters.append(record)
        # Links-only: new chapters are listed with no images — the
        # reader resolves images live from the chapter link. Any
        # images already stored (from earlier runs) are carried
        # forward above and used first.
        self.stats["chapters_new"] += len(new_numbers)
        if not chapters:
            # No server-rendered chapter list (many Madara sites
            # load it via AJAX): seed from the list item's latest
            # chapters, so the title is not hollow. Those are real
            # chapter links the reader can open, and they accumulate
            # across runs as new chapters appear. The title still
            # counts as no_chapters, so the title page is retried.
            for ch in list_item.get("latest", []) or []:
                num = normalize_number(str(ch.get("number", "")))
                if not num or not ch.get("id"):
                    continue
                record = {
                    "number": num,
                    "title": str(ch.get("title", "")),
                    "id": str(ch.get("id", "")),
                    "date": parse_date(str(ch.get("date", ""))),
                    "images": [],
                    "scraped_at": now,
                }
                if record["id"] in old_by_id:
                    old = old_by_id[record["id"]]
                    record["images"] = list(old.get("images", []) or [])
                    record["scraped_at"] = int(old.get("scraped_at", now))
                else:
                    new_numbers.append(num)
                chapters.append(record)
            self.stats["chapters_new"] += len(chapters)
        if data.get("cover"):
            self.note_image_host(data["cover"], source_item["site"])
        if list_item.get("cover"):
            self.note_image_host(list_item["cover"], source_item["site"])
        # Union with previously stored chapters the new list no
        # longer shows (Madara lists can be truncated); new wins.
        # Deduped by chapter URL, not number: one source can list
        # two parts under the same number, and the site shows those
        # as separate "(alt)" rows.
        by_id = {c["id"]: c for c in chapters if c.get("id")}
        if old_entry:
            for old in old_entry.get("chapters", []) or []:
                if old.get("id") and old["id"] not in by_id:
                    by_id[old["id"]] = {
                        "number": normalize_number(str(old.get("number", ""))),
                        "title": str(old.get("title", "")),
                        "id": str(old.get("id", "")),
                        "date": str(old.get("date", "")),
                        "images": list(old.get("images", []) or []),
                        "scraped_at": int(old.get("scraped_at", now)),
                    }
        chapters = sorted(
            by_id.values(), key=lambda c: _chapter_sort_key(c["number"]),
            reverse=True,
        )
        source_entry = {
            "source": source_id,
            "lang": source_item.get("lang", "en"),
            "scraped_at": now,
            "reliability": reliability(self.state, source_id),
            "quality": None,
            "chapters": chapters,
        }
        no_chapters = not data.get("chapters")
        # Merge metadata: the fresh fetch wins when it has a value,
        # otherwise keep what was stored. Hollow copies (a source
        # entry with no chapters, e.g. from before the chapters API
        # fallback existed) are dropped — but a title always keeps
        # at least one source entry, so a title whose only source
        # yielded no chapters stays attributed to it.
        sources = {
            sid: entry for sid, entry in prev_sources.items()
            if entry.get("chapters")
        }
        if source_entry["chapters"] or not sources:
            sources[source_id] = source_entry
        title = self.merge_title(cid, prev, data, sources)
        self.title_files[cid] = title
        self.touched.add(cid)
        # The index is built from index_entries, so a new title must
        # land here too (write_outputs rebuilds the entry from the
        # title file, but only iterates cids present in this map).
        self.index_entries[cid] = {
            "id": cid,
            "title": title["title"],
            "cover": title.get("cover", ""),
            "status": title.get("status", ""),
            "genres": list(title.get("genres", [])),
            "updated": max(
                (int(s.get("scraped_at", 0)) for s in title["sources"]),
                default=0,
            ),
            "copies": len(title["sources"]),
            "adult": bool(title.get("adult", False)),
        }
        # State: last-seen chapter info.
        latest = data.get("chapters") or []
        entry_state = {
            "slug": list_item["slug"],
            "last_chapter": latest[0].get("number", "") if latest else "",
            "last_chapter_date": (
                parse_date(latest[0].get("date", "")) if latest else ""
            ),
            "chapter_count": len(chapters),
            "chapter_hash": chapter_hash(chapters),
            "last_seen": now,
            "no_chapters": bool(no_chapters),
            "author": data.get("author", ""),
        }
        self.state.setdefault("titles", {}).setdefault(cid, {}).setdefault(
            "sources", {}
        )[source_id] = entry_state
        return "title_page"

    def merge_title(self, cid, prev, data, sources):
        title = prev.get("title") if prev else ""
        cover = prev.get("cover", "") if prev else ""
        synopsis = prev.get("synopsis", "") if prev else ""
        status = prev.get("status", "") if prev else ""
        genres = list(prev.get("genres", [])) if prev else []
        alt_titles = list(prev.get("alt_titles", [])) if prev else []
        author = prev.get("author", "") if prev else ""
        if data.get("author"):
            author = data["author"]
        if data.get("title"):
            title = data["title"]
        if data.get("cover"):
            cover = data["cover"]
        if data.get("synopsis"):
            synopsis = data["synopsis"]
        if data.get("status"):
            status = normalize_status(data["status"])
        if data.get("genres"):
            genres = data["genres"][:10]
        if data.get("alt_titles"):
            merged_alts = list(prev.get("alt_titles", [])) + list(
                data["alt_titles"]
            )
            alt_titles = list(dict.fromkeys(
                [a for a in merged_alts if a and a != title]
            ))[:10]
        flags = [self.registry_adult.get(sid, False) for sid in sources]
        return {
            "id": cid,
            "title": title,
            "alt_titles": alt_titles,
            "author": author,
            "cover": cover,
            "synopsis": synopsis,
            "status": status,
            "genres": genres,
            "adult": is_adult(genres, flags),
            "sources": [sources[k] for k in sorted(sources)],
        }

    def _record_pattern(self, site, source_id, source_item, data):
        """Update the per-source fetch pattern from what this
        detail() actually used: the chapter mode that supplied the
        list, the chapters API URL when one was used, and the image
        hosts seen (covers). A recorded mode is only replaced when
        a run proves a different one works."""
        entry = self.patterns["sources"].setdefault(source_id, {})
        mode = getattr(site, "last_chapter_mode", "")
        if mode and entry.get("chapters") != mode:
            entry["chapters"] = mode
            entry["detected_at"] = self.now
            self.patterns_dirty = True
        api_url = getattr(site, "last_chapters_api", "")
        if api_url:
            template = re.sub(
                r"(/api/comics/)[^/]+(/chapters.*)",
                r"\1{slug}\2", api_url,
            )
            if entry.get("chapters_api") != template:
                entry["chapters_api"] = template
                self.patterns_dirty = True
        for url in (data.get("cover") or "",):
            if not url:
                continue
            try:
                host = urlparse(url).hostname or ""
            except ValueError:
                host = ""
            if host:
                hosts = entry.setdefault("image_hosts", [])
                if host not in hosts:
                    hosts.append(host)
                    self.patterns_dirty = True
        if "theme" not in entry:
            entry["theme"] = source_item.get("theme", "")
        if "extension" not in entry:
            entry["extension"] = ""

    def note_image_host(self, url, source_site):
        try:
            host = urlparse(url).hostname or ""
        except ValueError:
            return
        if not host:
            return
        source_host = urlparse(source_site).hostname or ""
        if host == source_host or (
            source_host and host.endswith("." + source_host)
        ):
            return
        self.image_hosts.add(host.lower())
        # Referer candidates for this CDN: origins of sources whose
        # pages link images on it. img.php tries them in order when
        # the host hotlink-protects (403 without the right Referer).
        origin = source_site.rstrip("/") + "/"
        known = self.image_referers.setdefault(host.lower(), [])
        if origin not in known:
            known.append(origin)

    def run(self):
        sources = load_v1_sources(self.root, self.args.sources)
        self.registry_adult = {
            sid: bool(item.get("adult", False)) for sid, item in sources
        }
        print(f"sources: {', '.join(sid for sid, _ in sources) or '(none)'}")
        for i, (source_id, item) in enumerate(sources):
            if not self.budget.global_left():
                self.budget.stopped = True
                print(f"[{source_id}] budget exhausted, stopping")
                break
            # Fair share of what is left, split over the sources
            # still to run (recomputed each time, so a source that
            # finishes early leaves its share to the rest).
            secs, reqs = self.budget.remaining()
            left = len(sources) - i
            self.budget.begin_segment(secs / left, reqs // left)
            print(f"[{source_id}] {item['site']} ...")
            self.run_source(source_id, item)
        self.merge_duplicates()
        self.write_outputs()
        summary = {
            "sources_ok": self.stats["sources_ok"],
            "sources_failed": self.stats["sources_failed"],
            "titles_seen": self.stats["titles_seen"],
            "titles_new": self.stats["titles_new"],
            "titles_updated": self.stats["titles_updated"],
            "titles_unchanged": self.stats["titles_unchanged"],
            "titles_merged": self.stats.get("titles_merged", 0),
            "titles_total": len(self.index_entries),
            "chapters_total": sum(
                len(s.get("chapters", []) or [])
                for t in self.title_files.values()
                for s in t.get("sources", []) or []
            ) + self._carried_chapter_count(),
            "title_pages": self.stats["title_pages"],
            "requests": self.budget.requests,
            "seconds": round(time.monotonic() - self.budget.started, 1),
            "stopped_early": self.budget.stopped,
        }
        print("SUMMARY " + json.dumps(summary))
        return 0

    def _carried_chapter_count(self):
        """Chapters in title files that were not touched this run
        (their files were carried forward untouched)."""
        total = 0
        for cid in self.index_entries:
            if cid in self.title_files:
                continue
            data = self.prev_title(cid)
            if not data:
                continue
            for src in data.get("sources", []) or []:
                total += len(src.get("chapters", []) or [])
        return total

    # --- Duplicate merging (same title, different name) ---
    # The same work often appears under a different translated title
    # on another source. The fetcher only merges on an exact
    # canonical-slug match, so those become duplicate entries. This
    # pass collapses them: the winner keeps its id, the loser's
    # sources move in, alt titles union, and an alias is recorded
    # so the next fetch resolves the loser's name straight to the
    # winner instead of re-splitting. Runs after all sources, before
    # write_outputs, so duplicates merge automatically each hour.

    def _alt_text(self, title):
        """All alternative names for a title, joined and normalized
        to plain lowercase words, so a name split across the source's
        separators ("Me" + "the Heavenly Destined Villain") still
        reads as one phrase."""
        joined = " ".join(title.get("alt_titles", []) or [])
        text = re.sub(r"[^a-z0-9]+", " ", joined.lower())
        return re.sub(r"\s+", " ", text).strip()

    def _norm_name(self, name):
        text = re.sub(r"[^a-z0-9]+", " ", (name or "").lower())
        return re.sub(r"\s+", " ", text).strip()

    def _chapter_title_set(self, title):
        """Normalized chapter titles that carry real text. Bare
        "Chapter N" titles are skipped — they say nothing about
        identity, only the number does."""
        out = set()
        for src in title.get("sources", []) or []:
            for ch in src.get("chapters", []) or []:
                t = (ch.get("title") or "").strip().lower()
                if not t:
                    continue
                if re.fullmatch(r"chapter\s*\d+(?:\.\d+)?", t):
                    continue
                out.add(re.sub(r"\s+", " ", t))
        return out

    def _chapter_count(self, title):
        return sum(
            len(s.get("chapters", []) or [])
            for s in title.get("sources", []) or []
        )

    def _should_merge(self, a, b):
        """The merge rule. Returns the reason string, or '' when the
        pair must stay separate."""
        # Chapter-title overlap for the rules below.
        a_titles = self._chapter_title_set(a)
        b_titles = self._chapter_title_set(b)
        overlap = 0.0
        if a_titles and b_titles:
            overlap = len(a_titles & b_titles) / min(
                len(a_titles), len(b_titles)
            )
        # Rule 1: near-identical chapter titles, both with enough
        # real chapter titles to judge by. This is the decisive
        # signal — different translations of one original share
        # chapter names even when the title itself differs.
        if overlap >= 0.8 and len(a_titles) >= 10 and len(b_titles) >= 10:
            return f"chapter-overlap {overlap:.2f}"
        # Rule 2: same author and similar names.
        a_author = slugify(a.get("author", ""))
        b_author = slugify(b.get("author", ""))
        if a_author and a_author == b_author:
            sim = name_similarity(
                a.get("title", ""), b.get("title", "")
            )
            if sim >= 0.6:
                return f"author+name {sim:.2f}"
        # Rule 3: one title's name appears among the other's
        # alternative names. Alt titles arrive as messy joined
        # strings ("Me" + "the Heavenly Destined Villain"), so match
        # by substring on the normalized joined alt text, not by
        # exact slug. A MUTUAL cross-match (each name appears in
        # the other's alts) is decisive on its own; a one-way
        # cross-match needs an author match or real chapter
        # overlap. Both titles need at least 2 chapters so
        # one-chapter bracketed one-shots never merge.
        a_name = self._norm_name(a.get("title", ""))
        b_name = self._norm_name(b.get("title", ""))
        a_in_b = (
            len(a_name.split()) >= 2
            and a_name in self._alt_text(b)
        )
        b_in_a = (
            len(b_name.split()) >= 2
            and b_name in self._alt_text(a)
        )
        if (a_in_b or b_in_a) and (
            self._chapter_count(a) >= 2
            and self._chapter_count(b) >= 2
        ):
            if a_in_b and b_in_a:
                return "alt-title mutual cross-match"
            if a_author and a_author == b_author or overlap >= 0.3:
                return f"alt-title cross-match (overlap {overlap:.2f})"
        return ""

    def merge_duplicates(self):
        cids = sorted(self.index_entries)
        titles = {}
        for cid in cids:
            t = self.prev_title(cid)
            if t:
                titles[cid] = t
        merged = []
        removed = set()
        for i, cid_a in enumerate(cids):
            if cid_a in removed:
                continue
            a = titles.get(cid_a)
            if not a:
                continue
            for cid_b in cids[i + 1:]:
                if cid_b in removed:
                    continue
                b = titles.get(cid_b)
                if not b:
                    continue
                reason = self._should_merge(a, b)
                if not reason:
                    continue
                # Winner: more chapters, then more sources, then
                # the lower id (deterministic).
                a_ch, b_ch = self._chapter_count(a), self._chapter_count(b)
                a_n, b_n = len(a.get("sources", [])), len(b.get("sources", []))
                if (b_ch, b_n) > (a_ch, a_n):
                    winner, loser = b, a
                    winner_id, loser_id = cid_b, cid_a
                elif (a_ch, a_n) > (b_ch, b_n):
                    winner, loser = a, b
                    winner_id, loser_id = cid_a, cid_b
                else:
                    winner, loser = (a, b) if cid_a < cid_b else (b, a)
                    winner_id, loser_id = (
                        (cid_a, cid_b) if cid_a < cid_b else (cid_b, cid_a)
                    )
                # Move the loser's sources in. When the winner
                # already has a source, union the chapter lists
                # (dedup by chapter number) instead of dropping
                # the loser's copy — the same work on one source
                # under two names can hold different chapters.
                have = {
                    s.get("source"): s
                    for s in winner.get("sources", []) or []
                }
                for src in loser.get("sources", []) or []:
                    sid = src.get("source")
                    if sid not in have:
                        winner.setdefault("sources", []).append(src)
                        have[sid] = src
                        continue
                    existing = have[sid]
                    seen = {
                        normalize_number(c.get("number", ""))
                        for c in existing.get("chapters", []) or []
                    }
                    for ch in src.get("chapters", []) or []:
                        if normalize_number(ch.get("number", "")) not in seen:
                            existing.setdefault("chapters", []).append(ch)
                            seen.add(normalize_number(ch.get("number", "")))
                    existing["chapters"].sort(
                        key=lambda c: _chapter_sort_key(c.get("number", "")),
                        reverse=True,
                    )
                # Union alt titles, plus the loser's own title.
                alts = list(winner.get("alt_titles", []) or [])
                for extra in (loser.get("alt_titles", []) or []) + [
                    loser.get("title", "")
                ]:
                    if extra and extra != winner.get("title") and extra not in alts:
                        alts.append(extra)
                winner["alt_titles"] = alts[:10]
                # Record aliases so the next fetch resolves the
                # loser's name straight to the winner.
                loser_slug = canonical_title_slug(loser.get("title", ""))
                if loser_slug and loser_slug != winner_id:
                    self.aliases.setdefault(loser_slug, winner_id)
                if loser_id != loser_slug and loser_id != winner_id:
                    self.aliases.setdefault(loser_id, winner_id)
                # Drop the loser.
                removed.add(loser_id)
                self.index_entries.pop(loser_id, None)
                self.title_files.pop(loser_id, None)
                # The fetcher may have updated the loser earlier in
                # this run, so it can still be in touched. Drop it
                # there too, or write_outputs will look it up in
                # title_files and crash with a KeyError.
                self.touched.discard(loser_id)
                self.state.get("titles", {}).pop(loser_id, None)
                for key in [k for k, v in self.state.get("map", {}).items()
                            if v == loser_id]:
                    del self.state["map"][key]
                loser_file = self.root / "data" / "titles" / f"{loser_id}.json"
                if loser_file.is_file():
                    loser_file.unlink()
                self.touched.add(winner_id)
                self.title_files[winner_id] = winner
                merged.append(
                    f"{loser_id} -> {winner_id} ({reason})"
                )
                # The winner may have changed; re-read it for the
                # next pair in this row.
                if winner_id == cid_a:
                    a = winner
                else:
                    a = titles.get(cid_a)
        if merged:
            print(f"merged {len(merged)} duplicate title(s):")
            for line in merged:
                print(f"  {line}")
            self.overrides["aliases"] = self.aliases
            write_json_if_changed(
                self.root / "fetcher" / "overrides.json", self.overrides
            )
        self.stats["titles_merged"] = len(merged)
        return merged

    # --- Output writing ---

    def write_outputs(self):
        if self.args.dry_run:
            print("dry run: not writing outputs")
            return
        titles = []
        for cid, entry in self.index_entries.items():
            if cid in self.touched:
                title = self.title_files[cid]
                updated = max(
                    (int(s.get("scraped_at", 0)) for s in title["sources"]),
                    default=0,
                )
                titles.append({
                    "id": cid,
                    "title": title["title"],
                    "cover": title.get("cover", ""),
                    "status": title.get("status", ""),
                    "genres": list(title.get("genres", [])),
                    "updated": updated,
                    "copies": len(title["sources"]),
                    "adult": bool(title.get("adult", False)),
                })
            else:
                titles.append({
                    "id": entry["id"],
                    "title": entry["title"],
                    "cover": entry.get("cover", ""),
                    "status": entry.get("status", ""),
                    "genres": list(entry.get("genres", [])),
                    "updated": int(entry.get("updated") or 0),
                    "copies": int(entry.get("copies") or 0),
                    "adult": bool(entry.get("adult", False)),
                })
        titles.sort(key=lambda t: (-t["updated"], t["title"].lower()))
        catalog = {
            "version": 1,
            "generated_at": self.now,
            "titles": titles,
        }
        if write_json_if_changed(self.root / "data" / "catalog.json", catalog):
            print("wrote data/catalog.json "
                  f"({len(titles)} titles)")
        written = 0
        for cid in sorted(self.touched):
            title = self.title_files.get(cid)
            if title is None:
                # A title merged away mid-run (or otherwise gone)
                # must not crash the whole deploy; skip it.
                continue
            if write_json_if_changed(
                self.root / "data" / "titles" / f"{cid}.json", title
            ):
                written += 1
        print(f"wrote {written} title files")
        hosts_path = self.root / "data" / "image-hosts.json"
        existing = load_json(hosts_path, {"hosts": []})
        hosts = sorted(set(existing.get("hosts", [])) | self.image_hosts)
        if write_json_if_changed(hosts_path, {"hosts": hosts}):
            print(f"wrote data/image-hosts.json ({len(hosts)} hosts)")
        # Referer map: host -> [origins]. Existing entries keep
        # their order (first tried); new origins append.
        referers_path = self.root / "data" / "image-referers.json"
        existing_ref = load_json(referers_path, {})
        if not isinstance(existing_ref, dict):
            existing_ref = {}
        merged_ref = dict(existing_ref)
        for host, origins in self.image_referers.items():
            known = list(merged_ref.get(host, []) or [])
            for origin in origins:
                if origin not in known:
                    known.append(origin)
            merged_ref[host] = known
        if write_json_if_changed(referers_path, merged_ref):
            print(f"wrote data/image-referers.json ({len(merged_ref)} hosts)")
        # Fetch patterns: the per-source chapter/image mechanisms,
        # seeded from the keiyoushi extensions and updated as runs
        # verify them. Deployed with the catalog so the reader can
        # choose the same mechanism for its live fetch.
        if self.patterns_dirty:
            self.patterns["generated_at"] = self.now
            if write_json_if_changed(
                self.root / "data" / "source-patterns.json", self.patterns
            ):
                print(
                    "wrote data/source-patterns.json "
                    f"({len(self.patterns.get('sources', {}))} sources)"
                )
        write_json_atomic(self.root / "fetcher" / "state.json", self.state)
        print("wrote fetcher/state.json")


def _chapter_sort_key(number):
    try:
        return (0, float(number))
    except (TypeError, ValueError):
        return (1, 0.0)


def main(argv=None):
    parser = argparse.ArgumentParser(
        description="Hourly catalog fetcher for manhwafreedev."
    )
    parser.add_argument(
        "--root", default=str(ROOT),
        help="repo root (default: parent of the fetcher dir)",
    )
    parser.add_argument(
        "--sources", default=None,
        help="comma-separated source ids to scrape (default: v1 set)",
    )
    parser.add_argument(
        "--max-seconds", type=int, default=MAX_SECONDS,
        help=f"wall-clock cap for the run (default {MAX_SECONDS})",
    )
    parser.add_argument(
        "--max-requests", type=int, default=MAX_REQUESTS,
        help=f"request cap for the run (default {MAX_REQUESTS})",
    )
    parser.add_argument(
        "--list-pages", type=int, default=LIST_PAGES,
        help=f"/manga/ pages per source (default {LIST_PAGES})",
    )
    parser.add_argument(
        "--seed", action="store_true",
        help="seed mode: crawl each source's whole /manga/ "
        "directory from the stored cursor instead of only the "
        "latest-updates window",
    )
    parser.add_argument(
        "--seed-pages", type=int, default=SEED_PAGES,
        help=f"/manga/ pages per source per seed run (default {SEED_PAGES})",
    )
    parser.add_argument(
        "--dry-run", action="store_true",
        help="scrape but do not write any output files",
    )
    args = parser.parse_args(argv)
    args.sources = (
        [s.strip() for s in args.sources.split(",") if s.strip()]
        if args.sources else None
    )
    try:
        return Runner(args.root, args).run()
    except KeyboardInterrupt:
        print("interrupted")
        return 1


if __name__ == "__main__":
    sys.exit(main())

