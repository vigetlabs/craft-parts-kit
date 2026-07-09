---
title: Mock Asset Service for Parts Kit
type: feat
status: active
date: 2026-05-25
revised: 2026-07-09
brainstorm: docs/brainstorms/2026-05-25-mock-asset-brainstorm.md
---

# feat: Mock Asset Service for Parts Kit

> **2026-06-08 TDD rework.** This plan was restructured to a test-first execution
> strategy now that the repo has a working Codeception suite (unit + functional
> through the `\craft\test\Craft` connector), PHPStan level 4, and ECS. Two facts
> from a fresh codebase audit reshaped the phases:
>
> 1. **The feature is 100% greenfield.** Despite the original plan describing a
>    "WIP `MockAsset`" with bugs to fix, none of `MockAsset`, `MockAssetBuilder`,
>    the `Assets` service, or any controller/helper exists today. `Plugin.php` has
>    no `assets` component and no `@property-read` typo. **Phase 1 is therefore
>    scaffolding, not cleanup.**
> 2. **CI already loads `ext-imagick`.** Both `.github/workflows/ci.yml` and
>    `.github/workflows/codecept.yml` list `imagick` in `PHP_EXTENSIONS`, so
>    image-generation tests run in CI with no workflow change — the only code
>    change needed is adding `ext-imagick` to `composer.json`'s `require`.
>
> The design decisions in the Enhancement Summary below are unchanged and remain
> authoritative. What changed is *how the work is sequenced and verified*: every
> feature-bearing unit now leads with a failing test, and the acceptance criteria
> are mapped to concrete Codeception test files instead of manual verification.

> **2026-07-09 revisions** (from the mid-implementation assessment,
> `docs/plans/2026-07-09-mock-asset-implementation-assessment.md`):
>
> 1. **`ext-imagick` demoted from `require` to `suggest`** (supersedes point 2
>    above and the original U2 wording). A hard platform requirement would break
>    `composer update` for every existing 1.x install on an Imagick-less host —
>    including sites that never use mock assets — and would make the plugin
>    stricter than Craft core, which accepts GD or Imagick. The runtime guard in
>    `Assets::make()` is the enforcement point: it throws a clear
>    `RuntimeException` at first use, and the mock route is only reachable via
>    URLs minted by `make()`, so an Imagick-less site that never uses mocks never
>    hits a failure path. CI still exercises real Imagick (`PHP_EXTENSIONS`).
> 2. **Focal point defaults to Craft's center, not null** (supersedes the
>    brainstorm decision). A real image Asset never returns null from
>    `getFocalPoint()` — Craft defaults to `['x' => 0.5, 'y' => 0.5]`
>    (`Asset.php:2690`) — so a null-returning mock silently breaks
>    `object-position: {{ asset.getFocalPoint(true) }}` parity and crashes
>    `asset.getFocalPoint().x`. `MockAsset::getFocalPoint()` now mirrors Craft
>    exactly (null only for non-visual kinds; center default otherwise);
>    `getHasFocalPoint()` still reports false when unset, matching Craft.

## Enhancement Summary

**Deepened on:** 2026-05-25
**Review agents used:** security-sentinel, performance-oracle, code-simplicity-reviewer, architecture-strategist

### Refinements applied

1. **Phase split.** Phase 1 (foundation — `Assets` service scaffold, component registration, `getAssets()` getter, typed-getter/`@property-read` convention) lands as a small separate PR before the feature work. Phase 2 starts on a clean base.
2. **Builder pattern correction.** `MockAssetBuilder` holds a config array and constructs the `MockAsset` in `one()` via `new MockAsset($config)`. No mutating a half-built Asset mid-chain. Aligns with Yii's standard `new X($config)` idiom.
3. **Imager X integration extracted to a service.** `src/services/ImagerXIntegration.php` registers conditionally under `class_exists`. Keeps the soft-dependency surface in one file, matches existing Navigation/Assets service pattern.
4. **URL construction + signing extracted from `MockAsset`.** `Assets::signedUrlForImage(int $w, int $h, ?string $label): string` owns both routing and HMAC signing. The element no longer knows the controller's routing decision.
5. **Performance: fully lazy, controller-only generation.** Template rendering does **zero** Imagick and zero filesystem work — `getUrl()`/`getImg()`/`getSrcset()` only emit signed URL strings. Every PNG is generated inside `MockController` on the first HTTP request for its URL, then cached.
6. **Extensibility: keyed signed payload + transform normalization.** The signed payload is a keyed array (`['w'=>…, 'h'=>…, 'label'=>…]`), so future knobs slot in additively. All transform argument shapes — named handles, arrays, and `ImageTransform` objects — are normalized via `ImageTransforms::normalizeTransform()` before dimension resolution.
7. **Performance: drop ETag + 304.** `Cache-Control: public, max-age=31536000, immutable` is sufficient. Removes filemtime syscall and conditional-request branching.
8. **Security: tamper-proof signed URLs + visibility mirrors Parts Kit.** Mock URLs carry an HMAC-signed payload (`Security::hashData`/`validateData`); the controller validates the signature and rejects tampering with a 404. Access is **not** devMode-gated: a mock image is viewable by exactly whoever can view the Parts Kit.
9. **Security: reorder access check before signature validation.** The controller runs its permission gate before validating the token, so unauthorized requests get a uniform 403 and can't probe which tokens are valid.
10. **Security: hardcode `sendFile` disposition filename to `'mock.png'`.**
11. **Security: use `NotFoundHttpException` for 404, not custom response body.**
12. **Security: cap label length at 200 chars** in `MockAssetBuilder::label()`.
13. **Security: cap directory file count at 5,000** in `MockImageGenerator::generate()`.
14. **LSP violation acknowledgment.** `MockAsset` class docblock explicitly states the structural-subtype contract.
15. **Cut: static error PNG fallback.** Generation failure lets the exception bubble.
16. **Cut: per-render devMode warning logging.**
17. **Cut: Lite-license boot detection.** README note suffices.
18. **Cut: `@method` PHPDoc block on MockAsset.** `NotSupportedException` messages are the source of truth.
19. **Durable URLs for free.** The signed token carries the validated config, so the controller generates the PNG on demand — first request and after a cache clear — with no sidecar files.

### Rejected suggestions (with reasoning)

- **Cut bundled DejaVuSans.ttf** — standard Craft-community pattern; removing it surfaces portability bugs on slim Docker images. ~750KB is a fair price.
- **Cut fluent setters, keep only `configure(array)`** — user explicitly chose both in the brainstorm.
- **Inline `MockImageGenerator` as a private method** — static helper is the right fit; file isolation aids future refactors and **testability** (a key TDD enabler — see Testing Strategy).
- **Cut atomic temp-file + rename** — 5 lines for a real correctness property under concurrency. Keep.

### New considerations discovered

- A malicious template can fill `@storage/runtime/parts-kit-mocks/` by looping unique hashes. Bounded by 5,000-file cap.
- Future v2 security notes for pluggable generators (RCE shape), custom field support (cache collision), and config knobs for storage path (arbitrary file overwrite via rename).
- Viget consumer projects should be audited for `Asset $param` type-hints.

---

## Overview

Add a fully functional `MockAsset` element class (extending `craft\elements\Asset`) and a fluent / hash-config builder API so that Parts Kit template authors can render placeholder images without real assets uploaded locally. The mock generates real PNG files on disk lazily — on the first request for each URL (cached by SHA-1 hash of config) — serves them through a plugin controller route addressed by a **tamper-proof, HMAC-signed URL**, and integrates transparently with both Craft's native transform pipeline and Imager X (Pro). Image access **mirrors Parts Kit visibility**.

## Problem Statement

Parts Kit lets developers preview Twig components in isolation — but most production components consume Craft `Asset` instances. In local development assets aren't synced from production, asset IDs differ across machines, and setting up a real asset just to preview a component is high-friction for a no-setup dev tool.

The mock must implement the **subset of `Asset` that real-world Twig component templates actually use** (dimensions, transforms, alt, title, filename/extension/mimeType/kind, focal point) and route image production through a plugin controller serving real PNG files — without inline base64, without DB writes, and without breaking the moment a component calls `asset.getImg()` or runs a transform.

## Proposed Solution

