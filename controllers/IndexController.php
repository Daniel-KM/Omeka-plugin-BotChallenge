<?php

class BotChallenge_IndexController extends Omeka_Controller_AbstractActionController
{
    /**
     * Time to solve the challenge, so a harvested nonce is quickly useless.
     */
    const NONCE_LIFETIME = 600;

    public function indexAction()
    {
        // The cookie is delivered by verify() only, after the client posted
        // back the nonce: a plain GET on this page must not return anything
        // usable, else the challenge is only a decoration and the token can be
        // harvested with a single request.
        if ($this->getRequest()->isPost()) {
            return $this->verify();
        }

        $session = new Zend_Session_Namespace('BotChallenge');
        $nonce = bin2hex(random_bytes(16));
        $session->nonce = $nonce;
        $session->nonceTime = time();

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
    }

    /**
     * Check the nonce posted by the client and deliver the cookie.
     *
     * The nonce is single use and tied to the session, so the cookie cannot be
     * obtained with a single request, and a harvested page is useless without
     * its session.
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

        $session = new Zend_Session_Namespace('BotChallenge');
        $expected = (string) (isset($session->nonce) ? $session->nonce : '');
        $nonceTime = (int) (isset($session->nonceTime) ? $session->nonceTime : 0);

        // Single use: consume the nonce whatever the result, so a failed or
        // replayed check requires a new challenge page.
        unset($session->nonce, $session->nonceTime);

        $posted = (string) $this->getRequest()->getPost('nonce', '');
        if ($expected === ''
            || !hash_equals($expected, $posted)
            || time() - $nonceTime > self::NONCE_LIFETIME
        ) {
            $response->setHttpResponseCode(403);
            $response->setBody(json_encode(array('status' => 'error')));
            return $response;
        }

        $salt = (string) get_option('botchallenge_salt');
        $cookieLifetime = ((int) get_option('botchallenge_cookie_lifetime') ?: 7) * 86400;
        $timestamp = (string) time();
        $token = $timestamp . '_' . self::signature($timestamp, $this->userAgent(), $salt);

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
     * Sign a token for a user agent.
     *
     * The user agent is included, so a cookie shared with another client is
     * refused. The ip is not included: it changes too often (mobile network,
     * cgnat, rotating ipv6) to be usable with a long lived cookie.
     *
     * @see BotChallenge_Controller_Plugin_Challenge::signature()
     */
    public static function signature($timestamp, $userAgent, $salt)
    {
        return hash_hmac('sha256', $timestamp . "\n" . $userAgent, $salt);
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
