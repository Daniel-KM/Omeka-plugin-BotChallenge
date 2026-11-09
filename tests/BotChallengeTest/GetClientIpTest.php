<?php

require_once __DIR__ . '/TestableChallenge.php';

/**
 * @covers BotChallenge_Controller_Plugin_Challenge::getClientIp
 */
class BotChallengeTest_GetClientIpTest extends PHPUnit\Framework\TestCase
{
    /**
     * @var BotChallengeTest_TestableChallenge
     */
    protected $challenge;

    /**
     * @var array Backup of $_SERVER keys we modify.
     */
    protected $serverBackup = [];

    public function setUp(): void
    {
        $this->challenge = new BotChallengeTest_TestableChallenge();
        // Backup relevant $_SERVER keys.
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
            $this->serverBackup[$key] = $_SERVER[$key] ?? null;
        }
    }

    public function tearDown(): void
    {
        // Restore $_SERVER.
        foreach ($this->serverBackup as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }
    }

    protected function setServer(array $values): void
    {
        // Clear all proxy headers first.
        unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP']);
        foreach ($values as $key => $value) {
            $_SERVER[$key] = $value;
        }
    }

    public function testRemoteAddrOnly(): void
    {
        $this->setServer(['REMOTE_ADDR' => '203.0.113.50']);
        $this->assertSame('203.0.113.50', $this->challenge->publicGetClientIp());
    }

    public function testXForwardedForSingle(): void
    {
        $this->setServer([
            'HTTP_X_FORWARDED_FOR' => '198.51.100.10',
            'REMOTE_ADDR' => '10.0.0.1',
        ]);
        $this->assertSame('198.51.100.10', $this->challenge->publicGetClientIp());
    }

    public function testXForwardedForMultiple(): void
    {
        $this->setServer([
            'HTTP_X_FORWARDED_FOR' => '198.51.100.10, 10.0.0.1, 172.16.0.1',
            'REMOTE_ADDR' => '10.0.0.1',
        ]);
        $this->assertSame('198.51.100.10', $this->challenge->publicGetClientIp());
    }

    public function testXRealIp(): void
    {
        $this->setServer([
            'HTTP_X_REAL_IP' => '198.51.100.20',
            'REMOTE_ADDR' => '10.0.0.1',
        ]);
        $this->assertSame('198.51.100.20', $this->challenge->publicGetClientIp());
    }

    public function testXForwardedForTakesPriorityOverXRealIp(): void
    {
        $this->setServer([
            'HTTP_X_FORWARDED_FOR' => '198.51.100.10',
            'HTTP_X_REAL_IP' => '198.51.100.20',
            'REMOTE_ADDR' => '10.0.0.1',
        ]);
        $this->assertSame('198.51.100.10', $this->challenge->publicGetClientIp());
    }

    public function testFallbackToLoopbackWhenNoHeaders(): void
    {
        unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP'], $_SERVER['REMOTE_ADDR']);
        $this->assertSame('127.0.0.1', $this->challenge->publicGetClientIp());
    }

    public function testHeadersOfAPublicPeerAreIgnored(): void
    {
        $this->setServer([
            'HTTP_X_FORWARDED_FOR' => '198.51.100.10',
            'HTTP_X_REAL_IP' => '198.51.100.20',
            'REMOTE_ADDR' => '203.0.113.50',
        ]);
        $this->assertSame('203.0.113.50', $this->challenge->publicGetClientIp());
    }

    public function testForgedLeftPartIsIgnored(): void
    {
        $server = [
            'HTTP_X_FORWARDED_FOR' => '66.249.66.1, 198.51.100.10',
            'REMOTE_ADDR' => '10.0.0.1',
        ];
        $this->assertSame('198.51.100.10', BotChallenge_ClientIp::fromServer($server, []));
    }

    public function testExplicitListReplacesPrivateAddresses(): void
    {
        $server = ['HTTP_X_FORWARDED_FOR' => '198.51.100.10', 'REMOTE_ADDR' => '10.0.0.1'];
        $this->assertSame('10.0.0.1', BotChallenge_ClientIp::fromServer($server, ['192.0.2.10']));
        $server['REMOTE_ADDR'] = '192.0.2.10';
        $this->assertSame('198.51.100.10', BotChallenge_ClientIp::fromServer($server, ['192.0.2.0/24']));
    }

    public function testProxyOnTheSameServerIsTrusted(): void
    {
        $server = [
            'SERVER_ADDR' => '135.148.195.196',
            'REMOTE_ADDR' => '135.148.195.196',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.10',
        ];
        $this->assertSame('198.51.100.10', BotChallenge_ClientIp::fromServer($server, []));
    }

    public function testNormalizeProxies(): void
    {
        $this->assertSame(
            ['192.0.2.10', '10.0.0.0/8', '2001:db8::/32'],
            BotChallenge_ClientIp::normalizeProxies("192.0.2.10\nnot-an-ip\n10.0.0.0/8\n10.0.0.0/99\n2001:db8::/32\n192.0.2.10")
        );
    }

    /**
     * @dataProvider pathProvider
     */
    public function testPathIsExcepted(string $path, array $patterns, bool $expected): void
    {
        $this->assertSame($expected, $this->challenge->publicPathIsExcepted($path, $patterns));
    }

    public function pathProvider(): array
    {
        return [
            'prefix itself' => ['/api', ['/api'], true],
            'prefix sub-path' => ['/api/items', ['/api'], true],
            'shared prefix is not matched' => ['/apixxx', ['/api'], false],
            'suffix' => ['/search/suggest', ['*/suggest'], true],
            'suffix with sub-path' => ['/search/suggest/x', ['*/suggest'], true],
            'other segment' => ['/search/results', ['*/suggest'], false],
            'empty star' => ['/search', ['*'], false],
        ];
    }

}
