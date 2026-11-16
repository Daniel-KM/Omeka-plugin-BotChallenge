<?php

require_once __DIR__ . '/TestableChallenge.php';
require_once dirname(dirname(__DIR__)) . '/BotChallengePlugin.php';

/**
 * @covers BotChallenge_Controller_Plugin_Challenge::isPreviewBot
 * @covers BotChallenge_Controller_Plugin_Challenge::isVerifiedByReverseDns
 */
class BotChallengeTest_PreviewBotTest extends PHPUnit\Framework\TestCase
{
    /**
     * @var string|null
     */
    private $cacheDir;

    private function challenge(): BotChallengeTest_TestableChallenge
    {
        $challenge = new BotChallengeTest_TestableChallenge();
        $challenge->options = array(
            'botchallenge_preview_user_agents' => BotChallengePlugin::DEFAULT_PREVIEW_USER_AGENTS,
            'botchallenge_exception_rdns' => 'TelegramBot = ptr.telegram.org',
        );
        $namespace = 'test-' . bin2hex(random_bytes(4));
        $this->cacheDir = sys_get_temp_dir() . '/botchallenge-' . $namespace;
        $challenge->fakeCache = new BotChallenge_Cache($namespace, null, false);
        return $challenge;
    }

    protected function tearDown(): void
    {
        if ($this->cacheDir && is_dir($this->cacheDir)) {
            array_map('unlink', glob($this->cacheDir . '/{,.}*[!.]*', GLOB_BRACE) ?: array());
            rmdir($this->cacheDir);
        }
    }

    /**
     * A reverse dns counting its lookups.
     */
    private function countingDns(string $host, array $forward, int &$count): BotChallenge_ReverseDns
    {
        return new BotChallenge_ReverseDns(function ($ip) use ($host, &$count) {
            ++$count;
            return $host;
        }, function ($h) use ($forward) {
            return $forward;
        });
    }

    /**
     * @dataProvider previewProvider
     */
    public function testPreviewBots(string $userAgent, bool $expected): void
    {
        $this->assertSame($expected, $this->challenge()->publicIsPreviewBot($userAgent), $userAgent);
    }

    public function previewProvider(): array
    {
        return array(
            'facebook' => array('facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)', true),
            'mastodon' => array('Mastodon/4.3.1 (http.rb/5.2.0; +https://mastodon.social/)', true),
            'x' => array('Twitterbot/1.0', true),
            'telegram' => array('TelegramBot (like TwitterBot)', true),
            'browser' => array('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36', false),
            'curl' => array('curl/8.14.1', false),
            'empty' => array('', false),
        );
    }

    public function testTelegramVerifiedByItsNetwork(): void
    {
        $count = 0;
        $challenge = $this->challenge();
        $challenge->fakeReverseDns = $this->countingDns('a1.ptr.telegram.org', array('149.154.161.1'), $count);
        $this->assertTrue($challenge->publicIsVerifiedByReverseDns('TelegramBot (like TwitterBot)', '149.154.161.1'));
    }

    public function testTelegramUserAgentFromAnotherNetworkIsNotVerified(): void
    {
        $count = 0;
        $challenge = $this->challenge();
        $challenge->fakeReverseDns = $this->countingDns('host.scraper.example', array('203.0.113.9'), $count);
        $this->assertFalse($challenge->publicIsVerifiedByReverseDns('TelegramBot (like TwitterBot)', '203.0.113.9'));
        // The spoofed user agent still gets the preview, not the whole page.
        $this->assertTrue($challenge->publicIsPreviewBot('TelegramBot (like TwitterBot)'));
    }

    /**
     * A botnet using the user agent of a verified bot must not trigger two dns
     * lookups on each request.
     */
    public function testVerificationIsCachedByIp(): void
    {
        $count = 0;
        $challenge = $this->challenge();
        $challenge->fakeReverseDns = $this->countingDns('host.scraper.example', array('203.0.113.9'), $count);
        $this->assertFalse($challenge->publicIsVerifiedByReverseDns('TelegramBot', '203.0.113.9'));
        $this->assertFalse($challenge->publicIsVerifiedByReverseDns('TelegramBot', '203.0.113.9'));
        $this->assertSame(1, $count);
    }

    /**
     * When the dns was slow, the lookups are paused and the bot is not
     * verified, so it gets the cheap link preview only.
     */
    public function testLookupsArePausedAfterASlowDns(): void
    {
        $count = 0;
        $challenge = $this->challenge();
        $challenge->fakeReverseDns = $this->countingDns('a1.ptr.telegram.org', array('149.154.161.1'), $count);
        $challenge->fakeCache->set('rdns:paused', true, 600);
        $this->assertFalse($challenge->publicIsVerifiedByReverseDns('TelegramBot', '149.154.161.1'));
        $this->assertSame(0, $count);
    }

    public function testOtherUserAgentIsNeverLookedUp(): void
    {
        $challenge = $this->challenge();
        $challenge->fakeReverseDns = new BotChallenge_ReverseDns(function ($ip) {
            throw new \RuntimeException('No dns lookup expected.');
        });
        $this->assertFalse($challenge->publicIsVerifiedByReverseDns('Mozilla/5.0 Chrome/130.0', '203.0.113.9'));
    }
}
