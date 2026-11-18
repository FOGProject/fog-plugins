<?php
/**
 * The location hooks keep FOG's own group and node when the location's
 * group cannot give one.
 *
 * Forums 18310: a location's Storage Group had no nodes. On a task boot,
 * storageGroupSetting() handed that group to the boot menu, the core asked
 * it for a node, and "No nodes available" stopped the boot with an HTTP
 * 500. storageNodeSetting() would also have handed on an empty node, and a
 * group with no master threw from getMasterStorageNode() for a capture.
 *
 * Pinned here, against the real hook, with a Location double:
 *  - storageGroupSetting() skips a group with no nodes, and still sets a
 *    group that has nodes;
 *  - storageNodeSetting() keeps the node it was given when the location's
 *    node is empty, and still sets the location's node when it is valid;
 *  - a capture whose group throws from getMasterStorageNode() keeps the
 *    node it was given, with no exception.
 *
 * Usage: php tests/location-hooks-keep-core-node.test.php
 * Exit status 0 = pass, 1 = fail.
 */

namespace FOG\Plugins\Location\Items {
    /**
     * Location double: the hook builds `new Location($id)` itself, so the
     * test supplies the class and looks each id up in a table.
     */
    class Location
    {
        /**
         * Doubles by id: ['group' => object, 'node' => object].
         *
         * @var array
         */
        public static $byId = [];
        /**
         * This double's row.
         *
         * @var array
         */
        private $_row;
        /**
         * Looks the id up.
         *
         * @param int $id the location id
         */
        public function __construct($id)
        {
            $this->_row = self::$byId[$id];
        }
        /**
         * Always valid.
         *
         * @return bool
         */
        public function isValid()
        {
            return true;
        }
        /**
         * A field.
         *
         * @param string $key the field
         *
         * @return mixed
         */
        public function get($key)
        {
            $fields = ['id' => 7, 'name' => 'Adamsville', 'protocol' => 'http'];
            return $fields[$key] ?? '';
        }
        /**
         * The location's group.
         *
         * @return object
         */
        public function getStorageGroup()
        {
            return $this->_row['group'];
        }
        /**
         * The location's node.
         *
         * @return object
         */
        public function getStorageNode()
        {
            return $this->_row['node'];
        }
        /**
         * Every problem the hook logged.
         *
         * @var array
         */
        public static $logged = [];
        /**
         * Records the problem instead of writing it.
         *
         * @param string $problem what is wrong
         *
         * @return void
         */
        public static function logOnce($problem)
        {
            self::$logged[] = $problem;
        }
    }
}

namespace {
    $root = dirname(__DIR__);

    require $root . '/tests/stubs/fog-stubs.php';
    require $root . '/location/src/Hooks/LocationChangeItems.php';

    /**
     * Storage group double.
     */
    class KeepCoreNodeGroup
    {
        /**
         * Node ids in the group.
         *
         * @var array
         */
        public $nodes = [];
        /**
         * The node getMasterStorageNode() returns, or null to throw.
         *
         * @var object|null
         */
        public $master;
        /**
         * Always valid.
         *
         * @return bool
         */
        public function isValid()
        {
            return true;
        }
        /**
         * A field.
         *
         * @param string $key the field
         *
         * @return mixed
         */
        public function get($key)
        {
            return 'allnodes' === $key ? $this->nodes : '';
        }
        /**
         * The master node, or the core's exception.
         *
         * @return object
         */
        public function getMasterStorageNode()
        {
            if (null === $this->master) {
                throw new \Exception('No master nodes available');
            }
            return $this->master;
        }
    }

