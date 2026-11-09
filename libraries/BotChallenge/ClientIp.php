<?php

/**
 * Get the ip of the client, handling the trusted reverse proxies.
 *
 * The headers X-Forwarded-For and X-Real-IP are written by the client, so they
 * are read only when the request comes from a trusted proxy, else anybody could
 * write an excepted ip in them.
 */
class BotChallenge_ClientIp
{
    /**
     * Get the ip of the client.
     *
     * @param array|null $server Server params, default to $_SERVER.
     * @param string[]|null $trustedProxies Specific trusted proxies, else the
     *   ones of the config of the plugin.
     * @return string
     */
    public static function fromServer(?array $server = null, ?array $trustedProxies = null)
    {
        $server = $server ?? $_SERVER;
        $trustedProxies = $trustedProxies ?? self::configuredProxies();

        $remoteIp = trim((string) ($server['REMOTE_ADDR'] ?? ''));
        if (!self::isValidIp($remoteIp)) {
            return '127.0.0.1';
        }
        if (!self::isTrusted($remoteIp, $trustedProxies)
            && !self::isSameServer($remoteIp, $server)
        ) {
            return $remoteIp;
        }

        $forwarded = trim((string) ($server['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($forwarded !== '') {
            // From the right: the left part may be forged by the client.
            $clientIp = $remoteIp;
            foreach (array_reverse(array_map('trim', explode(',', $forwarded))) as $ip) {
                if (!self::isValidIp($ip)) {
                    break;
                }
                $clientIp = $ip;
                if (!self::isTrusted($ip, $trustedProxies)) {
                    break;
                }
            }
            return $clientIp;
        }

        $realIp = trim((string) ($server['HTTP_X_REAL_IP'] ?? ''));
        return self::isValidIp($realIp) ? $realIp : $remoteIp;
    }

    /**
     * Check if an address is a trusted proxy.
     *
     * Without configured list, the private and loopback addresses are trusted,
     * that is the common case of a proxy in the same host or network: a remote
     * client cannot connect with them.
     */
    public static function isTrusted($ip, array $trustedProxies)
    {
        if (!$trustedProxies) {
            return self::isValidIp($ip)
                && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }
        foreach ($trustedProxies as $proxy) {
            if (self::ipInCidr($ip, (string) $proxy)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if the request comes from the server itself, for example from a
     * reverse proxy on the same machine (Caddy or Nginx in front of Apache).
     *
     * A remote client cannot connect with the address of the server, so the
     * proxy headers of such a request are set by a local process.
     */
    public static function isSameServer($ip, array $server)
    {
        $serverIp = trim((string) ($server['SERVER_ADDR'] ?? ''));
        return self::isValidIp($ip)
            && self::isValidIp($serverIp)
            && inet_pton($ip) === inet_pton($serverIp);
    }

    /**
     * Check if an address is a single address or is within a cidr range.
     */
    public static function ipInCidr($ip, $cidr)
    {
        if (strpos($cidr, '/') === false) {
            return $ip === $cidr;
        }

        list($range, $bits) = explode('/', $cidr, 2);
        $bits = (int) $bits;

        $ipBin = @inet_pton($ip);
        $rangeBin = @inet_pton($range);

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

    /**
     * Keep the valid addresses and cidr ranges of a list, one per line.
     *
     * @param string $proxies
     * @return string[]
     */
    public static function normalizeProxies($proxies)
    {
        $result = array();
        foreach (preg_split('~[\s,]+~', (string) $proxies, -1, PREG_SPLIT_NO_EMPTY) as $proxy) {
            $parts = explode('/', $proxy, 2);
            if (!self::isValidIp($parts[0])) {
                continue;
            }
            if (isset($parts[1])) {
                $maxBits = strpos($parts[0], ':') === false ? 32 : 128;
                if (!ctype_digit($parts[1]) || (int) $parts[1] > $maxBits) {
                    continue;
                }
            }
            $result[] = $proxy;
        }
        return array_values(array_unique($result));
    }

    protected static function configuredProxies()
    {
        return class_exists('Zend_Registry') && Zend_Registry::isRegistered('bootstrap')
            ? self::normalizeProxies(get_option('botchallenge_trusted_proxies'))
            : array();
    }

    protected static function isValidIp($ip)
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
}
