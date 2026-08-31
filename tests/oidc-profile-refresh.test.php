<?php
/**
 * Guards "the provider is the source of truth, on every sign-in".
 *
 *   tests/oidc-profile-refresh.test.php
 *
 * The display name used to be written once, by _provisionUser(), and never
 * again. Renaming somebody in the directory left FOG showing the name they
 * had on the day they first signed in, forever. Reported against the lab
 * Keycloak: first/last name corrected there, FOG kept rendering the old
 * value on every subsequent login.
 *
 * What this pins, and why each one is a way it silently regresses:
 *
 *   1. _refreshProfile() exists and is called from BOTH existing-account
 *      paths. Calling it from one is the shape of the original bug -- an
 *      account linked by subject refreshes, one linked by name does not, and
 *      which you are is invisible from the UI.
 *   2. It does NOT write uName or uAuthSource. Refreshing the username would
 *      rename an account out from under its history and tasks; stamping
 *      authsource would take local password login away from an account an
 *      admin created, which is the opposite of break-glass.
 *   3. An empty name claim does not blank a good value. Some providers omit
 *      it depending on scopes.
 *
 * Source assertions only -- no DB, no provider, no session.
 *
 * Exit 0 = pass, 1 = fail.
 */
$flow = dirname(__DIR__) . '/oidc/src/Util/OIDCFlow.php';
if (!is_readable($flow)) {
    echo "cannot read $flow -- run this from the repository\n";
    exit(1);
}

$pass = 0;
$fail = 0;
function ok($m)
{
    global $pass;
    ++$pass;
    echo "  ok    $m\n";
}
function bad($m)
{
    global $fail;
    ++$fail;
    echo "  FAIL  $m\n";
}

/**
 * Source with comments stripped.
 *
 * This file's own docblocks name uName, uAuthSource and _refreshProfile, so
 * every assertion below would pass on the documentation alone.
 *
 * @param string $file the file
 *
 * @return string
 */
function src($file)
{
    $out = '';
    foreach (token_get_all(file_get_contents($file)) as $t) {
        if (is_array($t)) {
            if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= $t[1];
            continue;
        }
        $out .= $t;
    }
    return $out;
}

$s = src($flow);
$squashed = preg_replace('/\s+/', '', $s);

echo "1. the refresh exists and both existing-account paths use it\n";

if (false !== strpos($squashed, 'privatestaticfunction_refreshProfile(')) {
    ok('_refreshProfile() is defined');
} else {
    bad('_refreshProfile() is gone -- the display name is written once and never again');
}

// Two calls, not one. The subject-linked path and the link-on-first-sign-in
// path are different branches of _resolveUser() and both reach an existing
// FOG row.
$calls = preg_match_all('/self::_refreshProfile\(/', $s);
if ($calls >= 2) {
    ok("_refreshProfile() is called from $calls sites (both existing-account paths)");
} else {
    bad("_refreshProfile() is called from $calls site(s); both existing-account paths must refresh");
}

/*
 * Scope the write assertions to this method's body and nothing else.
 *
 * A fixed-length window is not good enough: the very next method is
 * _provisionUser(), which legitimately writes name and authsource, so a
 * window that overruns reports those as violations here. Cut at the start of
 * the following method instead.
 */
$at = strpos($squashed, 'privatestaticfunction_refreshProfile(');
$bodySquashed = '';
if (false !== $at) {
    $next = strpos($squashed, 'staticfunction', $at + 40);
    $bodySquashed = false === $next
        ? substr($squashed, $at)
        : substr($squashed, $at, $next - $at);
}
if ('' === $bodySquashed) {
    bad('could not isolate _refreshProfile()\'s body');
}

if (false !== strpos($bodySquashed, "set('display'")) {
    ok('_refreshProfile() writes the display name');
} else {
    bad('_refreshProfile() does not write the display name');
}

echo "\n2. it does not write what must not be rewritten\n";

foreach (
    [
        'name' => 'uName -- renaming an account out from under its history is a migration, not a login side effect',
        'authsource' => 'uAuthSource -- stamping it removes local password login from an admin-created account',
    ] as $field => $why
) {
    if (false === strpos($bodySquashed, "set('" . $field . "'")) {
        ok("does not write '$field'");
    } else {
        bad("writes '$field': $why");
    }
}

echo "\n3. a missing claim does not destroy a good value\n";

/*
 * The guard has to be on emptiness, not on presence: ?? '' yields '' for a
 * provider that sends "name": "", which is not the same as absent.
 *
 * And the FALLBACK VALUE is what actually matters. Asserting only that the
 * `if` exists passes a version that keeps the branch and assigns '' inside
 * it -- which is exactly the bug, with the guard still visible in the diff.
 * Pin the assignment.
 */
if (preg_match('/if\s*\(\s*\'\'\s*===\s*\$display\s*\)\s*\{\s*\$display\s*=\s*\(string\)\$user->get\(\s*\'display\'\s*\)/', $s)) {
    ok('an empty name claim falls back to the value already stored');
} else {
    bad('an empty or missing name claim does not fall back to the stored display name');
}

// Only write when something moved, so an unchanged sign-in leaves no history
// row. Pinned because the cheap version -- save() unconditionally -- looks
// identical and writes on every login.
if (preg_match('/if\s*\(\s*\$changed\s*\)\s*\{\s*\$user->save\(\)/', $s)) {
    ok('saves only when a value actually changed');
} else {
    bad('saves unconditionally -- every sign-in writes a row and a history entry');
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
