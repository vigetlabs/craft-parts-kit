<?php

namespace viget\partskit\tests\functional;

use Craft;
use craft\elements\User;
use FunctionalTester;
use Twig\Error\RuntimeError;
use yii\web\ForbiddenHttpException;

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
        // Twig\Error\RuntimeError wrapping a ForbiddenHttpException. Asserting the
        // wrapped cause ensures we're verifying the permission gate specifically,
        // not just any Twig runtime error.
        try {
            $I->amOnPage('/parts-kit');
            $I->fail('Expected requirePermission to deny the anonymous request.');
        } catch (RuntimeError $e) {
            $I->assertInstanceOf(ForbiddenHttpException::class, $e->getPrevious());
        }
    }
}
