<?php

namespace viget\partskit\tests\unit\models;

use Codeception\Test\Unit;
use InvalidArgumentException;
use UnitTester;
use viget\partskit\helpers\MockImageGenerator;
use viget\partskit\models\MockAsset;
use viget\partskit\models\MockAssetBuilder;

/**
 * U3 — MockAssetBuilder. Accumulates config via a fluent chain or configure()
 * array and constructs a MockAsset only in one(). Defaults are 800x600; labels
 * are trimmed and capped at 200 chars; unknown keys throw.
 */
class MockAssetBuilderTest extends Unit
{
    protected UnitTester $tester;

    public function testFluentSettersAccumulateConfig(): void
    {
        $asset = (new MockAssetBuilder())
            ->width(1024)
            ->height(768)
            ->label('Hero')
            ->alt('Hero photo')
            ->one();

        $this->assertInstanceOf(MockAsset::class, $asset);
        $this->assertSame(1024, $asset->getWidth());
        $this->assertSame(768, $asset->getHeight());
        $this->assertSame('Hero', $asset->getLabel());
        $this->assertSame('Hero photo', $asset->alt);
    }

    public function testConfigureMergesArray(): void
    {
        $asset = (new MockAssetBuilder())
            ->configure(['width' => 400, 'height' => 300])
            ->one();

        $this->assertSame(400, $asset->getWidth());
        $this->assertSame(300, $asset->getHeight());
    }

    public function testUnknownConfigKeyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/bogus/');

        (new MockAssetBuilder())->configure(['bogus' => 1]);
    }

    public function testDefaultsAppliedWhenUnset(): void
    {
        // Covers AC3 — make.one with no setters yields the 800x600 default.
        $asset = (new MockAssetBuilder())->one();

        $this->assertSame(800, $asset->getWidth());
        $this->assertSame(600, $asset->getHeight());
    }

    public function testLabelOver200CharsThrows(): void
    {
        // Covers NFR7.
        $this->expectException(InvalidArgumentException::class);

        (new MockAssetBuilder())->label(str_repeat('a', 201));
    }

    public function testLabelIsTrimmed(): void
    {
        $asset = (new MockAssetBuilder())->label('  Hero  ')->one();

        $this->assertSame('Hero', $asset->getLabel());
    }

    public function testLabelAt200CharsIsAccepted(): void
    {
        // Boundary: exactly 200 chars is allowed (cap is "more than 200").
        $label = str_repeat('a', 200);
        $asset = (new MockAssetBuilder())->label($label)->one();

        $this->assertSame($label, $asset->getLabel());
    }

    public function testZeroDimensionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new MockAssetBuilder())->width(0);
    }

    public function testNegativeDimensionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new MockAssetBuilder())->height(-10);
    }

    public function testDimensionOverMaxThrows(): void
    {
        // Guards against an enormous Imagick canvas (resource exhaustion).
        $this->expectException(InvalidArgumentException::class);

        (new MockAssetBuilder())->width(MockImageGenerator::MAX_DIMENSION + 1);
    }

    public function testMaxDimensionIsAccepted(): void
    {
        $asset = (new MockAssetBuilder())
            ->width(MockImageGenerator::MAX_DIMENSION)
            ->height(MockImageGenerator::MAX_DIMENSION)
            ->one();

        $this->assertSame(MockImageGenerator::MAX_DIMENSION, $asset->getWidth());
    }

    public function testOneReturnsFreshInstances(): void
    {
        $builder = new MockAssetBuilder();
        $first = $builder->one();
        $second = $builder->one();

        $this->assertNotSame($first, $second);
    }
}
