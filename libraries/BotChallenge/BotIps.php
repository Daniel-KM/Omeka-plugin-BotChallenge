<?php

/**
 * Registry of the search engine bots whose ip ranges are bundled, and of the
 * sources used to refresh them.
 *
 * The plugin exempts by ip/cidr only: a user agent is declarative, so it cannot
 * be opposed to a scraper that presents itself as a browser. The only usable
 * criterion is the membership of a range published by the operator of the bot.
 *
 * Sources are first-hand when the operator publishes one (Google, Bing and
 * Apple publish a json), else the GoodBots aggregate, that republishes the
 * official lists daily.
 *
 * Deliberately not included: Common Crawl (training corpus, not a search
 * engine), the commercial seo crawlers (Ahrefs, Semrush), the cdn (exempting a
 * cdn range exempts anyone routing requests through it), the monitoring
 * services and the webhooks.
 */
class BotChallenge_BotIps
{
    const GOODBOTS_URL = 'https://raw.githubusercontent.com/AnTheMaker/GoodBots/main/iplists/{bot}.ips';

    /**
     * Label and source of each bot, by name.
     *
     * The source is "json" for a Google-style json with a key "prefixes",
     * "goodbots" for a plain list of ranges from the aggregate, and "static"
     * when the operator publishes nothing exploitable.
     */
    const BOTS = array(
        'googlebot' => array(
            'label' => 'Googlebot (Google)', // @translate
            'source' => 'json',
            'url' => 'https://developers.google.com/search/apis/ipranges/googlebot.json',
        ),
        'bingbot' => array(
            'label' => 'Bingbot (Microsoft)', // @translate
            'source' => 'json',
            'url' => 'https://www.bing.com/toolbox/bingbot.json',
        ),
        'applebot' => array(
            'label' => 'Applebot (Apple)', // @translate
            'source' => 'json',
            'url' => 'https://search.developer.apple.com/applebot.json',
        ),
        'duckduckbot' => array(
            'label' => 'DuckDuckBot (DuckDuckGo)', // @translate
            'source' => 'goodbots',
        ),
        'qwant' => array(
            // Qwant publishes no list: the prefix announced by its autonomous
            // system is used (AS199064, holder "QWANT-AS QWANT SAS"), so the
            // whole network of Qwant is exempted, not only its bot.
            'label' => 'Qwant', // @translate
            'source' => 'static',
        ),
        'mojeekbot' => array(
            'label' => 'MojeekBot (Mojeek)', // @translate
            'source' => 'goodbots',
        ),
        'marginalia' => array(
            'label' => 'Marginalia', // @translate
            'source' => 'goodbots',
        ),
        'yandex' => array(
            'label' => 'Yandex', // @translate
            'source' => 'goodbots',
        ),
    );

    /**
     * Bots exempted by default: the ones that are identifiable and that bring
     * visitors back to the site.
     */
    const DEFAULT_BOTS = array(
        'googlebot',
        'bingbot',
        'applebot',
        'duckduckbot',
        'qwant',
        'mojeekbot',
        'marginalia',
    );

    /**
     * Value options for a select or a multicheckbox.
     */
    public static function valueOptions()
    {
        return array_map(function ($bot) {
            return $bot['label'];
        }, self::BOTS);
    }

    /**
     * Source url to refresh the ranges of a bot, or null when it is static.
     */
    public static function sourceUrl($bot)
    {
        $source = isset(self::BOTS[$bot]['source']) ? self::BOTS[$bot]['source'] : null;
        if ($source === 'goodbots') {
            return strtr(self::GOODBOTS_URL, array('{bot}' => $bot));
        }
        return $source === 'json'
            ? self::BOTS[$bot]['url']
            : null;
    }

    public static function isJsonSource($bot)
    {
        return (isset(self::BOTS[$bot]['source']) ? self::BOTS[$bot]['source'] : null) === 'json';
    }

    /**
     * Ip ranges bundled for a bot.
     *
     * @return string[]
     */
    public static function ips($bot)
    {
        if (!isset(self::BOTS[$bot])) {
            return array();
        }
        $filepath = self::path($bot);
        if (!is_readable($filepath)) {
            return array();
        }
        $ips = file($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($ips === false) {
            return array();
        }
        return array_values(array_filter(array_map('trim', $ips), function ($ip) {
            return $ip !== '' && $ip[0] !== '#';
        }));
    }

    /**
     * Ip ranges of the specified bots, deduplicated.
     *
     * The plugins are read-only in production, so the refreshed ranges are
     * stored in the options, not on disk: the "override" map (bot => ranges),
     * when it holds an entry for a bot, takes precedence over the bundled file.
     *
     * @param string[] $bots
     * @param array $override
     * @return string[]
     */
    public static function ipsForBots(array $bots, array $override = array())
    {
        $ips = array();
        foreach ($bots as $bot) {
            $ips = array_merge($ips, isset($override[$bot]) ? $override[$bot] : self::ips($bot));
        }
        return array_values(array_unique($ips));
    }

    public static function path($bot)
    {
        return dirname(dirname(__DIR__)) . '/data/bot-ips/' . $bot . '.txt';
    }
}