A complete `MockAsset` implementation routing image production through a plugin controller serving real PNG files from `@storage/runtime/parts-kit-mocks/`. Each mock URL is a **tamper-proof, HMAC-signed token** (via Yii's `Security`); the controller validates the signature and, because the validated config travels in the URL, **lazily generates the PNG on the first request** (and regenerates after any cache clear). Template rendering itself performs no image generation — it only emits signed URLs. Imager X integration uses an `EVENT_BEFORE_TRANSFORM_IMAGE` listener populating `transformedImages` with a `MockTransformedImage`.

**Target Twig usage:**

```twig
{# Hash config — terse #}
{% set hero = partsKit.assets.make({
  width: 1600, height: 900, label: 'Hero', alt: 'Hero photo',
}).one %}

{# Or fluent chain — incremental #}
{% set thumb = partsKit.assets.make.width(400).height(300).label('Thumbnail').one %}

{# Components consume mocks identically to real assets #}
{{ Image({ asset: hero, transform: 'hero-transform' }) }}
{{ craft.imagerx.transformImage(hero, { width: 800, mode: 'fit' }) }}
```

---

## Technical Approach

### Architecture

```mermaid
flowchart TD
    A[Twig template] -->|partsKit.assets.make| B[Assets service]
    B -->|new MockAssetBuilder| C[MockAssetBuilder]
    C -->|->one| D[MockAsset element]
    A -->|asset.getUrl/getImg| D
    D -->|signs URL no generation| E[/parts-kit/mock/&lt;signed-token&gt;.png]
    E -->|HTTP GET| F[MockController::actionView<br/>Parts Kit gate + validateData]
    F -->|sendFile| G[(@storage/runtime/parts-kit-mocks/&lt;hash&gt;.png)]
    F -.->|first request / miss → generate| H[MockImageGenerator]
    H -.->|atomic write| G
    I[craft.imagerx.transformImage] -->|EVENT_BEFORE_TRANSFORM_IMAGE| J[ImagerXIntegration listener]
    J -->|asset instanceof MockAsset?| K{Yes?}
    K -->|Yes| L[Populate transformedImages with MockTransformedImage]
    K -->|No| M[Pass through to default transformer]
    L -->|points at MockAsset URL| E
```

### File Inventory

Every file below is **new** — confirmed by a 2026-06-08 audit. Each feature-bearing source file is paired with its test file.

| Source file | Purpose | Test file |
|---|---|---|
| `src/Plugin.php` *(modify)* | Register `assets` + `imagerXIntegration` components, `getAssets()` getter + `@property-read`, URL rule, ClearCaches registration, call `ImagerXIntegration::register()` | `tests/unit/PluginWiringTest.php` |
| `src/services/Assets.php` | `make()` returns configured `MockAssetBuilder`; boot Imagick check; `signedUrlForImage()` builds the HMAC-signed URL | `tests/unit/services/AssetsTest.php` |
| `src/services/ImagerXIntegration.php` | Conditionally registers `EVENT_BEFORE_TRANSFORM_IMAGE`; isolates soft-dependency | `tests/unit/services/ImagerXIntegrationTest.php` |
| `src/models/MockAsset.php` | Implements supported asset surface; transform methods emit signed URLs (no generation at render); `NotSupportedException` for the rest | `tests/unit/models/MockAssetTest.php` |
| `src/models/MockAssetBuilder.php` | Holds config array, constructs `MockAsset` in `one()`; fluent setters + `configure()` | `tests/unit/models/MockAssetBuilderTest.php` |
| `src/models/MockTransformedImage.php` | Implements Imager X `TransformedImageInterface` | `tests/unit/models/MockTransformedImageTest.php` |
| `src/helpers/MockImageGenerator.php` | Imagick PNG generation invoked only by the controller; atomic write, scale-to-fit, file-count cap | `tests/unit/helpers/MockImageGeneratorTest.php` |
| `src/controllers/MockController.php` | `actionView(string $token)` — Parts Kit gate, HMAC validation, lazy generation, `sendFile` | `tests/functional/MockControllerCest.php` |
| `src/resources/fonts/DejaVuSans.ttf` | Bundled font (Bitstream Vera license, redistributable) | covered indirectly by `MockImageGeneratorTest` |
| `composer.json` *(modify)* | Declare `ext-imagick` in `suggest` (runtime guard in `Assets::make()` enforces it at first use — see 2026-07-09 revision) | `composer validate` |
| `README.md` / `CHANGELOG.md` *(modify)* | Document Mock Asset API, Imager X behavior, supported surface | n/a (docs) |

### Output Structure

New paths this plan introduces (existing `src/` tree omitted):

```
src/
├── controllers/
│   └── MockController.php          # new
├── helpers/                        # new namespace (first occupant)
│   └── MockImageGenerator.php
├── models/
│   ├── MockAsset.php               # new
│   ├── MockAssetBuilder.php        # new
│   └── MockTransformedImage.php    # new
├── resources/                      # new
│   └── fonts/
│       └── DejaVuSans.ttf
└── services/
    ├── Assets.php                  # new
    └── ImagerXIntegration.php      # new
tests/
├── unit/
│   ├── PluginWiringTest.php
│   ├── helpers/MockImageGeneratorTest.php
│   ├── models/MockAssetBuilderTest.php
│   ├── models/MockAssetTest.php
│   ├── models/MockTransformedImageTest.php
│   └── services/
│       ├── AssetsTest.php
│       └── ImagerXIntegrationTest.php
└── functional/
    └── MockControllerCest.php
```

---

## Testing Strategy

This is the spine of the rework. Read it before the implementation units — it defines the suite split, the harness affordances each unit relies on, and the handful of things that genuinely *cannot* be asserted in CI.

### Suite split

- **Unit suite (`tests/unit/`)** — the bulk of coverage. The `\craft\test\Craft` module boots a full Craft app with a real DB and rolls back per test, so unit tests can call `Craft::$app->getSecurity()`, `UrlHelper::siteUrl()`, instantiate elements, and resolve transforms. Use unit tests for: the builder, the `Assets` service (URL signing + round-trip), `MockAsset` (dimension math, metadata, render-time URL emission, `NotSupportedException`), `MockImageGenerator` (real PNG output — `imagick` is loaded in CI), the cache-option registration, and the Imager X guard.
- **Functional suite (`tests/functional/`)** — HTTP-level behavior of `MockController` only. The functional connector renders through `\craft\test\Craft` (proven by `tests/functional/PartsKitRouteCest.php`), so `$I->amOnPage()`, `$I->seeResponseCodeIs()`, response headers, and `$I->amLoggedInAs()` all work. Use it for the route: 200 + headers + real PNG bytes, tamper → 404, permission gate (403/200), cold-cache lazy generation.

### Harness affordances to lean on

- **Signing round-trips in-process.** `tests/.env`/CI set a fixed `SECURITY_KEY`, so `Assets::signedUrlForImage()` and `Security::validateData()` are deterministic within a test run. Assert sign→validate round-trips and that a one-byte mutation fails validation — no HTTP needed for the crypto itself.
- **Real PNGs are cheap to assert.** `MockImageGeneratorTest` writes to a temp dir (`Craft::getAlias('@storage/runtime/...')` or `codecept_output_dir()`), then asserts pixel dimensions with `getimagesize()` and PNG signature bytes. `imagick` is present in CI (`PHP_EXTENSIONS` already includes it).
- **Prefer array/`ImageTransform` transforms over named handles in tests.** `ImageTransforms::normalizeTransform()` resolves named handles (`'hero-transform'`) by DB lookup, which the harness won't have. Tests should pass `['width' => 400, 'mode' => 'fit']` or a constructed `ImageTransform` to exercise dimension math without a DB transform row. The named-handle path is covered by the manual AC8 check.
- **`--env fast`** skips the DB rebuild between runs; the suite must run once without it first to build the schema. Use it for the inner TDD loop.

### What cannot be auto-tested (explicitly manual)

- **AC8 (Imager X Pro short-circuit)** requires `spacecatninja/imager-x` installed with a Pro license — not a CI dependency (soft dep via `class_exists`). `MockTransformedImageTest` and the integration test **skip themselves** when `ImagerXIntegration::isAvailable()` is false (`$this->markTestSkipped(...)`), and AC8 is verified manually in the DDEV harness with Imager X installed. NFR5 (boots cleanly *without* Imager X) is the auto-tested half and is the CI default.
- **`NFR4` (Imagick missing → `RuntimeException`)** can't be exercised in CI because `imagick` is always loaded there. Cover the *message/throw shape* by asserting the guard exists and is reachable; verify the actual missing-extension path manually on an Imagick-less environment, or extract the check to a testable seam (`extension_loaded` wrapper) only if it proves necessary — do not over-engineer for it.

### Execution-posture default

Every feature-bearing unit below carries `Execution note: test-first` — write the failing Codeception test that encodes the unit's acceptance behavior, watch it fail, then implement to green. Do **not** expand units into literal RED/GREEN/REFACTOR substeps. Static analysis (`composer check-cs && composer phpstan`) must be clean before a unit is considered done.

---

## Implementation Units

Units carry stable U-IDs and are grouped into the original four phases. Phases 1–4 map to PRs in order; within a phase, follow the dependency order.

### Implementation Status

> Authoritative progress lives in git/PRs; this table is a convenience snapshot, last updated **2026-06-11**.

| Unit | Status | Branch / PR | Notes |
|---|---|---|---|
| U1 | ✅ Done | `jp/15-asset-mock-feature` → [#24](https://github.com/vigetlabs/craft-parts-kit/pull/24) (draft) | Commit `109fccc`. Tests + PHPStan + ECS green. |
| U2 | ✅ Done | `jp/15-asset-mock-deps` → [#25](https://github.com/vigetlabs/craft-parts-kit/pull/25) (draft, stacked on #24) | Commit `3e3700b`. Font provenance/license logged in U2. |
| U3 | ✅ Done | `jp/15-asset-mock-core` (Phase 2 PR pending) | Landed with U4 (commit `9d90e8d`); dimension bounds added in review. |
| U4 | ✅ Done | `jp/15-asset-mock-core` | Commit `9d90e8d`. Surface + dimensions + `NotSupportedException` guards. |
| U5 | ✅ Done | `jp/15-asset-mock-core` | Commit `a6a89b1`. `make()` + `signedUrlForImage()` + Imagick check. |
| U6 | ✅ Done | `jp/15-asset-mock-core` | Commit `0acad7e`. Transform-emitting URL methods. |
| U7 | ✅ Done | `jp/15-asset-mock-core` | Commit `61e1f56`. Generator + atomic write + 5,000-file cap. |
| U8 | ✅ Done | `jp/15-asset-mock-core` | Commit `e4e64a6`. Controller + signed URL rule + gate-before-validation. |
| U9 | ✅ Done | `jp/15-asset-mock-core` | Commit `f2cc3b8`. ClearCaches integration. |
| U10 | ⬜ Not started | — | `MockTransformedImage` model. |
| U11 | ⬜ Not started | — | `ImagerXIntegration` service + registration. |
| U12 | ⬜ Not started | — | README, CHANGELOG, consumer audit, final checks. |

### Phase 1 — Foundation scaffolding (separate PR)

A small, mergeable-on-its-own PR establishing the `Assets` service seam and the component-getter convention. Reframed from the original "cleanup" framing because **no WIP code exists to fix** — this is greenfield scaffolding that lets Phase 2 build on a registered service.

#### U1. `Assets` service skeleton + Plugin wiring

**✅ Status: Done** (commit `109fccc`, PR [#24](https://github.com/vigetlabs/craft-parts-kit/pull/24)) — all test scenarios implemented and green; PHPStan + ECS clean. `MockAssetBuilder` landed here as a minimal stub (anticipated below) so `make()` has a real return type; U3 fleshes it out.

**Goal:** A registered `assets` component reachable as `Plugin::getInstance()->getAssets()` and as the `partsKit.assets` Twig accessor, plus the typed-getter/`@property-read` convention applied to every component.

**Requirements:** Prerequisite for AC1, AC3 (the `make()` entry point lands here as a stub returning a builder; full behavior in U5).

**Dependencies:** none.

**Files:** `src/services/Assets.php` (new, minimal), `src/Plugin.php` (modify), `tests/unit/PluginWiringTest.php` (new), `tests/unit/services/AssetsTest.php` (new — stub-level only).

**Approach:** Mirror the existing `Navigation` registration exactly: add `'assets' => Assets::class` to `config()['components']`, add `getAssets(): Assets` returning `$this->get('assets')`, add `@property-read Assets $assets` to the class docblock, and audit `getNavigation()` for a matching `@property-read Navigation $navigation` (add if missing). Establish the convention in a one-line comment: every component in `config()['components']` gets a typed getter AND a `@property-read` line. `Assets` is a `craft\base\Component` for now with a placeholder `make()` signature.

**Patterns to follow:** `src/services/Navigation.php` (component shape), `src/Plugin.php` `getNavigation()` (getter), `tests/unit/services/NavigationTest.php` (unit-test style — instantiate the service, assert behavior).

**Execution note:** test-first.

**Test scenarios:**
- `PluginWiringTest::testAssetsComponentIsRegistered` — `Plugin::getInstance()->getAssets()` returns an `Assets` instance (not null, correct type).
- `PluginWiringTest::testNavigationGetterStillResolves` — guard against regressing the existing getter while adding the convention.
- `PluginWiringTest::testPartsKitTwigVariableExposesAssets` — the `partsKit` CraftVariable resolves `.assets` to the service (asserts the Twig-facing seam used by `make`).
- `AssetsTest::testMakeReturnsBuilder` — `make()` returns a `MockAssetBuilder` (will be a thin stub until U3/U5; this test is updated, not rewritten, in U5).

**Verification:** `composer test-unit` green; `composer check-cs && composer phpstan` clean; `getAssets()` resolves at runtime.

---

### Phase 2 — Core mock implementation

The meat. Feature dependencies (U2) land first, then the builder/element/service/generator/controller in dependency order.

#### U2. Feature dependencies — `ext-imagick`, bundled font

**✅ Status: Done** (commit `3e3700b`, PR [#25](https://github.com/vigetlabs/craft-parts-kit/pull/25), stacked on #24) — `ext-imagick` declared, DejaVu Sans 2.37 + `LICENSE` bundled, `*.ttf binary` added to `.gitattributes`. Full font provenance, checksums, and license-compliance analysis recorded below. **Revised 2026-07-09:** `ext-imagick` moved from `require` to `suggest` — the hard requirement would have broken `composer update` for existing installs that never use mocks; the `Assets::make()` runtime guard (U5) is the enforcement point instead. See the revision note at the top of this plan.

**Goal:** Declare the Imagick dependency (as a `suggest` + runtime guard) and ship the fallback font so generation works on slim images.

**Requirements:** Prerequisite for U7 (generation) and AC2.

**Dependencies:** none (can land alongside U1).

**Files:** `composer.json` (add `ext-imagick` to `suggest`), `src/resources/fonts/DejaVuSans.ttf` (new binary), `src/resources/fonts/LICENSE` (new — the font's license text, required for redistribution), `.gitattributes` (add `*.ttf binary`).

**Approach:** Declare the extension in `suggest` so Composer surfaces it without imposing a platform requirement on consumers who never use mock assets; `Assets::make()` (U5) throws a clear `RuntimeException` when it's actually needed and missing. Add the TTF under `src/resources/fonts/` — covered by the existing PSR-4-adjacent package contents (verify it is **not** matched by any `export-ignore` rule in `.gitattributes`, since `docs/`, `craft-install/`, `.ddev/` are stripped from the dist package). The repo's `.gitattributes` has a `* text=auto` rule that would LF-normalize (corrupt) a binary font on checkout, so add an explicit `*.ttf binary` rule. **No CI workflow change is needed** — `imagick` is already in `PHP_EXTENSIONS` in both `.github/workflows/ci.yml` and `codecept.yml`.

**Font provenance & license (logged for audit — resolved during U2 execution on 2026-06-08):**

- **Font:** DejaVu Sans, release **2.37** (the latest stable DejaVu release).
- **Why this font:** it is the de-facto bundled font for Imagick text rendering in the PHP/Craft ecosystem and is the file present at `/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf` on most Linux/Docker images — i.e. the exact second link in `MockImageGenerator`'s font fallback chain (bundled → system DejaVu → macOS `Helvetica.ttc` → throw). It has broad Unicode glyph coverage, and — unlike Arial/Helvetica (proprietary, runtime-fallback-only, never shippable) — it is **redistributable**, which is the deciding factor for bundling.
- **Source (verified to resolve, 2026-06-08):** official `dejavu-fonts` GitHub org release.
  - Release page: `https://github.com/dejavu-fonts/dejavu-fonts/releases/tag/version_2_37`
  - Asset downloaded: `https://github.com/dejavu-fonts/dejavu-fonts/releases/download/version_2_37/dejavu-fonts-ttf-2.37.tar.bz2`
  - Tarball SHA-256: `fa9ca4d13871dd122f61258a80d01751d603b4d3ee14095d65453b4e846e17d7`
  - Extracted `DejaVuSans.ttf`: **757,076 bytes**, SHA-256 `7da195a74c55bef988d0d48f9508bd5d849425c1770dba5d7bfc6ce9ed848954`, `file` reports valid `TrueType Font data`. Shipped **byte-for-byte unmodified**.
- **License:** Bitstream Vera License + Arev License, with DejaVu's own changes in the public domain. Official text: `https://dejavu-fonts.github.io/License.html` (verified identical to the bundled `src/resources/fonts/LICENSE`). It is a recognized free/libre license (SPDX id `Bitstream-Vera`; ships in Debian/Fedora main).
- **Compliance analysis for this repo:**
  - *"notices … shall be included in all copies"* → satisfied by bundling `LICENSE` alongside the `.ttf`.
  - *"may be sold as part of a larger software package but no copy … may be sold by itself"* → satisfied: the font ships **inside** the plugin, never standalone (and the plugin is MIT/free regardless).
  - *rename-on-modification clause* → N/A: shipped unmodified.
  - **License coexistence:** the font does **not** become MIT. The plugin's MIT license governs our code; the Bitstream Vera license governs the font (hence the co-located `LICENSE`). This dual-license-in-one-package arrangement is standard and accepted.

**Patterns to follow:** existing `composer.json` `require` block.

**Execution note:** none — config/asset scaffolding.

**Test scenarios:** `Test expectation: none — dependency/asset declaration.` Verification is `composer validate` succeeds, the font + LICENSE are present and committed, `git check-attr` confirms the `.ttf` is `export-ignore: unspecified` (ships) and `binary` (not LF-normalized), and `git archive` (per `.github/workflows/package-contents.yml`) still includes `src/resources/fonts/DejaVuSans.ttf`.

**Verification:** `composer validate` (run with `--no-plugins`; a Craft path-repo plugin otherwise errors on relative paths in this repo) reports the manifest valid; the lock-file "out of date" warning is expected and benign (`composer.lock` is git-ignored, CI installs fresh); `git check-attr export-ignore binary` confirms the font ships and is binary; CI install step succeeds with the new constraint.

#### U3. `MockAssetBuilder`

**Goal:** A fluent + array-config builder that accumulates config and constructs a `MockAsset` only in `one()`.

**Requirements:** AC3 (defaults), AC6/AC7 (config drives identity downstream), NFR7 (label cap).

**Dependencies:** U1.

**Files:** `src/models/MockAssetBuilder.php` (new), `tests/unit/models/MockAssetBuilderTest.php` (new).

**Approach:** `private array $_config = []`. `configure(array): self` merges and throws `InvalidArgumentException` on unknown keys. Fluent setters `width/height/label/alt/title/filename/focalPoint` each write to `_config` and return `$this`. `label()` trims and rejects > 200 chars. `one(): MockAsset` applies defaults (width 800, height 600 if unset; focalPoint left unset — `MockAsset::getFocalPoint()` supplies Craft's center default) and returns `new MockAsset($this->_config)`. The builder does **not** touch a `MockAsset` until `one()`.

**Patterns to follow:** Yii `new X($config)` idiom; existing model conventions in `src/models/`.

**Execution note:** test-first.

**Test scenarios:**
- `testFluentSettersAccumulateConfig` — chained setters produce a `MockAsset` with the expected width/height/label/alt.
- `testConfigureMergesArray` — `configure(['width' => 400])` then `one()` reflects the value.
- `testUnknownConfigKeyThrows` — `configure(['bogus' => 1])` throws `InvalidArgumentException` naming the key.
- `testDefaultsAppliedWhenUnset` — Covers AE/AC3. `(new MockAssetBuilder())->one()` yields width 800, height 600.
- `testLabelOver200CharsThrows` — Covers NFR7. 201-char label → `InvalidArgumentException`.
- `testLabelIsTrimmed` — `'  Hero  '` becomes `'Hero'`.
- `testOneReturnsFreshInstances` — two `one()` calls return distinct objects (no shared mutable state across chains).

**Verification:** `composer test-unit` green for the builder class; static analysis clean.

#### U4. `MockAsset` — surface, dimensions, unsupported guards

**Goal:** The element's non-URL surface: construction/init defaults, metadata getters/setters, mode-aware dimension resolution, and `NotSupportedException` for DB-touching methods. (URL emission is U6.)

**Requirements:** AC5 (dimension math), AC10 (unsupported throws); supports AC4.

**Dependencies:** U3.

**Files:** `src/models/MockAsset.php` (new), `tests/unit/models/MockAssetTest.php` (new).

**Approach:** Extend `craft\elements\Asset`. Class docblock states the LSP-violation contract verbatim (see Enhancement item 14). `__construct($config = [])` → `parent::__construct($config)`; `init()` → `parent::init()` then defaults `id = -1`, `kind = Asset::KIND_IMAGE`, `setScenario(self::SCENARIO_CREATE)`. Private typed props `?int $_width/$_height`, `?string $_label/$_filename`, `?array $_focalPoint`; `alt`/`title` inherited. Override `getWidth(array|string|ImageTransform $transform = null): ?int` (typed union, **not** `mixed` — LSP) and `getHeight(...)`: normalize via `ImageTransforms::normalizeTransform()`, then `Image::targetDimensions(...)` for mode-aware sizing. Metadata: `getFilename/setFilename/getExtension/getMimeType/getHasFocalPoint/getFocalPoint/setFocalPoint`; default filename `mock-{w}x{h}.png`, derive ext/mime from a set filename, `kind` stays image. `getFieldValue()` and DB-touching methods (`getVolume/getFolder/getFs/getUploader/getFieldLayout/getFieldValues/save/delete/validate`) throw `NotSupportedException(__METHOD__ . ' is not supported on MockAsset; see README.')`.

**Patterns to follow:** Craft 5 `craft\elements\Asset` signatures (`getWidth()` typed union at `Asset.php:2509`); `ImageTransforms::normalizeTransform()` (`ImageTransforms.php:270`); `Image::targetDimensions()` (`Image.php:90`).

**Technical design (directional, not spec):** dimension resolution = normalize transform → `Image::targetDimensions($this->_width, $this->_height, $t->width, $t->height, $t->mode, $t->upscale)`. A placeholder has no subject, so crop/fit/stretch/letterbox differ only in resulting dimensions.

**Execution note:** test-first. Use array/`ImageTransform` transforms in tests, not named handles (see Testing Strategy).

**Test scenarios:**
- `testConstructWithConfigSetsDimensions` — `new MockAsset(['width' => 800, 'height' => 600])` reports those base dims (no transform).
- `testInitDefaults` — fresh instance has `kind === Asset::KIND_IMAGE` and `id === -1`.
- `testGetWidthHeightNoTransform` — returns base dims.
- `testGetWidthFitTransformAspectPreserved` — Covers AC5. base 1600×900, `['width' => 400, 'mode' => 'fit']` → width 400, height 225.
- `testGetWidthStretchVsFitDiffer` — same inputs, `stretch` vs `fit` produce the documented different dimensions (mode-awareness).
- `testDefaultFilenameDerivedFromDimensions` — default filename is `mock-800x600.png`; extension `png`, mimeType `image/png`.
- `testSetFilenameDerivesExtensionAndMime` — `setFilename('hero.jpg')` → extension `jpg`, mimeType `image/jpeg`.
- `testFocalPointDefaultsToCenterAndHasFocalPointFalse` — `getHasFocalPoint()` false, `getFocalPoint()` returns Craft's center default `{x: 0.5, y: 0.5}` (never null for an image kind). *(Revised 2026-07-09 — was null.)*
- `testUnsupportedMethodThrowsWithMethodNameAndReadme` — Covers AC10. `getVolume()`/`getFieldValue('caption')` throw `NotSupportedException`; message contains the method name AND `README`.

**Verification:** `composer test-unit` green; static analysis clean (PHPStan must accept the typed-union override).

#### U5. `Assets::make()` + `signedUrlForImage()` + Imagick boot check

**Goal:** The single source of truth for routing and HMAC signing, plus the public `make()` entry and the boot-time Imagick guard.

**Requirements:** AC1 (URL pattern), AC6/AC7 (hash identity), NFR2 (tamper-proof), NFR4 (Imagick missing).

**Dependencies:** U1, U3.

**Files:** `src/services/Assets.php` (expand), `tests/unit/services/AssetsTest.php` (expand from U1 stub).

**Approach:** `make(array $config = []): MockAssetBuilder` wraps `(new MockAssetBuilder())->configure($config)` when config is provided, else an empty builder; it runs the Imagick check (`if (!extension_loaded('imagick')) throw new RuntimeException(...)` naming the extension + README). `signedUrlForImage(int $w, int $h, ?string $label): string` builds the keyed payload `Json::encode(['w' => $w, 'h' => $h, 'label' => $label], JSON_THROW_ON_ERROR)`, signs with `Craft::$app->getSecurity()->hashData($payload)`, `base64UrlEncode`s it, and wraps with `UrlHelper::siteUrl($partsKitDir . '/mock/' . $token . '.png')`. The on-disk filename is `sha1($payload)` (computed by the controller, not here).

**Patterns to follow:** `yii\helpers\StringHelper::base64UrlEncode/Decode`; Craft `Security::hashData/validateData`; the `|hash` Twig filter primitive.

**Execution note:** test-first.

**Test scenarios:**
- `testMakeReturnsConfiguredBuilder` — `make(['width' => 400])->one()` reports width 400.
- `testMakeNoArgsReturnsEmptyBuilder` — `make()->one()` yields defaults.
- `testSignedUrlMatchesRoutePattern` — Covers AC1. URL matches `#/parts-kit/mock/[A-Za-z0-9_-]+\.png$#`.
- `testSignedTokenRoundTrips` — decode the token, `Security::validateData()` returns the original payload; decoded `['w','h','label']` match inputs (NFR2 positive).
- `testTamperedTokenFailsValidation` — flip one byte of the token → `validateData()` returns false (NFR2 negative; the crypto half of AC13, asserted without HTTP).
- `testIdenticalConfigProducesIdenticalUrlAndHash` — Covers AC6. same `(w,h,label)` → identical URL and identical `sha1($payload)`.
- `testDifferentLabelProducesDifferentHash` — Covers AC7. label `'Hero'` vs `'Thumbnail'` → different token and different `sha1`.
- `testKeyedPayloadIsOrderStable` — payload encodes keys deterministically so the hash is stable across calls.
- *(NFR4)* — assert the `make()` Imagick guard throws `RuntimeException` with the extension name; since `imagick` is always loaded in CI, document this as a guard-shape assertion / manual check per Testing Strategy rather than forcing a brittle extension-unload mock.

**Verification:** `composer test-unit` green; round-trip and tamper assertions pass deterministically against the fixed `SECURITY_KEY`.

#### U6. `MockAsset` transform-emitting methods (render-time, no generation)

**Goal:** `getUrl/getImg/getSrcset/getUrlsBySize` emit signed URL strings only — memoized, zero Imagick, zero filesystem.

**Requirements:** AC1, AC4 (img markup), AC11 (zero render-time generation), AC12 (srcset emits N URLs lazily).

**Dependencies:** U4, U5.

**Files:** `src/models/MockAsset.php` (expand), `tests/unit/models/MockAssetTest.php` (expand).

**Approach:** `private array $_urlCache = []` keyed by normalized transform args. `getUrl(mixed $transform = null, ?bool $immediately = null): ?string` — normalize, resolve to final w/h, delegate to `getAssets()->signedUrlForImage($w, $h, $this->_label)`, memoize. `getImg(mixed $transform = null, ?array $sizes = null): ?Markup` — mirror Craft's `Html::tag('img', ...)` with `src/width/height/srcset/alt`; null when `kind !== KIND_IMAGE`. `getSrcset(array $sizes, mixed $transform = null): string|false` — compose from per-size signed URLs. `getUrlsBySize(array $sizes, mixed $transform = null): array`.

**Patterns to follow:** Craft `Asset::getImg()` (`Asset.php:1832`), `getSrcset()` (`1888`), `getUrl()` (`2150`).

**Execution note:** test-first. AC11/AC12 are the headline guarantees — assert no file is touched and Imagick is never constructed during render.

**Test scenarios:**
- `testGetUrlEmitsSignedUrl` — Covers AC1. `getUrl()` matches the route pattern.
- `testGetUrlIsMemoized` — two identical `getUrl()` calls return the identical string (and, if observable, sign once).
- `testGetUrlWithTransformResolvesDimensions` — `getUrl(['width' => 400, 'mode' => 'fit'])` encodes 400×225 in the payload.
- `testGetImgMarkup` — Covers AC4. `make({width:800,height:600,alt:'Hero'}).one.getImg()` renders `<img>` with `src`, `width="800"`, `height="600"`, `alt="Hero"`.
- `testGetImgReturnsNullForNonImageKind` — when `kind` is forced non-image, `getImg()` is null (Craft parity).
- `testGetSrcsetReturnsNUrls` — Covers AC12. `getSrcset(['1x','2x'])` (or width list) yields N distinct signed URLs.
- `testRenderTouchesNoFilesystemAndNoImagick` — Covers AC11. wrap a render of `getUrl/getImg/getSrcset` and assert the mocks cache dir gains zero files and no `Imagick` instance is created (assert via a temp dir snapshot before/after).

**Verification:** `composer test-unit` green; AC11 file-count snapshot is exactly zero; static analysis clean.

#### U7. `MockImageGenerator` helper

**Goal:** The only PNG generation path — produces correctly-sized PNGs with centered scaled text, written atomically, bounded by a file-count cap.

**Requirements:** AC2 (exact pixel dims), NFR1 (atomic write), NFR6 (5,000-file cap).

**Dependencies:** U2.

**Files:** `src/helpers/MockImageGenerator.php` (new, first occupant of `helpers/`), `tests/unit/helpers/MockImageGeneratorTest.php` (new).

**Approach:** Static `generate(string $path, int $width, int $height, ?string $label): void`. Font chain: bundled `DejaVuSans.ttf` → `/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf` → `/System/Library/Fonts/Helvetica.ttc` → `RuntimeException` with install hint. Render bg `#999999`, centered white label (default `"{w}x{h}"`). Scale-to-fit: start `(int)(min($w,$h) * 0.2)`, step down via `queryFontMetrics()` until fit, floor 8px. PNG: `setImageFormat('png')`, quality 75, `stripImage()`. Atomic write: temp file `$path.tmp.{bin2hex(random_bytes(8))}` then `rename()`. Before writing, count `*.png`; if > 5,000, `Craft::warning(...)` and abort (caller treats a missing file as a normal miss). No try/catch — let Imagick exceptions bubble.

**Patterns to follow:** Imagick `setFont`/`queryFontMetrics` docs; stateless static helper (class docblock explains the namespace + testability rationale).

**Execution note:** test-first — this is the unit where TDD pays off most, since the static helper is directly callable with a temp path.

**Test scenarios:**
- `testGeneratesPngOfExactDimensions` — Covers AC2. `generate($tmp, 800, 600, null)` → `getimagesize()` returns 800×600 and PNG mime.
- `testGeneratesPngWithCustomLabel` — non-default label produces a valid PNG of the requested dims (text path doesn't throw).
- `testDefaultLabelIsDimensions` — with null label the file is produced (smoke: valid PNG); optional visual check deferred.
- `testOutputIsValidPngSignature` — first bytes match the PNG magic number.
- `testAtomicWriteLeavesNoTempFile` — Covers NFR1. after `generate()`, the final path exists and no `*.tmp.*` sibling remains.
- `testFileCountCapAbortsGeneration` — Covers NFR6. pre-seed the dir with 5,001 `*.png` stubs; `generate()` logs a warning and does **not** create the target file.
- `testMissingFontChainThrowsRuntimeException` — when no font resolves (simulate by pointing the chain at a temp dir with no font), `RuntimeException` with an install hint. *(If the bundled font always resolves, cover this as a guard-shape assertion.)*
- *(small dims)* `testSmallDimensionsFloorFontAt8px` — `generate($tmp, 10, 10, '10x10')` succeeds (font floor prevents a zero/negative point size).

**Verification:** `composer test-unit` green (requires `imagick` — present in CI); generated files asserted via `getimagesize()`; temp dir cleaned in `_after`.

#### U8. `MockController` + URL rule

**Goal:** The HTTP endpoint: Parts Kit gate → HMAC validation → lazy generation → `sendFile`, addressed by the signed-token route.

**Requirements:** AC2 (200 + headers + dims), AC12 (cold-cache lazy gen), AC13 (tamper 404 + visibility), NFR3 (visibility mirror), NFR8 (gate before validation).

**Dependencies:** U5, U6, U7.

**Files:** `src/controllers/MockController.php` (new), `src/Plugin.php` (add URL rule), `tests/functional/MockControllerCest.php` (new).

**Approach:** Namespace `viget\partskit\controllers`, extends `craft\web\Controller`, `protected array|bool|int $allowAnonymous = ['view'];`. `actionView(string $token): Response`: (1) if `getSettings()->requireViewPermission` → `requirePermission('parts-kit:view')` **before** any token work; (2) `validateData(base64UrlDecode($token))` → false ⇒ `NotFoundHttpException`; (3) `Json::decode($payload)` → read `w/h/label`; (4) `$path = Craft::getAlias('@storage/runtime/parts-kit-mocks/') . sha1($payload) . '.png'`; (5) `if (!file_exists($path)) MockImageGenerator::generate(...)` — the **only** generation site; (6) headers `Cache-Control: public, max-age=31536000, immutable`; (7) `return $this->response->sendFile($path, 'mock.png', ['mimeType' => 'image/png', 'inline' => true])`. URL rule in `_registerUrlRules()`: `$event->rules[$partsKitDir . '/mock/<token:[A-Za-z0-9_-]+>\.png'] = 'parts-kit/mock/view';`.

**Patterns to follow:** `src/controllers/ViewController.php` (`allowAnonymous`, action shape), `tests/functional/PartsKitRouteCest.php` (functional route + permission-gate assertions, `amLoggedInAs`).

**Execution note:** test-first via the functional suite. Generate a valid token in-test by calling `Assets::signedUrlForImage()` and extracting the path segment, then `$I->amOnPage()` it.

**Test scenarios (functional `Cest`):**
- `validTokenReturns200PngOfExactDimensions` — Covers AC2. with `requireViewPermission=false` (or logged-in admin), GET a signed 800×600 URL → 200, `Content-Type: image/png`, `Cache-Control: public, max-age=31536000, immutable`, body is an 800×600 PNG (assert via `getimagesizefromstring()` on the response body).
- `coldCacheGeneratesThenServes` — Covers AC12. clear the mocks dir, request a fresh URL → 200 and the file now exists on disk; a second request still 200 (cache hit).
- `tamperedTokenReturns404` — Covers AC13. mutate one byte of a valid token → 404 (`NotFoundHttpException`).
- `anonymousDeniedWhenPermissionRequired` — Covers NFR3. `requireViewPermission=true`, no user → 403 (mirror `PartsKitRouteCest`'s gate assertion).
- `adminAllowedWhenPermissionRequired` — `requireViewPermission=true`, admin logged in → 200.
- `publicWhenPermissionNotRequired` — `requireViewPermission=false`, anonymous → 200.
- `gateRunsBeforeValidation` — Covers NFR8. `requireViewPermission=true`, anonymous, **tampered** token → 403 (not 404), proving no token oracle.
- `differentLabelsServeSeparateFiles` — Covers AC7 end-to-end. two URLs differing only by label produce two files on disk.

**Verification:** `composer test-functional` green; response-body PNG dimensions asserted; 403-before-404 ordering proven.

#### U9. ClearCaches integration

**Goal:** `php craft clear-caches/all` empties `@storage/runtime/parts-kit-mocks/`.

**Requirements:** AC9.

**Dependencies:** U8.

**Files:** `src/Plugin.php` (register `ClearCaches::EVENT_REGISTER_CACHE_OPTIONS`), `tests/unit/PluginWiringTest.php` (expand).

**Approach:** In `attachEventHandlers()`, register a `RegisterCacheOptionsEvent` listener pushing `['key' => 'parts-kit-mocks', 'label' => Craft::t('parts-kit', 'Parts Kit mock images'), 'action' => Craft::getAlias('@storage/runtime/parts-kit-mocks')]`.

**Patterns to follow:** `ClearCaches::EVENT_REGISTER_CACHE_OPTIONS` (`ClearCaches.php:42`); existing `Event::on` registrations in `attachEventHandlers()`.

**Execution note:** test-first.

**Test scenarios:**
- `testCacheOptionRegistered` — trigger `ClearCaches::EVENT_REGISTER_CACHE_OPTIONS` (or read `Craft::$app->utilities` cache options) and assert an option with key `parts-kit-mocks` whose `action` path resolves to the mocks dir.
- `testClearCachesEmptiesMocksDir` — Covers AC9. seed a PNG in the mocks dir, invoke the registered `action`/clear path, assert the directory is empty afterward.

**Verification:** `composer test-unit` green; before/after directory listing confirms emptied.

---

### Phase 3 — Imager X integration

Soft dependency. Pro enables transparent integration; Lite gets a documented manual workaround. The class files must load even when Imager X is absent, and the tests must skip themselves when it is.

#### U10. `MockTransformedImage` model

**Goal:** A `TransformedImageInterface` implementation that points back at the `MockAsset`'s signed URL.

**Requirements:** AC8 (Imager X result), NFR5 (loads without Imager X).

**Dependencies:** U6.

**Files:** `src/models/MockTransformedImage.php` (new), `tests/unit/models/MockTransformedImageTest.php` (new).

**Approach:** Implements `spacecatninja\imagerx\models\TransformedImageInterface`, mirroring `NoopImageModel`. Constructor `(MockAsset $asset, array $transform)`. `getUrl()` → `$asset->getUrl($transform)`; `getWidth/getHeight` → resolved via `Image::targetDimensions()`; `getPath()` → disk path; `getExtension()` → `'png'`; `getMimeType()` → `'image/png'`. The class must remain loadable with Imager X absent — autoload only, guarded by `class_exists` checks at registration (U11), no top-level `use` of Imager X types in `Plugin.php`.

**Patterns to follow:** `spacecatninja/imager-x/src/models/NoopImageModel.php` (fall back to the interface contract if Imager X isn't installed locally).

**Execution note:** test-first, but the test **skips** when `ImagerXIntegration::isAvailable()` is false.

**Test scenarios:**
- `testUrlDelegatesToAsset` — *(skipped unless Imager X present)* `getUrl()` equals `$asset->getUrl($transform)`.
- `testWidthHeightResolveViaTargetDimensions` — *(skipped unless present)* dims match the transform.
- `testExtensionAndMimeArePng` — *(skipped unless present)* `'png'` / `'image/png'`.
- `Test note:` all cases call `$this->markTestSkipped('Imager X not installed')` when the interface is absent — this is the auto-tested expression of "loads/behaves only when available."

**Verification:** `composer test-unit` green (tests skip cleanly when Imager X absent); class autoloads without fatal when Imager X is not installed.

#### U11. `ImagerXIntegration` service + registration

**Goal:** Conditionally attach the `EVENT_BEFORE_TRANSFORM_IMAGE` listener that swaps in `MockTransformedImage` for `MockAsset` inputs.

**Requirements:** AC8, NFR5.

**Dependencies:** U10.

**Files:** `src/services/ImagerXIntegration.php` (new), `src/Plugin.php` (register component + call `register()`), `tests/unit/services/ImagerXIntegrationTest.php` (new).

**Approach:** Extends `yii\base\Component`. Static `isAvailable(): bool` → `class_exists(\spacecatninja\imagerx\services\ImagerService::class)`. `register(): void` returns early when unavailable, else `Event::on(ImagerService::class, EVENT_BEFORE_TRANSFORM_IMAGE, $this->_handleBeforeTransform(...))`. Handler: if `$event->image instanceof MockAsset`, set `$event->transformedImages = array_map(fn($t) => new MockTransformedImage($event->image, $t), $event->transforms)`. Register `'imagerXIntegration' => ImagerXIntegration::class` in `config()['components']` (with getter + `@property-read` per the U1 convention) and call `$this->get('imagerXIntegration')->register()` after `attachEventHandlers()` in `init()`.

**Patterns to follow:** `Navigation`/`Assets` component registration; `ImagerService::EVENT_BEFORE_TRANSFORM_IMAGE` (`ImagerService.php:46`); `TransformImageEvent` payload shape.

**Execution note:** test-first. The NFR5 guard test runs in CI (Imager X absent); AC8 is manual.

**Test scenarios:**
- `testIsAvailableFalseWithoutImagerX` — Covers NFR5. in CI (no Imager X), `isAvailable()` is false and `register()` is a no-op (no exception, plugin boots).
- `testPluginBootsWithoutImagerX` — Covers NFR5. assert the plugin instance initializes and `imagerXIntegration` component resolves even though Imager X is absent.
- `testListenerSwapsMockAsset` — *(skipped unless Imager X present)* Covers AC8. firing `EVENT_BEFORE_TRANSFORM_IMAGE` with a `MockAsset` populates `transformedImages` with `MockTransformedImage`s; with a non-mock it passes through unchanged.
- **Manual AC8** — with Imager X Pro installed in the DDEV harness, `craft.imagerx.transformImage(mock, {width:400})` returns a `MockTransformedImage` serving a 400px PNG, and `LocalSourceImageModel::getLocalCopy()` is never invoked.

**Verification:** `composer test-unit` green with Imager X absent (guard tests pass, integration tests skip); manual AC8 recorded in the PR.

---

### Phase 4 — Polish & docs

#### U12. README, CHANGELOG, consumer audit, final checks

**Goal:** Document the API and supported surface; verify CP preview context; record the consumer-project audit.

**Requirements:** Documentation Plan; AC10 message audit; CP-preview risk row.

**Dependencies:** U8, U11.

**Files:** `README.md` (modify), `CHANGELOG.md` (modify).

**Approach:** New "Mock Assets" README section with the Twig examples, the supported-`Asset`-surface table (and what throws), the Imager X Pro/Lite matrix incl. the manual `{ noop: true }` workaround, the visibility note (mirrors Parts Kit; HMAC-signed; tampered → 404; dev-only intent), the `clear-caches/all` note, and the file-proliferation/5,000-cap note. CHANGELOG entry announcing mock assets + the `ext-imagick` runtime dependency for mocks (suggested in Composer, enforced by the `make()` guard). Verify every `NotSupportedException` message names the method and points to README. Audit one or two Viget consumer projects for `Asset $param` type-hints and record findings in the PR. Manually verify CP entry-preview context renders mock URLs.

**Patterns to follow:** existing `README.md` structure; `craft-viget-base-testing-reference` for doc conventions.

**Execution note:** none — docs and manual verification.

**Test scenarios:** `Test expectation: none — documentation and manual verification.` The behavioral guarantees were locked by U1–U11's automated tests; this unit adds prose and the two manual checks (CP preview, consumer audit).

**Verification:** `composer test && composer check-cs && composer phpstan` all green; README + CHANGELOG updated; CP-preview and consumer-audit results recorded in the PR description.

---

## Acceptance Criteria

Each criterion now names the test(s) that enforce it. "Manual" criteria are those the soft Imager X / Imagick-absent dependencies put out of CI reach (see Testing Strategy).

### Functional Requirements

- [ ] **AC1** — `make({width:800,height:600}).one.getUrl()` matches `/parts-kit/mock/[A-Za-z0-9_-]+\.png`. → `AssetsTest`, `MockAssetTest`.
- [ ] **AC2** — GET that URL (as a Parts-Kit-viewer) → 200, `Content-Type: image/png`, `Cache-Control: public, max-age=31536000, immutable`, PNG exactly 800×600. → `MockControllerCest`, `MockImageGeneratorTest`.
- [ ] **AC3** — `make.one` (no setters) → 800×600 default. → `MockAssetBuilderTest`.
- [ ] **AC4** — `make({width:800,height:600,alt:'Hero'}).one.getImg()` → `<img>` with `src/width=800/height=600/alt="Hero"`. → `MockAssetTest`.
- [ ] **AC5** — `make({width:1600,height:900}).one.getUrl({width:400,mode:'fit'})` serves 400×225. → `MockAssetTest` (dims) + `MockControllerCest` (served bytes).
- [ ] **AC6** — Two identical configs → same hash, one file, same URL. → `AssetsTest`.
- [ ] **AC7** — Changing only `label` → different hash + separate file. → `AssetsTest` (hash) + `MockControllerCest` (separate files).
- [ ] **AC8** — *(Manual + skipped CI test)* Imager X Pro: `transformImage(mock,{width:400})` → `MockTransformedImage` serving a 400px PNG; `LocalSourceImageModel::getLocalCopy()` never invoked. → `ImagerXIntegrationTest` (skipped when absent) + manual DDEV check.
- [ ] **AC9** — `clear-caches/all` empties the mocks dir. → `PluginWiringTest`.
- [ ] **AC10** — Any unsupported method throws `NotSupportedException` naming the method + README. → `MockAssetTest`.
- [ ] **AC11** — Render does zero generation/filesystem work. → `MockAssetTest` (file-count snapshot == 0, no Imagick instance).
- [ ] **AC12** — `getSrcset` with N sizes emits N URLs, no render-time generation; each PNG generated lazily on first request. → `MockAssetTest` (emits N) + `MockControllerCest` (cold-cache gen).
- [ ] **AC13** — One-byte-altered token → 404; untampered URL viewable by exactly Parts-Kit viewers. → `AssetsTest` (validate=false) + `MockControllerCest` (404 + gate).

### Non-Functional Requirements

- [ ] **NFR1** — Concurrent first-request generation never corrupts a PNG (temp-file + atomic `rename()`). → `MockImageGeneratorTest` (no temp residue; atomicity by construction).
- [ ] **NFR2** — Tamper-proof HMAC token; on-disk filename `sha1($validatedPayload)`, never raw input (no traversal). → `AssetsTest`, `MockControllerCest`.
- [ ] **NFR3** — Access mirrors Parts Kit visibility (403 when gated + unauthorized; public when off). → `MockControllerCest`.
- [ ] **NFR4** — *(Manual / guard-shape)* Imagick missing → clear `RuntimeException` naming the extension + README. → `AssetsTest` guard assertion; manual on Imagick-less env (CI always has imagick).
- [ ] **NFR5** — Plugin loads with Imager X **not** installed; integration registers only when available. → `ImagerXIntegrationTest` (runs in CI).
- [ ] **NFR6** — Generation aborts + logs past 5,000 PNGs. → `MockImageGeneratorTest`.
- [ ] **NFR7** — `label()` rejects > 200 chars with `InvalidArgumentException`. → `MockAssetBuilderTest`.
- [ ] **NFR8** — Access check before signature validation (unauthorized → 403 regardless of token validity; no oracle). → `MockControllerCest` (`gateRunsBeforeValidation`).

---

## Quality Gates

The original "manual verification of all ACs" gate is **replaced** by automated coverage; only the genuinely-unautomatable items remain manual.

- [ ] `composer test` green (unit + functional) locally and in CI (MySQL **and** PostgreSQL matrix).
- [ ] `composer check-cs` clean.
- [ ] `composer phpstan` clean (level 4) — including the typed-union `getWidth()` override.
- [ ] Every feature-bearing unit (U1, U3–U11) has its test file committed **in the same change** as the implementation, written test-first.
- [ ] Imager X tests skip cleanly when Imager X is absent (CI default) — no errors, no false failures.
- [ ] **Manual:** AC8 with Imager X Pro installed (DDEV harness).
- [ ] **Manual:** NFR4 on an Imagick-less environment (or accepted as a guard-shape assertion).
- [ ] **Manual:** CP entry-preview context renders mock URLs.
- [ ] README + CHANGELOG updated; consumer-project audit recorded in the PR.

---

## Alternative Approaches Considered

Evaluated in the brainstorm and rejected:

- **Base64 data URLs** — bloats HTML, defeats caching, no transforms.
- **Pre-generation + real Craft transform pipeline** — needs a real Volume + DB rows per size; crosses from "mock" into "real ephemeral asset."
- **External placeholder service (placehold.co, picsum)** — requires internet, no transforms, third-party dependency.
- **Imager X custom Transformer registration** — Pro-only, requires per-call opt-in; `EVENT_BEFORE_TRANSFORM_IMAGE` is transparent.
- **Sidecar `{hash}.json` config files** — unnecessary; the signed token already carries validated config.
- **GD fallback when Imagick is missing** — YAGNI; Imagick is a standard Craft transform dependency and CI already loads it.

### Testing-approach alternatives (new in the TDD rework)

- **Pure-unit controller tests via direct action invocation** instead of functional HTTP — rejected. The functional connector works here (unlike sibling plugins) and HTTP-level tests prove headers, routing, and the permission gate that direct invocation would bypass. Use functional for `MockController`.
- **Mock Imagick to unit-test the generator without `ext-imagick`** — rejected. CI already loads `imagick`; mocking it would test the mock, not the PNG. Generate real files and assert with `getimagesize()`.
- **Add Imager X to `require-dev` to auto-test AC8** — rejected for v1. It's a soft dependency (Pro license); pulling it into dev deps complicates the matrix and licensing. Skip-when-absent tests + a manual DDEV check is the right cost/coverage trade.

---

## Risk Analysis & Mitigation

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| **Imagick absent on dev container** | Medium | High | Boot-time check in `make()`; clear error; README guidance. (CI unaffected — imagick preloaded.) |
| **Font missing on minimal image** | Medium | Medium | Bundle DejaVuSans.ttf; system-font fallback chain; `MockImageGeneratorTest` font-chain case. |
| **Imager X Lite user hits Pro-only short-circuit** | Low | Medium | README documents `{ noop: true }`; no runtime detection. |
| **Concurrent generation corrupts cached file** | Low | Medium | Atomic temp-file + rename; `MockImageGeneratorTest` asserts no temp residue. |
| **Forged / tampered mock URL** | Low | High | HMAC `validateData`; `sha1($validatedPayload)` filename; `AssetsTest` + `MockControllerCest`. |
| **Imager X tests fail CI because the dep is absent** | Medium | Medium | `markTestSkipped()` guard; NFR5 guard test is the CI-run half. |
| **PHPStan rejects the typed-union `getWidth()` override** | Medium | Low | Match Craft 5's exact signature; verify under `composer phpstan` in U4 before moving on. |
| **Functional PNG-byte assertion flakiness** | Low | Low | Assert via `getimagesizefromstring()` on the response body; fixed `SECURITY_KEY` makes tokens deterministic. |
| **Malicious template fills disk via unique hashes** | Low | Low | 5,000-file cap; `MockImageGeneratorTest` covers it. |
| **Long-label DoS via `queryFontMetrics`** | Low | Low | 200-char cap; `MockAssetBuilderTest` covers it. |
| **CP entry preview can't load mock URLs** | Low | Medium | Manual verify in U12; URL rule on site rules. |

---

## Future Considerations

Out of scope for v1 (deferred until a real need surfaces):

- Background/text color customization (`->bgColor`, `->textColor`).
- Format selection (PNG-only in v1; `format` key is additive to the signed payload).
- Pluggable image generators (`->generator(callable)`).
- Non-image asset kinds (PDF, video).
- Custom field support on mocks.
- Plugin config knobs for storage path / URL prefix.
- Auto-injection of `noop: true` for Imager X Lite.
- `PartsKitAssetLike` interface to decouple component code from the Asset hierarchy (needs the consumer audit from U12).

### Security considerations for v2 additions

- **Pluggable generators** — RCE-shaped; needs an allowlist or opaque registered IDs.
- **Custom field support** — hash inputs must include field values to prevent cache-collision pollution.
- **Config knobs for storage path** — must reject paths outside `@storage` to prevent arbitrary overwrite via `rename()`.

---

## Dependencies & Prerequisites

- PHP 8.2+ and Craft CMS 5.0+ (already required).
- PHP `ext-imagick` (suggested in `composer.json`, not required — the `Assets::make()` runtime guard enforces it at first use; already present in CI `PHP_EXTENSIONS`). *(Revised 2026-07-09 — was a hard `require`.)*
- Bundled DejaVu Sans TTF + its `LICENSE` (`src/resources/fonts/`). DejaVu **2.37**, sourced byte-for-byte from the official `dejavu-fonts` GitHub release, Bitstream Vera + Arev license (redistributable inside a larger package). Full provenance, checksums, and license-compliance analysis recorded in **U2** above.
- Codeception suite (unit + functional through `\craft\test\Craft`), PHPStan level 4, ECS — all already wired and CI-enforced.
- **Optional:** Imager X Pro for transparent integration (soft dependency via `class_exists`; not in dev deps).

---

## References & Research

### Internal references

- Brainstorm: `docs/brainstorms/2026-05-25-mock-asset-brainstorm.md`
- Existing services: `src/services/Navigation.php` (component + registration pattern to mirror)
- Existing controllers: `src/controllers/ViewController.php`, `src/controllers/ApiController.php` (`allowAnonymous`, action shape)
- Existing model: `src/models/Settings.php`
- Plugin entry: `src/Plugin.php` (`config()`, `attachEventHandlers()`, `_registerUrlRules()`, `getNavigation()`)
- Permission gate (Twig): `src/templates/root.twig`
- **Test patterns to mirror:** `tests/unit/services/NavigationTest.php` (unit style, fixtures), `tests/functional/PartsKitRouteCest.php` (functional route + permission-gate assertions), `tests/unit/NavNodeTest.php`, `tests/unit/HarnessBootTest.php`
- Test harness config: `codeception.yml`, `tests/unit.suite.yml`, `tests/functional.suite.yml`, `tests/_craft/`
- CI: `.github/workflows/ci.yml`, `.github/workflows/codecept.yml` (note `imagick` already in `PHP_EXTENSIONS`), `.github/workflows/package-contents.yml` (font must ship)

### Craft 5 source references

- `vendor/craftcms/cms/src/elements/Asset.php:1832` — `getImg()` default markup
- `vendor/craftcms/cms/src/elements/Asset.php:1888` — `getSrcset()` signature
- `vendor/craftcms/cms/src/elements/Asset.php:2150` — `getUrl()` signature
- `vendor/craftcms/cms/src/elements/Asset.php:2509` — `getWidth()` typed-union signature (NOT mixed)
- `vendor/craftcms/cms/src/elements/Asset.php:1076` — `public ?string $alt`
- `vendor/craftcms/cms/src/elements/Asset.php:1070` — `public ?string $kind`
- `vendor/craftcms/cms/src/helpers/ImageTransforms.php:270` — `normalizeTransform()`
- `vendor/craftcms/cms/src/helpers/Image.php:90` — `targetDimensions()`
- `vendor/craftcms/cms/src/utilities/ClearCaches.php:42` — `EVENT_REGISTER_CACHE_OPTIONS`
- `vendor/craftcms/cms/src/web/Response.php:245` — `sendFile()` signature

### Imager X references (Pro license)

- `spacecatninja/imager-x/src/services/ImagerService.php:46` — `EVENT_BEFORE_TRANSFORM_IMAGE`
- `spacecatninja/imager-x/src/events/TransformImageEvent.php` — event payload shape
- `spacecatninja/imager-x/src/models/NoopImageModel.php` — reference to mirror for `MockTransformedImage`

### External references

- Craft CMS 5.x docs — Assets: https://craftcms.com/docs/5.x/reference/element-types/assets.html
- Craft CMS 5.x docs — Controllers: https://craftcms.com/docs/5.x/extend/controllers.html
- Craft CMS 5.x docs — Testing: https://craftcms.com/docs/5.x/extend/testing.html
- Yii 2 `Security::validateData()` / `hashData()`: https://www.yiiframework.com/doc/api/2.0/yii-base-security
- Imagick `queryFontMetrics`: https://www.php.net/manual/en/imagick.queryfontmetrics.php
- DejaVu Fonts license (bundled font): https://dejavu-fonts.github.io/License.html
- DejaVu Fonts 2.37 release (bundled font source): https://github.com/dejavu-fonts/dejavu-fonts/releases/tag/version_2_37

### Brainstorm decision summary

- Default dimensions: **800×600**
- Filename default: **auto `mock-{w}x{h}.png`; setting filename derives ext/mimeType**
- Focal point default: ~~null; `hasFocalPoint()` false~~ **revised 2026-07-09: Craft's center default `{x: 0.5, y: 0.5}`, mirroring `Asset::getFocalPoint()`; `hasFocalPoint()` still false when unset** (a real image Asset never returns null, so neither can the mock)
- Asset kinds in v1: **image-only**
- Hash inputs: **width, height, label** (keyed, HMAC-signed; no version prefix; rely on clear-caches)
- Imager X Lite UX: **documented manual workaround only**
- Plugin config knobs: **hardcoded for v1**
