# Mock Asset Service — Brainstorm

**Date:** 2026-05-25
**Status:** Brainstorm complete, ready for planning

---

## What We're Building

A `MockAsset` element class plus a builder API that lets parts-kit template authors render placeholder images without needing real assets uploaded locally. Mocks behave enough like real `craft\elements\Asset` instances that typical Twig component templates (which call `asset.getUrl()`, `asset.getImg()`, `asset.alt`, `asset.focalPoint`, etc.) render correctly when handed a mock instead of a real asset.

The motivating problem: Parts Kit renders example versions of Twig components. Those components frequently require a Craft asset, but local dev environments often don't have assets uploaded — and even when they do, asset state is not in sync across machines or fresh checkouts. The mock fills that gap.

**Twig call site (target API):**

```twig
{# Terse: hash config #}
{% set hero = partsKit.assets.make({
  width: 1600,
  height: 900,
  label: 'Hero',
  alt: 'Hero photo',
}).one %}

{# Or fluent: chained setters #}
{% set thumb = partsKit.assets.make
  .width(400)
  .height(300)
  .label('Thumbnail')
  .one %}

{# Component then uses the mock like any real asset #}
{{ Image({ asset: hero, transform: 'hero' }) }}
```

---

## Why This Approach

### Path A: Override transform methods directly on `MockAsset`

We extend `craft\elements\Asset`, override the four transform-producing methods (`getUrl`, `getImg`, `getSrcset`, `getUrlsBySize`) plus the asset surface that components actually touch (`alt`, `title`, `filename`, `extension`, `mimeType`, `kind`, focal point). All other Asset methods continue to throw `NotSupportedException` — the mock isn't trying to impersonate a complete `Asset`, only the subset components consume.

**Considered and rejected: pre-generate + use Craft's native transform pipeline.** This would mean auto-provisioning a real Volume, writing real DB rows, and making the "mock" effectively a real (ephemeral) Asset. It's cleaner inside Craft's transform code but pulls a lot of state into the picture: a Volume must exist, DB grows with one row per unique size, and the mock stops being a mock.

The user explicitly preferred no DB writes and no provisioned Volume, so Path A wins despite costing more code in `MockAsset` itself.

### Imager X support via `EVENT_BEFORE_TRANSFORM_IMAGE`

Imager X intercepts the transform call site (`craft.imagerx.transformImage(asset, transform)`), so our `getUrl` override never runs in projects that use it. Imager X reads source files through `LocalSourceImageModel`, which would crash on a fileless mock inside `getLocalCopy()`.

**Solution:** the plugin registers a listener on `ImagerService::EVENT_BEFORE_TRANSFORM_IMAGE`. When `$event->image instanceof MockAsset`, the handler populates `$event->transformedImages` with a custom `MockTransformedImage` (modeled after `NoopImageModel`), short-circuiting the rest of the pipeline. Real assets fall through untouched.

This requires an Imager X **Pro** license (the `transformedImages` short-circuit is Pro-gated). Viget's projects already use Pro. For OSS / Lite users we'll document the manual `noop: true` config-override fallback.

A custom transformer (via `EVENT_REGISTER_TRANSFORMERS`) was considered but rejected — it would force template authors to opt in with `transformer: 'mock'` per call, while the event hook does it transparently based on asset type.

### File storage: `@storage/runtime/parts-kit-mocks/`

Generated PNGs live in `@storage/runtime/parts-kit-mocks/`, served by a plugin-registered controller route under the existing `/parts-kit/` URL namespace (so the existing `parts-kit:view` permission gate covers them automatically). Files are named by a SHA-1 hash of the full mock configuration, so identical configs produce identical URLs and the browser caches them. Lazy generation: files are written on first request, served from disk after.

