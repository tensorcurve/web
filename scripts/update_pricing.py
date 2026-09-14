#!/usr/bin/env python3
"""Refresh the TensorCurve pricing snapshot from Verda's public pricing page.

Reads https://verda.com/pricing, extracts the single-GPU instance table
(hardware, on-demand and spot prices) plus the self-service commitment
discount schedule, and writes:

  tensorcurve/assets/data/pricing-snapshot.json
  tensorcurve/assets/data/pricing-snapshot.csv
  tensorcurve/assets/pricing-data.js
  tensorcurve/assets/data/pricing-history.csv   (one row per GPU x term per day)

Only the Python standard library is used. The script refuses to write when
the extracted data fails validation, so a broken source page keeps the last
good snapshot in place.

Usage:
  python3 scripts/update_pricing.py            # fetch and write
  python3 scripts/update_pricing.py --check    # fetch, validate, print, no write
  python3 scripts/update_pricing.py --html f   # parse a saved HTML file
"""
from __future__ import annotations

import argparse
import csv
import datetime as dt
import hashlib
import html
import json
import re
import sys
import urllib.request
from decimal import Decimal, ROUND_HALF_UP
from pathlib import Path

SOURCE_URL = "https://verda.com/pricing"
ROOT = Path(__file__).resolve().parent.parent
THEME = ROOT / "tensorcurve"
JSON_PATH = THEME / "assets" / "data" / "pricing-snapshot.json"
CSV_PATH = THEME / "assets" / "data" / "pricing-snapshot.csv"
JS_PATH = THEME / "assets" / "pricing-data.js"
HISTORY_PATH = THEME / "assets" / "data" / "pricing-history.csv"

# GPU key -> (table row name, tie-breaker on CPU count when the name repeats)
TRACKED = {
    "H100": ("1x H100 SXM5 80GB", 30),
    "H200": ("1x H200 SXM5 141GB", 44),
    "A100": ("1x A100 SXM4 80GB", 22),
}
TERMS = ("1", "3", "6", "12")
# Sanity bounds for a single-GPU hourly on-demand price in USD.
PRICE_MIN, PRICE_MAX = Decimal("0.10"), Decimal("50")
# Refuse a day-over-day base price move larger than this fraction.
MAX_DAILY_MOVE = Decimal("0.50")

USER_AGENT = "TensorCurveBot/1.0 (+https://www.tensorcurve.com/; daily public tariff check)"


def fetch(url: str) -> str:
    req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT, "Accept": "text/html"})
    with urllib.request.urlopen(req, timeout=60) as resp:
        if resp.status != 200:
            raise RuntimeError(f"HTTP {resp.status} from {url}")
        return resp.read().decode("utf-8", errors="replace")


def cells(row_html: str) -> list[str]:
    out = []
    for m in re.finditer(r"<t[dh][^>]*>(.*?)</t[dh]>", row_html, re.S):
        text = html.unescape(re.sub(r"<[^>]+>", " ", m.group(1)))
        out.append(re.sub(r"\s+", " ", text).strip())
    return out


def tables(page: str) -> list[list[list[str]]]:
    body = page[page.find("<body"):] if "<body" in page else page
    result = []
    for t in re.finditer(r"<table[^>]*>(.*?)</table>", body, re.S):
        rows = [cells(r.group(1)) for r in re.finditer(r"<tr[^>]*>(.*?)</tr>", t.group(1), re.S)]
        result.append([r for r in rows if r])
    return result


def money(text: str) -> Decimal:
    m = re.search(r"\$\s*([0-9]+(?:\.[0-9]+)?)", text)
    if not m:
        raise ValueError(f"no price in {text!r}")
    return Decimal(m.group(1))


def gb(text: str) -> int:
    m = re.search(r"([0-9]+)\s*GB", text)
    if not m:
        raise ValueError(f"no GB value in {text!r}")
    return int(m.group(1))


def jsonld_offers(page: str) -> dict[str, Decimal]:
    """Machine-readable on-demand prices: name -> price (more precise than the displayed table)."""
    offers: dict[str, Decimal] = {}
    for m in re.finditer(r'<script[^>]*type="application/ld\+json"[^>]*>(.*?)</script>', page, re.S):
        try:
            data = json.loads(m.group(1))
        except json.JSONDecodeError:
            continue

        def walk(node):
            if isinstance(node, dict):
                if node.get("@type") == "Offer" and str(node.get("name", "")).endswith(" on-demand"):
                    name = node["name"][: -len(" on-demand")].strip()
                    price = node.get("price")
                    if name and price is not None and name not in offers:
                        offers[name] = Decimal(str(price))
                for v in node.values():
                    walk(v)
            elif isinstance(node, list):
                for v in node:
                    walk(v)

        walk(data)
    return offers


