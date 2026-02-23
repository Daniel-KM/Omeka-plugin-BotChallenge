<?php

class BotChallenge_IndexController extends Omeka_Controller_AbstractActionController
{
    public function indexAction()
    {
        $salt = get_option('botchallenge_salt') ?: '';
        $timestamp = (string) microtime();
        $hmac = hash_hmac('sha256', $salt . $timestamp, $salt);
        $token = $timestamp . '_' . $hmac;

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

        // Detect HTTPS.
        $isHttps = false;
        if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
            $isHttps = true;
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https'
        ) {
            $isHttps = true;
        } elseif (($_SERVER['SERVER_PORT'] ?? null) === '443') {
            $isHttps = true;
        }

        $delay = (int) get_option('botchallenge_delay') ?: 5;
        $cookieLifetimeDays = (int) get_option('botchallenge_cookie_lifetime') ?: 90;
        $cookieLifetimeSeconds = $cookieLifetimeDays * 86400;
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

        $this->view->token = $token;
        $this->view->delay = $delay;
        $this->view->cookieLifetime = $cookieLifetimeSeconds;
        $this->view->redirectUrl = $redirectUrl;
        $this->view->isHttps = $isHttps;
        $this->view->testHeadless = $testHeadless;
    }
}
