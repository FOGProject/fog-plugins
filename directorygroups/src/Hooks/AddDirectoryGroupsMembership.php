<?php
/**
 * Keeps a host in one directory group, and writes its OU onto the host.
 *
 * PHP version 7
 *
 * @category AddDirectoryGroupsMembership
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

namespace FOG\Plugins\DirectoryGroups\Hooks;

/**
 * Keeps a host in one directory group, and writes its OU onto the host.
 *
 * A directory group's hosts live in its core group (groupMembers), so a host
 * can join one from the core Group page, the host's Groups tab, mass edit,
 * CSV import, registration or the API, as well as from this plugin's own
 * pages. Core fires ASSOCIATION_UPDATED from all of those, and this listener
 * applies the two rules a directory group adds:
 *
 * - A host is in at most one directory group. An OU is a single place in the
 *   directory, so two would contradict each other. Joining one removes the
 *   host from any other directory group's core group.
 * - Joining writes the group's OU into the host's ADOU, and its domain into
 *   ADDomain. This is a deliberate exception to fogproject ADR 0038's "a
 *   group grants, it does not copy": placement and the domain join read the
 *   host's own fields, and Tom chose the write (GH-1879 follow-up).
 *
 * Leaving a directory group changes nothing on the host.
 *
 * @category AddDirectoryGroupsMembership
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class AddDirectoryGroupsMembership extends \FOG\Base\Hook
{
    /**
     * The name of this hook.
     *
     * @var string
     */
    public $name = 'AddDirectoryGroupsMembership';
    /**
     * The description of this hook.
     *
     * @var string
     */
    public $description = 'One directory group per host, and its OU on the host';
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
    public $node = 'directorygroups';
    /**
     * Initializes object.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->registerInstalled([
            ['ASSOCIATION_UPDATED', 'membershipChanged'],
        ]);
    }
    /**
     * Applies both rules to the hosts that just joined a group.
     *
     * @param mixed $arguments The event arguments.
     *
     * @return void
     */
    public function membershipChanged($arguments)
    {
        if ('GroupAssociation' !== \FOG\Base\FOGBase::shortName($arguments['association'])
            || count($arguments['added'] ?: []) < 1
        ) {
            return;
        }
        $joins = self::joins(
            \FOG\Base\FOGBase::shortName($arguments['owner']),
            (int)$arguments['owner']->get('id'),
            (array)$arguments['added']
        );
        $groups = self::_directoryGroups();
        $plan = self::plan($joins, $groups);
        if (count($plan) < 1) {
            return;
        }
        // Leave every other directory group. Through Group::save(), not a
        // raw delete, so the removal is an ordinary membership change: it
        // fires this event again, with nothing added, which returns above.
        $now = self::_memberships(array_keys($plan), array_keys($groups));
        foreach (self::leaves($plan, $now) as $groupID => $hostIDs) {
            (new \FOG\Items\Group($groupID))->removeHost($hostIDs)->save();
        }
        // One UPDATE per directory group joined, not per host.
        $byGroup = [];
        foreach ($plan as $hostID => $groupID) {
            $byGroup[$groupID][] = $hostID;
        }
        foreach ($byGroup as $groupID => $hostIDs) {
            $fields = ['ADOU' => $groups[$groupID]['dn']];
            if ('' !== $groups[$groupID]['domain']) {
                $fields['ADDomain'] = $groups[$groupID]['domain'];
            }
            (new \FOG\Managers\HostManager())->update(['id' => $hostIDs], 'AND', $fields);
        }
    }
    /**
     * The directory groups each host must leave.
     *
     * @param array $plan        hostID => the core group id it keeps
     * @param array $memberships list of [hostID, groupID] it holds now, in
     *                           directory groups only
     *
     * @return array groupID => host ids to remove from it
     */
    public static function leaves(array $plan, array $memberships)
    {
        $leave = [];
        foreach ($memberships as list($hostID, $groupID)) {
            if (isset($plan[$hostID]) && $plan[$hostID] !== $groupID) {
                $leave[$groupID][] = $hostID;
            }
        }
        return $leave;
    }
    /**
     * Which directory groups these hosts are in now.
     *
     * @param array $hostIDs  the hosts
     * @param array $groupIDs the core groups that back a directory group
     *
     * @return array list of [hostID, groupID]
     */
    private static function _memberships(array $hostIDs, array $groupIDs)
    {
        if (count($hostIDs) < 1 || count($groupIDs) < 1) {
            return [];
        }
        $hosts = implode(',', array_map('intval', $hostIDs));
        $groups = implode(',', array_map('intval', $groupIDs));
        $rows = self::$DB->query(
            'SELECT `gmHostID` AS `h`, `gmGroupID` AS `g` FROM `groupMembers`'
            . " WHERE `gmHostID` IN ($hosts) AND `gmGroupID` IN ($groups)"
        )->fetch(\PDO::FETCH_ASSOC, 'fetch_all')->get();
        $pairs = [];
        foreach ((array)$rows as $row) {
            $pairs[] = [(int)$row['h'], (int)$row['g']];
        }
        return $pairs;
    }
    /**
     * The (host, core group) pairs a membership change added.
     *
     * @param string $owner   the saved object's class, Group or Host
     * @param int    $ownerID its id
     * @param array  $added   the other end's ids
     *
     * @return array list of [hostID, groupID]
     */
    public static function joins($owner, $ownerID, array $added)
    {
        $pairs = [];
        foreach ($added as $id) {
            $pairs[] = 'Host' === $owner
                ? [$ownerID, (int)$id]
                : [(int)$id, $ownerID];
        }
        return $pairs;
    }
    /**
     * Which directory group each joining host ends up in.
     *
     * Pairs whose group backs no directory group are ignored. When one save
     * adds a host to two directory groups at once, the last one wins, so the
     * answer never depends on database row order.
     *
     * @param array $joins  list of [hostID, groupID]
     * @param array $groups core group id => directory group, as
     *                      _directoryGroups() returns them
     *
     * @return array hostID => the core group id it keeps
     */
    public static function plan(array $joins, array $groups)
    {
        $keep = [];
        foreach ($joins as list($hostID, $groupID)) {
            if ($hostID > 0 && isset($groups[$groupID])) {
                $keep[$hostID] = $groupID;
            }
        }
        return $keep;
    }
    /**
     * Every directory group, keyed by the core group that backs it.
     *
     * @return array groupID => ['dn' => string, 'domain' => string]
     */
    private static function _directoryGroups()
    {
        $rows = self::$DB->query(
            'SELECT `d`.`doGroupID` AS `gid`, `d`.`doDN` AS `dn`,'
            . ' COALESCE(`dd`.`ddName`, \'\') AS `domain`'
            . ' FROM `directoryOUs` `d`'
            . ' LEFT JOIN `directoryDomains` `dd` ON `dd`.`ddID` = `d`.`doDomainID`'
            . ' WHERE `d`.`doGroupID` IS NOT NULL'
        )->fetch(\PDO::FETCH_ASSOC, 'fetch_all')->get();
        $groups = [];
        foreach ((array)$rows as $row) {
            $groups[(int)$row['gid']] = [
                'dn' => (string)$row['dn'],
                'domain' => (string)$row['domain']
            ];
        }
        return $groups;
    }
}
