---
title: "feat: DDEV + craft-install dev harness for parts-kit"
type: feat
status: completed
created: 2026-05-29
depth: standard
---

# feat: DDEV + craft-install dev harness for parts-kit

## Summary

The parts-kit plugin currently has no way to run a real Craft CMS install locally — you can read the source and run the (aspirational) Codeception harness, but you cannot boot Craft, install the plugin, and actually *see* `/parts-kit` render a component library in a browser.

This plan grafts the DDEV-based development harness from [`craft-ddev-plugin-starter`](https://github.com/vigetlabs/craft-ddev-plugin-starter) onto the existing parts-kit plugin. After this work, `ddev start && ddev setup` will boot a containerized Craft 5 install with the plugin symlinked in via a Composer path repository (so `src/` edits are live), and visiting `https://craft-parts-kit.ddev.site/parts-kit` will show the parts-kit UI populated with sample components.

Two things make this more than a copy-paste of the starter:

1. **parts-kit is already configured**, not a greenfield template. The starter ships placeholder tokens (`:plugin_handle`, etc.) and a `configure.php` find/replace script. We are not running `configure.php` — we hand-author the harness with parts-kit's real values baked in (`viget` / `parts-kit` / `viget\partskit` / `viget/craft-parts-kit`).
2. **parts-kit renders the site's `templates/parts-kit/` directory.** A generic starter with an empty site shows nothing. To demonstrate the plugin, the dev install must ship sample part templates and a dev config that allows anonymous viewing.

---

## Problem Frame & Scope

**Problem:** There is no local runtime for the plugin. Contributors cannot manually verify behavior (URL routing, the `<parts-kit>` web component, nav generation, permission gating) against a real Craft install without standing up their own project and wiring the plugin in by hand.

**Goal:** One-command-ish local setup (`ddev start` → `ddev setup`) that produces a working Craft install with parts-kit installed, the plugin live-editable, and `/parts-kit` rendering a populated component library.

### In scope
- `.ddev/` configuration and a `setup` command that installs Craft + the plugin idempotently
- A `craft-install/` Craft 5 application that loads the plugin via a Composer path-repository symlink
- A root `craft` console script
- A dev-only parts-kit config enabling anonymous viewing (per decision below)
- A small set of sample part templates so the UI is populated on first boot
- Repo hygiene so the dev harness is **not** shipped in the distributed Composer package
- README + CLAUDE.md documentation for the new workflow

### Scope Boundaries

#### Deferred to Follow-Up Work
- Pre-seeded `project.yaml` project config (this plan drives setup imperatively via the CLI instead — see Key Decisions)
- Committing `craft-install/composer.lock` for reproducible Craft versions across contributors

#### Out of scope (non-goals)
- The Codeception test harness. The committed `CLAUDE.md` describes a `tests/_craft/...` + `codeception.yml` suite, but **none of it is tracked in this branch** (`tests/` contains only an untracked `.env`). DDEV serving is a manual-runtime concern, orthogonal to that harness. This plan neither adds nor repairs it.
- CI / GitHub Actions changes.
- Fixing the hardcoded `'parts-kit'` folder name in `src/services/Navigation.php:25` (known gotcha; the sample dir uses `parts-kit`, so it is not exercised here).

---

## Key Technical Decisions

### 1. Path-repository version constraint must be branch-agnostic
The starter's `craft-install/composer.json` requires the plugin as `"dev-main"`. Composer path repositories infer the package version from the **checked-out git branch** (`dev-<branch>`). This work lands on `jp/ddev-setup`, not `main`, so a literal `"dev-main"` constraint would fail to resolve.

**Decision:** Require the plugin as `"@dev"` (with `minimum-stability: dev` + `prefer-stable: true`) and a path repository with `"symlink": true`. `"@dev"` is a stability flag that accepts whatever `dev-<branch>` the path repo reports, so it works on any branch including `main`. If `"@dev"` proves finicky on a given Composer version, fall back to `"*"`. This is the single highest-risk detail in the plan — verification must explicitly confirm the symlink resolves (see U2 verification).

### 2. `composer_root: "."` so `ddev composer` targets the plugin
The `craftcms` DDEV project type defaults `ddev composer` to the docroot's app dir (`craft-install/`). Setting `composer_root: "."` points it at the plugin root instead. Craft app dependencies are then managed via `ddev exec -d /var/www/html/craft-install composer ...`. This mirrors the starter exactly and is the source of the most common "wrong composer" gotcha — it must be documented.

### 3. Drive setup imperatively; do not pre-seed `project.yaml`
The starter ships a `project.yaml` with a pinned `system.schemaVersion` (e.g. `5.9.0.8`). A pinned schema version drifts against whatever Craft version Composer resolves and causes `project-config/apply` failures on fresh installs. **Decision:** let `ddev setup` run `install/craft` to create a fresh system, then `plugin/install parts-kit`, and omit a committed `project.yaml`. This is more robust for a single-purpose dev harness and removes a maintenance burden. The `setup` script's `project-config/apply` step from the starter is dropped (nothing to apply).

### 4. Dev-only config enables anonymous parts-kit viewing
`Settings::$requireViewPermission` defaults to `true`, which gates `/parts-kit` behind login + the `parts-kit:view` permission. **Decision (confirmed with user):** ship `craft-install/config/parts-kit.php` setting `requireViewPermission => false` so `/parts-kit` renders immediately after `ddev setup` without logging in. This file lives only inside `craft-install/` (a dev artifact, export-ignored), never in the distributed plugin, so production defaults are unchanged.

### 5. Ship sample part templates (confirmed with user)
`craft-install/templates/parts-kit/` ships a small demo set so the `<parts-kit>` UI is populated on first boot and the plugin is demonstrably working.

---

## Output Structure

New and modified files (repo-relative). Everything under `.ddev/`, `craft-install/`, and the root `craft` script is **export-ignored** so it never ships in the Composer package.

```
viget-parts-kit/
├── .ddev/
│   ├── config.yaml                      # NEW — craftcms type, docroot craft-install/web, composer_root "."
│   └── commands/web/setup               # NEW — idempotent install script
├── craft/                               # NEW (file) — root console bootstrap
├── craft-install/                       # NEW — full Craft 5 dev app
│   ├── .env.example                     # NEW
│   ├── .gitignore                       # NEW
│   ├── bootstrap.php                    # NEW
│   ├── composer.json                    # NEW — path repo → ../ (symlink), requires @dev
│   ├── config/
│   │   ├── app.php                      # NEW
│   │   ├── general.php                  # NEW
│   │   ├── routes.php                   # NEW
│   │   └── parts-kit.php                # NEW — dev override: requireViewPermission=false
│   ├── storage/.gitignore               # NEW
│   ├── templates/
│   │   ├── index.twig                   # NEW — Craft welcome page
│   │   └── parts-kit/                   # NEW — sample components
│   │       ├── index.twig               # NEW — root landing (skipped by nav)
│   │       └── button/
│   │           ├── default.twig         # NEW
│   │           └── primary.twig         # NEW
│   └── web/
│       ├── .htaccess                    # NEW
│       └── index.php                    # NEW
├── .gitattributes                       # MODIFIED — export-ignore harness paths
├── .gitignore                           # MODIFIED — ignore craft-install/.env, vendor, etc.
├── CLAUDE.md                            # MODIFIED — DDEV workflow section
└── README.md                            # MODIFIED — "Local development with DDEV" section
```

---

## Implementation Units

### U1. DDEV configuration and setup command

**Goal:** Define the DDEV project (Craft type, docroot, composer root) and an idempotent `ddev setup` command that installs Craft and the plugin.

**Dependencies:** None (but the setup command exercises U2/U3 at runtime).

**Files:**
- `.ddev/config.yaml` (create)
- `.ddev/commands/web/setup` (create, executable)

**Approach:**
- Mirror the starter's `config.yaml`: `type: craftcms`, `docroot: craft-install/web`, `php_version: "8.3"`, `webserver_type: nginx-fpm`, MariaDB 10.11, `composer_version: "2"`, and critically `composer_root: "."` (Decision 2). Set `name: craft-parts-kit` (real value, no placeholder).
- Adapt the starter's `setup` script with parts-kit values: install Craft app deps (`cd craft-install && composer install`), copy `.env` from example if absent, generate keys via `php craft setup/keys` if unset, guard the install with `php craft install/check`, then `php craft install/craft --interactive=0` with dev admin creds, then `php craft plugin/install parts-kit || php craft plugin/enable parts-kit`.
- **Drop** the starter's `project-config/apply --force` step (Decision 3 — no committed `project.yaml`).
- Echo the final site/admin URLs and the dev-only credential warning.

**Patterns to follow:** `craft-ddev-plugin-starter/.ddev/config.yaml` and `.ddev/commands/web/setup` verbatim in structure; substitute real values instead of `:ddev_project_name` / `:plugin_handle`.

**Test scenarios:** `Test expectation: none -- infrastructure config and an idempotent shell script; no unit-testable behavior. Validated by the U6 end-to-end verification (ddev start && ddev setup).`

**Verification:** `ddev setup` run twice in a row succeeds both times (idempotency): second run reports "skipping" for keys, `.env`, and install steps rather than erroring.

---

### U2. craft-install Craft 5 application skeleton

**Goal:** A full Craft 5 app under `craft-install/` that loads the plugin via a Composer path-repository symlink.

**Dependencies:** None.

**Files:**
- `craft-install/composer.json` (create)
- `craft-install/bootstrap.php` (create)
- `craft-install/web/index.php` (create)
- `craft-install/web/.htaccess` (create)
- `craft-install/config/general.php` (create)
- `craft-install/config/app.php` (create)
- `craft-install/config/routes.php` (create)
- `craft-install/.env.example` (create)
- `craft-install/.gitignore` (create)
- `craft-install/storage/.gitignore` (create)

**Approach:**
- `composer.json`: `minimum-stability: dev`, `prefer-stable: true`, require `craftcms/cms: ^5.0.0`, `viget/craft-parts-kit: "@dev"` (Decision 1), `vlucas/phpdotenv: ^5.4`. Two repositories: the Craft Composer endpoint (`https://composer.craftcms.com`, `canonical: false`) and a `type: path` repo pointing at `../` with `"options": { "symlink": true }`. Pin `config.platform.php` to `8.2` (matches the plugin's floor). Allow the `craftcms/plugin-installer` and `yiisoft/yii2-composer` plugins.
- `bootstrap.php`, `web/index.php`, `web/.htaccess`: copy from starter unchanged (no placeholders in these).
- `config/general.php`: copy starter's (omitScriptNameInUrls, preloadSingles, preventUserEnumeration, `@webroot` alias). `config/app.php`: `id` from `CRAFT_APP_ID`. `config/routes.php`: empty array.
- `.env.example`: copy starter's, substituting `PRIMARY_SITE_URL=https://craft-parts-kit.ddev.site/`. DDEV-provided DB vars (`db`/`db`/`db`) stay as-is.
- `craft-install/.gitignore`: ignore `/.env`, `/vendor/`, `/config/license.key`, `/storage/*` (keep `.gitignore`), `/web/cpresources/*`. `storage/.gitignore`: `*` + `!.gitignore`.

**Patterns to follow:** `craft-ddev-plugin-starter/craft-install/*` — but the plugin-name substitutions in `composer.json` use parts-kit's real package, and the version constraint changes from `dev-main` to `@dev`.

**Technical design (directional, not implementation spec):** the path repository is what makes `src/` edits live:
```
craft-install/vendor/viget/craft-parts-kit  ──symlink──▶  <repo root>/
```
Composer resolves `viget/craft-parts-kit @dev` from the `../` path repo and symlinks it into the app's vendor dir.

**Test scenarios:** `Test expectation: none -- app scaffolding with no plugin-owned behavior. Correctness is proven by the symlink/boot verification below and the U6 end-to-end check.`

**Verification:** After `ddev exec -d /var/www/html/craft-install composer install`, confirm `craft-install/vendor/viget/craft-parts-kit` is a symlink to the repo root (`ls -la`), and `php craft plugin/list` (or the CP Plugins screen) shows Parts Kit installable/installed. An edit to `src/Plugin.php` is reflected without re-running Composer.

---

### U3. Root console script

**Goal:** A root `craft` script so `ddev craft <command>` and `php craft <command>` bootstrap the console app through `craft-install/`.

**Dependencies:** U2 (relies on `craft-install/bootstrap.php`).

**Files:**
- `craft` (create, executable)

**Approach:** Copy the starter's root `craft` script verbatim — it requires `craft-install/bootstrap.php` and runs Craft's console bootstrap from `CRAFT_VENDOR_PATH`. No placeholders. Ensure the file is executable (`chmod +x`).

**Patterns to follow:** `craft-ddev-plugin-starter/craft`.

**Test scenarios:** `Test expectation: none -- thin bootstrap shim. Exercised by U1's setup script and U6 verification.`

**Verification:** `ddev craft help` lists Craft console commands without error.

---

### U4. parts-kit dev config and sample part templates

**Goal:** Make `/parts-kit` render a populated component library immediately after setup, without login.

**Dependencies:** U2 (templates and config live under `craft-install/`).

**Files:**
- `craft-install/config/parts-kit.php` (create)
- `craft-install/templates/index.twig` (create)
- `craft-install/templates/parts-kit/index.twig` (create)
- `craft-install/templates/parts-kit/button/default.twig` (create)
- `craft-install/templates/parts-kit/button/primary.twig` (create)

**Approach:**
- `config/parts-kit.php`: return `['requireViewPermission' => false]` (Decision 4). A short comment marks it dev-only. Craft auto-maps `config/<handle>.php` to plugin settings, so no further wiring is needed.
- `templates/index.twig`: copy the starter's Craft welcome page (gives a sensible site root; not strictly required but avoids a 404 at `/`).
- `templates/parts-kit/`: a minimal but real demo. `button/default.twig` and `button/primary.twig` render plain styled buttons (inline styles or a `<style>` block are fine — no build step). `parts-kit/index.twig` is a simple landing message; recall it is intentionally **skipped** by `Navigation` (root `index.twig` is excluded) so it will not appear as a nav node — that is correct behavior to exercise.

**Patterns to follow:** Existing markup conventions in `README.md`'s usage examples and `src/templates/root.twig` (parts are plain Twig fragments, included directly — no `{% layout %}`). The `default`/`primary` naming under `button/` produces clean URLs `/parts-kit/button/default` and `/parts-kit/button/primary`, matching the README's documented URL shape.

**Test scenarios:** `Test expectation: none -- fixture templates and a config value, not plugin logic.` Behavior is verified manually:
- Visiting `/parts-kit` (anonymous) returns 200 and renders the `<parts-kit>` web component (not a login redirect) — confirms Decision 4 config is applied.
- The nav lists a "Button" group with "Default" and "Primary" pages; the root `parts-kit/index.twig` does **not** appear as its own nav entry.
- Visiting `/parts-kit/button/default` renders the button fragment inside `root.twig`.

**Verification:** Anonymous browser load of `https://craft-parts-kit.ddev.site/parts-kit` shows the populated library; clicking a part loads it in the iframe.

---

### U5. Repo hygiene — keep the harness out of the distributed package

**Goal:** Ensure the DDEV harness and Craft install are git-tracked for contributors but **excluded** from the Composer archive and from accidental env/vendor commits.

**Dependencies:** U1, U2, U3 (the paths being ignored/exported must exist).

**Files:**
- `.gitattributes` (modify)
- `.gitignore` (modify)

**Approach:**
- `.gitattributes`: add `export-ignore` entries for `craft-install/`, `.ddev/`, and the root `craft` script (and `configure.php` is N/A here). This keeps the dev harness out of `composer archive` / Packagist tarballs, matching the existing pattern that already export-ignores `tests/`, `.github/`, etc. Preserve the existing `* text=auto` line.
- `.gitignore`: add `craft-install/.env`, `craft-install/vendor/`, `craft-install/storage/*` (the `craft-install/.gitignore` from U2 also covers these locally; the root entries are belt-and-suspenders and make intent explicit). Do not re-ignore anything already covered. Note the repo already ignores `/vendor`, `.env`, `composer.lock`, `.vscode`.

**Patterns to follow:** Existing `.gitattributes` export-ignore block; `craft-ddev-plugin-starter/.gitattributes`.

**Test scenarios:**
- Run `git archive HEAD | tar -t` (or `composer archive --format=tar` then inspect) and confirm no `craft-install/`, `.ddev/`, or root `craft` entries appear — only the distributable plugin (`src/`, `composer.json`, `LICENSE.md`, etc.).
- Confirm `git status` after `ddev setup` shows no `craft-install/.env`, `craft-install/vendor/`, or `craft-install/storage/*` as untracked/modified.

**Verification:** The Composer archive contains the plugin payload and excludes all dev-harness paths.

---

### U6. Documentation — README and CLAUDE.md

**Goal:** Document the DDEV workflow so a contributor can go from clone to a running `/parts-kit` without reverse-engineering the harness.

**Dependencies:** U1–U5 (documents the finished workflow).

**Files:**
- `README.md` (modify)
- `CLAUDE.md` (modify)

**Approach:**
- `README.md`: add a "Local development with DDEV" section: prerequisites (DDEV installed), `ddev start` → `ddev setup`, the resulting URLs (`https://craft-parts-kit.ddev.site` and `/admin`, login `admin` / `password`), and the two key gotchas — (a) `ddev composer` operates on the **plugin root** (use `ddev exec -d /var/www/html/craft-install composer ...` for Craft app deps), and (b) run `ddev craft clear-caches/cp-resources` after JS/CSS changes. Note that the dev install ships sample parts and allows anonymous `/parts-kit` viewing via `craft-install/config/parts-kit.php`.
- `CLAUDE.md`: add a "Local runtime (DDEV)" subsection under or near Commands describing the root↔`craft-install` path-repo relationship, the `composer_root: "."` gotcha, and that `craft-install/` is a dev-only, export-ignored harness distinct from both the plugin `src/` and the (currently-absent) Codeception harness. Optionally correct the test-harness description if it misleads, but that is out of scope per Scope Boundaries — at minimum, do not let the new DDEV docs contradict the existing test docs.

**Patterns to follow:** `craft-ddev-plugin-starter/README.md` (Development section, Useful Commands table) and `craft-ddev-plugin-starter/CLAUDE.md` (Key Relationship section); adapt to parts-kit's real names and the anonymous-viewing/sample-parts specifics.

**Test scenarios:** `Test expectation: none -- documentation. Validated by following the documented steps and reaching a working /parts-kit (the U6 end-to-end verification).`

**Verification (end-to-end, whole plan):** On a clean checkout, `ddev start && ddev setup` completes, and an anonymous browser visit to `https://craft-parts-kit.ddev.site/parts-kit` shows the sample component library; `/admin` logs in with `admin`/`password`.

---

## System-Wide Impact

- **Distributed package:** unchanged at runtime — all new paths are export-ignored (U5). Consumers who `composer require viget/craft-parts-kit` get exactly what they get today.
- **Production plugin behavior:** unchanged. The anonymous-viewing override lives only in `craft-install/config/`, never in `src/`. Default `requireViewPermission` stays `true`.
- **Contributors:** gain a one-command local runtime; must have DDEV installed.
- **Repo size:** adds a `craft-install/` app skeleton (small — vendor/ and storage/ are git-ignored).

## Risks & Mitigations

| Risk | Likelihood | Mitigation |
|---|---|---|
| Path-repo `@dev` constraint fails to resolve on a non-`main` branch | Medium | Decision 1 uses `@dev` (branch-agnostic) not `dev-main`; U2 verification explicitly checks the symlink resolves; fallback to `"*"`. |
| `project-config` schema drift on fresh install | Low | Decision 3 omits committed `project.yaml`; setup installs fresh + `plugin/install`. |
| `ddev composer` run against the wrong dir confuses contributors | Medium | `composer_root: "."` is intentional (Decision 2) and explicitly documented in U6 with the `ddev exec` escape hatch. |
| unpkg CDN unreachable → blank UI | Low | Pre-existing condition (the web component already loads from unpkg); note network requirement in README. |

## Deferred / Open Implementation Notes

- Whether to commit `craft-install/composer.lock` for reproducible Craft versions across contributors — decide after observing lock churn under `minimum-stability: dev`.
- A pre-seeded `project.yaml` (and matching `ddev setup` `project-config/apply` step) could be revisited if the imperative install proves flaky.
- The hardcoded `'parts-kit'` folder name in `Navigation` is untouched; if the dev config ever sets a custom `directory`, nav generation will break (known, out-of-scope gotcha).
