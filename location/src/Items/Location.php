<?php
/**
 * The location class.
 *
 * PHP version 5
 *
 * @category Location
 * @package  FOGProject
 * @author   Lee Rowlett <nope@nope.nope>
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

namespace FOG\Plugins\Location\Items;

/**
 * The location class.
 *
 * @category Location
 * @package  FOGProject
 * @author   Lee Rowlett <nope@nope.nope>
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class Location extends \FOG\Base\FOGController
{
    /**
     * The location table
     *
     * @var string
     */
    protected $databaseTable = 'location';
    /**
     * The location table fields and common names
     *
     * @var array
     */
    protected $databaseFields = [
        'id' => 'lID',
        'name' => 'lName',
        'description' => 'lDesc',
        'createdBy' => 'lCreatedBy',
        'createdTime' => 'lCreatedTime',
        'storagegroupID' => 'lStorageGroupID',
        'storagenodeID' => 'lStorageNodeID',
        'tftp' => 'lTftpEnabled',
        'protocol' => 'lStorageNodeProto'
    ];
    /**
     * The required fields
     *
     * @var array
     */
    protected $databaseFieldsRequired = [
        'name',
        'storagegroupID',
    ];
    /**
     * Additional fields.
     *
     * @var array
     */
    protected $additionalFields = [
        'hosts',
        'storagenode',
        'storagegroup'
    ];
    /**
     * Database -> Class field relationships
     *
     * @var array
     */
    protected $databaseFieldClassRelationships = [
        'StorageGroup' => [
            'id',
            'storagegroupID',
            'storagegroup'
        ],
        'StorageNode' => [
            'id',
            'storagenodeID',
            'storagenode'
        ]
    ];
    protected $sqlQueryStr = "SELECT `%s`
        FROM `%s`
        LEFT OUTER JOIN `nfsGroups`
        ON `location`.`lStorageGroupID` = `nfsGroups`.`ngID`
        LEFT OUTER JOIN `nfsGroupMembers`
        ON `location`.`lStorageNodeID` = `nfsGroupMembers`.`ngmID`
        AND `nfsGroups`.`ngID` = `nfsGroupMembers`.`ngmGroupID`
        %s
        %s
        %s";
    protected $sqlFilterStr = "SELECT COUNT(`%s`)
        FROM `%s`
        LEFT OUTER JOIN `nfsGroups`
        ON `nfsGroups`.`ngID` = `location`.`lStorageGroupID`
        LEFT OUTER JOIN `nfsGroupMembers`
        ON `nfsGroupMembers`.`ngmID` = `location`.`lStorageNodeID`
        AND `nfsGroups`.`ngID` = `nfsGroupMembers`.`ngmGroupID`
        %s";
    protected $sqlTotalStr = "SELECT COUNT(`%s`)
        FROM `%s`";
    /**
     * Destroy this particular object.
     *
     * @param string $key the key to destroy for match
     *
     * @return bool
     */
    public function destroy($key = 'id')
    {
        \FOG\Router\Route::deletemass(
            'locationassociation',
            ['locationID' => $this->get('id')]
        );
        return parent::destroy($key);
    }
    /**
     * Stores the item in the DB either stored or updated.
     *
     * @return object
     */
    public function save()
    {
        parent::save();
        return $this
            ->assocSetter('Location', 'host')
            ->load();
    }
    /**
     * Add host to the location.
     *
     * @param array $addArray the items to add.
     *
     * @return object
     */
    public function addHost($addArray)
    {
        return $this->addRemItem(
            'hosts',
            (array)$addArray,
            'merge'
        );
    }
    /**
     * Remove host from the location.
     *
     * @param array $removeArray the items to remove.
     *
     * @return object
     */
    public function removeHost($removeArray)
    {
        return $this->addRemItem(
            'hosts',
            (array)$removeArray,
            'diff'
        );
    }
    /**
     * Get the current location group.
     *
     * @return object
     */
    public function getStorageGroup()
    {
        return $this->get('storagegroup');
    }
    /**
     * Get the storage node.
     *
     * With no node set, the location's group chooses one. A group with no
     * nodes, or a group that no longer exists, makes the core throw "No
     * nodes available". Uncaught, that stopped every PXE boot for the
     * location's hosts with an HTTP 500 (forums 18310). So the exception
     * becomes one error log line, naming the location, and an empty node.
     * The hook callers in LocationChangeItems check isValid() and then
     * keep FOG's own choice. A new caller must check isValid() too.
     *
     * @return object
     */
    public function getStorageNode()
    {
        if ($this->get('storagenodeID')) {
            return $this->get('storagenode');
        }
        try {
            return $this->getStorageGroup()->getOptimalStorageNode();
        } catch (\Exception $e) {
            self::logOnce(
                sprintf(
                    'location "%s" (id %d) has no Storage Node set, and its '
                    . 'Storage Group (id %d) gave no node: %s',
                    $this->get('name'),
                    (int)$this->get('id'),
                    (int)$this->get('storagegroupID'),
                    $e->getMessage()
                )
            );
            return new \FOG\Items\StorageNode();
        }
    }
    /**
     * Writes a location problem to the error log once per request.
     *
     * The boot menu fires the location hooks several times per boot, so
     * the same problem would otherwise fill the log with identical lines.
     *
     * @param string $problem what is wrong, naming the location
     *
     * @return void
     */
    public static function logOnce($problem)
    {
        static $logged = [];
        if (isset($logged[$problem])) {
            return;
        }
        $logged[$problem] = true;
        error_log(
            'FOG location: ' . $problem
            . '. Its hosts use the storage FOG picks without the location.'
        );
    }
    /**
     * Loads the locations hosts.
     *
     * @return void
     */
    protected function loadHosts()
    {
        $this->_loadHostIds(
            'locationassociation',
            ['locationID' => $this->get('id')],
            'hostID'
        );
    }
}
