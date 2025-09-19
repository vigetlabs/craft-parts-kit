<?php

namespace viget\partskit\models;

use craft\base\FsInterface;
use craft\elements\Asset;
use craft\elements\conditions\ElementConditionInterface;
use craft\elements\db\AssetQuery;
use craft\elements\db\EagerLoadPlan;
use craft\elements\User;
use craft\errors\ImageTransformException;
use craft\models\FieldLayout;
use craft\models\ImageTransform;
use craft\models\Volume;
use craft\models\VolumeFolder;
use InvalidArgumentException;
use Twig\Markup;
use yii\base\NotSupportedException;

/**
 * Work in Progress 
 */
class MockAsset extends Asset
{
    private ?int $_width = null;
    private ?int $_height = null;

    public function __construct()
    {
    }

    public function init(): void
    {
    }

    /**
     * @inheritdoc
     * @return AssetQuery The newly created [[AssetQuery]] instance.
     */
    public static function find(): AssetQuery
    {
        throw new NotSupportedException('This is a mock and does not support finding assets.');
    }

    /**
     * @inheritdoc
     * @return ElementConditionInterface
     */
    public static function createCondition(): ElementConditionInterface
    {
        throw new NotSupportedException('This is a mock and does not support finding assets.');
    }

    /**
     * @inheritdoc
     * @since 3.4.0
     */
    public static function eagerLoadingMap(array $sourceElements, string $handle): array|null|false
    {
        throw new NotSupportedException('This is a mock and does not support finding assets.');
    }

    /**
     * @inheritdoc
     * @since 3.4.0
     */
    public function setEagerLoadedElements(string $handle, array $elements, EagerLoadPlan $plan): void
    {
        throw new NotSupportedException('This is a mock and does not support finding assets.');
    }

    /**
     * @inheritdoc
     */
    protected static function defineSources(string $context): array
    {
        throw new NotSupportedException('This is a mock and does not support finding assets.');
    }

    /**
     * @inheritdoc
     */
    public static function findSource(string $sourceKey, ?string $context): ?array
    {
        throw new NotSupportedException('This is a mock and does not support finding assets.');
    }

    public static function sourcePath(string $sourceKey, string $stepKey, ?string $context): ?array
    {
        throw new NotSupportedException('This is a mock and does not support finding assets.');
    }

    /**
     * @inheritdoc
     */
    protected static function defineFieldLayouts(?string $source): array
    {
        throw new NotSupportedException('Not implemented.');
    }

    /**
     * @inheritdoc
     */
    protected static function defineActions(string $source): array
    {
        throw new NotSupportedException('Not implemented.');
    }

    /**
     * @inheritdoc
     */
    protected static function defineSearchableAttributes(): array
    {
        throw new NotSupportedException('Not implemented.');
    }

    /**
     * @inheritdoc
     */
    public static function sortOptions(): array
    {
        throw new NotSupportedException('Not implemented.');
    }

    /**
     * @inheritdoc
     */
    protected static function defineSortOptions(): array
    {
        throw new NotSupportedException('Not implemented.');
    }


    /**
     * Returns an `<img>` tag based on this asset.
     *
     * @param ImageTransform|string|array|null $transform The transform to use when generating the html.
     * @param string[]|null $sizes The widths/x-descriptors that should be used for the `srcset` attribute
     * (see [[getSrcset()]] for example syntaxes)
     * @return Markup|null
     * @throws InvalidArgumentException
     */
    public function getImg(mixed $transform = null, ?array $sizes = null): ?Markup
    {
        throw new NotSupportedException('Not implemented.');
    }

    /**
     * Returns a `srcset` attribute value based on the given widths or x-descriptors.
     *
     * For example, if you pass `['100w', '200w']`, you will get:
     *
     * ```
     * image-url@100w.ext 100w,
     * image-url@200w.ext 200w
     * ```
     *
     * If you pass x-descriptors, it will be assumed that the image's current width is the `1x` width.
     * So if you pass `['1x', '2x']` on an image with a 100px-wide transform applied, you will get:
     *
     * ```
     * image-url@100w.ext,
     * image-url@200w.ext 2x
     * ```
     *
     * @param string[] $sizes
     * @param ImageTransform|string|array|null $transform A transform handle or configuration that should be applied to
     *     the image
     * @return string|false The `srcset` attribute value, or `false` if it can't be determined
     * @throws InvalidArgumentException
     * @since 3.5.0
     */
    public function getSrcset(array $sizes, mixed $transform = null): string|false
    {
        throw new NotSupportedException('Not implemented.');
    }

