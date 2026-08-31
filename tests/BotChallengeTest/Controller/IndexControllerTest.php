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
        set_option('botchallenge_cookie_lifetime', 7);
        set_option('botchallenge_test_headless', 1);
        set_option('botchallenge_bind_ip', 0);
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
        $this->assertStringContainsString('setInterval', $body);
        // The client posts the nonce back and the server sets the cookie, so
        // the page never writes the cookie by itself.
        $this->assertStringContainsString('fetch(', $body);
        $this->assertStringNotContainsString('document.cookie', $body);
    }

    public function testDefaultRedirectUrl(): void
    {
        $this->dispatch('/bot-challenge');
        $body = $this->response->outputBody();
        $this->assertMatchesRegularExpression(
            '/var redirectUrl = "\\\\?\/"/',
            $body,
            'Default redirect URL should be "/"'
        );
    }

    public function testValidRedirectUrl(): void
    {
        $this->dispatch('/bot-challenge?redirect_url=/items/1');
        $body = $this->response->outputBody();
        $this->assertMatchesRegularExpression(
            '/var redirectUrl = ".*items.*1"/',
            $body
        );
    }

    public function testOpenRedirectBlockedDoubleSlash(): void
    {
        $this->dispatch('/bot-challenge?redirect_url=//evil.com');
        $body = $this->response->outputBody();
        $this->assertDoesNotMatchRegularExpression(
            '/var redirectUrl = ".*evil\.com.*"/',
            $body,
            'Open redirect with // should be blocked'
        );
    }

    public function testOpenRedirectBlockedProtocol(): void
    {
        $this->dispatch('/bot-challenge?redirect_url=http://evil.com');
        $body = $this->response->outputBody();
        $this->assertDoesNotMatchRegularExpression(
            '/var redirectUrl = ".*evil\.com.*"/',
            $body,
            'Open redirect with http:// should be blocked'
        );
    }

    public function testNoncePresentInBody(): void
    {
        $this->dispatch('/bot-challenge');
        $body = $this->response->outputBody();
        $this->assertMatchesRegularExpression('/var nonce = "[0-9a-f]{32}"/', $body);
    }

    /**
     * The page used to print a valid token, so the cookie could be harvested
     * with a single request and shared with any other client.
     */
    public function testGetDeliversNoUsableToken(): void
    {
        $this->dispatch('/bot-challenge');
        $body = $this->response->outputBody();

        // No signed token: a token is "{timestamp}_{64 hex}".
        $this->assertDoesNotMatchRegularExpression('/\d{10}_[0-9a-f]{64}/', $body);
        $this->assertStringNotContainsString('omeka_bot_challenge', $body);
    }

    public function testPostWithoutNonceIsRefused(): void
    {
        $this->request->setMethod('POST')->setPost(array('nonce' => 'not-a-valid-nonce'));
        $this->dispatch('/bot-challenge');
        $this->assertEquals(403, $this->response->getHttpResponseCode());
    }

    /**
     * The countdown is run by the client, so the delay is checked by the
     * server too: else a client posting the nonce immediately gets the cookie.
     *
     * @dataProvider provideDelays
     */
    public function testDelayIsCheckedByTheServer(int $elapsed, int $delay, bool $tooEarly): void
    {
        $this->assertSame($tooEarly, BotChallenge_IndexController::isTooEarly($elapsed, $delay));
    }

    public function provideDelays(): array
    {
        return array(
            'immediate answer of a bot' => array(0, 5, true),
            'answer far too early' => array(1, 5, true),
            'answer just too early' => array(3, 5, true),
            'one second short, tolerated' => array(4, 5, false),
            'answer on time' => array(5, 5, false),
            'answer after the delay' => array(30, 5, false),
            'no delay configured' => array(0, 0, false),
            'delay of one second' => array(0, 1, false),
        );
    }
}
