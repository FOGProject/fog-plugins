<?php
/**
 * Every LDAPServers column that 1.5 never created reaches an upgraded table.
 *
 * THE BUG THIS EXISTS FOR (forum topic 18300). A server upgraded from 1.5
 * could not add or edit an LDAP server: "Add LDAP server failed!", with the
 * database answering "Unknown column 'lsGroupNamAttr'". Every released 1.5.10
 * LDAPServers table stops at lsEnableNestedGroup. createSql() declares the
 * 1.6 columns, but CREATE TABLE IF NOT EXISTS is a no-op against a table that
 * exists, and only the nested-group and TLS columns had an ADD COLUMN step.
 * Reads hid it, because getColumns() drops an absent column. save() names
 * every declared column, so every write failed.
 *
 * WHAT IS CHECKED. Each column in createSql() that is not in the pinned 1.5
 * list must be added by a later step: an "ADD COLUMN `x`" in schema(), or an
 * entry in _addMissing15Columns() that schema() calls.
 *
 * PINNED BY HAND: the 1.5 list is LDAPServers as install() created it in
 * fogproject tag 1.5.10.2534, the last 1.5 release. This repository is fetched
 * on its own (bin/fetch-plugins.sh), so it cannot read a fogproject checkout.
 * 1.5 is closed, so the list cannot move.
 *
 * Usage: php tests/ldap-upgrade-from-15-columns.test.php
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
$file = $root . '/ldap/src/Managers/LDAPManager.php';
$failures = [];

// LDAPServers as 1.5.10.2534's LDAPManager::install() created it.
$released15 = [
    'lsID', 'lsName', 'lsDesc', 'lsCreatedBy', 'lsAddress', 'lsCreatedTime',
    'lsUserSearchDN', 'lsPort', 'lsUserNamAttr', 'lsGrpMemberAttr',
    'lsAdminGroup', 'lsUserGroup', 'lsSearchScope', 'lsBindDN', 'lsBindPwd',
    'lsGrpSearchDN', 'lsUseGroupMatch', 'lsUserFilter', 'lsEnableNestedGroup',
];

/**
 * Lifts one method's body out of the source by matching braces.
 *
 * @param string $src  the source, comments already stripped
 * @param string $name the method name
 *
 * @return string
 */
function luMethodBody($src, $name)
{
    $at = strpos($src, 'function ' . $name . '(');
    if (false === $at) {
        return '';
    }
    $open = strpos($src, '{', $at);
    $depth = 0;
    for ($i = $open, $n = strlen($src); $i < $n; $i++) {
        if ($src[$i] === '{') {
            $depth++;
        }
        if ($src[$i] === '}' && --$depth === 0) {
            return substr($src, $open, $i - $open + 1);
        }
    }

    return '';
}

// Strip comments, so a column named only in prose counts for nothing.
$src = '';
foreach (token_get_all(file_get_contents($file)) as $token) {
    if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
        continue;
    }
    $src .= is_array($token) ? $token[1] : $token;
}

// createSql()'s column list: the first [...] after the "true," argument.
$create = luMethodBody($src, 'createSql');
if (!preg_match('/true\s*,\s*\[(.*?)\]/s', $create, $m)) {
    fwrite(STDERR, "FAIL: could not read the column list in createSql()\n");
    exit(1);
}
preg_match_all("/'(ls[A-Za-z]+)'/", $m[1], $mm);
$declared = $mm[1];
if (count($declared) < count($released15)) {
    fwrite(STDERR, "FAIL: createSql() lists fewer columns than 1.5 had\n");
    exit(1);
}

$schema = luMethodBody($src, 'schema');
$helper = luMethodBody($src, '_addMissing15Columns');
$helperRuns = $helper !== ''
    && false !== strpos($schema, '_addMissing15Columns()');

$new = array_diff($declared, $released15);
foreach ($new as $column) {
    $byAlter = (bool)preg_match(
        '/ADD COLUMN `' . preg_quote($column, '/') . '`/',
        $schema
    );
    $byHelper = $helperRuns && (bool)preg_match(
        "/'" . preg_quote($column, '/') . "'\s*=>/",
        $helper
    );
    if (!$byAlter && !$byHelper) {
        $failures[] = sprintf(
            '%s is in createSql() but no schema() step adds it to a table '
            . 'created by 1.5, so save() fails there with Unknown column',
            $column
        );
    }
}

if ($failures) {
    foreach ($failures as $f) {
        fwrite(STDERR, "FAIL: $f\n");
    }
    exit(1);
}
printf(
    "PASS: %d LDAPServers columns added since 1.5 all reach an upgraded table\n",
    count($new)
);
exit(0);
