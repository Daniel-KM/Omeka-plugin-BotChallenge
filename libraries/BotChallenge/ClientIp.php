<?php

/**
 * Get the ip of the client, handling the common proxy headers.
 */
class BotChallenge_ClientIp
{
    /**
     * The headers are declarative, so they are trusted only as much as the
     * proxy in front of Omeka is: without a proxy, they should be stripped by
     * the server.
     */
    public static function fromServer()
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
        return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '127.0.0.1';
    }
}
