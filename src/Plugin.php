<?php

namespace viget\partskit;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use viget\partskit\models\Settings;
use viget\partskit\services\Assets;
use viget\partskit\services\Navigation;
use yii\base\Event;
use yii\web\View as BaseView;

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

    public bool $hasCpSettings = false;

    public string $schemaVersion = '1.0.0';

    public static function config(): array
    {
        return [
            'components' => [
                'navigation' => Navigation::class,
                'assets' => Assets::class,
            ],
        ];
    }

    public function getNavigation(): Navigation
    {
        return $this->get('navigation');
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
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
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            $this->_registerUrlRules(...)
        );

        // Make this plugin available as `partsKit` Twig variable
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function (Event $e) {
                /** @var CraftVariable $variable */
                $variable = $e->sender;
                $variable->set('partsKit', self::getInstance());
            }
        );
    }

    /**
     * Overrides the default `/parts-kit` paths to route them 
     * through our custom controller action. 
     * 
     * This gives full control over the rendering of the parts kit UI and
     * bypasses the need for {% layout %} tags in parts kit templates.
     */
    private function _registerUrlRules(RegisterUrlRulesEvent $event): void
    {
        $partsKitDir = $this->getSettings()->directory;
        $event->rules[$partsKitDir] = 'parts-kit/view/root';
        $event->rules[$partsKitDir . '/<template:.+>'] = 'parts-kit/view/template';
    }
}
