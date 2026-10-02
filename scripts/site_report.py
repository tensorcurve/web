#!/usr/bin/env python3
"""Weekly traffic and search report for tensorcurve.com.

Reads Google Analytics 4 (Data API) and Search Console (Search Analytics API)
with a read-only service account. Standard library only; the service-account
token is signed with the system `openssl` command.

Environment variables (never commit their values):
  GA_SA_JSON      service-account key: the JSON file content, or its base64
  GA_PROPERTY_ID  GA4 property ID (digits, from Admin > Property details)
  GSC_SITE        Search Console property, default "https://tensorcurve.com/"
                  (use "sc-domain:tensorcurve.com" for a domain property)

Usage:
  python3 scripts/site_report.py              # last 28 days vs previous 28
  python3 scripts/site_report.py --days 7
  python3 scripts/site_report.py --json out.json
"""
from __future__ import annotations

import argparse
import base64
import datetime as dt
import json
import os
import sys
import time
import urllib.error
import urllib.parse
import subprocess
import tempfile
import urllib.request

SCOPES = "https://www.googleapis.com/auth/analytics.readonly https://www.googleapis.com/auth/webmasters.readonly"
TOKEN_URL = "https://oauth2.googleapis.com/token"


def load_key() -> dict:
    raw = os.environ.get("GA_SA_JSON", "").strip()
    if not raw:
        sys.exit("GA_SA_JSON is not set. Add the service-account key to the environment settings.")
    if not raw.startswith("{"):
        raw = base64.b64decode(raw).decode("utf-8")
    key = json.loads(raw)
    for field in ("client_email", "private_key", "token_uri"):
        if field not in key:
            sys.exit(f"GA_SA_JSON is missing '{field}'; use the JSON key file downloaded from Google Cloud.")
    return key


def _b64url(data: bytes) -> str:
    return base64.urlsafe_b64encode(data).rstrip(b"=").decode()


def access_token(key: dict) -> str:
    now = int(time.time())
    header = {"alg": "RS256", "typ": "JWT", "kid": key.get("private_key_id")}
    claims = {"iss": key["client_email"], "scope": SCOPES, "aud": key.get("token_uri", TOKEN_URL), "iat": now, "exp": now + 3600}
    signing_input = _b64url(json.dumps(header, separators=(",", ":")).encode()) + "." + _b64url(json.dumps(claims, separators=(",", ":")).encode())
    with tempfile.NamedTemporaryFile("w", suffix=".pem", delete=True) as kf:
        kf.write(key["private_key"])
        kf.flush()
        os.chmod(kf.name, 0o600)
        sig = subprocess.run(["openssl", "dgst", "-sha256", "-sign", kf.name], input=signing_input.encode(), capture_output=True, check=True).stdout
    assertion = signing_input + "." + _b64url(sig)
    body = urllib.parse.urlencode({"grant_type": "urn:ietf:params:oauth:grant-type:jwt-bearer", "assertion": assertion}).encode()
    return _post(key.get("token_uri", TOKEN_URL), body, {"Content-Type": "application/x-www-form-urlencoded"})["access_token"]


