<?php

class BotChallenge_Token
{
    /**
     * Sign a token for a client.
     *
     * The user agent is included, so a cookie shared with another client is
     * refused. The ip is included only when the option is set: it changes too
     * often (mobile network, cgnat, rotating ipv6) to be used by default with
     * a cookie living several days.
     */
    public static function signature($timestamp, $userAgent, $salt, $ip = '')
    {
        return hash_hmac('sha256', $timestamp . "\n" . $userAgent . "\n" . $ip, $salt);
    }

    /**
     * Create a nonce signed for a client: "{timestamp}.{random}.{hmac}".
     */
    public static function createNonce(int $time, string $random, string $userAgent, string $salt, string $ip = ''): string
    {
        return $time . '.' . $random . '.' . self::nonceSignature((string) $time, $random, $userAgent, $salt, $ip);
    }

    /**
     * Get the time of a nonce, or null when it is not signed for the client.
     */
    public static function nonceTime(string $nonce, string $userAgent, string $salt, string $ip = ''): ?int
    {
        $parts = explode('.', $nonce);
        if (count($parts) !== 3 || !ctype_digit($parts[0]) || $parts[1] === '') {
            return null;
        }
        [$time, $random, $hmac] = $parts;
        return hash_equals(self::nonceSignature($time, $random, $userAgent, $salt, $ip), $hmac)
            ? (int) $time
            : null;
    }

    /**
     * The prefix distinguishes the signature of the nonce from the one of the
     * cookie, that use the same salt.
     */
    protected static function nonceSignature(string $time, string $random, string $userAgent, string $salt, string $ip): string
    {
        return hash_hmac('sha256', "nonce\n" . $time . "\n" . $random . "\n" . $userAgent . "\n" . $ip, $salt);
    }
}
