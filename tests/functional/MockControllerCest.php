<?php

namespace viget\partskit\tests\functional;

use Craft;
use craft\elements\User;
use craft\helpers\FileHelper;
use FunctionalTester;
use viget\partskit\helpers\MockImageGenerator;
use viget\partskit\services\Assets;

/**
 * HTTP-level behavior of MockController: signed-token routing, the Parts Kit
 * visibility gate, lazy cold-cache generation, and tamper rejection.
 *
 * Tokens are minted in-process via Assets::signedUrlForImage() (fixed
 * SECURITY_KEY makes them deterministic); the path segment is then requested
 * through the functional connector.
 *
 * The connector rebuilds the app per request, so the visibility gate is driven
 * through the PARTS_KIT_REQUIRE_VIEW_PERMISSION env var, which the harness's
 * config/parts-kit.php re-reads on each build. The served PNG bytes are asserted
 * against the on-disk cache file rather than the response body, since the
 * functional connector does not expose response headers/binary bodies directly.
 */
class MockControllerCest
{
    public function _before(FunctionalTester $I): void
    {
        // Each test starts from an empty cache so on-disk assertions don't
        // depend on execution order.
        $this->clearMocksDir();
    }

    public function _after(FunctionalTester $I): void
    {
        putenv('PARTS_KIT_REQUIRE_VIEW_PERMISSION');
    }

    private function requireGate(bool $required): void
    {
        putenv('PARTS_KIT_REQUIRE_VIEW_PERMISSION=' . ($required ? '1' : '0'));
    }

    private function mockPath(int $width, int $height, ?string $label): string
    {
        $url = (new Assets())->signedUrlForImage($width, $height, $label);

        return parse_url($url, PHP_URL_PATH);
    }

    private function tamper(string $path): string
    {
        return preg_replace_callback(
            '#/mock/(.)#',
            fn(array $m) => '/mock/' . ($m[1] === 'A' ? 'B' : 'A'),
            $path,
        );
    }

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $user = new User();
        $user->admin = true;
        $user->username = 'mock-tester-' . bin2hex(random_bytes(4));
        $user->email = $user->username . '@example.test';
        Craft::$app->getElements()->saveElement($user, false);

        $I->amLoggedInAs($user);
    }

    private function mocksDir(): string
    {
        return Craft::getAlias(Assets::CACHE_DIRECTORY);
    }

    private function clearMocksDir(): void
    {
        $dir = $this->mocksDir();

        if (is_dir($dir)) {
            FileHelper::clearDirectory($dir);
        }
    }

    /**
     * @return string[]
     */
    private function cachedFiles(): array
    {
        return glob($this->mocksDir() . '/*.png') ?: [];
    }

    public function validTokenReturns200PngOfExactDimensions(FunctionalTester $I): void
    {
        // Covers AC2 — 200 with image/png + immutable cache, and the served file
        // is exactly 800x600.
        $this->requireGate(false);

        $I->amOnPage($this->mockPath(800, 600, null));
        $I->seeResponseCodeIs(200);

        $headers = Craft::$app->getResponse()->getHeaders();
        $I->assertSame('image/png', $headers->get('Content-Type'));
        $I->assertSame('public, max-age=31536000, immutable', $headers->get('Cache-Control'));

        $files = $this->cachedFiles();
        $I->assertCount(1, $files);

        $info = getimagesize($files[0]);
        $I->assertNotFalse($info);
        $I->assertSame(800, $info[0]);
        $I->assertSame(600, $info[1]);
        $I->assertSame('image/png', $info['mime']);
    }

    public function coldCacheGeneratesThenServes(FunctionalTester $I): void
    {
        // Covers AC12 — first request generates the file, second hits the cache.
        $this->requireGate(false);

        $path = $this->mockPath(640, 480, 'Cold');

        $I->amOnPage($path);
        $I->seeResponseCodeIs(200);
        $I->assertNotEmpty($this->cachedFiles(), 'First request generated the PNG on disk');

        $I->amOnPage($path);
        $I->seeResponseCodeIs(200);
    }

    public function tamperedTokenReturns404(FunctionalTester $I): void
    {
        // Covers AC13 — a one-byte mutation fails signature validation.
        $this->requireGate(false);

        $I->amOnPage($this->tamper($this->mockPath(800, 600, null)));
        $I->seeResponseCodeIs(404);
    }

    public function outOfRangeDimensionsReturn404(FunctionalTester $I): void
    {
        // A validly-signed token whose dimensions exceed the canvas cap is
        // rejected before it can allocate a huge Imagick image.
        $this->requireGate(false);

        $oversize = MockImageGenerator::MAX_DIMENSION + 1;
        $I->amOnPage($this->mockPath($oversize, $oversize, null));
        $I->seeResponseCodeIs(404);
    }

    public function fileCountCapReturns404(FunctionalTester $I): void
    {
        // When the cache is at the file-count cap, generation aborts and the
        // controller serves a 404 rather than an error.
        $this->requireGate(false);

        $dir = $this->mocksDir();
        FileHelper::createDirectory($dir);
        for ($i = 0; $i < MockImageGenerator::MAX_FILES; $i++) {
            touch($dir . '/cap-stub-' . $i . '.png');
        }

        $I->amOnPage($this->mockPath(800, 600, 'Capped'));
        $I->seeResponseCodeIs(404);
    }

    public function anonymousDeniedWhenPermissionRequired(FunctionalTester $I): void
    {
        // Covers NFR3 — gated + unauthorized → 403.
        $this->requireGate(true);

        $I->amOnPage($this->mockPath(800, 600, null));
        $I->seeResponseCodeIs(403);
    }

    public function adminAllowedWhenPermissionRequired(FunctionalTester $I): void
    {
        $this->requireGate(true);
        $this->loginAsAdmin($I);

        $I->amOnPage($this->mockPath(800, 600, null));
        $I->seeResponseCodeIs(200);
    }

    public function publicWhenPermissionNotRequired(FunctionalTester $I): void
    {
        $this->requireGate(false);

        $I->amOnPage($this->mockPath(800, 600, null));
        $I->seeResponseCodeIs(200);
    }

    public function gateRunsBeforeValidation(FunctionalTester $I): void
    {
        // Covers NFR8 — anonymous + tampered token → 403 (not 404): the gate
        // runs before signature validation, so there is no token oracle.
        $this->requireGate(true);

        $I->amOnPage($this->tamper($this->mockPath(800, 600, null)));
        $I->seeResponseCodeIs(403);
    }

    public function differentLabelsServeSeparateFiles(FunctionalTester $I): void
    {
        // Covers AC7 end-to-end — two URLs differing only by label are distinct
        // and both serve. The on-disk filename is sha1(payload), so distinct
        // tokens map to distinct cache files (the hash difference itself is
        // pinned by AssetsTest::testDifferentLabelProducesDifferentHash). The
        // harness rebuilds @storage per request, so this asserts the routing
        // identity rather than accumulating both files in one directory.
        $this->requireGate(false);

        $hero = $this->mockPath(800, 600, 'Hero');
        $thumbnail = $this->mockPath(800, 600, 'Thumbnail');
        $I->assertNotSame($hero, $thumbnail, 'Different labels produce different URLs');

        $I->amOnPage($hero);
        $I->seeResponseCodeIs(200);

        $I->amOnPage($thumbnail);
        $I->seeResponseCodeIs(200);
    }
}
