<?php

namespace viget\partskit\models;

use craft\base\FsInterface;
use craft\elements\Asset;
use craft\elements\User;
use craft\helpers\Assets as AssetsHelper;
use craft\helpers\FileHelper;
use craft\helpers\Html;
use craft\helpers\Image;
use craft\helpers\ImageTransforms;
use craft\helpers\Template;
use craft\models\FieldLayout;
use craft\models\ImageTransform;
use craft\models\Volume;
use craft\models\VolumeFolder;
use Twig\Markup;
use viget\partskit\Plugin;
use yii\base\NotSupportedException;
use yii\helpers\ArrayHelper;

/**
 * A placeholder Asset for Parts Kit previews.
 *
 * MockAsset extends {@see Asset} but is **not** a Liskov-substitutable Asset: it
 * has no row in the `assets` table, no Volume, no filesystem, and no field
 * layout. It implements only the subset of the Asset surface that real-world
 * Twig component templates actually consume — dimensions, transform-aware
 * sizing, alt/title, filename/extension/mimeType/kind, and focal point. Every
 * DB- or volume-touching method throws {@see NotSupportedException}. Callers
 * that need a full Asset must not receive a MockAsset.
 *
 * Construction is normally driven by {@see MockAssetBuilder::one()}, which
 * passes a config array. Mock-specific keys (`width`, `height`, `label`,
 * `filename`, `focalPoint`) are extracted into typed properties here; `alt` and
 * `title` flow through the normal Asset/Element config handling.
 */
class MockAsset extends Asset
{
    private ?int $_width = null;

    private ?int $_height = null;

    private ?string $_label = null;

    private ?string $_filename = null;

    /**
     * @var array{x: float, y: float}|null
     */
    private ?array $_focalPoint = null;

    /**
     * Memoized signed URLs, keyed by resolved `{width}x{height}`. Rendering the
     * same image at the same size never re-signs.
     *
     * @var array<string, string>
     */
    private array $_urlCache = [];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct($config = [])
    {
        $this->_width = ArrayHelper::remove($config, 'width');
        $this->_height = ArrayHelper::remove($config, 'height');
        $this->_label = ArrayHelper::remove($config, 'label');
        $this->_filename = ArrayHelper::remove($config, 'filename');

        /** @var array{x: float, y: float}|null $focalPoint */
        $focalPoint = ArrayHelper::remove($config, 'focalPoint');
        $this->_focalPoint = $focalPoint;

        parent::__construct($config);
    }

    public function init(): void
    {
        parent::init();

        // A mock has no DB row or volume; pin a sentinel id and force the image
        // kind so component code branching on `kind`/`id` behaves predictably.
        $this->id = -1;
        $this->kind = Asset::KIND_IMAGE;
        $this->setScenario(self::SCENARIO_CREATE);
    }

    /**
     * The mock's label (used as generated-image text and in the signed URL
     * payload). Not part of the Asset surface — specific to mocks.
     */
    public function getLabel(): ?string
    {
        return $this->_label;
    }

    /**
     * Emits a signed URL for the mock at the (optionally transformed) size.
     * This is render-time only: it performs zero Imagick and zero filesystem
     * work — the PNG is generated lazily by the controller on first request.
     */
    public function getUrl(mixed $transform = null, ?bool $immediately = null): ?string
    {
        [$width, $height] = $this->_resolveDimensions($transform);

        if ($width === null || $height === null) {
            return null;
        }

        $key = $width . 'x' . $height;

        return $this->_urlCache[$key] ??= Plugin::getInstance()
            ->getAssets()
            ->signedUrlForImage($width, $height, $this->_label);
    }

    public function getImg(mixed $transform = null, ?array $sizes = null): ?Markup
    {
        if ($this->kind !== self::KIND_IMAGE) {
            return null;
        }

        $url = $this->getUrl($transform);

        if ($url === null) {
            return null;
        }

        // Resolve dimensions once for the width/height attributes.
        [$width, $height] = $this->_resolveDimensions($transform);

        $img = Html::tag('img', '', [
            'src' => $url,
            'width' => $width,
            'height' => $height,
            'srcset' => $sizes ? $this->getSrcset($sizes, $transform) : false,
            'alt' => $this->alt,
        ]);

        return Template::raw($img);
    }