    /**
     * Returns an array of image transform URLs based on the given widths or x-descriptors.
     *
     * For example, if you pass `['100w', '200w']`, you will get:
     *
     * ```php
     * [
     *     '100w' => 'image-url@100w.ext',
     *     '200w' => 'image-url@200w.ext'
     * ]
     * ```
     *
     * If you pass x-descriptors, it will be assumed that the image’s current width is the indented 1x width.
     * So if you pass `['1x', '2x']` on an image with a 100px-wide transform applied, you will get:
     *
     * ```php
     * [
     *     '1x' => 'image-url@100w.ext',
     *     '2x' => 'image-url@200w.ext'
     * ]
     * ```
     *
     * @param string[] $sizes
     * @param ImageTransform|string|array|null $transform A transform handle or configuration that should be applied to
     *     the image
     * @return array
     * @since 3.7.16
     */
    public function getUrlsBySize(array $sizes, mixed $transform = null): array
    {
        throw new NotSupportedException('Not implemented.');
    }

    /**
     * @inheritdoc
     */
    public function getIsTitleTranslatable(): bool
    {
        throw new NotSupportedException('Not implemented.');
    }

    /**
     * @inheritdoc
     */
    public function getTitleTranslationDescription(): ?string
    {
        throw new NotSupportedException('Not implemented.');
    }

    /**
     * @inheritdoc
     */
    public function getTitleTranslationKey(): string
    {
        throw new NotSupportedException('Not implemented.');
    }

    /**
     * Returns the Alternative Text field's translation key.
     *
     * @return string
     * @since 5.0.0
     */
    public function getAltTranslationKey(): string
    {
        throw new NotSupportedException('Not implemented.');
    }

    /**
     * @inheritdoc
     */
    public function getFieldLayout(): ?FieldLayout
    {
        throw new NotSupportedException('Not implemented.');
    }

    /**
     * Returns the asset's volume folder.
     *
     * @return VolumeFolder
     * @throws InvalidConfigException if [[folderId]] is missing or invalid
     */
    public function getFolder(): VolumeFolder
    {
        throw new NotSupportedException('Not implemented.');
    }

    /**
     * Returns the asset's volume.
     *
     * @return Volume
     * @throws InvalidConfigException if [[volumeId]] is missing or invalid
     */
    public function getVolume(): Volume
    {
        throw new NotSupportedException('Not implemented.');
    }

    /**
     * Returns the user that uploaded the asset, if known.
     *
     * @return User|null
     * @since 3.4.0
     */
    public function getUploader(): ?User
    {
        throw new NotSupportedException('Not implemented.');
    }

    /**
     * Sets the asset's uploader.
     *
     * @param User|null $uploader
     * @since 3.4.0
     */
    public function setUploader(?User $uploader = null): void
    {
        throw new NotSupportedException('Not implemented.');

        return;
    }

    /**
     * Sets the transform.
     *
     * @param ImageTransform|string|array|null $transform A transform handle or configuration that should be applied to
     *     the image
     * @return Asset
     * @throws ImageTransformException if $transform is an invalid transform handle
     */
    public function setTransform(mixed $transform): Asset
    {
        throw new NotSupportedException('Not implemented.');
    }

