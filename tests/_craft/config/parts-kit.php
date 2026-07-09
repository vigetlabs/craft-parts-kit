<?php

/**
 * Test-harness override for the Parts Kit plugin settings.
 *
 * The Craft test connector rebuilds the application on every functional request,
 * so a test cannot toggle the visibility gate by mutating the in-memory settings
 * model — the next request gets a fresh plugin with default settings. This file
 * is re-evaluated on each app build, so functional tests drive the gate through
 * the `PARTS_KIT_REQUIRE_VIEW_PERMISSION` env var instead.
 *
 * Default mirrors the production default (gate on). Set the env var to `0` to
 * open the route to anonymous requests.
 */
return [
    'requireViewPermission' => getenv('PARTS_KIT_REQUIRE_VIEW_PERMISSION') !== '0',
];
