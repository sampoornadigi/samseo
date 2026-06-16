# WP Search Console Manager — Architecture & Technical Spec

A WordPress plugin that connects to Google Search Console (GSC), tracks **performance**, surfaces **on-page / indexing issues**, and generates **auto-fix suggestions** — all inside wp-admin.

- **Auth:** OAuth 2.0 (site admin clicks "Connect", logs into Google, grants access)
- **Target:** WordPress 6.x, PHP 8.1+
- **Scope of v1:** Performance tracking + URL inspection (on-page issues) + fix suggestions

---

## 1. What the GSC API can and cannot do

This shapes the entire design, so it comes first.

| Need | API | Supported? | Key constraint |
|---|---|---|---|
| Performance (clicks, impressions, CTR, position) | Search Analytics API (`searchanalytics.query`) | ✅ Fully | Max 25,000 rows/request; paginate via `startRow`. Data is ~2–3 days delayed. |
| Per-URL indexing & on-page issues | URL Inspection API (`urlInspection.index.inspect`) | ✅ Per-URL only | **2,000 inspections/day and 600/min per property.** No bulk endpoint. |
| Mobile usability, rich-result/schema issues, canonical chosen | URL Inspection API | ✅ | Returned per inspected URL inside the inspection result. |
| Sitemap submission/status | Sitemaps API | ✅ | Useful but secondary. |
| Aggregate "Coverage" / "Enhancements" reports (the charts in the GSC web UI) | — | ❌ **Not exposed** | Must be reconstructed URL-by-URL via URL Inspection. |
| Submit URL for (re)indexing | Indexing API | ⚠️ Officially only for `JobPosting`/`BroadcastEvent` types | Don't rely on it for general pages. |

**The single most important design consequence:** there is no "give me all my site's issues" call. You build that view yourself by inspecting URLs one at a time, throttled under the 2,000/day cap. On a large site, a full scan spans several days, so the architecture is built around an **incremental, queued crawler**.

**OAuth scopes:**

- `https://www.googleapis.com/auth/webmasters.readonly` — performance + inspection (read)
- `https://www.googleapis.com/auth/webmasters` — adds sitemap submit (only if you add write features)

Request the minimum: `webmasters.readonly` covers all of v1.

---

## 2. High-level architecture

```
WordPress (wp-admin)
│
├── Settings: connect Google account (OAuth 2.0)
├── Dashboard: performance + issues overview
├── Performance: trends, drops, query/page tables
├── Issues: per-URL inspection results + status
├── Suggestions: auto-fix recommendations per URL
│
├── WP-Cron schedulers
│     ├── Daily performance sync   → Search Analytics API
│     └── Rolling URL inspection   → URL Inspection API (throttled)
│
├── Token store (encrypted refresh token)
├── Google API client (Guzzle / google-api-php-client)
└── Custom DB tables (perf data, inspections, suggestions, queue)
        │
        └──► Google Search Console API
```

The plugin never blocks a page load on an API call. All Google traffic happens in background cron jobs that write to local tables; the admin screens read only from those tables.

---

## 3. OAuth 2.0 flow

Recommended: **google-api-php-client** library (handles token refresh, retries).

1. Admin enters a **Client ID** and **Client Secret** from their own Google Cloud project (Settings screen). *(Shipping your own credentials means every install shares one OAuth app and one quota project — fine for a single agency, risky for a distributed plugin. For agency use, one shared GCP project is simplest.)*
2. Admin clicks **Connect** → redirected to Google consent screen with scope `webmasters.readonly` and `access_type=offline` (to get a refresh token).
3. Google redirects back to a plugin callback (`admin-post.php?action=wpscm_oauth_callback`) with an auth code.
4. Plugin exchanges code → access token + refresh token.
5. **Store the refresh token encrypted** (see §7). Access tokens are short-lived and refreshed automatically.
6. Plugin calls `sites.list` to let the admin pick which verified property to manage.

Edge cases to handle: revoked access (refresh fails → prompt reconnect), property removed from GSC, multiple properties (store a `property_url` per connection).

