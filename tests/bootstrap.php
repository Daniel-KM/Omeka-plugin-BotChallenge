<?php
/**
 * Bootstrap for BotChallenge plugin tests.
 *
 * Unit tests (IpInCidr, Token, GetClientIp) need only the Challenge class.
 * Controller tests require the full Omeka Classic application.
 *
 * To run controller tests, copy application/tests/config.ini.changeme to
 * application/tests/config.ini and configure a test database.
 */

// Add Zend Framework 1 to include path (bundled with Omeka Classic)
// and register its autoloader so that Zend_* classes resolve.
$omekaRoot = dirname(__DIR__, 3);
set_include_path(
    $omekaRoot . '/application/libraries' . PATH_SEPARATOR . get_include_path()
);
require_once 'Zend/Loader/Autoloader.php';
Zend_Loader_Autoloader::getInstance();

// Autoload the plugin library class (needed for unit tests).
require_once dirname(__DIR__) . '/libraries/BotChallenge/Controller/Plugin/Challenge.php';

// Bootstrap the full Omeka application when a test database is configured.
$omekaTestBootstrap = $omekaRoot . '/application/tests/bootstrap.php';
if (file_exists($omekaTestBootstrap)) {
    $configFile = $omekaRoot . '/application/tests/config.ini';
    if (file_exists($configFile)) {
        require_once $omekaTestBootstrap;
    }
}
