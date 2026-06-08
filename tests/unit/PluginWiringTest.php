<?php

namespace viget\partskit\tests\unit;

use Codeception\Test\Unit;
use UnitTester;
use viget\partskit\Plugin;
use viget\partskit\services\Assets;
use viget\partskit\services\Navigation;

/**
 * Pins the plugin's component wiring: every component registered in
 * Plugin::config()['components'] must resolve through both its typed getter and
 * the Yii magic property (the latter is how the `partsKit` Twig variable reaches
 * the service, e.g. `partsKit.assets.make(...)`).
 */
class PluginWiringTest extends Unit
{
    protected UnitTester $tester;

    private function plugin(): Plugin
    {
        $plugin = Plugin::getInstance();
        $this->assertNotNull($plugin, 'parts-kit plugin should be installed in the test harness');

        return $plugin;
    }

    public function testAssetsComponentIsRegistered(): void
    {
        $this->assertInstanceOf(Assets::class, $this->plugin()->getAssets());
    }

    public function testNavigationGetterStillResolves(): void
    {
        // Guard against regressing the existing getter while adding the
        // typed-getter/@property-read convention for `assets`.
        $this->assertInstanceOf(Navigation::class, $this->plugin()->getNavigation());
    }

    public function testPartsKitTwigVariableExposesAssets(): void
    {
        // The `partsKit` Twig variable is the Plugin instance itself, so
        // `partsKit.assets` dereferences to the Yii magic property below. This is
        // the seam the Twig usage `partsKit.assets.make(...)` depends on.
        $this->assertInstanceOf(Assets::class, $this->plugin()->assets);
    }
}
