<?php
/**
 * The association between hosts and ous.
 *
 * PHP version 5
 *
 * @category DirectoryGroupsAssociation
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

namespace FOG\Plugins\DirectoryGroups\Items;

/**
 * The association between hosts and ous.
 *
 * Retired: schema step 6 moves these rows into core groupMembers and drops
 * the table. The class stays because DirectoryGroupsAssociationManager
 * resolves it by name, and that manager still creates the table for steps
 * 2 and 3 on a fresh install.
 *
 * @category DirectoryGroupsAssociation
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class DirectoryGroupsAssociation extends \FOG\Base\FOGController
{
    /**
     * The association table.
     *
     * @var string
     */
    protected $databaseTable = 'directoryOUAssoc';
    /**
     * The association fields and common names.
     *
     * @var array
     */
    protected $databaseFields = [
        'id' => 'doaID',
        'directorygroupsID' => 'doaOUID',
        'hostID' => 'doaHostID'
    ];
    /**
     * The required fields
     *
     * @var array
     */
    protected $databaseFieldsRequired = [
        'directorygroupsID',
        'hostID'
    ];
    /**
     * Additional fields
     *
     * @var array
     */
    protected $additionalFields = [
        'host',
        'directorygroups'
    ];
    /**
     * Database -> Class field relationships
     *
     * @var array
     */
    protected $databaseFieldClassRelationships = [
        'Host' => [
            'id',
            'hostID',
            'host'
        ],
        'DirectoryGroups' => [
            'id',
            'directorygroupsID',
            'directorygroups'
        ]
    ];
    /**
     * Return the associated directory group.
     *
     * @return object
     */
    public function getDirectoryGroups()
    {
        return $this->get('directorygroups');
    }
    /**
     * Return the associated host.
     *
     * @return host
     */
    public function getHost()
    {
        return $this->get('host');
    }
}
