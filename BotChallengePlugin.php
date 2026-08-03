<?php

class BotChallengePlugin extends Omeka_Plugin_AbstractPlugin
{
    protected $_hooks = array(
        'install',
        'uninstall',
        'upgrade',
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
        // Machine endpoints and token authorized links, where a browser
        // challenge is meaningless or harmful.
        'botchallenge_exception_paths' => self::DEFAULT_EXCEPTION_PATHS,
        'botchallenge_exception_ips' => '',
    );

    /**
     * Paths opened by default, one per line.
     */
    const DEFAULT_EXCEPTION_PATHS = "/api\n/files\n/iiif\n/iiif-presentation\n/oai-pmh-repository\n/sitemap.xml";

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

    public function hookUpgrade($args)
    {
        $oldVersion = $args['old_version'];

        if (version_compare($oldVersion, '2.4.0', '<')) {
            // New default exception paths.
            $paths = array_filter(array_map('trim', explode("\n", (string) get_option('botchallenge_exception_paths'))));
            $paths = array_unique(array_merge($paths, array_filter(explode("\n", self::DEFAULT_EXCEPTION_PATHS))));
            sort($paths);
            set_option('botchallenge_exception_paths', implode("\n", $paths));
        }
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
