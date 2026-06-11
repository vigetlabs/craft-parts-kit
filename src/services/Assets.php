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
     * Alias of the directory holding the lazily-generated mock PNGs. Single
     * source of truth: the controller (cache path), the generator (via that
     * path), and the clear-caches registration all derive from here.
     */
    public const CACHE_DIRECTORY = '@storage/runtime/parts-kit-mocks';

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

    /**
     * The resolved (absolute) mock-image cache directory.
     */
    public function cacheDirectory(): string
    {
        return Craft::getAlias(self::CACHE_DIRECTORY);
    }

    /**
     * The on-disk cache path for a validated signed payload. The filename is the
     * SHA-1 of the payload — never raw input — so there is no path-traversal
     * surface and identical configs share one file.
     */
    public function cachePathForPayload(string $payload): string
    {
        return $this->cacheDirectory() . '/' . sha1($payload) . '.png';
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
