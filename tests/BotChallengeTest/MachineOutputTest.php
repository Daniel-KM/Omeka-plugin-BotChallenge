<?php

class BotChallengeTest_MachineOutputTest extends PHPUnit\Framework\TestCase
{
    /**
     * @var BotChallengeTest_TestableChallenge
     */
    protected $challenge;

    public function setUp(): void
    {
        $this->challenge = new BotChallengeTest_TestableChallenge();
    }

    public function tearDown(): void
    {
        Zend_Controller_Action_HelperBroker::removeHelper('contextSwitch');
    }

    public function testWithoutHelperNoOutputIsMachine(): void
    {
        Zend_Controller_Action_HelperBroker::removeHelper('contextSwitch');
        $this->assertFalse($this->challenge->publicIsMachineOutput('rss2'));
    }

    /**
     * @dataProvider outputProvider
     */
    public function testOutputIsMachine(string $output, bool $expected): void
    {
        $contexts = new Zend_Controller_Action_Helper_ContextSwitch();
        $contexts->addContexts(Omeka_Application_Resource_Helpers::getDefaultResponseContexts());
        Zend_Controller_Action_HelperBroker::addHelper($contexts);
        $this->assertSame($expected, $this->challenge->publicIsMachineOutput($output));
    }

    public function outputProvider(): array
    {
        return [
            'rss' => ['rss2', true],
            'atom' => ['atom', true],
            'omeka xml' => ['omeka-xml', true],
            'dublin core' => ['dcmes-xml', true],
            'empty' => ['', false],
            'unknown' => ['html', false],
        ];
    }

    /**
     * @dataProvider contextProvider
     */
    public function testPageWithoutTheFormatIsChallenged(?string $context, bool $expected): void
    {
        $contexts = new BotChallengeTest_Stub_ContextSwitch();
        $contexts->currentContext = $context;
        Zend_Controller_Action_HelperBroker::addHelper($contexts);
        $helper = new BotChallenge_Controller_Action_Helper_OutputContext('/bot-challenge');
        $this->assertSame($expected, $helper->needsChallenge());
    }

    public function contextProvider(): array
    {
        return [
            'format applied' => ['rss2', false],
            'format ignored by the page' => [null, true],
        ];
    }

    public function testWithoutHelperThePageIsChallenged(): void
    {
        Zend_Controller_Action_HelperBroker::removeHelper('contextSwitch');
        $helper = new BotChallenge_Controller_Action_Helper_OutputContext('/bot-challenge');
        $this->assertTrue($helper->needsChallenge());
    }
}

/**
 * The name of the helper is the last part of the class name.
 */
class BotChallengeTest_Stub_ContextSwitch extends Zend_Controller_Action_Helper_ContextSwitch
{
    public $currentContext;

    public function getCurrentContext()
    {
        return $this->currentContext;
    }

}
