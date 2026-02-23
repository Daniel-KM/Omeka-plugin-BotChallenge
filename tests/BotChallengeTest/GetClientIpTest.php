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
}
