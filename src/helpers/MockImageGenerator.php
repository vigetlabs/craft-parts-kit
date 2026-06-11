<?php

namespace viget\partskit\helpers;

use Craft;
use craft\helpers\FileHelper;
use Imagick;
use ImagickDraw;
use ImagickPixel;
use RuntimeException;

/**
 * Generates the placeholder PNGs served by
 * {@see \viget\partskit\controllers\MockController}.
 *
 * This is the **only** image-generation path in the plugin, and it is invoked
 * only by the controller on a cache miss — never at template-render time. It is
 * a stateless static helper (its own namespace + isolation aid testability: the
 * generator is directly callable with a temp path and asserted via
 * `getimagesize()`).
 *
 * Writes are atomic (temp file + `rename()`) so a concurrent first request can
 * never observe a half-written PNG, and generation is bounded by a file-count
 * cap so a hostile template can't fill the cache directory unboundedly.
 */
class MockImageGenerator
{
    /**
     * Hard ceiling on cached PNGs in the target directory. Past this, generation
     * aborts (a missing file then reads as a normal cache miss to the caller).
     */
    public const MAX_FILES = 5000;

    /**
     * Hard ceiling per axis. A placeholder never needs to be larger, and the
     * bound keeps a stray (or hostile) dimension from allocating an enormous
     * Imagick canvas. The builder rejects out-of-range dimensions up front; the
     * controller re-checks the decoded token as defense in depth.
     */
    public const MAX_DIMENSION = 5000;

    private const BACKGROUND_COLOR = '#999999';

    private const TEXT_COLOR = '#ffffff';

    private const MIN_FONT_SIZE = 8;

    /**
     * Fraction of the canvas the label must fit within (per axis).
     */
    private const TEXT_FIT_RATIO = 0.9;

    /**
     * Generates a PNG of exactly {@param $width}×{@param $height} at the given
     * path, with a centered, scaled-to-fit label (defaulting to the dimensions).
     * The write is atomic. No-ops (with a warning) if the directory already
     * holds {@see MAX_FILES} PNGs.
     *
     * @throws RuntimeException if no usable font is found
     */
    public static function generate(string $path, int $width, int $height, ?string $label): void
    {
        $directory = dirname($path);
        FileHelper::createDirectory($directory);

        if (count(glob($directory . '/*.png') ?: []) >= self::MAX_FILES) {
            Craft::warning(
                sprintf(
                    'Parts Kit mock image cache hit the %d-file cap; skipping generation for %s.',
                    self::MAX_FILES,
                    basename($path),
                ),
                __METHOD__,
            );

            return;
        }

        $text = ($label !== null && $label !== '') ? $label : "{$width}x{$height}";
        $font = self::resolveFont();

        $image = new Imagick();
        $image->newImage($width, $height, new ImagickPixel(self::BACKGROUND_COLOR), 'png');

        $draw = new ImagickDraw();
        $draw->setFillColor(new ImagickPixel(self::TEXT_COLOR));
        $draw->setFont($font);
        $draw->setGravity(Imagick::GRAVITY_CENTER);

        $fontSize = self::fitFontSize($image, $draw, $text, $width, $height);
        $draw->setFontSize($fontSize);

        $image->annotateImage($draw, 0, 0, 0, $text);

        $image->setImageFormat('png');
        $image->setImageCompressionQuality(75);
        $image->stripImage();

        // Atomic write: a concurrent reader sees either no file or the complete
        // PNG, never a partial one. On any failure, remove the temp file so a
        // half-written artifact never lingers (it would also be invisible to the
        // *.png file-count cap).
        $tempPath = $path . '.tmp.' . bin2hex(random_bytes(8));

        try {
            $image->writeImage($tempPath);

            if (!rename($tempPath, $path)) {
                throw new RuntimeException(
                    'Failed to move the generated mock image into place: ' . basename($path),
                );
            }
        } catch (\Throwable $e) {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }

            throw $e;
        } finally {
            $draw->clear();
            $image->clear();
        }
    }

    /**
     * Steps the font size down from ~20% of the smaller edge until the label
     * fits within {@see TEXT_FIT_RATIO} of both axes, flooring at
     * {@see MIN_FONT_SIZE} so tiny canvases still get a positive point size.
     */
    private static function fitFontSize(
        Imagick $image,
        ImagickDraw $draw,
        string $text,
        int $width,
        int $height,
    ): int {
        $size = max(self::MIN_FONT_SIZE, (int)(min($width, $height) * 0.2));
        $maxWidth = $width * self::TEXT_FIT_RATIO;
        $maxHeight = $height * self::TEXT_FIT_RATIO;

        while ($size > self::MIN_FONT_SIZE) {
            $draw->setFontSize($size);
            $metrics = $image->queryFontMetrics($draw, $text);

            if ($metrics['textWidth'] <= $maxWidth && $metrics['textHeight'] <= $maxHeight) {
                break;
            }

            $size--;
        }

        return $size;
    }

    private static function resolveFont(): string
    {
        // Bundled DejaVu Sans first, then the common Linux/Docker system path,
        // then macOS Helvetica for local dev.
        $candidates = [
            dirname(__DIR__) . '/resources/fonts/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/System/Library/Fonts/Helvetica.ttc',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException(
            'Parts Kit could not find a usable font for mock image generation. '
            . 'The bundled DejaVuSans.ttf is missing and no system fallback was found. See README.',
        );
    }
}
