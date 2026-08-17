<?php
/**
 * Registers this plugin's routes and its login-page button.
 *
 * PHP version 7.4+
 *
 * @category AddOIDCRoutes
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
/**
 * Registers this plugin's routes and its login-page button.
 *
 * @category AddOIDCRoutes
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class AddOIDCRoutes extends Hook
{
    /**
     * The name of this hook.
     *
     * @var string
     */
    public $name = 'AddOIDCRoutes';
    /**
     * The description.
     *
     * @var string
     */
    public $description = 'Add the OpenID Connect sign-in routes.';
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
            ['API_PLUGIN_ROUTES', 'routes'],
            ['LOGIN_PAGE_PROVIDERS', 'loginButtons']
        ]);
    }
    /**
     * Declares the two flow endpoints.
     *
     * Both are 'public', which is the accurate declaration rather than a
     * convenient one: they exist to be used by somebody who has no session
     * yet, so there is no permission they could be gated on. What stands in
     * for one is that neither does anything on its own -- start() only mints
     * unguessable values and redirects, and callback() refuses everything
     * that does not match the state and nonce it minted.
     *
     * @param mixed $arguments The routes to add to.
     *
     * @return void
     */
    public function routes($arguments)
    {
        $arguments['routes'][] = [
            'name' => 'oidcStart',
            'method' => 'GET',
            'path' => '/ext/oidc/start',
            'handler' => ['OIDCFlow', 'start'],
            'auth' => 'public'
        ];
        $arguments['routes'][] = [
            'name' => 'oidcCallback',
            'method' => 'GET',
            'path' => '/ext/oidc/callback',
            'handler' => ['OIDCFlow', 'callback'],
            'auth' => 'public'
        ];
    }
    /**
     * Puts a button on the login form for each enabled provider.
     *
     * Only enabled ones: a provider an admin is still configuring must not
     * be reachable, and start() checks the same thing again rather than
     * trusting that nobody kept the URL.
     *
     * The start URL carries the provider id and nothing else. Anything the
     * provider needs to know is decided server-side from that row, so there
     * is no parameter here worth tampering with.
     *
     * @param mixed $arguments The provider buttons to add to.
     *
     * @return void
     */
    public function loginButtons($arguments)
    {
        $ids = Route::getIds('oidc', ['enabled' => [1]]);
        foreach ((array)$ids as $id) {
            $provider = self::getClass('OIDC', $id);
            if (!$provider->isValid()) {
                continue;
            }
            $arguments['providers'][] = [
                'label' => sprintf(
                    _('Sign in with %s'),
                    $provider->get('name')
                ),
                'url' => sprintf(
                    '%sext/oidc/start?provider=%d',
                    OIDC::webrootBase(),
                    (int)$id
                ),
                'icon' => $provider->get('icon')
            ];
        }
    }
}
