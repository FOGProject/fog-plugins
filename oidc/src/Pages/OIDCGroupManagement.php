<?php
/**
 * The identity provider group management page.
 *
 * PHP version 7.4+
 *
 * @category OIDCGroupManagement
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

namespace FOG\Plugins\OIDC\Pages;

use FOG\Plugins\OIDC\Items\OIDCGroup;

/**
 * The identity provider group management page.
 *
 * A provider group is edited here rather than on the provider page because
 * what it grants is an ordinary association: the standard association tab
 * enumerates roles (or user groups) and checks the ones this group grants.
 * renderAssocTab() needs an enumerable entity and an owner id, and on the
 * provider page the owner would be the provider -- a bystander to a
 * group/role relationship.
 *
 * @category OIDCGroupManagement
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class OIDCGroupManagement extends \FOG\Base\FOGPage
{
    /**
     * The node this page works on.
     *
     * @var string
     */
    public $node = 'oidcgroup';
    /**
     * Initialize object.
     *
     * @param string $name the name to construct with
     */
    public function __construct($name = '')
    {
        $this->name = _('OpenID Connect Group Management');
        parent::__construct($this->name);
        $this->headerData = [
            _('Group Claim Value'),
            _('Identity Provider')
        ];
        $this->attributes = [
            [],
            []
        ];
    }
    /**
     * The providers available to scope a group to, id => name.
     *
     * @return array
     */
    private static function _providerChoices()
    {
        $ids = (array)\FOG\Router\Route::getIds('oidc', [], 'id');
        $names = (array)\FOG\Router\Route::getIds('oidc', [], 'name');
        if (count($ids) !== count($names)) {
            return [];
        }
        return array_combine($ids, $names);
    }
    /**
     * Builds the create-form fields (shared by add() and addModal()).
     *
     * @return array
     */
    protected function _addFields()
    {
        $oidcgroup = filter_input(INPUT_POST, 'oidcgroup');
        $providerID = filter_input(INPUT_POST, 'providerID');

        $labelClass = 'col-sm-3 col-form-label';

        return [
            self::makeLabel(
                $labelClass,
                'oidcgroup',
                _('Group Claim Value')
            ) => self::makeInput(
                'form-control oidcgroupname-input',
                'oidcgroup',
                _('fog-admins'),
                'text',
                'oidcgroup',
                $oidcgroup,
                true
            ),
            self::makeLabel(
                $labelClass,
                'providerID',
                _('Identity Provider')
            ) => self::selectForm(
                'providerID',
                self::_providerChoices(),
                $providerID,
                true
            )
        ];
    }
    /**
     * Creates new item.
     *
     * @return void
     */
    public function add()
    {
        $this->renderAddForm(
            'oidcgroup',
            _('Create New Provider Group'),
            'OIDCGROUP_ADD_FIELDS',
            'OIDCGroup'
        );
    }
    /**
     * Creates new item from a modal.
     *
     * @return void
     */
    public function addModal()
    {
        $this->renderAddModalForm(
            'oidcgroup',
            'OIDCGROUP_ADD_FIELDS',
            'OIDCGroup'
        );
    }
    /**
     * Add post.
     *
     * @return void
     */
    public function addPost()
    {
        $this->handleAddPost(
            'OIDCGroup',
            'OIDCGROUP_ADD',
            _('Provider group added!'),
            _('Provider Group Create Success'),
            _('Provider Group Create Fail'),
            function (&$serverFault) {
                $name = trim(
                    filter_input(INPUT_POST, 'oidcgroup')
                );
                $providerID = (int)filter_input(INPUT_POST, 'providerID');
                if ('' === $name) {
                    throw new \Exception(_('A group name is required!'));
                }
                /**
                 * Validated against the providers that exist rather than
                 * trusted, so a posted id cannot scope a group to a provider
                 * that was deleted between render and submit.
                 */
                if (!array_key_exists($providerID, self::_providerChoices())) {
                    throw new \Exception(
                        _('Please select an identity provider!')
                    );
                }
                if (self::_groupExists($providerID, $name)) {
                    throw new \Exception(
                        _('That group is already defined for this provider!')
                    );
                }
                $OIDCGroup = (new \FOG\Plugins\OIDC\Items\OIDCGroup())
                    ->set('name', $name)
                    ->set('providerID', $providerID);
                if (!$OIDCGroup->save()) {
                    $serverFault = true;
                    throw new \Exception(_('Add provider group failed!'));
                }
                return $OIDCGroup;
            }
        );
    }
    /**
     * Whether a group of this name is already defined for a provider.
     *
     * Raw bound SQL rather than a manager exists()/count(): _buildSql()
     * turns '*' and '+' in a scalar filter value into a SQL LIKE wildcard,
     * and a group claim value is an opaque provider string that may contain
     * either -- Entra ID emits raw object GUIDs, and a Keycloak group path
     * is free text. The unique index is the real guard; this only exists to
     * turn its error into a readable message.
     *
     * @param int    $providerID the provider id
     * @param string $name       the group claim value
     * @param int    $ignoreID   a group id to exclude (the one being renamed)
     *
     * @return bool
     */
    private static function _groupExists($providerID, $name, $ignoreID = 0)
    {
        $rows = self::$DB
            ->query(
                'SELECT `ogID` FROM `OIDCGroups` '
                . 'WHERE `ogProviderID` = :provider AND `ogName` = :name '
                . 'AND `ogID` <> :ignore',
                [],
                [
                    'provider' => (int)$providerID,
                    'name' => $name,
                    'ignore' => (int)$ignoreID
                ]
            )
            ->fetch('', 'fetch_all')
            ->get();
        return is_array($rows) && !empty($rows);
    }
    /**
     * The general tab.
     *
     * @return void
     */
    public function oidcGroupGeneral()
    {
        $name = (
            filter_input(INPUT_POST, 'oidcgroup') ?:
            $this->obj->get('name')
        );
        $providerID = (
            filter_input(INPUT_POST, 'providerID') ?:
            $this->obj->get('providerID')
        );

        $labelClass = 'col-sm-3 col-form-label';

        $fields = [
            self::makeLabel(
                $labelClass,
                'oidcgroup',
                _('Group Claim Value')
            ) => self::makeInput(
                'form-control oidcgroupname-input',
                'oidcgroup',
                _('fog-admins'),
                'text',
                'oidcgroup',
                $name,
                true
            ),
            self::makeLabel(
                $labelClass,
                'providerID',
                _('Identity Provider')
            ) => self::selectForm(
                'providerID',
                self::_providerChoices(),
                $providerID,
                true
            )
        ];

        $buttons = self::makeButton(
            'general-send',
            _('Update'),
            'btn btn-primary float-end'
        );
        $buttons .= self::makeButton(
            'general-delete',
            _('Delete'),
            'btn btn-danger float-start'
        );

        self::$HookManager->processEvent(
            'OIDCGROUP_GENERAL_FIELDS',
            [
                'fields' => &$fields,
                'buttons' => &$buttons,
                'OIDCGroup' => &$this->obj
            ]
        );
        $rendered = self::formFields($fields);
        unset($fields);

        echo self::makeFormTag(
            '',
            'oidcgroup-general-form',
            self::makeTabUpdateURL(
                'oidcgroup-general',
                $this->obj->get('id')
            ),
            'post',
            'application/x-www-form-urlencoded',
            true
        );
        echo '<div class="card">';
        echo '<div class="card-body">';
        echo $rendered;
        echo '</div>';
        echo '<div class="card-footer">';
        echo $buttons;
        echo $this->deleteModal();
        echo '</div>';
        echo '</div>';
        echo '</form>';
    }
    /**
     * Updates the general tab.
     *
     * @throws Exception
     *
     * @return void
     */
    public function oidcGroupGeneralPost()
    {
        self::checkAuthAndCSRF();
        $name = trim(
            filter_input(INPUT_POST, 'oidcgroup')
        );
        $providerID = (int)filter_input(INPUT_POST, 'providerID');
        if ('' === $name) {
            throw new \Exception(_('A group name is required!'));
        }
        if (!array_key_exists($providerID, self::_providerChoices())) {
            throw new \Exception(_('Please select an identity provider!'));
        }
        if (self::_groupExists($providerID, $name, (int)$this->obj->get('id'))) {
            throw new \Exception(
                _('That group is already defined for this provider!')
            );
        }
        $this->obj
            ->set('name', $name)
            ->set('providerID', $providerID);
    }
    /**
     * The roles this group grants.
     *
     * @return void
     */
    public function oidcGroupRoles()
    {
        $this->renderAssocTab(
            'oidcgroup-role',
            _('Provider Group Role Associations'),
            _('Role Name'),
            'role',
            'btn btn-primary float-end',
            _(
                'Anyone whose group claim carries this value receives these '
                . 'roles on sign in. Roles are recomputed from the provider '
                . 'each time, so removing someone from the group downgrades '
                . 'them at their next login.'
            ),
            // Create-and-associate: a claim value usually needs a role that
            // does not exist yet, and sending an admin off to the Roles page
            // and back is the loop this closes.
            'role'
        );
    }
    /**
     * Updates the roles this group grants.
     *
     * @return void
     */
    public function oidcGroupRolePost()
    {
        $this->assocPost('addRole', 'removeRole');
    }
    /**
     * The user groups this group grants.
     *
     * @return void
     */
    public function oidcGroupUserGroups()
    {
        $this->renderAssocTab(
            'oidcgroup-usergroup',
            _('Provider Group User Group Associations'),
            _('User Group Name'),
            'usergroup',
            'btn btn-primary float-end',
            _(
                'Preferred over mapping straight to a role: the user group '
                . 'holds the roles, so policy stays in one place and the '
                . 'provider only decides who is in which bucket.'
            ),
            'usergroup',
            // Without this the button reads "Create New Usergroup".
            _('User Group')
        );
    }
    /**
     * Updates the user groups this group grants.
     *
     * @return void
     */
    public function oidcGroupUserGroupPost()
    {
        $this->assocPost('addUserGroup', 'removeUserGroup');
    }
    /**
     * The edit element.
     *
     * @return void
     */
    public function edit()
    {
        $tabData = [];

        // General
        $tabData[] = [
            'name' => _('General'),
            'id' => 'oidcgroup-general',
            'generator' => function () {
                $this->oidcGroupGeneral();
            }
        ];

        // Role Association
        $tabData[] = [
            'name' => _('Role Association'),
            'id' => 'oidcgroup-role',
            'generator' => function () {
                $this->oidcGroupRoles();
            }
        ];

        // User Group Association
        $tabData[] = [
            'name' => _('User Group Association'),
            'id' => 'oidcgroup-usergroup',
            'generator' => function () {
                $this->oidcGroupUserGroups();
            }
        ];
        $this->renderEditTabs($tabData, $this->obj);
    }
    /**
     * Update the edit elements.
     *
     * @return void
     */
    public function editPost()
    {
        $this->handleEditPost(
            'OIDCGroup',
            'OIDCGROUP_EDIT',
            _('Provider group updated!'),
            _('Provider Group Update Success'),
            _('Provider Group Update Fail'),
            function (&$serverFault) {
                global $tab;
                switch ($tab) {
                    case 'oidcgroup-general':
                        $this->oidcGroupGeneralPost();
                        break;
                    case 'oidcgroup-role':
                        $this->oidcGroupRolePost();
                        break;
                    case 'oidcgroup-usergroup':
                        $this->oidcGroupUserGroupPost();
                        break;
                }
                if (!$this->obj->save()) {
                    $serverFault = true;
                    throw new \Exception(_('Provider group update failed!'));
                }
                \FOG\Auth\Authorization::resetCache();
            }
        );
    }
    /**
     * Gets the role list for the role association tab.
     *
     * @return void
     */
    public function getRolesList()
    {
        return $this->assocItemsList(
            'role',
            'oidcgrouproleassociation',
            'oidcGroupRoleAssoc',
            '`roles`.`rID`',
            '`oidcGroupRoleAssoc`.`ograRoleID`',
            '`oidcGroupRoleAssoc`.`ograGroupID`',
            [
                [
                    // getItemsList() aliases the association flag as
                    // strtolower(class) . 'Assoc', so this is 'oidcgroup',
                    // not 'OIDCGroup'. The lookup is a case-sensitive array
                    // key, and a miss reads as dissociated -- the tab renders
                    // fine and every checkbox is silently unchecked.
                    'db' => 'oidcgroupAssoc',
                    'dt' => 'association',
                    'removeFromQuery' => true
                ]
            ]
        );
    }
    /**
     * Gets the user group list for the user group association tab.
     *
     * @return void
     */
    public function getUserGroupsList()
    {
        return $this->assocItemsList(
            'usergroup',
            'oidcgroupusergroupassociation',
            'oidcGroupUserGroupAssoc',
            '`userGroups`.`ugID`',
            '`oidcGroupUserGroupAssoc`.`ogugUserGroupID`',
            '`oidcGroupUserGroupAssoc`.`ogugGroupID`',
            [
                [
                    // See getRolesList(): the alias is the lowercased class
                    // name, and a miss silently unchecks every box.
                    'db' => 'oidcgroupAssoc',
                    'dt' => 'association',
                    'removeFromQuery' => true
                ]
            ]
        );
    }
    /**
     * Lists every provider group, flagged by whether it feeds the owner.
     *
     * The mirror of the two lists above, for the tabs this plugin injects
     * onto the Role and User Group pages. It cannot go through
     * assocItemsList(): that helper reads the owner from $this->obj, and
     * here $this->obj is whatever OIDCGroup the id happened to name, while
     * the real owner is the role or user group being edited on the other
     * page. The owner is therefore passed explicitly.
     *
     * These live on the plugin's node rather than on the core pages
     * because a plugin cannot add a sub method to a core page class --
     * FOGPageManager dispatches with method_exists() against the page.
     *
     * @param object $owner     the role or user group being edited
     * @param string $secondary the association class for that owner type
     * @param string $table     the association table
     * @param string $itemCol   the association's group column
     * @param string $ownerCol  the association's owner column
     * @param string $assocDt   the association flag getItemsList() emits,
     *                          '<lowercased owner class>Assoc'
     *
     * @return void
     */
    private function _feedList(
        $owner,
        $secondary,
        $table,
        $itemCol,
        $ownerCol,
        $assocDt
    ) {
        $join = [
            "LEFT OUTER JOIN `$table` ON "
            . "`OIDCGroups`.`ogID` = $itemCol "
            . "AND $ownerCol = '" . $owner->get('id') . "'"
        ];
        return $owner->getItemsList(
            'oidcgroup',
            $secondary,
            $join,
            '',
            [
                // The same claim value can legitimately be published by more
                // than one provider, so the row has to say which one it came
                // from. Added here rather than left to
                // AddOIDCAPI::customizeDT(), which only decorates the Route
                // list -- getItemsList() builds its own columns.
                [
                    'db' => 'ogProviderID',
                    'dt' => 'oidcprovider',
                    'formatter' => function ($d, $row) {
                        return OIDCGroup::providerLinkCell($d);
                    }
                ],
                [
                    'db' => $assocDt,
                    'dt' => 'association',
                    'removeFromQuery' => true
                ]
            ]
        );
    }
    /**
     * The owner id for the reverse lists, or 0 when absent.
     *
     * Read from its own parameter rather than from 'id': the association
     * tab helper appends the page's own id, which on the role page is the
     * role -- passing that as 'id' here would load a same-numbered
     * OIDCGroup and read as the wrong entity.
     *
     * @return int
     */
    private static function _ownerID()
    {
        return (int)filter_input(INPUT_GET, 'ownerID');
    }
    /**
     * Lists provider groups against the role being edited.
     *
     * @return void
     */
    public function getRoleFeedList()
    {
        return $this->_feedList(
            new \FOG\Items\Role(self::_ownerID()),
            'oidcgrouproleassociation',
            'oidcGroupRoleAssoc',
            '`oidcGroupRoleAssoc`.`ograGroupID`',
            '`oidcGroupRoleAssoc`.`ograRoleID`',
            'roleAssoc'
        );
    }
    /**
     * Lists provider groups against the user group being edited.
     *
     * @return void
     */
    public function getUserGroupFeedList()
    {
        return $this->_feedList(
            new \FOG\Items\UserGroup(self::_ownerID()),
            'oidcgroupusergroupassociation',
            'oidcGroupUserGroupAssoc',
            '`oidcGroupUserGroupAssoc`.`ogugGroupID`',
            '`oidcGroupUserGroupAssoc`.`ogugUserGroupID`',
            'usergroupAssoc'
        );
    }
}
