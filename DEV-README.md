# Dev & Test Environment

A Docker stack for developing and testing the WordPress plugins in this folder:
a browsable WordPress for manual testing, plus a PHP 8.1 tooling container with
**PHPCS** (WordPress Coding Standards), **PHPStan**, and the **WordPress PHPUnit
integration suite**.

## Requirements

- Docker Desktop (Windows/macOS) or Docker Engine + Compose v2 (Linux).
- `make` is optional — every step has a raw command below.

## Services

| Service | URL / use | Notes |
|---|---|---|
| `wordpress` | http://localhost:8080 | Manual/visual testing. First visit runs the install wizard. |
| `adminer` | http://localhost:8081 | DB browser. Server `db`, user `root`, pass `root`. |
| `db` | MySQL 8 (host port 3307) | Data persists in the `db_data` volume. |
| `wpcli` | `docker compose run --rm wpcli wp ...` | WP-CLI; not a long-running service. |
| `tooling` | `make shell` | PHP 8.1 + Composer + PHPCS/PHPStan/PHPUnit. |

The plugin is live-mounted into WordPress at
`wp-content/plugins/sampoorna-seo`, so edits on your machine are
reflected immediately.

## First-time setup

```bash
cp .env.example .env            # optional; defaults work
docker compose build tooling    # build the PHP tooling image
docker compose up -d            # start everything
docker compose exec tooling composer install        # PHP dev deps
docker compose exec tooling bash dev/install-wp-tests.sh wordpress_test root root db latest
```

With `make`:

```bash
make build && make up
make composer
make test-setup
```

Then activate the plugin for manual testing:

```bash
make wp ARGS="core install --url=http://localhost:8080 --title=Dev --admin_user=admin --admin_password=admin --admin_email=dev@example.test --skip-email"
make wp ARGS="plugin activate sampoorna-seo"
```

## Daily commands

| Task | `make` | Raw command |
|---|---|---|
| Start / stop | `make up` / `make down` | `docker compose up -d` / `down` |
| Lint (PHPCS) | `make lint` | `docker compose exec tooling vendor/bin/phpcs` |
| Auto-fix | `make fix` | `docker compose exec tooling vendor/bin/phpcbf` |
| Static analysis | `make stan` | `docker compose exec tooling vendor/bin/phpstan analyse` |
| Unit/integration tests | `make test` | `docker compose exec tooling vendor/bin/phpunit` |
| Everything | `make check` | lint + stan + test |
| Shell in tooling | `make shell` | `docker compose exec tooling bash` |
| WP-CLI | `make wp ARGS="plugin list"` | `docker compose run --rm wpcli wp plugin list` |

## How testing works

- **PHPCS** enforces WordPress Coding Standards + PHP 8.1 compatibility
  (`phpcs.xml.dist`). It scans `sampoorna-seo/`. (The PSR-4 `*/sampoorna-seo/*`
  tree is exempted from the `class-*.php` filename sniff.)
- **PHPStan** runs level 5 with WordPress stubs (`phpstan.neon.dist`). Run it
  with `--memory-limit=1G` (the WordPress stubs exceed the default 128M).
- **PHPUnit** runs real WordPress integration tests (`phpunit.xml.dist`,
  `tests/`). The test library is installed by `dev/install-wp-tests.sh` into the
  `tooling` container and uses a dedicated `wordpress_test` database on the `db`
  service. The bootstrap loads the plugin on `muplugins_loaded`; the sample
  suite in `tests/sampoorna-seo/` checks class loading, the crypto round-trip,
  table creation, and a performance upsert/read.

This replaces the structural-only validation used previously — `make check` now
gives you real `php -l`-equivalent linting, static analysis, and DB-backed tests.

## Plugin structure

The plugin is `sampoorna-seo/`, namespaced `Sampoorna\SEO\` with a lightweight
PSR-4 autoloader in `sampoorna-seo.php` (no runtime third-party deps; Composer is
dev-only). Modules live under `includes/` — `Core/`, `Security/`,
`Integrations/GSC/`, `Admin/`, plus placeholder dirs for future phases (`Meta/`,
`Schema/`, `Technical/`, etc.). When adding a new module, drop PascalCase classes
under the matching `includes/<Module>/` directory; the autoloader resolves them.

## Notes

- The WordPress site and the test database are **separate** — running tests
  never touches your manual-testing site.
- `make clean` removes volumes and **destroys** the site and test DB.
- On Windows, run these from PowerShell or WSL2 with Docker Desktop running.
