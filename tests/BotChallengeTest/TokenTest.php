<?php

require_once dirname(dirname(__DIR__)) . '/controllers/IndexController.php';

/**
 * Tests for the signature shared between the Challenge plugin and the
 * IndexController.
 *
 * Token format: "{timestamp}_{signature}", where the signature covers the
 * timestamp and the user agent, so a cookie is not usable by another client.
 *
 * The signature of the plugin is called directly: a test rewriting the formula
 * would keep passing after the formula changed.
 *
 * @covers BotChallenge_Token::signature
 */
class BotChallengeTest_TokenTest extends PHPUnit\Framework\TestCase
{
    const SALT = 'test-salt-abc123';
    const AGENT = 'Mozilla/5.0 (X11; Linux x86_64)';

    public function testSameInputsProduceSameSignature(): void
    {
        $this->assertSame(
            BotChallenge_Token::signature('1700000000', self::AGENT, self::SALT),
            BotChallenge_Token::signature('1700000000', self::AGENT, self::SALT)
        );
    }

    public function testDifferentSaltProducesDifferentSignature(): void
    {
        $this->assertNotSame(
            BotChallenge_Token::signature('1700000000', self::AGENT, 'salt-one'),
            BotChallenge_Token::signature('1700000000', self::AGENT, 'salt-two')
        );
    }

    public function testDifferentTimestampProducesDifferentSignature(): void
    {
        $this->assertNotSame(
            BotChallenge_Token::signature('1700000000', self::AGENT, self::SALT),
            BotChallenge_Token::signature('1700000001', self::AGENT, self::SALT)
        );
    }

    /**
     * The core of the fix: a cookie shared with another client is refused.
     */
    public function testDifferentUserAgentProducesDifferentSignature(): void
    {
        $this->assertNotSame(
            BotChallenge_Token::signature('1700000000', self::AGENT, self::SALT),
            BotChallenge_Token::signature('1700000000', 'Some-Bot/1.0', self::SALT)
        );
    }

    public function testEmptyUserAgentIsSupported(): void
    {
        $signature = BotChallenge_Token::signature('1700000000', '', self::SALT);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $signature);
    }

    public function testSignatureFormat(): void
    {
        $signature = BotChallenge_Token::signature('1700000000', self::AGENT, self::SALT);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $signature);
    }

    /**
     * The timestamp must hold no space: it is encoded as "+" in the cookie and
     * not decoded back, so the signature would never match.
     */
    public function testTokenHasNoSpace(): void
    {
        $timestamp = (string) time();
        $token = $timestamp . '_' . BotChallenge_Token::signature($timestamp, self::AGENT, self::SALT);
        $this->assertStringNotContainsString(' ', $token);
        $this->assertMatchesRegularExpression('/^\d+_[0-9a-f]{64}$/', $token);
    }

    /**
     * Validation as done by the Challenge plugin.
     */
    public function testValidation(): void
    {
        $timestamp = (string) time();
        $token = $timestamp . '_' . BotChallenge_Token::signature($timestamp, self::AGENT, self::SALT);

        $pos = strrpos($token, '_');
        $this->assertNotFalse($pos);
        $parsedTimestamp = substr($token, 0, $pos);
        $parsedSignature = substr($token, $pos + 1);

        $this->assertTrue(hash_equals(
            BotChallenge_Token::signature($parsedTimestamp, self::AGENT, self::SALT),
            $parsedSignature
        ));
        $this->assertSame(time(), (int) $parsedTimestamp);
    }

    public function testRejectsWrongSalt(): void
    {
        $timestamp = (string) time();
        $signature = BotChallenge_Token::signature($timestamp, self::AGENT, 'correct-salt');

        $this->assertFalse(hash_equals(
            BotChallenge_Token::signature($timestamp, self::AGENT, 'wrong-salt'),
            $signature
        ));
    }

    /**
     * The ip is added to the signature only when the option is set.
     */
    public function testIpIsNotInTheSignatureByDefault(): void
    {
        $this->assertSame(
            BotChallenge_Token::signature('1700000000', self::AGENT, self::SALT),
            BotChallenge_Token::signature('1700000000', self::AGENT, self::SALT, '')
        );
    }

    public function testDifferentIpProducesDifferentSignature(): void
    {
        $this->assertNotSame(
            BotChallenge_Token::signature('1700000000', self::AGENT, self::SALT, '10.0.0.1'),
            BotChallenge_Token::signature('1700000000', self::AGENT, self::SALT, '10.0.0.2')
        );
    }

    public function testEnablingTheIpInvalidatesTheCookies(): void
    {
        $this->assertNotSame(
            BotChallenge_Token::signature('1700000000', self::AGENT, self::SALT),
            BotChallenge_Token::signature('1700000000', self::AGENT, self::SALT, '10.0.0.1')
        );
    }

    public function testRejectsSharedCookie(): void
    {
        $timestamp = (string) time();
        $signature = BotChallenge_Token::signature($timestamp, self::AGENT, self::SALT);

        // Another client replaying the very same cookie.
        $this->assertFalse(hash_equals(
            BotChallenge_Token::signature($timestamp, 'Another-Client/2.0', self::SALT),
            $signature
        ));
    }

    /**
     * Zend does not autoload the controllers, so the front controller plugin,
     * run on each page, cannot use a class of the controller.
     */
    public function testFrontControllerPluginDoesNotUseAController(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/libraries/BotChallenge/Controller/Plugin/Challenge.php');
        $this->assertStringNotContainsString('BotChallenge_IndexController', $source);
    }

}
