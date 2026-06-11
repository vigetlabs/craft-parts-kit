<?php

namespace viget\partskit\tests\unit\services;

use Codeception\Test\Unit;
use Craft;
use craft\helpers\Json;
use UnitTester;
use viget\partskit\models\MockAssetBuilder;
use viget\partskit\services\Assets;
use yii\helpers\StringHelper;

/**
 * Assets service — the make() entry point plus URL routing and HMAC signing.
 *
 * Signing round-trips in-process: the harness pins a fixed SECURITY_KEY, so
 * sign → validate is deterministic and a one-byte mutation must fail validation.
 * No HTTP is needed for the crypto itself (that's exercised end-to-end in
 * MockControllerCest).
 */
class AssetsTest extends Unit
{
    protected UnitTester $tester;

    private const ROUTE_PATTERN = '#/parts-kit/mock/[A-Za-z0-9_-]+\.png$#';

    public function testMakeReturnsBuilder(): void
    {
        $this->assertInstanceOf(MockAssetBuilder::class, (new Assets())->make());
    }

    public function testMakeReturnsConfiguredBuilder(): void
    {
        $asset = (new Assets())->make(['width' => 400])->one();

        $this->assertSame(400, $asset->getWidth());
    }

    public function testMakeNoArgsReturnsEmptyBuilder(): void
    {
        $asset = (new Assets())->make()->one();

        $this->assertSame(800, $asset->getWidth());
        $this->assertSame(600, $asset->getHeight());
    }

    public function testSignedUrlMatchesRoutePattern(): void
    {
        // Covers AC1.
        $url = (new Assets())->signedUrlForImage(800, 600, 'Hero');

        $this->assertMatchesRegularExpression(self::ROUTE_PATTERN, $url);
    }

    public function testSignedTokenRoundTrips(): void
    {
        // NFR2 (positive): the validated payload decodes back to the inputs.
        $url = (new Assets())->signedUrlForImage(800, 600, 'Hero');
        $payload = $this->validatedPayload($url);

        $this->assertNotFalse($payload);

        $data = Json::decode($payload);
        $this->assertSame(800, $data['w']);
        $this->assertSame(600, $data['h']);
        $this->assertSame('Hero', $data['label']);
    }

    public function testTamperedTokenFailsValidation(): void
    {
        // NFR2 (negative): flipping one token character breaks the signature.
        $url = (new Assets())->signedUrlForImage(800, 600, 'Hero');
        $token = $this->tokenFrom($url);

        $mutated = $token;
        $mutated[5] = $token[5] === 'A' ? 'B' : 'A';

        $signed = StringHelper::base64UrlDecode($mutated);
        $this->assertFalse(Craft::$app->getSecurity()->validateData($signed));
    }

    public function testIdenticalConfigProducesIdenticalUrlAndHash(): void
    {
        // Covers AC6 — same (w, h, label) → identical URL and identical
        // on-disk hash.
        $a = (new Assets())->signedUrlForImage(800, 600, 'Hero');
        $b = (new Assets())->signedUrlForImage(800, 600, 'Hero');

        $this->assertSame($a, $b);
        $this->assertSame(
            sha1($this->validatedPayload($a)),
            sha1($this->validatedPayload($b)),
        );
    }

    public function testDifferentLabelProducesDifferentHash(): void
    {
        // Covers AC7 — changing only the label changes the token and the hash.
        $a = (new Assets())->signedUrlForImage(800, 600, 'Hero');
        $b = (new Assets())->signedUrlForImage(800, 600, 'Thumbnail');

        $this->assertNotSame($a, $b);
        $this->assertNotSame(
            sha1($this->validatedPayload($a)),
            sha1($this->validatedPayload($b)),
        );
    }

    public function testKeyedPayloadIsOrderStable(): void
    {
        // The payload encodes keys deterministically, so the hash is stable
        // across calls.
        $first = $this->validatedPayload((new Assets())->signedUrlForImage(800, 600, 'Hero'));
        $second = $this->validatedPayload((new Assets())->signedUrlForImage(800, 600, 'Hero'));

        $this->assertSame($first, $second);
    }

    private function tokenFrom(string $url): string
    {
        preg_match('#/mock/([A-Za-z0-9_-]+)\.png#', $url, $matches);

        return $matches[1];
    }

    /**
     * Decodes a mock URL's token and returns the HMAC-validated payload (or
     * false if the signature does not verify) — mirroring what the controller
     * does on each request.
     */
    private function validatedPayload(string $url): string|false
    {
        $signed = StringHelper::base64UrlDecode($this->tokenFrom($url));

        return Craft::$app->getSecurity()->validateData($signed);
    }
}
