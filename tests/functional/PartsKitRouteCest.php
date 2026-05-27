<?php

namespace viget\partskit\tests\functional;

use Craft;
use craft\elements\User;
use FunctionalTester;
use Twig\Error\RuntimeError;

/**
 * Exercises the permission-gated parts-kit route (`{directory}` ->
 * `parts-kit/view/root`, default directory `parts-kit`).
 *
 * The gate lives in root.twig via `{% requirePermission 'parts-kit:view' %}`,
 * controlled by `settings.requireViewPermission` (default true). These tests run
 * against that production default — an admin renders the page, an anonymous user
 * is denied. CI confirmed Twig renders through the `\craft\test\Craft` functional
 * connector, unlike craft-viget-base's functional suite.
 */
class PartsKitRouteCest
{
    public function adminCanViewPartsKit(FunctionalTester $I): void
    {
        $user = new User();
        $user->admin = true;
        $user->username = 'parts-kit-tester';
        $user->email = 'parts-kit-tester@example.test';
        Craft::$app->getElements()->saveElement($user, false);

        // Admins pass requirePermission, so the gated root template renders.
        $I->amLoggedInAs($user);

        $I->amOnPage('/parts-kit');
        $I->seeResponseCodeIs(200);
        $I->seeInSource('<parts-kit');
    }

    public function anonymousIsDeniedByThePermissionGate(FunctionalTester $I): void
    {
        // With no user logged in, root.twig's {% requirePermission %} denies the
        // request. Craft surfaces this during template rendering as a
        // Twig\Error\RuntimeError wrapping ForbiddenHttpException.
        $I->expectThrowable(RuntimeError::class, function () use ($I) {
            $I->amOnPage('/parts-kit');
        });
    }
}
