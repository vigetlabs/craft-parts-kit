<?php

namespace viget\partskit\tests\unit\models;

use Codeception\Test\Unit;
use craft\elements\Asset;
use UnitTester;
use viget\partskit\models\MockAsset;
use yii\base\NotSupportedException;

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

    public function testFilenameConfigKeyIsHonored(): void
    {
        $asset = new MockAsset(['width' => 800, 'height' => 600, 'filename' => 'banner.webp']);

        $this->assertSame('banner.webp', $asset->getFilename());
        $this->assertSame('image/webp', $asset->getMimeType());
    }

    public function testFocalPointDefaultsToNullAndHasFocalPointFalse(): void
    {
        $asset = new MockAsset(['width' => 800, 'height' => 600]);

        $this->assertFalse($asset->getHasFocalPoint());
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
}
