<?php

class BotChallenge_Controller_Plugin_Challenge extends Zend_Controller_Plugin_Abstract
{
    /**
     * Duration of the cache of the reverse dns verifications, by ip.
     */
    const RDNS_CACHE_SECONDS = 21600;

    /**
     * A reverse dns verification longer than that pauses the next ones.
     */
    const RDNS_SLOW_SECONDS = 2;

    const RDNS_PAUSE_SECONDS = 600;

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

        $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';

        // Skip the bots verified by their network: the user agent announces a
        // bot and the ip belongs to its domain (forward-confirmed reverse dns).
        if ($this->isVerifiedByReverseDns($userAgent, $clientIp)) {
            return;
        }

        // A link preview bot (social networks, messaging apps) cannot solve the
        // challenge, and the ip of many of them is unknown (each instance of
        // Mastodon fetches its previews). So it gets a card with the metadata of
        // the page only, built without dispatching the page, so a scraper using
        // this user agent gets nothing else and does not load the server.
        if ($this->isPreviewBot($userAgent)) {
            list($status, $html) = (new BotChallenge_LinkPreview())->render($request);
            $this->getResponse()
                ->setHttpResponseCode($status)
                ->setHeader('Content-Type', 'text/html; charset=utf-8', true)
                ->setHeader('Cache-Control', 'no-store', true)
                ->setBody($html)
                ->sendResponse();
            exit;
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

        // The clients of the feeds and of the exports (param "output") cannot
        // pass a browser challenge, but a page without this format ignores the
        // param, so the check is done once the controller set its context.
        if ($this->isMachineOutput((string) $request->getParam('output'))) {
            Zend_Controller_Action_HelperBroker::addHelper(new BotChallenge_Controller_Action_Helper_OutputContext($url));
            return;
        }

        $this->getResponse()
            ->setRedirect($url, 302)
            ->sendResponse();
        exit;
    }

    /**
     * Is the user agent a bot verified by its network?
     *
     * Each line of the option maps a part of the user agent to the domain of
     * the reverse dns of its ips, for example "TelegramBot = ptr.telegram.org".
     */
    protected function isVerifiedByReverseDns(string $userAgent, string $ip): bool
    {
        if ($userAgent === '' || $ip === '') {
            return false;
        }
        foreach (array_filter(array_map('trim', explode("\n", (string) $this->option('botchallenge_exception_rdns')))) as $line) {
            $parts = array_map('trim', explode('=', $line, 2));
            if (count($parts) === 2 && $parts[0] !== '' && stripos($userAgent, $parts[0]) !== false) {
                return $this->verifyReverseDnsCached($ip, $parts[1]);
            }
        }
        return false;
    }

    /**
     * Verify the reverse dns of an ip, with a cache and a pause when it is slow.
     *
     * The user agent is declarative, so a botnet may use the one of a verified
     * bot to trigger two dns lookups on each request. The result is cached by
     * ip, positive or not, and the lookups are paused when the dns is slow: the
     * bot then gets the link preview only, that is cheap. The cache is not
     * stored in the options, that are all loaded on each request.
     */
    protected function verifyReverseDnsCached(string $ip, string $domain): bool
    {
        $cache = $this->cache();
        $key = 'rdns:' . $ip . ' ' . $domain;
        $cached = $cache->get($key);
        if ($cached !== null) {
            return (bool) $cached;
        }
        if ($cache->get('rdns:paused')) {
            return false;
        }

        $start = microtime(true);
        $isVerified = $this->reverseDns()->verify($ip, $domain);
        if (microtime(true) - $start > self::RDNS_SLOW_SECONDS) {
            $cache->set('rdns:paused', true, self::RDNS_PAUSE_SECONDS);
        }

        $cache->set($key, $isVerified, self::RDNS_CACHE_SECONDS);
        return $isVerified;
    }

    protected function isPreviewBot(string $userAgent): bool
    {
        if ($userAgent === '') {
            return false;
        }
        foreach (explode("\n", (string) $this->option('botchallenge_preview_user_agents')) as $agent) {
            $agent = trim($agent);
            if ($agent !== '' && stripos($userAgent, $agent) !== false) {
                return true;
            }
        }
        return false;
    }

    protected function option(string $name)
    {
        return get_option($name);
    }

    protected function cache(): BotChallenge_Cache
    {
        // The keys are distinct by installation, and the fallback directory is
        // private to it.
        return new BotChallenge_Cache(substr(sha1(BASE_DIR), 0, 12), FILES_DIR . '/botchallenge-cache');
    }

    protected function reverseDns(): BotChallenge_ReverseDns
    {
        return new BotChallenge_ReverseDns();
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
     * Check if the output is a response context (rss2, atom, omeka-xml…).
     *
     * The contexts are the default ones and the ones added by the plugins with
     * the filter "response_contexts".
     */
    protected function isMachineOutput(string $output): bool
    {
        return $output !== ''
            && Zend_Controller_Action_HelperBroker::hasHelper('contextSwitch')
            && Zend_Controller_Action_HelperBroker::getStaticHelper('contextSwitch')->hasContext($output);
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
