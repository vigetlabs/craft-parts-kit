<?php

namespace viget\partskit;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterTemplateRootsEvent;
use craft\web\View;
use viget\partskit\services\Assets;
use viget\partskit\services\Navigation;
use yii\base\Event;

/**
 * Craft Parts Kit plugin
 *
 * @method static Plugin getInstance()
 * @author Viget <craft@viget.com>
 * @copyright Viget
 * @license MIT
 * @property-read Assets $assetService
 */
class Plugin extends BasePlugin
{
    public const TEMPLATE_ROOT = 'parts-kit';

    public string $schemaVersion = '1.0.0';

    public static function config(): array
    {
        return [
            'components' => ['navigation' => Navigation::class, 'assets' => Assets::class],
        ];
    }

    public function getNavigation(): Navigation
    {
        return $this->get('navigation');
    }

    public function init(): void
    {
        parent::init();

        // Defer most setup tasks until Craft is fully initialized
        Craft::$app->onInit(function () {
            $this->attachEventHandlers();
            // ...
        });
    }

    private function attachEventHandlers(): void
    {
        // Register event handlers here ...
        // (see https://craftcms.com/docs/4.x/extend/events.html to get started)
        Event::on(
            View::class,
            View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS,
            function (RegisterTemplateRootsEvent $event) {
                $event->roots[self::TEMPLATE_ROOT] = $this->getBasePath() . '/templates';
            }
        );

        // Override rendering of the root /parts-kit URL, so we can render a custom template that
        // injects the HTML / JS for our parts kit UI.
        Event::on(
            \craft\web\UrlManager::class,
            \craft\web\UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function (\craft\events\RegisterUrlRulesEvent $event) {
                $partsKitDir = 'parts-kit'; // $this->getSettings()->partsKitDirectory; TODO
                $event->rules[$partsKitDir] = 'parts-kit/view/root';
            }
        );

        // Make this plugin available as `partsKit` Twig variable
        Event::on(
            \craft\web\twig\variables\CraftVariable::class,
            \craft\web\twig\variables\CraftVariable::EVENT_INIT,
            static function (Event $e) {
                /** @var \craft\web\twig\variables\CraftVariable $variable */
                $variable = $e->sender;
                $variable->set('partsKit', self::getInstance());
            }
        );
    }
}
