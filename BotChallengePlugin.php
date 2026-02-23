<?php

class BotChallengePlugin extends Omeka_Plugin_AbstractPlugin
{
    protected $_hooks = array(
        'install',
        'uninstall',
        'initialize',
        'config_form',
        'config',
        'define_routes',
    );

    protected $_options = array(
        'botchallenge_salt' => '',
        'botchallenge_delay' => 5,
        'botchallenge_cookie_lifetime' => 90,
        'botchallenge_test_headless' => 1,
        'botchallenge_exception_paths' => '/api',
        'botchallenge_exception_ips' => '',
    );

    public function hookInstall()
    {
        $this->_installOptions();
        // Generate a random salt.
        set_option('botchallenge_salt', bin2hex(random_bytes(32)));
    }

    public function hookUninstall()
    {
        $this->_uninstallOptions();
    }

    public function hookInitialize()
    {
        $front = Zend_Controller_Front::getInstance();
        $front->registerPlugin(new BotChallenge_Controller_Plugin_Challenge());
    }

    public function hookDefineRoutes($args)
    {
        $args['router']->addRoute(
            'bot-challenge',
            new Zend_Controller_Router_Route(
                'bot-challenge',
                array(
                    'module' => 'bot-challenge',
                    'controller' => 'index',
                    'action' => 'index',
                )
            )
        );
    }

    public function hookConfigForm($args)
    {
        $view = get_view();
        echo $view->partial('plugins/bot-challenge-config-form.php');
    }

    public function hookConfig($args)
    {
        $post = $args['post'];

        // Regenerate salt if empty.
        $salt = isset($post['botchallenge_salt']) ? trim($post['botchallenge_salt']) : '';
        if ($salt === '') {
            $salt = bin2hex(random_bytes(32));
        }
        set_option('botchallenge_salt', $salt);

        set_option('botchallenge_delay', (int) $post['botchallenge_delay']);
        set_option('botchallenge_cookie_lifetime', (int) $post['botchallenge_cookie_lifetime']);
        set_option('botchallenge_test_headless', isset($post['botchallenge_test_headless']) ? 1 : 0);
        set_option('botchallenge_exception_paths', trim($post['botchallenge_exception_paths'] ?? ''));
        set_option('botchallenge_exception_ips', trim($post['botchallenge_exception_ips'] ?? ''));
    }
}
