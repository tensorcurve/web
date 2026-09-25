# TensorCurve

Source of the WordPress theme that powers [tensorcurve.com](https://www.tensorcurve.com/), an English editorial site about GPU rental economics.

- **Theme**: `tensorcurve/` — classic WordPress theme, version 1.5.2
- **Requires**: WordPress 6.5+, PHP 8.0+ (validated on WordPress 7.1 / PHP 8.3)
- **License**: GPL-2.0-or-later ([LICENSE.txt](tensorcurve/LICENSE.txt))

## What the theme provides

- Dynamic homepage with a featured article, category cards and guide listings
- Article pages with author metadata, related posts and an auto-generated table of contents
- **Pricing Lab**: a browser-side view of published GPU tariffs (H100 / H200 / A100 across 1, 3, 6 and 12 month terms), refreshed daily (see below)
- **Price tracker pages** (`/h100-pricing/`, `/h200-pricing/`, `/a100-pricing/`): per-GPU supplier table, commitment curves and daily on-demand history, with Dataset structured data and JSON/CSV downloads
- Data methodology page describing the source, check date and calculation rule
- Optional AdSense slots, off by default; no consent platform, ads.txt or tracking code bundled
- Optional setup screen (Appearance → TensorCurve Setup) that creates pages and imports four sample articles as drafts

No paid page builder or required plugin.

## Daily pricing refresh

A GitHub Actions workflow (`.github/workflows/update-pricing.yml`) runs `scripts/update_pricing.py` once a day. The script reads Verda's public pricing page, extracts the tracked single-GPU configurations and the commitment discount schedule, validates the result, and commits the updated snapshot to `main`. It also collects H100 comparison prices from other suppliers, each independently so one failure never blocks the rest: Together AI (published reserved rates by duration bucket), Hyperstack and Lambda (on-demand only), and Microsoft Azure (public retail-price API, pay-as-you-go and 1/3/5-year reservations normalised per GPU-hour):

- `tensorcurve/assets/data/pricing-snapshot.json` — current snapshot (the theme's feed)
- `tensorcurve/assets/data/pricing-snapshot.csv` — same data, flat
- `tensorcurve/assets/data/pricing-history.csv` — one row per GPU × term per day, appended over time
- `tensorcurve/assets/pricing-data.js` — static JS export

The theme fetches the JSON snapshot from this repository, caches it for six hours, and falls back to the last good copy or the bundled file if the fetch fails. The feed URL and the on/off switch live in Appearance → Customize → TensorCurve — Pricing data. Run the job by hand from the Actions tab (workflow_dispatch) or locally:

```
python3 scripts/update_pricing.py --check   # fetch and validate only
python3 scripts/update_pricing.py           # write the data files
```

## Install

1. Zip the `tensorcurve/` directory (or download a release zip).
2. WordPress admin → Appearance → Themes → Add New → Upload Theme.
3. Activate, then open Appearance → TensorCurve Setup.

Full Korean instructions: [tensorcurve/INSTALL-KO.md](tensorcurve/INSTALL-KO.md).
Validation record: [tensorcurve/VALIDATION.md](tensorcurve/VALIDATION.md).

## Layout

```
tensorcurve/
├── functions.php, header.php, footer.php, index.php, single.php, page*.php
├── front-page.php          # homepage
├── page-pricing.php        # Pricing Lab
├── inc/                    # setup screen, customizer, ads, sample content, methodology
├── template-parts/         # card, curve, mini-table
├── assets/                 # CSS, JS, favicon, pricing snapshot data
└── style.css, theme.json, screenshot.png
scripts/update_pricing.py   # daily extractor
.github/workflows/          # scheduled refresh
```
