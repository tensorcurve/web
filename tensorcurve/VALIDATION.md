# Validation — TensorCurve 1.0.0

Validated locally on WordPress 7.1 and PHP 8.3.6. The isolated test instance used the WordPress SQLite Database Integration drop-in; the distributed theme itself does not require SQLite or contain any test database. No Namecheap/EasyWP production account was accessed or changed.

Passed:
- PHP syntax checks for all theme PHP files.
- Recognition and activation as a valid WordPress theme.
- Installation of the distributable ZIP through WordPress Theme_Upgrader.
- Optional setup creates the expected page templates and imports four sample posts as drafts.
- Repeated setup does not duplicate those pages/posts or overwrite edited page content.
- Existing search-visibility setting remains unchanged; advertising is off by default.
- Publisher and ad slot input validation rejects malformed values.
- Empty-content homepage and CSS/JavaScript asset loading.
- Publishing posts updates homepage cards and the featured article.
- Article heading structure, generated H2 table of contents and anchor navigation.
- One article ad placeholder even with a shortcode and fallback placement.
- WordPress canonical output and Article JSON-LD.
- Category archive, search, guide pagination and WordPress 404 response/template.
- Pricing Lab's initial rate, GPU/region/start/term updates and clickable price matrix.
- Headless Chromium desktop and 390px mobile checks, including no document-width overflow on home, article and Pricing Lab. Desktop home and mobile article screenshots visually inspected.

Not certified by these checks:
- Google AdSense approval, live ad delivery, ad revenue or search ranking.
- Production consent configuration, privacy-policy completeness or ads.txt ownership.
- Real GPU data collection or quote accuracy (the lab is synthetic).
- Load capacity or performance on the selected hosting account, or compatibility with every third-party plugin.

Before public launch, complete the real content and operator information, then verify HTTPS, caching, backup/restore and ad/consent settings on the chosen host.


## Version 1.1.0 — sourced-content update
- Three GPU configurations × four terms validated against extracted official Verda HTML.
- All 36 future-start combinations remain missing; no synthetic premiums or provider rates remain in the lab.
- PHP syntax checks passed for all theme templates.
- Existing local WordPress runtime loaded the new chart and data successfully.
- Browser verification: data-script ordering, H100/H200 filters, missing future rates, and mobile width at 390px passed with no page errors.
- Four English article drafts and the new methodology are included. Import does not replace previously edited posts.
- Snapshot dated 2026-09-13; automatic refresh is not enabled.


## Version 1.2.0 — automatic daily refresh
- `scripts/update_pricing.py` extracts the Verda GPU instance table (hardware, on-demand, spot), the schema.org on-demand offers and the commitment discount table. Verified against the live page on 2026-09-14: H100 3.25 and H200 4.20 match the 1.1.0 snapshot exactly; A100 now uses the machine-readable 1.736 (displayed 1.74), with the displayed figure recorded as `base_usd_display`.
- Validation gate: all three configurations present, base in [0.10, 50] USD, term rates below base and decreasing, discounts increasing, no base move above 50% between refreshes. A failing extraction exits non-zero and writes nothing.
- `inc/pricing-data.php` tested with a stubbed WordPress runtime: fresh feed accepted and cached 6 h; feed older than the bundled file ignored; failed fetch falls back to the last good copy, then the bundled file, with a 30 min retry cache; automatic refresh off uses only the bundled file.
- `template-parts/curve.php` regenerated from data reproduces the 1.1.0 SVG geometry for identical inputs (same axis range, point coordinates and labels).
- PHP syntax checks passed for all theme files (PHP 8.4).
- Not certified: GitHub Actions scheduling delays (cron runs can be late by minutes to an hour), Verda page redesigns (the job fails safe and keeps the previous snapshot), and host-level HTTP egress restrictions that would block the theme's feed fetch (the theme then shows the bundled snapshot).


## Version 1.2.1 — mobile page margins
- Fixed: on screens up to 760px wide, `.info-page` (About, Data methodology, Contact, 404 and other single pages) rendered flush to the screen edge because its `margin:auto` rule outranked the 20px side margins applied to `main`. The mobile rule now sets the same 20px side margins explicitly. Verified with a 390px headless Chromium render: home, About and Data methodology all show 20px side gutters and no horizontal overflow.
