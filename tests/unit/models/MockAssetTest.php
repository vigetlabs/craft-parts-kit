<?php

namespace viget\partskit\tests\unit\models;

use Codeception\Test\Unit;
use Craft;
use craft\elements\Asset;
use UnitTester;
use viget\partskit\models\MockAsset;
use viget\partskit\models\MockAssetBuilder;
use viget\partskit\Plugin;
use viget\partskit\services\Assets;
use yii\base\NotSupportedException;
use yii\helpers\StringHelper;

/**
 * U4 — MockAsset non-URL surface: construction/init defaults, metadata
 * getters/setters, mode-aware dimension resolution, and NotSupportedException
 * for DB-/volume-touching methods. URL emission is covered in U6.
 *
 * Transforms are passed as arrays (not named handles) so dimension math runs
 * without a DB transform row — see the plan's Testing Strategy.
 */
class MockAssetTest extends Unit
{
    protected UnitTester $tester;

    public function testConstructWithConfigSetsDimensions(): void
    {
        $asset = new MockAsset(['width' => 800, 'height' => 600]);

        $this->assertSame(800, $asset->getWidth());
        $this->assertSame(600, $asset->getHeight());
    }

    public function testInitDefaults(): void
    {
        $asset = new MockAsset(['width' => 800, 'height' => 600]);

        $this->assertSame(Asset::KIND_IMAGE, $asset->kind);
        $this->assertSame(-1, $asset->id);
    }

    public function testGetWidthHeightNoTransform(): void
    {
        $asset = new MockAsset(['width' => 1600, 'height' => 900]);

        $this->assertSame(1600, $asset->getWidth());
        $this->assertSame(900, $asset->getHeight());
    }

    public function testGetWidthFitTransformAspectPreserved(): void
    {
        // Covers AC5 — base 1600x900, fit to width 400 → 400x225.
        $asset = new MockAsset(['width' => 1600, 'height' => 900]);

        $this->assertSame(400, $asset->getWidth(['width' => 400, 'mode' => 'fit']));
        $this->assertSame(225, $asset->getHeight(['width' => 400, 'mode' => 'fit']));
    }

    public function testGetWidthStretchVsFitDiffer(): void
    {
        // Mode-awareness: a 400x400 box fits 1600x900 to 400x225, but stretches
        // to the full 400x400. Both width and height must be given for the modes
        // to diverge.
        $asset = new MockAsset(['width' => 1600, 'height' => 900]);

        $fit = ['width' => 400, 'height' => 400, 'mode' => 'fit'];
        $stretch = ['width' => 400, 'height' => 400, 'mode' => 'stretch'];

        $this->assertSame(225, $asset->getHeight($fit));
        $this->assertSame(400, $asset->getHeight($stretch));
    }

    public function testDefaultFilenameDerivedFromDimensions(): void
    {
        $asset = new MockAsset(['width' => 800, 'height' => 600]);

        $this->assertSame('mock-800x600.png', $asset->getFilename());
        $this->assertSame('png', $asset->getExtension());
        $this->assertSame('image/png', $asset->getMimeType());
    }

    public function testSetFilenameDerivesExtensionAndMime(): void
    {
        $asset = new MockAsset(['width' => 800, 'height' => 600]);
        $asset->setFilename('hero.jpg');

        $this->assertSame('hero.jpg', $asset->getFilename());
        $this->assertSame('jpg', $asset->getExtension());
        $this->assertSame('image/jpeg', $asset->getMimeType());
    }

    public function testUnknownExtensionMimeTypeIsNull(): void
    {
        // An unrecognized extension reports null rather than a misleading
        // image/png fallback.
        $asset = new MockAsset(['width' => 800, 'height' => 600]);
        $asset->setFilename('data.zzzz');

        $this->assertNull($asset->getMimeType());
    }

    public function testFilenameConfigKeyIsHonored(): void
    {
        $asset = new MockAsset(['width' => 800, 'height' => 600, 'filename' => 'banner.webp']);

        $this->assertSame('banner.webp', $asset->getFilename());
        $this->assertSame('image/webp', $asset->getMimeType());
    }

    public function testFocalPointDefaultsToCenterAndHasFocalPointFalse(): void
    {
        // Mirrors Craft: an image Asset with no focal point set reports
        // hasFocalPoint=false but getFocalPoint() returns the center default,
        // never null — so object-position CSS behaves identically for mocks.
        $asset = new MockAsset(['width' => 800, 'height' => 600]);

        $this->assertFalse($asset->getHasFocalPoint());
        $this->assertSame(['x' => 0.5, 'y' => 0.5], $asset->getFocalPoint());
        $this->assertSame('50% 50%', $asset->getFocalPoint(true));
    }

    public function testFocalPointIsNullForNonImageKind(): void
    {
        // Craft parity: non-visual kinds have no focal point at all.
        $asset = new MockAsset(['width' => 800, 'height' => 600]);
        $asset->kind = 'document';

        $this->assertNull($asset->getFocalPoint());
    }

    public function testFocalPointWhenSet(): void
    {
        $asset = new MockAsset([
            'width' => 800,
            'height' => 600,
            'focalPoint' => ['x' => 0.25, 'y' => 0.75],
        ]);

        $this->assertTrue($asset->getHasFocalPoint());
        $this->assertSame(['x' => 0.25, 'y' => 0.75], $asset->getFocalPoint());
    }

