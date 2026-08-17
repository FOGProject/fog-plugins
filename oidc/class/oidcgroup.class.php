<?php
/**
 * A group name from an identity provider, and what it grants.
 *
 * PHP version 7.4+
 *
 * @category OIDCGroup
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
/**
 * A group name from an identity provider, and what it grants.
 *
 * The thing an admin maps. A row is one value seen in a provider's group
 * claim, and the roles and user groups associated with it are what somebody
 * carrying that value receives when they sign in.
 *
 * It is an object of its own rather than a pair of columns on the provider
 * because granting a role or a user group is an ordinary association, and
 * the shared association tab needs the group itself to be the owning object.
 * The LDAP plugin arrived at the same shape for the same reason (#882).
 *
 * Rows are scoped to a provider: a group name only means something relative
 * to the directory it came from, and two providers can legitimately publish
 * the same name for different populations.
 *
 * @category OIDCGroup
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class OIDCGroup extends FOGController
{
    /**
     * The table name.
     *
     * @var string
     */
    protected $databaseTable = 'OIDCGroups';
    /**
     * The table fields.
     *
     * @var array
     */
    protected $databaseFields = [
        'id' => 'ogID',
        'providerID' => 'ogProviderID',
        'name' => 'ogName'
    ];
    /**
     * The required fields.
     *
     * @var array
     */
    protected $databaseFieldsRequired = [
        'providerID',
        'name'
    ];
    /**
     * Additional fields.
     *
     * @var array
     */
    protected $additionalFields = [
        'roles',
        'usergroups',
        'oidcprovider'
    ];
    /**
     * Database -> Class field relationships.
     *
     * @var array
     */
    protected $databaseFieldClassRelationships = [
        'OIDC' => [
            'id',
            'providerID',
            'oidcprovider'
        ]
    ];
    /**
     * A datatable cell linking to the provider a group belongs to.
     *
     * The same group name can legitimately exist at more than one provider,
     * so a bare "fog-admins" is ambiguous wherever groups from several are
     * listed together. Shared by the group list and the association tabs so
     * the two cannot describe a group differently.
     *
     * @param mixed $providerID the ogProviderID value for the row
     *
     * @return string the cell markup
     */
    public static function providerLinkCell($providerID)
    {
        if (!$providerID) {
            return Route::EMPTY_CELL;
        }
        $name = self::getClass('OIDC', $providerID)->get('name');
        // A group outliving its provider should still list, not fatal.
        if (!$name) {
            return Route::EMPTY_CELL;
        }
        return self::entityLink('oidc', $providerID, $name);
    }
    /**
     * Stores the group, syncing both association sets.
     *
     * assocSetter() no-ops on an association that was never loaded or set,
     * so a save path that never touches roles or user groups is safe.
     *
     * @return object
     */
    public function save()
    {
        parent::save();
        return $this
            ->assocSetter('OIDCGroupRoleAssociation', 'role', true)
            ->assocSetter('OIDCGroupUserGroupAssociation', 'usergroup', true)
            ->load();
    }
    /**
     * Grants this group the given roles.
     *
     * @param array $addArray the role ids to add
     *
     * @return object
     */
    public function addRole($addArray)
    {
        return $this->addRemItem('roles', (array)$addArray, 'merge');
    }
    /**
     * Stops this group granting the given roles.
     *
     * @param array $removeArray the role ids to remove
     *
     * @return object
     */
    public function removeRole($removeArray)
    {
        return $this->addRemItem('roles', (array)$removeArray, 'diff');
    }
    /**
     * Grants this group the given user groups.
     *
     * @param array $addArray the user group ids to add
     *
     * @return object
     */
    public function addUserGroup($addArray)
    {
        return $this->addRemItem('usergroups', (array)$addArray, 'merge');
    }
    /**
     * Stops this group granting the given user groups.
     *
     * @param array $removeArray the user group ids to remove
     *
     * @return object
     */
    public function removeUserGroup($removeArray)
    {
        return $this->addRemItem('usergroups', (array)$removeArray, 'diff');
    }
    /**
     * Loads the roles this group grants.
     *
     * @return void
     */
    protected function loadRoles()
    {
        $this->set(
            'roles',
            (array)Route::getIds(
                'oidcgrouproleassociation',
                ['oidcgroupID' => $this->get('id')],
                'roleID'
            )
        );
    }
    /**
     * Loads the user groups this group grants.
     *
     * @return void
     */
    protected function loadUsergroups()
    {
        $this->set(
            'usergroups',
            (array)Route::getIds(
                'oidcgroupusergroupassociation',
                ['oidcgroupID' => $this->get('id')],
                'usergroupID'
            )
        );
    }
    /**
     * Removes the group and the associations that point at it.
     *
     * Nothing else cleans these up on this path, and an orphaned association
     * row would be inherited by whichever group next reuses this
     * auto-increment id. The DELETEMASS_API hook covers the REST path, where
     * this method is never constructed at all.
     *
     * @param string $key the key to destroy on
     *
     * @return bool
     */
    public function destroy($key = 'id')
    {
        $id = (int)$this->get('id');
        if ($id > 0) {
            Route::deletemass(
                'oidcgrouproleassociation',
                ['oidcgroupID' => $id]
            );
            Route::deletemass(
                'oidcgroupusergroupassociation',
                ['oidcgroupID' => $id]
            );
        }
        return parent::destroy($key);
    }
}
