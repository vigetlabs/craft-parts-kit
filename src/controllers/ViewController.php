<?php

namespace viget\partskit\controllers;

use Craft;
use craft\web\Controller;
use Illuminate\Support\Collection;
use viget\partskit\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * View controller
 * 
 * Renders the root parts kit as well as invividual part examples
 */
class ViewController extends Controller
{
    protected array|int|bool $allowAnonymous = ['root', 'template'];

    /**
     * actions/parts-kit/view/root
     */
    public function actionRoot(): Response
    {
        return $this->renderTemplate(
            Plugin::TEMPLATE_ROOT . '/root',
        );
    }

    /**
     * actions/parts-kit/view/template
     * parts-kit/<template>
     * 
     * @see Plugin::_registerUrlRules()
     */
    public function actionTemplate(string $template = 'layout'): Response
    {
        $partsKitDir = Plugin::getInstance()->getSettings()->directory;
        $templatePath = "$partsKitDir/$template";
        $filePath = Craft::$app->path->getSiteTemplatesPath() . '/' . $templatePath;
        $extensions = Craft::$app->getConfig()->getGeneral()->defaultTemplateExtensions;
        $fileExists = Collection::make($extensions)
            ->contains(fn(string $extension) => file_exists($filePath . '.' . $extension));
        
        if (!$fileExists) {
            throw new NotFoundHttpException("Template not found: $templatePath");
        }

        return $this->renderTemplate(
            Plugin::TEMPLATE_ROOT . '/root',
            variables: [
                'templatePath' => $templatePath,
            ],
        );
    }
}
