"""Find a working free proxy for the sources that block GitHub Actions.

Downloads the fresh iplocate free-proxy-list (updated every 30 min
— a proxy that works now may be dead next time, so never reuse a
stale list), tests the http/https proxies for basic connectivity,
then tests the survivors against mangaforfree's ajax/chapters POST
and zinmanga-net's chapters API. Prints the best proxy URL and
exits 0: the first one that reaches BOTH, else the first that
reaches mangaforfree, else the first that reaches zinmanga-net.
Exits 1 when no proxy reaches either source.

Only http:// and https:// proxies are tested — the fetcher's
urllib ProxyHandler has no SOCKS support in the stdlib. Shells out
to curl for the proxy requests (preinstalled on the GitHub ubuntu
runner and the Oracle VM); the list download itself is urllib.
"""
import json
import subprocess
import sys
import urllib.request
from concurrent.futures import ThreadPoolExecutor

LIST_URL = (
    "https://raw.githubusercontent.com/iplocate/free-proxy-list/main/all-proxies.txt"
)
# A mangaforfree title known to hold 157 chapters via the POST.
MFF_URL = (
    "https://mangaforfree.com/manga/milf-hunting-in-another-world-13/ajax/chapters/"
)
MFF_REFERER = "https://mangaforfree.com/manga/milf-hunting-in-another-world-13/"
# A zinmanga-net title (Serim's Golden Rule, 25 chapters) whose API
# is Cloudflare-blocked from datacenter IPs.
ZIN_URL = "https://www.zinmanga.net/api/comics/serims-golden-rule/chapters"
MAX_DETAIL_TESTS = 40


def curl_proxy(ip, port, url, extra=None, timeout=10):
    """GET/POST through an http proxy. Returns the response body, or ''."""
    cmd = ["curl", "-s", "--max-time", str(timeout), "-x", f"http://{ip}:{port}"]
    if extra:
        cmd += extra
    cmd.append(url)
    try:
        r = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout + 5)
        return r.stdout
    except Exception:
        return ""


def test_basic(proxy):
    proto, ip, port = proxy
    out = curl_proxy(ip, port, "https://api.ipify.org?format=json", timeout=10)
    try:
        return bool(json.loads(out).get("ip"))
    except Exception:
        return False


def main():
    try:
        req = urllib.request.Request(
            LIST_URL, headers={"User-Agent": "Mozilla/5.0"}
        )
        with urllib.request.urlopen(req, timeout=30) as resp:
            lines = resp.read().decode("utf-8", "replace").splitlines()
    except Exception as exc:
        print(f"could not download proxy list: {exc}", file=sys.stderr)
        return 1
    proxies = []
    for line in lines:
        line = line.strip()
        if "://" not in line:
            continue
        proto, rest = line.split("://", 1)
        if proto not in ("http", "https"):
            continue
        if ":" not in rest:
            continue
        ip, port = rest.rsplit(":", 1)
        try:
            proxies.append((proto, ip, int(port)))
        except ValueError:
            continue
    print(f"{len(proxies)} http/https proxies in the fresh list", file=sys.stderr)
    with ThreadPoolExecutor(max_workers=30) as ex:
        working = [p for p, ok in zip(proxies, ex.map(test_basic, proxies)) if ok]
    print(f"{len(working)} connect", file=sys.stderr)
    best_both = None
    best_mff = None
    best_zin = None
    for proto, ip, port in working[:MAX_DETAIL_TESTS]:
        mff = curl_proxy(ip, port, MFF_URL, extra=[
            "-H", f"Referer: {MFF_REFERER}",
            "-H", "X-Requested-With: XMLHttpRequest", "-X", "POST",
        ], timeout=20)
        mff_n = mff.count("wp-manga-chapter")
        zin_ok = False
        try:
            d = json.loads(curl_proxy(ip, port, ZIN_URL, timeout=20))
            zin_ok = bool(d.get("total") or len(d.get("data") or []))
        except Exception:
            pass
        url = f"{proto}://{ip}:{port}"
        print(f"{url} mff={mff_n} zin_ok={zin_ok}", file=sys.stderr)
        if mff_n >= 100 and zin_ok:
            best_both = url
            break
        if mff_n >= 100 and best_mff is None:
            best_mff = url
        elif zin_ok and best_zin is None:
            best_zin = url
    best = best_both or best_mff or best_zin
    if not best:
        print("no proxy reaches mangaforfree or zinmanga-net", file=sys.stderr)
        return 1
    print(best)
    return 0


if __name__ == "__main__":
    sys.exit(main())
