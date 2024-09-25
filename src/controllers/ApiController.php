<?php

namespace viget\partskit\controllers;

use Craft;
use craft\web\Controller;
use viget\partskit\Plugin;
use yii\web\Response;

/**
 * Navigation controller
 */
class ApiController extends Controller
{
    protected array|int|bool $allowAnonymous = ['config'];

    /**
     * viget-parts-kit/api/config action
     */
    public function actionConfig(): Response
    {
        return $this->asJson([
            'schemaVersion' => '0.0.1',
            'nav' => Plugin::getInstance()->getNavigation()->getNav(),
        ]);
    }
}
