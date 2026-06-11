<?php

namespace viget\partskit\models;

use InvalidArgumentException;
use viget\partskit\helpers\MockImageGenerator;

/**
 * Accumulates mock-asset configuration and constructs a {@see MockAsset} in
 * one().
 *
 * Two interchangeable styles are supported (the user chose both in the
 * brainstorm):
 *
 * ```php
 * // Fluent chain
 * $asset = (new MockAssetBuilder())->width(400)->height(300)->label('Thumb')->one();
 *
 * // Array config
 * $asset = (new MockAssetBuilder())->configure(['width' => 400, 'height' => 300])->one();
 * ```
 *
 * The builder never touches a {@see MockAsset} until one(): it only holds a
 * config array, applies defaults, and constructs the element via
 * `new MockAsset($config)` (the standard Yii `new X($config)` idiom).
 */
class MockAssetBuilder
{
    /**
     * Config keys accepted by {@see configure()}. Each maps to a fluent setter
     * of the same name, so configure() and the chain share one validation path.
     *
     * @var list<string>
     */
    private const ALLOWED_KEYS = [
        'width',
        'height',
        'label',
        'alt',
        'title',
        'filename',
        'focalPoint',
    ];

    private const MAX_LABEL_LENGTH = 200;

    private const DEFAULT_WIDTH = 800;

    private const DEFAULT_HEIGHT = 600;

    /**
     * @var array<string, mixed>
     */
    private array $_config = [];

    /**
     * Merges an array of config, validating keys and routing each value through
     * its fluent setter (so the label cap and trimming apply uniformly).
     *
     * @param array<string, mixed> $config
     * @throws InvalidArgumentException if a key is not recognized
     */
    public function configure(array $config): self
    {
        foreach ($config as $key => $value) {
            if (!in_array($key, self::ALLOWED_KEYS, true)) {
                throw new InvalidArgumentException(
                    sprintf('Unknown mock asset config key "%s".', $key),
                );
            }

            $this->{$key}($value);
        }

        return $this;
    }

    public function width(int $width): self
    {
        $this->_config['width'] = $this->assertDimension($width, 'width');

        return $this;
    }

    public function height(int $height): self
    {
        $this->_config['height'] = $this->assertDimension($height, 'height');

        return $this;
    }

    /**
     * Dimensions must be a positive integer no larger than
     * {@see MockImageGenerator::MAX_DIMENSION}, so a typo (or a hostile template)
     * can't request an enormous canvas.
     *
     * @throws InvalidArgumentException when out of range
     */
    private function assertDimension(int $value, string $name): int
    {
        if ($value < 1 || $value > MockImageGenerator::MAX_DIMENSION) {
            throw new InvalidArgumentException(sprintf(
                'Mock asset %s must be between 1 and %d, got %d.',
                $name,
                MockImageGenerator::MAX_DIMENSION,
                $value,
            ));
        }

        return $value;
    }

    public function label(string $label): self
    {
        $label = trim($label);

        if (mb_strlen($label) > self::MAX_LABEL_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('Mock asset label must be %d characters or fewer.', self::MAX_LABEL_LENGTH),
            );
        }

        $this->_config['label'] = $label;

        return $this;
    }

    public function alt(string $alt): self
    {
        $this->_config['alt'] = $alt;

        return $this;
    }

    public function title(string $title): self
    {
        $this->_config['title'] = $title;

        return $this;
    }

    public function filename(string $filename): self
    {
        $this->_config['filename'] = $filename;

        return $this;
    }

    /**
     * @param array{x: float, y: float} $focalPoint
     */
    public function focalPoint(array $focalPoint): self
    {
        $this->_config['focalPoint'] = $focalPoint;

        return $this;
    }

    /**
     * Applies defaults and constructs a fresh {@see MockAsset}. Each call
     * returns a distinct instance — the builder holds no mock state between
     * calls.
     */
    public function one(): MockAsset
    {
        $config = array_merge(
            ['width' => self::DEFAULT_WIDTH, 'height' => self::DEFAULT_HEIGHT],
            $this->_config,
        );

        return new MockAsset($config);
    }
}
