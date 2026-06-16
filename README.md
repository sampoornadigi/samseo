# Sampoorna SEO

Agency SEO platform for Sampoorna Digi Branding — a per-site WordPress SEO engine plus a central multi-tenant control plane, with GEO/AEO, an AI layer, and lossless migration from Rank Math / Yoast / AIOSEO.

This repository contains the **`sampoorna-seo`** plugin — currently the Google Search Console seed module (performance, issues, suggestions, digests), refactored into the `Sampoorna\SEO\` namespace with PSR-4 autoloading and a modular `includes/` layout ready for the broader build — plus the full program plan.

## Repository layout

```
.
├── sampoorna-seo/               # WordPress plugin (Sampoorna\SEO\ namespace, PSR-4; GSC seed module under includes/Integrations/GSC)
├── dev/                         # Docker tooling image + WP test-suite installer
├── tests/                       # PHPUnit bootstrap + integration tests
├── docker-compose.yml           # WordPress + MySQL + Adminer + WP-CLI + PHP tooling
├── Makefile                     # make up / check / test / lint / stan ...
├── composer.json                # dev tooling: PHPCS, PHPStan, PHPUnit
├── phpcs.xml.dist               # WordPress Coding Standards ruleset
├── phpstan.neon.dist            # static analysis config
├── phpunit.xml.dist             # test suite config
├── DEV-README.md                # how to run the dev/test environment
├── Sampoorna-SEO-Build-Plan.md  # full program roadmap (phases 0–5)
└── GSC-Issue-Manager-Plugin-Spec.md  # architecture spec for the GSC module
```

## Quick start (dev environment)

Requires Docker Desktop. From the repo root:

```bash
cp .env.example .env
make build && make up        # WordPress at http://localhost:8080, Adminer at http://localhost:8081
make composer                # install PHP dev dependencies
make test-setup              # download WP test library + create the test DB
make check                   # PHPCS + PHPStan + PHPUnit
```

See **DEV-README.md** for the full command reference and the raw `docker compose` equivalents (Windows-friendly).

## The plugin: Sampoorna SEO (GSC seed module)

Connects to Google Search Console via OAuth 2.0 and, inside wp-admin:

- **Performance** — daily sync of clicks/impressions/CTR/position; dashboard KPIs, trend chart, click-drop detection.
- **Issues** — throttled URL-Inspection crawler (under Google's 2,000/day cap) surfacing indexing, canonical, mobile, and structured-data problems.
- **Suggestions** — deterministic, advisory fix recommendations (title/meta length, canonical, low-CTR pages, etc.).
- **Digests & export** — scheduled email summaries and CSV export.

Install the plugin by uploading a zip of the `sampoorna-seo/` folder via Plugins → Add New → Upload (or symlink it into `wp-content/plugins/`), then follow the Settings screen.

## Roadmap

The full build plan (own engine → technical SEO → schema → migration gate → control plane → GEO/AI visibility) is in **Sampoorna-SEO-Build-Plan.md**.

## License

GPL-2.0-or-later.
