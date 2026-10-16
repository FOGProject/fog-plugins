<?php
/**
 * A host is in one directory group, and the hosts live in core groups.
 *
 * Each directory group is backed by a core group (GH-1879 follow-up), so its
 * hosts are groupMembers rows and every core grant reaches them. Core fires
 * ASSOCIATION_UPDATED whichever page saved a membership, and
 * AddDirectoryGroupsMembership keeps the rule an OU needs: one per host.
 *
 * What this pins:
 * - the listener reads both directions of the event: a Group saved with new
 *   hosts, and a Host saved with new groups;
 * - only core groups that back a directory group count;
 * - a host added to two directory groups in one save keeps the last one;
 * - a host leaves every other directory group it is in, and nothing else;
 * - the schema change is appended (fogproject installdb() skips the steps
 *   an install has passed), and directoryOUAssoc is dropped only after its
 *   rows are copied.
 *
 * Requires no database.
 *
 * PHP version 7.4+
 *
 * @category Tests
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

$root = dirname(__DIR__);
$failures = [];
$checks = 0;

/**
 * Asserts a condition.
 *
 * @param string $label what is being checked
 * @param bool   $cond  the condition
 *
 * @return void
 */
function check($label, $cond)
{
    global $failures, $checks;
    $checks++;
    if (!$cond) {
        $failures[] = $label;
    }
}

require_once __DIR__ . '/stubs/fog-stubs.php';
require_once $root . '/directorygroups/src/Hooks/AddDirectoryGroupsMembership.php';

use FOG\Plugins\DirectoryGroups\Hooks\AddDirectoryGroupsMembership as M;

// ------------------------------------------------------- 1. both directions

check(
    'a Group saved with hosts 4 and 5 joins each host to that group',
    [[4, 9], [5, 9]] === M::joins('Group', 9, [4, 5])
);
check(
    'a Host saved with groups 9 and 10 joins that host to each group',
    [[4, 9], [4, 10]] === M::joins('Host', 4, [9, 10])
);

// ---------------------------------------------------------------- 2. plan

// Core groups 9 and 10 back directory groups; 11 is an ordinary group.
$groups = [
    9 => ['dn' => 'OU=Sales,DC=lab,DC=local', 'domain' => 'lab.local'],
    10 => ['dn' => 'OU=Labs,DC=lab,DC=local', 'domain' => 'lab.local'],
];
check(
    'joining an ordinary core group plans nothing',
    [] === M::plan([[4, 11]], $groups)
);
check(
    'joining a directory group keeps that group for the host',
    [4 => 9] === M::plan([[4, 9], [4, 11]], $groups)
);
check(
    'two directory groups in one save: the last one wins',
    [4 => 10] === M::plan(M::joins('Host', 4, [9, 10]), $groups)
);

// -------------------------------------------------------------- 3. leaves

check(
    'a host leaves the directory group it no longer keeps',
    [9 => [4]] === M::leaves([4 => 10], [[4, 9], [4, 10]])
);
check(
    'a host is not removed from the group it keeps',
    [] === M::leaves([4 => 9], [[4, 9]])
);
check(
    'a host that did not join anything is left alone',
    [] === M::leaves([4 => 9], [[5, 10]])
);
check(
    'hosts leaving the same group are removed in one batch',
    [9 => [4, 5]] === M::leaves([4 => 10, 5 => 10], [[4, 9], [5, 9]])
);

// -------------------------------------------------------------- 4. schema

$manager = (string)file_get_contents(
    $root . '/directorygroups/src/Managers/DirectoryGroupsManager.php'
);
check(
    'doGroupID is added by its own ALTER step, not folded into the'
        . ' released CREATE of step 1',
    false !== strpos($manager, 'ADD COLUMN `doGroupID`')
);
$move = substr($manager, (int)strpos($manager, 'function moveToCoreGroups'));
$copy = strpos($move, 'INSERT IGNORE INTO `groupMembers`');
$drop = strpos($move, 'DirectoryGroupsAssociationManager');
check(
    'directoryOUAssoc is dropped only after its rows reach groupMembers',
    false !== $copy && false !== $drop && $copy < $drop
);

if (count($failures)) {
    echo 'FAIL: ' . count($failures) . " of $checks checks failed.\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "directorygroups-membership: $checks checks passed\n";
