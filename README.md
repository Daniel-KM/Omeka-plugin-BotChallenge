Bot Challenge (plugin for Omeka Classic)
========================================

> __New versions of this plugin and support for Omeka Classic version 3.x
> are available on [GitLab], which seems to respect users and privacy better
> than the previous repository.__

[Bot Challenge] is a plugin for [Omeka Classic] that protects public pages
against automated bots using a self-hosted JavaScript challenge. It does not
depend on any third piracy service (google, cloudflare, etc.), so it is GDPR
compliant.

Visitors without a valid cookie are redirected to a verification page, where a
javascript challenge must be completed to get one. Bots, most of whom can't
execute javascript currently, remain trapped in a redirect loop and cannot crawl
the site. The plugin also detects headless browsers (Selenium, Puppeteer,
PhantomJS, etc.).

It is inspired by the mechanism used in [AtoM] (Access to Memory) and many
similar tools (wordfence [Wordpress], antibot [Drupal], etc.).


Installation
------------

The plugin requires Omeka Classic 3.0 or above (tested up to 3.2).

* From the zip

Download the last release [BotChallenge.zip] from the list of releases, and
uncompress it in the `plugins` directory. Rename the folder to `BotChallenge`
if needed.

* From the source and for development

Clone the repository into the `plugins` directory:

```sh
cd plugins
git clone https://gitlab.com/Daniel-KM/Omeka-plugin-BotChallenge.git BotChallenge
```

* For test

The plugin includes a comprehensive test suite with unit and controller tests.
Unit tests (IP/CIDR matching, token generation) run without a database.
Controller tests require a configured test database (see `application/tests/config.ini`).

Run them from the root of Omeka:

```sh
# All tests
vendor/bin/phpunit -c plugins/BotChallenge/phpunit.xml --testdox

# Unit tests only (no database required)
vendor/bin/phpunit -c plugins/BotChallenge/phpunit.xml --testsuite unit

# Controller tests only (requires test database)
vendor/bin/phpunit -c plugins/BotChallenge/phpunit.xml --testsuite controller
```


Quick start
-----------

Once enabled, the plugin is active with default settings: all public pages are
protected. Admin pages, login/logout, api routes, cli jobs, and static files
are not protected.

The configuration can be adjusted in the plugin config form (Admin > Plugins >
BotChallenge > Configure).

- HMAC salt: token used for test (leave empty to autogenerate one)
- Challenge delay: Time the visitor must wait (5 seconds)
- Cookie lifetime: How long the challenge cookie remains valid (7 days). The
  cookie is renewed once half of its lifetime is spent, so an active visitor is
  never challenged again; only an inactive one is.
- Bind the cookie to the ip: add the ip to the signature, so a cookie shared
  with another machine is refused. Disabled by default, and only relevant on a
  controlled network: see the limits below. Toggling it invalidates all
  cookies.
- Detect headless browsers: Run additional tests for headless environments
- Exception paths: /api, /files, /iiif, /oai-pmh-repository, etc.
- Exempted search engines: check the engines whose published ip ranges are
  excluded from the challenge
- Update the ip ranges now: run a job to refresh the bundled ranges
- Exception ips: list of IPv4/IPv6/cidr ranges (one per line), added to the
  ranges of the engines above: the networks of the institution, a partner
  harvester, etc.

### How the challenge works

1. A visitor without a valid cookie is redirected to the challenge page, that
   holds a single use nonce, tied to the session. The page delivers no cookie:
   a plain request returns nothing usable.
2. The javascript checks the browser (headless detection), waits for the delay,
   then posts the nonce back.
3. The server checks the nonce, consumes it, and sets the cookie itself. So the
   cookie is `HttpOnly`: the client never needs to read it.

The cookie holds a timestamp and a signature (HMAC-SHA256) covering that
timestamp and the user agent, with the salt as key. Binding the user agent
prevents a cookie from being shared: a cookie obtained once and distributed to
other clients is refused.

The ip is not part of the signature by default. It can be added with the option
above, but it has two limits, so it is only relevant on a controlled network
(intranet, institution with a fixed egress), where the ips are stable and where
sharing a cookie between machines is precisely what should be forbidden.

**The ip changes although the visitor did not move.** The privacy extensions of
ipv6 rotate the address of a desktop, usually every day. A dual stack client
may exit in ipv4 or in ipv6 for the same site. A residential ip is renewed when
the router restarts. The icloud private relay, a vpn or tor change the egress,
sometimes between two connections. An university or a company may have several
egress ips in load balancing. A mobile network is only one case among others.

