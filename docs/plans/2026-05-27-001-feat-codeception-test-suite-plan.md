---
title: "feat: Add Codeception test suite"
type: feat
status: completed
date: 2026-05-27
deepened: 2026-05-27
---

# feat: Add Codeception test suite

## Summary

Stand up a Codeception test harness for the Parts Kit plugin, modeled on the working setup in `craft-viget-base` but adapted from a Craft *project/module* to a Craft *plugin*: the plugin is installed into a DB-backed `\craft\test\Craft` test instance via the `plugins` config rather than bootstrapped as a module. Deliver unit + functional suites, composer `test` scripts, starter tests against existing plugin code, README docs, and a GitHub Actions CI workflow running on MySQL + PostgreSQL under PHP 8.2.

---

## Problem Frame

The plugin ships with static analysis (PHPStan, level 4) and code style (ECS) but **no automated tests**. As logic-heavy features land — the navigation tree builder, the permission-gated view controller, and the in-flight Mock Asset Service (#15) — there is no harness to catch regressions. Bug #9 (navigation fails when the first folder has no direct `.twig` file) is exactly the kind of behavior a test suite would pin down. `craft-viget-base` already runs Codeception against Craft and even tests parts-kit-style template scanning, giving us a proven local reference to copy from.

---

## Requirements

- R1. The plugin has a runnable Codeception harness that boots a real Craft test instance with **unit** and **functional** suites, invoked locally via composer scripts.
- R2. The harness mirrors `craft-viget-base` conventions (DB-backed `\craft\test\Craft` module, `tests/_craft` instance, `.env`-driven DB config), adapted so the plugin-under-test is installed via the `plugins` config.
- R3. Starter unit tests prove the harness works against existing, stable plugin code (NavNode, Navigation service).
- R4. A functional test exercises the permission-gated `/parts-kit` route.
- R5. GitHub Actions CI runs the suite on push and pull request against MySQL **and** PostgreSQL under PHP 8.2, using a reusable workflow modeled on base's `ci.yml` + `codecept.yml`.
- R6. The README documents how to run tests locally, and test artifacts are git-ignored.

---

## Scope Boundaries

- Full or even broad test coverage of existing code — this establishes the harness and conventions, not comprehensive coverage.
- Acceptance / browser (Selenium) suite — base scaffolds one, but ticket #16 scoped this to unit + functional.
- Code-coverage reporting / Codecov upload — commented out in base; not part of this work.
- Changing or fixing any plugin behavior. Tests that touch open bug #9 characterize current behavior; the fix is separate.

### Deferred to Follow-Up Work

- MockAsset / MockAssetBuilder test coverage: ticket #15 (that code is actively being reshaped; testing it now would churn).
- Acceptance/browser suite: a future ticket if end-to-end UI coverage becomes valuable.

---

## Context & Research

### Relevant Code and Patterns

- `craft-viget-base/codeception.yml` — root config: paths, `params: [env, tests/.env]`, `\craft\test\Craft` module with `configFile`, `entryUrl`, `dbSetup: {clean, setupCraft}`, and a `fast` env that skips DB rebuild. **Primary template to copy.**
- `craft-viget-base/tests/unit.suite.yml` / `functional.suite.yml` — suite actors and enabled modules (`\craft\test\Craft`, `Asserts`, `\Helper\Unit` / `\Helper\Functional`).
- `craft-viget-base/tests/_bootstrap.php` — defines `CRAFT_*` path constants pointing at `tests/_craft/*` and calls `TestSetup::configureCraft()`.
- `craft-viget-base/tests/_craft/config/test.php` — one-liner: `return TestSetup::createTestCraftObjectConfig();`.
- `craft-viget-base/tests/.env.example.mysql` / `.env.example.pgsql` + `tests/.gitignore` (ignores `.env`) — DB credentials and `SECURITY_KEY`/`APP_ID` for the test instance.
- `craft-viget-base/.github/workflows/ci.yml` + `codecept.yml` — reusable CI: DB service containers, PHP extension caching, `.env` copy/sed, timezone import, `codecept run unit,functional --fail-fast`.
- `craft-viget-base/composer.json` — `require-dev` versions to mirror: `codeception/codeception ^5.0.11`, `codeception/module-asserts ^3`, `codeception/module-yii2 ^1.1.9`, `codeception/module-phpbrowser ^3`, `vlucas/phpdotenv ^5.5`.
- **Plugin code under test (this repo):**
  - `src/services/Navigation.php` — `getNav()` scans `getSiteTemplatesPath()/parts-kit/`, rejects hidden/underscore paths, skips `index.*`, builds a `NavNode` tree, sorts children by title. Highest-value target; relates to bug #9.
  - `src/models/NavNode.php` — pure value object; `jsonSerialize()` exposes `title`/`url`/`children` and **excludes** `path`.
  - `src/Plugin.php` — registers template root `parts-kit`, the `parts-kit:view` permission, URL rules `directory` → `parts-kit/view/root` and `directory/<template>` → `parts-kit/view/template`, and the `partsKit` Twig variable. Needed for functional route tests.

### Institutional Learnings

- No `docs/solutions/` entries exist yet in this repo. The authoritative reference is the sibling `craft-viget-base` Codeception setup and the `\craft\test\Craft` module source (`vendor/craftcms/cms/src/test/Craft.php`).

### External References

- `craftcms/cms` `\craft\test\Craft` module — `installPlugin()` calls `Craft::$app->getPlugins()->installPlugin($plugin['handle'])`; `mockModulesAndPlugins()` reads `$plugin['class']`. Confirms the `plugins` entry shape: `{ class: <FQCN>, handle: <handle> }`.
- Craft 5 testing docs: https://craftcms.com/docs/5.x/testing/ (general Craft testing framework guidance).

---

## Key Technical Decisions

- **DB-backed Craft test harness, not isolated unit tests.** The plugin's logic is Craft-coupled (`Navigation` reads `Craft::$app->getPath()` and `config->general->defaultTemplateExtensions`). Booting a real Craft test instance — as base and `craftcms/cms` do — is required for meaningful tests. Accepted cost: tests need a database, locally and in CI. *Caveat acknowledged:* the two named unit targets (NavNode, Navigation) are themselves DB-independent — `getSiteTemplatesPath()` resolves the `@templates` alias and `defaultTemplateExtensions` is static config — so the DB tax (a disposable `craft_test` DB, the 2× DB matrix) is really paid for the functional route test and forward-looking coverage (controllers, MockAsset #15). The MySQL+PostgreSQL matrix is retained per decision; if functional stays inert (see R4), revisit running the unit suite on a single engine.
- **Install the plugin-under-test via the `plugins` config**, format `- { class: viget\partskit\Plugin, handle: parts-kit }`. This is the plugin analogue of base's module `bootstrap`.
- **Unit tests instantiate services directly** (`new Navigation()`, `new NavNode(...)`) rather than going through `Plugin::getInstance()`. This sidesteps the root-package plugin-discovery problem (below) for the bulk of tests — they need Craft booted (provided by the Craft module) but not the plugin *installed*.
- **Test autoload namespace** added under `autoload-dev`: `viget\partskit\tests\` → `tests/`. (Base put fixtures under `autoload`; `autoload-dev` is the cleaner home for test-only classes.)
- **Mirror base's CI shape**: a thin `ci.yml` (triggers) calling a reusable `codecept.yml` (matrix). DB matrix = MySQL + PostgreSQL; PHP matrix = 8.2 only.

---

## Open Questions

### Resolved During Planning

- CI database matrix → MySQL **and** PostgreSQL (mirror base; confirmed with user).
- CI PHP matrix → 8.2 only (matches plugin minimum and base; confirmed with user).
- Test suites → unit + functional only; no acceptance suite (confirmed with user).
- Starter-test targets → NavNode + Navigation; MockAsset deferred to #15 (its code is in flux).

### Deferred to Implementation

- **Root-package plugin discovery for functional tests** *(verified — expected to work, not block)*. Craft discovers plugins from `vendor/craftcms/plugins.php`. Because this package's type is `craft-plugin`, the `craftcms/plugin-installer` auto-writes the root plugin into that file on every `composer install` (verified: `vendor/craftcms/plugins.php` already lists `parts-kit` → `viget\partskit\Plugin`). `vendor/` is git-ignored and regenerated each install — the normal mechanism — so `installPlugin('parts-kit')` should resolve the handle with no hand-rolled wiring. **Do not** use `tests/_craft/config/app.php` for this: `app.php` registers Yii *modules* (what base does, because base *is* a module), which does not populate plugin discovery. Just verify the install at implementation time. Fallback for the route/auth wiring (not discovery): if functional rendering proves fiddly within this ticket's scope, mark the functional route test incomplete and land the harness + unit tests, leaving functional wiring to a follow-up.
- **Functional auth for `/parts-kit`.** The route is gated by the `parts-kit:view` permission (commit 18378ce). The functional test will need to log in an admin/permissioned user, or grant the permission in the test environment. Exact mechanism (test fixture user vs. `$I->amLoggedInAs(...)`) is an execution-time detail.
- **Decision needed — fix the `illuminate` → `Illuminate` import casing in `src/services/Navigation.php`?** This is a one-character `src` change required for the isolated `NavigationTest` (R3) to pass reliably. It technically lands a `src` edit in a test-only ticket, brushing the "no behavior change" scope boundary (though it changes no behavior — the prod path already loads the correct casing). Options: fix it here as a tiny prerequisite (preferred), or split it into a separate prerequisite PR before this ticket's U3.
- **Decision needed — is R4 (a *working* functional route test) realistically in scope?** Base's functional suite is entirely `markTestIncomplete`, so there is no working precedent. If functional rendering can't be made green from scratch within this ticket, R4 should be reframed as explicitly deferred to follow-up rather than a deliverable-with-fallback.
- Whether the trivial `index.*`-skip and hidden-path behaviors need fixture files that exactly reproduce bug #9's structure — decide while writing fixtures.

---

## Output Structure

    codeception.yml                          # repo root: paths, params, \craft\test\Craft module
    .github/workflows/
    ├── ci.yml                               # triggers (push/PR/dispatch), calls codecept.yml
    └── codecept.yml                         # reusable matrix workflow (MySQL+PgSQL, PHP 8.2)
    tests/
    ├── .gitignore                           # ignores .env
    ├── .env.example.mysql
    ├── .env.example.pgsql
    ├── _bootstrap.php                       # CRAFT_* path constants + TestSetup::configureCraft()
    ├── unit.suite.yml
    ├── functional.suite.yml
    ├── _support/
    │   ├── Helper/{Unit,Functional}.php
    │   └── (UnitTester/FunctionalTester generated by `codecept build`)
    ├── _craft/
    │   ├── config/{test,general,app}.php
    │   ├── storage/.gitignore
    │   └── templates/parts-kit/             # fixture templates for Navigation tests
    │       ├── button/{default,blue,_ignore}.twig
    │       └── cta-block/default.twig
    ├── unit/
    │   ├── NavNodeTest.php
    │   └── services/NavigationTest.php
    └── functional/
        └── PartsKitRouteCest.php

---

## Implementation Units

### U1. Add Codeception dev dependencies & composer scripts

**Goal:** Make the Codeception toolchain installable and runnable from the repo.

**Requirements:** R1

**Dependencies:** None

**Files:**
- Modify: `composer.json` (add `require-dev` packages, `autoload-dev` test namespace, `test`/`test-unit`/`test-functional` scripts)

**Approach:**
- Add `require-dev`: `codeception/codeception ^5.0.11`, `codeception/module-asserts ^3.0.0`, `codeception/module-yii2 ^1.1.9`, `codeception/module-phpbrowser ^3.0.0`, `vlucas/phpdotenv ^5.5` — mirroring base's pinned versions. The `\craft\test\Craft` module ships with `craftcms/cms` (already required), so no extra package for it.
- Add `autoload-dev` PSR-4: `viget\partskit\tests\` → `tests/`.
- Add scripts: `test: codecept run`, `test-unit: codecept run unit`, `test-functional: codecept run functional` (base used `testunit`/`testfunctional`; prefer the `composer test` convention named in #16).
- Verify the package's effective minimum stability before assuming no change is needed: it already requires `craftcms/ecs:dev-main` and `craftcms/phpstan:dev-main`, and `composer.lock` is git-ignored, so `composer install` resolves fresh each time. If adding the Codeception stack triggers a stability-resolution failure, mirror base's `minimum-stability: dev` + `prefer-stable: true`.

**Patterns to follow:** `craft-viget-base/composer.json` `require-dev` + `scripts`.

**Test scenarios:**
- Test expectation: none — manifest-only change.

**Verification:** `composer install` succeeds; `./vendor/bin/codecept` is present and prints its version.

---

### U2. Scaffold Codeception harness & Craft test instance

**Goal:** A booting test harness: root config, both suite configs, the `tests/_craft` Craft instance, env files, and a single smoke test that proves Craft starts.

**Requirements:** R1, R2

**Dependencies:** U1

**Files:**
- Create: `codeception.yml`
- Create: `tests/unit.suite.yml`, `tests/functional.suite.yml`
- Create: `tests/_bootstrap.php`
- Create: `tests/_support/Helper/Unit.php`, `tests/_support/Helper/Functional.php`
- Create: `tests/_craft/config/test.php`, `tests/_craft/config/general.php`, `tests/_craft/config/app.php`
- Create: `tests/_craft/storage/.gitignore`
- Create: `tests/.env.example.mysql`, `tests/.env.example.pgsql`, `tests/.gitignore`
- Create: `tests/unit/HarnessBootTest.php` (smoke)
- Test: `tests/unit/HarnessBootTest.php`

**Approach:**
- Copy base's `codeception.yml` structure; in the `\craft\test\Craft` module config, set `plugins: [{ class: viget\partskit\Plugin, handle: parts-kit }]` (vs. base's empty array). Keep `dbSetup: { clean: true, setupCraft: true }` and the `fast` env override.
- `tests/_bootstrap.php`: define `CRAFT_*` path constants pointing at `tests/_craft/*`, then `TestSetup::configureCraft()` — copy base verbatim, adjusting only the vendor path if needed.
- `tests/_craft/config/test.php`: `return TestSetup::createTestCraftObjectConfig();`.
- `tests/_craft/config/app.php`: reserved for plugin/module registration if root-package discovery requires it (see Deferred to Implementation). May start empty/minimal.
- Generate the `UnitTester`/`FunctionalTester` actor classes via `codecept build`. These live in `tests/_support/` and are **committed** (as in base); only `tests/_support/_generated/` (the regenerated `*Actions` traits) is git-ignored via its own `.gitignore`.
- `tests/.gitignore` ignores `.env` (DB credentials) and `_output/` (logs and run output) — satisfying R6's "test artifacts are git-ignored".
- Smoke test asserts `Craft::$app` is an `Application` instance — proves the DB-backed harness boots before any real assertions are written.

**Patterns to follow:** `craft-viget-base/tests/{_bootstrap.php,_craft/config/test.php,unit.suite.yml,functional.suite.yml,.gitignore}`.

**Test scenarios:**
- Happy path: harness boot smoke — running `codecept run unit` executes `HarnessBootTest` and `Craft::$app` resolves to a Craft application instance → green.

**Verification:** `composer test-unit` boots Craft against a local DB and the smoke test passes; `tests/_output` and `tests/.env` are git-ignored.

---

### U3. Unit suite: starter tests for NavNode & Navigation

**Goal:** Real coverage of the plugin's most logic-heavy stable code, including the behavior around open bug #9.

**Requirements:** R3

**Dependencies:** U2

**Files:**
- Create: `tests/unit/NavNodeTest.php`
- Create: `tests/unit/services/NavigationTest.php`
- Create: `tests/_craft/templates/parts-kit/button/default.twig`, `.../button/blue.twig`, `.../button/_ignore.twig`, `.../cta-block/default.twig` (fixtures)
- Test: `tests/unit/NavNodeTest.php`, `tests/unit/services/NavigationTest.php`

**Approach:**
- Instantiate `new NavNode(...)` and `new Navigation()` directly — no plugin install needed. `Navigation::getNav()` reads `getSiteTemplatesPath()`, which the test instance points at `tests/_craft/templates`, so the fixture templates drive the assertions.
- Fixtures reproduce a realistic parts-kit tree plus an underscore-prefixed file to exercise the hidden-path filter.
- **Prerequisite (verified blocker for this unit).** `src/services/Navigation.php:8` imports `use illuminate\Support\Collection;` with a lowercase `i`. Composer PSR-4 prefixes are case-sensitive, so in an isolated unit test — plugin not installed, no other plugin class loaded first — `getNav()` fatals with "Class illuminate\Support\Collection not found". It only works at runtime because `ViewController` loads the correctly-cased `Illuminate\Support\Collection` first. Resolve before/with this unit: either correct the import to `Illuminate\Support\Collection` (a one-character `src` fix — brushes the "no behavior change" boundary but is required for R3), or have the test deterministically trigger the correctly-cased autoload first. See Open Questions. Prefer the import fix.

**Execution note:** For the bug-#9 boundary (first directory with no direct `.twig`), write a **characterization** test that asserts current behavior and reference #9 in a comment — do not assume the fixed behavior. Tag it (e.g. `@group bug-9`) and word its assertion message as "asserts current buggy behavior — flip when #9 is fixed", so the eventual #9 fix updates it deliberately rather than reading as a surprise regression.

**Patterns to follow:** `craft-viget-base/tests/unit/PartsKitTest.php` (test class shape, `_fixtures()` if a DB fixture is ever needed); `Codeception\Test\Unit` base class.

**Test scenarios:**
- Happy path (NavNode): `jsonSerialize()` returns `title`, `url`, `children` and **omits** `path`.
- Edge case (NavNode): `children` defaults to `[]` and `url` may be `null` (directory node).
- Happy path (Navigation): given the fixture tree, `getNav()` returns top-level nodes `Button` and `Cta Block`, alpha-sorted, each with file children sorted by title.
- Happy path (Navigation): a file node's `url` is `/parts-kit/button/default` (extension stripped); a directory node's `url` is `null`.
- Edge case (Navigation): `_ignore.twig` (underscore) and any dot-prefixed segment are excluded from the tree.
- Edge case (Navigation): a root `index.twig`/`index.html` is skipped.
- Edge case (Navigation): title formatting — `cta-block` → `Cta Block` (humanized, dashes→spaces).
- Characterization (Navigation, relates to #9): a first directory containing only a subdirectory (no direct `.twig`) — assert the current observed result and link #9.

**Verification:** `composer test-unit` runs all unit tests green; assertions fail loudly if fixture tree shape changes.

---

### U4. Functional suite: `/parts-kit` route smoke test

**Goal:** Prove the plugin's permission-gated route renders end-to-end inside the functional suite.

**Requirements:** R4

**Dependencies:** U2

**Files:**
- Create: `tests/functional/PartsKitRouteCest.php`
- Test: `tests/functional/PartsKitRouteCest.php`

**Approach:**
- Functional tests require the plugin **installed/registered** so its URL rules and permission are active — this is where the root-package discovery question (Deferred to Implementation) must be resolved.
- The route is gated by `parts-kit:view`; the authorized-access test logs in a permissioned/admin user before requesting the configured parts-kit directory URL.

**Execution note:** Be aware that base's functional suite is **not** a working precedent — `craft-viget-base/tests/functional/PartialCest.php` has *both* tests permanently `markTestIncomplete('Twig extensions don\'t load for some reason')`. So functional route/template rendering through the `\craft\test\Craft` connector has a known-fragile track record, and parts-kit's functional wiring must be solved from scratch, not adapted. If it can't be made green within this ticket's scope, `markTestIncomplete()` with a clear reason so the harness and unit suite still land — and treat R4 as effectively deferred (see Open Questions) rather than a guaranteed deliverable.

**Patterns to follow:** `craft-viget-base/tests/functional/PartialCest.php` (Cest shape, `$I->amOnPage(...)`, `$I->see(...)`, `markTestIncomplete`).

**Test scenarios:**
- Happy path: an authenticated user with `parts-kit:view` visits the parts-kit directory URL → 200 and the parts kit UI shell is present.
- Error path: an anonymous/unauthorized request to the same URL is denied (redirect to login or 403), confirming the permission gate.

**Verification:** `composer test-functional` runs green, or the test is explicitly `markTestIncomplete` with a documented reason — never silently skipped.

---

### U5. GitHub Actions CI workflow

**Goal:** Run the suite automatically on push and PR across MySQL + PostgreSQL on PHP 8.2.

**Requirements:** R5

**Dependencies:** U2, U3, U4

**Files:**
- Create: `.github/workflows/codecept.yml` (reusable, `workflow_call`, matrix)
- Create: `.github/workflows/ci.yml` (triggers, calls `codecept.yml`)

**Approach:**
- Copy base's reusable `codecept.yml`: MySQL 8.0 + Postgres service containers with health checks, PHP extension caching, `setup-php` for 8.2, copy `tests/.env.example.${db}` → `tests/.env`, set the DB password via `sed`, MySQL timezone import, then `./vendor/bin/codecept run unit,functional --fail-fast`.
- `ci.yml`: trigger on `push` (this repo's default branch is `main`, not base's `v5`), `pull_request`, and `workflow_dispatch`; concurrency-cancel in progress; call `codecept.yml` with `php_versions: '["8.2"]'`.
- Existing `.github/workflows/create-release.yml` is untouched.

**Patterns to follow:** `craft-viget-base/.github/workflows/{ci.yml,codecept.yml}`.

**Test scenarios:**
- Test expectation: none — CI configuration. Validated by observing the workflow run, not by an in-repo test.

**Verification:** Pushing the branch triggers the workflow; both MySQL and PostgreSQL jobs run the suite and report status on the PR.

---

### U6. Document testing in README

**Goal:** Tell contributors how to run the suite locally.

**Requirements:** R6

**Dependencies:** U1

**Files:**
- Modify: `README.md` (add a "Testing" / "Development" section)

**Approach:**
- Document: install dev deps (`composer install`), create `tests/.env` from `tests/.env.example.mysql` (or pgsql), ensure a local `craft_test` database, then `composer test` / `composer test-unit` / `composer test-functional`. Mention the `--env fast` option for skipping DB rebuilds.

**Patterns to follow:** Existing `README.md` structure and tone.

**Test scenarios:**
- Test expectation: none — documentation.

**Verification:** A new contributor can follow the README to run the suite without consulting `craft-viget-base`.

---

## System-Wide Impact

- **Interaction graph:** Adds a `tests/` tree, a root `codeception.yml`, two CI workflow files, and `composer.json` dev-only changes. No `src/` runtime code changes. Existing `create-release.yml` workflow and the ECS/PHPStan tooling are unaffected.
- **Error propagation:** Test failures surface via non-zero `codecept` exit codes locally and as failed CI checks on PRs.
- **State lifecycle risks:** The `\craft\test\Craft` module wraps tests in transactions and rebuilds the test DB (`clean: true`); requires a disposable `craft_test` database locally and in CI — never point it at a real database.
- **API surface parity:** None — no public plugin API changes.
- **Unchanged invariants:** All `src/` behavior, the plugin's permission, URL rules, and Twig variable remain exactly as-is; tests observe behavior, they do not modify it.

---

## Risks & Dependencies

| Risk | Mitigation |
|------|------------|
| Functional route/template rendering through the `\craft\test\Craft` connector is fragile — base's own functional tests are 100% `markTestIncomplete`. | Solve parts-kit functional wiring from scratch (no working precedent in base); if it can't go green in scope, `markTestIncomplete` and treat R4 as deferred so the harness + unit suite still land. |
| `Navigation`'s isolated unit test fatals on the case-sensitive `use illuminate\Support\Collection;` import (works in prod only via load order). | Fix the import casing to `Illuminate\…` as a one-character prerequisite (preferred), or have the test trigger the correctly-cased autoload first. |
| `/parts-kit` is permission-gated, so functional requests need an authenticated user. | Log in a permissioned/admin user in the functional test; treat exact auth wiring as an execution detail. |
| CI DB service containers can be flaky or slow to become ready. | Mirror base's health-checks, extension caching, and MySQL timezone import verbatim. |
| `craftcms/cms` test-framework or Codeception version drift vs. base. | Pin `require-dev` to base's known-good `^` versions. Note: `composer.lock` is git-ignored in this repo (commit bedc160), so CI resolves the `^` constraints fresh each run — there is no lock to "rely on". Accept floating-within-constraint, or commit a lock if CI reproducibility becomes necessary (reverses the current ignore policy). |
| Local runs need a `craft_test` database that doesn't exist by default. | README documents DB creation and `.env` setup; CI provisions DBs via service containers. |

---

## Documentation / Operational Notes

- README gains a Testing section (U6). No runtime, rollout, or monitoring impact — this is dev-tooling and CI only.
- New required local prerequisite for contributors who run tests: a disposable `craft_test` database and a `tests/.env` file (git-ignored).

---

## Sources & References

- Reference setup (sibling repo): `craft-viget-base` — `codeception.yml`, `tests/`, `.github/workflows/{ci,codecept}.yml`, `composer.json`.
- Craft test framework source: `vendor/craftcms/cms/src/test/Craft.php` (`installPlugin`, `mockModulesAndPlugins`, `plugins` config shape).
- Craft 5 testing docs: https://craftcms.com/docs/5.x/testing/
- Related code: `src/services/Navigation.php`, `src/models/NavNode.php`, `src/Plugin.php`
- Related issues: #16 (this ticket), #15 (Mock Asset Service — deferred test coverage), #9 (navigation first-folder bug — characterized in U3)
