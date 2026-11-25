"""Madara-theme fetching (stdlib only: urllib + htmltree).

Selectors mirror lib/adapters/madara.php, which were verified live
against mangazin.org on 2026-11-23:
- list items: div.page-item-detail (title, cover, latest chapters)
- title page: div.post-title h1, div.summary_image img,
  div.summary__content, div.post-content_item rows (status, genre,
  author, alternative), li.wp-manga-chapter links
- chapter page: div.reading-content img (data-src first), fallback
  div.page-break img

Chapter lists come from whichever mechanism the source uses
(keiyoushi extension reference, recorded per source in
data/source-patterns.json so the choice is stable):
- manga-page: the list is server-rendered in the title page.
- api: a JSON API whose URL is embedded in the title page
  (e.g. /api/comics/<slug>/chapters on zinmanga.net).
- manga-ajax: POST {manga}/ajax/chapters/ (empty body, XHR).
- manga-ajax-paginated: POST {manga}/ajax/chapters/?t={page} to 404.
- admin-ajax: POST /wp-admin/admin-ajax.php with
  action=manga_get_chapters&manga=<post id>.
- spa: the list lives in the page's embedded JSON state
  (__NEXT_DATA__ or <script type="application/json">).
detail() tries the server-rendered list first (free), then the
recorded mode, then the rest, and reports which mode supplied the
list in self.last_chapter_mode.

The list items carry the two latest chapters with their dates, so
the runner can tell whether a title changed without fetching its
title page.
"""
import json
import re
import time
import urllib.error
import urllib.request
from urllib.parse import urljoin, urlparse

from htmltree import find_all, find_first, img_src, parse, text

USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36"
)
REQUEST_TIMEOUT = 10
# Chapters API pages per title (10 chapters/page). The API reports
# last_page, so the loop stops there; this is only a runaway guard.
API_MAX_PAGES = 100


def fetch(url, timeout=REQUEST_TIMEOUT):
    """One polite GET. Returns (ok, status, body, kind, message).
    kind is "" on success, else one of: cloudflare, http_403,
    http_<code>, timeout, network."""
    req = urllib.request.Request(
        url,
        headers={
            "User-Agent": USER_AGENT,
            "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
            "Accept-Language": "en-US,en;q=0.9",
        },
    )
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            body = resp.read().decode("utf-8", "replace")
            return True, resp.status, body, "", ""
    except urllib.error.HTTPError as exc:
        try:
            body = exc.read().decode("utf-8", "replace")
        except Exception:
            body = ""
        if exc.code in (403, 503) and (
            "Just a moment" in body
            or "challenges.cloudflare.com" in body
            or "cf-chl" in body
        ):
            return False, exc.code, body, "cloudflare", (
                "Access is being challenged and this server cannot "
                "pass the check."
            )
        if exc.code == 403:
            return False, 403, body, "http_403", "HTTP 403 (Forbidden)."
        return False, exc.code, body, f"http_{exc.code}", f"HTTP {exc.code}."
    except urllib.error.URLError as exc:
        reason = str(getattr(exc, "reason", exc))
        if "timed out" in reason or "Timeout" in reason:
            return False, 0, "", "timeout", "The request timed out."
        return False, 0, "", "network", f"Could not be reached ({reason})."
    except TimeoutError:
        return False, 0, "", "timeout", "The request timed out."
    except Exception as exc:  # noqa: BLE001 - network edge cases
        return False, 0, "", "network", f"Could not be reached ({exc})."


