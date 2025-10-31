<?php

namespace viget\partskit\models;

use craft\base\Model;

class Settings extends Model
{
    /**
     * The directory where the parts kit templates will be located. 
     * This is both the URL you access `mysite.dev/parts-kit` and the path in your project's `templates` directory.
     */
    public string $directory = 'parts-kit';

    /**
     * Path to a Twig template in your project that loads scripts & styles used by your part's markup. 
     */
    public ?string $headTemplatePath = null;

    public function defineRules(): array
    {
        return [
            [['directory'], 'required'],
        ];
    }
}

