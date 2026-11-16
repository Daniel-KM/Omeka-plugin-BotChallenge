<?php

/**
 * @covers BotChallenge_Token::createNonce
 * @covers BotChallenge_Token::nonceTime
 */
class BotChallengeTest_NonceTest extends PHPUnit\Framework\TestCase
{
    const RANDOM = '0123456789abcdef0123456789abcdef';

    public function testNonceOfTheClientGivesItsTime(): void
    {
        $nonce = BotChallenge_Token::createNonce(1794942458, self::RANDOM, 'Mozilla/5.0', 'salt');
        $this->assertSame(1794942458, BotChallenge_Token::nonceTime($nonce, 'Mozilla/5.0', 'salt'));
    }

    public function testNonceIsBoundToTheUserAgent(): void
    {
        $nonce = BotChallenge_Token::createNonce(1794942458, self::RANDOM, 'Mozilla/5.0', 'salt');
        $this->assertNull(BotChallenge_Token::nonceTime($nonce, 'curl/8.0', 'salt'));
    }

    public function testNonceIsBoundToTheIpWhenSet(): void
    {
        $nonce = BotChallenge_Token::createNonce(1794942458, self::RANDOM, 'Mozilla/5.0', 'salt', '192.0.2.1');
        $this->assertSame(1794942458, BotChallenge_Token::nonceTime($nonce, 'Mozilla/5.0', 'salt', '192.0.2.1'));
        $this->assertNull(BotChallenge_Token::nonceTime($nonce, 'Mozilla/5.0', 'salt', '192.0.2.2'));
    }

    public function testNonceNeedsTheSalt(): void
    {
        $nonce = BotChallenge_Token::createNonce(1794942458, self::RANDOM, 'Mozilla/5.0', 'salt');
        $this->assertNull(BotChallenge_Token::nonceTime($nonce, 'Mozilla/5.0', 'other'));
    }

    public function testTamperedNonceIsRefused(): void
    {
        $nonce = BotChallenge_Token::createNonce(1794942458, self::RANDOM, 'Mozilla/5.0', 'salt');
        [, $random, $hmac] = explode('.', $nonce);
        // A later time cannot be set to skip the delay.
        $this->assertNull(BotChallenge_Token::nonceTime('1794942400.' . $random . '.' . $hmac, 'Mozilla/5.0', 'salt'));
    }

    public function testNonceIsNotTheSignatureOfTheCookie(): void
    {
        $cookieSignature = BotChallenge_Token::signature('1794942458', 'Mozilla/5.0', 'salt');
        $nonce = BotChallenge_Token::createNonce(1794942458, self::RANDOM, 'Mozilla/5.0', 'salt');
        $this->assertStringNotContainsString($cookieSignature, $nonce);
    }

    /**
     * @dataProvider provideMalformedNonces
     */
    public function testMalformedNonceIsRefused(string $nonce): void
    {
        $this->assertNull(BotChallenge_Token::nonceTime($nonce, 'Mozilla/5.0', 'salt'));
    }

    public function provideMalformedNonces(): array
    {
        return array(
            'empty' => array(''),
            'old format' => array(self::RANDOM),
            'two parts' => array('1794942458.' . self::RANDOM),
            'four parts' => array('1794942458.a.b.c'),
            'no time' => array('.' . self::RANDOM . '.abc'),
            'not a time' => array('abc.' . self::RANDOM . '.abc'),
            'no random' => array('1794942458..abc'),
        );
    }
}
