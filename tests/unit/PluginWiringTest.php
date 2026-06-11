<?php

namespace viget\partskit\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craft\helpers\FileHelper;
use craft\utilities\ClearCaches;
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

    private function mocksDir(): string
    {
        return Craft::getAlias('@storage/runtime/parts-kit-mocks');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mocksCacheOption(): ?array
    {
        foreach (ClearCaches::cacheOptions() as $option) {
            if (($option['key'] ?? null) === 'parts-kit-mocks') {
                return $option;
            }
        }

        return null;
    }

    public function testCacheOptionRegistered(): void
    {
        $option = $this->mocksCacheOption();

        $this->assertNotNull($option, 'A parts-kit-mocks clear-caches option should be registered');
        $this->assertSame($this->mocksDir(), $option['action']);
    }

    public function testClearCachesEmptiesMocksDir(): void
    {
        // Covers AC9 — invoking the registered action (a directory path, cleared
        // by FileHelper::clearDirectory exactly as `clear-caches/all` does)
        // empties the mock image cache.
        $dir = $this->mocksDir();
        FileHelper::createDirectory($dir);
        file_put_contents($dir . '/seed.png', 'not-a-real-png');
        $this->assertNotEmpty(glob($dir . '/*') ?: []);

        $option = $this->mocksCacheOption();
        $this->assertNotNull($option);
        $this->assertIsString($option['action']);

        FileHelper::clearDirectory($option['action']);

        $this->assertSame([], glob($dir . '/*') ?: []);
    }
}