    /**
     * Returns the element's full URL.
     *
     * @param ImageTransform|string|array|null $transform A transform handle or configuration that should be applied to
     *     the image If an array is passed, it can optionally include a `transform` key that defines a base transform
     *     which the rest of the settings should be applied to.
     * @param bool|null $immediately Whether the image should be transformed immediately
     * @return string|null
     * @throws InvalidConfigException
     */
    public function getUrl(mixed $transform = null, ?bool $immediately = null): ?string
    {
        // Create a 10x10 gray square image using Imagick
        $image = new \Imagick();
        $width = $this->getWidth();
        $height = $this->getHeight();
        $image->newImage($width, $height, new \ImagickPixel('gray'));

        // Add centered white text with width and height
        $draw = new \ImagickDraw();
        $draw->setFillColor(new \ImagickPixel('white'));
        // $draw->setFont('Arial');
        $draw->setFontSize(min($width, $height) / 5);
        $draw->setGravity(\Imagick::GRAVITY_CENTER);
        $text = "{$width}x{$height}";
        $image->annotateImage($draw, 0, 0, 0, $text);

        $image->setImageFormat('png');

        // Get the image blob and base64 encode it
        $imageBlob = $image->getImageBlob();
        $base64Image = base64_encode($imageBlob);

        // Create the data URL
        $dataUrl = 'data:image/png;base64,' . $base64Image;

        // Clean up
        $image->clear();
        $image->destroy();

        return $dataUrl;
    }


    /**
     * Returns preview thumb image HTML.
     *
     * @param int $desiredWidth
     * @param int $desiredHeight
     * @return string
     * @since 3.4.0
     */
    public function getPreviewThumbImg(int $desiredWidth, int $desiredHeight): string
    {
        throw new NotSupportedException('Not implemented.');
    }

    /**
     * Returns the filename, with or without the extension.
     *
     * @param bool $withExtension
     * @return string
     * @throws InvalidConfigException if the filename isn’t set yet
     */
    public function getFilename(bool $withExtension = true): string
    {
        throw new NotSupportedException('Not implemented.');
    }

    /**
     * Sets the filename (with extension).
     *
     * @param string $filename
     * @since 4.0.0
     */
    public function setFilename(string $filename): void
    {
        throw new NotSupportedException('Not implemented.');
    }

    /**
     * Returns the file extension.
     *
     * @return string
     */
    public function getExtension(): string
    {
        throw new NotSupportedException('Not implemented.');
    }

    /**
     * Returns the file’s MIME type, if it can be determined.
     *
     * @param ImageTransform|string|array|null $transform A transform handle or configuration that should be applied to
     *     the mime type
     * @return string|null
     * @throws ImageTransformException if $transform is an invalid transform handle
     */
    public function getMimeType(mixed $transform = null): ?string
    {
        throw new NotSupportedException('Not implemented.');
    }

    /**
     * Returns the file's format, if it can be determined.
     *
     * @param ImageTransform|string|array|null $transform A transform handle or configuration that should be applied to
     *     the image
     * @return string The asset's format
     * @throws ImageTransformException If an invalid transform handle is supplied
     */
    public function getFormat(mixed $transform = null): string
    {
        throw new NotSupportedException('Not implemented.');
    }

    /**
     * Returns the image height.
     *
     * @param ImageTransform|string|array|null $transform A transform handle or configuration that should be applied to
     *     the image
     * @return int|null
     */
    public function getHeight(mixed $transform = null): ?int
    {
        return $this->_height;
    }

    /**
     * Sets the image height.
     *
     * @param int|null $height the image height
     */
    public function setHeight(?int $height): void
    {
        $this->_height = $height;
    }

    /**
     * Returns the image width.
     *
     * @param array|string|ImageTransform|null $transform A transform handle or configuration that should be applied to
     *     the image
     * @return int|null
     */
    public function getWidth(array|string|ImageTransform $transform = null): ?int
    {
        return $this->_width;
    }

    /**
     * Sets the image width.
     *
     * @param int|null $width the image width
     */
    public function setWidth(?int $width): void
    {
        $this->_width = $width;
    }

    /**
     * Returns the formatted file size, if known.
     *
     * @param int|null $decimals the number of digits after the decimal point
     * @param bool $short whether the size should be returned in short form ("kB" instead of "kilobytes")
     * @return string|null
     * @since 3.4.0
     */
    public function getFormattedSize(?int $decimals = null, bool $short = true): ?string
    {
        throw new NotSupportedException('Not implemented.');
    }

    /**
     * Returns the formatted file size in bytes, if known.
     *
     * @param bool $short whether the size should be returned in short form ("B" instead of "bytes")
     * @return string|null
     * @since 3.4.0
     */
    public function getFormattedSizeInBytes(bool $short = true): ?string
    {
        throw new NotSupportedException('Not implemented.');
    }

