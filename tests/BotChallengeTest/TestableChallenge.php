<?php
/**
 * Testable subclass that exposes protected methods of the Challenge plugin.
 *
 * The real routeShutdown() checks php_sapi_name() === 'cli' and returns
 * immediately in the test environment.  This subclass allows testing the
 * individual protected helpers directly.
 */
class BotChallengeTest_TestableChallenge extends BotChallenge_Controller_Plugin_Challenge
{
    public function publicIpInCidr(string $ip, string $cidr): bool
    {
        return $this->ipInCidr($ip, $cidr);
    }

    public function publicGetClientIp(): string
    {
        return $this->getClientIp();
    }

    public function publicPathIsExcepted(string $path, array $exceptionPaths): bool
    {
        return $this->pathIsExcepted($path, $exceptionPaths);
    }

    public function publicIsMachineOutput(string $output): bool
    {
        return $this->isMachineOutput($output);
    }

    /**
     * Options read by the helpers, since get_option() is not available here.
     *
     * @var array
     */
    public $options = array();

    /**
     * @var BotChallenge_Cache|null
     */
    public $fakeCache;

    /**
     * @var BotChallenge_ReverseDns|null
     */
    public $fakeReverseDns;

    public function publicIsPreviewBot(string $userAgent): bool
    {
        return $this->isPreviewBot($userAgent);
    }

    public function publicIsVerifiedByReverseDns(string $userAgent, string $ip): bool
    {
        return $this->isVerifiedByReverseDns($userAgent, $ip);
    }

    protected function option(string $name)
    {
        return $this->options[$name] ?? null;
    }

    protected function cache(): BotChallenge_Cache
    {
        return $this->fakeCache ?? parent::cache();
    }

    protected function reverseDns(): BotChallenge_ReverseDns
    {
        return $this->fakeReverseDns ?? parent::reverseDns();
    }
}
