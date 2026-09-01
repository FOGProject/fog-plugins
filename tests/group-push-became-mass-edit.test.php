<?php
/**
 * The location and ou plugins no longer push a value across a group.
 *
 * Both shipped a second hook file -- AddLocationGroup, AddOUGroup -- whose
 * only job was to set one value on every member of a group. ADR 0038
 * decision 13 replaces them with a HOST_MASSEDIT_* contribution, and the
 * reason is not tidiness:
 *
 *   1. It was a COPY, not a grant. The hook read the membership at the
 *      instant the button was pressed and wrote a row per member, so a host
 *      added to the group afterward got nothing and a host removed kept what
 *      it had.
 *   2. It ALWAYS CLOBBERED. Every save ran a deletemass over every member
 *      before inserting, so there was no way to express "leave this host
 *      alone" -- the form had no such state to offer. Saving the tab to
 *      change one host's location wiped it from all of them.
 *
 * They are not grants because a location and an OU are single-valued per
 * host: the write path has always been delete-then-insert-one. A grant is a
 * SET that several groups union into, and unioning two locations means
 * nothing.
 *
 * WHAT THIS DRIVES:
 *
 *   1. Neither plugin ships a group hook again, and no hook writes an
 *      association across a group's membership. Re-adding one would restore
 *      both defects at once and would look, from the group page, exactly
 *      like the tab that used to be there.
 *   2. Each host hook registers BOTH mass-edit events. Registering only the
 *      field event ships a control that renders, submits, and silently does
 *      nothing.
 *   3. 'leave' writes nothing at all -- not an empty delete, not a no-op
 *      insert. This is the whole point of the three-state model, and the one
 *      state the old hook could not express.
 *   4. 'set' with an id that does not exist THROWS. Treating it as a clear
 *      would strip the value off every selected host, which is the old
 *      clobber arriving by a new route.
 *   5. 'clear' deletes and inserts nothing.
 *   6. The value control is rendered empty and named `value[<key>]`, and no
 *      plugin draws its own action control -- a two-state field in a mass
 *      edit is the defect the design exists to prevent.
 *
 * Usage: php tests/group-push-became-mass-edit.test.php
 * Exit status 0 = pass, 1 = fail.
 */

$root = dirname(__DIR__);
$fails = [];

if (!function_exists('_')) {
    /**
     * Stand-in for gettext.
     *
     * @param string $s the string
     *
     * @return string
     */
    function _($s)
    {
        return $s;
    }
}

/**
 * Records a failure.
 *
 * @param string $why what went wrong
 *
 * @return void
 */
function fail($why)
{
    global $fails;
    $fails[] = $why;
}

/**
 * Asserts a condition.
 *
 * @param string $label what is being checked
 * @param bool   $cond  the condition
 *
 * @return bool
 */
function check($label, $cond)
{
    if (!$cond) {
        fail($label);
    }

    return (bool)$cond;
}

require_once __DIR__ . '/stubs/fog-stubs.php';

// ------------------------------------------------------- 1. the hooks are gone

$gone = [
    'location/src/Hooks/AddLocationGroup.php',
    'ou/src/Hooks/AddOUGroup.php',
    'location/js/fog.location.group.edit.js',
    'ou/js/fog.ou.group.edit.js',
];
foreach ($gone as $rel) {
    check(
        "$rel is not shipped any more",
        !file_exists($root . '/' . $rel)
    );
}

// No hook anywhere may write an association over a group's membership again.
// Both old hooks did it the same way: resolve the members, then deletemass.
$hookFiles = [];
foreach (glob($root . '/*/src/Hooks/*.php') as $file) {
    $hookFiles[$file] = (string)file_get_contents($file);
}
foreach ($hookFiles as $file => $src) {
    $code = preg_replace('#/\*.*?\*/#s', '', $src);
    $code = (string)preg_replace('#//[^\n]*#', '', (string)$code);
    $readsMembers = (
        false !== strpos($code, "get('hosts')")
        || false !== strpos($code, "'groupassociation'")
    );
    if (!$readsMembers) {
        continue;
    }
    check(
        basename($file) . ' does not deletemass over a group membership',
        false === strpos($code, 'deletemass')
    );
}

// ------------------------------------------- 2. both events, on both plugins

$cases = [
    [
        'file'  => 'location/src/Hooks/AddLocationHost.php',
        'class' => 'FOG\Plugins\Location\Hooks\AddLocationHost',
        'key'   => 'location',
        'assoc' => 'locationassociation',
        'col'   => 'locationID',
    ],
    [
        'file'  => 'ou/src/Hooks/AddOUHost.php',
        'class' => 'FOG\Plugins\OU\Hooks\AddOUHost',
        'key'   => 'ou',
        'assoc' => 'ouassociation',
        'col'   => 'ouID',
    ],
];

