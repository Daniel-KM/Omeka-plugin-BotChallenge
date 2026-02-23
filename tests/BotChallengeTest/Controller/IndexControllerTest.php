<?php

/**
 * @covers BotChallenge_IndexController
 *
 * Requires the full Omeka Classic application with a test database.
 */
class BotChallengeTest_Controller_IndexControllerTest extends Omeka_Test_AppTestCase
{
    protected $_isAdminTest = false;

    /**
     * Override appBootstrap() to register the plugin controller directory,
     * route, and options AFTER the application is fully bootstrapped.
     *
     * setUpBootstrap() runs before $this->application->bootstrap(), so
     * routes added there are overwritten and DB is not available yet.
     */
    public function appBootstrap()
    {
        parent::appBootstrap();

        // Prevent SSL redirect during tests.
        $this->frontController->unregisterPlugin('Omeka_Controller_Plugin_Ssl');

        // Register the plugin controller directory.
        $pluginDir = dirname(__DIR__, 3);
        $this->frontController->addControllerDirectory(
            $pluginDir . '/controllers',
            'bot-challenge'
        );

        // Register the route.
        $this->frontController->getRouter()->addRoute(
            'bot-challenge',
            new Zend_Controller_Router_Route(
                'bot-challenge',
                array(
                    'module' => 'bot-challenge',
                    'controller' => 'index',
                    'action' => 'index',
                )
            )
        );

        // Register the plugin view script path so the ViewRenderer
        // can resolve index/index.php for module "bot-challenge".
        $view = Zend_Registry::get('view');
        $view->addScriptPath($pluginDir . '/views/public');

        // Set default options the plugin would normally install.
        set_option('botchallenge_salt', bin2hex(random_bytes(32)));
        set_option('botchallenge_delay', 5);
        set_option('botchallenge_cookie_lifetime', 90);
        set_option('botchallenge_test_headless', 1);
        set_option('botchallenge_exception_paths', '/api');
        set_option('botchallenge_exception_ips', '');
    }

    public function testRouteIsAccessible(): void
    {
        $this->dispatch('/bot-challenge');
        $this->assertModule('bot-challenge');
        $this->assertController('index');
        $this->assertAction('index');
    }

    public function testTerminalViewHasNoOmekaLayout(): void
    {
        $this->dispatch('/bot-challenge');
        $body = $this->response->outputBody();
        // Terminal view: standalone HTML, no Omeka layout.
        $this->assertStringContainsString('<!DOCTYPE html>', $body);
        $this->assertStringNotContainsString('id="admin-bar"', $body);
    }

    public function testContainsJsChallenge(): void
    {
        $this->dispatch('/bot-challenge');
        $body = $this->response->outputBody();
        $this->assertStringContainsString('omeka_bot_challenge', $body);
        $this->assertStringContainsString('setInterval', $body);
        $this->assertStringContainsString('document.cookie', $body);
    }

    public function testDefaultRedirectUrl(): void
    {
        $this->dispatch('/bot-challenge');
        $body = $this->response->outputBody();
        $this->assertRegExp(
            '/var redirectUrl = "\\\\?\/"/',
            $body,
            'Default redirect URL should be "/"'
        );
    }

    public function testValidRedirectUrl(): void
    {
        $this->dispatch('/bot-challenge?redirect_url=/items/1');
        $body = $this->response->outputBody();
        $this->assertRegExp(
            '/var redirectUrl = ".*items.*1"/',
            $body
        );
    }

    public function testOpenRedirectBlockedDoubleSlash(): void
    {
        $this->dispatch('/bot-challenge?redirect_url=//evil.com');
        $body = $this->response->outputBody();
        $this->assertNotRegExp(
            '/var redirectUrl = ".*evil\.com.*"/',
            $body,
            'Open redirect with // should be blocked'
        );
    }

    public function testOpenRedirectBlockedProtocol(): void
    {
        $this->dispatch('/bot-challenge?redirect_url=http://evil.com');
        $body = $this->response->outputBody();
        $this->assertNotRegExp(
            '/var redirectUrl = ".*evil\.com.*"/',
            $body,
            'Open redirect with http:// should be blocked'
        );
    }

    public function testTokenPresentInBody(): void
    {
        $this->dispatch('/bot-challenge');
        $body = $this->response->outputBody();
        // Token is "microtime_hmac": e.g. "0.12345678 1234567890_abc...def".
        $this->assertRegExp('/var token = "[\d.]+ \d+_[0-9a-f]{64}"/', $body);
    }
}
