<?php

require_once dirname(dirname(__DIR__)) . '/libraries/BotChallenge/BotIps.php';

/**
 * Unit tests of the registry of the search engines and of their bundled ranges.
 *
 * @covers BotChallenge_BotIps
 */
class BotChallengeTest_BotIpsTest extends PHPUnit\Framework\TestCase
{
    public function testEachBotHasALabelAndABundledList()
    {
        foreach (BotChallenge_BotIps::BOTS as $bot => $data) {
            $this->assertNotEmpty($data['label'], "No label for $bot.");
            $this->assertContains($data['source'], array('json', 'goodbots', 'static'), "Unknown source for $bot.");
            $this->assertFileExists(BotChallenge_BotIps::path($bot), "No bundled ranges for $bot.");
            $this->assertNotEmpty(BotChallenge_BotIps::ips($bot), "Empty bundled ranges for $bot.");
        }
    }

    public function testDefaultBotsAreKnown()
    {
        foreach (BotChallenge_BotIps::DEFAULT_BOTS as $bot) {
            $this->assertArrayHasKey($bot, BotChallenge_BotIps::BOTS);
        }
    }

    public function testBundledRangesAreValidIpsOrCidr()
    {
        foreach (array_keys(BotChallenge_BotIps::BOTS) as $bot) {
            foreach (BotChallenge_BotIps::ips($bot) as $ip) {
                list($address, $prefix) = array_pad(explode('/', $ip, 2), 2, null);
                $this->assertNotFalse(filter_var($address, FILTER_VALIDATE_IP), "Invalid ip in $bot: $ip");
                if ($prefix !== null) {
                    $max = strpos($address, ':') === false ? 32 : 128;
                    $this->assertTrue(ctype_digit($prefix) && (int) $prefix <= $max, "Invalid prefix in $bot: $ip");
                }
            }
        }
    }

    public function testIpsForBotsMergesAndDeduplicates()
    {
        $google = BotChallenge_BotIps::ips('googlebot');
        $bing = BotChallenge_BotIps::ips('bingbot');
        $merged = BotChallenge_BotIps::ipsForBots(array('googlebot', 'bingbot', 'googlebot'));

        $this->assertSame(count(array_unique(array_merge($google, $bing))), count($merged));
        $this->assertSame($merged, array_values(array_unique($merged)));
    }

    public function testOverrideTakesPrecedenceOverBundledFile()
    {
        $override = array('googlebot' => array('1.2.3.0/24', '4.5.6.7'));
        $merged = BotChallenge_BotIps::ipsForBots(array('googlebot', 'bingbot'), $override);

        // The bundled googlebot ranges are replaced by the override, the
        // bingbot ones still come from its file.
        $this->assertContains('1.2.3.0/24', $merged);
        $this->assertContains('4.5.6.7', $merged);
        foreach (BotChallenge_BotIps::ips('googlebot') as $bundled) {
            $this->assertNotContains($bundled, $merged);
        }
        $this->assertSame(
            count($override['googlebot']) + count(BotChallenge_BotIps::ips('bingbot')),
            count($merged)
        );
    }

    public function testEmptyOverrideFallsBackToBundledFiles()
    {
        $this->assertSame(
            BotChallenge_BotIps::ipsForBots(array('googlebot')),
            BotChallenge_BotIps::ipsForBots(array('googlebot'), array())
        );
    }

    public function testUnknownBotHasNoIpAndNoSource()
    {
        $this->assertSame(array(), BotChallenge_BotIps::ips('ahrefsbot'));
        $this->assertNull(BotChallenge_BotIps::sourceUrl('ahrefsbot'));
    }

    /**
     * The exemption of a cdn range would exempt anyone routing requests through
     * it, and a commercial seo crawler is precisely what the challenge blocks.
     */
    public function testExcludedCrawlersAreNotBundled()
    {
        foreach (array('ahrefsbot', 'semrushbot', 'commoncrawl', 'cloudflare', 'fastly') as $excluded) {
            $this->assertArrayNotHasKey($excluded, BotChallenge_BotIps::BOTS);
        }
    }

    public function testSourceUrlByKindOfSource()
    {
        $this->assertStringContainsString('developers.google.com', (string) BotChallenge_BotIps::sourceUrl('googlebot'));
        $this->assertStringContainsString('GoodBots', (string) BotChallenge_BotIps::sourceUrl('marginalia'));
        // Qwant publishes no list: the range is static, so it is never fetched.
        $this->assertNull(BotChallenge_BotIps::sourceUrl('qwant'));
    }

    public function testValueOptionsAreLabelsByName()
    {
        $valueOptions = BotChallenge_BotIps::valueOptions();
        $this->assertSame(array_keys(BotChallenge_BotIps::BOTS), array_keys($valueOptions));
        $this->assertSame('Googlebot (Google)', $valueOptions['googlebot']);
    }
}
