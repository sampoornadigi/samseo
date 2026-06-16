# Sampoorna SEO — Build Plan & Program Roadmap

**Companion to:** Sampoorna SEO Requirements Specification v0.1
**Scope:** Full build (Option A, locked) — per-site engine + agency control plane + GEO/AEO + AI layer + lossless migration
**Starting point:** the existing **WP Search Console Manager** plugin is folded in as a seed module (GSC integration + insights + reporting + control-plane handshake patterns)
**Status:** plan v1.0

---

## 1. Executive read of the requirements

The PRD is internally consistent and the hard call is already made: **build the engine, don't wrap one.** That single decision is what makes this a large program rather than a plugin. Everything downstream follows from it — you now own the deceptively hard parts (canonical precedence, paginated sitemaps, hreflang, redirect ordering) and the migration importers become MVP-critical rather than optional, because there is no borrowed engine to fall back on during a client cutover.

Three things define success, and none of them is the SEO engine itself:

1. **The control plane** (§4.8) — central config push, audit→approve→deploy→rollback, bulk ops, white-label. This is the actual reason to build and the only part you cannot buy.
2. **GEO/AEO + AI-visibility** (§4.4) — `llms.txt`, AI-crawler access/engagement, AEO scoring, and (the real gap) LLM citation tracking. This is the differentiation no WP-native plugin has.
3. **Migration fidelity** (§4.9) — the gate that determines whether you can move a single live client without de-indexing them.

The competitive matrix (§3) is best read as a "don't reinvent" map: the on-page engine is table stakes you must match but win no points for; spend disproportionate effort on the four empty columns (AI visibility, control plane, white-label, GEO/AEO).

The biggest strategic insight in the doc is the **server-side rendering advantage over OTTO**. OTTO uses a client-side pixel because it must be platform-agnostic; you control every client's WordPress, so you render meta/schema/canonicals into `wp_head` server-side — more reliable for Google and immune to OTTO's documented failure mode (broken sitemaps, slow loads, de-indexed pages). Protecting that advantage means the **performance and front-end-safety budget (§5) is a top-priority constraint, not a nicety.**

**Principal risks to plan around** (expanded in §9): migration data loss, the engine becoming a tar pit (scope creep), control-plane single-point-of-failure, and AI quality reaching live client sites unattended.

---

## 2. What we already have — reusing WP Search Console Manager

The GSC plugin built across the prior phases is not throwaway. It is a working, security-conscious WordPress plugin that already implements several patterns this program needs. It becomes the seed for the **Integrations**, **Insights**, and **Reporting** modules and donates infrastructure to the control-plane handshake.

| Existing component (WP Search Console Manager) | Reuse target in Sampoorna SEO | Action |
|---|---|---|
| OAuth 2.0 flow to Google (auth URL, callback, refresh, revoke) | §4.7 GSC integration | Reuse logic; relocate token custody to the control plane (per-site becomes a fallback) |
| `WPSCM_Crypto` (AES-256 token encryption from WP salts) | §5 security; any at-rest secret | Reuse as-is → `Sampoorna\SEO\Security\Crypto` |
| `WPSCM_API` (Search Analytics + URL Inspection REST client, pagination, 429 handling) | §4.7 GSC; §4.4 crawler signals | Reuse; extend with GA4, Bing |
| Throttled inspection crawler (queue table, rate-cap, backoff, re-queue on save) | §4.8 audit engine; §4.3 issue detection | Generalize the queue/worker into the audit runner |
| Issues model + typed derivation + auto-resolve | §4.8 health scoring inputs; §4.3 issue warnings | Becomes the "Technical" health dimension feed |
| Suggestions engine (rule-based, advisory, current-vs-suggested) | §4.1/§4.8 recommendations; pre-AI baseline | Keep as deterministic layer beneath the AI layer (§4.10) |
| Reports: scheduled digest + CSV export | §4.8 reports/alerts | Reuse digest; route bulk/branded reports to the Node generator |
| Admin UI patterns (list tables, nonces, capability checks, notices, cron) | §4.1 editor, all admin screens | Reuse conventions; restyle under white-label |
| DB layer (`dbDelta`, versioned upgrade routine) | All persistence | Reuse the migration/versioning pattern |

**Net:** roughly the Integrations (GSC), part of Insights/Audit, Reporting, and the security/cron/DB plumbing are 50–80% seeded. The on-page engine, schema, sitemaps, redirects, migration, control plane, and GEO modules are net-new.

