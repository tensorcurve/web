# TensorCurve

Source of the WordPress theme that powers [tensorcurve.com](https://www.tensorcurve.com/), an English editorial site about GPU rental economics.

- **Theme**: `tensorcurve/` — classic WordPress theme, version 1.1.0
- **Requires**: WordPress 6.5+, PHP 8.0+ (validated on WordPress 7.1 / PHP 8.3)
- **License**: GPL-2.0-or-later ([LICENSE.txt](tensorcurve/LICENSE.txt))

## What the theme provides

- Dynamic homepage with a featured article, category cards and guide listings
- Article pages with author metadata, related posts and an auto-generated table of contents
- **Pricing Lab**: a browser-side view of published GPU tariffs (H100 / H200 / A100 across 1, 3, 6 and 12 month terms) with a JSON/CSV snapshot in `tensorcurve/assets/data/`
- Data methodology page describing the source, check date and calculation rule
- Optional AdSense slots, off by default; no consent platform, ads.txt or tracking code bundled
- Optional setup screen (Appearance → TensorCurve Setup) that creates pages and imports four sample articles as drafts

No paid page builder or required plugin. No live GPU price feed is included; the pricing snapshot is a dated manual extract.

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
```
