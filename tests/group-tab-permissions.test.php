<?php
/**
 * An injected group tab takes the group's permissions, not the page's.
 *
 * Both the LDAP and the OpenID Connect plugin inject an association tab
 * onto the core Role and User Group pages, so a directory group or a
 * provider group can be attached from either end. The tab is a second door
 * onto an association the plugin's own page owns, and that is exactly why
 * the permission is the interesting part:
 *
 *   Granting a role to a directory group decides who becomes an
 *   administrator of this server the next time somebody signs in through
 *   that directory. The right that opens it must be the one the plugin's
 *   own page demands (ldapgroup.edit / oidcgroup.edit), never the
 *   role.edit or usergroup.edit that got the admin onto the core page. Both
 *   plugins deliberately register their group node's permissions
 *   separately from the provider/server node for the same reason.
 *
 * The failure is silent in both directions. Drop the check and the tab
 * behaves identically for an administrator while quietly handing anyone
 * with role.edit the ability to grant themselves a role through a
 * directory group. Put the check after the write and it is not a check at
 * all -- the association is already saved.
 *
 * Two further things are pinned because losing either is equally quiet:
 * the CSRF/auth check on the POST, and the id normalization that keeps a 0
 * or a non-numeric out of the getClass() lookup.
 *
 * Source-level: the hooks cannot be instantiated without a booted FOG, a
 * session and a database.
 *
 * Usage: php tests/group-tab-permissions.test.php
 * Exit status 0 = pass, 1 = fail.
 */

$root = dirname(__DIR__);

/*
 * plugin => [hook file, the node the association belongs to].
 *
 * The node is not the plugin's own: both plugins put groups on a node of
 * their own so that managing servers/providers and changing what a group
 * grants are separately grantable rights.
 */
$hooks = [
    'ldap' => [
        $root . '/ldap/hooks/addldapgrouptabs.hook.php',
        'ldapgroup'
    ],
    'oidc' => [
        $root . '/oidc/hooks/addoidcgrouptabs.hook.php',
        'oidcgroup'
    ]
];

$failures = [];
$checks = 0;

/**
 * Records one assertion.
 *
 * @param string $what the claim
 * @param bool   $ok   whether it held
 *
 * @return void
 */
function check($what, $ok)
{
    global $failures, $checks;
    $checks++;
    if (!$ok) {
        $failures[] = $what;
    }
}

/**
 * A method body with comments stripped and whitespace flattened.
 *
 * Comments have to go: the prose above each of these methods names every
 * symbol this test searches for, and would satisfy the search on its own.
 *
 * @param string $file   file to read
 * @param string $method method name
 *
 * @return string|null code of the body, or null if not found
 */
function methodSource($file, $method)
{
    $t = token_get_all(file_get_contents($file));
    $n = count($t);
    for ($i = 0; $i < $n; $i++) {
        if (!is_array($t[$i]) || T_FUNCTION !== $t[$i][0]) {
            continue;
        }
        $j = $i + 1;
        while ($j < $n && is_array($t[$j]) && T_WHITESPACE === $t[$j][0]) {
            $j++;
        }
        if ($j >= $n || !is_array($t[$j]) || $t[$j][1] !== $method) {
            continue;
        }
        $depth = 0;
        $src = '';
        $started = false;
        for ($k = $j; $k < $n; $k++) {
            $c = $t[$k];
            if (is_array($c)
                && in_array($c[0], [T_COMMENT, T_DOC_COMMENT], true)
            ) {
                continue;
            }
            if (!is_array($c)) {
                if ('{' === $c) {
                    $depth++;
                    $started = true;
                } elseif ('}' === $c) {
                    if (0 === --$depth && $started) {
                        return preg_replace('#\s+#', '', $src);
                    }
                }
            }
            if ($started) {
                $src .= is_array($c) ? $c[1] : $c;
            }
        }
        return preg_replace('#\s+#', '', $src);
    }
    return null;
}

/**
 * The permission string a hook demands, however it is spelled.
 *
 * Both hooks may name the node through a class constant rather than a
 * literal, so a search for the finished "oidcgroup.view" would miss a
 * correct implementation. This resolves the constant form as well, and
 * returns the offset so ordering can be checked.
 *
 * @param string $src  flattened method source
 * @param string $node the group node
 * @param string $perm 'view' or 'edit'
 *
 * @return int|false offset of the check, or false when absent
 */
function permOffset($src, $node, $perm)
{
    $spellings = [
        "Authorization::can('" . $node . "." . $perm . "')",
        "Authorization::can(self::GROUP_NODE.'." . $perm . "')"
    ];
    foreach ($spellings as $s) {
        $at = strpos($src, $s);
        if (false !== $at) {
            return $at;
        }
    }
    return false;
}

foreach ($hooks as $plugin => $where) {
    list($file, $groupNode) = $where;
    if (!is_readable($file)) {
        fwrite(STDERR, "FAIL: cannot read $file\n");
        exit(1);
    }

    /*
     * 1. The tab does not render without <groupnode>.view.
     *
     * Not the security boundary on its own -- the POST below is what
     * refuses -- but a tab that renders and then rejects every save is a
     * bug report, and an install not using this plugin's groups has
     * nothing to put in it.
     */
    $inject = methodSource($file, 'injectTabData');
    if (null === $inject) {
        fwrite(STDERR, "FAIL: $plugin has no injectTabData()\n");
        exit(1);
    }
    $view = permOffset($inject, $groupNode, 'view');
    $add = strpos($inject, "pluginsTabData");
    check(
        "$plugin injectTabData() requires $groupNode.view",
        false !== $view
    );
    check(
        "$plugin injectTabData() checks it BEFORE adding the tab",
        false !== $view && false !== $add && $view < $add
    );

    /*
     * 2. The POST refuses without <groupnode>.edit, refuses before it
     *    writes, and never reads the core page's own edit right.
     */
    $post = methodSource($file, 'editSuccess');
    if (null === $post) {
        fwrite(STDERR, "FAIL: $plugin has no editSuccess()\n");
        exit(1);
    }
    $edit = permOffset($post, $groupNode, 'edit');
    $write = strpos($post, '$group->{$method}');
    check(
        "$plugin editSuccess() requires $groupNode.edit",
        false !== $edit
    );
    check(
        "$plugin editSuccess() writes the association",
        false !== $write
    );
    check(
        "$plugin editSuccess() checks the permission BEFORE it writes",
        false !== $edit && false !== $write && $edit < $write
    );
    // A refusal that does not carry a 4xx reads to the browser as success,
    // and the tab would report the association changed when it did not.
    check(
        "$plugin editSuccess() answers a refusal with HTTP_FORBIDDEN",
        false !== strpos($post, 'HTTP_FORBIDDEN')
    );
    check(
        "$plugin editSuccess() does not gate on the core page's own right",
        false === strpos($post, "can('role.edit')")
        && false === strpos($post, "can('usergroup.edit')")
    );
    check(
        "$plugin editSuccess() enforces CSRF and auth",
        false !== strpos($post, 'self::checkAuthAndCSRF()')
    );
    check(
        "$plugin editSuccess() normalizes the submitted ids",
        false !== strpos($post, 'self::positiveIntIds($items)')
    );
    // Roles and group memberships are cached per user per request, and this
    // just changed which ones a sign-in will produce.
    check(
        "$plugin editSuccess() clears the authorization cache",
        false !== strpos($post, 'Authorization::resetCache()')
    );
}

if (count($failures)) {
    fwrite(STDERR, 'FAIL (' . count($failures) . " of $checks):\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - $f\n");
    }
    exit(1);
}

echo "ok  $checks checks passed\n";
exit(0);