def _post(url: str, body: bytes, headers: dict) -> dict:
    req = urllib.request.Request(url, data=body, headers=headers, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            return json.loads(r.read())
    except urllib.error.HTTPError as e:
        detail = e.read().decode("utf-8", "replace")
        raise SystemExit(f"{url} -> HTTP {e.code}: {detail[:600]}")


def api(token: str, url: str, payload: dict) -> dict:
    return _post(url, json.dumps(payload).encode(), {"Authorization": f"Bearer {token}", "Content-Type": "application/json"})


# ---------------------------------------------------------------- Analytics
def ga_report(token: str, prop: str, days: int) -> dict:
    url = f"https://analyticsdata.googleapis.com/v1beta/properties/{prop}:runReport"
    cur = {"startDate": f"{days}daysAgo", "endDate": "yesterday", "name": "current"}
    prev = {"startDate": f"{2 * days}daysAgo", "endDate": f"{days + 1}daysAgo", "name": "previous"}
    metrics = [{"name": m} for m in ("activeUsers", "newUsers", "sessions", "screenPageViews", "averageSessionDuration", "engagementRate")]

    def rows(resp, dims):
        out = []
        for r in resp.get("rows", []):
            d = {h["name"]: v["value"] for h, v in zip(resp.get("dimensionHeaders", []), r.get("dimensionValues", []))}
            d.update({h["name"]: v["value"] for h, v in zip(resp.get("metricHeaders", []), r.get("metricValues", []))})
            out.append(d)
        return out

    totals = api(token, url, {"dateRanges": [cur, prev], "metrics": metrics})
    daily = api(token, url, {"dateRanges": [cur], "dimensions": [{"name": "date"}], "metrics": [{"name": "activeUsers"}, {"name": "sessions"}, {"name": "screenPageViews"}], "orderBys": [{"dimension": {"dimensionName": "date"}}]})
    pages = api(token, url, {"dateRanges": [cur], "dimensions": [{"name": "pagePath"}], "metrics": [{"name": "screenPageViews"}, {"name": "activeUsers"}], "orderBys": [{"metric": {"metricName": "screenPageViews"}, "desc": True}], "limit": 15})
    channels = api(token, url, {"dateRanges": [cur], "dimensions": [{"name": "sessionDefaultChannelGroup"}], "metrics": [{"name": "sessions"}, {"name": "activeUsers"}], "orderBys": [{"metric": {"metricName": "sessions"}, "desc": True}]})
    sources = api(token, url, {"dateRanges": [cur], "dimensions": [{"name": "sessionSource"}], "metrics": [{"name": "sessions"}], "orderBys": [{"metric": {"metricName": "sessions"}, "desc": True}], "limit": 10})
    countries = api(token, url, {"dateRanges": [cur], "dimensions": [{"name": "country"}], "metrics": [{"name": "activeUsers"}], "orderBys": [{"metric": {"metricName": "activeUsers"}, "desc": True}], "limit": 10})
    devices = api(token, url, {"dateRanges": [cur], "dimensions": [{"name": "deviceCategory"}], "metrics": [{"name": "activeUsers"}]})
    return {"totals": rows(totals, ["dateRange"]), "daily": rows(daily, ["date"]), "pages": rows(pages, ["pagePath"]),
            "channels": rows(channels, []), "sources": rows(sources, []), "countries": rows(countries, []), "devices": rows(devices, [])}


# ----------------------------------------------------------- Search Console
def gsc_report(token: str, site: str, days: int) -> dict:
    url = f"https://searchconsole.googleapis.com/webmasters/v3/sites/{urllib.parse.quote(site, safe='')}/searchAnalytics/query"
    end = dt.date.today() - dt.timedelta(days=3)  # Search Console lags about 2-3 days
    start = end - dt.timedelta(days=days - 1)
    pstart, pend = start - dt.timedelta(days=days), start - dt.timedelta(days=1)

    def q(s, e, dims, limit=25):
        return api(token, url, {"startDate": s.isoformat(), "endDate": e.isoformat(), "dimensions": dims, "rowLimit": limit}).get("rows", [])

    return {"range": [start.isoformat(), end.isoformat()], "previous_range": [pstart.isoformat(), pend.isoformat()],
            "totals": q(start, end, [], 1), "previous_totals": q(pstart, pend, [], 1),
            "queries": q(start, end, ["query"], 30), "pages": q(start, end, ["page"], 20)}


# ------------------------------------------------------------------ output
def pct(a: float, b: float) -> str:
    return "n/a" if not b else f"{(a - b) / b * 100:+.0f}%"


def print_report(ga: dict | None, gsc: dict | None, days: int) -> None:
    if ga:
        print(f"## Google Analytics, last {days} days (vs previous {days})")
        t = {r.get("dateRange", f"r{i}"): r for i, r in enumerate(ga["totals"])}
        c, p = t.get("current", {}), t.get("previous", {})
        for m, label in (("activeUsers", "Users"), ("newUsers", "New users"), ("sessions", "Sessions"), ("screenPageViews", "Page views")):
            cv, pv = float(c.get(m, 0)), float(p.get(m, 0))
            print(f"  {label:12} {cv:>8.0f}   prev {pv:>6.0f}   {pct(cv, pv)}")
        if c:
            print(f"  Avg session  {float(c.get('averageSessionDuration', 0)):>7.0f}s   Engagement {float(c.get('engagementRate', 0)) * 100:.0f}%")
        print("  Channels:  " + ", ".join(f"{r['sessionDefaultChannelGroup']} {r['sessions']}" for r in ga["channels"]))
        print("  Sources:   " + ", ".join(f"{r['sessionSource']} {r['sessions']}" for r in ga["sources"]))
        print("  Countries: " + ", ".join(f"{r['country']} {r['activeUsers']}" for r in ga["countries"]))
        print("  Devices:   " + ", ".join(f"{r['deviceCategory']} {r['activeUsers']}" for r in ga["devices"]))
        print("  Top pages:")
        for r in ga["pages"]:
            print(f"    {r['screenPageViews']:>5} views  {r['activeUsers']:>4} users  {r['pagePath']}")
        print("  Daily users: " + " ".join(f"{r['date'][4:6]}/{r['date'][6:]}:{r['activeUsers']}" for r in ga["daily"]))
    if gsc:
        print(f"\n## Search Console {gsc['range'][0]} to {gsc['range'][1]} (vs {gsc['previous_range'][0]} to {gsc['previous_range'][1]})")
        c = (gsc["totals"] or [{}])[0]
        p = (gsc["previous_totals"] or [{}])[0]
        print(f"  Clicks {c.get('clicks', 0):.0f} (prev {p.get('clicks', 0):.0f})  Impressions {c.get('impressions', 0):.0f} (prev {p.get('impressions', 0):.0f})"
              f"  CTR {c.get('ctr', 0) * 100:.1f}%  Avg position {c.get('position', 0):.1f} (prev {p.get('position', 0):.1f})")
        print("  Queries (clicks / impressions / position):")
        for r in gsc["queries"]:
            print(f"    {r['clicks']:>3.0f} {r['impressions']:>5.0f} {r['position']:>6.1f}  {r['keys'][0]}")
        print("  Pages:")
        for r in gsc["pages"]:
            print(f"    {r['clicks']:>3.0f} {r['impressions']:>5.0f} {r['position']:>6.1f}  {r['keys'][0]}")


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--days", type=int, default=28)
    ap.add_argument("--json", help="also write the raw report to this file")
    ap.add_argument("--skip-ga", action="store_true")
    ap.add_argument("--skip-gsc", action="store_true")
    args = ap.parse_args()
    key = load_key()
    token = access_token(key)
    print(f"Authenticated as {key['client_email']}")
    ga = gsc = None
    if not args.skip_ga:
        prop = os.environ.get("GA_PROPERTY_ID", "").strip().removeprefix("properties/")
        if not prop.isdigit():
            print("GA_PROPERTY_ID is not set to a numeric GA4 property ID; skipping Analytics.", file=sys.stderr)
        else:
            ga = ga_report(token, prop, args.days)
    if not args.skip_gsc:
        gsc = gsc_report(token, os.environ.get("GSC_SITE", "https://tensorcurve.com/").strip(), args.days)
    print_report(ga, gsc, args.days)
    if args.json:
        with open(args.json, "w", encoding="utf-8") as f:
            json.dump({"generated_at": dt.datetime.now(dt.timezone.utc).isoformat(), "analytics": ga, "search_console": gsc}, f, indent=2)
    return 0


if __name__ == "__main__":
    sys.exit(main())