def fetch_post(url, data=b"", headers=None, timeout=REQUEST_TIMEOUT):
    """One polite form POST (the Madara AJAX chapter endpoints).
    Returns (ok, status, body, kind, message), same kinds as fetch.
    A 404 is returned as ok=False with kind "http_404" — the
    paginated AJAX endpoint signals the end of the list that way."""
    req_headers = {
        "User-Agent": USER_AGENT,
        "Accept": "*/*",
        "Accept-Language": "en-US,en;q=0.9",
        "X-Requested-With": "XMLHttpRequest",
        "Content-Type": "application/x-www-form-urlencoded",
    }
    if headers:
        req_headers.update(headers)
    req = urllib.request.Request(
        url, data=data, headers=req_headers, method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            body = resp.read().decode("utf-8", "replace")
            return True, resp.status, body, "", ""
    except urllib.error.HTTPError as exc:
        try:
            body = exc.read().decode("utf-8", "replace")
        except Exception:
            body = ""
        if exc.code == 404:
            return False, 404, body, "http_404", "HTTP 404 (Not Found)."
        if exc.code in (403, 503) and (
            "Just a moment" in body
            or "challenges.cloudflare.com" in body
            or "cf-chl" in body
        ):
            return False, exc.code, body, "cloudflare", (
                "Access is being challenged and this server cannot "
                "pass the check."
            )
        if exc.code == 403:
            return False, 403, body, "http_403", "HTTP 403 (Forbidden)."
        return False, exc.code, body, f"http_{exc.code}", f"HTTP {exc.code}."
    except urllib.error.URLError as exc:
        reason = str(getattr(exc, "reason", exc))
        if "timed out" in reason or "Timeout" in reason:
            return False, 0, "", "timeout", "The request timed out."
        return False, 0, "", "network", f"Could not be reached ({reason})."
    except TimeoutError:
        return False, 0, "", "timeout", "The request timed out."
    except Exception as exc:  # noqa: BLE001 - network edge cases
        return False, 0, "", "network", f"Could not be reached ({exc})."


def is_madara(html):
    """True when the page looks like a Madara listing: the wp-manga
    markers plus list items. Guards against a source that redesigned
    or moved."""
    return "wp-manga" in html and "page-item-detail" in html


def resolve_url(base, href):
    if not href:
        return ""
    return urljoin(base.rstrip("/") + "/", href)


def url_path(url):
    return (urlparse(url).path or "").strip("/")


class MadaraSite:
    """One Madara source. Enforces a delay between requests to the
    same host and counts requests for the run budget."""

    def __init__(self, source_id, base_url, delay=0.5, list_path="manga"):
        self.source_id = source_id
        self.base = base_url.rstrip("/")
        self.delay = delay
        self.list_path = list_path.strip("/") or "manga"
        self.requests = 0
        self._last_fetch = 0.0
        # Chapter mode that supplied the last detail() list
        # (manga-page, api, manga-ajax, manga-ajax-paginated,
        # admin-ajax, spa, or "" when none did). The runner records
        # it in data/source-patterns.json so the choice is stable.
        self.last_chapter_mode = ""
        self.last_chapters_api = ""

    def get(self, url):
        wait = self.delay - (time.monotonic() - self._last_fetch)
        if wait > 0:
            time.sleep(wait)
        self._last_fetch = time.monotonic()
        self.requests += 1
        return fetch(url)

    def post(self, url, data=b"", referer=""):
        """Form POST with the XHR headers the Madara AJAX endpoints
        expect. Referer is the manga page the request comes from."""
        wait = self.delay - (time.monotonic() - self._last_fetch)
        if wait > 0:
            time.sleep(wait)
        self._last_fetch = time.monotonic()
        self.requests += 1
        headers = {}
        if referer:
            headers["Referer"] = referer
        return fetch_post(url, data=data, headers=headers)

    # --- Lists ---

    def list_page(self, page=1):
        """Latest-updates list. The archive (default /manga/,
        some sources use /manhwa/ or /read/) is ordered
        newest-first by default. Returns (ok, items, kind, message)
        where each item has slug, title, cover and latest chapters."""
        url = f"{self.base}/{self.list_path}/"
        if page > 1:
            url += f"page/{page}/"
        ok, status, body, kind, message = self.get(url)
        if not ok:
            return False, [], kind, message
        if not is_madara(body):
            return False, [], "not_madara", (
                "The listing did not look like the expected layout."
            )
        return True, parse_list(body, self.base), "", ""

    # --- Title page ---

    def detail(self, slug, chapter_mode=""):
        """Full title page: metadata + chapter list. Returns
        (ok, data, kind, message). The chapter list is taken from
        the mechanism that works for this source, in the order the
        keiyoushi extension for it uses (recorded in
        data/source-patterns.json): the server-rendered list is free
        (already fetched), then the recorded mode, then the rest.
        The mode that supplied the list is left in
        self.last_chapter_mode for the runner to record."""
        url = f"{self.base}/{slug.strip('/')}/"
        ok, status, body, kind, message = self.get(url)
        if not ok:
            return False, None, kind, message
        data = parse_detail(body, self.base, slug)
        if data is None:
            return False, None, "bad_response", (
                "The title page did not contain readable details."
            )
        self.last_chapter_mode = ""
        self.last_chapters_api = ""
        if data.get("chapters"):
            self.last_chapter_mode = "manga-page"
            data["chapters"] = complete_chapter_list(data["chapters"])
            return True, data, "", ""
        title_slug = slug.strip("/").split("/")[-1]
        # Fallbacks, recorded mode first, then the rest. Each costs
        # requests, so the recorded mode (when right) avoids the
        # wasted attempts.
        order = []
        for mode in (chapter_mode, "api", "manga-ajax",
                     "manga-ajax-paginated", "admin-ajax", "spa"):
            if mode and mode not in order:
                order.append(mode)
        for mode in order:
            chapters = []
            if mode == "api":
                api_url = find_chapters_api(body, self.base)
                if api_url:
                    chapters = self.fetch_api_chapters(api_url, title_slug)
                    if chapters:
                        self.last_chapters_api = api_url
            elif mode == "manga-ajax":
                chapters = self.ajax_chapters(slug)
            elif mode == "manga-ajax-paginated":
                chapters = self.ajax_chapters_paginated(slug)
            elif mode == "admin-ajax":
                post_id = find_post_id(body)
                if post_id:
                    chapters = self.admin_ajax_chapters(slug, post_id)
            elif mode == "spa":
                chapters = find_spa_chapters(body, self.base)
            if chapters:
                data["chapters"] = chapters
                self.last_chapter_mode = mode
                break
        # Complete the list when the mode only returns a latest-N
        # window (manga-page, manga-ajax, admin-ajax). The API and
        # SPA modes supply the full list themselves, so they skip
        # this.
        if self.last_chapter_mode in (
            "manga-page", "manga-ajax", "manga-ajax-paginated",
            "admin-ajax",
        ):
            data["chapters"] = complete_chapter_list(
                data.get("chapters") or []
            )
        return True, data, "", ""

    def ajax_chapters(self, slug):
        """MangaAjax chapter list: POST {manga}/ajax/chapters/ with
        an empty body and XHR headers (the keiyoushi Madara theme's
        fetchMangaAjaxChapters). Returns the chapter list, or []."""
        manga_url = f"{self.base}/{slug.strip('/')}/"
        url = f"{self.base}/{slug.strip('/')}/ajax/chapters/"
        ok, _, body, _, _ = self.post(url, data=b"", referer=manga_url)
        if not ok:
            return []
        return parse_chapter_list_html(body, self.base)

    def ajax_chapters_paginated(self, slug):
        """MangaAjaxPaginated: POST {manga}/ajax/chapters/?t={page}
        until a 404, an empty page, or a page that adds no new
        chapters (some sources ignore ?t= and return page 1 every
        time — stop instead of looping the same list 50 times).
        Returns the chapter list, deduplicated."""
        manga_url = f"{self.base}/{slug.strip('/')}/"
        chapters = []
        seen = set()
        page = 1
        while page <= 50:
            url = (
                f"{self.base}/{slug.strip('/')}/ajax/chapters/?t={page}"
            )
            ok, status, body, kind, _ = self.post(
                url, data=b"", referer=manga_url
            )
            if not ok:
                break
            page_chapters = parse_chapter_list_html(body, self.base)
            if not page_chapters:
                break
            new = [
                c for c in page_chapters if c.get("id") not in seen
            ]
            if not new:
                break
            for c in new:
                seen.add(c.get("id"))
            chapters.extend(new)
            page += 1
        return chapters

    def admin_ajax_chapters(self, slug, post_id):
        """AdminAjax chapter list: POST /wp-admin/admin-ajax.php
        with action=manga_get_chapters&manga=<post id>."""
        import urllib.parse

        manga_url = f"{self.base}/{slug.strip('/')}/"
        data = urllib.parse.urlencode({
            "action": "manga_get_chapters",
            "manga": post_id,
        }).encode()
        ok, _, body, _, _ = self.post(
            f"{self.base}/wp-admin/admin-ajax.php",
            data=data, referer=manga_url,
        )
        if not ok:
            return []
        return parse_chapter_list_html(body, self.base)

    def fetch_api_chapters(self, api_url, title_slug):
        """All pages of a chapters JSON API, newest first. The API
        paginates (10/page); capped at 10 pages so one long title
        cannot eat the run's budget."""
        chapters = []
        page = 1
        while page <= API_MAX_PAGES:
            url = api_url
            if page > 1:
                sep = "&" if "?" in api_url else "?"
                url = f"{api_url}{sep}page={page}"
            ok, _, api_body, _, _ = self.get(url)
            if not ok:
                break
            try:
                payload = json.loads(api_body)
            except ValueError:
                break
            page_chapters = parse_api_chapters(payload, title_slug)
            if not page_chapters:
                break
            chapters.extend(page_chapters)
            _, last_page = api_page_info(payload)
            if page >= last_page:
                break
            page += 1
        return chapters

    # --- Chapter page ---

    def chapter_images(self, chapter_id):
        """Image URLs of one chapter page, in reading order. Returns
        (ok, images, kind, message)."""
        url = f"{self.base}/{chapter_id.strip('/')}/"
        ok, status, body, kind, message = self.get(url)
        if not ok:
            return False, [], kind, message
        images = parse_chapter_images(body)
        if not images:
            return False, [], "bad_response", (
                "The chapter page did not contain any readable images."
            )
        return True, images, "", ""


_CHAPTER_URL_RE = re.compile(
    # Accept the "chap-" abbreviation too (bunmanga, manhuahot),
    # so gap-filling works for those sources. Deliberately NOT "ch-":
    # mangamaniacs URLs carry a varying "no-XXXX" prefix per chapter,
    # so synthesising them would build wrong links.
    r"^(.*(?:chapter|chap)-)(\d+(?:\.\d+)?)(.*)$", re.I
)


def complete_chapter_list(chapters):
    """Fill a 'latest N' chapter window out to the full list.

    Madara title-page AJAX (manga-ajax / admin-ajax) and many
    server-rendered title pages return only the newest N chapters
    and ignore every pagination parameter, so the fetcher cannot
    page to the older ones (verified on manhwanex: ajax ?t=, POST
    page/t/paged and the manga page ?manga-paged= all return the
    same latest 16; the 'Show more' button only expands the CSS
    to reveal those same 16). But the chapter URLs follow the
    chapter-<number> pattern and the series runs 1..max, so the
    missing numbers are constructed from the URL pattern of the
    chapters we did fetch. Fetched chapters keep their real data
    (title, date); synthesized ones get 'Chapter N' and no date.
    Returns the union, deduplicated by number, sorted by number.
    Only integer numbers are synthesized; decimal chapters (12.5)
    are left to the fetched list. Returns the input unchanged when
    there is no gap (max <= count) or the URL pattern cannot be
    derived."""
    if not chapters:
        return chapters
    max_num = 0.0
    prefix = None
    suffix = ""
    have = set()
    for ch in chapters:
        num = ch.get("number", "")
        try:
            f = float(num)
        except (TypeError, ValueError):
            continue
        if f > max_num:
            max_num = f
        have.add(num)
        if prefix is None:
            m = _CHAPTER_URL_RE.match(ch.get("id", "") or "")
            if m:
                prefix = m.group(1)
                # Keep any suffix after the number (e.g. "-raw" on
                # mangaforfree chapter URLs) so synthesized links
                # match the source's real URL shape.
                suffix = m.group(3)
    if prefix is None or max_num <= len(chapters):
        return chapters
    import math

    top = int(math.floor(max_num))
    out = list(chapters)
    for n in range(1, top + 1):
        key = str(n)
        if key in have:
            continue
        # prefix already ends in "chapter-", so just append the
        # number (and the source's suffix, e.g. "-raw"), giving
        # e.g. manga/<slug>/chapter-1 or .../chapter-1-raw.
        out.append({
            "number": key,
            "title": f"Chapter {n}",
            "id": f"{prefix}{n}{suffix}",
            "date": "",
        })

    def _num(c):
        try:
            return float(c.get("number", 0))
        except (TypeError, ValueError):
            return 0.0

    out.sort(key=_num)
    return out


def parse_list(html, base):
    """div.page-item-detail items: slug, title, cover, and the two
    latest chapters (number, title, id, date) for change detection."""
    from normalize import chapter_number_from  # local import: no cycle

    root = parse(html)
    items = []
    seen = set()
    for node in find_all(root, tag="div", cls="page-item-detail"):
        # Title link: the <a> in div.post-title. Some Madara sites
        # serve titles under /webtoon/ (or /manhwa/) instead of
        # /manga/, so the href is not filtered on a fixed prefix.
        title_node = find_first(node, tag="div", cls="post-title")
        link = (
            find_first(title_node, tag="a")
            if title_node is not None else None
        )
        if link is None:
            links = [
                a for a in find_all(node, tag="a")
                if "/manga/" in a.get("href", "")
            ]
            link = links[0] if links else None
        if link is None:
            continue
        slug = url_path(resolve_url(base, link.get("href", "")))
        if not slug or slug in seen:
            continue
        title = ""
        if title_node is not None:
            title = text(link)
        if not title:
            title = link.get("title", "").strip()
        if not title:
            img = find_first(node, tag="img")
            title = img.get("alt", "").strip() if img is not None else ""
        if not title:
            continue
        img = find_first(node, tag="img")
        cover = img_src(img)
        chapters = []
        for item in find_all(node, tag="div", cls="chapter-item"):
            link = find_first(item, tag="a")
            if link is None:
                continue
            href = link.get("href", "")
            link_text = text(link)
            chapters.append({
                "number": chapter_number_from(link_text, href),
                "title": link_text,
                "id": url_path(resolve_url(base, href)),
                "date": text(find_first(item, tag="span", cls="post-on")),
            })
        seen.add(slug)
        items.append({
            "slug": slug,
            "title": title,
            "cover": cover,
            "latest": chapters,
        })
    return items


def parse_chapter_list_html(html, base):
    """Chapter list from any HTML carrying li.wp-manga-chapter
    items (a title page, or an AJAX chapter-list response). The
    same extraction parse_detail uses, standalone."""
    from normalize import chapter_number_from  # local import: no cycle

    root = parse(html)
    chapters = []
    for link in find_all(root, tag="a"):
        li = link.parent
        if li is None or li.tag != "li":
            continue
        if "wp-manga-chapter" not in li.cls():
            continue
        href = link.get("href", "")
        if not href:
            continue
        link_text = text(link)
        date_node = find_first(li, tag="span", cls="chapter-release-date")
        chapters.append({
            "number": chapter_number_from(link_text, href),
            "title": link_text,
            "id": url_path(resolve_url(base, href)),
            "date": text(date_node),
        })
    return chapters


def find_post_id(html):
    """WordPress post id of a manga page (data-post-id), for the
    AdminAjax chapter endpoint. None when absent."""
    match = re.search(r'data-post-id=["\'](\d+)["\']', html)
    return match.group(1) if match else None


def find_spa_chapters(html, base):
    """Chapter list from a single-page app's embedded state:
    __NEXT_DATA__ JSON, or <script type="application/json"> blocks.
    Looks for arrays of objects carrying a chapter URL (a href or
    slug containing /chapter). Returns [] when the page carries no
    such state — most Madara sites are server-rendered, so this is
    the last fallback, not the first guess."""
    from normalize import chapter_number_from  # local import: no cycle

    blobs = []
    m = re.search(
        r'<script[^>]*id=["\']__NEXT_DATA__["\'][^>]*>(.*?)</script>',
        html, re.S,
    )
    if m:
        blobs.append(m.group(1))
    blobs.extend(
        re.findall(
            r'<script[^>]*type=["\']application/json["\'][^>]*>(.*?)</script>',
            html, re.S,
        )
    )
    for blob in blobs:
        try:
            data = json.loads(blob)
        except ValueError:
            continue
        found = _spa_chapter_entries(data, base)
        if found:
            return found
    return []


def _spa_chapter_entries(node, base, depth=0):
    """Recursively search parsed JSON for chapter entries: objects
    with a URL/slug containing /chapter, or a chapter number plus a
    URL. Returns chapter dicts in the order found."""
    from normalize import chapter_number_from  # local import: no cycle

    if depth > 8:
        return []
    chapters = []
    if isinstance(node, dict):
        url = (
            node.get("url") or node.get("href") or node.get("slug") or ""
        )
        if isinstance(url, str) and "/chapter" in url:
            num = node.get("chapter_num") or node.get("number") or ""
            name = node.get("chapter_name") or node.get("name") or node.get("title") or ""
            chapters.append({
                "number": chapter_number_from(str(name), url),
                "title": str(name),
                "id": url_path(resolve_url(base, url)),
                "date": str(node.get("updated_at") or node.get("date") or ""),
            })
        for value in node.values():
            chapters.extend(_spa_chapter_entries(value, base, depth + 1))
    elif isinstance(node, list):
        for item in node:
            chapters.extend(_spa_chapter_entries(item, base, depth + 1))
    return chapters


def clean_synopsis(raw, title=""):
    """Keep only the real plot from a Madara summary__content block.

    Several sources stuff the synopsis block with SEO/marketing
    sections that are not the story: ZinManga (zinmanga.net,
    mangazin.org) adds "Details of X / Welcome to ZinManga / Main
    Plot / Why should you read X on ZinManga? / Explore More
    Genres" plus keyword lists; manhwanex adds a whole SEO article
    ("Generic Breakdown / Character Relationships / Core Concept /
    Arc Breakdown / Why You Should Read / Reader Experience /
    Related Series / FAQ / Update Schedule"); pornhwaz adds
    "Original Novel / Official Translations / Similar Manhwas /
    Update of X" plus site promo; s2manga adds "Search for series
    of same genre(s) / Welcome to site" plus keyword lists.
    Clean synopses contain none of these markers and pass through
    untouched. Returns the cleaned text (or the original when
    cleaning would leave almost nothing).
    """
    if not raw:
        return ""
    text_clean = re.sub(r"\s+", " ", raw).strip()
    if not text_clean:
        return ""
    lower = text_clean.lower()
    # Section headings that start SEO/marketing content. The plot
    # is everything before the first of these.
    section_markers = [
        "generic breakdown",
        "character relationships",
        "core concept",
        "arc breakdown",
        "why you should read",
        "why should you read",
        "reader experience",
        "related series",
        "faq of the",
        "update schedule",
        "explore more genres",
        "similar manhwas",
        "search for series of same genre",
        "original novel",
        "welcome to zinmanga",
        "welcome to site",
        "free access",
        "daily updates",
        "user-friendly interface",
        "high-quality content",
        "accessibility",
    ]
    # ZinManga pattern: the real plot sits under a "Main Plot"
    # heading, after the "Details of X / Welcome to ZinManga"
    # blurb and before the marketing sections.
    main_plot = lower.find("main plot")
    if main_plot >= 0:
        after = text_clean[main_plot + len("main plot"):]
        after_lower = after.lower()
        cut = len(after)
        for marker in section_markers:
            pos = after_lower.find(marker)
            if 0 <= pos < cut:
                cut = pos
        plot = after[:cut].strip()
        if len(plot) >= 30:
            return _trim_synopsis(plot)
    # Generic pattern: the plot is the intro, before the first
    # SEO/marketing section heading.
    cut = len(text_clean)
    for marker in section_markers:
        pos = lower.find(marker)
        if 0 <= pos < cut:
            cut = pos
    plot = text_clean[:cut].strip()
    # pornhwaz leads with "Summary of X Online Manhwa" before the
    # plot; drop that header.
    m = re.match(
        r"^summary of .{1,120}? online manhwa", plot, re.IGNORECASE
    )
    if m:
        plot = plot[m.end():].strip()
    # Drop the broken "Also known as , , , and ," template line
    # manhwanex embeds in the intro (alt titles already live in
    # the alt_titles field).
    plot = re.sub(
        r"also known as(?:\s*,){1,}\s*(?:and\s*,)?\s*",
        "",
        plot,
        flags=re.IGNORECASE,
    )
    # Drop the broken "Other titles including , , and are also
    # recognized by fans..." template sentence.
    plot = re.sub(
        r"other titles including\s*,.*?are also recognized by fans"
        r"[^.]*\.\s*",
        "",
        plot,
        flags=re.IGNORECASE,
    )
    # Drop inline keyword clusters ("... read manhua online",
    # "... manhua online free") left inside the intro. Longest
    # phrase first so "read manhua online free" is removed whole
    # instead of leaving a stray "free".
    plot = re.sub(
        r"\s*(?:read\s+(?:manhua|manga|manhwa)\s+online\s+free"
        r"|read\s+(?:manhua|manga|manhwa)\s+online"
        r"|read\s+online\s+(?:manhua|manga|manhwa)"
        r"|(?:manhua|manga|manhwa)\s+online\s+free)\s*",
        " ",
        plot,
        flags=re.IGNORECASE,
    )
    # Drop concatenated genre-keyword tokens ("Manhuaevolution",
    # "manhuaread") left when the keyword line is stripped.
    plot = re.sub(
        r"\b\w*(?:manhua|manga|manhwa)[a-z]{2,}\b",
        "",
        plot,
        flags=re.IGNORECASE,
    )
    # Drop a long CJK span: manhwanex embeds the (already stored)
    # alt titles as one concatenated blob of CJK + English names
    # inside the intro. Remove from the first to the last CJK
    # character of such a span (only when it is long, so a single
    # CJK name in a real sentence survives).
    def _drop_cjk_span(sentence):
        cjk = re.compile(
            r"[\u4e00-\u9fff\u3040-\u30ff\uac00-\ud7af]"
        )
        hits = [m.start() for m in cjk.finditer(sentence)]
        if len(hits) >= 2 and hits[-1] - hits[0] > 40:
            return sentence[: hits[0]] + sentence[hits[-1] + 1 :]
        if len(hits) == 1:
            return cjk.sub("", sentence)
        return sentence

    parts = re.split(r"(?<=[.!?])\s+", plot)
    plot = " ".join(_drop_cjk_span(p) for p in parts)
    # Drop a repeated title fragment: manhwanex repeats the title
    # in its keyword line ("... Title Manhuaevolution manhua ..."),
    # leaving a bare "Title manhua" after the keyword tokens are
    # stripped. Keep the first occurrence, drop later ones.
    if title and len(title) >= 8:
        occ = [m for m in re.finditer(re.escape(title), plot, re.IGNORECASE)]
        if len(occ) > 1:
            for m in reversed(occ[1:]):
                end = m.end()
                tail = re.match(
                    r"\s+(?:manhua|manga|manhwa)\b", plot[end:],
                    re.IGNORECASE,
                )
                if tail:
                    end += tail.end()
                plot = plot[: m.start()] + plot[end:]
    plot = re.sub(r"\s+", " ", plot).strip()
    if len(plot) < 30:
        return text_clean
    return _trim_synopsis(plot)


def _trim_synopsis(plot):
    """Cap a cleaned synopsis at ~2000 chars on a sentence boundary."""
    if len(plot) <= 2000:
        return plot
    head = plot[:2000]
    for sep in (". ", "! ", "? "):
        pos = head.rfind(sep)
        if pos >= 500:
            return head[: pos + 1].strip()
    return head.rstrip() + "…"


def parse_detail(html, base, slug):
    """Title page. Returns None when the page has no readable title."""
    from normalize import chapter_number_from  # local import: no cycle

    root = parse(html)
    title = ""
    title_node = find_first(root, tag="div", cls="post-title")
    if title_node is not None:
        title = text(find_first(title_node, tag="h1"))
    if not title:
        title = text(find_first(root, tag="h1"))
    if not title:
        meta = find_first(root, tag="meta", attr="property", value="og:title")
        title = meta.get("content", "").strip() if meta is not None else ""
    if not title:
        return None
    cover_node = find_first(root, tag="div", cls="summary_image")
    cover = img_src(find_first(cover_node, tag="img")) if cover_node else ""
    synopsis = clean_synopsis(
        text(find_first(root, tag="div", cls="summary__content")),
        title=title,
    )
    status, genres, author, alt_titles = "", [], "", []
    for row in find_all(root, tag="div", cls="post-content_item"):
        heading = text(find_first(row, tag="div", cls="summary-heading")).lower()
        content = find_first(row, tag="div", cls="summary-content")
        if "status" in heading:
            status = text(content)
        elif "genre" in heading and content is not None:
            genres = [
                text(a) for a in find_all(content, tag="a") if text(a)
            ]
        elif "author" in heading:
            author = text(content)
        elif "alternative" in heading or "alt name" in heading:
            raw = text(content)
            alt_titles = [
                part.strip() for part in re.split(r"[,/|•]", raw)
                if part.strip()
            ]
    chapters = parse_chapter_list_html(html, base)
    return {
        "slug": slug.strip("/"),
        "title": title,
        "cover": cover,
        "synopsis": synopsis,
        "status": status,
        "genres": genres,
        "author": author,
        "alt_titles": alt_titles,
        "chapters": chapters,
    }


_API_CHAPTERS_RE = re.compile(
    r"""["']([^"']*?/api/[^"']*?/chapters[^"']*)["']"""
)


def find_chapters_api(html, base):
    """URL of the chapters JSON API embedded in a title page, if
    any (e.g. /api/comics/<slug>/chapters on zinmanga.net)."""
    match = _API_CHAPTERS_RE.search(html)
    if not match:
        return None
    return resolve_url(base, match.group(1))


def api_page_info(payload):
    """(current_page, last_page) of a chapters API response;
    (1, 1) when the payload carries no pagination info."""
    if isinstance(payload, dict):
        data = payload.get("data", {})
        if isinstance(data, dict):
            try:
                current = int(data.get("current_page", 1))
                last = int(data.get("last_page", 1))
                return max(1, current), max(1, last)
            except (TypeError, ValueError):
                pass
    return 1, 1


def parse_api_chapters(payload, title_slug):
    """Chapter list from a Madara chapters JSON API. Accepts
    {"data": {"chapters": [...]}}, {"data": [...]}, or a bare list.
    Items carry chapter_num, chapter_name, chapter_slug and an ISO
    updated_at. Returns the same chapter dicts as parse_detail."""
    from normalize import chapter_number_from, normalize_number, parse_date  # local import: no cycle

    if isinstance(payload, dict):
        data = payload.get("data", payload)
        items = data.get("chapters", []) if isinstance(data, dict) else []
    elif isinstance(payload, list):
        items = payload
    else:
        return []
    if not isinstance(items, list):
        return []
    chapters = []
    for item in items:
        if not isinstance(item, dict):
            continue
        slug = str(item.get("chapter_slug", "") or "")
        name = str(item.get("chapter_name", "") or "")
        number = ""
        if item.get("chapter_num") is not None:
            number = normalize_number(str(item["chapter_num"]))
        if not number:
            number = chapter_number_from(name, slug)
        if not number:
            continue
        chapters.append({
            "number": number,
            "title": name,
            "id": f"manga/{title_slug}/{slug}" if slug else "",
            "date": parse_date(str(item.get("updated_at", "") or "")),
        })
    return chapters


def parse_chapter_images(html):
    """Image URLs of a chapter page, in reading order (data-src
    first). Returns a deduplicated list of absolute http(s) URLs."""
    root = parse(html)
    # Exact class token, not substring: the page also has
    # "reading-content-wrap" (the outer wrapper, which contains the
    # whole page including related manga) and
    # "related-reading-content" (related thumbnails).
    container = None
    for cand in find_all(root, tag="div", cls="reading-content"):
        if "reading-content" in cand.cls().split():
            container = cand
            break
    if container is None:
        container = find_first(root, tag="div", cls="page-break")
    if container is None:
        return []
    images = []
    for img in find_all(container, tag="img"):
        src = img_src(img)
        if src.startswith("http://") or src.startswith("https://"):
            if src not in images:
                images.append(src)
    return images
