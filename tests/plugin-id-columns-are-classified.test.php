<?php
/**
 * Every id column in a plugin table has been CLASSIFIED in core's map.
 *
 * fogproject's tests/foreign-key-map.test.php makes the same demand of core,
 * and says why: nobody forgets a constraint they have decided to add, what
 * gets forgotten is DECIDING. A table lands with a `somethingID` column,
 * nothing enforces the relationship, and it joins the class of orphan sources
 * ADR 0031 exists to close -- silently, and for years.
 *
 * That test cannot see this repository. It walks
 * `commons/schema-expected.php`, which describes core's 70 tables and no
 * plugin table at all, so for the whole of ADR 0031 nothing required a
 * decision about a plugin's id columns. Three had never had one:
 * `capone.cImageID`, `capone.cOSID` and `subnetgroup.sgGroupID`, found by
 * looking rather than by any gate. This is the missing half.
 *
 * WHAT COUNTS AS AN ID COLUMN. A name ending in `ID` or `Id` appearing in a
 * manager's createTableSql() field list. Across all 24 plugin tables that
 * yields exactly one false positive -- OIDCProviders.opClientID is an OIDC
 * client credential, a string, not a reference to anything -- so it is
 * bounded by a single named exception rather than by a list that could
 * quietly absorb real ones.
 *
 * THE ESCAPE IS TO CLASSIFY THE COLUMN, not to add it to $primary. Put it in
 * fogproject's commons/schema-constraints.php, including as `poly` (target
 * table named by a sibling column, so no constraint is expressible). That is
 * an answer. Absence is not.
 *
 * Like tests/foreign-keys-applied-per-plugin.test.php, the expected sets are
 * pinned here by hand because bin/fetch-plugins.sh fetches this repository on
 * its own and cannot assume a fogproject checkout is anywhere nearby. Both
 * halves have to be edited together.
 *
 * Exit status 0 = pass, 1 = fail.
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
 * Each table's OWN primary key. Not a reference, so nothing to classify.
 */
$primary = [
    'LDAPGroups.lgID', 'LDAPServers.lsID', 'OIDCGroups.ogID',
    'OIDCProviders.opID', 'capone.cID', 'helloWorld.hwID',
    'ldapGroupRoleAssoc.lgraID', 'ldapGroupUserGroupAssoc.lgugID',
    'ldapUserGrant.lugID', 'location.lID', 'locationAssoc.laID', 'ntfy.nID',
    'oidcGroupRoleAssoc.ograID', 'oidcGroupUserGroupAssoc.ogugID',
    'oidcIdentity.oiID', 'oidcUserGrant.ougID', 'ou.ouID', 'ouAssoc.oaID',
    'pushbullet.pID', 'slack.sID', 'subnetgroup.sgID', 'windowsKeys.wkID',
    'windowsKeysAssoc.wkaID', 'wolbroadcast.wbID',
];

/*
 * Classified in fogproject's commons/schema-constraints.php. A constraint is
 * declared for each of these; the group name is the plugin's directory.
 */
$classified = [
    'locationAssoc.laLocationID', 'locationAssoc.laHostID',
    'location.lStorageGroupID', 'location.lStorageNodeID',
    'ouAssoc.oaOUID', 'ouAssoc.oaHostID',
    'windowsKeysAssoc.wkaImageID', 'windowsKeysAssoc.wkaKeyID',
    'LDAPGroups.lgServerID', 'ldapGroupRoleAssoc.lgraGroupID',
    'ldapGroupRoleAssoc.lgraRoleID', 'ldapGroupUserGroupAssoc.lgugGroupID',
    'ldapGroupUserGroupAssoc.lgugUserGroupID', 'ldapUserGrant.lugUserID',
    'OIDCGroups.ogProviderID', 'oidcIdentity.oiProviderID',
    'oidcIdentity.oiUserID', 'oidcGroupRoleAssoc.ograGroupID',
    'oidcGroupRoleAssoc.ograRoleID', 'oidcGroupUserGroupAssoc.ogugGroupID',
    'oidcGroupUserGroupAssoc.ogugUserGroupID', 'oidcUserGrant.ougUserID',
    'capone.cImageID', 'capone.cOSID', 'subnetgroup.sgGroupID',
];

/*
 * Classified `poly` in that map: the target table is named by a sibling
 * column, so no foreign key is expressible. A decision, not an omission.
 */
$poly = ['ldapUserGrant.lugTargetID', 'oidcUserGrant.ougTargetID'];

/*
 * Ends in ID and is not an id. The single bounded exception; see the header.
 */
$notAReference = ['OIDCProviders.opClientID'];

$decided = array_flip(
    array_merge($primary, $classified, $poly, $notAReference)
);

/**
 * Source with comments removed, so a commented-out column cannot satisfy a
 * check or a commented-out table escape one.
 *
 * @param string $file the file to read
 *
 * @return string
 */
function idStrip($file)
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

$seen = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root)
);
foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    if (false !== strpos($path, '/tests/')) {
        continue;
    }
    // Managers live at <plugin>/src/Managers/<Class>.php under the PSR-4
    // layout (tests/plugin-layout.test.php) -- the bucket directory is what
    // that layout guarantees, not any filename suffix.
    if (basename(dirname($path)) !== 'Managers') {
        continue;
    }
    $src = idStrip($path);
    if (false === strpos($src, 'createTableSql(')) {
        continue;
    }
    if (!preg_match('/\$tablename\s*=\s*.([A-Za-z_]+)./', $src, $tm)) {
        continue;
    }
    $table = $tm[1];
    // Only the createTableSql() argument list. A column name mentioned in a
    // query elsewhere in the manager is not this table declaring it.
    $chunk = substr($src, (int)strpos($src, 'createTableSql('), 2000);
    preg_match_all('/.([A-Za-z_]+(?:ID|Id)).\s*[,\]]/', $chunk, $cm);
    foreach (array_unique($cm[1]) as $column) {
        $key = $table . '.' . $column;
        $seen[$key] = true;
        $checks++;
        if (!isset($decided[$key])) {
            $failures[] = "$key has never been classified."
                . ' Add it to fogproject commons/schema-constraints.php --'
                . ' as a constraint, or as `poly` if a sibling column names'
                . ' its table -- and pin it in this test. An id column with'
                . ' no decision is how a dangling reference ships';
        }
    }
}

// The inverse: a pinned entry whose column is gone is a stale pin, and a
// stale pin is how this test quietly stops covering the thing it names.
foreach (array_keys($decided) as $key) {
    $checks++;
    if (!isset($seen[$key])) {
        $failures[] = "$key is pinned in this test but no plugin table"
            . ' declares it. Remove the pin, or restore the column';
    }
}

// A regex that matches nothing passes every check above. Anchor the sweep on
// a count that a broken extraction cannot reach.
$checks++;
if (count($seen) < 50) {
    $failures[] = sprintf(
        'only %d id column(s) were found across the plugin managers, so the'
        . ' checks above proved almost nothing -- the extraction is broken',
        count($seen)
    );
}

if (count($failures)) {
    echo 'FAIL: ' . count($failures) . " problem(s).\n\n";
    foreach ($failures as $f) {
        echo "  $f\n";
    }
    exit(1);
}

printf(
    "plugin-id-columns-are-classified: %d checks passed, %d id column(s)\n",
    $checks,
    count($seen)
);
exit(0);