def parse(page: str) -> dict:
    all_tables = tables(page)

    # GPU instance table: header starts with Hardware/CPUs/RAM/GPU VRAM/Price/Spot price.
    gpu_rows = None
    for t in all_tables:
        head = [h.lower() for h in t[0]]
        if len(head) >= 6 and "spot" in head[5] and "1x H100 SXM5 80GB" in [r[0] for r in t[1:]]:
            gpu_rows = t[1:]
            break
    if gpu_rows is None:
        raise RuntimeError("GPU instance table not found")

    # Commitment discount table: Commitment / Discount.
    discounts: dict[str, Decimal] = {}
    for t in all_tables:
        head = [h.lower() for h in t[0]]
        if len(head) == 2 and head[0].startswith("commitment") and head[1].startswith("discount"):
            for term, disc in t[1:]:
                n = re.match(r"([0-9]+)\s*(month|year)", term.lower())
                pct = re.match(r"([0-9]+(?:\.[0-9]+)?)\s*%", disc)
                if not n or not pct:
                    continue
                months = int(n.group(1)) * (12 if n.group(2) == "year" else 1)
                discounts[str(months)] = (Decimal(pct.group(1)) / 100).quantize(Decimal("0.0001")).normalize()
            break
    missing = [t for t in TERMS if t not in discounts]
    if missing:
        raise RuntimeError(f"discount schedule incomplete, missing months: {missing}; found {discounts}")

    offers = jsonld_offers(page)
    gpus = {}
    for key, (name, cpus_hint) in TRACKED.items():
        candidates = [r for r in gpu_rows if r[0] == name]
        if not candidates:
            raise RuntimeError(f"{name} not found in GPU instance table")
        row = next((r for r in candidates if r[1].strip() == str(cpus_hint)), candidates[0])
        display_price = money(row[4])
        base = offers.get(name, display_price)
        gpus[key] = {
            "model": name.replace("1x ", "").rsplit(" ", 1)[0],
            "vram_gb": gb(row[3]),
            "gpu_count": 1,
            "cpus": int(row[1]),
            "ram_gb": gb(row[2]),
            "base_usd": fmt(base, 4 if base != base.quantize(Decimal("0.01")) else 2),
            "base_usd_display": fmt(display_price, 2),
            "spot_usd": fmt(money(row[5]), 4),
            "rates": {t: fmt(base * (1 - discounts[t]), 4) for t in TERMS},
        }

    return {
        "discounts": {t: fmt(discounts[t], 2) for t in TERMS},
        "discounts_all": {k: fmt(v, 2) for k, v in sorted(discounts.items(), key=lambda kv: int(kv[0]))},
        "gpus": gpus,
    }


def fmt(value: Decimal, places: int) -> str:
    return str(value.quantize(Decimal(1).scaleb(-places), rounding=ROUND_HALF_UP))


def validate(new: dict, previous: dict | None) -> list[str]:
    problems = []
    for key, g in new["gpus"].items():
        base = Decimal(g["base_usd"])
        if not (PRICE_MIN <= base <= PRICE_MAX):
            problems.append(f"{key}: base {base} outside [{PRICE_MIN}, {PRICE_MAX}]")
        rates = [Decimal(g["rates"][t]) for t in TERMS]
        if any(r >= base for r in rates) or rates != sorted(rates, reverse=True):
            problems.append(f"{key}: term rates must fall below base and decrease with term: {rates}")
        if previous and key in previous.get("gpus", {}):
            old = Decimal(previous["gpus"][key]["base_usd"])
            if old > 0 and abs(base - old) / old > MAX_DAILY_MOVE:
                problems.append(f"{key}: base moved {old} -> {base}, more than {MAX_DAILY_MOVE:%} in one refresh")
    d = [Decimal(new["discounts"][t]) for t in TERMS]
    if d != sorted(d) or d[0] <= 0 or d[-1] >= 1:
        problems.append(f"discount schedule not increasing in (0,1): {d}")
    return problems


