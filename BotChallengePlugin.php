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
        'botchallenge_cookie_lifetime' => 7,
        'botchallenge_test_headless' => 1,
        'botchallenge_bind_ip' => 0,
        // Machine endpoints and token authorized links, where a browser
        // challenge is meaningless or harmful.
        'botchallenge_exception_paths' => self::DEFAULT_EXCEPTION_PATHS,
        'botchallenge_exception_ips' => '',
        'botchallenge_exception_bots' => '[]',
        // Ranges refreshed from the sources, by bot.
        'botchallenge_bot_ips' => '[]',
        // Ranges of the exempted bots and manual ranges, merged when the config
        // is saved and read on each request.
        'botchallenge_exception_ips_all' => '[]',
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
        $this->_saveBots(BotChallenge_BotIps::DEFAULT_BOTS, '');
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

            // The ip ranges of the search engines are now bundled.
            $this->_saveBots(BotChallenge_BotIps::DEFAULT_BOTS, (string) get_option('botchallenge_exception_ips'));
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
        set_option('botchallenge_bind_ip', empty($post['botchallenge_bind_ip']) ? 0 : 1);
        set_option('botchallenge_exception_paths', trim($post['botchallenge_exception_paths'] ?? ''));

        $exceptionIps = trim($post['botchallenge_exception_ips'] ?? '');
        set_option('botchallenge_exception_ips', $exceptionIps);

        $bots = array_values(array_intersect(
            (array) ($post['botchallenge_exception_bots'] ?? array()),
            array_keys(BotChallenge_BotIps::BOTS)
        ));
        $this->_saveBots($bots, $exceptionIps);

        if (!empty($post['botchallenge_refresh_ips'])) {
            Zend_Registry::get('bootstrap')->getResource('jobs')
                ->sendLongRunning('BotChallenge_Job_RefreshBotIps');
        }
    }

    /**
     * Save the exempted bots and merge their ranges with the manual ones.
     *
     * The ranges of the bots (bundled files or the base override refreshed by
     * the job) and the manual ones are merged once here, so the check of each
     * request reads a single list.
     */
    protected function _saveBots(array $bots, $exceptionIps)
    {
        $exceptionIps = array_filter(array_map('trim', explode("\n", (string) $exceptionIps)));
        // The bundled files may be overridden by the ranges refreshed by the
        // job and stored in the options.
        $override = json_decode((string) get_option('botchallenge_bot_ips'), true) ?: array();
        set_option('botchallenge_exception_bots', json_encode(array_values($bots)));
        set_option('botchallenge_exception_ips_all', json_encode(array_values(array_unique(array_merge(
            BotChallenge_BotIps::ipsForBots($bots, $override),
            $exceptionIps
        )))));
    }
}
