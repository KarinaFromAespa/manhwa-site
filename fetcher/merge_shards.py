#!/usr/bin/env python3
"""Merge shard outputs into the final catalog.

Each shard (fetcher/fetcher.py --shard i/N) writes only the titles it
touched, plus a manifest, into its shard-out dir. This script combines
them on top of the base catalog (data/ as checked out) and writes the
final catalog back to data/.

Titles that appear in more than one shard (the same work on sources
split across shards) get their sources unioned: a source entry is
kept once per source id, and its chapters are unioned by chapter id
(then by number), newest first. Metadata comes from the freshest
fetch; the base copy is kept when a shard has nothing newer.

Usage:
    python3 fetcher/merge_shards.py --root . data/shard-0 data/shard-1 data/shard-2
"""
import argparse
import hashlib
import json
import sys
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from normalize import is_junk_title, normalize_number  # noqa: E402


def load_json(path, default):
    try:
        return json.loads(Path(path).read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return default


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


def _chapter_sort_key(number):
    try:
        return (0, float(number))
    except (TypeError, ValueError):
        return (1, 0.0)


def union_sources(base_sources, shard_sources):
    """Union two source-entry lists by source id; chapters union by
    chapter id, falling back to number. Returns the merged list."""
    by_id = {}
    order = []
    for entry in list(base_sources or []) + list(shard_sources or []):
        if not isinstance(entry, dict):
            continue
        sid = entry.get("source")
        if not sid:
            continue
        if sid not in by_id:
            by_id[sid] = {
                "source": sid,
                "lang": entry.get("lang", "en"),
                "fetched_at": int(entry.get("fetched_at", entry.get("scraped_at", 0)) or 0),
                "reliability": entry.get("reliability"),
                "quality": entry.get("quality"),
                "chapters": [],
            }
            order.append(sid)
        cur = by_id[sid]
        # Freshest fetch wins for the entry metadata.
        if int(entry.get("fetched_at", entry.get("scraped_at", 0)) or 0) >= cur["fetched_at"]:
            cur["fetched_at"] = int(entry.get("fetched_at", entry.get("scraped_at", 0)) or 0)
            if entry.get("reliability") is not None:
                cur["reliability"] = entry.get("reliability")
            if entry.get("quality") is not None:
                cur["quality"] = entry.get("quality")
        # Union chapters by id, then by number.
        seen_id = {c.get("id") for c in cur["chapters"] if c.get("id")}
        seen_num = {
            normalize_number(str(c.get("number", "")))
            for c in cur["chapters"]
        }
        for ch in entry.get("chapters", []) or []:
            if not isinstance(ch, dict):
                continue
            cid = ch.get("id")
            num = normalize_number(str(ch.get("number", "")))
            if cid and cid in seen_id:
                continue
            if not cid and num and num in seen_num:
                continue
            cur["chapters"].append(ch)
            if cid:
                seen_id.add(cid)
            if num:
                seen_num.add(num)
        cur["chapters"].sort(
            key=lambda c: _chapter_sort_key(c.get("number", "")),
            reverse=True,
        )
    return [by_id[sid] for sid in order]


def merge_title(base, shard_title):
    """Merge a shard's title dict into the base title dict (or adopt
    it when there is no base). Returns the merged title."""
    if not base:
        return shard_title
    out = dict(base)
    # Freshest metadata wins when it has a value.
    for key in ("cover", "status", "author", "synopsis"):
        if shard_title.get(key):
            out[key] = shard_title[key]
    if shard_title.get("genres"):
        out["genres"] = list(shard_title["genres"])
    if shard_title.get("adult") is not None:
        out["adult"] = bool(shard_title["adult"])
    alts = list(out.get("alt_titles", []) or [])
    for extra in shard_title.get("alt_titles", []) or []:
        if extra and extra not in alts:
            alts.append(extra)
    if shard_title.get("title") and shard_title["title"] != out.get("title"):
        if shard_title["title"] not in alts:
            alts.append(shard_title["title"])
    out["alt_titles"] = alts[:20]
    out["sources"] = union_sources(
        base.get("sources", []), shard_title.get("sources", [])
    )
    return out


def main(argv=None):
    parser = argparse.ArgumentParser(
        description="Merge fetcher shard outputs into the catalog."
    )
    parser.add_argument("--root", default=".",
                        help="repo root (default: current directory)")
    parser.add_argument("shards", nargs="+",
                        help="shard output directories to merge")
    args = parser.parse_args(argv)
    root = Path(args.root)
    now = int(time.time())

    # Base catalog (checked out from main).
    base_index = load_json(root / "data" / "catalog.json", None)
    base_entries = {}
    if isinstance(base_index, dict):
        for entry in base_index.get("titles", []) or []:
            if isinstance(entry, dict) and entry.get("id"):
                base_entries[entry["id"]] = entry
    titles = {}  # cid -> full title dict
    for cid in list(base_entries):
        data = load_json(root / "data" / "titles" / f"{cid}.json", None)
        if isinstance(data, dict) and data.get("id") == cid:
            titles[cid] = data

    image_hosts = set(load_json(
        root / "data" / "image-hosts.json", {"hosts": []}
    ).get("hosts", []))
    image_referers = load_json(root / "data" / "image-referers.json", {})
    if not isinstance(image_referers, dict):
        image_referers = {}
    patterns = load_json(root / "data" / "source-patterns.json", {})
    if not isinstance(patterns, dict):
        patterns = {}
    patterns.setdefault("version", 1)
    patterns.setdefault("sources", {})

    total_new = 0
    total_updated = 0
    combined_stats = {
        "sources_ok": [], "sources_failed": {},
        "titles_seen": 0, "titles_new": 0, "titles_updated": 0,
        "titles_unchanged": 0, "title_pages": 0, "requests": 0,
    }
    # Merge each shard.
    for shard_dir in args.shards:
        shard = Path(shard_dir)
        manifest = load_json(shard / "manifest.json", None)
        if not isinstance(manifest, dict):
            print(f"WARN: no manifest in {shard}, skipping")
            continue
        st = manifest.get("stats", {}) or {}
        combined_stats["sources_ok"].extend(st.get("sources_ok", []))
        combined_stats["sources_failed"].update(st.get("sources_failed", {}))
        for k in ("titles_seen", "titles_new", "titles_updated",
                  "titles_unchanged", "title_pages", "requests"):
            combined_stats[k] += int(st.get(k, 0) or 0)
        for host in manifest.get("image_hosts", []) or []:
            image_hosts.add(host)
        for host, origins in (manifest.get("image_referers", {}) or {}).items():
            known = list(image_referers.get(host, []) or [])
            for origin in origins or []:
                if origin not in known:
                    known.append(origin)
            image_referers[host] = known
        if manifest.get("patterns_dirty"):
            for sid, pat in (manifest.get("patterns", {}) or {}).get("sources", {}).items():
                patterns["sources"][sid] = pat
        for entry in manifest.get("titles", []) or []:
            cid = entry.get("id")
            if not cid:
                continue
            shard_title = load_json(shard / "titles" / f"{cid}.json", None)
            if not isinstance(shard_title, dict):
                print(f"WARN: shard {shard} lists {cid} but has no title file")
                continue
            is_new = cid not in titles
            titles[cid] = merge_title(titles.get(cid), shard_title)
            if is_new:
                total_new += 1
            else:
                total_updated += 1

    # Prune junk titles (list/homepage headings filed as manga,
    # e.g. "latest manga updates"): drop them from the merged titles
    # and their detail files, and record them for the FTP delete step
    # (the upload cannot delete). This is the fetch.yml merge path —
    # the one that actually writes the final catalog.
    pruned = [
        cid for cid, t in titles.items()
        if is_junk_title(t.get("title", ""))
    ]
    if pruned:
        for cid in pruned:
            titles.pop(cid, None)
            fp = root / "data" / "titles" / f"{cid}.json"
            try:
                fp.unlink()
            except FileNotFoundError:
                pass
        (root / "data" / "pruned-titles.txt").write_text(
            "\n".join(pruned) + "\n", encoding="utf-8"
        )
        print(f"pruned {len(pruned)} junk titles: {', '.join(pruned)}")

    # Rebuild the catalog index from the merged titles.
    index_titles = []
    for cid, title in titles.items():
        updated = max(
            (int(s.get("fetched_at", s.get("scraped_at", 0))) for s in title.get("sources", []) or []),
            default=0,
        )
        index_titles.append({
            "id": cid,
            "title": title.get("title", ""),
            "cover": title.get("cover", ""),
            "status": title.get("status", ""),
            "genres": list(title.get("genres", []) or []),
            "updated": updated,
            "copies": len(title.get("sources", []) or []),
            "adult": bool(title.get("adult", False)),
        })
    index_titles.sort(key=lambda t: (-t["updated"], t["title"].lower()))
    catalog = {"version": 1, "generated_at": now, "titles": index_titles}
    if write_json_if_changed(root / "data" / "catalog.json", catalog):
        print(f"wrote data/catalog.json ({len(index_titles)} titles)")
    written = 0
    for cid, title in sorted(titles.items()):
        if write_json_if_changed(
            root / "data" / "titles" / f"{cid}.json", title
        ):
            written += 1
    print(f"wrote {written} title files")
    if write_json_if_changed(
        root / "data" / "image-hosts.json", {"hosts": sorted(image_hosts)}
    ):
        print(f"wrote data/image-hosts.json ({len(image_hosts)} hosts)")
    if write_json_if_changed(root / "data" / "image-referers.json", image_referers):
        print(f"wrote data/image-referers.json ({len(image_referers)} hosts)")
    patterns["generated_at"] = now
    if write_json_if_changed(
        root / "data" / "source-patterns.json", patterns
    ):
        print(f"wrote data/source-patterns.json ({len(patterns['sources'])} sources)")

    chapters_total = sum(
        len(s.get("chapters", []) or [])
        for t in titles.values()
        for s in t.get("sources", []) or []
    )
    summary = {
        "sources_ok": combined_stats["sources_ok"],
        "sources_failed": combined_stats["sources_failed"],
        "titles_new": total_new,
        "titles_updated": total_updated,
        "titles_total": len(titles),
        "chapters_total": chapters_total,
        "title_pages": combined_stats["title_pages"],
        "requests": combined_stats["requests"],
    }
    print("MERGE SUMMARY " + json.dumps(summary))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
