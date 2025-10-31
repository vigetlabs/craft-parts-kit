<?php

namespace viget\partskit\controllers;

use craft\web\Controller;
use viget\partskit\Plugin;
use yii\web\Response;

/**
 * Navigation controller
 */
class ViewController extends Controller
{
    protected array|int|bool $allowAnonymous = ['root'];

    /**
     * parts-kit/view/root action
     */
    public function actionRoot(): Response
    {
        return $this->renderTemplate(
            Plugin::TEMPLATE_ROOT . '/root',
        );
    }
}
