<?php

/**
 * Refresh the bundled ip ranges of the search engines from their sources.
 *
 * The lists move (Google added ranges recently), so a bundled list silently
 * ages: without a periodic refresh, an engine that changed its ranges receives
 * the challenge again and stops indexing the site.
 *
 * A source that is unreachable or that returns a truncated list is skipped: the
 * previous list is kept, since replacing it with an empty one would deindex the
 * site.
 */
class BotChallenge_Job_RefreshBotIps extends Omeka_Job_AbstractJob
{
    /**
     * Minimum number of ranges expected from a source to replace a list.
     */
    const MIN_IPS = 1;

    public function perform()
    {
        // The plugins are read-only in production, so the refreshed ranges are
        // stored in the options, not in the bundled files.
        $override = json_decode((string) get_option('botchallenge_bot_ips'), true) ?: array();

        $refreshed = 0;
        foreach (array_keys(BotChallenge_BotIps::BOTS) as $bot) {
            $url = BotChallenge_BotIps::sourceUrl($bot);
            if (!$url) {
                continue;
            }
            $ips = $this->fetchIps($url, BotChallenge_BotIps::isJsonSource($bot));
            // A source that returns much less than the current list is a
            // truncated or altered answer, not a real removal of ranges.
            $current = count(isset($override[$bot]) ? $override[$bot] : BotChallenge_BotIps::ips($bot));
            if (count($ips) < self::MIN_IPS || ($current > 4 && count($ips) < $current / 2)) {
                _log(sprintf(
                    'BotChallenge: the source of %1$s is unreachable or truncated (%2$s): the previous ranges are kept.', // @translate
                    $bot,
                    $url
                ), Zend_Log::WARN);
                continue;
            }
            // Store the ranges only when they differ from the bundled file, so
            // the override stays empty while the plugin is up to date.
            $bundled = BotChallenge_BotIps::ips($bot);
            sort($bundled);
            if ($ips === $bundled) {
                unset($override[$bot]);
            } else {
                $override[$bot] = $ips;
            }
            ++$refreshed;
            _log(sprintf(
                'BotChallenge: %1$d ranges updated for %2$s.', // @translate
                count($ips),
                $bot
            ), Zend_Log::INFO);
        }

        set_option('botchallenge_bot_ips', json_encode($override));

        // Rebuild the merged list read by the front controller plugin of each
        // request.
        $bots = json_decode((string) get_option('botchallenge_exception_bots'), true) ?: BotChallenge_BotIps::DEFAULT_BOTS;
        $exceptionIps = array_filter(array_map('trim', explode("\n", (string) get_option('botchallenge_exception_ips'))));
        set_option('botchallenge_exception_ips_all', json_encode(array_values(array_unique(array_merge(
            BotChallenge_BotIps::ipsForBots($bots, $override),
            $exceptionIps
        )))));

        _log(sprintf(
            'BotChallenge: %d source(s) refreshed.', // @translate
            $refreshed
        ), Zend_Log::NOTICE);
    }

    /**
     * @return string[]
     */
    protected function fetchIps($url, $isJson)
    {
        $content = @file_get_contents($url, false, stream_context_create(array(
            'http' => array('timeout' => 30, 'user_agent' => 'Omeka Classic BotChallenge'),
        )));
        if (!is_string($content) || $content === '') {
            return array();
        }

        if ($isJson) {
            $data = json_decode($content, true);
            $prefixes = is_array($data) && isset($data['prefixes']) ? $data['prefixes'] : array();
            $ips = array();
            foreach ($prefixes as $prefix) {
                $ip = isset($prefix['ipv4Prefix'])
                    ? $prefix['ipv4Prefix']
                    : (isset($prefix['ipv6Prefix']) ? $prefix['ipv6Prefix'] : null);
                if ($ip) {
                    $ips[] = $ip;
                }
            }
        } else {
            $ips = array_map('trim', explode("\n", strtr($content, array("\r" => "\n"))));
        }

        $ips = array_filter($ips, function ($ip) {
            return (bool) preg_match('~^[0-9a-fA-F.:]+(/\d{1,3})?$~', (string) $ip);
        });
        sort($ips);
        return array_values(array_unique($ips));
    }
}
