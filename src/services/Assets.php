<?php

namespace viget\partskit\services;

use Craft;
use viget\partskit\models\MockAssetBuilder;
use yii\base\Component;
use viget\partskit\models\MockAsset;

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
