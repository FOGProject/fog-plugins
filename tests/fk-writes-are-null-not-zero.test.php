<?php
/**
 * No plugin writes the 0 sentinel into a column that carries a foreign key.
 *
 * THE BUG THIS EXISTS FOR, in core first. `massEditCoreFields()` declared the
 * Image field as clearing to `0`. `hosts`.`hostImage` has a foreign key to
 * `images`.`imageID`, and 0 is not exempt from a constraint just because it
 * reads as an absence -- no image has id 0, so "Clear on all" on Image was
 * refused by the database outright. The schema half of that has been gated
 * for a while: foreign-keys-applied-per-plugin.test.php already requires
 * `location`.`lStorageNodeID` to be nullable and its legacy 0s swept. What
 * nothing gated was the WRITE half -- the column can be perfectly nullable
 * and the code can still put a 0 in it.
 *
 * It found one. LocationDeleteMassItems::deletemassitems() cleaned up after
 * a deleted storage node with
 *
 *     ->update(['storagenodeID' => $ids], '', 0)
 *
 * where the third argument is the UPDATE DATA. FOGManagerController::update()
 * accepts an associative array or an array of them and returns false for
 * anything else, so the call had always been a silent no-op -- and had it
 * ever worked, 0 is precisely the value the constraint now refuses.
 *
 * WHAT IS CHECKED. Three plugin columns are constrained by core's
 * commons/schema-constraints.php, and their field names come from each
 * plugin's own $databaseFields:
 *
 *     capone.cImageID       -> Capone::imageID
 *     capone.cOSID          -> Capone::osID
 *     location.lStorageNodeID -> Location::storagenodeID
 *
 * (subnetgroup.sgGroupID is classified `poly` and takes no constraint.)
 *
 * For each, no write of a literal 0 or empty string, and no update() whose
 * data argument is a bare scalar -- the shape that made the original a
 * no-op, and which no caller can ever have meant.
 *
 * PINNED BY HAND, like its two sibling tests, and for the same reason they
 * state: bin/fetch-plugins.sh fetches this repository on its own and cannot
 * assume a fogproject checkout is anywhere nearby, so the constraint file
 * cannot be read. Adding a constrained plugin column means adding it here.
 *
 * Usage: php tests/fk-writes-are-null-not-zero.test.php
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
$checks = 0;
$failures = [];

// Every plugin-owned column that core's schema-constraints.php constrains,
// column => [plugin, Item class, field name as that Item's $databaseFields
// keys it].
//
// The `config` ones at the top are where a 0 sentinel was ever plausible --
// a nullable reference whose "none" used to be 0. The `junction` ones below
// cannot hold a sentinel by construction (an association row either exists or
// it does not), but they are covered anyway: a 0 in one of them is an orphan
// naming a row that does not exist, which is the same refusal by the same
// constraint, and enumerating only the "risky" half is how the next one gets
// missed.
//
// ldapUserGrant.lugTargetID and oidcUserGrant.ougTargetID are deliberately
// absent: both are classified `poly` (the target table is named by a sibling
// column), so no constraint is expressible and there is nothing to comply
// with.
$constrained = [
    // config -- a real reference that can be absent
    'cImageID' => ['capone', 'Capone', 'imageID'],
    'cOSID' => ['capone', 'Capone', 'osID'],
    'lStorageGroupID' => ['location', 'Location', 'storagegroupID'],
    'lStorageNodeID' => ['location', 'Location', 'storagenodeID'],
    // junction -- one row per association
    'laLocationID' => ['location', 'LocationAssociation', 'locationID'],
    'laHostID' => ['location', 'LocationAssociation', 'hostID'],
    'oaOUID' => ['ou', 'OUAssociation', 'ouID'],
    'oaHostID' => ['ou', 'OUAssociation', 'hostID'],
    'sgGroupID' => ['subnetgroup', 'SubnetGroup', 'groupID'],
    // satellite / junction, ldap
    'lgServerID' => ['ldap', 'LDAPGroup', 'serverID'],
    'lgraGroupID' => ['ldap', 'LDAPGroupRoleAssociation', 'ldapgroupID'],
    'lgraRoleID' => ['ldap', 'LDAPGroupRoleAssociation', 'roleID'],
    'lgugGroupID' => ['ldap', 'LDAPGroupUserGroupAssociation', 'ldapgroupID'],
    'lgugUserGroupID' => ['ldap', 'LDAPGroupUserGroupAssociation', 'usergroupID'],
    'lugUserID' => ['ldap', 'LDAPUserGrant', 'userID'],
    // satellite / junction, oidc
    'ogProviderID' => ['oidc', 'OIDCGroup', 'providerID'],
    'oiProviderID' => ['oidc', 'OIDCIdentity', 'providerId'],
    'oiUserID' => ['oidc', 'OIDCIdentity', 'userId'],
    'ograGroupID' => ['oidc', 'OIDCGroupRoleAssociation', 'oidcgroupID'],
    'ograRoleID' => ['oidc', 'OIDCGroupRoleAssociation', 'roleID'],
    'ogugGroupID' => ['oidc', 'OIDCGroupUserGroupAssociation', 'oidcgroupID'],
    'ogugUserGroupID' => ['oidc', 'OIDCGroupUserGroupAssociation', 'usergroupID'],
    'ougUserID' => ['oidc', 'OIDCUserGrant', 'userID'],
];

// The mapping is asserted rather than trusted: if a plugin renames the field
// behind one of these columns, every grep below silently stops looking at
// anything and the file passes while covering nothing.
foreach ($constrained as $column => list($plugin, $item, $field)) {
    $checks++;
    $itemFile = $root . '/' . $plugin . '/src/Items/' . $item . '.php';
    $src = (string)@file_get_contents($itemFile);
    if ('' === $src) {
        $failures[] = "$plugin: $item.php is missing, so the mapping this"
            . " test scans for ($field => $column) cannot be confirmed";
        continue;
    }
    if (false === strpos($src, "'" . $field . "' => '" . $column . "'")) {
        $failures[] = "$plugin: $item no longer maps $field => $column;"
            . ' this test is looking for a field that does not exist';
    }
}

/*
 * Comments are stripped before anything is matched, with the tokenizer rather
 * than a regex. This test's own docblock quotes the broken call it exists to
 * catch, and so does the comment that replaced that call -- a scan over raw
 * text flags both, which would make the only way to document a defect be to
 * stop describing it.
 */