    /**
     * Ported from {@see Asset::getUrlsBySize()} — same descriptor parsing,
     * `ceil()` rounding, and only-carry-height-if-the-base-transform-set-one
     * rule — but emitting signed mock URLs instead of transform URLs.
     * `getSrcset()` needs no override: Craft's inherited implementation just
     * formats whatever this method returns into a srcset string.
     *
     * @param string[] $sizes
     * @return array<string, string|null>
     */
    public function getUrlsBySize(array $sizes, mixed $transform = null): array
    {
        if ($this->kind !== self::KIND_IMAGE) {
            return [];
        }

        $normalized = ImageTransforms::normalizeTransform($transform);

        [$currentWidth, $currentHeight] = $this->_resolveDimensions($normalized);

        if (!$currentWidth || !$currentHeight) {
            return [];
        }

        $urls = [];

        foreach ($sizes as $size) {
            if ($size === '1x') {
                $urls[$size] = $this->getUrl($normalized);
                continue;
            }

            [$value, $unit] = AssetsHelper::parseSrcsetSize($size);

            $sizeTransform = $normalized ? $normalized->toArray() : [];
            unset($sizeTransform['name'], $sizeTransform['handle']);

            if ($unit === 'w') {
                $sizeTransform['width'] = (int)$value;
            } else {
                $sizeTransform['width'] = (int)ceil($currentWidth * $value);
            }

            // Only carry a height if the base transform set one.
            if ($normalized && $normalized->height) {
                $sizeTransform['height'] = $unit === 'w'
                    ? (int)ceil($currentHeight * $sizeTransform['width'] / $currentWidth)
                    : (int)ceil($currentHeight * $value);
            }

            $urls["$value$unit"] = $this->getUrl($sizeTransform);
        }

        return $urls;
    }

    public function getWidth(array|string|ImageTransform $transform = null): ?int
    {
        return $this->_resolveDimensions($transform)[0];
    }

    public function getHeight(mixed $transform = null): ?int
    {
        return $this->_resolveDimensions($transform)[1];
    }

    public function getFilename(bool $withExtension = true): string
    {
        $filename = $this->_filename
            ?? sprintf('mock-%dx%d.png', $this->_width ?? 0, $this->_height ?? 0);

        if (!$withExtension) {
            return pathinfo($filename, PATHINFO_FILENAME);
        }

        return $filename;
    }

    public function setFilename(string $filename): void
    {
        $this->_filename = $filename;
    }

    public function getExtension(): string
    {
        return strtolower(pathinfo($this->getFilename(), PATHINFO_EXTENSION) ?: 'png');
    }

    public function getMimeType(mixed $transform = null): ?string
    {
        // No fallback: an unrecognized extension honestly reports null rather
        // than claiming image/png. (The default mock-{w}x{h}.png filename
        // always resolves.)
        return FileHelper::getMimeTypeByExtension($this->getFilename());
    }

    public function getHasFocalPoint(): bool
    {
        return $this->_focalPoint !== null;
    }

    /**
     * Mirrors {@see Asset::getFocalPoint()} exactly: null for non-visual kinds,
     * otherwise the set focal point or Craft's center default (`0.5/0.5`) — a
     * real image Asset never returns null here, so a mock must not either
     * (`object-position: {{ asset.getFocalPoint(true) }}` has to behave the
     * same for both). `getHasFocalPoint()` still reports false when unset,
     * matching Craft.
     */
    public function getFocalPoint(bool $asCss = false): array|string|null
    {
        if (!in_array($this->kind, [self::KIND_IMAGE, self::KIND_VIDEO], true)) {
            return null;
        }

        $focal = $this->_focalPoint ?? ['x' => 0.5, 'y' => 0.5];

        if ($asCss) {
            return sprintf('%s%% %s%%', $focal['x'] * 100, $focal['y'] * 100);
        }

        return $focal;
    }

    public function setFocalPoint(array|string|null $value): void
    {
        $this->_focalPoint = is_array($value) ? $value : null;
    }

    // --- Unsupported surface -------------------------------------------------
    // Methods that would touch the database, a Volume, a filesystem, or the
    // field layout. A mock has none of these, so they fail loudly rather than
    // returning misleading empty data.

    public function getVolume(): Volume
    {
        throw $this->_unsupported(__METHOD__);
    }

    public function getFolder(): VolumeFolder
    {
        throw $this->_unsupported(__METHOD__);
    }

    public function getFs(): FsInterface
    {
        throw $this->_unsupported(__METHOD__);
    }

    public function getUploader(): ?User
    {
        throw $this->_unsupported(__METHOD__);
    }

    public function getFieldLayout(): ?FieldLayout
    {
        throw $this->_unsupported(__METHOD__);
    }

    public function getFieldValue(string $fieldHandle): mixed
    {
        throw $this->_unsupported(__METHOD__);
    }

    public function getFieldValues(?array $fieldHandles = null): array
    {
        throw $this->_unsupported(__METHOD__);
    }

    /**
     * Resolves the mock's dimensions for an optional transform. With no
     * transform (or no base dimensions) the base size is returned; otherwise the
     * mode-aware target size is computed exactly as Craft would for a real asset.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function _resolveDimensions(mixed $transform): array
    {
        if ($this->_width === null || $this->_height === null) {
            return [$this->_width, $this->_height];
        }

        $normalized = ImageTransforms::normalizeTransform($transform);

        if ($normalized === null) {
            return [$this->_width, $this->_height];
        }

        return Image::targetDimensions(
            $this->_width,
            $this->_height,
            $normalized->width,
            $normalized->height,
            $normalized->mode,
            $normalized->upscale,
        );
    }

    private function _unsupported(string $method): NotSupportedException
    {
        return new NotSupportedException(
            $method . ' is not supported on MockAsset; see README.',
        );
    }
}