    /**
     * Host or task double.
     */
    class KeepCoreNodeThing
    {
        /**
         * Fields get() returns.
         *
         * @var array
         */
        public $fields = [];
        /**
         * Whether this is a capture.
         *
         * @var bool
         */
        public $capture = false;
        /**
         * Always valid.
         *
         * @return bool
         */
        public function isValid()
        {
            return true;
        }
        /**
         * A field.
         *
         * @param string $key the field
         *
         * @return mixed
         */
        public function get($key)
        {
            return $this->fields[$key] ?? '';
        }
        /**
         * Whether this is a capture.
         *
         * @return bool
         */
        public function isCapture()
        {
            return $this->capture;
        }
        /**
         * Never multicast here.
         *
         * @return bool
         */
        public function isMulticast()
        {
            return false;
        }
        /**
         * A field write, as the node's location_url.
         *
         * @param string $name  the property
         * @param mixed  $value the value
         *
         * @return void
         */
        public function __set($name, $value)
        {
            $this->fields[$name] = $value;
        }
    }

    $fails = [];
    $check = function ($label, $ok) use (&$fails) {
        echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
        if (!$ok) {
            $fails[] = $label;
        }
    };

    ini_set('error_log', '/dev/null');
    \FOG\Router\Route::$lists['locationassociation'] = [(object)['locationID' => 7]];

    $task = new KeepCoreNodeThing();
    $host = new KeepCoreNodeThing();
    $host->fields['task'] = $task;
    $empty = new KeepCoreNodeThing();
    $empty->fields['id'] = 0;
    $emptyNode = new class {
        /**
         * Invalid, as an id-less node is.
         *
         * @return bool
         */
        public function isValid()
        {
            return false;
        }
    };
    $coreNode = new KeepCoreNodeThing();
    $coreNode->fields['ip'] = '10.0.0.1';
    $hook = new \FOG\Plugins\Location\Hooks\LocationChangeItems();

    // A group with no nodes is not handed to the boot menu.
    $group = new KeepCoreNodeGroup();
    \FOG\Plugins\Location\Items\Location::$byId[7] = ['group' => $group, 'node' => $emptyNode];
    $StorageGroup = null;
    $args = ['Host' => $host, 'StorageGroup' => &$StorageGroup];
    $hook->storageGroupSetting($args);
    $check('a group with no nodes is not set', null === $StorageGroup);
    $check(
        'a group with no nodes is logged',
        1 === count(\FOG\Plugins\Location\Items\Location::$logged)
    );

    // A group with nodes still is.
    $group->nodes = [4, 5];
    $hook->storageGroupSetting($args);
    $check('a group with nodes is set', $group === $StorageGroup);
    $check(
        'a group with nodes is not logged',
        1 === count(\FOG\Plugins\Location\Items\Location::$logged)
    );

    // An empty location node keeps the node FOG chose.
    $StorageNode = $coreNode;
    $args = ['Host' => $host, 'StorageNode' => &$StorageNode];
    $hook->storageNodeSetting($args);
    $check('an empty location node keeps the given node', $coreNode === $StorageNode);

    // A valid location node still replaces it.
    $locNode = new KeepCoreNodeThing();
    $locNode->fields = ['ip' => '10.0.0.4', 'webroot' => 'fog'];
    \FOG\Plugins\Location\Items\Location::$byId[7]['node'] = $locNode;
    $StorageNode = $coreNode;
    $hook->storageNodeSetting($args);
    $check('a valid location node is set', $locNode === $StorageNode);

    // A capture whose group has no master keeps the given node.
    \FOG\Plugins\Location\Items\Location::$byId[7]['node'] = $emptyNode;
    $task->capture = true;
    $StorageNode = $coreNode;
    $threw = false;
    try {
        $hook->storageNodeSetting($args);
    } catch (\Throwable $e) {
        $threw = true;
    }
    $check('a capture with no master does not throw', !$threw);
    $check('a capture with no master keeps the given node', $coreNode === $StorageNode);
    $last = end(\FOG\Plugins\Location\Items\Location::$logged);
    $check(
        'a capture with no master is logged',
        is_string($last) && false !== strpos($last, 'No master nodes available')
    );

    if ($fails) {
        echo 'FAIL (' . count($fails) . ")\n";
        exit(1);
    }
    echo "ok\n";
    exit(0);
}
