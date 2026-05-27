<?php

namespace viget\partskit\tests\functional;

use FunctionalTester;

/**
 * Exercises the permission-gated parts-kit route registered by the plugin
 * (`{directory}` -> `parts-kit/view/root`, default directory `parts-kit`).
 *
 * Status (ticket #16, R4): marked incomplete pending CI verification.
 *
 * The view permission is NOT enforced in ViewController (which sets
 * `allowAnonymous = ['root', 'template']`) — it is enforced inside `root.twig`
 * via `{% requirePermission 'parts-kit:view' %}`, gated by `settings.requireViewPermission`
 * (default true). That makes both assertions below depend on Twig rendering through the
 * `\craft\test\Craft` functional connector.
 *
 * craft-viget-base's equivalent functional suite is entirely `markTestIncomplete`
 * ("Twig extensions don't load for some reason"), and this harness has no local database
 * to verify against. The assertions capture the intended behavior; remove the
 * `markTestIncomplete()` calls (and wire the login helper) once this is confirmed green
 * on CI. If functional Twig rendering proves as fragile as base's, R4 stays deferred.
 */
class PartsKitRouteCest
{
    public function anonymousAccessIsDenied(FunctionalTester $I): void
    {
        $I->markTestIncomplete('Pending CI verification — see class docblock (#16, R4).');

        // requireViewPermission defaults true, so root.twig's {% requirePermission %}
        // should deny an anonymous request (403, or a redirect to the login page).
        $I->amOnPage('/parts-kit');
        $I->seeResponseCodeIsClientError();
    }

    public function permittedUserCanViewPartsKit(FunctionalTester $I): void
    {
        $I->markTestIncomplete('Pending CI verification + login wiring — see class docblock (#16, R4).');

        // TODO: log in a user that holds `parts-kit:view` (or an admin), e.g. via a Craft
        // user fixture + $I->amLoggedInAs($user), then assert the root parts-kit UI renders.
        $I->amOnPage('/parts-kit');
        $I->seeResponseCodeIs(200);
    }
}
