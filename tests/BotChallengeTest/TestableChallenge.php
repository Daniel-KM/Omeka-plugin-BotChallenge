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
}
