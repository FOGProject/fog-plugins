<?php
/**
 * Ends the identity provider's session when FOG's ends.
 *
 * PHP version 7.4+
 *
 * @category OIDCLogout
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

namespace FOG\Plugins\OIDC\Hooks;

use FOG\Plugins\OIDC\Items\OIDC;
use FOG\Plugins\OIDC\Util\OIDCFlow;

/**
 * Ends the identity provider's session when FOG's ends.
 *
 * @category OIDCLogout
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class OIDCLogout extends \FOG\Base\Hook
{
    /**
     * The name of this hook.
     *
     * @var string
     */
    public $name = 'OIDCLogout';
    /**
     * The description.
     *
     * @var string
     */
    public $description = 'End the provider session when FOG\'s session ends.';
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
            ['USER_LOGGING_OUT', 'providerLogout']
        ]);
    }
    /**
     * Sends a signing-out user to the provider's end_session endpoint.
     *
     * Without this, clicking Log out destroys FOG's session and leaves the
     * provider's SSO session untouched -- so clicking the provider button
     * again re-authenticates silently and drops the same person straight
     * back into the same account. There is then no way to sign in as
     * somebody else short of clearing cookies, and on an account carrying
     * uAuthSource='oidc' (which core refuses a local password) there is no
     * way to sign in as anybody else at all.
     *
     * Only for a session that was actually made by this plugin: the values
     * this reads are written by OIDCFlow::callback() and by nothing else, so
     * a password session has nothing stored and this returns without
     * touching $redirect. An install with no provider using the setting
     * therefore behaves exactly as it did before.
     *
     * The hook fires before core destroys the session, which is what makes
     * the stored ID token still readable -- see User::logout().
     *
     * @param mixed $arguments where to send the browser instead
     *
     * @return void
     */
    public function providerLogout($arguments)
    {
        $url = OIDCFlow::logoutUrl();
        if ('' === $url && OIDCFlow::forcedProvider() > 0) {
            /*
             * No provider logout to do, but this install sends its login
             * page straight to a provider (#17) -- so core's default
             * landing spot, management/index.php, would bounce the person
             * who just signed out back to a provider whose SSO session is
             * still alive, and sign them silently back in. "Log out" that
             * leaves you logged in is worse than no logout at all.
             *
             * management/login.php is the one page that cannot do that.
             * It does not end the provider session -- only single logout
             * does -- but it leaves somebody looking at a form instead of
             * back where they started.
             *
             * Deliberately NOT postLogoutUri(), which is the ordinary login
             * page: that is the right landing when single logout HAS run,
             * because the provider will then ask who you are instead of
             * waving you through. Here it has not.
             */
            $url = OIDC::localLoginUrl();
        }
        if ('' === $url) {
            return;
        }
        $arguments['redirect'] = $url;
    }
}
