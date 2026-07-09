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
            throw $this->_notFound();
        }

        $config = Json::decode($payload);
        $width = (int)($config['w'] ?? 0);
        $height = (int)($config['h'] ?? 0);
        $label = $config['label'] ?? null;

        // Defense in depth: the builder bounds dimensions at mint time, but a
        // transform or a directly-built URL could still carry an out-of-range
        // size. Reject it before it reaches Imagick's canvas allocation.
        if (
            $width < 1 || $height < 1
            || $width > MockImageGenerator::MAX_DIMENSION
            || $height > MockImageGenerator::MAX_DIMENSION
        ) {
            throw $this->_notFound();
        }

        $path = Plugin::getInstance()->getAssets()->cachePathForPayload($payload);

        if (!file_exists($path)) {
            MockImageGenerator::generate($path, $width, $height, $label);
        }

        // Generation may no-op at the file-count cap; treat a still-missing file
        // as a normal miss rather than failing the response.
        if (!file_exists($path)) {
            throw $this->_notFound();
        }

        // A plain raw-content response (not sendFile/sendContentAsFile): the
        // placeholder PNGs are tiny, and a streamed/flushed download response
        // sends headers mid-request, which the functional connector can't drive.
        // Serving the bytes as response content keeps the same observable result
        // (image/png body + immutable cache) and stays testable.
        $bytes = file_get_contents($path);

        // The file vanished between the existence check and the read (e.g. a
        // concurrent clear-caches). Treat it as a miss rather than serving an
        // empty-body 200.
        if ($bytes === false) {
            throw $this->_notFound();
        }

        $response = $this->response;
        $response->format = Response::FORMAT_RAW;
        $response->getHeaders()
            ->set('Content-Type', 'image/png')
            ->set('Content-Disposition', 'inline; filename="mock.png"')
            ->set('Cache-Control', 'public, max-age=31536000, immutable');
        $response->content = $bytes;

        return $response;
    }

    /**
     * Every failure mode — invalid signature, out-of-range dimensions, cap-
     * aborted generation, vanished file — answers with this same uniform 404 so
     * the response never leaks which check failed.
     */
    private function _notFound(): NotFoundHttpException
    {
        return new NotFoundHttpException('Mock image not found.');
    }
}
