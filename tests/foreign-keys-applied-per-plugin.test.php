<?php
/**
 * Every plugin whose tables carry a foreign key must sweep before it adds.
 *
 * fogproject ADR 0031. The relationships themselves are declared in
 * fogproject's `packages/web/commons/schema-constraints.php` -- half of them
 * point at core tables, and that map is meant to answer "what points at
 * hosts?" from one file. What lives HERE is the act of applying them: one
 * step appended to the plugin manager's schema(), passing the plugin's own
 * group name.
 *
 * Two things can go wrong and neither is visible at runtime:
 *
 * - **Adding without sweeping.** `ADD CONSTRAINT` validates the rows already
 *   in the table and answers 1452 if any of them point at a parent that is
 *   gone. applyConstraints() reports a refusal rather than returning it, so
 *   the update succeeds, the constraint is simply never created, and the
 *   plugin ships claiming integrity it does not have.
 * - **Passing the wrong group, or none.** A missing group applies EVERY
 *   enabled relationship in the map, core's included, from inside a plugin
 *   install. The wrong group applies someone else's.
 *
 * This repository is fetched on its own (bin/fetch-plugins.sh) and cannot
 * assume a fogproject checkout is anywhere nearby, so the expected set below
 * is pinned here by hand rather than read from that map. Both halves have to
 * be edited together; fogproject's tests/foreign-key-map.test.php pins the
 * same set from the other side.
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

/*
 * Plugin directory => the manager file that owns its schema() and the group
 * name it must pass.
 *
 * The group is the plugin's own directory name, and it is a STRING where
 * core's groups are ints. That is not decoration: planConstraints() and
 * planSweep() both select on ===, and PHP 7.4 -- FOG's floor -- calls
 * `5 == 'ldap'` true. A plugin group written as a number would be applied by
 * a core schema step, against tables core has not swept.
 *
 * ADD A ROW HERE IN THE SAME COMMIT THAT LANDS A PLUGIN'S STEP.
 */
$expected = [
    'location' => 'location/class/locationmanager.class.php',
    'ou' => 'ou/class/oumanager.class.php',
    'windowskey' => 'windowskey/class/windowskeymanager.class.php',
];

/**
 * Source with comments removed, so a commented-out call cannot satisfy a
 * check.
 *
 * @param string $file the file to read
 *
 * @return string
 */
function fkStrip($file)
{
    $clean = '';
    foreach (token_get_all((string)file_get_contents($file)) as $token) {
        if (is_array($token)
            && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)
        ) {
            continue;
        }
        $clean .= is_array($token) ? $token[1] : $token;
    }

    return $clean;
}

foreach ($expected as $plugin => $relative) {
    $file = $root . '/' . $relative;
    $checks++;
    if (!is_file($file)) {
        $failures[] = "$plugin: $relative does not exist";
        continue;
    }
    // Whitespace stripped as well as comments. A call is the same call
    // whether or not its argument was wrapped to keep the line under 80,
    // and the first version of this test failed a perfectly good
    // applyConstraints( \n 'windowskey' \n ) on formatting alone.
    $src = preg_replace('/\s+/', '', fkStrip($file));

    $sweep = "SchemaReconciler::sweepOrphans('$plugin')";
    $apply = "SchemaReconciler::applyConstraints('$plugin')";

    $checks++;
    $sweepAt = strpos($src, $sweep);
    if (false === $sweepAt) {
        $failures[] = "$plugin: schema() never calls $sweep. Adding a"
            . ' constraint to a table holding orphans answers 1452 and the'
            . ' constraint is silently never created';
    }

    $checks++;
    $applyAt = strpos($src, $apply);
    if (false === $applyAt) {
        $failures[] = "$plugin: schema() never calls $apply, so its"
            . ' relationships are declared in the map and never applied';
    }

    // Order, not just presence. Sweeping after adding is the same as not
    // sweeping at all.
    $checks++;
    if (false !== $sweepAt && false !== $applyAt && $sweepAt > $applyAt) {
        $failures[] = "$plugin: sweeps AFTER it adds, which is the same as"
            . ' not sweeping';
    }

    // Neither call may be made unfiltered. applyConstraints() with no group
    // applies every enabled relationship in the map -- core's included --
    // from inside a plugin install.
    $checks++;
    if (strpos($src, 'SchemaReconciler::applyConstraints()') !== false
        || strpos($src, 'SchemaReconciler::sweepOrphans()') !== false
    ) {
        $failures[] = "$plugin: calls applyConstraints() or sweepOrphans()"
            . " with no group; that reaches core's relationships too";
    }

    // Qualification is NOT checked here. core-references-are-qualified.php
    // already does it generically, for every bare class name in the tree,
    // and better -- a check written here would pass as long as ONE of the
    // two calls kept its namespace.
}