foreach ($cases as $case) {
    require_once $root . '/' . $case['file'];
    $hook = new $case['class']();
    $key = $case['key'];

    $events = [];
    foreach ($hook->registered as $pair) {
        $events[] = $pair[0];
    }
    check(
        "$key registers HOST_MASSEDIT_FIELDS",
        in_array('HOST_MASSEDIT_FIELDS', $events, true)
    );
    check(
        "$key registers HOST_MASSEDIT_APPLY",
        in_array('HOST_MASSEDIT_APPLY', $events, true)
    );

    // ------------------------------------------------- 3. the field it offers

    \FOG\Util\SharedHostValues::$rows = [
        $key => ['uniform' => false, 'value' => ''],
    ];
    $fields = [];
    $hostIDs = [1, 2, 3];
    $args = ['fields' => &$fields, 'hostIDs' => &$hostIDs];
    $hook->massEditFields($args);

    if (check("$key contributes a field", isset($fields[$key]))) {
        $field = $fields[$key];
        check(
            "$key names its control value[$key]",
            false !== strpos((string)$field['input'], 'name="value[' . $key . ']"')
        );
        check(
            "$key reports a mixed selection as (varies)",
            '(varies)' === $field['hint']
        );
        // Core draws the action control. A plugin drawing its own could ship
        // a two-state field, which is the defect this design prevents.
        check(
            "$key does not draw its own action control",
            false === strpos((string)$field['input'], 'action[')
        );
        // Rendered empty: there is no honest value when the selection differs.
        check(
            "$key renders its control with no value preselected",
            false === strpos((string)$field['input'], 'selected')
        );
    }

    // A uniform selection reports the NAME, not the raw id.
    \FOG\Util\SharedHostValues::$rows = [
        $key => ['uniform' => true, 'value' => '7'],
    ];
    $fields = [];
    $args = ['fields' => &$fields, 'hostIDs' => &$hostIDs];
    $hook->massEditFields($args);
    check(
        "$key reports a uniform selection by name rather than by id",
        isset($fields[$key]) && 'StubName' === $fields[$key]['hint']
    );

    // ------------------------------------------------------- 4. applying it

    /**
     * Runs one apply and returns what it wrote.
     *
     * @param object $hook   the hook
     * @param string $key    the field key
     * @param array  $action the resolved instruction
     *
     * @return array [deletes, batches, threw]
     */
    $apply = function ($hook, $key, $action) {
        \FOG\Router\Route::$deleted = [];
        $manager = new \FOG\Base\StubItem();
        \FOG\Base\Hook::$classes = [
            'LocationAssociationManager' => $manager,
            'OUAssociationManager' => $manager,
        ];
        if (isset($action['invalid'])) {
            \FOG\Base\Hook::$classes['Location'] = function ($id) {
                $item = new \FOG\Base\StubItem($id);
                $item->valid = false;

                return $item;
            };
            \FOG\Base\Hook::$classes['OU'] =
                \FOG\Base\Hook::$classes['Location'];
        }
        $hostIDs = [1, 2, 3];
        $actions = [$key => $action];
        $args = ['hostIDs' => &$hostIDs, 'actions' => &$actions];
        $threw = false;
        try {
            $hook->massEditApply($args);
        } catch (\Exception $e) {
            $threw = true;
        }
        \FOG\Base\Hook::$classes = [];

        return [\FOG\Router\Route::$deleted, $manager->batches, $threw];
    };

    // 'leave' writes NOTHING. The state the old hook could not express.
    list($deleted, $batches, $threw) = $apply(
        $hook,
        $key,
        ['action' => 'leave', 'value' => '']
    );
    check("$key leave deletes nothing", 0 === count($deleted));
    check("$key leave inserts nothing", 0 === count($batches));
    check("$key leave does not throw", !$threw);

    // 'set' replaces: one delete over the selection, one row per host.
    list($deleted, $batches, $threw) = $apply(
        $hook,
        $key,
        ['action' => 'set', 'value' => '5']
    );
    check("$key set does not throw", !$threw);
    if (check("$key set deletes once", 1 === count($deleted))) {
        check(
            "$key set deletes from " . $case['assoc'],
            $case['assoc'] === $deleted[0][0]
        );
        check(
            "$key set scopes the delete to the selection",
            isset($deleted[0][1]['hostID'])
            && [1, 2, 3] === $deleted[0][1]['hostID']
        );
    }
    if (check("$key set inserts once", 1 === count($batches))) {
        check(
            "$key set names hostID and " . $case['col'],
            ['hostID', $case['col']] === $batches[0][0]
        );
        check(
            "$key set writes one row per selected host",
            3 === count($batches[0][1])
            && [1, 5] === $batches[0][1][0]
        );
    }

    // 'set' naming something that does not exist THROWS. Falling through to
    // the delete would clear the value on every selected host.
    list($deleted, $batches, $threw) = $apply(
        $hook,
        $key,
        ['action' => 'set', 'value' => '99', 'invalid' => true]
    );
    check("$key set of a missing record throws", $threw);
    check(
        "$key set of a missing record deletes nothing",
        0 === count($deleted)
    );
    check(
        "$key set of a missing record inserts nothing",
        0 === count($batches)
    );

    // 'clear' deletes and inserts nothing.
    list($deleted, $batches, $threw) = $apply(
        $hook,
        $key,
        ['action' => 'clear', 'value' => '']
    );
    check("$key clear does not throw", !$threw);
    check("$key clear deletes once", 1 === count($deleted));
    check("$key clear inserts nothing", 0 === count($batches));

    // An empty selection is not an instruction to delete everything.
    \FOG\Router\Route::$deleted = [];
    $none = [];
    $actions = [$key => ['action' => 'set', 'value' => '5']];
    $args = ['hostIDs' => &$none, 'actions' => &$actions];
    $hook->massEditApply($args);
    check(
        "$key writes nothing when the selection is empty",
        0 === count(\FOG\Router\Route::$deleted)
    );
}

if (count($fails)) {
    echo "FAIL (" . count($fails) . "):\n";
    foreach ($fails as $why) {
        echo "  - $why\n";
    }
    exit(1);
}

echo "ok  group push became mass edit\n";
exit(0);
