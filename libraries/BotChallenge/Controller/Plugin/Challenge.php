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
        foreach ($exceptionPaths as $exceptionPath) {
            // Compare on a segment boundary with final "/".
            $exceptionPath = rtrim((string) $exceptionPath, '/');
            if ($exceptionPath === '') {
                continue;
            }
            if ($pathOnly === $exceptionPath
                || strpos($pathOnly, $exceptionPath . '/') === 0
            ) {
                return;
            }
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
            $expectedHmac = BotChallenge_IndexController::signature($timestamp, $userAgent, $salt, $ip);
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
            . BotChallenge_IndexController::signature($timestamp, $userAgent, $salt, $ip);

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
     * Get client IP, handling proxies.
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
        if (strpos($cidr, '/') === false) {
            return $ip === $cidr;
        }

        [$range, $bits] = explode('/', $cidr, 2);
        $bits = (int) $bits;

        $ipBin = inet_pton($ip);
        $rangeBin = inet_pton($range);

        if ($ipBin === false || $rangeBin === false) {
            return false;
        }
        if (strlen($ipBin) !== strlen($rangeBin)) {
            return false;
        }

        $totalBits = strlen($ipBin) * 8;
        if ($bits < 0 || $bits > $totalBits) {
            return false;
        }

        $mask = str_repeat("\xff", (int) ($bits / 8));
        if ($bits % 8) {
            $mask .= chr(0xff << (8 - ($bits % 8)));
        }
        $mask = str_pad($mask, strlen($ipBin), "\x00");

        return ($ipBin & $mask) === ($rangeBin & $mask);
    }
}
