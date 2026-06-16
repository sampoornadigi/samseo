=== Sampoorna SEO ===
Contributors: lsnsoft
Tags: seo, google search console, performance, indexing, url inspection
Requires at least: 6.0
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later

Agency SEO engine for Sampoorna Digi Branding. The current seed module connects Google Search Console via OAuth 2.0, tracks performance, surfaces on-page / indexing issues, gives rule-based fix suggestions, and sends email digests — all inside wp-admin.

== Description ==

Sampoorna SEO is a per-site WordPress SEO engine (and, in later phases, a central agency control plane). This release ships the **Google Search Console integration** seed module under the `Sampoorna\SEO\` namespace.

Performance:

* OAuth 2.0 connection to Google Search Console (refresh token stored encrypted).
* Property selection; daily performance sync (clicks, impressions, CTR, position) via WP-Cron plus "Sync now".
* Dashboard KPIs with trend arrows, a clicks/impressions chart, and per-page click-drop detection.
* Performance screen: top pages and top queries (28 days).

On-page / indexing issues:

* Throttled URL Inspection crawler under Google's 2,000/day limit (1,900 safety cap) with backoff.
* Typed issues — indexing, canonical mismatch, mobile usability, structured-data — that auto-resolve when fixed.
* Issues screen with filters, bulk actions, details, and a scan-progress bar; re-inspects on save and on a ~21-day cadence.

Fix suggestions:

* Deterministic suggestion engine from issues, title/meta length checks, and low-CTR pages with top queries.
* Suggestions screen: current vs suggested, priority, recommendation, filters, bulk apply/dismiss/reset, post Edit links. No automatic edits.

Digests & export:

* Email digest (weekly or daily) of KPIs vs prior period, top click drops, open issue counts, and new suggestion counts — sent to a configurable recipient via WP-Cron, with a "Send test digest now" button.
* CSV export on the Performance (top pages / top queries), Issues, and Suggestions screens.

== Setup ==

1. Create a Google Cloud project and enable the **Search Console API**.
2. Configure the OAuth consent screen and add the scope
   `https://www.googleapis.com/auth/webmasters.readonly`.
3. Create an **OAuth client ID -> Web application**. Set the Authorized redirect URI
   to the value shown on the plugin's Settings screen
   (`.../wp-admin/admin-post.php?action=sampoorna_seo_oauth_callback`).
4. Paste the Client ID and Secret into Settings, save, then click **Connect**.
5. Pick a property, then go to the Dashboard and click **Sync now**.
6. On Issues, click **Build / refresh queue** to begin inspecting URLs.
7. On Suggestions, click **Generate suggestions**.
8. Optionally enable the email digest under Settings → Email digest.

== Notes & limits ==

* Google's performance data lags ~2-3 days; the plugin never requests "today".
* The URL Inspection API is limited to 2,000 inspections/day per property.
* There is no bulk "issues" export in the API; the Issues view is rebuilt URL-by-URL.
* Email delivery depends on your site being able to send mail (consider an SMTP plugin).
* WP-Cron timing depends on site traffic; use a real system cron for reliable digests.
* Uses only the WordPress HTTP API — no Composer/vendor runtime dependencies (PSR-4 autoloaded).

== Changelog ==

= 0.1.0 =
* Initial Sampoorna SEO release: Google Search Console seed module migrated to the `Sampoorna\SEO\` namespace with PSR-4 autoloading and the modular `includes/` layout. Carries forward the full GSC feature set (performance sync, URL-inspection crawler, typed issues, rule-based suggestions, email digests, CSV export) from WP Search Console Manager 1.3.0.
