---
title: "chore: Green static analysis and enforce it in CI"
type: chore
status: completed
created: 2026-06-02
depth: standard
---

# chore: Green static analysis and enforce it in CI

## Summary

Today the plugin ships PHPStan (level 4) and ECS (Craft CMS ruleset), but neither runs in CI and PHPStan reports **13 errors** at level 4. CI runs only the Codeception suite, so style and static-analysis regressions never surface. This plan makes static analysis green and turns it into an enforced gate, in three moves:

1. **Delete the MockAsset concept entirely** (per the user's directive — it will be rebuilt from the ground up later). This removes 11 of the 13 PHPStan errors at their source rather than fixing or baselining work-in-progress code.
2. **Fix the 2 remaining production errors** — the `Settings::$directory` type-narrowing errors in `Plugin.php` and `ViewController.php` — getting `src/` to a clean level-4 run.
3. **Expand ECS + PHPStan scope to `tests/`** with exclusions for Codeception-generated/actor files, then **add a single-run static-analysis job to CI** so `check-cs` and `phpstan` run on every push and PR.

Resolves [#21](https://github.com/vigetlabs/craft-parts-kit/issues/21) (now via deletion rather than baseline) and [#18](https://github.com/vigetlabs/craft-parts-kit/issues/18).

---

## Problem Frame

The Codeception suite ([#16](https://github.com/vigetlabs/craft-parts-kit/issues/16) / PR #17) added PHP under `tests/`, but `ecs.php` and `phpstan.neon` both scope only `src`, and `.github/workflows/ci.yml` → `codecept.yml` runs only Codeception. So:

- Test-code style and type regressions get no coverage.
- PHPStan already reports 13 level-4 errors in `src/` that nobody sees because it never runs in CI.

Of those 13 errors, 11 come from `MockAsset` / `MockAssetBuilder` — a work-in-progress feature ([#15](https://github.com/vigetlabs/craft-parts-kit/issues/15)) whose `@throws` PHPDoc tags reference exception classes that don't exist. Issue #21 framed these as "fix or baseline, coordinating with #15." The user has decided instead to **remove the MockAsset concept outright and rebuild it later**, which deletes those 11 errors at the source and removes the dependency on #15. The remaining 2 errors are real: `getSettings()` returns the base `craft\base\Model`, so PHPStan can't see `Settings::$directory`.

This is groundwork: a green, enforced static-analysis baseline is a precondition for confidently extending the plugin (including the eventual MockAsset rebuild).

---

## Requirements

- **R1.** The MockAsset concept (`MockAsset`, `MockAssetBuilder`, the `assets` service, and all wiring) is fully removed from `src/`, with no dangling references. (origin: #21 — chosen resolution; user directive)
- **R2.** The 2 `Settings::$directory` errors in `Plugin.php` and `ViewController.php` are fixed by narrowing the settings type. (origin: #21)
- **R3.** `composer phpstan` exits 0 at level 4 against `src/`. (origin: #21 acceptance criteria)
- **R4.** `ecs.php` and `phpstan.neon` both include `tests` in their paths, with skip/exclude rules for Codeception-generated files (`tests/_support/_generated/`) and the `UnitTester`/`FunctionalTester` actor classes. (origin: #18 req 1)
- **R5.** `composer check-cs` and `composer phpstan` both exit 0 against the expanded `src/` + `tests/` scope. (origin: #18)
- **R6.** CI runs `check-cs` and `phpstan` on push and pull request, as a single run (not multiplied across the DB matrix). (origin: #18 req 2)

---

## Scope Boundaries

**In scope:** Deleting MockAsset; fixing the 2 type errors; expanding ECS + PHPStan scope to `tests/`; triaging the resulting `tests/` findings; adding a CI static-analysis gate.

**Non-goals:**
- Rebuilding the Mock Asset Service. That is deliberately deferred ([#15](https://github.com/vigetlabs/craft-parts-kit/issues/15) / a future rebuild) and is **not** part of this plan. This plan only removes the current broken implementation.
- Raising the PHPStan level above 4, or changing the ECS ruleset.
- Adding new test coverage for plugin behavior (the Codeception suite is unchanged here).

### Deferred to Follow-Up Work
- The MockAsset rebuild itself (separate future effort; this plan is the clean-slate precondition).

---

## Key Technical Decisions

- **Delete, don't baseline, the MockAsset errors.** A PHPStan baseline file would carry 11 known-bad entries that the future rebuild then has to reconcile. Deleting the WIP code removes the errors at the source and leaves a clean slate. (Per user directive.)
- **Fix both `$directory` errors with one PHPDoc annotation.** Both `Plugin.php` and `ViewController.php` reach the property through `Plugin::...->getSettings()->directory`. Adding `@method Settings getSettings()` to the `Plugin` class docblock narrows the return type for PHPStan at both call sites without touching runtime behavior or adding `instanceof` noise. This is the idiomatic Craft plugin pattern. (origin: #21 lists this among the acceptable fixes.)
- **Static analysis is its own CI job, not part of the DB matrix.** `codecept.yml` runs across MySQL × PostgreSQL (2 legs, extensible). PHPStan and ECS need no database and must not run redundantly. Add a separate lightweight job to `ci.yml` (single PHP 8.2 run, no services). This keeps the gate fast and the matrix semantically clean.
- **Exclude generated/actor files from analysis, don't lint them.** `tests/_support/_generated/*` and the `UnitTester`/`FunctionalTester` actor classes are Codeception-generated and rely on magic that PHPStan can't resolve without stubs. Exclude them via `excludePaths` (PHPStan) and `skip` (ECS) rather than annotating generated code.

---

## Dependency Graph

```
U1 (delete MockAsset) ─┐
                       ├─→ U3 (src green @ level 4) ─→ U6 (CI gate)
U2 (fix $directory) ───┘                                  ↑
                                                          │
U4 (ECS → tests/) ────────────────────────────────────────┤
U5 (PHPStan → tests/) ────────────────────────────────────┘
```

U1 and U2 are independent of each other but both feed the green-`src/` state. U3 is a verification checkpoint (no new code). U4 and U5 are independent. U6 (CI) depends on everything being green.

---

## Implementation Units

### U1. Delete the MockAsset concept

**Goal:** Remove all MockAsset code and wiring from `src/`, eliminating the 11 WIP PHPStan errors at their source.

**Requirements:** R1

**Dependencies:** none

**Files:**
- `src/models/MockAsset.php` — delete
- `src/models/MockAssetBuilder.php` — delete
- `src/services/Assets.php` — delete (it exists only to return a `MockAssetBuilder` via `make()`)
- `src/Plugin.php` — remove the `use viget\partskit\services\Assets;` import, the `@property-read Assets $assetService` PHPDoc line, and the `'assets' => Assets::class` entry in `config()`'s `components` array
- `CLAUDE.md` — remove the MockAsset/MockAssetBuilder "Gotchas" bullet (it documents now-deleted code)

**Approach:** The surface is fully self-contained — `grep` confirms `MockAsset` is referenced only by these three files, and the `assets` component is registered only in `Plugin.php`. No templates, controllers, or tests reference it. Remove the three model/service files, then strip the three `Plugin.php` references. The `navigation` component registration and `getNavigation()` accessor are untouched.

**Patterns to follow:** Leave the `navigation` component wiring in `Plugin::config()` as the reference shape for component registration — only the `assets` line is removed.

**Test scenarios:** Test expectation: none — pure deletion of unused, unreferenced code. Verification is via the static-analysis and existing-suite runs below.

**Verification:**
- `grep -rn "MockAsset\|assetService\|services\\\\Assets" src/` returns nothing.
- The existing Codeception suite still passes (`composer test`) — nothing depended on the deleted code.

---

### U2. Narrow the settings type to fix the `$directory` errors

**Goal:** Resolve the 2 real PHPStan errors where `Settings::$directory` is accessed off a base-`Model`-typed `getSettings()`.

**Requirements:** R2

**Dependencies:** none

**Files:**
- `src/Plugin.php` — add a `@method Settings getSettings()` annotation to the class-level docblock (the `Settings` class is already imported). Affected call site: `_registerUrlRules()` (`$this->getSettings()->directory`).
- `src/controllers/ViewController.php` — call site `actionTemplate()` (`Plugin::getInstance()->getSettings()->directory`) is fixed transitively by the `Plugin` annotation; verify no further change is needed.

**Approach:** Both errors stem from `getSettings()` being inherited as `craft\base\Model`. Declaring `@method Settings getSettings()` on the `Plugin` class tells PHPStan the concrete return type, resolving both call sites with one annotation and zero runtime change. If for any reason the `@method` tag doesn't satisfy level 4 at the `ViewController` call site (it routes through `Plugin::getInstance()`), fall back to a local `@var Settings` assertion there — but the class-level annotation is expected to suffice.

**Patterns to follow:** The existing `@method static Plugin getInstance()` annotation already present on the `Plugin` docblock — add the `getSettings()` `@method` tag alongside it.

**Test scenarios:** Test expectation: none — type-only annotation, no behavioral change. Covered by the PHPStan run in U3.

**Verification:**
- `composer phpstan` reports zero `Access to an undefined property` errors for `$directory`.

---

### U3. Confirm `src/` is green at level 4

**Goal:** Verify the combined effect of U1 + U2: a clean PHPStan run against `src/` at level 4.

**Requirements:** R3

**Dependencies:** U1, U2

**Files:** none (verification checkpoint — no `phpstan.neon` change yet; scope is still `src`)

**Approach:** This is an explicit gate, not new code. Run `composer phpstan` and confirm it exits 0. If residual errors remain, they belong to U1/U2 and are resolved there before proceeding. Sequencing U3 before the `tests/` scope expansion (U4/U5) isolates "is `src/` clean?" from "did expanding scope surface new findings?", which keeps triage legible.

**Test scenarios:** Test expectation: none — verification step.

**Verification:**
- `composer phpstan` exits 0.
- `composer test` still passes (regression guard on the deletion + annotation).

---

### U4. Expand ECS scope to `tests/`

**Goal:** Lint `tests/` under the Craft CMS ECS ruleset, excluding generated/actor files, and autofix the surfaced findings.

**Requirements:** R4, R5

**Dependencies:** none (independent of U5)

**Files:**
- `ecs.php` — add `__DIR__ . '/tests'` to `paths()`; add a `skip()` rule for `tests/_support/_generated/*` and the `UnitTester`/`FunctionalTester` actor classes
- `tests/**` — any files ECS autofix touches (whitespace, ordering, etc. — exact set discovered at implementation time)

**Approach:** Add `tests` to the paths array, then run `composer check-cs` to enumerate findings. Expect an initial batch in hand-written test files (`tests/unit/`, `tests/functional/`, `tests/_support/Helper/`). Apply `composer fix-cs` for autofixable rules; manually resolve any that aren't. Skip generated actors and `_generated/` so ECS never rewrites regenerated code. Keep the `skip()` list as tight as possible — exclude only genuinely generated files, not hand-written tests.

> **Directional note (not implementation spec):** the `skip` shape is roughly `skip([__DIR__ . '/tests/_support/_generated', UnitTester::class, FunctionalTester::class])`. Confirm the exact ECS skip syntax (path glob vs. class reference) against the installed ECS version during implementation.

**Patterns to follow:** The existing `ecs.php` structure (`paths()` + `sets([SetList::CRAFT_CMS_4])`) — extend it, don't restructure it.

**Test scenarios:** Test expectation: none — lint configuration and autofix; behavior unchanged. Verification is the clean `check-cs` run.

**Verification:**
- `composer check-cs` exits 0 across `src/` + `tests/`.
- Generated actors and `_generated/` are untouched by `fix-cs` (confirm they don't appear in the diff).

---

### U5. Expand PHPStan scope to `tests/`

**Goal:** Analyze `tests/` at level 4, excluding generated/actor files and accounting for Codeception's magic `$I`/actor methods, and resolve the surfaced findings.

**Requirements:** R4, R5

**Dependencies:** none (independent of U4; but see Risk note on interaction with U3)

**Files:**
- `phpstan.neon` — add `tests` to `paths`; add `excludePaths` for `tests/_support/_generated/*` and the actor classes; add targeted `ignoreErrors` or stub config if Codeception magic methods (`$I->...`, `$this->tester->...`) surface unresolvable-method errors that exclusion alone doesn't cover
- `tests/**` — any genuine type issues PHPStan flags in hand-written tests (exact set discovered at implementation time)

**Approach:** Add `tests` to `paths`, run `composer phpstan`, and triage. Two error classes are expected: (a) generated/actor magic methods — handled by `excludePaths`; (b) real findings in hand-written tests — fixed directly. Prefer `excludePaths` over broad `ignoreErrors`; reach for `ignoreErrors` only for Codeception-idiomatic patterns that are correct but unresolvable without stubs, and scope each ignore as narrowly as possible (specific message + path). Do **not** lower the level to silence test noise — exclusion/ignore is the right tool.

> **Directional note (not implementation spec):** if magic-method errors are widespread, evaluate whether a Codeception/PHPStan stub or the `phpstan/extension-installer` path is warranted, but default to the minimal `excludePaths` + targeted `ignoreErrors` approach first. Decide based on the actual findings, not in advance.

**Patterns to follow:** The existing `phpstan.neon` (`includes` the Craft PHPStan config, `level: 4`, `paths`) — extend `paths`, add `excludePaths` as a sibling parameter.

**Test scenarios:** Test expectation: none — static-analysis configuration; no behavioral change.

**Verification:**
- `composer phpstan` exits 0 across `src/` + `tests/` at level 4.
- Excludes are minimal — only generated/actor files, confirmed by reviewing the `excludePaths`/`ignoreErrors` list.

---

### U6. Add a static-analysis CI gate

**Goal:** Run `check-cs` and `phpstan` on every push and pull request as a single, database-free CI job.

**Requirements:** R6

**Dependencies:** U3, U4, U5 (CI must only be turned on once everything is green, or the gate fails immediately)

**Files:**
- `.github/workflows/ci.yml` — add a `static-analysis` job alongside the existing `tests` job (single PHP 8.2 run, no DB services), running `composer install` then `composer check-cs` and `composer phpstan`. Alternatively, factor it into a small reusable `static-analysis.yml` called from `ci.yml`, mirroring how `tests` calls `codecept.yml` — choose based on whether the inline job stays small.

**Approach:** Model the new job on the non-DB steps of `codecept.yml` (checkout → setup-php with the existing extension list → Composer cache keyed on `composer.json`, since `composer.lock` is git-ignored → `composer install`). Then run the two static-analysis composer scripts. No MySQL/PostgreSQL services, no test `.env`, no DB matrix — a single leg. Keep it in the same `ci.yml` trigger set (`push` to `main`, `pull_request`, `workflow_dispatch`) so it gates the same events as the suite.

**Patterns to follow:**
- `codecept.yml` for the PHP setup / Composer-cache steps (reuse the `PHP_EXTENSIONS` list and the `composer.json`-keyed cache — see CLAUDE.md note that `composer.lock` is git-ignored here).
- `ci.yml`'s existing `tests` job as the structural sibling for how a job is declared and triggered.

**Test scenarios:** Test expectation: none — CI configuration. Validated by the workflow running green on this plan's own PR.

**Verification:**
- On the PR that lands this work, the new `static-analysis` job appears in checks and passes.
- Deliberately introducing a style or type error locally makes `check-cs`/`phpstan` fail (confirms the gate actually gates) — revert before merge.

---

## Risks & Mitigations

- **Expanding PHPStan to `tests/` surfaces more findings than expected.** Mitigation: U5 is sequenced after `src/` is already green (U3), so test-scope findings are triaged in isolation. Prefer narrow `excludePaths`/`ignoreErrors`; never lower the level.
- **CI gate flips red on unrelated future test regeneration.** Mitigation: excluding `tests/_support/_generated/*` and the actor classes means regenerated Codeception code is never analyzed, so routine `codecept build` output won't break the gate.
- **The `@method` annotation doesn't fully satisfy the `ViewController` call site.** Mitigation: documented fallback in U2 (local `@var Settings` assertion). Low risk — both sites resolve through the same `getSettings()`.
- **MockAsset deletion breaks something undiscovered.** Mitigation: `grep` confirms zero references outside the three files + `Plugin.php` wiring; U1/U3 verification re-runs the full Codeception suite to catch any surprise.

---

## Verification Strategy

The plan is complete when, on a single branch:

1. `composer phpstan` exits 0 at level 4 across `src/` + `tests/`.
2. `composer check-cs` exits 0 across `src/` + `tests/`.
3. `composer test` (full Codeception suite) still passes — the deletion and annotations introduced no regression.
4. The PR shows a passing `static-analysis` CI check that runs independently of the MySQL/PostgreSQL test matrix.
