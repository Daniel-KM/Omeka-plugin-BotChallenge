<?php

/**
 * Challenge a request with the param "output" when the page ignores it.
 *
 * The feeds and the exports cannot pass a browser challenge, but a page that
 * does not provide the requested format ignores the param and returns html.
 * The contexts of the action are set by the constructor of the controller, so
 * the check is done before the dispatch of the action.
 */
class BotChallenge_Controller_Action_Helper_OutputContext extends Zend_Controller_Action_Helper_Abstract
{
    /**
     * @var string
     */
    protected $challengeUrl;

    public function __construct(string $challengeUrl)
    {
        $this->challengeUrl = $challengeUrl;
    }

    public function preDispatch()
    {
        if (!$this->needsChallenge()) {
            return;
        }
        $this->getResponse()
            ->setRedirect($this->challengeUrl, 302)
            ->sendResponse();
        exit;
    }

    public function needsChallenge(): bool
    {
        return !Zend_Controller_Action_HelperBroker::hasHelper('contextSwitch')
            || !Zend_Controller_Action_HelperBroker::getStaticHelper('contextSwitch')->getCurrentContext();
    }
}