/*
 * location.lStorageNodeID has to be nullable, and a fresh install has to
 * build it that way.
 *
 * The column is a tri-state -- Location::getStorageNode() returns the named
 * node when it is truthy and falls through to the storage group's optimal
 * node when it is not -- and it spelled "no node" as 0. A foreign key
 * accepts NULL for "no reference" and nothing else, so a constraint over a
 * column still holding 0 demands a storage node with ngmID = 0 and refuses
 * every location that has not pinned one.
 *
 * schema() step 4 converts an existing install. This checks the OTHER half:
 * createSql() builds the column nullable, so a fresh install does not have
 * to be migrated the moment it is created. The two are easy to separate --
 * step 4 alone passes every upgrade test and fails only on a brand new
 * server.
 */
$src = fkStrip($root . '/location/class/locationmanager.class.php');
$tight = preg_replace('/\s+/', '', $src);
$checks++;
if (strpos($tight, "MODIFYCOLUMN`lStorageNodeID`") === false
    || strpos($tight, 'NULLDEFAULTNULL') === false
) {
    $failures[] = 'location: schema() does not make lStorageNodeID nullable;'
        . ' a foreign key cannot accept its 0 sentinel';
}
$checks++;
if (strpos($tight, "SET`lStorageNodeID`=NULL") === false) {
    $failures[] = 'location: schema() does not convert lStorageNodeID = 0 to'
        . ' NULL, so existing rows still name a storage node that does not'
        . ' exist';
}

/*
 * The fresh-install half, read out of createSql() rather than grepped for.
 *
 * createTableSql() takes parallel arrays -- fields, types, nulls, defaults --
 * and renders NOT NULL for a `nulls` entry that is `=== false`. So the check
 * is positional: find lStorageNodeID's index in the field list and require
 * the matching nulls entry to be true. A grep for "true" anywhere in the
 * method would pass on the `$exists` argument.
 */
$body = '';
$at = strpos($src, 'function createSql(');
if (false !== $at) {
    $open = strpos($src, '{', $at);
    $depth = 0;
    for ($i = $open, $n = strlen($src); $i < $n; $i++) {
        if ($src[$i] === '{') {
            $depth++;
        }
        if ($src[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                $body = substr($src, $open, $i - $open + 1);
                break;
            }
        }
    }
}
$checks++;
if (!preg_match_all('/\[([^\[\]]*)\]/s', $body, $m)) {
    $failures[] = 'location: could not read createSql()\'s argument arrays';
} else {
    $fields = null;
    $nulls = null;
    foreach ($m[1] as $group) {
        $items = array_map('trim', explode(',', $group));
        $items = array_values(array_filter($items, 'strlen'));
        if (null === $fields && in_array("'lStorageNodeID'", $items, true)) {
            $fields = $items;
            continue;
        }
        if (null !== $fields
            && null === $nulls
            && count($items) === count($fields)
            && $items === array_map(
                static function ($v) {
                    return in_array($v, ['true', 'false'], true) ? $v : null;
                },
                $items
            )
        ) {
            $nulls = $items;
            break;
        }
    }
    $index = null === $fields
        ? false
        : array_search("'lStorageNodeID'", $fields, true);
    if (false === $index || null === $nulls) {
        $failures[] = 'location: could not locate lStorageNodeID in'
            . ' createSql()\'s field and nulls arrays';
    } elseif (($nulls[$index] ?? '') !== 'true') {
        $failures[] = 'location: createSql() builds lStorageNodeID NOT NULL,'
            . ' so a fresh install cannot take its foreign key';
    }
}

if (count($failures)) {
    echo 'FAIL: ' . count($failures) . " problem(s).\n\n";
    foreach ($failures as $f) {
        echo "  $f\n";
    }
    exit(1);
}

printf(
    "foreign-keys-applied-per-plugin: %d checks passed, %d plugin(s)\n",
    $checks,
    count($expected)
);
