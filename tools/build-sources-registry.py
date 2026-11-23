#!/usr/bin/env python3
"""Generate lib/sources.php (the server-side source registry) from
docs/manhwa-sources.json.

Run from the repo root:  python3 tools/build-sources-registry.py

The registry is server-side only: it is never rendered to visitors.
Each entry keeps id, name, lang, site, host, theme, access, adult,
confidence, plus reliability and quality (null until measured — the
scraper fills those in; see docs/architecture.md section 6).
"""
import json
import re
import sys
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import urlparse

ROOT = Path(__file__).resolve().parent.parent
SRC = ROOT / "docs" / "manhwa-sources.json"
DST = ROOT / "lib" / "sources.php"


def slugify(name: str) -> str:
    slug = re.sub(r"[^a-z0-9]+", "-", name.lower()).strip("-")
    return slug or "source"


def php_str(value: str) -> str:
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"


def php_value(value):
    if value is None:
        return "null"
    if value is True:
        return "true"
    if value is False:
        return "false"
    if isinstance(value, (int, float)):
        return repr(value)
    return php_str(str(value))


def main() -> int:
    data = json.loads(SRC.read_text(encoding="utf-8"))
    seen: dict[str, int] = {}
    entries = []
    for item in data:
        base_id = slugify(item["name"])
        n = seen.get(base_id, 0) + 1
        seen[base_id] = n
        source_id = base_id if n == 1 else f"{base_id}-{n}"
        site = item["site"].rstrip("/")
        entries.append({
            "id": source_id,
            "name": item["name"],
            "lang": item.get("lang", ""),
            "site": site,
            "host": urlparse(site).netloc,
            "theme": item.get("theme", ""),
            "access": item.get("access", "free"),
            "adult": bool(item.get("adult", False)),
            "confidence": item.get("confidence", ""),
            "reliability": None,
            "quality": None,
        })

    stamp = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M UTC")
    lines = [
        "<?php",
        "// GENERATED FILE — do not edit by hand.",
        f"// Built from docs/manhwa-sources.json on {stamp} by",
        "// tools/build-sources-registry.py. Server-side only: this",
        "// registry is never rendered to visitors.",
        "return [",
    ]
    for e in entries:
        fields = ", ".join(f"{php_str(k)} => {php_value(v)}" for k, v in e.items())
        lines.append(f"    [{fields}],")
    lines.append("];")
    lines.append("")
    DST.write_text("\n".join(lines), encoding="utf-8")
    print(f"wrote {DST} ({len(entries)} sources)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
