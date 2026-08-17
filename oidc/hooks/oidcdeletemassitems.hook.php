<?php
/**
 * Clears identity links when what they point at is deleted.
 *
 * PHP version 7.4+
 *
 * @category OIDCDeleteMassItems
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
/**
 * Clears identity links when what they point at is deleted.
 *
 * @category OIDCDeleteMassItems
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class OIDCDeleteMassItems extends Hook
{
    /**
     * The name of this hook.
     *
     * @var string
     */
    public $name = 'OIDCDeleteMassItems';
    /**
     * The description of this hook.
     *
     * @var string
     */
    public $description = 'Delete En-mass Route altering for OpenID Connect';
    /**
     * The active flag.
     *
     * @var bool
     */
    public $active = true;
    /**
     * The node this hook enacts with.
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
            ['DELETEMASS_API', 'deletemassitems'],
        ]);
    }
    /**
     * Drops the links belonging to a deleted user or provider.
     *
     * A left-behind link is not merely untidy. It records "this subject at
     * this provider IS user 12", and user ids are reused after a database
     * restore, an import or a migration -- so a stale link is a standing
     * instruction to sign a stranger into whoever holds that id next.
     * _resolveUser() would refuse the login if the claim disagreed, but a
     * link nothing disagrees with is simply believed.
     *
     * Registered on DELETEMASS_API rather than relying on a destroy()
     * override because Route::delete(), the REST single-delete, funnels
     * straight into deletemass() and never constructs the object -- so an
     * override would not run on that path at all. The LDAP plugin found
     * this the hard way (#885).
     *
     * @param mixed $arguments The items to change.
     *
     * @return void
     */
    public function deletemassitems($arguments)
    {
        switch ($arguments['classname']) {
            case 'oidc':
                $arguments['removeItems']['oidcidentity'] = [
                    'providerId' => $arguments['itemIDs']
                ];
                break;
            case 'user':
                $arguments['removeItems']['oidcidentity'] = [
                    'userId' => $arguments['itemIDs']
                ];
                break;
        }
    }
}
