<?php

class BotChallenge_Controller_Plugin_Challenge extends Zend_Controller_Plugin_Abstract
{
    public function routeShutdown(Zend_Controller_Request_Abstract $request)
    {
        // Skip CLI (background jobs, scripts).
        if (php_sapi_name() === 'cli') {
            return;
        }

        $module = $request->getModuleName();

        // Skip the challenge page itself.
        if ($module === 'bot-challenge') {
            return;
        }

        // Skip login/logout routes.
        $controller = $request->getControllerName();
        if ($controller === 'users') {
            return;
        }

        // Skip admin routes (behind authentication).
        // The ADMIN constant is defined by admin/index.php, which is more
        // reliable than path detection because $request->getBaseUrl()
        // returns "/admin" in admin context, making path stripping unreliable.
        if (defined('ADMIN') && ADMIN) {
            return;
        }

        // Skip authenticated users.
        if (current_user()) {
            return;
        }

        $requestUri = $request->getRequestUri();
        $basePath = $request->getBaseUrl();
        $path = $requestUri;
        if ($basePath !== '' && strpos($path, $basePath) === 0) {
            $path = substr($path, strlen($basePath));
        }
        // Strip query string for path checks.
        $pathOnly = strtok($path, '?');

        // Skip exception paths.
        $exceptionPaths = get_option('botchallenge_exception_paths') ?: '';
        $exceptionPaths = array_filter(array_map('trim', explode("\n", $exceptionPaths)));
        if ($this->pathIsExcepted($pathOnly, $exceptionPaths)) {
            return;
        }

        // Skip exception IPs.
        $clientIp = $this->getClientIp();
        // The merged list is prepared when the config is saved; fall back to
        // the manual ranges when the config was never saved.
        $exceptionIps = json_decode((string) get_option('botchallenge_exception_ips_all'), true) ?: null;
        if ($exceptionIps === null) {
            $exceptionIps = array_filter(array_map('trim', explode("\n", (string) get_option('botchallenge_exception_ips'))));
        }
        foreach ($exceptionIps as $exceptionIp) {
            if ($exceptionIp !== '' && $this->ipInCidr($clientIp, $exceptionIp)) {
                return;
            }
        }

        // Check cookie: format is "{timestamp}_{hmac}".
        $cookieValue = isset($_COOKIE['omeka_bot_challenge'])
            ? $_COOKIE['omeka_bot_challenge']
            : '';
        $pos = strrpos($cookieValue, '_');
        if ($pos !== false) {
            $salt = (string) get_option('botchallenge_salt');
            $cookieLifetime = ((int) get_option('botchallenge_cookie_lifetime') ?: 7) * 86400;
            $timestamp = substr($cookieValue, 0, $pos);
            $hmac = substr($cookieValue, $pos + 1);
            $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
            $ip = get_option('botchallenge_bind_ip')
                ? $clientIp
                : '';
            $expectedHmac = BotChallenge_Token::signature($timestamp, $userAgent, $salt, $ip);
            $age = time() - (int) $timestamp;
            if (hash_equals($expectedHmac, $hmac)
                && $age >= 0
                && $age <= $cookieLifetime
            ) {
                // Sliding window: the lifetime is sealed in the cookie, so a
                // regular visitor would be challenged again on a fixed date
                // whatever its activity. Renew it once it is half spent, so
                // only an inactive visitor is challenged again.
                if ($age > $cookieLifetime / 2) {
                    $this->renewCookie($basePath, $salt, $userAgent, $cookieLifetime, $ip);
                }
                return;
            }
        }

        // Redirect to challenge page.
        $url = $basePath . '/bot-challenge?redirect_url=' . urlencode($requestUri);
        $this->getResponse()
            ->setRedirect($url, 302)
            ->sendResponse();
        exit;
    }

    /**
     * Renew the cookie of a visitor that is still active.
     */
    protected function renewCookie($basePath, $salt, $userAgent, $cookieLifetime, $ip = '')
    {
        $timestamp = (string) time();
        $token = $timestamp . '_'
            . BotChallenge_Token::signature($timestamp, $userAgent, $salt, $ip);

        $isHttps = false;
        if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
            $isHttps = true;
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $isHttps = strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
        } elseif (($_SERVER['SERVER_PORT'] ?? null) === '443') {
            $isHttps = true;
        }

        setcookie('omeka_bot_challenge', $token, array(
            'expires' => time() + $cookieLifetime,
            'path' => rtrim((string) $basePath, '/') . '/',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ));
    }

    /**
     * Get client IP, handling the trusted proxies.
     */
    protected function getClientIp(): string
    {
        return BotChallenge_ClientIp::fromServer();
    }

    /**
     * Check if an IP address is within a CIDR range.
     */
    protected function ipInCidr(string $ip, string $cidr): bool
    {
        return BotChallenge_ClientIp::ipInCidr($ip, $cidr);
    }

    /**
     * Whether the path matches an exception path, on a segment boundary.
     *
     * A prefix pattern ("/sso") matches itself and any sub-path ("/sso",
     * "/sso/metadata"), but not a path that merely shares its prefix
     * ("/ssoxxx"), so an exempted prefix cannot be widened by accident.
     *
     * A suffix pattern (a star then "/suggest") matches any path whose last
     * segment is the given one ("/search/suggest"), for the machine endpoints
     * that have no fixed prefix.
     *
     * @param string[] $exceptionPaths
     */
    protected function pathIsExcepted(string $path, array $exceptionPaths): bool
    {
        foreach ($exceptionPaths as $exceptionPath) {
            $exceptionPath = (string) $exceptionPath;
            if ($exceptionPath !== '' && $exceptionPath[0] === '*') {
                $segment = rtrim(substr($exceptionPath, 1), '/');
                if ($segment === '') {
                    continue;
                }
                if (substr($path, -strlen($segment)) === $segment
                    || strpos($path, $segment . '/') !== false
                ) {
                    return true;
                }
                continue;
            }
            $exceptionPath = rtrim($exceptionPath, '/');
            if ($exceptionPath === '') {
                continue;
            }
            if ($path === $exceptionPath
                || strpos($path, $exceptionPath . '/') === 0
            ) {
                return true;
            }
        }
        return false;
    }
}
