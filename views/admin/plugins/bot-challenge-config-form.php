<?php
/**
 * @var Omeka_View $this
 */
?>
<fieldset id="fieldset-botchallenge"><legend><?= __('Bot Challenge') ?></legend>

    <div class="field">
        <div class="two columns alpha">
            <?= $this->formLabel('botchallenge_salt', __('HMAC salt')) ?>
        </div>
        <div class="inputs five columns omega">
            <?= $this->formText('botchallenge_salt', get_option('botchallenge_salt'), array('id' => 'botchallenge_salt')) ?>
            <p class="explanation">
                <?= __('Secret salt used to generate challenge tokens. Leave empty to regenerate automatically. Changing this value invalidates all existing cookies.') ?>
            </p>
        </div>
    </div>

    <div class="field">
        <div class="two columns alpha">
            <?= $this->formLabel('botchallenge_delay', __('Challenge delay (seconds)')) ?>
        </div>
        <div class="inputs five columns omega">
            <?= $this->formText('botchallenge_delay', get_option('botchallenge_delay'), array('id' => 'botchallenge_delay', 'size' => 4)) ?>
            <p class="explanation">
                <?= __('Number of seconds the visitor must wait before the cookie is set.') ?>
            </p>
        </div>
    </div>

    <div class="field">
        <div class="two columns alpha">
            <?= $this->formLabel('botchallenge_cookie_lifetime', __('Cookie lifetime (days)')) ?>
        </div>
        <div class="inputs five columns omega">
            <?= $this->formText('botchallenge_cookie_lifetime', get_option('botchallenge_cookie_lifetime'), array('id' => 'botchallenge_cookie_lifetime', 'size' => 4)) ?>
            <p class="explanation">
                <?= __('Number of days the challenge cookie remains valid.') ?>
            </p>
        </div>
    </div>

    <div class="field">
        <div class="two columns alpha">
            <?= $this->formLabel('botchallenge_test_headless', __('Detect headless browsers')) ?>
        </div>
        <div class="inputs five columns omega">
            <?= $this->formCheckbox('botchallenge_test_headless', get_option('botchallenge_test_headless'), null, array('1', '0')) ?>
            <p class="explanation">
                <?= __('Run additional tests to detect headless browsers (Selenium, PhantomJS, Puppeteer, etc.).') ?>
            </p>
        </div>
    </div>

    <div class="field">
        <div class="two columns alpha">
            <?= $this->formLabel('botchallenge_exception_paths', __('Exception paths')) ?>
        </div>
        <div class="inputs five columns omega">
            <?= $this->formTextarea('botchallenge_exception_paths', get_option('botchallenge_exception_paths'), array('id' => 'botchallenge_exception_paths', 'rows' => 5, 'cols' => 60)) ?>
            <p class="explanation">
                <?= __('Url path prefixes to exclude from the challenge, one per line. Example: /api') ?>
            </p>
        </div>
    </div>

    <div class="field">
        <div class="two columns alpha">
            <?= $this->formLabel('botchallenge_exception_bots', __('Exempted search engines')) ?>
        </div>
        <div class="inputs five columns omega">
            <?= $this->formMultiCheckbox(
                'botchallenge_exception_bots[]',
                json_decode((string) get_option('botchallenge_exception_bots'), true) ?: array(),
                array('id' => 'botchallenge_exception_bots'),
                array_map('__', BotChallenge_BotIps::valueOptions())
            ) ?>
            <p class="explanation">
                <?= __('Search engines whose published ip ranges are excluded from the challenge. Without exemption, they receive the javascript challenge, do not solve it, and stop indexing the site. The ranges are bundled with the plugin and may be updated with the job below.') ?>
            </p>
        </div>
    </div>

    <div class="field">
        <div class="two columns alpha">
            <?= $this->formLabel('botchallenge_refresh_ips', __('Update the ip ranges now')) ?>
        </div>
        <div class="inputs five columns omega">
            <?= $this->formCheckbox('botchallenge_refresh_ips', null, array('id' => 'botchallenge_refresh_ips'), array('1', '0')) ?>
            <p class="explanation">
                <?= __('Run a job to fetch the ip ranges from the sources of the search engines. A source that is unreachable or truncated is skipped and the bundled ranges are kept.') ?>
            </p>
        </div>
    </div>

    <div class="field">
        <div class="two columns alpha">
            <?= $this->formLabel('botchallenge_exception_ips', __('Exception IPs')) ?>
        </div>
        <div class="inputs five columns omega">
            <?= $this->formTextarea('botchallenge_exception_ips', get_option('botchallenge_exception_ips'), array('id' => 'botchallenge_exception_ips', 'rows' => 5, 'cols' => 60)) ?>
            <p class="explanation">
                <?= __('Ips v4 or v6 or cidr ranges to exclude from the challenge, one per line: the networks of the institution, a partner harvester, a specific bot. They are added to the ranges of the search engines above.') ?>
            </p>
        </div>
    </div>

</fieldset>