    /**
     * Returns the image dimensions.
     *
     * @return string|null
     * @since 3.4.0
     */
    public function getDimensions(): ?string
    {
        throw new NotSupportedException('Not implemented.');
    }

    /**
     * Returns the asset's path in the volume.
     *
     * @param string|null $filename Filename to use. If not specified, the asset's filename will be used.
     * @return string
     */
    public function getPath(?string $filename = null): string
    {
        throw new NotSupportedException('Not implemented.');
    }

    /**
     * Return the path where the source for this Asset's transforms should be.
     *
     * @return string
     */
    public function getImageTransformSourcePath(): string
    {
        throw new NotSupportedException('Not implemented.');
    }

    /**
     * Get a temporary copy of the actual file.
     *
     * @return string
     * @throws VolumeException If unable to fetch file from volume.
     * @throws InvalidConfigException If no volume can be found.
     */
    public function getCopyOfFile(): string
    {
        throw new NotSupportedException('Not implemented.');
    }

    /**
     * Returns a stream of the actual file.
     *
     * @return resource
     * @throws InvalidConfigException if [[volumeId]] is missing or invalid
     * @throws FsException if a stream cannot be created
     */
    public function getStream()
    {
        throw new NotSupportedException('Not implemented.');
    }

    /**
     * Returns the file's contents.
     *
     * @return string
     * @throws InvalidConfigException if [[volumeId]] is missing or invalid
     * @throws AssetException if a stream could not be created
     * @since 3.0.6
     */
    public function getContents(): string
    {
        throw new NotSupportedException('Not implemented.');
    }

    /**
     * Generates a base64-encoded [data
     * URL](https://developer.mozilla.org/en-US/docs/Web/HTTP/Basics_of_HTTP/Data_URIs) for the asset.
     *
     * @return string
     * @throws InvalidConfigException if [[volumeId]] is missing or invalid
     * @throws AssetException if a stream could not be created
     * @since 3.5.13
     */
    public function getDataUrl(): string
    {
        throw new NotSupportedException('Not implemented.');
    }

    /**
     * Returns whether this asset can be edited by the image editor.
     *
     * @return bool
     */
    public function getSupportsImageEditor(): bool
    {
        throw new NotSupportedException('Not implemented.');
    }

    /**
     * Returns whether a user-defined focal point is set on the asset.
     *
     * @return bool
     */
    public function getHasFocalPoint(): bool
    {
        throw new NotSupportedException('Not implemented.');
    }

    /**
     * Returns the focal point represented as an array with `x` and `y` keys, or null if it's not an image.
     *
     * @param bool $asCss whether the value should be returned in CSS syntax ("50% 25%") instead
     * @return array|string|null
     */
    public function getFocalPoint(bool $asCss = false): array|string|null
    {
        throw new NotSupportedException('Not implemented.');
    }

    /**
     * Sets the asset's focal point.
     *
     * @param array|string|null $value
     * @throws InvalidArgumentException if $value is invalid
     */
    public function setFocalPoint(array|string|null $value): void
    {
        throw new NotSupportedException('Not implemented.');
    }


    /**
     * @inheritdoc
     * @throws InvalidConfigException
     */
    public function afterSave(bool $isNew): void
    {
        throw new NotSupportedException('Not implemented.');
    }

    /**
     * @inheritdoc
     */
    public function beforeDelete(): bool
    {
        throw new NotSupportedException('Not implemented.');
    }

    /**
     * @inheritdoc
     */
    public function afterDelete(): void
    {
        throw new NotSupportedException('Not implemented.');
    }

    /**
     * @inheritdoc
     */
    public function beforeRestore(): bool
    {
        throw new NotSupportedException('Not implemented.');
    }

    /**
     * @inheritdoc
     */
    public function getHtmlAttributes(string $context): array
    {
        throw new NotSupportedException('Not implemented.');
    }

    /**
     * Returns the filesystem the asset is stored in.
     *
     * @return FsInterface
     * @throws InvalidConfigException
     * @since 4.0.0
     * @deprecated in 4.4.0
     */
    public function getFs(): FsInterface
    {
        throw new NotSupportedException('Not implemented.');
    }
}
