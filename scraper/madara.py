"""Madara-theme scraping (stdlib only: urllib + htmltree).

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
API_MAX_PAGES = 10  # chapters API pages per title (10 chapters/page)


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

    def __init__(self, source_id, base_url, delay=0.5):
        self.source_id = source_id
        self.base = base_url.rstrip("/")
        self.delay = delay
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
        """Latest-updates list. Madara's /manga/ archive is ordered
        newest-first by default. Returns (ok, items, kind, message)
        where each item has slug, title, cover and latest chapters."""
        url = f"{self.base}/manga/"
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
        until a 404 or an empty page. Returns the chapter list."""
        manga_url = f"{self.base}/{slug.strip('/')}/"
        chapters = []
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
            chapters.extend(page_chapters)
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
    synopsis = text(find_first(root, tag="div", cls="summary__content"))
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
                part.strip() for part in re.split(r"[,/|]", raw)
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
