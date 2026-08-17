<?php
/**
 * Which user groups an identity provider group grants.
 *
 * PHP version 7.4+
 *
 * @category OIDCGroupUserGroupAssociation
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
/**
 * Which user groups an identity provider group grants.
 *
 * @category OIDCGroupUserGroupAssociation
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class OIDCGroupUserGroupAssociation extends FOGController
{
    /**
     * The table name.
     *
     * @var string
     */
    protected $databaseTable = 'oidcGroupUserGroupAssoc';
    /**
     * The table fields.
     *
     * @var array
     */
    protected $databaseFields = [
        'id' => 'ogugID',
        // Route::ids() orders by name, so an association table without one
        // has every lookup against it fail. The LDAP plugin needed two
        // repair migrations to learn that.
        'name' => 'ogugName',
        'oidcgroupID' => 'ogugGroupID',
        'usergroupID' => 'ogugUserGroupID'
    ];
    /**
     * The required fields.
     *
     * @var array
     */
    protected $databaseFieldsRequired = [
        'oidcgroupID',
        'usergroupID'
    ];
}