---

## 4. Data sync strategy

### 4a. Performance sync (daily cron)

- Query `searchanalytics.query` for the property with `dimensions: [date, page, query, device, country]`.
- Pull the trailing window (e.g. last 90 days on first run, then daily incremental for the newest available date).
- Respect the ~2–3 day data lag; re-fetch the last ~3 days each run to catch late-finalized data (upsert).
- Paginate with `rowLimit: 25000` + `startRow`.
- Store rows in `wp_wpscm_performance`.

**Drop detection:** after each sync, compare each page's clicks/impressions/avg-position over a recent window (e.g. last 7 days) vs the prior comparable window. Flag pages exceeding a configurable threshold (e.g. clicks down >30%, or position worsened by >5). Write flags to the issues table with type `performance_drop`.

### 4b. URL inspection (rolling, throttled cron)

This is the rate-limited part — design accordingly.

- Maintain a **queue table** of URLs (seeded from published posts/pages, sitemaps, and top pages from performance data).
- Each cron tick (e.g. every 15 min) pulls N URLs whose `last_inspected` is oldest, where N keeps you safely under **2,000/day and 600/min**. A simple safe pace: ~80 inspections/hour ≈ 1,920/day.
- For each URL, call `urlInspection.index.inspect` and store:
  - Index status (`coverageState`, `verdict`, `robotsTxtState`, `indexingState`)
  - Google-selected canonical vs user-declared canonical (mismatch = issue)
  - Mobile usability verdict + issues
  - Rich-results / structured-data verdict + issues
  - Last crawl time, page fetch state
- Re-inspect each URL on a configurable cadence (e.g. every 14–30 days), prioritizing changed/updated posts (hook `save_post` to re-queue).
- Surface a "scan progress" bar so the admin knows a full site scan takes time on large sites.

**Backoff:** on HTTP 429 / quota errors, exponentially back off and pause the queue until the next day's quota window.

---

## 5. Auto-fix suggestions engine

Suggestions are **derived locally** from inspection + performance data joined against the post's own content. The plugin reads the actual WordPress post and compares it to what GSC reports.

| Detected issue | Suggested fix | How it's derived |
|---|---|---|
| Canonical mismatch (Google chose a different URL) | Show both URLs; suggest setting/aligning canonical | Compare `googleCanonical` vs `userCanonical` from inspection |
| Page not indexed / "Crawled - currently not indexed" | Surface reason; suggest content depth, internal links, sitemap inclusion | `coverageState` |
| Missing/short title or meta description | Suggest length-appropriate title/meta | Read post `<title>`/meta; flag <30 or >60 chars title, etc. |
| High impressions, low CTR | Suggest title/meta rewrite for the top query | Join performance (CTR percentile) with query data |
| Structured-data errors | List the specific schema errors to fix | `richResultsResult.detectedItems[].issues` |
| Mobile usability issues | List the reported usability problems | `mobileUsabilityResult.issues` |
| Ranking drop on a page | Flag for review; show before/after metrics | `performance_drop` flag from §4a |

Optional v2: a "Generate improved title/meta" button using an LLM. v1 keeps suggestions rule-based and deterministic. **Suggestions are advisory** — the admin reviews and applies; the plugin should not silently edit content.

---

## 6. Database schema

Custom tables (prefix `wp_wpscm_`), created on activation via `dbDelta`.

**`wp_wpscm_performance`**
`id, property_url, date, page_url, query, device, country, clicks, impressions, ctr, position, synced_at` — index on `(property_url, date, page_url)`.

**`wp_wpscm_inspections`**
`id, property_url, url, verdict, coverage_state, robots_state, indexing_state, google_canonical, user_canonical, mobile_verdict, rich_results_verdict, last_crawl_time, raw_json (longtext), last_inspected` — unique on `(property_url, url)`.

**`wp_wpscm_issues`**
`id, url, type (enum: indexing|canonical|mobile|schema|title_meta|performance_drop|ctr), severity, details_json, status (open|ignored|resolved), detected_at, resolved_at`.

