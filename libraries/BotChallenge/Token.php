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
}