Rejected alternatives: base64 data URLs (bloats HTML, defeats caching, breaks transforms), `@webroot/cpresources` (wrong category — that folder is for asset bundles), arbitrary `@webroot/parts-kit-mocks/` (pollutes the project's webroot, permissions issues in some envs), placeholder services like `placehold.co` (requires internet, breaks transforms, third-party dependency).

---

## Key Decisions

| Decision | Choice |
|---|---|
| **Asset behavior strategy** | Path A: override transform methods on `MockAsset`; keep `NotSupportedException` on everything else |
| **Image delivery** | Real PNG files on disk, served via plugin controller route |
| **Storage location** | `@storage/runtime/parts-kit-mocks/{hash}.png` |
| **URL pattern** | `/parts-kit/mock/{hash}.png` (under existing parts-kit namespace, gated by `parts-kit:view`) |
| **File naming** | SHA-1 hash of full mock config (width, height, label, format, etc.) |
| **Caching headers** | `Cache-Control: max-age=31536000, immutable` (content-addressed URLs) |
| **Cleanup** | Integrated with `php craft clear-caches/all` (storage/runtime is cache-clearable) |
| **Imager X integration** | `EVENT_BEFORE_TRANSFORM_IMAGE` listener short-circuits with `MockTransformedImage` (requires Pro) |
| **Imager X Lite fallback** | Documented only — users pass `{ noop: true }` manually |
| **Visual customization** | Dimensions + optional `label` only. Always gray PNG. No bg color, text color, or format choice. |
| **Twig API** | Both hash config (`make({...})`) AND fluent setters on the returned builder |
| **Builder methods** | `width`, `height`, `label`, `alt`, `title`, `filename`, `focalPoint`, terminal `one` |
| **Asset surface implemented** | dimensions, transforms, alt, title, filename, extension, mimeType, kind, focal point |
| **Asset surface NOT implemented** | Custom fields, volume, folder, uploader, anything DB-backed |
| **Image generator** | Imagick (already in place — generates gray PNG with centered dimension/label text) |

---

## Implementation Sketch (PHP-side)

```php
// services/Assets.php
public function make(array $config = []): MockAssetBuilder
{
    return (new MockAssetBuilder())->configure($config);
}

// models/MockAssetBuilder.php
public function configure(array $config): self
{
    foreach ($config as $key => $value) {
        if (method_exists($this, $key)) {
            $this->$key($value);
        }
    }
    return $this;
}

public function width(int $v): self { /* ... */ }
public function height(int $v): self { /* ... */ }
public function label(string $v): self { /* ... */ }
public function alt(string $v): self { /* ... */ }
public function title(string $v): self { /* ... */ }
public function filename(string $v): self { /* ... */ }
public function focalPoint(array $v): self { /* ... */ }

public function one(): MockAsset { /* ... */ }
```

```php
// models/MockAsset.php — sketch of the transform-producing override
public function getUrl(mixed $transform = null, ?bool $immediately = null): ?string
{
    [$w, $h] = $this->resolveTransformDimensions($transform);
    $hash = sha1(json_encode([
        'width' => $w,
        'height' => $h,
        'label' => $this->label,
    ]));
    $path = Craft::getAlias('@storage/runtime/parts-kit-mocks/' . $hash . '.png');
    if (!file_exists($path)) {
        $this->generatePng($path, $w, $h, $this->label);
    }
    return UrlHelper::actionUrl('parts-kit/mock/view', ['hash' => $hash]);
}
```

```php
// Plugin.php — Imager X integration (in attachEventHandlers)
if (class_exists(ImagerService::class)) {
    Event::on(
        ImagerService::class,
        ImagerService::EVENT_BEFORE_TRANSFORM_IMAGE,
        function (TransformImageEvent $event) {
            if (!$event->image instanceof MockAsset) {
                return;
            }
            $event->transformedImages = array_map(
                fn(array $t) => new MockTransformedImage($event->image, $t),
                $event->transforms
            );
        }
    );
}
```

---

## Resolved Questions

1. **Default dimensions (unset width/height):** Default to **800×600**. `partsKit.assets.make.one` with no setters renders an 800×600 gray PNG.

2. **Filename defaults & derivation:** Default filename is **`mock-{width}x{height}.png`**. When `->filename('hero.jpg')` is set, extension/mimeType/kind are **auto-derived** from the filename. Single source of truth.

3. **Focal point default:** **`null`**, with `hasFocalPoint()` returning `false`. Faithful to Craft's real behavior — focal points are opt-in on real assets. Users set explicitly via `->focalPoint([0.3, 0.7])`.

4. **Non-image asset kinds:** **Image-only in v1.** `kind` always returns `'image'` regardless of the filename. Document the limitation; revisit only if a real component needs PDF/video mocking.

5. **Hash inputs:** **Config only** — `sha1(width|height|label|...)`. No plugin version prefix. Invalidation happens via `php craft clear-caches/all`, consistent with the rest of Craft.

6. **Imager X Lite (OSS) experience:** **Document only.** No runtime auto-injection. README explains the manual `{ noop: true }` workaround for Lite users. Viget projects use Pro, so the event-hook path is the supported path.

7. **Plugin config knobs:** **Hardcoded for v1.** Storage at `@storage/runtime/parts-kit-mocks/`, URL at `/parts-kit/mock/`. Add config knobs only if a concrete need emerges.

---

## Out of Scope (Explicit YAGNI)

- Custom fields on mocks (`->set('caption', ...)`)
- Background color / text color customization
- Format choice (always PNG)
- Pluggable image generators (picsum, AI, custom callbacks)
- Real Craft transform pipeline running on mock files (avoided to keep the design DB-free and Volume-free)
- Imager X Pro custom transformer registration (event-hook approach is cleaner for this use case)
- Mocking non-image asset kinds (PDFs, videos)

---

## Next Step

Run `/workflows:plan` to convert this brainstorm into an implementation plan.
