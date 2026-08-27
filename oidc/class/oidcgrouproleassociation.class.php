<?php
/**
 * Which roles an identity provider group grants.
 *
 * PHP version 7.4+
 *
 * @category OIDCGroupRoleAssociation
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
/**
 * Which roles an identity provider group grants.
 *
 * @category OIDCGroupRoleAssociation
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class OIDCGroupRoleAssociation extends \FOG\Base\FOGController
{
    /**
     * The table name.
     *
     * @var string
     */
    protected $databaseTable = 'oidcGroupRoleAssoc';
    /**
     * The table fields.
     *
     * @var array
     */
    protected $databaseFields = [
        'id' => 'ograID',
        // Route::ids() orders by name, so an association table without one
        // has every lookup against it fail. The LDAP plugin needed two
        // repair migrations to learn that.
        'name' => 'ograName',
        'oidcgroupID' => 'ograGroupID',
        'roleID' => 'ograRoleID'
    ];
    /**
     * The required fields.
     *
     * @var array
     */
    protected $databaseFieldsRequired = [
        'oidcgroupID',
        'roleID'
    ];
}
