<?php
/**
 * The OU class.
 *
 * PHP version 5
 *
 * @category OU
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

namespace FOG\Plugins\DirectoryGroups\Items;

/**
 * The OU class.
 *
 * @category OU
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class DirectoryGroups extends \FOG\Base\FOGController
{
    /**
     * The location table
     *
     * @var string
     */
    protected $databaseTable = 'directoryOUs';
    /**
     * The location table fields and common names
     *
     * @var array
     */
    protected $databaseFields = [
        'id' => 'doID',
        'name' => 'doName',
        'description' => 'doDesc',
        'createdBy' => 'doCreatedBy',
        'createdTime' => 'doCreatedTime',
        'ou' => 'doDN',
        'domainID' => 'doDomainID',
        'groupID' => 'doGroupID'
    ];
    /**
     * The required fields
     *
     * @var array
     */
    protected $databaseFieldsRequired = [
        'name',
        'ou'
    ];
    /**
     * Additional fields.
     *
     * @var array
     */
    protected $additionalFields = [
        'hosts'
    ];
    /**
     * The longest name a core group may carry: groups.groupName is
     * varchar(50), and directory group names run to 255.
     */
    const GROUP_NAME_MAX = 50;
    /**
     * Stores the item in the DB either stored or updated.
     *
     * Every directory group is backed by a core group of the same name. The
     * core group holds the hosts, so whatever an admin grants that group --
     * snapins, printers, modules, power schedules -- reaches them (fogproject
     * ADR 0038). This creates the core group on first save, keeps its name
     * in step, and saves the host list through it.
     *
     * Saving through the core group fires ASSOCIATION_UPDATED, so the one
     * group per host rule and the AD write live in one listener, whichever
     * page saved the membership. See AddDirectoryGroupsMembership.
     *
     * @return object|bool
     */
    public function save()
    {
        if (!parent::save()) {
            return false;
        }
        $Group = new \FOG\Items\Group((int)$this->get('groupID'));
        $name = self::groupName(
            $this->get('name'),
            $this->get('id'),
            $Group->isValid() ? $Group->get('id') : 0
        );
        if (!$Group->isValid()) {
            $Group = (new \FOG\Items\Group())
                ->set('name', $name)
                ->set('description', _('Hosts in directory group') . ' ' . $this->get('name'))
                ->set('createdBy', $this->get('createdBy') ?: 'fog');
            if (!$Group->save()) {
                return false;
            }
            $this->set('groupID', $Group->get('id'));
            if (!parent::save()) {
                return false;
            }
        } elseif ($Group->get('name') !== $name) {
            $Group->set('name', $name)->save();
        }
        if ($this->isDirty('hosts')) {
            $Group->set('hosts', (array)$this->get('hosts'))->save();
        }
        return $this->load();
    }
    /**
     * The core group name for a directory group.
     *
     * The directory group's own name when it fits and no other core group
     * has it. Otherwise that name cut short, ending in "#<id>", which only
     * this directory group can carry. Example: a second "Sales" becomes
     * "Sales #12".
     *
     * @param string $name    the directory group name
     * @param int    $id      the directory group id
     * @param int    $groupID the core group already backing it, if any
     *
     * @return string
     */
    public static function groupName($name, $id, $groupID = 0)
    {
        $name = trim((string)$name);
        if (strlen($name) <= self::GROUP_NAME_MAX
            && !self::_groupNameTaken($name, $groupID)
        ) {
            return $name;
        }
        $suffix = ' #' . (int)$id;
        return rtrim(substr($name, 0, self::GROUP_NAME_MAX - strlen($suffix)))
            . $suffix;
    }
    /**
     * Does a core group other than this one hold the name?
     *
     * @param string $name    the name
     * @param int    $groupID the core group to ignore
     *
     * @return bool
     */
    private static function _groupNameTaken($name, $groupID)
    {
        return (new \FOG\Managers\GroupManager())->exists($name, (int)$groupID);
    }
    /**
     * Add host to the directory group.
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
     * Remove host from the directory group.
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
     * Loads the hosts, from the core group that holds them.
     *
     * @return void
     */
    protected function loadHosts()
    {
        $this->_loadHostIds(
            'groupassociation',
            ['groupID' => (int)$this->get('groupID') ?: -1],
            'hostID'
        );
    }
}
