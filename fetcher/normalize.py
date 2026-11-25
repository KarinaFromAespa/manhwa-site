"""Normalization helpers for the catalog fetcher.

Canonical ids, chapter numbers, dates and statuses. The number
normalization mirrors lib/catalog.php::normalizeNumber exactly so
the fetcher and the site agree on what "Chapter 12" means.
"""
import re
import unicodedata
from datetime import datetime, timedelta, timezone

# Genres that mark a title adult. A title is adult when its genres
# include one of these, or when every source carrying it is
# registry-flagged adult. Adult titles are kept and tagged, never
# filtered out.
ADULT_GENRES = {
    "adult", "mature", "smut", "erotica", "hentai",
    "porn", "pornographic", "sex",
}


def slugify(text):
    """Lowercase ASCII slug: accents folded, runs of non-alphanumerics
    become one hyphen. Same scheme as tools/build-sources-registry.py.
    """
    folded = unicodedata.normalize("NFKD", text or "")
    folded = "".join(c for c in folded if not unicodedata.combining(c))
    slug = re.sub(r"[^a-z0-9]+", "-", folded.lower()).strip("-")
    return slug


def canonical_title_slug(title):
    """Normalized title slug for a canonical id. Folds common
    variants ("Season 1", "Part 2", "Vol. 3") so re-releases of the
    same title share one id."""
    slug = slugify(title)
    # Drop trailing season/part/volume markers, repeatedly.
    while True:
        new = re.sub(
            r"-(season|part|volume|vol)-?\d+$", "", slug
        )
        new = re.sub(r"-s\d+$", "", new)
        if new == slug:
            return slug
        slug = new


def canonical_id(title, author=""):
    """Canonical id for a title. The author is only used by the
    runner to disambiguate collisions; the plain slug is the id
    whenever it is unambiguous."""
    return canonical_title_slug(title)


def normalize_number(raw):
    """Mirror of lib/catalog.php::normalizeNumber: "007" -> "7",
    "12.50" -> "12.5", "12.0" -> "12", "Chapter 12" -> "12". Anything
    that does not look like a plain number is returned as-is."""
    n = (raw or "").strip()
    n = re.sub(r"^(chapter|ch\.?|ep\.?|episode)\s*", "", n, flags=re.I)
    m = re.match(r"^(\d+)(?:\.(\d+))?$", n)
    if m:
        out = str(int(m.group(1)))
        if m.group(2):
            frac = m.group(2).rstrip("0")
            if frac:
                out += "." + frac
        return out
    return n


def chapter_number_from(text, href=""):
    """Extract a chapter number from link text, falling back to the
    URL. Returns the normalized number, or "" when nothing matches."""
    # Some Madara sites abbreviate the chapter marker: "Chap 35"
    # (bunmanga, manhuahot: chap-N URLs) or "Ch.004"
    # (mangamaniacs: no-XXXX-ch-YYY-<group> URLs). Accept those too,
    # or those sources yield no chapter number at all and read as
    # hollow. The text is tried first, so a "ch" in the title slug
    # only matters when the link text carries no number.
    for source in (text or "", href or ""):
        m = re.search(r"(?:chapter|chap|ch)[\s\-._]?(\d+(?:\.\d+)?)", source, re.I)
        if m:
            return normalize_number(m.group(1))
    return ""


_RELATIVE_RE = re.compile(
    r"(\d+)\s*(minute|hour|day|week|month|year)s?\s*ago", re.I
)
_DATE_FORMATS = ("%b %d, %Y", "%B %d, %Y", "%Y-%m-%d", "%d %b %Y")


