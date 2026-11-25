#!/usr/bin/env python3
"""Audit the live catalog for titles with missing or no chapters.

Reads the catalog downloaded from the live site (data/catalog.json
+ data/titles/*.json) and reports:
- GAP: a source whose chapter count is far below its highest chapter
  number (max - count > 5). The fetcher used to keep only the newest
  N chapters, so these are titles the backfill did not reach (small
  gaps, unfixable URL patterns, or titles never re-fetched).
- HOLLOW: a source with 0 chapters (the title page yielded no
  chapter list at all), and titles where EVERY source is hollow.

Usage: python3 fetcher/audit_gaps.py [--root .] [--out report.md]
Writes a JSON report to data/audit-gaps.json and a readable markdown
report to the --out path (default: audit-gaps.md in the current dir).
"""
import argparse
import json
import sys
from pathlib import Path


def chapter_max(chapters):
    """Highest chapter number in a chapter list (float), or 0."""
    best = 0.0
    for ch in chapters or []:
        try:
            n = float(ch.get("number", "") or 0)
        except (TypeError, ValueError):
            continue
        if n > best:
            best = n
    return best


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--root", default=".")
    ap.add_argument("--out", default="audit-gaps.md")
    args = ap.parse_args()
    root = Path(args.root)
    cat_path = root / "data" / "catalog.json"
    try:
        catalog = json.loads(cat_path.read_text())
    except Exception as exc:
        print(f"cannot read {cat_path}: {exc}", file=sys.stderr)
        return 1
    titles = catalog.get("titles", [])
    gaps = []
    hollows = []
    all_hollow_titles = []
    checked = 0
    for entry in titles:
        cid = entry.get("id", "")
        title = entry.get("title", cid)
        detail_path = root / "data" / "titles" / f"{cid}.json"
        try:
            detail = json.loads(detail_path.read_text())
        except Exception:
            continue
        checked += 1
        sources = detail.get("sources", []) or []
        title_all_hollow = bool(sources)
        for src in sources:
            source_id = src.get("source", "?")
            chapters = src.get("chapters", []) or []
            count = len(chapters)
            mx = chapter_max(chapters)
            if count == 0:
                hollows.append({
                    "id": cid, "title": title, "source": source_id,
                    "count": 0, "max": mx,
                })
            else:
                title_all_hollow = False
                if mx - count > 5:
                    gaps.append({
                        "id": cid, "title": title, "source": source_id,
                        "count": count, "max": mx,
                        "missing": round(mx - count),
                    })
        if title_all_hollow:
            all_hollow_titles.append({"id": cid, "title": title})
    gaps.sort(key=lambda g: -g["missing"])
    hollows.sort(key=lambda h: h["title"].lower())
    all_hollow_titles.sort(key=lambda t: t["title"].lower())
    report = {
        "catalog_titles": len(titles),
        "checked": checked,
        "gap_pairs": len(gaps),
        "gap_missing_total": sum(g["missing"] for g in gaps),
        "hollow_pairs": len(hollows),
        "all_hollow_titles": len(all_hollow_titles),
        "gaps": gaps,
        "hollows": hollows,
        "all_hollow": all_hollow_titles,
    }
    out_json = root / "data" / "audit-gaps.json"
    out_json.write_text(json.dumps(report, indent=1, ensure_ascii=False))
    lines = []
    lines.append("# Chapter gaps + hollow titles audit")
    lines.append("")
    lines.append(
        f"Catalog: {len(titles)} titles, {checked} detail files read."
    )
    lines.append(
        f"GAP title-source pairs: {len(gaps)} "
        f"(~{sum(g['missing'] for g in gaps):,} missing chapters)."
    )
    lines.append(
        f"HOLLOW title-source pairs: {len(hollows)} "
        f"(0 chapters on that source)."
    )
    lines.append(
        f"Titles hollow on EVERY source: {len(all_hollow_titles)}."
    )
    lines.append("")
    lines.append("## GAP titles (chapter count far below the highest chapter)")
    lines.append("")
    lines.append("| Title | Source | Chapters | Highest | Missing (est.) |")
    lines.append("|---|---|---:|---:|---:|")
    for g in gaps:
        lines.append(
            f"| {g['title']} | {g['source']} | {g['count']} | "
            f"{g['max']:.0f} | ~{g['missing']:,} |"
        )
    lines.append("")
    lines.append("## HOLLOW titles (0 chapters on a source)")
    lines.append("")
    lines.append("| Title | Source |")
    lines.append("|---|---|")
    for h in hollows:
        lines.append(f"| {h['title']} | {h['source']} |")
    lines.append("")
    lines.append("## Titles hollow on EVERY source")
    lines.append("")
    for t in all_hollow_titles:
        lines.append(f"- {t['title']}")
    Path(args.out).write_text("\n".join(lines) + "\n", encoding="utf-8")
    print(
        f"checked {checked}/{len(titles)} titles: "
        f"{len(gaps)} gap pairs (~{sum(g['missing'] for g in gaps):,} missing), "
        f"{len(hollows)} hollow pairs, "
        f"{len(all_hollow_titles)} titles hollow on every source"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