**The ip is shared by different visitors.** Behind a mobile cgnat or the nat of
a library, a school or an university — the very public of Omeka — thousands of
people go out with the same ip, and a cookie shared inside that perimeter passes
the check anyway.

So the option refuses legitimate visitors that did nothing, and still accepts a
cookie shared between colocated machines.

The delay of the countdown is checked by the server too: the page runs it, so
without this check a client posting the answer immediately would get a cookie.

Note that this binding is not a proof of identity, since a user agent is
declarative: a client copying the header along with the cookie would pass. The
barrier is the nonce, that forces each client to hold a session and to make the
round trip.

### Exempted search engines

The plugin exempts by ip/cidr only: a user agent is declarative, so it cannot be
opposed to a scraper that presents itself as a browser. **Without exemption, the
search engines receive the javascript challenge, do not solve it, and stop
indexing the site.**

The ip ranges of eight engines are bundled with the plugin (Googlebot, Bingbot,
Applebot, DuckDuckBot, Qwant, MojeekBot, Marginalia, Yandex) and selectable with
checkboxes. All but Yandex are exempted by default. Sources are first-hand when
the operator publishes one (Google, Bing and Apple publish a json), else the
[GoodBots] aggregate. Qwant publishes no list, so the prefix announced by its
autonomous system is used (AS199064).

Some indexers are not bundled deliberately: Common Crawl (training corpus, not a
search engine), the commercial seo crawlers (Ahrefs, Semrush) and the ones that
use a cdn, since it would allow anyone routing requests through it.

The lists move, so a job refreshes them from their sources: it can be run from
the config form.


TODO
----

- [ ] Rate limiting for api (see [Mediawiki])
- [ ] Anti robots BlackHole (see [Wordpress blackhole])
- [ ] Remove ux challenge


Warning
-------

Use it at your own risk.

It's always recommended to backup your files and your databases and to check
your archives regularly so you can roll back if needed.

Changing the salt invalidates all existing cookies: every visitor will need to
complete the challenge again.

The cookie is bound to the user agent, so a visitor updating its browser
completes the challenge again.


Troubleshooting
---------------

See online issues on the [plugin issues] page on GitLab.


License
-------

This plugin is published under the [CeCILL v2.1] license, compatible with
[GNU/GPL] and approved by [FSF] and [OSI].

This software is governed by the CeCILL license under French law and abiding by
the rules of distribution of free software. You can use, modify and/ or
redistribute the software under the terms of the CeCILL license as circulated by
CEA, CNRS and INRIA at the following URL "http://www.cecill.info".

As a counterpart to the access to the source code and rights to copy, modify and
redistribute granted by the license, users are provided only with a limited
warranty and the software's author, the holder of the economic rights, and the
successive licensors have only limited liability.

In this respect, the user's attention is drawn to the risks associated with
loading, using, modifying and/or developing or reproducing the software by the
user in light of its specific status of free software, that may mean that it is
complicated to manipulate, and that also therefore means that it is reserved for
developers and experienced professionals having in-depth computer knowledge.
Users are therefore encouraged to load and test the software's suitability as
regards their requirements in conditions enabling the security of their systems
and/or data to be ensured and, more generally, to use and operate it in the same
conditions as regards security.

The fact that you are presently reading this means that you have had knowledge
of the CeCILL license and that you accept its terms.


Copyright
---------

- Copyright Daniel Berthereau, 2026 (see [Daniel-KM] on GitLab)

The idea of this plugin comes from a [thread in omeka forum].


[Bot Challenge]: https://gitlab.com/Daniel-KM/Omeka-plugin-BotChallenge
[Omeka Classic]: https://omeka.org/classic
[AtoM]: https://www.accesstomemory.org/en/docs/2.10/admin-manual/security/js-challenge
[Wordpress]: https://wordpress.org/plugins/wordfence
[Drupal]: https://www.drupal.org/project/antibot
[GoodBots]: https://github.com/AnTheMaker/GoodBots
[Mediawiki]: https://www.mediawiki.org/wiki/API:Ratelimit
[Wordpress blackhole]: https://wordpress.org/plugins/blackhole-bad-bots
[BotChallenge.zip]: https://gitlab.com/Daniel-KM/Omeka-plugin-BotChallenge/-/releases
[thread in omeka forum]: https://forum.omeka.org/t/inquiry-about-bot-traffic-control-in-omeka-classic/28800
[installing a plugin]: https://omeka.org/classic/docs/Admin/Adding_and_Managing_Plugins/
[plugin issues]: https://gitlab.com/Daniel-KM/Omeka-plugin-BotChallenge/-/issues
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[GitLab]: https://gitlab.com/Daniel-KM
[Daniel-KM]: https://gitlab.com/Daniel-KM "Daniel Berthereau"