def parse_date(raw, now=None):
    """Madara dates are absolute ("Nov 17, 2026") or relative
    ("19 minutes ago", "3 days ago"). Returns YYYY-MM-DD, or the raw
    text (trimmed) when it cannot be parsed."""
    text = (raw or "").strip()
    if not text:
        return ""
    # ISO 8601 timestamps (chapter APIs): "2026-11-23T15:02:31.000000Z".
    iso = re.match(r"^(\d{4}-\d{2}-\d{2})", text)
    if iso:
        return iso.group(1)
    now = now or datetime.now(timezone.utc)
    m = _RELATIVE_RE.search(text)
    if m:
        amount = int(m.group(1))
        unit = m.group(2).lower()
        delta = {
            "minute": timedelta(minutes=amount),
            "hour": timedelta(hours=amount),
            "day": timedelta(days=amount),
            "week": timedelta(weeks=amount),
            "month": timedelta(days=30 * amount),
            "year": timedelta(days=365 * amount),
        }[unit]
        return (now - delta).strftime("%Y-%m-%d")
    for fmt in _DATE_FORMATS:
        try:
            return datetime.strptime(text, fmt).strftime("%Y-%m-%d")
        except ValueError:
            continue
    return text[:40]


def normalize_status(raw):
    """Map common status spellings to the site's display values.
    Unknown values pass through unchanged."""
    mapping = {
        "ongoing": "Ongoing",
        "on-going": "Ongoing",
        "on going": "Ongoing",
        "completed": "Completed",
        "complete": "Completed",
        "hiatus": "Hiatus",
        "dropped": "Dropped",
        "cancelled": "Cancelled",
        "canceled": "Cancelled",
    }
    value = (raw or "").strip()
    return mapping.get(value.lower(), value)


# Words that carry no identity when comparing titles: bracketed
# series tags are stripped separately, and these generic words are
# dropped so "Ongoing" or "English" cannot inflate a match.
_GENERIC_NAME_WORDS = {
    "ongoing", "completed", "hiatus", "dropped", "english", "eng",
    "raw", "official", "webtoon", "manhwa", "manhua", "manga",
    "novel", "series", "the", "a", "an", "of", "and", "in", "on",
}


def name_tokens(name):
    """Word tokens for title comparison: bracketed tags like
    "[Not Enough Milk]" removed, generic words dropped, the rest
    lowercased and split on non-alphanumerics."""
    text = re.sub(r"\[[^\]]*\]", " ", name or "")
    words = re.split(r"[^a-z0-9]+", text.lower())
    return {w for w in words if w and w not in _GENERIC_NAME_WORDS}


def name_similarity(a, b):
    """Jaccard similarity of the two titles' name tokens. 0 when
    either side has no tokens left after stripping."""
    ta, tb = name_tokens(a), name_tokens(b)
    if not ta or not tb:
        return 0.0
    return len(ta & tb) / len(ta | tb)


def is_adult(genres, source_adult_flags):
    """Adult when any genre is an adult genre, or when every source
    carrying the title is registry-flagged adult."""
    lowered = {g.strip().lower() for g in genres or []}
    if lowered & ADULT_GENRES:
        return True
    return bool(source_adult_flags) and all(source_adult_flags)


# Titles that are not manga at all: error pages and list/homepage
# headings a wrong-page fetch can mistake for a manga title.
# (zinmanga-net "latest-manga-updates", 2026-11-25: a list page
# parsed as a title, filing ~10,000 chapters from many manga under
# one bogus title.) Used by fetcher.py (skip on fetch) and
# merge_shards.py (prune from the catalog).
_JUNK_TITLES = {
    "404", "403", "401", "400", "500", "502", "503",
    "not found", "page not found", "error", "oops",
    "latest manga updates", "latest updates", "latest manga",
    "manga list", "manga updates", "hot manga", "popular manga",
    "new manga", "recently updated", "trending manga",
    "top manga", "all manga", "browse manga",
    "latest manhwa updates", "latest manhwa", "manhwa list",
}


def is_junk_title(title):
    t = (title or "").strip().lower()
    if not t:
        return True
    if t in _JUNK_TITLES:
        return True
    if re.fullmatch(r"\d{3}", t):
        return True
    return False
