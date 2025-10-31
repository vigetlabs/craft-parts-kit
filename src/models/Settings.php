<?php

namespace viget\partskit\models;

use craft\base\Model;

class Settings extends Model
{
    /**
     * The directory where the parts kit tempaltes will be located. 
     * This is both the URL you access `mysite.dev/parts-kit` and the path in your project's `templates` directory.
     * 
     * @var string
     */
    public string $directory = 'parts-kit';

    public function defineRules(): array
    {
        return [
            [['directory'], 'required'],
        ];
    }
}

