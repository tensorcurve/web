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