    public function testUnsupportedMethodThrowsWithMethodNameAndReadme(): void
    {
        // Covers AC10 — getVolume() throws, message names the method and README.
        $asset = new MockAsset(['width' => 800, 'height' => 600]);

        $this->expectException(NotSupportedException::class);
        $this->expectExceptionMessageMatches('/getVolume.*README/s');

        $asset->getVolume();
    }

    public function testUnsupportedFieldValueThrows(): void
    {
        // Covers AC10 — field access is unsupported on a mock.
        $asset = new MockAsset(['width' => 800, 'height' => 600]);

        $this->expectException(NotSupportedException::class);
        $this->expectExceptionMessageMatches('/getFieldValue/');

        $asset->getFieldValue('caption');
    }

    // --- U6: transform-emitting URL methods ----------------------------------

    public function testGetUrlEmitsSignedUrl(): void
    {
        // Covers AC1.
        $asset = new MockAsset(['width' => 800, 'height' => 600]);

        $this->assertMatchesRegularExpression(
            '#/parts-kit/mock/[A-Za-z0-9_-]+\.png$#',
            (string)$asset->getUrl(),
        );
    }

    public function testGetUrlIsMemoized(): void
    {
        $asset = new MockAsset(['width' => 800, 'height' => 600]);

        $this->assertSame($asset->getUrl(), $asset->getUrl());
    }

    public function testGetUrlWithTransformResolvesDimensions(): void
    {
        // getUrl with a fit-400 transform on a 1600x900 mock encodes 400x225.
        $asset = new MockAsset(['width' => 1600, 'height' => 900, 'label' => 'Hero']);

        $expected = Plugin::getInstance()->getAssets()->signedUrlForImage(400, 225, 'Hero');

        $this->assertSame($expected, $asset->getUrl(['width' => 400, 'mode' => 'fit']));
    }

    public function testGetImgMarkup(): void
    {
        // Covers AC4.
        $asset = (new MockAssetBuilder())
            ->width(800)
            ->height(600)
            ->alt('Hero')
            ->one();

        $img = (string)$asset->getImg();

        $this->assertStringContainsString('<img', $img);
        $this->assertStringContainsString('width="800"', $img);
        $this->assertStringContainsString('height="600"', $img);
        $this->assertStringContainsString('alt="Hero"', $img);
        $this->assertMatchesRegularExpression('#src="[^"]*/parts-kit/mock/[A-Za-z0-9_-]+\.png"#', $img);
    }

    public function testGetImgReturnsNullForNonImageKind(): void
    {
        $asset = new MockAsset(['width' => 800, 'height' => 600]);
        $asset->kind = 'document';

        $this->assertNull($asset->getImg());
    }

    public function testGetSrcsetReturnsNUrls(): void
    {
        // Covers AC12 — N sizes emit N distinct signed URLs.
        $asset = new MockAsset(['width' => 800, 'height' => 600]);

        $srcset = $asset->getSrcset(['1x', '2x']);
        $this->assertIsString($srcset);

        $entries = explode(', ', $srcset);
        $this->assertCount(2, $entries);

        $urls = array_map(fn(string $entry) => explode(' ', $entry)[0], $entries);
        $this->assertCount(2, array_unique($urls), 'Each srcset size yields a distinct URL');
    }

    public function testGetFocalPointAsCss(): void
    {
        $asset = new MockAsset([
            'width' => 800,
            'height' => 600,
            'focalPoint' => ['x' => 0.25, 'y' => 0.75],
        ]);

        $this->assertSame('25% 75%', $asset->getFocalPoint(true));
    }

    public function testGetUrlAndImgAreNullWhenDimensionsAbsent(): void
    {
        // A MockAsset built with no dimensions can't form a URL.
        $asset = new MockAsset([]);

        $this->assertNull($asset->getUrl());
        $this->assertNull($asset->getImg());
    }

    public function testGetSrcsetWidthDescriptorsEncodeRequestedWidths(): void
    {
        // The 'w' descriptor branch sets the transform width directly; assert the
        // emitted URLs encode those widths in their signed payloads.
        $asset = new MockAsset(['width' => 800, 'height' => 600]);

        $srcset = $asset->getSrcset(['400w', '800w']);
        $this->assertIsString($srcset);

        $widths = [];
        foreach (explode(', ', $srcset) as $entry) {
            $url = explode(' ', $entry)[0];
            preg_match('#/mock/([A-Za-z0-9_-]+)\.png#', $url, $matches);
            $payload = Craft::$app->getSecurity()->validateData(
                StringHelper::base64UrlDecode($matches[1]),
            );
            $widths[] = json_decode($payload, true)['w'];
        }

        $this->assertSame([400, 800], $widths);
    }

    public function testRenderTouchesNoFilesystemAndNoImagick(): void
    {
        // Covers AC11 — emitting URLs/markup creates no files in the mocks dir.
        $dir = Craft::getAlias(Assets::CACHE_DIRECTORY);
        $before = is_dir($dir) ? count(glob($dir . '/*') ?: []) : 0;

        $asset = new MockAsset(['width' => 800, 'height' => 600, 'label' => 'Hero']);
        $asset->getUrl();
        $asset->getImg();
        $asset->getSrcset(['1x', '2x']);

        $after = is_dir($dir) ? count(glob($dir . '/*') ?: []) : 0;
        $this->assertSame($before, $after, 'Rendering must not generate any files');
    }
}
