<?php

class BotChallenge_IndexController extends Omeka_Controller_AbstractActionController
{
    /**
     * Time to solve the challenge, so a harvested nonce is quickly useless.
     */
    const NONCE_LIFETIME = 600;

    /**
     * Margin allowed on the challenge delay.
     *
     * The nonce time and the check are in seconds, so an answer sent right on
     * time may be counted one second short.
     */
    const DELAY_TOLERANCE = 1;

    public function indexAction()
    {
        // The cookie is delivered by verify() only, after the client posted
        // back the nonce: a plain GET on this page must not return anything
        // usable, else the challenge is only a decoration and the token can be
        // harvested with a single request.
        if ($this->getRequest()->isPost()) {
            return $this->verify();
        }

        // The nonce is signed instead of stored in the session, so a bot that
        // reaches the page stores nothing in it.
        $nonce = BotChallenge_Token::createNonce(
            time(),
            bin2hex(random_bytes(16)),
            $this->userAgent(),
            $this->salt(),
            $this->boundIp()
        );

        // Validate redirect url: must start with "/" and not "//".
        $basePath = $this->getRequest()->getBaseUrl();
        $defaultRedirect = rtrim($basePath, '/') . '/';
        $redirectUrl = $this->getRequest()->getParam('redirect_url', $defaultRedirect);
        if (!is_string($redirectUrl)
            || $redirectUrl === ''
            || $redirectUrl[0] !== '/'
            || (strlen($redirectUrl) > 1 && $redirectUrl[1] === '/')
        ) {
            $redirectUrl = $defaultRedirect;
        }

        $delay = (int) get_option('botchallenge_delay') ?: 5;
        $testHeadless = (bool) get_option('botchallenge_test_headless');
        $interaction = (string) get_option('botchallenge_interaction');

        // Prevent caching.
        $this->getResponse()
            ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->setHeader('Pragma', 'no-cache');

        // Disable Omeka layout — render standalone page.
        // Use Zend_Layout directly: the Layout action helper may not
        // be registered when no layout resource is configured.
        $layout = Zend_Layout::getMvcInstance();
        if ($layout) {
            $layout->disableLayout();
        }

        $this->view->nonce = $nonce;
        $this->view->delay = $delay;
        $this->view->redirectUrl = $redirectUrl;
        $this->view->isHttps = $this->isHttps();
        $this->view->testHeadless = $testHeadless;
        $this->view->interaction = in_array($interaction, array('checkbox', 'button'), true) ? $interaction : '';
    }

    /**
     * Check the nonce posted by the client and deliver the cookie.
     *
     * The nonce is signed for the client and single use, so the cookie cannot
     * be obtained with a single request. A harvested nonce can be used once
     * from another client with the same user agent, like the session cookie
     * could be copied with the page before, and the ip is checked only when the
     * option is set.
     */
    protected function verify()
    {
        $response = $this->getResponse();
        $response
            ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->setHeader('Content-Type', 'application/json');

        $layout = Zend_Layout::getMvcInstance();
        if ($layout) {
            $layout->disableLayout();
        }
        $this->_helper->viewRenderer->setNoRender(true);

        $salt = $this->salt();
        $userAgent = $this->userAgent();
        $ip = $this->boundIp();

        $posted = (string) $this->getRequest()->getPost('nonce', '');
        $nonceTime = BotChallenge_Token::nonceTime($posted, $userAgent, $salt, $ip);
        $elapsed = time() - (int) $nonceTime;

        // Single use: consume the nonce whatever the result, so a failed or
        // replayed check requires a new challenge page. Without usable cache,
        // a nonce can be replayed until its expiration.
        $isReplayed = false;
        if ($nonceTime !== null && $elapsed <= self::NONCE_LIFETIME) {
            $cache = BotChallenge_Cache::forInstallation();
            $key = 'nonce_' . hash('sha256', $posted);
            $isReplayed = $cache->get($key) !== null;
            $cache->set($key, 1, self::NONCE_LIFETIME);
        }

        if ($nonceTime === null
            || $elapsed > self::NONCE_LIFETIME
            || $isReplayed
        ) {
            $response->setHttpResponseCode(403);
            $response->setBody(json_encode(array('status' => 'error')));
            return $response;
        }

        // The delay is checked here too: the countdown of the page is run by
        // the client, so without this check it is only a decoration and a
        // client posting the nonce immediately gets the cookie. The nonce is
        // consumed above, so a new challenge page is required to retry.
        $delay = (int) get_option('botchallenge_delay') ?: 5;
        if (self::isTooEarly($elapsed, $delay)) {
            $response->setHttpResponseCode(403);
            $response->setBody(json_encode(array('status' => 'too_early')));
            return $response;
        }

        $cookieLifetime = ((int) get_option('botchallenge_cookie_lifetime') ?: 7) * 86400;
        $timestamp = (string) time();
        $token = $timestamp . '_' . BotChallenge_Token::signature($timestamp, $userAgent, $salt, $ip);

        // The cookie is set by the server, so it can be http only: the client
        // never needs to read it.
        setcookie('omeka_bot_challenge', $token, array(
            'expires' => time() + $cookieLifetime,
            'path' => rtrim($this->getRequest()->getBaseUrl(), '/') . '/',
            'secure' => $this->isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ));
        $response->setBody(json_encode(array('status' => 'success')));
        return $response;
    }

    /**
     * Check that the challenge was not answered before its delay.
     *
     * The tolerance covers the second used for the times: an answer sent right
     * on time may be counted one second short.
     */
    public static function isTooEarly($elapsed, $delay)
    {
        return $elapsed < $delay - self::DELAY_TOLERANCE;
    }

    protected function salt(): string
    {
        return (string) get_option('botchallenge_salt');
    }

    /**
     * The ip is used for the signatures only when the option is set.
     */
    protected function boundIp(): string
    {
        return get_option('botchallenge_bind_ip')
            ? BotChallenge_ClientIp::fromServer()
            : '';
    }

    protected function userAgent()
    {
        return isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
    }

    protected function isHttps()
    {
        if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https'
        ) {
            return true;
        }
        return ($_SERVER['SERVER_PORT'] ?? null) === '443';
    }
}
