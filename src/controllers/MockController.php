<?php

namespace viget\partskit\controllers;

use Craft;
use craft\helpers\Json;
use craft\web\Controller;
use viget\partskit\helpers\MockImageGenerator;
use viget\partskit\Plugin;
use yii\helpers\StringHelper;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Serves the placeholder PNGs referenced by {@see \viget\partskit\models\MockAsset}
 * URLs.
 *
 * The route token is an HMAC-signed, base64url JSON payload (`{w, h, label}`).
 * Each request: enforces the Parts Kit visibility gate **first** (so an
 * unauthorized request gets a uniform 403 and cannot probe which tokens are
 * valid), then validates the signature (tamper → 404), then lazily generates the
 * PNG on a cache miss and serves it with a long immutable cache header.
 */
class MockController extends Controller
{
    protected array|int|bool $allowAnonymous = ['view'];

    /**
     * actions/parts-kit/mock/view — `{directory}/mock/<token>.png`
     *
     * @see Plugin::_registerUrlRules()
     */
    public function actionView(string $token): Response
    {
        // Gate before any token work: visibility mirrors Parts Kit, and running
        // the permission check first means a forged token never reveals whether
        // it would have validated (no oracle).
        if (Plugin::getInstance()->getSettings()->requireViewPermission) {
            $this->requirePermission('parts-kit:view');
        }

        $payload = Craft::$app->getSecurity()->validateData(StringHelper::base64UrlDecode($token));

        if ($payload === false) {
            throw new NotFoundHttpException('Mock image not found.');
        }

        $config = Json::decode($payload);
        $width = (int)$config['w'];
        $height = (int)$config['h'];
        $label = $config['label'] ?? null;

        $path = Plugin::getInstance()->getAssets()->cachePathForPayload($payload);

        if (!file_exists($path)) {
            MockImageGenerator::generate($path, $width, $height, $label);
        }

        // Generation may no-op at the file-count cap; treat a still-missing file
        // as a normal miss rather than failing the response.
        if (!file_exists($path)) {
            throw new NotFoundHttpException('Mock image not found.');
        }

        // A plain raw-content response (not sendFile/sendContentAsFile): the
        // placeholder PNGs are tiny, and a streamed/flushed download response
        // sends headers mid-request, which the functional connector can't drive.
        // Serving the bytes as response content keeps the same observable result
        // (image/png body + immutable cache) and stays testable.
        $response = $this->response;
        $response->format = Response::FORMAT_RAW;
        $response->getHeaders()
            ->set('Content-Type', 'image/png')
            ->set('Content-Disposition', 'inline; filename="mock.png"')
            ->set('Cache-Control', 'public, max-age=31536000, immutable');
        $response->content = (string)file_get_contents($path);

        return $response;
    }
}
