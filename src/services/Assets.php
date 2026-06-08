<?php

namespace viget\partskit\services;

use viget\partskit\models\MockAssetBuilder;
use yii\base\Component;

/**
 * Assets service — the entry point for building mock assets from Twig
 * (`partsKit.assets.make(...)`).
 *
 * U1 establishes the service and the make() seam. The Imagick boot check,
 * config application, and signedUrlForImage() (routing + HMAC signing) land in
 * U5.
 */
class Assets extends Component
{
    /**
     * Returns a builder for a mock asset. Config is applied in U5; for now this
     * returns an empty builder regardless of the passed config.
     *
     * @param array<string, mixed> $config
     */
    public function make(array $config = []): MockAssetBuilder
    {
        return new MockAssetBuilder();
    }
}