def build_snapshot(parsed: dict, page: str, now: dt.datetime, previous: dict | None) -> dict:
    return {
        "schema_version": 2,
        "provider": "Verda",
        "source_url": SOURCE_URL,
        "checked_on": now.date().isoformat(),
        "collection_method": "Automated daily extraction of the public HTML table and schema.org offers",
        "currency": "USD",
        "unit": "per GPU-hour",
        "region": None,
        "start_date": None,
        "availability_verified": False,
        "product_family": "GPU instances / self-service reserved",
        "classification": "calculated_from_published_tariff",
        "discounts": parsed["discounts"],
        "discounts_all": parsed["discounts_all"],
        "gpus": parsed["gpus"],
        "notes": [
            "GPU instances table only; not instant clusters, serverless or spot.",
            "No region or guaranteed start date attached to these extracted rates.",
            "Discount-derived rates are calculations, not separately published quotations.",
            "base_usd is the machine-readable offer price; base_usd_display is the rounded figure shown in the table.",
            "Refreshed automatically once a day from the public source page.",
        ],
        "collected_at": now.isoformat(timespec="seconds"),
        "source_sha256": hashlib.sha256(page.encode("utf-8")).hexdigest(),
        "review_status": "automated extraction; validated against sanity bounds",
        "previous_checked_on": previous.get("checked_on") if previous else None,
    }


def write_outputs(snap: dict) -> None:
    JSON_PATH.write_text(json.dumps(snap, indent=2) + "\n", encoding="utf-8")
    JS_PATH.write_text("window.TENSORCURVE_PRICING = " + json.dumps(snap) + ";\n", encoding="utf-8")
    header = ["provider", "gpu", "vram_gb", "gpu_count", "cpus", "ram_gb", "region", "guaranteed_start",
              "term_months", "base_usd_per_hour", "discount", "calculated_usd_per_gpu_hour",
              "checked_on", "source_url", "classification"]
    rows = []
    for g in snap["gpus"].values():
        for t in TERMS:
            rows.append(["Verda", g["model"], g["vram_gb"], g["gpu_count"], g["cpus"], g["ram_gb"],
                         "not specified", "not verified", t, g["base_usd"], snap["discounts"][t],
                         g["rates"][t], snap["checked_on"], snap["source_url"], snap["classification"]])
    with CSV_PATH.open("w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(header)
        w.writerows(rows)
    append_history(snap)


def append_history(snap: dict) -> None:
    header = ["checked_on", "provider", "gpu", "term_months", "base_usd_per_hour", "spot_usd_per_hour",
              "discount", "calculated_usd_per_gpu_hour"]
    existing = []
    if HISTORY_PATH.exists():
        with HISTORY_PATH.open(newline="", encoding="utf-8") as f:
            existing = [r for r in csv.reader(f)][1:]
    existing = [r for r in existing if r and r[0] != snap["checked_on"]]
    for key, g in snap["gpus"].items():
        for t in TERMS:
            existing.append([snap["checked_on"], "Verda", key, t, g["base_usd"], g.get("spot_usd", ""),
                             snap["discounts"][t], g["rates"][t]])
    existing.sort(key=lambda r: (r[0], r[2], int(r[3])))
    with HISTORY_PATH.open("w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(header)
        w.writerows(existing)


def main(argv: list[str]) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--check", action="store_true", help="validate and print, do not write")
    ap.add_argument("--html", type=Path, help="parse a saved HTML file instead of fetching")
    args = ap.parse_args(argv)

    page = args.html.read_text(encoding="utf-8") if args.html else fetch(SOURCE_URL)
    previous = json.loads(JSON_PATH.read_text(encoding="utf-8")) if JSON_PATH.exists() else None
    parsed = parse(page)
    problems = validate(parsed, previous)
    if problems:
        print("VALIDATION FAILED; snapshot not written:", file=sys.stderr)
        for p in problems:
            print("  -", p, file=sys.stderr)
        return 2

    now = dt.datetime.now(dt.timezone.utc)
    snap = build_snapshot(parsed, page, now, previous)
    for key, g in snap["gpus"].items():
        print(f"{key}: base {g['base_usd']} (shown {g['base_usd_display']}), spot {g['spot_usd']}, "
              f"rates {', '.join(t + 'm=' + g['rates'][t] for t in TERMS)}")
    print("discounts:", snap["discounts"], "| checked_on:", snap["checked_on"])
    if args.check:
        return 0
    write_outputs(snap)
    print("wrote", JSON_PATH.relative_to(ROOT), CSV_PATH.relative_to(ROOT), JS_PATH.relative_to(ROOT), HISTORY_PATH.relative_to(ROOT))
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
