# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A Craft CMS 5 plugin (`viget/craft-parts-kit`, handle `parts-kit`) that turns a `templates/parts-kit` directory into a Storybook-style component library. It renders your real Twig templates—no build step, no npm. The browsing UI is a third-party web component (`@viget/parts-kit`) loaded from unpkg in `src/templates/root.twig`; the plugin's job is to scan templates, serve a JSON nav config, and route clean URLs to rendered parts.

## Commands

```bash
composer test              # full Codeception suite (unit + functional)
composer test-unit         # unit suite only
composer test-functional   # functional suite only
composer check-cs          # ECS lint (Craft CMS ruleset)
composer fix-cs            # ECS autofix
composer phpstan           # PHPStan level 4 (src/ only)
```

Run a single test class or method (use the suite-relative path under `tests/<suite>/`):

```bash
./vendor/bin/codecept run unit services/NavigationTest
./vendor/bin/codecept run unit services/NavigationTest:testSkipsRootIndexTemplate
./vendor/bin/codecept run functional PartsKitRouteCest
```

Add `--env fast` to skip the DB rebuild between runs (`./vendor/bin/codecept run unit --env fast`). The suite must run **without** `--env fast` at least once first to build the schema.

## Local runtime (DDEV)

For manual, in-browser development there is a DDEV harness, **distinct from** the Codeception test harness above. It boots a real Craft 5 install with the plugin symlinked in:

```bash
ddev start   # Start containers
ddev setup   # Install Craft + plugin (idempotent — see .ddev/commands/web/setup)
```

Then `/parts-kit` renders at `https://craft-parts-kit.ddev.site/parts-kit` and the CP is at `/admin` (`admin` / `password`).

**Root ↔ craft-install relationship.** The plugin source is the repo root; `craft-install/` is a full Craft app whose `composer.json` lists the repo root (`../`) as a `type: path` repository with `symlink: true` and requires `viget/craft-parts-kit: "@dev"`. Composer symlinks `craft-install/vendor/viget/craft-parts-kit` → repo root, so `src/` edits are live without reinstalling. `@dev` (not `dev-main`) is used so the path repo resolves on any branch. The root `craft` script bootstraps the console app via `craft-install/bootstrap.php`.

**Gotcha — `ddev composer` targets the plugin root.** `.ddev/config.yaml` sets `composer_root: "."`, overriding the `craftcms` type default. So `ddev composer` operates on the plugin's `composer.json`. To manage Craft app dependencies, run them against `craft-install/`: `ddev exec -d /var/www/html/craft-install composer require <package>`.

**Dev-only files.** `craft-install/`, `.ddev/`, the root `craft` script, and `docs/` are `export-ignore`d in `.gitattributes`, so they never ship in the distributed Composer package—`.github/workflows/package-contents.yml` fails CI if any of them leak into the `git archive`. `craft-install/config/parts-kit.php` sets `requireViewPermission => false` for anonymous viewing in dev only; the plugin's production default stays `true`. The setup script installs Craft fresh and runs `plugin/install`—there is no committed `project.yaml`, and `craft-install/config/project/` is git-ignored so Craft's live project-config sync doesn't dirty the working tree.

## Test harness setup

Tests run against a **real Craft instance backed by a real database**—there is no in-memory mode. Before running locally:

1. Create a `craft_test` database (MySQL or PostgreSQL).
2. `cp tests/.env.example.mysql tests/.env` (or `.pgsql`) and fill in credentials.

The plugin is auto-discovered as a `craft-plugin` and installed by handle in `codeception.yml`. The harness points Craft's site templates path at `tests/_craft/templates/`, so the fixture tree under `tests/_craft/templates/parts-kit/` drives every nav assertion—editing those fixtures changes test expectations.

Unlike sibling plugins (e.g. craft-viget-base), the functional suite **does** work here: Twig renders through the `\craft\test\Craft` connector, so HTTP-level route/permission tests are viable (see `tests/functional/PartsKitRouteCest.php`).

`composer.lock` is git-ignored in this repo, so CI keys its Composer cache off `composer.json`. CI (`.github/workflows/ci.yml` → `codecept.yml`) runs the suite on PHP 8.2 against both MySQL and PostgreSQL, for pull requests and pushes to `main`.

## Architecture

Everything wires up in `src/Plugin.php` inside `Craft::$app->onInit()`:

- **Template root** — registers `parts-kit` as a site template namespace pointing at `src/templates/` (this is where `root.twig` lives, distinct from the user's `templates/parts-kit/` parts directory).
- **URL rules** — overrides the parts-kit paths so they bypass normal Twig routing: `{directory}` → `parts-kit/view/root`, `{directory}/<template:.+>` → `parts-kit/view/template`. This is what lets parts render without `{% layout %}` tags.
- **`partsKit` Twig variable** — exposes the plugin instance to templates (used by `root.twig` as `craft.partsKit.settings`).
- **`parts-kit:view` permission** — registered here, enforced in `root.twig`.

**Request flow:**

1. `ViewController` (`src/controllers/`) handles both routes. `actionRoot` renders `root.twig` with no part; `actionTemplate` validates the requested template exists (checking each configured template extension) then renders `root.twig` with a `templatePath`. Both are `allowAnonymous`—the actual gate is in the template.
2. `root.twig` is the single template for everything. With no `templatePath` it renders the `<parts-kit>` web component pointed at the config endpoint; with one, it `include`s the requested part. The permission check (`{% requirePermission 'parts-kit:view' %}`) lives here, toggled by `settings.requireViewPermission`.
3. `ApiController::actionConfig` (`parts-kit/api/config`) returns the JSON the web component fetches: `{ schemaVersion, nav }`.

**Navigation building** (`src/services/Navigation.php`) — `getNav()` scans the parts directory, builds a tree of `NavNode`s (directories become groups with `url: null`, files become pages with a clean URL), then folds children into parents and sorts. Exclusion rules: any path segment starting with `.` or `_` is hidden; root-level `index.twig`/`index.html` is skipped. `NavNode` is `JsonSerializable` and omits its internal `path` from JSON output.

**Settings** (`src/models/Settings.php`) — `directory`, `headTemplatePath`, `requireViewPermission`. Users override via `config/parts-kit.php`.

### Gotchas

- **`Navigation::getNav()` hardcodes the `'parts-kit'` folder name** (`src/services/Navigation.php:25`) instead of reading `settings.directory`. The controllers and URL rules respect a custom `directory`, but the nav scan does not—changing `directory` will break nav generation until this is fixed.
- **`MockAsset` / `MockAssetBuilder`** (`src/models/`) are a work-in-progress feature for rendering placeholder images (generated as Imagick data URLs), exposed via the `assets` service's `make()` builder. Most `MockAsset` methods throw `NotSupportedException`; only width/height/`getUrl()` are implemented.
- `root.twig` loads the UI from `https://unpkg.com/@viget/parts-kit@^0/...`—the browsing UI requires network access to the CDN.
