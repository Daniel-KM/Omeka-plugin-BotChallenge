<?php
/**
 * @var Omeka_View $this
 * @var string $nonce
 * @var int $delay
 * @var string $redirectUrl
 * @var bool $isHttps
 * @var bool $testHeadless
 */

// The styles follow Omeka minimal layout colors (see application/views/scripts/error/).

?>
<!DOCTYPE html>
<html lang="<?= html_escape(get_html_lang()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= html_escape(__('Verifying your browser')) ?></title>
    <style>
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: "Lato", sans-serif;
            font-size: 14px;
            line-height: 1.5em;
            background: #F5f5f5;
            color: #232323;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 24px;
        }

        #content {
            background: white;
            padding: 48px;
            width: 100%;
            max-width: 480px;
            text-align: center;
            box-shadow: 0 0 8px rgba(0, 0, 0, 0.1);
        }

        h1 {
            font-weight: 700;
            font-size: 24px;
            line-height: 30px;
            color: #939393;
            margin-bottom: 12px;
        }

        .spinner {
            width: 40px;
            height: 40px;
            margin: 0 auto 24px;
            border: 4px solid #dfdfdf;
            border-top-color: #003576;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        .countdown {
            font-size: 32px;
            font-weight: bold;
            color: #003576;
            margin: 12px 0;
        }

        .interaction {
            margin: 12px 0;
        }
        .interaction label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }
        .interaction input {
            width: 20px;
            height: 20px;
        }
        .interaction button {
            border: 0;
            background: #003576;
            color: #fff;
            cursor: pointer;
            font: inherit;
            padding: 8px 24px;
        }

        .status {
            color: #666;
            font-size: 14px;
        }
        .error {
            color: #914E33;
        }

        noscript #noscript-box {
            display: block;
        }
        #noscript-box {
            border-left: 4px solid #914E33;
            text-align: left;
            padding-left: 12px;
        }

        footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            text-align: center;
            padding: 6px;
            font-size: 12px;
            color: #939393;
        }
        footer a {
            color: #003576;
            text-decoration: none;
            transition: 0.5s all;
        }
        footer a:hover {
            color: #000710;
        }

        @media (max-width: 600px) {
            body {
                padding: 12px;
            }
            #content {
                padding: 24px;
            }
        }
    </style>
</head>
<body>
    <div id="content" role="main">
        <noscript>
            <div id="noscript-box">
                <h1><?= html_escape(__('JavaScript required')) ?></h1>
                <p class="error"><?= html_escape(__('Please enable JavaScript in your browser to access this site.')) ?></p>
            </div>
        </noscript>
        <div id="challenge" style="display:none;">
            <div class="spinner" id="spinner"></div>
            <h1><?= html_escape(__('Verifying your browser…')) ?></h1>
            <div class="countdown" id="countdown"></div>
            <?php if ($interaction === 'checkbox'): ?>
            <p class="interaction" id="interaction" hidden><label><input type="checkbox" id="interaction-control"> <?= html_escape(__('I am not a robot')) ?></label></p>
            <?php elseif ($interaction === 'button'): ?>
            <p class="interaction" id="interaction" hidden><button type="button" id="interaction-control"><?= html_escape(__('Access the site')) ?></button></p>
            <?php endif ?>
            <p class="status" id="status"><?= html_escape($interaction ? __('Please wait a few seconds.') : __('This is an automatic process. Please wait.')) ?></p>
        </div>
    </div>
    <footer>
        <span><?= sprintf(__('Powered by %s'), '<a href="https://omeka.org">Omeka</a>') ?></span>
    </footer>
    <script>
    (function() {
        var nonce = <?= json_encode($nonce) ?>;
        var delay = <?= (int) $delay ?>;
        var redirectUrl = <?= json_encode($redirectUrl) ?>;
        var isHttps = <?= $isHttps ? 'true' : 'false' ?>;
        var testHeadless = <?= $testHeadless ? 'true' : 'false' ?>;
        var interaction = <?= json_encode($interaction) ?>;

        var box = document.getElementById('challenge');
        var countdownEl = document.getElementById('countdown');
        var statusEl = document.getElementById('status');
        var spinnerEl = document.getElementById('spinner');

        box.style.display = '';

        if (testHeadless) {
            var isBot = false;
            if (navigator.webdriver === true) isBot = true;
            if (window._phantom || window.callPhantom) isBot = true;
            if (window.__selenium_unwrapped || window.__webdriver_evaluate || window.__driver_evaluate) isBot = true;
            if (/HeadlessChrome/.test(navigator.userAgent)) isBot = true;
            // No check of plugins or network: an empty list of plugins (pdf
            // viewer disabled by policy) or a null rtt (fast local network) are
            // common in real browsers, in particular in managed networks.
            var isMobile = /Mobi|Android/i.test(navigator.userAgent);
            if (!isMobile && (!navigator.languages || navigator.languages.length === 0)) isBot = true;
            if (isBot) {
                spinnerEl.style.display = 'none';
                countdownEl.textContent = '';
                statusEl.className = 'status error';
                statusEl.textContent = <?= json_encode(__('Automated browser detected. Access denied.')) ?>;
                return;
            }
        }

        var verify = function() {
            // The cookie is delivered by the server, once it checked the
            // nonce: the client cannot forge it by itself.
            var body = new FormData();
            body.append('nonce', nonce);
            fetch(window.location.pathname, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            })
                .then(function (response) {
                    if (!response.ok) throw new Error('denied');
                    statusEl.textContent = <?= json_encode(__('Verified. Redirecting…')) ?>;
                    window.location.href = redirectUrl;
                })
                .catch(function () {
                    statusEl.className = 'status error';
                    statusEl.textContent = <?= json_encode(__('Verification failed. Reload the page to try again.')) ?>;
                });
        };

        var askInteraction = function() {
            var control = document.getElementById('interaction-control');
            document.getElementById('interaction').hidden = false;
            statusEl.textContent = interaction === 'checkbox'
                ? <?= json_encode(__('Check the box to access the site.')) ?>
                : <?= json_encode(__('Click the button to access the site.')) ?>;
            control.focus();
            control.addEventListener('click', function handler(event) {
                // A click triggered by a script is not trusted.
                if (!event.isTrusted) {
                    event.preventDefault();
                    return;
                }
                control.removeEventListener('click', handler);
                control.disabled = true;
                verify();
            });
        };

        var remaining = delay;
        countdownEl.textContent = remaining;
        var interval = setInterval(function() {
            remaining--;
            if (remaining > 0) {
                countdownEl.textContent = remaining;
            } else {
                clearInterval(interval);
                countdownEl.textContent = '';
                spinnerEl.style.display = 'none';
                if (interaction) {
                    askInteraction();
                } else {
                    verify();
                }
            }
        }, 1000);
    })();
    </script>
</body>
</html>
