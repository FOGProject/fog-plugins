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
class OIDCDeleteMassItems extends \FOG\Base\Hook
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
     * Drops this plugin's rows that point at something being deleted.
     *
     * Both directions, because the entity classes cannot cover their own.
     * The TARGET being deleted (a role, a user group, a user) is never
     * covered anywhere else: a deleted role would leave its mapping behind,
     * and a deleted user the record of what the sync had granted them.
     *
     * A left-behind row is not merely untidy. An identity link records "this
     * subject at this provider IS user 12", and ids are reused after a
     * database restore, an import or a migration -- so a stale link is a
     * standing instruction to sign a stranger into whoever holds that id
     * next. _resolveUser() would refuse the login if the claim disagreed,
     * but a link nothing disagrees with is simply believed. A surviving
     * role mapping has the same shape: it is still live, so everyone
     * carrying that claim value receives whatever now holds the id.
     *
     * Registered on DELETEMASS_API rather than relying on a destroy()
     * override because Route::delete(), the REST single-delete, funnels
     * straight into deletemass() and never constructs the object -- so an
     * override would not run on that path at all. The LDAP plugin found
     * this the hard way (#885). OIDCGroup::destroy() stays: it is what the
     * UI path uses, and this makes the two agree.
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
                // The provider's groups. deletemass() runs each removeItems
                // entry back through itself, so this re-enters as classname
                // 'oidcgroup' below and each group still takes its own
                // mappings with it.
                $arguments['removeItems']['oidcgroup'] = [
                    'providerID' => $arguments['itemIDs']
                ];
                break;
            case 'oidcgroup':
                $arguments['removeItems']['oidcgrouproleassociation'] = [
                    'oidcgroupID' => $arguments['itemIDs']
                ];
                $arguments['removeItems']['oidcgroupusergroupassociation'] = [
                    'oidcgroupID' => $arguments['itemIDs']
                ];
                break;
            case 'user':
                $arguments['removeItems']['oidcidentity'] = [
                    'userId' => $arguments['itemIDs']
                ];
                $arguments['removeItems']['oidcusergrant'] = [
                    'userID' => $arguments['itemIDs']
                ];
                break;
            case 'role':
                $arguments['removeItems']['oidcgrouproleassociation'] = [
                    'roleID' => $arguments['itemIDs']
                ];
                $arguments['removeItems']['oidcusergrant'] = [
                    'targetType' => OIDCUserGrant::TARGET_ROLE,
                    'targetID' => $arguments['itemIDs']
                ];
                break;
            case 'usergroup':
                $arguments['removeItems']['oidcgroupusergroupassociation'] = [
                    'usergroupID' => $arguments['itemIDs']
                ];
                $arguments['removeItems']['oidcusergrant'] = [
                    'targetType' => OIDCUserGrant::TARGET_USERGROUP,
                    'targetID' => $arguments['itemIDs']
                ];
                break;
        }
    }
}
