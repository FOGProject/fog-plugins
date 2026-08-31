<?php
/**
 * Sends the login page straight to the identity provider.
 *
 * PHP version 7.4+
 *
 * @category OIDCLoginRedirect
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

namespace FOG\Plugins\OIDC\Hooks;

use FOG\Plugins\OIDC\Util\OIDCFlow;

/**
 * Sends the login page straight to the identity provider.
 *
 * @category OIDCLoginRedirect
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class OIDCLoginRedirect extends \FOG\Base\Hook
{
    /**
     * The name of this hook.
     *
     * @var string
     */
    public $name = 'OIDCLoginRedirect';
    /**
     * The description.
     *
     * @var string
     */
    public $description = 'Send the login page to the identity provider.';
    /**
     * For posterity.
     *
     * @var bool
     */
    public $active = true;
    /**
     * The node to work with.
     *
     * @var string
     */
    public $node = 'oidc';
    /**
     * Initialize object.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->registerInstalled([
            ['LOGIN_PAGE_REDIRECT', 'loginRedirect']
        ]);
    }
    /**
     * Where an anonymous visitor goes instead of FOG's login form.
     *
     * For an install where everyone signs in through one provider, landing
     * on a username and password box is a dead end: the accounts are at the
     * provider and the box cannot accept them. This is the setting that
     * removes the extra click.
     *
     * It is also the most dangerous setting in this plugin, and the design
     * of the seam is what contains it. Core only offers LOGIN_PAGE_REDIRECT
     * when FOG_LOCAL_LOGIN is undefined, so on management/login.php this
     * method is never reached -- not consulted and overruled, never asked.
     * That is what makes the escape hatch survive a provider whose
     * certificate expired, whose issuer was mistyped, or which is simply
     * switched off; and it also means a bug in this method cannot take that
     * page down, because the page does not run it.
     *
     *     https://<fog>/fog/management/login.php
     *
     * A provider that refuses a sign-in sends the browser to that same page
     * rather than back to index.php (OIDCFlow::_fail()), so a provider that
     * is down produces one error message rather than a redirect loop.
     *
     * @param mixed $arguments where to send the browser instead
     *
     * @return void
     */
    public function loginRedirect($arguments)
    {
        $url = OIDCFlow::loginRedirectUrl();
        if ('' === $url) {
            return;
        }
        $arguments['redirect'] = $url;
    }
}
