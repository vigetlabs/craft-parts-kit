<?php

namespace viget\partskit\tests\unit;

use Codeception\Test\Unit;
use Craft;
use UnitTester;
use yii\base\Application;

/**
 * Smoke test: proves the DB-backed Craft test harness boots before any
 * behavioral assertions are written.
 */
class HarnessBootTest extends Unit
{
    protected UnitTester $tester;

    public function testCraftApplicationBoots(): void
    {
        $this->assertInstanceOf(Application::class, Craft::$app);
    }
}
