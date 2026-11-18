<?php
/**
 * A location whose group gives no node returns an empty node, not a fatal.
 *
 * Forums 18310: a location had "Boot files from" on, no Storage Node, and a
 * Storage Group with no nodes. Location::getStorageNode() asked the group,
 * the core threw "No nodes available", and nothing caught it. Every PXE boot
 * of the location's hosts ended in an HTTP 500, so they showed as not
 * registered.
 *
 * Pinned here, against the real Location class:
 *  - a group that throws gives an empty (invalid) node and one log line
 *    naming the location;
 *  - a group that answers gives its node, as before;
 *  - a Storage Node set on the location wins, and the group is not asked.
 *
 * Usage: php tests/location-node-from-empty-group.test.php
 * Exit status 0 = pass, 1 = fail.
 */

$root = dirname(__DIR__);

require $root . '/tests/stubs/fog-stubs.php';
require $root . '/location/src/Items/Location.php';

/**
 * A storage group that answers with a node, or throws as the core does.
 */
class EmptyGroupTestGroup
{
    /**
     * The node to return, or null to throw.
     *
     * @var object|null
     */
    public $node;
    /**
     * How often the group was asked.
     *
     * @var int
     */
    public $asked = 0;
    /**
     * The core's least-loaded pick.
     *
     * @return object
     */
    public function getOptimalStorageNode()
    {
        $this->asked++;
        if (null === $this->node) {
            throw new \Exception('No nodes available');
        }
        return $this->node;
    }
}

$fails = [];
$check = function ($label, $ok) use (&$fails) {
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
    if (!$ok) {
        $fails[] = $label;
    }
};

// The empty node the fix returns. The stub autoloader makes StorageNode a
// proxy; point it at an item that reports invalid, as an id-less node does.
\FOG\Base\Hook::$classes['StorageNode'] = function () {
    $node = new \FOG\Base\StubItem();
    $node->valid = false;
    return $node;
};

$log = tempnam(sys_get_temp_dir(), 'loc');
ini_set('error_log', $log);

// A group with no nodes.
$group = new EmptyGroupTestGroup();
$location = (new \FOG\Plugins\Location\Items\Location())
    ->set('id', 7)
    ->set('name', 'Adamsville')
    ->set('storagegroupID', 2)
    ->set('storagenodeID', '')
    ->set('storagegroup', $group);
$threw = false;
$node = null;
try {
    $node = $location->getStorageNode();
} catch (\Throwable $e) {
    $threw = true;
}
$check('a group with no nodes does not throw', !$threw);
$check('a group with no nodes gives an invalid node', $node && !$node->isValid());
$logged = (string)@file_get_contents($log);
$check(
    'the log line names the location and the group',
    false !== strpos($logged, 'location "Adamsville" (id 7)')
    && false !== strpos($logged, 'Storage Group (id 2)')
);
// The boot menu fires the hooks several times per boot: one line, not many.
$location->getStorageNode();
$check(
    'the same problem is logged once per request',
    1 === substr_count((string)@file_get_contents($log), 'FOG location:')
);

// A group that answers.
$good = new \FOG\Base\StubItem(4);
$group->node = $good;
$check('a group with a node gives that node', $good === $location->getStorageNode());

// A node set on the location wins.
$pinned = new \FOG\Base\StubItem(9);
$location->set('storagenodeID', 9)->set('storagenode', $pinned);
$group->asked = 0;
$check('a set Storage Node wins', $pinned === $location->getStorageNode());
$check('a set Storage Node does not ask the group', 0 === $group->asked);

@unlink($log);
if ($fails) {
    echo 'FAIL (' . count($fails) . ")\n";
    exit(1);
}
echo "ok\n";
exit(0);
