<?php

namespace viget\partskit\services;

use viget\partskit\models\MockAssetBuilder;
use yii\base\Component;

/**
 * Assets Service
 */
class Assets extends Component
{
    public function make(): MockAssetBuilder
    {
        return new MockAssetBuilder();
    }
}
