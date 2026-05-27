---
title: Mock Asset Service for Parts Kit
type: feat
status: active
date: 2026-05-25
brainstorm: docs/brainstorms/2026-05-25-mock-asset-brainstorm.md
---

# feat: Mock Asset Service for Parts Kit

## Enhancement Summary

**Deepened on:** 2026-05-25
**Review agents used:** security-sentinel, performance-oracle, code-simplicity-reviewer, architecture-strategist

### Refinements applied

1. **Phase split.** Phase 1 (foundation cleanup — constructor fix, `@property-read` typo, `getAssets()` getter) lands as a small separate PR before the feature work. Phase 2 starts on a clean base.
2. **Builder pattern correction.** `MockAssetBuilder` now holds a config array and constructs the `MockAsset` in `one()` via `new MockAsset($config)`. No more mutating a half-built Asset mid-chain. Aligns with Yii's standard `new X($config)` idiom.
3. **Imager X integration extracted to a service.** `src/services/ImagerXIntegration.php` registers conditionally in `Plugin::config()` under `class_exists`. Keeps the soft-dependency surface in one file, matches existing Navigation/Assets service pattern.
4. **URL construction + signing extracted from `MockAsset`.** New `Assets::signedUrlForImage(int $w, int $h, ?string $label): string` method owns both routing and HMAC signing. The element no longer knows the controller's routing decision.
5. **Performance: fully lazy, controller-only generation.** Template rendering does **zero** Imagick and zero filesystem work — `getUrl()`/`getImg()`/`getSrcset()` only emit signed URL strings. Every PNG is generated inside `MockController` on the first HTTP request for its URL, then cached. This drops render time for a 20-mock page to near-zero (no longer ~2-4s) and removes the per-request generation guard entirely.
6. **Extensibility: keyed signed payload + transform normalization.** The signed payload is a keyed array (`['w'=>…, 'h'=>…, 'label'=>…]`), so future knobs (`format`, `bg`, `position`) slot in additively without breaking existing decode. All transform argument shapes — named handles (`'hero-transform'`), arrays, and `ImageTransform` objects — are normalized via `ImageTransforms::normalizeTransform()` before dimension resolution, so mode-aware sizing (crop/fit/stretch/letterbox) works uniformly.
7. **Performance: drop ETag + 304.** `Cache-Control: public, max-age=31536000, immutable` is sufficient. Removes filemtime syscall, conditional-request branching, and NFR4 entirely.
8. **Security: tamper-proof signed URLs + visibility mirrors Parts Kit.** Mock URLs carry an HMAC-signed payload (`Security::hashData`/`validateData` — the primitive behind Craft's `|hash` filter); the controller validates the signature and rejects any tampering with a 404. Access is **no longer** devMode-gated: a mock image is viewable by exactly whoever can view the Parts Kit (public, or `parts-kit:view`-gated, per `requireViewPermission`). Signing is what makes opening access safe — forged dimensions/labels are impossible without the app security key. Replaces the earlier `ForbiddenHttpException` hard-block.
9. **Security: reorder access check before signature validation.** The controller runs its permission gate before validating the token, so unauthorized requests get a uniform 403 and can't probe which tokens are valid.
10. **Security: hardcode `sendFile` disposition filename to `'mock.png'`.** Removes defense-in-depth concern about user-controlled header content.
11. **Security: use `NotFoundHttpException` for 404, not custom response body.** Eliminates any future XSS risk from interpolated hash echo.
12. **Security: cap label length at 200 chars** in `MockAssetBuilder::label()` to bound `queryFontMetrics` cost.
13. **Security: cap directory file count at 5,000** in `MockImageGenerator::generate()`. Bounds disk-fill via malicious template loop.
14. **LSP violation acknowledgment.** `MockAsset` class docblock explicitly states the structural-subtype contract — only read-time transform APIs are honored, mutations and persistence are unsupported by design.
15. **Cut: static error PNG fallback.** Generation failure now lets the exception bubble. In dev, broken image = real signal, not noise to mask.
16. **Cut: per-render devMode warning logging.** Replaced by the Parts Kit visibility gate plus tamper-proof signed URLs (item 8).
17. **Cut: Lite-license boot detection.** README note suffices. Viget uses Pro anyway.
18. **Cut: `@method` PHPDoc block on MockAsset.** Goes stale; `NotSupportedException` messages are the source of truth.
19. **Durable URLs for free.** Because the signed token carries the (validated) config, the controller generates the PNG on demand — both on first request and after a cache clear — with no sidecar `{hash}.json` files and no 404-then-refresh. Generation is controller-only; there is no render-time generation path.

### Rejected suggestions (with reasoning)

- **Cut bundled DejaVuSans.ttf** — best-practices research showed this is the standard Craft-community pattern. Removing it surfaces portability bugs on Alpine/slim Docker images. ~750KB is a fair price.
- **Cut fluent setters, keep only `configure(array)`** — user explicitly chose both in the brainstorm. Reviewer didn't see that decision.
- **Inline `MockImageGenerator` as a private method** — architecture review confirmed static helper is the right fit; the file isolation aids future refactors.
- **Cut atomic temp-file + rename** — 5 lines of code for a real correctness property under concurrency. Keep.

### New considerations discovered

- A malicious template can fill `@storage/runtime/parts-kit-mocks/` by looping unique hashes. Bounded by 5,000-file cap.
- Future v2 security notes added for pluggable generators (RCE shape), custom field support (cache collision), and config knobs for storage path (arbitrary file overwrite via rename).
- Viget consumer projects should be audited for `Asset $param` type-hints to confirm component code accepts MockAsset via the extends-Asset path (vs untyped duck-typing).

---

## Overview

Add a fully functional `MockAsset` element class (extending `craft\elements\Asset`) and a fluent / hash-config builder API so that Parts Kit template authors can render placeholder images without needing real assets uploaded locally. The mock generates real PNG files on disk lazily — on the first request for each URL (cached by SHA-1 hash of config) — serves them through a plugin controller route addressed by a **tamper-proof, HMAC-signed URL**, and integrates transparently with both Craft's native transform pipeline and Imager X (Pro) via `EVENT_BEFORE_TRANSFORM_IMAGE`. Image access **mirrors Parts Kit visibility** — if a user can view the Parts Kit, they can load its mock images.

## Problem Statement

Parts Kit lets developers preview Twig components in isolation — but most production components consume Craft `Asset` instances (for images: hero photos, thumbnails, avatars, etc.). In local development:

1. Assets are not in sync with production (no CDN backfill, no fresh-checkout dump),
2. Asset IDs differ across machines and environments,
3. Setting up a real asset just to preview a component is high-friction work for what should be a no-setup dev tool.

The existing WIP `MockAsset` class extends `Asset` but throws `NotSupportedException` on ~95% of methods and renders a base64 PNG inline. This breaks the moment a component calls `asset.getImg()`, `asset.getUrl({width: 400})`, `asset.alt`, or any transform pipeline. It also bloats HTML and defeats browser caching.

## Proposed Solution

A complete rewrite of `MockAsset` that implements the **subset of `Asset` that real-world Twig component templates actually use** (dimensions, transforms, alt, title, filename/extension/mimeType/kind, focal point) and routes image production through a plugin controller serving real PNG files from `@storage/runtime/parts-kit-mocks/`. Each mock URL is a **tamper-proof, HMAC-signed token** (via Yii's `Security`, surfaced in Craft as the `|hash` filter); the controller validates the signature and, because the validated config travels in the URL, **lazily generates the PNG on the first request** (and regenerates it after any cache clear). Template rendering itself performs no image generation — it only emits signed URLs. Image access **mirrors Parts Kit visibility** rather than gating on devMode. Imager X integration uses an `EVENT_BEFORE_TRANSFORM_IMAGE` listener that populates `transformedImages` with a custom `MockTransformedImage` model, transparently short-circuiting the pipeline for mocks.

**Target Twig usage:**

```twig
{# Hash config — terse #}
{% set hero = partsKit.assets.make({
  width: 1600,
  height: 900,
  label: 'Hero',
  alt: 'Hero photo',
}).one %}

{# Or fluent chain — incremental #}
{% set thumb = partsKit.assets.make
  .width(400)
  .height(300)
  .label('Thumbnail')
  .one %}

{# Components consume mocks identically to real assets #}
{{ Image({ asset: hero, transform: 'hero-transform' }) }}
{{ craft.imagerx.transformImage(hero, { width: 800, mode: 'fit' }) }}
```

## Technical Approach

### Architecture

```mermaid
flowchart TD
    A[Twig template] -->|partsKit.assets.make| B[Assets service]
    B -->|new MockAssetBuilder| C[MockAssetBuilder]
    C -->|->one| D[MockAsset element]
    A -->|asset.getUrl/getImg| D
    D -->|signs URL (no generation)| E[/parts-kit/mock/&lt;signed-token&gt;.png]
    E -->|HTTP GET| F[MockController::actionView<br/>Parts Kit gate + validateData]
    F -->|sendFile| G[(@storage/runtime/parts-kit-mocks/&lt;hash&gt;.png)]
    F -.->|first request / miss → generate from validated config| H[MockImageGenerator]
    H -.->|atomic write| G
    I[craft.imagerx.transformImage] -->|ImagerService::EVENT_BEFORE_TRANSFORM_IMAGE| J[Plugin event listener]
    J -->|asset instanceof MockAsset?| K{Yes?}
    K -->|Yes| L[Populate $event->transformedImages with MockTransformedImage]
    K -->|No| M[Pass through to default transformer]
    L -->|points at MockAsset URL| E
```

### File Inventory

| File | Purpose | Status |
|---|---|---|
| `src/Plugin.php` | Add URL rule, ClearCaches registration, `getAssets()` getter, fix `@property-read` typo. Imager X registration deferred to `ImagerXIntegration` service | Modify |
| `src/services/Assets.php` | `make(array $config = [])` returns configured `MockAssetBuilder`; boot-time Imagick check; `signedUrlForImage(int $w, int $h, ?string $label): string` builds the tamper-proof HMAC-signed URL | Modify |
| `src/services/ImagerXIntegration.php` | Conditionally registers `EVENT_BEFORE_TRANSFORM_IMAGE` listener; isolates soft-dependency surface | New |
| `src/models/MockAsset.php` | Full rewrite: implements supported asset surface, overrides four transform methods to emit signed URLs (no generation at render), retains `NotSupportedException` for the rest with helpful messages. Class docblock acknowledges LSP violation as intentional | Rewrite |
| `src/models/MockAssetBuilder.php` | Holds config array, constructs `MockAsset` in `one()`. Adds `configure(array)`, `label` (200-char cap), `alt`, `title`, `filename`, `focalPoint` builder methods | Extend |
| `src/models/MockTransformedImage.php` | Implements `TransformedImageInterface` for Imager X short-circuit | New |
| `src/helpers/MockImageGenerator.php` | Imagick PNG generation invoked only by the controller; `generate()`, font detection, atomic write, scale-to-fit text, file-count cap | New |
| `src/controllers/MockController.php` | `actionView(string $token): Response` — access mirrors Parts Kit visibility; validates HMAC signature (404 on tamper); lazily generates the PNG from validated config on first request; serves PNG via `sendFile` with hardcoded disposition filename | New |
| `src/resources/fonts/DejaVuSans.ttf` | Bundled font (DejaVu Sans — Bitstream Vera license, redistributable) | New |
| `composer.json` | Add `"ext-imagick": "*"` to require | Modify |
| `README.md` | Document Mock Asset API, Imager X Pro/Lite behavior, supported surface, "dev-only" guarantee | Modify |

### Implementation Phases

#### Phase 1: Foundation cleanup (separate PR)

Small, mergeable-on-its-own PR that fixes latent bugs unrelated to the mock asset feature. Lands first so Phase 2 builds on a clean base.

**Tasks**

- [ ] Fix `MockAsset::__construct()` to accept `$config = []` and call `parent::__construct($config)`. Current empty no-arg breaks `new MockAsset(['width' => 800])`.
- [ ] Fix `MockAsset::init()` to call `parent::init()`. Per Craft 5 source (`Asset::init()` line 1288), init is minimal, no DB lookups, safe to call.
- [ ] Fix `Plugin.php` `@property-read Assets $assetService` → `@property-read Assets $assets` to match actual component key.
- [ ] Add `getAssets(): Assets` getter to `Plugin.php` mirroring the existing `getNavigation()` pattern.
- [ ] Audit `getNavigation()` for the same drift — if `@property-read Navigation $navigation` is missing, add it. Establish convention: every component in `config()['components']` gets a typed getter AND a matching `@property-read` line.
- [ ] Run `composer check-cs` and `composer phpstan` clean before moving on.

**Note:** `composer.json` ext-imagick addition and bundled font moved to Phase 2 — they're feature dependencies, not foundation cleanup.

**Success criteria:** Existing parts-kit functionality unchanged; static analysis passes; `new MockAsset(['width' => 800])` instantiates without exception; this PR lands cleanly before Phase 2 begins.

#### Phase 2: Core mock implementation

The meaty phase — write everything the Craft transform pipeline touches.

**Feature dependencies added in this phase:**

- [ ] Add `"ext-imagick": "*"` to `composer.json` `require`.
- [ ] Create `src/resources/fonts/` directory and add `DejaVuSans.ttf` (Bitstream Vera license, redistributable). Ensure included in composer package via default plugin autoload.

**Tasks**

- [ ] **`MockImageGenerator` helper** (`src/helpers/MockImageGenerator.php`):
  - Class-level docblock explains: stateless static helper, first occupant of the `helpers/` namespace, isolated for testability and future refactor.
  - Static `generate(string $path, int $width, int $height, ?string $label): void` — the only generation entry point; called exclusively from `MockController` on a cache miss.
  - Font detection chain: bundled `DejaVuSans.ttf` → `/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf` → `/System/Library/Fonts/Helvetica.ttc` → throw `RuntimeException` with install hint
  - Render: bg `#999999` (AA-contrast with white text), centered white label
  - Default label: `"{$width}x{$height}"`; respect user-provided override
  - Scale-to-fit: start `(int)(min($w, $h) * 0.2)`, use `queryFontMetrics()` to step down until fit, floor at 8px
  - PNG output: `setImageFormat('png')`, `setImageCompressionQuality(75)`, `stripImage()`
  - **Atomic write**: write to `$path.tmp.{bin2hex(random_bytes(8))}` then `rename()` to final path
  - **File-count cap (security):** before writing, count `*.png` files in the directory. If > 5,000, log `Craft::warning('parts-kit mocks cache full')` and abort generation (callers handle missing file as a normal cache miss).
  - **NO try/catch fallback** — let Imagick exceptions bubble. In dev, broken image is the desired signal that the env is broken (Imagick missing, no fonts, disk full). The `MockAsset::getUrl()` caller can decide whether to swallow.

- [ ] **`MockAsset` rewrite** (`src/models/MockAsset.php`):
  - Class docblock explicitly acknowledges LSP violation:
    ```
    /**
     * MockAsset is a structural subtype of craft\elements\Asset, honoring ONLY
     * the read-time transform/metadata APIs needed by typical Twig component
     * templates. Mutations, persistence, field access, and volume-related calls
     * are intentionally unsupported and will throw NotSupportedException.
     * This violates LSP by design; the alternative (a composition wrapper) cannot
     * pass Craft/ImagerX type-hints that require `craft\elements\Asset`.
     */
    ```
  - Extend `craft\elements\Asset`
  - Restore `__construct($config = [])`, `init()` (Phase 1 already done)
  - In `init()`: set sane defaults — `$this->id = -1`, `$this->kind = Asset::KIND_IMAGE`, `setScenario(self::SCENARIO_CREATE)`
  - **Private typed properties for builder-set values:** `?int $_width`, `?int $_height`, `?string $_label`, `?string $_filename`, `?array $_focalPoint`. The `alt` and `title` properties are inherited from `Asset` / `Element` and used directly.
  - **Per-instance URL memo:** `private array $_urlCache = [];` — keyed by normalized transform args; caches the signed URL string so repeated `getUrl()` calls skip re-signing. No generation or filesystem access is involved (generation is controller-only).
  - **Override transform-producing methods** with exact Craft 5 signatures:
    - `getUrl(mixed $transform = null, ?bool $immediately = null): ?string` — memoized; normalizes `$transform` via `ImageTransforms::normalizeTransform()`, resolves it to the final width/height, then delegates to `Plugin::getInstance()->getAssets()->signedUrlForImage($w, $h, $this->_label)`. Emits a URL only — no generation.
    - `getImg(mixed $transform = null, ?array $sizes = null): ?Markup` — mirror Craft's default: `Html::tag('img', '', ['src', 'width', 'height', 'srcset', 'alt'])`. Returns null when `$this->kind !== Asset::KIND_IMAGE` (matches Craft).
    - `getSrcset(array $sizes, mixed $transform = null): string|false` — composes the srcset string purely from signed URLs (one per size, via `signedUrlForImage`). No generation: each size's PNG is created lazily by the controller when the browser fetches that URL.
    - `getUrlsBySize(array $sizes, mixed $transform = null): array`
  - **Override `getWidth(array|string|ImageTransform $transform = null): ?int`** — note the typed union, NOT `mixed` (LSP). Normalize `$transform` via `ImageTransforms::normalizeTransform()` first (so named handles, arrays, and `ImageTransform` objects all resolve), then compute via `Image::targetDimensions($this->_width, $this->_height, $t->width, $t->height, $t->mode, $t->upscale)`. This is mode-aware: `crop`, `fit`, `stretch`, and `letterbox` all yield the correct target size (a placeholder has no subject to crop, so mode only affects dimensions).
  - **Override `getHeight(mixed $transform = null): ?int`** — same normalization + dimension resolution.
  - **Implement metadata getters/setters:** `getFilename()`, `setFilename()`, `getExtension()`, `getMimeType()`, `getHasFocalPoint()`, `getFocalPoint()`, `setFocalPoint()`.
  - **Filename auto-derivation:** default filename `mock-{w}x{h}.png`. When `setFilename('hero.jpg')` is called, derive extension/mimeType from the path. `kind` remains `'image'` always in v1.
  - **Signed payload & cache key:** the canonical payload is a **keyed** array, `Json::encode(['w' => $w, 'h' => $h, 'label' => $this->_label], JSON_THROW_ON_ERROR)` — keyed (not positional) so future fields (`format`, `bg`, `position`) are additive and don't break decode. The URL token is `Security::hashData($payload)`, URL-safe-encoded; the on-disk cache filename is `sha1($payload)`. The controller (post-`validateData`) recomputes `sha1($payload)` to locate or generate the file. **Extension points:** add a key here, read it in `MockController`, and pass it through to `MockImageGenerator::generate()` — the signature and cache key absorb the new field automatically (new config ⇒ new hash ⇒ fresh PNG).
  - **Field access override:** `getFieldValue($handle, $siteId = null)` throws `NotSupportedException` with message `"MockAsset does not support custom fields. Field '{$handle}' was requested. Use ->alt, ->title, or ->label on the builder, or pass a real Asset to this component."`
  - **Explicit overrides for DB-touching methods** (`getVolume`, `getFolder`, `getFs`, `getUploader`, `getFieldLayout`, `getFieldValues`, `save`, `delete`, `validate`) — throw `NotSupportedException(__METHOD__ . ' is not supported on MockAsset; see README.')`. Per architecture review, don't try to enumerate every Element method exhaustively — focus on the ones that would query the DB if not stubbed.

- [ ] **`MockAssetBuilder` rewrite** (`src/models/MockAssetBuilder.php`):
  - **Holds config array, not a MockAsset instance.** `private array $_config = [];` Constructs the `MockAsset` only in `one()` via `new MockAsset($this->_config)`. No more mid-chain half-built Asset.
  - `configure(array $config): self` — merges into `$this->_config`. Unknown keys throw `InvalidArgumentException("Unknown mock asset config key: '{$key}'")`.
  - Fluent setters: `width(int)`, `height(int)`, `label(string)`, `alt(string)`, `title(string)`, `filename(string)`, `focalPoint(array)`. Each writes to `$this->_config` and returns `$this`.
  - `label(string $value)`: `trim($value)`; reject `if (strlen($value) > 200) throw new InvalidArgumentException('Mock label exceeds 200 characters.');` (security: bounds `queryFontMetrics` cost).
  - `one(): MockAsset` — applies defaults (width=800, height=600 if unset; focalPoint stays null) then constructs `new MockAsset($this->_config)`.

- [ ] **`Assets` service extension** (`src/services/Assets.php`):
  - `make(array $config = []): MockAssetBuilder` — wraps `(new MockAssetBuilder())->configure($config)` when config provided, else returns empty builder.
  - Boot-time Imagick check inside `make()`: `if (!extension_loaded('imagick')) throw new RuntimeException('The Imagick PHP extension is required for parts-kit mock assets. Install ext-imagick or remove parts-kit mock usage.')`
  - `signedUrlForImage(int $w, int $h, ?string $label): string` — single source of truth for routing **and** signing. Builds the canonical **keyed** payload (`Json::encode(['w' => $w, 'h' => $h, 'label' => $label])`), signs it with `Craft::$app->getSecurity()->hashData($payload)` (HMAC keyed by the app security key — the same primitive behind Craft's `|hash` Twig filter), URL-safe-encodes the result, and wraps it: `UrlHelper::siteUrl($partsKitDir . '/mock/' . StringHelper::base64UrlEncode($signed) . '.png')`. `MockAsset::getUrl()` delegates here.
    - Use `yii\helpers\StringHelper::base64UrlEncode()` / `base64UrlDecode()` for transport-safe encoding of the signed payload (avoids `/`, `+`, `=` in the path segment). The controller reverses this before `validateData()`.

- [ ] **`MockController`** (`src/controllers/MockController.php`):
  - Namespace: `viget\partskit\controllers`
  - Extends `craft\web\Controller`
  - `protected array|bool|int $allowAnonymous = ['view'];` (access decisions happen inside the action, mirroring the Parts Kit gate)
  - `actionView(string $token): Response`:
    1. **Access mirrors Parts Kit visibility (before signature validation — removes timing oracle):** if `Plugin::getInstance()->getSettings()->requireViewPermission` → `$this->requirePermission('parts-kit:view')`. **No devMode/admin hard-block** — if a user can view the Parts Kit, they can load its mock images. When `requireViewPermission` is `false`, `allowAnonymous` leaves the route public, exactly like the Parts Kit itself.
    2. **Validate the signature (tamper-proof):** `$payload = Craft::$app->getSecurity()->validateData(StringHelper::base64UrlDecode($token)); if ($payload === false) throw new NotFoundHttpException();` — the HMAC is keyed by the app security key, so a tampered or hand-crafted token can't be forged and resolves to a standard 404 (no XSS risk; Craft renders its own error page).
    3. **Decode the validated config:** `$config = Json::decode($payload);` then read `$config['w']`, `$config['h']`, `$config['label']`. These values are trusted — they came from a signature the server itself produced. New payload keys (e.g. `format`) are read here.
    4. Resolve path: `$path = Craft::getAlias('@storage/runtime/parts-kit-mocks/') . sha1($payload) . '.png';` — the filename is derived from server-validated data, never from raw request input, so path traversal is structurally impossible (`basename()` is no longer needed).
    5. **Generate on first request (fully lazy):** `if (!file_exists($path)) MockImageGenerator::generate($path, $config['w'], $config['h'], $config['label']);` — this is the **only** place images are generated. The first request for a URL creates the PNG; every later request (and any request after `clear-caches/all`) hits the cache or regenerates from the same signed config. No render-time generation, no sidecar files, no 404-then-refresh.
    6. Set headers: `Cache-Control: public, max-age=31536000, immutable`. **No ETag, no 304 logic** — `immutable` is sufficient; the syscall and conditional-request branching are wasted complexity.
    7. `return $this->response->sendFile($path, 'mock.png', ['mimeType' => 'image/png', 'inline' => true]);` — hardcoded `'mock.png'` for the disposition filename (defense in depth against future header-injection via filename interpolation)

- [ ] **`Plugin.php` modifications**:
  - In `_registerUrlRules()`: add `$event->rules[$partsKitDir . '/mock/<token:[A-Za-z0-9_-]+>\.png'] = 'parts-kit/mock/view';` — the segment is a URL-safe base64 signed token, not a bare hash.
  - In `attachEventHandlers()`: register `ClearCaches::EVENT_REGISTER_CACHE_OPTIONS` listener:
    ```php
    Event::on(ClearCaches::class, ClearCaches::EVENT_REGISTER_CACHE_OPTIONS,
      function (RegisterCacheOptionsEvent $event) {
        $event->options[] = [
          'key' => 'parts-kit-mocks',
          'label' => Craft::t('parts-kit', 'Parts Kit mock images'),
          'action' => Craft::getAlias('@storage/runtime/parts-kit-mocks'),
        ];
      });
    ```
  - **Note:** No per-render devMode warning is added. Abuse safety is enforced by the controller: access mirrors the Parts Kit gate, and the tamper-proof signed token means only server-issued URLs ever resolve (no forged dimensions/labels). Mocks are documented as Parts Kit-only and not a production asset substitute.

**Success criteria:** All functional acceptance criteria pass; static analysis clean; existing parts-kit functionality unchanged.

#### Phase 3: Imager X integration

Soft-dependency on Imager X. Pro license enables full transparent integration; Lite gets a documented manual workaround in README. Integration is extracted into its own service per architecture review.

**Tasks**

- [ ] **`MockTransformedImage` model** (`src/models/MockTransformedImage.php`):
  - Implements `spacecatninja\imagerx\models\TransformedImageInterface`
  - Mirror `NoopImageModel` structure (`vendor/spacecatninja/imager-x/src/models/NoopImageModel.php` in projects that have Imager X installed — fall back to interface contract if not locally available)
  - Constructor accepts `MockAsset $asset, array $transform`
  - Methods return: `getUrl()` → `$asset->getUrl($transform)`, `getWidth()` and `getHeight()` → resolved via our own `Image::targetDimensions()`, `getPath()` → the disk path, `getExtension()` → 'png', `getMimeType()` → 'image/png'
  - **Class file must remain loadable even if Imager X is absent.** Use guarded loading via Composer autoloader plus `class_exists` check in `ImagerXIntegration::register()` before any reference. No top-level `use` of Imager X types in `Plugin.php`.

- [ ] **`ImagerXIntegration` service** (`src/services/ImagerXIntegration.php`):
  - Extends `yii\base\Component`
  - Static `isAvailable(): bool` → `class_exists(\spacecatninja\imagerx\services\ImagerService::class)`
  - `register(): void` — call from `Plugin::init`. Attaches `EVENT_BEFORE_TRANSFORM_IMAGE` listener:
    ```php
    public function register(): void
    {
        if (!self::isAvailable()) {
            return;
        }
        Event::on(
            \spacecatninja\imagerx\services\ImagerService::class,
            \spacecatninja\imagerx\services\ImagerService::EVENT_BEFORE_TRANSFORM_IMAGE,
            $this->_handleBeforeTransform(...)
        );
    }

    private function _handleBeforeTransform(\spacecatninja\imagerx\events\TransformImageEvent $event): void
    {
        if (!$event->image instanceof MockAsset) {
            return;
        }
        $event->transformedImages = array_map(
            fn(array $t) => new MockTransformedImage($event->image, $t),
            $event->transforms
        );
    }
    ```

- [ ] **`Plugin::config()`** — register the new service:
  ```php
  'components' => [
      'navigation' => Navigation::class,
      'assets' => Assets::class,
      'imagerXIntegration' => ImagerXIntegration::class,
  ],
  ```

- [ ] **`Plugin::init()`** — call `$this->get('imagerXIntegration')->register();` after `attachEventHandlers()`. (Service handles the `isAvailable()` guard internally.)

- [ ] **No Lite-license boot detection.** Document the manual `{ noop: true }` workaround in README. Don't add runtime detection — Viget uses Pro, and the warning would be noise for the 0.1% Lite case.

**Success criteria:** AC8 passes; non-Imager-X projects unaffected (plugin boots cleanly without Imager X installed); README documents the Lite workaround.

#### Phase 4: Polish & docs

**Tasks**

- [ ] **README.md updates**:
  - New section "Mock Assets" with the Twig usage examples from this plan
  - Document the supported `Asset` surface (and what throws)
  - Document Imager X Pro vs Lite behavior, including the manual `{ noop: true }` workaround for Lite
  - Note: mock image access mirrors Parts Kit visibility — public when `requireViewPermission` is off, otherwise gated on `parts-kit:view`. URLs are HMAC-signed and tamper-proof; an altered token returns `404`. Mocks are intended for Parts Kit templates only, not as a production asset substitute.
  - Note: cache wipes with `php craft clear-caches/all`
  - Note: file proliferation — different labels create different cached PNGs; cap is 5,000 files per directory
- [ ] Verify all `NotSupportedException` messages name the unsupported method and point to README
- [ ] **Audit one or two Viget consumer projects** for `Asset $param` type-hints in components. Confirm the extends-Asset path works for typed code. Document any patterns where components are untyped so v2 can consider an interface-based alternative.
- [ ] Test in CP entry preview context to verify mock URLs work outside the parts-kit iframe
- [ ] Final pass: `composer check-cs && composer phpstan`

**Success criteria:** README documents the full API; CP preview context verified; CI green; consumer-project audit recorded in the PR description.

## Alternative Approaches Considered

These were evaluated in the brainstorm and explicitly rejected:

- **Base64 data URLs:** bloats HTML, defeats browser caching, can't run Craft transforms.
- **Pre-generation + real Craft transform pipeline:** requires auto-provisioning a real Volume and creating DB rows per unique size. Crosses the line from "mock" into "real ephemeral asset"; the user explicitly preferred no DB writes.
- **External placeholder service (placehold.co, picsum):** requires internet, no transform support, third-party dependency.
- **Imager X custom Transformer registration:** Pro-only, requires authors to opt in via `transformer: 'mock'` per call. `EVENT_BEFORE_TRANSFORM_IMAGE` does it transparently based on asset type.
- **Sidecar `{hash}.json` config files for URL durability:** unnecessary — the tamper-proof signed token already carries the (HMAC-validated) config, so the controller regenerates a cleared PNG on demand without any sidecar file. Durability comes for free from the signing scheme.
- **GD fallback when Imagick is missing:** YAGNI. Imagick is already a Craft transform dependency in most production stacks; we add it as a composer requirement.

## Acceptance Criteria

### Functional Requirements

- [ ] **AC1** — `partsKit.assets.make({width: 800, height: 600}).one` returns a `MockAsset` whose `.getUrl()` returns a URL matching `/parts-kit/mock/[A-Za-z0-9_-]+\.png` (a URL-safe, HMAC-signed token).
- [ ] **AC2** — A GET to the URL from AC1 by a requester who can view the Parts Kit returns HTTP 200, `Content-Type: image/png`, `Cache-Control: public, max-age=31536000, immutable`, and a PNG of exact pixel dimensions 800×600.
- [ ] **AC3** — `partsKit.assets.make.one` (no setters) renders an 800×600 PNG (the default).
- [ ] **AC4** — `partsKit.assets.make({width: 800, height: 600, alt: 'Hero'}).one.getImg()` renders `<img>` with `src`, `width=800`, `height=600`, `alt="Hero"` attributes populated.
- [ ] **AC5** — `partsKit.assets.make({width: 1600, height: 900}).one.getUrl({width: 400, mode: 'fit'})` returns a URL that serves a 400×225 PNG (aspect-preserved via `Image::targetDimensions`).
- [ ] **AC6** — Two identically-configured mocks produce the same hash, generate one file on disk, and return the same URL.
- [ ] **AC7** — Changing only `label` (e.g. `{label: 'Hero'}` vs `{label: 'Thumbnail'}`) produces a different hash and a separate file on disk (this is intentional and documented).
- [ ] **AC8** — With Imager X Pro installed, `craft.imagerx.transformImage(mock, {width: 400})` returns a `MockTransformedImage` whose URL serves a 400-px-wide PNG. `LocalSourceImageModel::getLocalCopy()` is never invoked.
- [ ] **AC9** — `php craft clear-caches/all` empties `@storage/runtime/parts-kit-mocks/` (verified by listing the directory before and after).
- [ ] **AC10** — Calling any unsupported `Asset` method (e.g. `mock.getVolume()`, `mock.getFolder()`, `mock.getFieldValue('caption')`) throws `NotSupportedException` whose message includes the method name AND a pointer to the README.
- [ ] **AC11** — Template rendering performs zero image generation and zero filesystem access: `getUrl()`/`getImg()`/`getSrcset()` only build signed URL strings (verifiable by asserting Imagick is never instantiated and no file is touched during a render). All generation happens inside `MockController`.
- [ ] **AC12** — A `getSrcset` call with N sizes triggers no generation at render time; it returns N signed URLs, and each size's PNG is generated lazily on its first HTTP request (then cached). Requesting all N URLs on a cold cache produces N correctly-sized PNGs.
- [ ] **AC13** — A mock URL whose signed token has been altered by even one byte returns HTTP 404 via `Security::validateData()`. An untampered URL is viewable by exactly whoever can view the Parts Kit: public when `requireViewPermission = false`, gated on `parts-kit:view` when `true`. There is no separate devMode gate on mock images.

### Non-Functional Requirements

- [ ] **NFR1** — Concurrent first-request generation of the same hash from two HTTP requests never produces a corrupt PNG. Implementation uses temp-file + atomic `rename()`.
- [ ] **NFR2** — Mock URLs are tamper-proof: the path token is an HMAC-signed payload (`Security::hashData`/`validateData`); any modification fails validation and yields 404. The on-disk filename is `sha1($validatedPayload)` — derived from server-validated data, never from raw request input — so path traversal is structurally impossible.
- [ ] **NFR3** — Mock image access mirrors Parts Kit visibility. When `Settings::$requireViewPermission` is `true`, both the Parts Kit and its mock URLs require `parts-kit:view` (unauthorized requests receive 403). When `false`, both are public. If the Parts Kit renders for a user, its mock images load for that user — there is no independent devMode gate on images.
- [ ] **NFR4** — If Imagick is missing, `partsKit.assets.make()` throws a clear `RuntimeException` naming the missing extension and the README workaround.
- [ ] **NFR5** — The plugin loads correctly when Imager X is *not* installed (no fatal class-not-found errors); the integration only registers when `ImagerXIntegration::isAvailable()` returns true.
- [ ] **NFR6** — Generation gracefully bounds disk usage: `MockImageGenerator::generate()` aborts and logs a warning when the cache directory contains more than 5,000 PNG files.
- [ ] **NFR7** — `MockAssetBuilder::label()` rejects input over 200 characters with `InvalidArgumentException`.
- [ ] **NFR8** — `MockController::actionView` runs the access check BEFORE signature validation, so an unauthorized request always receives 403 regardless of token validity — no oracle distinguishing a valid token from a forged one.

### Quality Gates

- [ ] `composer check-cs` clean
- [ ] `composer phpstan` clean
- [ ] Manual verification of all 10 functional ACs from a parts-kit template
- [ ] Manual verification with Imager X Pro installed (AC8) and uninstalled (NFR7)
- [ ] Manual verification of CP entry preview context (mock URL renders correctly inside the CP preview iframe)
- [ ] README updated, including supported surface table and Imager X behavior matrix

## Success Metrics

This is dev tooling — no production telemetry. Success is qualitative:

1. A Viget developer can scaffold a new parts-kit template for an image-consuming component without uploading or seeding any Craft assets.
2. The same parts-kit template renders identically on a fresh checkout (no environment-specific asset state).
3. Both Craft native and Imager X transform call sites work without special-casing in component templates.

## Dependencies & Prerequisites

### Build / Runtime

- PHP 8.2+ (already required by the plugin)
- Craft CMS 5.0+ (already required)
- PHP `ext-imagick` (newly required — added to `composer.json`)
- Bundled font: DejaVu Sans TTF (added to `src/resources/fonts/`)

### Optional integrations

- Imager X Pro — for transparent integration without per-call opt-in. Lite users see documented manual workaround. No-Imager projects unaffected.

### No new composer requirements other than ext-imagick

Specifically NOT adding:
- `spacecatninja/imager-x` (soft dependency via `class_exists`)
- Any image-generation library beyond Imagick

## Risk Analysis & Mitigation

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| **Imagick absent on dev container** | Medium | High (mock unusable) | Boot-time check in `make()`; clear error message; README install guidance |
| **Font missing on minimal Docker image** | Medium | Medium (text rendering breaks) | Bundle DejaVuSans.ttf with the plugin; fallback chain to system fonts |
| **Imager X Lite user hits Pro-only short-circuit** | Low (Viget uses Pro) | Medium (5xx error) | README documents manual `{ noop: true }` workaround; no runtime detection (kept simple) |
| **Concurrent generation corrupts cached file** | Low | Medium (broken image until refresh) | Atomic temp-file + rename pattern |
| **Forged / tampered mock URL** | Low | High (unauthorized generation or disclosure) | HMAC signature (`Security::validateData`) — unforgeable without the app security key; cache filename is `sha1($validatedPayload)`, never raw input, so no path traversal |
| **Mock used in a real (non-Parts-Kit) template** | Medium | Low (visual only, not security) | By design, images render wherever Parts Kit visibility allows; signed URLs prevent forgery; README states mocks are for Parts Kit templates only and are not a production asset substitute |
| **Malicious template loops to fill disk via unique hashes** | Low (trusted dev env) | Low (storage/runtime is small) | 5,000-file cap in `MockImageGenerator::generate()`; generation aborts and logs a warning past the threshold |
| **Long-label DoS via `queryFontMetrics`** | Low | Low | 200-char cap on `MockAssetBuilder::label()` |
| **File proliferation fills disk** | Low | Low (storage/runtime is small) | `clear-caches/all` integration; documented in README |
| **`MockAsset` autocomplete misleads authors into calling unsupported methods** | High | Low (clear runtime error) | `@method` PHPDoc on the class; `NotSupportedException` includes method name + README pointer |
| **CP entry preview can't load mock URLs** | Low | Medium (preview broken for parts-kit-using components) | Verify in Phase 4; URL rule registers on site rules so should work |
| **Plugin install order issues with Imager X** | Low | Medium (event listener doesn't fire) | `Craft::$app->onInit` deferral already in place ensures Imager X has loaded before our listener registers |

## Resource Requirements

Single-developer implementation. Estimated effort: ~1-2 days for Phases 1-3 with manual verification, plus polish and docs in Phase 4. No infrastructure changes required.

## Future Considerations

Explicitly out of scope for v1 (deferred until a real need surfaces):

- **Background color / text color customization** (`->bgColor`, `->textColor` builder methods)
- **Format selection** (always PNG in v1; honoring `->format('webp')` later means adding a `format` key to the signed payload, switching the URL extension + `Content-Type`, and branching `MockImageGenerator` — additive, no API break)
- **Pluggable image generators** (`->generator(callable)` escape hatch for picsum/AI/custom)
- **Non-image asset kinds** (PDF, video — would require generator branching)
- **Custom field support** on mocks (`->set('caption', ...)`)
- **Plugin config knobs** for storage path / URL prefix
- **Auto-injection of `noop: true` for Imager X Lite** (currently documented-only)
- **`PartsKitAssetLike` interface** — decouple component code from the Asset hierarchy via a structural interface that both real Assets and MockAssets implement. Requires consumer-project audit (see Phase 4) to determine whether components type-hint on `Asset` or accept duck-typed values.

### Security considerations for v2 additions

When implementing any of the deferred items, watch for these vectors:

- **Pluggable image generators (`->generator(callable)`)** — RCE-shaped surface. Will need an allowlist of registered generator names, or a no-callable-accepted design (only opaque generator IDs registered via config).
- **Custom field support** — hash inputs must include field values to prevent cache-collision-based pollution.
- **Plugin config knobs for storage path** — must reject paths outside `@storage` to prevent arbitrary file overwrite via the `rename()` step in `MockImageGenerator`.

Adding any of the above should be possible additively without breaking the v1 API.

## Documentation Plan

- [ ] README.md — new "Mock Assets" section (see Phase 4)
- [ ] Inline PHPDoc — `@method` block on `MockAsset` listing supported subset
- [ ] Inline PHPDoc — every public method on `MockAssetBuilder` with example call site
- [ ] CHANGELOG.md — entry for v[next].0.0 announcing mock asset support and ext-imagick requirement
- [ ] No separate API docs site — README is the primary reference

## References & Research

### Internal references

- Brainstorm: `docs/brainstorms/2026-05-25-mock-asset-brainstorm.md`
- Existing services: `src/services/Assets.php:11`, `src/services/Navigation.php:16`
- Existing controllers: `src/controllers/ViewController.php:17`, `src/controllers/ApiController.php:21`
- Existing models: `src/models/MockAsset.php:23`, `src/models/MockAssetBuilder.php`, `src/models/Settings.php:7`
- Plugin entry: `src/Plugin.php` (specifically `_registerUrlRules:122`, `attachEventHandlers:69`, `config():38-46`)
- Permission registration: `src/Plugin.php:99-112`
- Existing permission gate (Twig): `src/templates/root.twig:13`

### Craft 5 source references

- `vendor/craftcms/cms/src/elements/Asset.php:1832` — `getImg()` default markup
- `vendor/craftcms/cms/src/elements/Asset.php:1888` — `getSrcset()` signature
- `vendor/craftcms/cms/src/elements/Asset.php:2150` — `getUrl()` signature
- `vendor/craftcms/cms/src/elements/Asset.php:2509` — `getWidth()` typed-union signature (NOT mixed)
- `vendor/craftcms/cms/src/elements/Asset.php:1076` — `public ?string $alt = null;` (native property since 4.0)
- `vendor/craftcms/cms/src/elements/Asset.php:1070` — `public ?string $kind = null;` (no getter — direct property)
- `vendor/craftcms/cms/src/elements/Asset.php:185-207` — `KIND_IMAGE` and related constants
- `vendor/craftcms/cms/src/helpers/ImageTransforms.php:270` — `normalizeTransform()` for all transform argument shapes
- `vendor/craftcms/cms/src/helpers/Image.php:90` — `targetDimensions()` for mode-aware dimension resolution
- `vendor/craftcms/cms/src/utilities/ClearCaches.php:42` — `EVENT_REGISTER_CACHE_OPTIONS` constant
- `vendor/craftcms/cms/src/web/Response.php:245` — `sendFile()` signature

### Imager X references (Pro license)

- `spacecatninja/craft-imager-x/src/services/ImagerService.php:46` — `EVENT_BEFORE_TRANSFORM_IMAGE` constant
- `spacecatninja/craft-imager-x/src/events/TransformImageEvent.php` — event payload shape
- `spacecatninja/craft-imager-x/src/transformers/TransformerInterface.php` — `transform()` contract (not used directly, but mirrors what we return)
- `spacecatninja/craft-imager-x/src/models/NoopImageModel.php` — reference implementation to mirror for `MockTransformedImage`

### External references

- Craft CMS 5.x docs — Assets: https://craftcms.com/docs/5.x/reference/element-types/assets.html
- Craft CMS 5.x docs — Controllers: https://craftcms.com/docs/5.x/extend/controllers.html
- Yii 2 `Security::validateData()` / `hashData()` — HMAC data signing: https://www.yiiframework.com/doc/api/2.0/yii-base-security#validateData()-detail
- Craft CMS 5.x docs — `hash` Twig filter (HMAC, validated via `craft.app.security.validateData()`): https://craftcms.com/docs/5.x/reference/twig/filters.html#hash
- Imager X extending: https://imager-x.spacecat.ninja/extending.html
- Imagick docs — setFont: https://www.php.net/manual/en/imagick.setfont.php
- Imagick docs — queryFontMetrics: https://www.php.net/manual/en/imagick.queryfontmetrics.php

### Brainstorm decision summary

All decisions from the brainstorm are reflected above. Notable resolved questions:

- Default dimensions: **800×600**
- Filename default: **auto-generated `mock-{w}x{h}.png`; setting filename derives extension/mimeType**
- Focal point default: **null; `hasFocalPoint()` returns false**
- Asset kinds in v1: **image-only**
- Hash inputs: **width, height, label** (keyed, HMAC-signed payload; no plugin version prefix; rely on clear-caches)
- Imager X Lite UX: **documented manual workaround only**
- Plugin config knobs: **hardcoded for v1**