$stripComments = static function ($src) {
    $out = '';
    foreach (token_get_all($src) as $token) {
        if (is_array($token)) {
            if (T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0]) {
                // Keep the newlines so nothing on separate lines is joined
                // into a match that does not exist in the code.
                $out .= str_repeat("\n", substr_count($token[1], "\n"));
                continue;
            }
            $out .= $token[1];
            continue;
        }
        $out .= $token;
    }
    return $out;
};

$phpFiles = [];
$it = new \RecursiveIteratorIterator(
    new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
);
foreach ($it as $file) {
    $path = $file->getPathname();
    if (substr($path, -4) !== '.php') {
        continue;
    }
    // The suite's own fixtures quote these shapes on purpose.
    if (false !== strpos($path, '/tests/')
        || false !== strpos($path, '/vendor/')
    ) {
        continue;
    }
    $phpFiles[] = $path;
}
$checks++;
if (count($phpFiles) < 20) {
    $failures[] = 'found only ' . count($phpFiles) . ' plugin PHP files;'
        . ' the walk is not reaching the tree';
}

foreach ($constrained as $column => list($plugin, $item, $field)) {
    $q = preg_quote($field, '/');
    // ->set('field', 0) / ->set('field', '') and 'field' => 0 / => ''
    $patterns = [
        "/->set\(\s*'" . $q . "'\s*,\s*(0|'')\s*\)/",
        "/'" . $q . "'\s*=>\s*(0|'')\s*[,\)\]]/",
    ];
    foreach ($phpFiles as $path) {
        $src = $stripComments((string)file_get_contents($path));
        if (false === strpos($src, $field)) {
            continue;
        }
        foreach ($patterns as $pattern) {
            $checks++;
            if (preg_match($pattern, $src, $hit)) {
                $failures[] = str_replace($root . '/', '', $path)
                    . ": writes " . trim($hit[0])
                    . " -- `$column` carries a foreign key, so 0 and '' name"
                    . ' a row that does not exist. Write null.';
            }
        }
    }
}

/*
 * The shape that made the original a no-op rather than a wrong write:
 * update($find, $op, 0). A bare scalar as the third argument is never
 * meaningful -- update() answers false without touching anything -- so it is
 * always either a typo for an array or a misunderstanding of the signature.
 * Checked across every plugin, not just the constrained columns, because the
 * failure is invisible either way.
 */
foreach ($phpFiles as $path) {
    $src = $stripComments((string)file_get_contents($path));
    if (false === strpos($src, '->update(')) {
        continue;
    }
    $checks++;
    // `[^;]*?` for the first argument, NOT `\[[^\]]*\]`. The find-map is
    // routinely `['storagenodeID' => $arguments['itemIDs']]`, whose inner `]`
    // ends a negated-class match early -- so the tighter pattern skipped the
    // exact line this check exists to catch, and passed. Bounded on `;` so a
    // match cannot run past the end of the statement.
    if (preg_match(
        "/->update\([^;]*?,\s*'[^']*'\s*,\s*(?!\[)(0|''|null|false)\s*\)/s",
        $src,
        $hit
    )) {
        $failures[] = str_replace($root . '/', '', $path)
            . ': ' . preg_replace('/\s+/', ' ', trim($hit[0]))
            . ' -- update() takes an associative array of columns as its'
            . ' third argument and returns false for a scalar, so this'
            . ' silently does nothing.';
    }
}

if (count($failures)) {
    fwrite(STDERR, "FAIL: a plugin writes a sentinel into a foreign-key column:\n");
    foreach (array_unique($failures) as $f) {
        fwrite(STDERR, "  - $f\n");
    }
    fwrite(
        STDERR,
        sprintf("%d of %d checks failed\n", count(array_unique($failures)), $checks)
    );
    exit(1);
}

printf("PASS  plugin foreign-key writes are null, not 0: %d checks\n", $checks);
exit(0);