**Refactor-up-front task:** rename to the `Sampoorna\SEO\` namespace, adopt PSR-4 autoloading + Composer (dev-only; no runtime third-party deps), and restructure into the module layout in §3 before extending. Doing this rename once, early, avoids a painful retrofit later.

---

## 3. Target architecture

Two deployables, matching the PRD's §6 diagram.

### 3.1 Per-site plugin (`sampoorna-seo`)

A standard WordPress plugin (PHP 8.1+), server-rendered, organized as independent modules behind a thin core:

```
sampoorna-seo/
├── sampoorna-seo.php            # bootstrap, constants, activation/upgrade, module loader
├── includes/
│   ├── Core/                    # container, hooks, settings store, REST router, capabilities
│   ├── Security/                # Crypto (from WPSCM), signed-request signer/verifier, nonces
│   ├── Meta/                    # §4.1 per-object meta store + wp_head renderer + template engine
│   ├── Schema/                  # §4.2 JSON-LD graph builder, types, validator
│   ├── Technical/               # §4.3 sitemaps, redirects, 404 monitor, robots, hreflang, IndexNow/Indexing
│   ├── Content/                 # §4.1 on-page score, readability, §4.4 AEO score
│   ├── Geo/                     # §4.4 llms.txt, AI-crawler access checker, engagement logger
│   ├── Ai/                      # §4.10 AiClient (only thing that talks to models; proxies to control plane)
│   ├── Integrations/            # §4.7 GSC (seeded), GA4, Bing, Merchant Center
│   ├── Local/                   # §4.6 multi-location LocalBusiness, WooCommerce SEO
│   ├── Migration/               # §4.9 source detectors + importers (RankMath/Yoast/AIOSEO) + dry-run/verify
│   ├── Audit/                   # §4.8 audit runner (generalized crawler), recommendation builder
│   ├── ControlPlane/            # §4.8 handshake client, config sync, bulk-op executor, white-label
│   └── Admin/                   # editors, screens, list tables (seeded from WPSCM admin)
└── assets/                      # bundled Chart.js etc. (no CDN in production)
```

Design rules: every module is opt-in and isolated (a broken module must not take down `wp_head`); all SEO output is server-rendered and performance-budgeted; the control plane is **optional at runtime** — sites degrade to local-only if it's unreachable (§5 reliability).

### 3.2 Control plane (Hostinger KVM VPS — reuse existing services)

Per §6, this is a **new module on infrastructure you already run**, not a new stack: Node.js dashboard, PostgreSQL (config, audit logs, AI-visibility data), Redis (job queue + cache), Python workers (GSC/GA4 fetch, AI-visibility sampling), the existing Node report generator, and a central API-key vault. Sites talk to it over signed REST + webhooks.

**Trust boundary:** AI keys and provider credentials never live on client sites; all model calls are proxied through the control plane so cost and secrets stay with you (§4.5, §4.10, §5).

---

## 4. Module build plan (tied to requirements)

Each module below lists its requirement anchor, the core work, and the non-obvious hard parts to budget for.

**4.1 Meta engine (Core on-page).** Per-object meta store (post/page/term/CPT); `wp_head` renderer for title, description, canonical, robots, OG/Twitter; template-variable engine (`%title%`, `%sitename%`, `%category%`, `%sep%`); unlimited focus keywords; 0–100 on-page score. *Hard parts:* canonical precedence (self vs override vs paginated vs WooCommerce), correct robots interaction with core, and template tokens that must round-trip with the migration normalizer (§4.9). This module is the spine — it ships in Phase 0.

**4.2 Schema.** JSON-LD graph (not isolated blobs) for Article, Product, LocalBusiness, FAQ, HowTo, BreadcrumbList, Organization, WebSite, Person (18+); sitewide Organization+WebSite+Breadcrumb auto-inject; conditional rules; schema.org validation before output. *Hard parts:* a single connected `@graph` with stable `@id` references; not fabricating properties (ties to the AI no-fabrication guardrail).

**4.3 Technical SEO.** Paginated XML sitemaps + index (posts/pages/CPTs/taxonomies/images) with search-engine ping; redirect manager (301/302/307/410 + regex) with defined precedence; 404 monitor with one-click redirect; `robots.txt` editor; hreflang; **IndexNow + Google Indexing API** (reuse the MPM Dental setup). *Hard parts:* sitemap pagination/caching at scale without front-end cost, and redirect-ordering/loop detection. This is where OTTO breaks — hold the performance budget here hardest.

**4.4 GEO/AEO (primary differentiator).** Port the **Sampoorna LLMs.txt Generator** as the `llms.txt`/`llms-full.txt` module; AI-crawler access verifier (GPTBot, ClaudeBot, PerplexityBot, Google-Extended, CCBot) against robots + Cloudflare (reuse the Epistemo fix logic); **AEO score** (question headings, direct-answer paragraphs, defined-term blocks, extractable lists/tables, FAQ presence); AI-referral traffic tracking; **AI-bot crawler-engagement logging** (frequency, last-seen, status per bot); and the **COULD** prototype of **LLM citation tracking** (QUEST-style) via control-plane Python workers + your keys. *Hard parts:* citation tracking is genuinely novel — prototype rough early, expect iteration.

**4.5 / 4.10 AI layer (cross-cutting).** One `AiClient` is the only code that talks to models; every module calls it; it proxies to the control-plane AI proxy (keys, rate/cost limits, content-hash caching, provider/task routing — cheap model for alt/bulk meta, strong model for schema/rewrites/agentic commands), with a direct-key fallback for standalone use. *Guardrails are mandatory and non-negotiable:* human-in-the-loop via approve→deploy→rollback (auto-apply opt-in per task per client), validate-don't-trust (schema/length/template checks before output), no fabrication, cost caching by content hash, and per-client privacy policy (healthcare clients never get PHI sent; route to compliant providers).

**4.6 Local & WooCommerce.** Multi-location LocalBusiness schema + per-location pages (Anu Furniture's 14 Hyderabad + 4 Bengaluru showrooms is the acceptance test); Product schema, OG product tags, clean WooCommerce canonicals incl. variations and out-of-stock noindex; tie into existing Merchant Center/feed work.

**4.7 Integrations.** Central GSC (one OAuth, all properties) surfacing impressions/clicks/position per site — **seeded by WP Search Console Manager**; GA4; Bing Webmaster (SHOULD); REST API + webhooks feeding the Python→PostgreSQL→Node reporting stack (SHOULD).

**4.8 Control plane (core reason to build).** Central dashboard listing all sites with health/scores/errors/last-sync; **multi-dimensional health scores** (Content/Authority/Technical/UX/GEO); **audit→recommend→approve→deploy→rollback** with every change logged and reversible; **config templating** by vertical (education/healthcare/furniture/trading); **bulk ops** (regenerate sitemaps, IndexNow, update llms.txt, apply schema, run audit) for one/group/all; **white-label** (Sampoorna branding, hidden upsells, client roles); secure signed site↔plane auth with rotatable keys; branded PDF/PPTX reports via the existing Node generator (SHOULD); role separation (SHOULD); GBP automation + local-grid tracking (SHOULD); critical-event alerts via email/WhatsApp (SHOULD).

**4.9 Migration (hard gate).** Source detector; **dry-run diff first** (no silent writes); **non-destructive** (leave `_yoast_wpseo_*` etc. in place so rollback = deactivate/reactivate); idempotent + resumable (WP-CLI/cron batches); post-migration verification (re-render sample URLs, compare title/desc/canonical/robots to source). Per-source mappings are specified in the PRD; the three traps to engineer explicitly: **Yoast term data in the `wpseo_taxonomy_meta` option (not termmeta)**, **AIOSEO storing everything in `wp_aioseo_posts` (not postmeta)**, and **template tokens differing per plugin** (normalize, never copy raw). Scores (linkdex/content_score/TruSEO) are not imported — recompute with your analyzer.

---

## 5. Phase roadmap (0 → 5)

Effort is given in **relative T-shirt sizes** and indicative engineer-weeks for one experienced WP engineer (control-plane phases assume a second engineer for the Node/Python side). Treat numbers as planning aids, not commitments — re-estimate at each phase boundary.

### Phase 0 — Engine foundation `[L · ~4–6 wk]`
**Build:** plugin scaffold + module loader; per-object meta store; **server-side `wp_head` renderer** (title/description/canonical/robots/OG/Twitter); global title-template engine; per-post admin editor; control-plane handshake REST API with **HMAC-signed auth**; **AI service layer** (`AiClient` → control-plane proxy) with first concrete use: one-click AI title + meta generation in the editor.
**Reuse:** Crypto, REST/cron/DB plumbing, admin patterns, OAuth scaffolding from WP Search Console Manager.
**Exit criteria:** a clean install renders correct, performance-budgeted SEO tags server-side on a real site; editor saves per-object meta; site completes a signed handshake with the control plane; AI generates a title/meta through the proxy.
**Dependencies:** none (foundational).

### Phase 1 — Technical SEO engine `[L · ~4–6 wk]`
**Build:** paginated XML sitemaps + index; redirect manager + 404 monitor; `robots.txt` + hreflang; IndexNow + Google Indexing API.
**Exit:** sitemaps validate and paginate at 10k+ URLs with no measurable TTFB hit; redirects honor precedence with loop detection; 404→redirect one-click works; IndexNow + Indexing API submit successfully.
**Dependencies:** Phase 0 (meta/canonical model).

### Phase 2 — Schema + content analysis `[M–L · ~3–5 wk]`
**Build:** JSON-LD `@graph` engine (18+ types, conditional rules, validation); on-page score; readability; **AEO score**.
**Exit:** valid connected schema on Article/Product/Local/FAQ; scores render in the editor; AEO checks fire; passes Google Rich Results test on samples.
**Dependencies:** Phase 0 (content access), Phase 1 (canonical/URL truth).

### Phase 3 — Migration importers `[L · ~4–6 wk]` — **hard gate**
**Build:** detectors + importers for **Rank Math, Yoast, AIOSEO**; dry-run diff; non-destructive writes; idempotent/resumable batches; verification; bulk-trigger from control plane (SHOULD).
**Exit:** against one real site of each source, a dry-run diff is accurate, import is lossless on title/desc/canonical/robots/redirects, verification matches source byte-for-intent, and rollback restores cleanly. **No production cutover before this passes.**
**Dependencies:** Phases 0–2 (the target schema must exist to map into).

### Phase 4 — Control plane `[XL · ~6–10 wk, 2 people]`
**Build:** VPS dashboard; health scores (5 dimensions); config templating; bulk ops; white-label; **audit→approve→deploy→rollback**; branded reports via existing Node generator; roles; alerts; GBP (SHOULD).
**Exit:** add a site by config (not manual setup); push a vertical template to N sites; run an audit, approve a fix, deploy server-side, roll it back; client view shows only branded features; control-plane outage degrades sites to local-only with no front-end impact.
**Dependencies:** Phase 0 handshake; Phases 1–3 give it something to orchestrate.

### Phase 5 — GEO / AI visibility `[L · ~4–6 wk]`
**Build:** port `llms.txt`; AI-crawler access checker + engagement analytics; **LLM citation tracking (QUEST-style)** via control-plane workers.
**Exit:** `llms.txt`/`llms-full.txt` generate and update; crawler access blocks are detected and flagged; per-bot engagement reports render; citation-tracking prototype samples target prompts and records brand presence per client.
**Dependencies:** Phase 4 (workers, queue, dashboard surfaces).

**Critical path:** 0 → 1 → 2 → 3 is sequential (each needs the prior). Phase 4 can begin in parallel after Phase 0 (the handshake exists) using a second engineer, but its audit/bulk value grows as 1–3 land. Phase 5's `llms.txt` port can start any time (it's self-contained); citation tracking depends on Phase 4 workers.

**Sequencing note vs. the PRD:** §7 lists GEO/AI visibility last. Consider pulling the **`llms.txt` port and AI-crawler access checker forward to run alongside Phase 0–1** — they're self-contained, already largely built, and are your headline differentiator for client conversations. Keep the heavier citation-tracking work in Phase 5.

---

## 6. Cross-cutting concerns (apply to every phase)

**Performance budget (top priority).** Sub-0.05s added front-end load; defer all dashboard/API/cron work off the request path; no render-blocking; bundle assets locally (no CDN dependency in production). Add a CI check that fails the build if `wp_head` output exceeds a TTFB budget on a reference page. This is where OTTO fails — it is your differentiator, so treat regressions as release-blockers.

**Security.** Nonce + capability checks on every admin action; sanitize in / escape out everywhere; HMAC-signed control-plane requests with rotatable per-site keys; zero secrets in client-site code. Reuse `Crypto` for any at-rest secret.

**Reliability.** Control-plane outage must never affect a client front end — local-only degradation is a tested path, not an aspiration.

**Testing.** Per-source migration fixtures (real exported DBs of each plugin/version); golden-file tests for `wp_head` and schema output; Rich Results validation in CI; a staging install of each client vertical. Note the build-tooling caveat below.

**Build tooling / verification gap (carried over from this engagement).** The sandbox used here has **no PHP runtime and an unreliable file-sync to the shell**, so validation has been structural (tokenizer), not true `php -l`/PHPUnit. For this program, stand up a proper local dev environment (wp-env or Local) with PHP 8.1, PHPCS (WordPress-Coding-Standards), PHPStan, and PHPUnit so every module gets real linting and tests. This is a prerequisite before serious engine work.

**i18n.** Translatable strings throughout; Telugu/English admin where useful.

---

## 7. Recommended near-term sequence (next ~8 weeks)

1. **Stand up the dev environment** (PHP 8.1, PHPCS/PHPStan/PHPUnit, wp-env) — removes the verification gap above.
2. **Refactor WP Search Console Manager** into the `Sampoorna\SEO\` namespace + module layout; land Crypto, REST, cron, DB, and admin patterns as shared Core.
3. **Phase 0 engine foundation** on top of that core, including the HMAC handshake and the `AiClient` proxy.
4. **In parallel:** port the `llms.txt` generator and AI-crawler access checker (self-contained, high client value).
5. **Define the control-plane handshake contract** (auth, config schema, audit/result payloads) early — both sides depend on it; lock it before Phase 4 staffing ramps.

---

## 8. Open decisions to resolve before/at each gate

- **Custom post type / meta storage shape** — single serialized meta vs discrete keys per field (affects migration mapping and query performance). Recommend discrete keys for queryability.
- **Control-plane config ownership** — when a template is pushed, can a site override locally? Define precedence (template vs local) before Phase 4.
- **AI auto-apply policy defaults** — confirm the default is human-in-the-loop everywhere, auto-apply opt-in per task per client (PRD says so; make it the enforced default).
- **GBP automation scope** — full GBP Galactic parity is large; confirm whether Phase 4 ships posts/hours/info sync only, with local-grid tracking deferred.
- **Citation-tracking cost ceiling** — sampling target prompts across engines has real API cost; set a per-client budget and cadence before Phase 5.
- **White-label depth** — menu/branding only, or full removal of all Sampoorna mentions and custom login? Affects Phase 4 UI work.

---

## 9. Risks & mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| **Migration data loss** on live cutover (Yoast taxonomy_meta, AIOSEO table, token syntax) | De-indexed pages, lost rankings overnight | Phase 3 is a hard gate: dry-run diff, non-destructive, verification against a real site of each source before any cutover; rollback = deactivate/reactivate |
| **Engine tar-pit / scope creep** | Effort sunk into matching commodity features, differentiators slip | Match the matrix baseline only; spend net-new effort on the four empty columns; resist gold-plating canonical/sitemap edge cases beyond correctness |
| **Front-end degradation** (OTTO's failure mode) | The one quality lever over OTTO is lost | Server-side render only; CI performance budget as release-blocker; no client-side injection |
| **Control plane = single point of failure** | All client sites impaired | Tested local-only degradation; control plane optional at runtime |
| **AI quality reaching live sites** | Wrong/fabricated meta or schema → Google penalty | Mandatory human-in-the-loop; validate-don't-trust; no-fabrication prompts; per-client privacy policy |
| **Verification gap in current tooling** | Bugs ship undetected | Stand up real PHP/PHPUnit/PHPCS dev env before engine work (§6) |
| **Two-surface complexity** (PHP plugin + Node/Python plane) | Coordination/contract drift | Lock the handshake contract early (§7.5); version it |

---

## 10. Bottom line

The plan is to **refactor the GSC plugin into a shared core, then build outward module by module**, holding two constraints above all else: **server-side rendering + performance** (your structural edge over OTTO) and **migration fidelity** (the gate to touching any live client). Sequence the engine (0→1→2), pass the migration gate (3), then build the control plane (4) that is the actual product, and layer the GEO/AI-visibility differentiators (5) — pulling the self-contained `llms.txt`/crawler-access pieces forward because they're already built and they're what sets you apart in the sales conversation. The single most important prerequisite is unglamorous: a real PHP testing environment, so the engine's hard edges are caught by tests rather than by a client's rankings.
