<?php

namespace viget\partskit\services;

use Craft;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use RuntimeException;
use viget\partskit\models\MockAssetBuilder;
use viget\partskit\Plugin;
use yii\base\Component;
use yii\helpers\StringHelper;

/**
 * Assets service — the entry point for building mock assets from Twig
 * (`partsKit.assets.make(...)`) and the single source of truth for mock-image
 * URL routing and signing.
 *
 * A mock image URL carries an HMAC-signed, base64url-encoded JSON payload
 * (`{w, h, label}`) as its path token. The signature makes the URL
 * tamper-proof: {@see \viget\partskit\controllers\MockController} validates it
 * before serving and rejects any mutation with a 404. Because the validated
 * config travels in the URL, the controller can generate the PNG lazily on the
 * first request — no sidecar files, no DB. The on-disk cache filename is
 * `sha1($payload)`, computed by the controller (not here).
 */
class Assets extends Component
{
    /**
     * Returns a builder for a mock asset, applying any provided config. Runs the
     * Imagick boot guard so misconfigured environments fail with a clear message
     * at the point of use rather than deep inside generation.
     *
     * @param array<string, mixed> $config
     * @throws RuntimeException if the Imagick extension is unavailable
     */
    public function make(array $config = []): MockAssetBuilder
    {
        $this->_ensureImagickAvailable();

        $builder = new MockAssetBuilder();

        if ($config !== []) {
            $builder->configure($config);
        }

        return $builder;
    }

    /**
     * Builds the tamper-proof, signed site URL that serves a mock PNG of the
     * given dimensions and label. Rendering only ever emits this string — no
     * image is generated until the URL is requested.
     */
    public function signedUrlForImage(int $width, int $height, ?string $label): string
    {
        $payload = Json::encode(['w' => $width, 'h' => $height, 'label' => $label]);
        $signed = Craft::$app->getSecurity()->hashData($payload);

        // Strip base64 padding so the token matches the `[A-Za-z0-9_-]+` route
        // pattern; base64 decoding tolerates the missing padding on the way back.
        $token = rtrim(StringHelper::base64UrlEncode($signed), '=');

        $directory = Plugin::getInstance()->getSettings()->directory;

        return UrlHelper::siteUrl($directory . '/mock/' . $token . '.png');
    }

    private function _ensureImagickAvailable(): void
    {
        if (!extension_loaded('imagick')) {
            throw new RuntimeException(
                'Parts Kit mock assets require the PHP Imagick extension (ext-imagick). '
                . 'Install it to render mock images. See README.',
            );
        }
    }
}
