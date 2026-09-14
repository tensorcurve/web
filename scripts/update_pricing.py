#!/usr/bin/env python3
"""Refresh the TensorCurve pricing snapshot from Verda's public pricing page.

Reads https://verda.com/pricing, extracts the single-GPU instance table
(hardware, on-demand and spot prices) plus the self-service commitment
discount schedule. Also reads the public on-demand H100 prices published by
Hyperstack and Lambda as comparison points (neither publishes a term
discount schedule, so no curve is derived for them). Writes:

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

# Comparison providers: on-demand (and, where published, "reserved from") prices only.
HYPERSTACK_URL = "https://www.hyperstack.cloud/gpu-pricing"
LAMBDA_URL = "https://lambda.ai/instances"
TOGETHER_URL = "https://www.together.ai/pricing"
AZURE_SKU = "Standard_ND96isr_H100_v5"
AZURE_REGION = "eastus"
AZURE_URL = ("https://prices.azure.com/api/retail/prices?$filter=armSkuName%20eq%20%27" + AZURE_SKU
             + "%27%20and%20armRegionName%20eq%20%27" + AZURE_REGION + "%27&currencyCode=USD")
AZURE_GPUS_PER_VM = 8
HOURS_PER_YEAR = 8760
COMPARISON_GPUS = {  # our key -> provider row label
    "hyperstack": {"H100": "NVIDIA H100 SXM", "H200": "NVIDIA H200 SXM", "A100": "NVIDIA A100 SXM"},
    "lambda": {"H100": "NVIDIA H100 SXM", "A100": "NVIDIA A100 SXM"},
}


def fetch(url: str) -> str:
    req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT, "Accept": "text/html, application/json"})
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


def strip_tags(fragment: str) -> str:
    return re.sub(r"\s+", " ", html.unescape(re.sub(r"<[^>]+>", " ", fragment))).strip()


def parse_hyperstack(page: str) -> dict:
    """Hyperstack publishes per-GPU on-demand VM prices and a 'starting from' reserved price."""
    rows = []
    for m in re.finditer(r'<div class="page-price_card_row_item df">(.*?)(?=<div class="page-price_card_row_item df">|<div class="page-price_card_row_item |</div>\s*</div>\s*</div>)', page, re.S):
        cols = [strip_tags(c) for c in re.findall(r'<div class="page-price_card_row_item_col[^"]*">(.*?)</div>', m.group(1), re.S)]
        if cols:
            rows.append(cols)
    on_demand = {r[0]: r for r in rows if len(r) >= 5 and r[4].startswith("$")}
    reserved = {r[0]: r for r in rows if len(r) >= 2 and r[1].lstrip("\xa0 ").startswith("$") and (len(r) < 5 or not r[4].startswith("$"))}
    gpus = {}
    for key, label in COMPARISON_GPUS["hyperstack"].items():
        if label not in on_demand:
            raise RuntimeError(f"Hyperstack: {label} not found in on-demand table")
        row = on_demand[label]
        entry = {
            "model": label.replace("NVIDIA ", ""),
            "vram_gb": int(row[1]),
            "max_cpus_per_gpu": int(row[2]),
            "max_ram_gb_per_gpu": int(row[3]),
            "on_demand_usd": fmt(money(row[4]), 2),
            "reserved_from_usd": fmt(money(reserved[label][1]), 2) if label in reserved else None,
            "reserved_term": "not published (starting-from price)",
            "term_rates": {},
        }
        gpus[key] = entry
    return {
        "name": "Hyperstack",
        "source_url": HYPERSTACK_URL,
        "unit": "USD per GPU-hour, on-demand VM, billed per minute",
        "classification": "published_on_demand_and_reserved_from",
        "term_schedule_published": False,
        "gpus": gpus,
    }


def parse_lambda(page: str) -> dict:
    """Lambda publishes per-GPU on-demand instance prices by instance size (8x, 4x, 2x, 1x tabs)."""
    body = page[page.find("<body"):] if "<body" in page else page
    tab_labels = re.findall(r'<button[^>]*role="tab"[^>]*>\s*([0-9]+x)\s*<', body)
    tbls = tables(body)
    price_tables = [t for t in tbls if t and t[0] and t[0][0].lower() == "plan" and any("PRICE" in h.upper() for h in t[0])]
    if not price_tables:
        raise RuntimeError("Lambda: instance price tables not found")
    sizes = tab_labels[: len(price_tables)] if len(tab_labels) >= len(price_tables) else [f"{n}x" for n in (8, 4, 2, 1)][: len(price_tables)]
    gpus = {}
    for key, label in COMPARISON_GPUS["lambda"].items():
        by_size = {}
        specs = None
        for size, t in zip(sizes, price_tables):
            for r in t[1:]:
                if r and r[0] == label and len(r) >= 6:
                    by_size[size] = fmt(money(r[5]), 2)
                    if size == "1x":
                        specs = r
        if "1x" not in by_size:
            raise RuntimeError(f"Lambda: 1x {label} price not found; sizes seen {list(by_size)}")
        gpus[key] = {
            "model": label.replace("NVIDIA ", ""),
            "vram_gb": gb(specs[1]) if specs else None,
            "vcpus": int(specs[2]) if specs else None,
            "on_demand_usd": by_size["1x"],
            "on_demand_usd_by_instance_size": by_size,
            "term_rates": {},
            "reserved_from_usd": None,
            "reserved_term": "not published (contact sales)",
        }
    return {
        "name": "Lambda",
        "source_url": LAMBDA_URL,
        "unit": "USD per GPU-hour, on-demand instance, price shown for a 1-GPU instance; larger instances listed separately",
        "classification": "published_on_demand",
        "term_schedule_published": False,
        "gpus": gpus,
    }


def parse_together(page: str) -> dict:
    """Together AI publishes per-GPU on-demand and reserved prices by contract duration bucket."""
    body = page[page.find("<body"):] if "<body" in page else page
    rows, buckets, head = [], [], []
    for m in re.finditer(r'<table[^>]*class="[^"]*pricing_table is-gpu[^"]*"[^>]*>(.*?)</table>', body, re.S):
        rows = [cells(r.group(1)) for r in re.finditer(r"<tr[^>]*>(.*?)</tr>", m.group(1), re.S)]
        head = [c for r in rows[:2] for c in r]
        buckets = [h for h in head if re.match(r"[0-9]+(-[0-9]+)?\+?\s*days", h)]
        if len(buckets) >= 3:
            break
    if len(buckets) < 3:
        raise RuntimeError(f"Together: reserved-duration table not found; last header {head}")
    bucket_to_months = {}
    for b in buckets:
        hi = re.match(r"([0-9]+)-([0-9]+)\s*days", b)
        if hi:
            bucket_to_months[b] = str(round(int(hi.group(2)) / 30))
    gpus = {}
    for key, label in (("H100", "NVIDIA HGX H100"), ("H200", "NVIDIA HGX H200")):
        row = next((r for r in rows if r and re.sub(r"\s+", " ", r[0]) == label and len(r) >= 3 + len(buckets)), None)
        if row is None:
            raise RuntimeError(f"Together: {label} row not found")
        vals = row[1:]
        term_rates, term_buckets = {}, {}
        for b, v in zip(buckets, vals[2:]):
            months = bucket_to_months.get(b)
            if months and v.startswith("$"):
                term_rates[months] = fmt(money(v), 2)
                term_buckets[months] = b
        gpus[key] = {
            "model": label.replace("NVIDIA ", ""),
            "preemptible_usd": fmt(money(vals[0]), 2) if vals[0].startswith("$") else None,
            "on_demand_usd": fmt(money(vals[1]), 2),
            "term_rates": term_rates,
            "term_buckets": term_buckets,
            "reserved_from_usd": None,
            "reserved_term": "duration buckets as published; longer terms on request",
        }
    return {
        "name": "Together AI",
        "source_url": TOGETHER_URL,
        "unit": "USD per GPU-hour, HGX H100 (8-GPU node pricing quoted per GPU)",
        "classification": "published_reserved_by_duration",
        "term_schedule_published": True,
        "tier": "self-service",
        "gpus": gpus,
    }


def parse_azure(raw: str) -> dict:
    """Azure retail prices API: pay-as-you-go and reservation totals for the ND H100 v5 VM, normalised per GPU-hour."""
    items = json.loads(raw).get("Items", [])
    linux = [i for i in items if "Win" not in i.get("productName", "") and i.get("type") in ("Consumption", "Reservation")]
    payg = next((i for i in linux if i["type"] == "Consumption" and i["meterName"] == "ND96isrH100v5"), None)
    spot = next((i for i in linux if i["type"] == "Consumption" and i["meterName"].endswith("Spot")), None)
    if payg is None:
        raise RuntimeError("Azure: pay-as-you-go meter not found")
    per_gpu = lambda vm_hourly: Decimal(str(vm_hourly)) / AZURE_GPUS_PER_VM  # noqa: E731
    term_rates = {}
    for i in linux:
        if i["type"] != "Reservation":
            continue
        years = re.match(r"([0-9]+)\s*Year", i.get("reservationTerm", ""))
        if not years:
            continue
        yrs = int(years.group(1))
        hourly_vm = Decimal(str(i["retailPrice"])) / (HOURS_PER_YEAR * yrs)
        term_rates[str(12 * yrs)] = fmt(per_gpu(hourly_vm), 4)
    if "12" not in term_rates:
        raise RuntimeError("Azure: 1-year reservation not found")
    return {
        "name": "Microsoft Azure",
        "source_url": "https://azure.microsoft.com/en-us/pricing/details/virtual-machines/linux/",
        "api_url": AZURE_URL,
        "unit": f"USD per GPU-hour, {AZURE_SKU} ({AZURE_GPUS_PER_VM}x H100) in {AZURE_REGION}, Linux; VM price divided by {AZURE_GPUS_PER_VM}; reservation total divided by term hours",
        "classification": "published_reserved_by_term_hyperscaler",
        "term_schedule_published": True,
        "tier": "hyperscaler",
        "gpus": {"H100": {
            "model": "ND96isr H100 v5 (8x H100 SXM)",
            "gpu_count": AZURE_GPUS_PER_VM,
            "on_demand_usd": fmt(per_gpu(payg["retailPrice"]), 4),
            "spot_usd": fmt(per_gpu(spot["retailPrice"]), 4) if spot else None,
            "term_rates": dict(sorted(term_rates.items(), key=lambda kv: int(kv[0]))),
            "reserved_from_usd": None,
            "reserved_term": "1, 3 and 5 year reservations as published",
        }},
    }


PROVIDERS = (
    ("hyperstack", HYPERSTACK_URL, parse_hyperstack),
    ("lambda", LAMBDA_URL, parse_lambda),
    ("together", TOGETHER_URL, parse_together),
    ("azure", AZURE_URL, parse_azure),
)


def collect_comparisons(previous: dict | None, now: dt.datetime, saved: dict | None = None) -> dict:
    """Fetch comparison providers independently; a failure keeps that provider's previous entry."""
    out = {}
    prev = (previous or {}).get("providers", {}) if previous else {}
    for key, url, parser in PROVIDERS:
        try:
            page = saved[key] if saved and key in saved else fetch(url)
            entry = parser(page)
            entry.setdefault("tier", "self-service")
            for g in entry["gpus"].values():
                price = Decimal(g["on_demand_usd"])
                if not (PRICE_MIN <= price <= PRICE_MAX):
                    raise RuntimeError(f"{key}: on-demand {price} outside sanity bounds")
                rates = [Decimal(v) for v in (g.get("term_rates") or {}).values()]
                if any(r <= 0 or r >= price for r in rates):
                    raise RuntimeError(f"{key}: term rates must be positive and below on-demand: {rates}")
            entry["checked_on"] = now.date().isoformat()
            entry["status"] = "ok"
            entry["source_sha256"] = hashlib.sha256(page.encode("utf-8")).hexdigest()
            out[key] = entry
        except Exception as exc:  # noqa: BLE001 - a comparison source must never block the main refresh
            print(f"WARNING: {key} refresh failed: {exc}", file=sys.stderr)
            if key in prev:
                out[key] = dict(prev[key], status=f"stale: {type(exc).__name__}")
    return out


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


