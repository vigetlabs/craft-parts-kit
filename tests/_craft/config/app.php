<?php

// The Parts Kit plugin is installed into the test instance via the `plugins`
// config in codeception.yml. Because this package's type is `craft-plugin`, it is
// auto-registered in vendor/craftcms/plugins.php on `composer install`, so the test
// Craft instance can resolve and install it by handle.
//
// Note: app.php registers Yii *modules*, which is a distinct mechanism from plugin
// discovery — do not list the plugin here.
return [];