**`wp_wpscm_suggestions`**
`id, issue_id, post_id, suggestion_type, current_value, suggested_value, applied (bool), created_at`.

**`wp_wpscm_queue`**
`id, url, post_id, priority, last_inspected, next_due, attempts, status`.

Tokens are stored in an encrypted option, not a table (see §7).

---

## 7. Security

- **Encrypt the refresh token** at rest. Derive a key from WordPress salts (`AUTH_KEY` etc.) via `sodium_crypto_secretbox`, or use a dedicated key in `wp-config.php`. Never store tokens in plaintext options.
- All settings actions protected with **nonces** and `current_user_can('manage_options')`.
- OAuth callback validates the `state` parameter (CSRF).
- Client Secret stored encrypted; never echoed back to the browser.
- Sanitize/escape everything rendered in admin tables (inspection data is external input).
- Honor data-privacy: provide a "Disconnect & delete data" action that revokes the token and drops local GSC data.

---

## 8. Admin UI (screens)

1. **Dashboard** — top KPIs (clicks/impressions/CTR/avg position with trend arrows), open-issues count by type, scan progress.
2. **Performance** — date-range picker, page & query tables (sortable), trend charts (Chart.js), drop alerts.
3. **Issues** — filterable table by type/severity/status; bulk ignore/resolve; click a row → full inspection detail + raw GSC verdict.
4. **Suggestions** — per-URL recommended fixes with current vs suggested values; "mark applied".
5. **Settings** — Google credentials, connect/disconnect, property selector, sync cadence, drop-detection thresholds, inspection pace.

Use WP's native list-table styling and the Settings API so it feels native.

---

## 9. Tech stack & dependencies

- **google-api-php-client** (`google/apiclient`) via Composer — bundled in the plugin's `/vendor`.
- **Chart.js** for trend charts (enqueued locally).
- WP-Cron for scheduling (recommend documenting a real system cron → `wp-cron.php` for reliable timing on low-traffic sites).
- Optional **Action Scheduler** (the library WooCommerce uses) for robust background queue processing instead of raw WP-Cron — strongly recommended for the inspection queue.

---

## 10. Build phases

1. **Phase 1 — Connect & performance:** OAuth flow, property selection, daily Search Analytics sync, Performance + Dashboard screens.
2. **Phase 2 — Inspection:** queue + throttled URL Inspection cron, Issues screen, scan progress.
3. **Phase 3 — Suggestions:** rule-based suggestion engine, Suggestions screen.
4. **Phase 4 — Polish:** drop-detection alerts (email/admin notice), data export (CSV), disconnect/cleanup, docs.

---

## 11. Risks & limits to flag to stakeholders

- **No bulk issue export** — full-site issue coverage is reconstructed URL-by-URL and is inherently incremental (days on large sites).
- **2,000 inspections/day per property** is the hard ceiling; a 50k-URL site can't be fully re-scanned daily.
- **Data lag** — performance numbers are 2–3 days behind; "today" is never complete.
- **OAuth app verification** — if distributed publicly, Google requires app verification for sensitive scopes; for internal/agency use a single GCP project avoids this.
- **WP-Cron reliability** — depends on site traffic; recommend real cron for sync timing.

---

## Sources

- [Usage Limits — Search Console API (Google for Developers)](https://developers.google.com/webmaster-tools/limits)
- [Welcoming the new Search Console URL Inspection API (Google Search Central)](https://developers.google.com/search/blog/2022/01/url-inspection-api)
- [Search Analytics: query — Search Console API](https://developers.google.com/webmaster-tools/v1/searchanalytics/query)
- [Google Search Console URL Inspection API: A Practical Guide (ZoomSEO)](https://zoomseo.com.au/index.php/2025/09/05/using-google-search-consoles-url-inspection-api/)
- [Google Search Console API Essential Guide (Rollout)](https://rollout.com/integration-guides/google-search-console/api-essentials)
