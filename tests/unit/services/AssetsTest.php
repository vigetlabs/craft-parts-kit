<?php

namespace viget\partskit\tests\unit\services;

use Codeception\Test\Unit;
use UnitTester;
use viget\partskit\models\MockAssetBuilder;
use viget\partskit\services\Assets;

/**
 * Assets service. U1 only pins the make() entry point returning a builder;
 * config application, the Imagick boot check, and signedUrlForImage() arrive in
 * U5 and extend these cases rather than replacing them.
 */
class AssetsTest extends Unit
{
    protected UnitTester $tester;

    public function testMakeReturnsBuilder(): void
    {
        $this->assertInstanceOf(MockAssetBuilder::class, (new Assets())->make());
    }
}
