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
            if ($exceptionPath !== '' && strpos($pathOnly, $exceptionPath) === 0) {
                return;
            }
        }

        // Skip exception IPs.
        $clientIp = $this->getClientIp();
        $exceptionIps = get_option('botchallenge_exception_ips') ?: '';
        $exceptionIps = array_filter(array_map('trim', explode("\n", $exceptionIps)));
        foreach ($exceptionIps as $exceptionIp) {
            if ($exceptionIp !== '' && $this->ipInCidr($clientIp, $exceptionIp)) {
                return;
            }
        }

        // Check cookie: format is "{microtime}_{hmac}".
        $cookieValue = isset($_COOKIE['omeka_bot_challenge'])
            ? $_COOKIE['omeka_bot_challenge']
            : '';
        $pos = strrpos($cookieValue, '_');
        if ($pos !== false) {
            $salt = get_option('botchallenge_salt') ?: '';
            $cookieLifetime = (int) get_option('botchallenge_cookie_lifetime') * 86400;
            $timestamp = substr($cookieValue, 0, $pos);
            $hmac = substr($cookieValue, $pos + 1);
            $expectedHmac = hash_hmac('sha256', $salt . $timestamp, $salt);
            // microtime() format: "0.12345678 1234567890".
            $seconds = (int) substr($timestamp, strpos($timestamp, ' ') + 1);
            if (hash_equals($expectedHmac, $hmac)
                && time() - $seconds <= $cookieLifetime
            ) {
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
     * Get client IP, handling proxies.
     */
    protected function getClientIp(): string
    {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
            if ($ip !== '') {
                return $ip;
            }
        }
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = trim($_SERVER['HTTP_X_REAL_IP']);
            if ($ip !== '') {
                return $ip;
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
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