def build_snapshot(parsed: dict, page: str, now: dt.datetime, previous: dict | None, providers: dict | None = None) -> dict:
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
            "providers: comparison on-demand prices from other suppliers; they publish no term-discount schedule, so no term curve is derived for them.",
        ],
        "collected_at": now.isoformat(timespec="seconds"),
        "source_sha256": hashlib.sha256(page.encode("utf-8")).hexdigest(),
        "review_status": "automated extraction; validated against sanity bounds",
        "previous_checked_on": previous.get("checked_on") if previous else None,
        "providers": providers or {},
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
    for pkey, prov in snap.get("providers", {}).items():
        if not str(prov.get("status", "")).startswith("ok"):
            continue
        for gkey, g in prov["gpus"].items():
            existing.append([snap["checked_on"], prov["name"], gkey, "on-demand", g["on_demand_usd"], "", "", g["on_demand_usd"]])
            if g.get("reserved_from_usd"):
                existing.append([snap["checked_on"], prov["name"], gkey, "reserved-from", g["on_demand_usd"], "", "", g["reserved_from_usd"]])
            for months, rate in (g.get("term_rates") or {}).items():
                existing.append([snap["checked_on"], prov["name"], gkey, months, g["on_demand_usd"], g.get("spot_usd") or "", "", rate])
    order = {"on-demand": 0, "reserved-from": 1}
    existing.sort(key=lambda r: (r[0], r[1], r[2], order.get(r[3], 10 + int(r[3]) if r[3].isdigit() else 99)))
    with HISTORY_PATH.open("w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(header)
        w.writerows(existing)


def main(argv: list[str]) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--check", action="store_true", help="validate and print, do not write")
    ap.add_argument("--html", type=Path, help="parse a saved HTML file instead of fetching")
    ap.add_argument("--hyperstack-html", type=Path, help="parse a saved Hyperstack page instead of fetching")
    ap.add_argument("--lambda-html", type=Path, help="parse a saved Lambda page instead of fetching")
    ap.add_argument("--together-html", type=Path, help="parse a saved Together page instead of fetching")
    ap.add_argument("--azure-json", type=Path, help="parse a saved Azure API response instead of fetching")
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
    saved = {k: pth.read_text(encoding="utf-8") for k, pth in (("hyperstack", args.hyperstack_html), ("lambda", args.lambda_html), ("together", args.together_html), ("azure", args.azure_json)) if pth}
    providers = collect_comparisons(previous, now, saved)
    snap = build_snapshot(parsed, page, now, previous, providers)
    for key, prov in providers.items():
        g = prov["gpus"].get("H100", {})
        print(f"{prov['name']}: H100 on-demand {g.get('on_demand_usd')} reserved-from {g.get('reserved_from_usd')} terms {g.get('term_rates')} [{prov.get('status')}]")
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
