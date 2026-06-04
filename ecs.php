<?php

declare(strict_types=1);

use craft\ecs\SetList;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return static function(ECSConfig $ecsConfig): void {
    $ecsConfig->paths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __FILE__,
    ]);

    // Codeception-generated code: the gitignored actions trait, the committed
    // actor stubs that `codecept build` regenerates, and the compiled
    // storage artifacts. None are hand-written, so keep ECS off them.
    $ecsConfig->skip([
        __DIR__ . '/tests/_support/_generated',
        __DIR__ . '/tests/_support/UnitTester.php',
        __DIR__ . '/tests/_support/FunctionalTester.php',
        __DIR__ . '/tests/_craft/storage',
    ]);

    $ecsConfig->sets([
        SetList::CRAFT_CMS_4,
    ]);
};
