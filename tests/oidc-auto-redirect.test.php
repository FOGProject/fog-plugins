<?php
/**
 * Guards "everyone signs in through the provider" and its escape hatch (#17).
 *
 *   tests/oidc-auto-redirect.test.php
 *
 * For an install where every account lives at the identity provider, FOG's
 * username and password box is a dead end -- it cannot accept those
 * credentials. opAutoRedirect removes the extra click by sending an
 * anonymous visitor straight to the provider.
 *
 * It is also the most dangerous setting in this plugin. An unconditional
 * redirect to a provider that is unreachable, whose certificate expired,
 * whose discovery broke or whose issuer was mistyped takes the login form
 * away from every administrator at once, and there is no way back through a
 * browser -- including for the local break-glass account, which exists for
 * exactly this and which core spends a whole test file keeping able to sign
 * in.
 *
 * So most of what is pinned here is containment rather than function:
 *
 *   1. It ships OFF, in both places a default can be stated. An install
 *      that upgraded into this switched on would find its login form
 *      replaced by a redirect nobody asked for.
 *   2. TWO providers flagged must REFUSE and render the form. Silently
 *      picking one hides a misconfiguration on the page an admin is least
 *      able to debug, while sending everybody to a provider half of them
 *      may not have an account at.
 *   3. The complaint about that goes to the error log, not to the page.
 *      This runs for anonymous visitors.
 *   4. A refused sign-in lands on management/login.php, not index.php.
 *      Bouncing back to index.php is an infinite redirect when the provider
 *      is down, and an unreadable flash message when it is not.
 *   5. Logging out on such an install goes to management/login.php too,
 *      whether or not single logout is on. Otherwise "Log out" sends you to
 *      the page that signs you straight back in.
 *
 * The escape hatch itself is core's and is pinned there
 * (fogproject tests/local-login-entrypoint.test.php): index.php offers
 * LOGIN_PAGE_REDIRECT only when FOG_LOCAL_LOGIN is undefined, so the
 * listener below is never reached on management/login.php. Nothing this
 * plugin does can change that, which is the point of putting it there.
 *
 * Source assertions only.
 *
 * Exit 0 = pass, 1 = fail.
 */
$root = dirname(__DIR__);
$flowFile = $root . '/oidc/class/oidcflow.class.php';
$hookFile = $root . '/oidc/hooks/oidcloginredirect.hook.php';
$outFile = $root . '/oidc/hooks/oidclogout.hook.php';
$modelFile = $root . '/oidc/class/oidc.class.php';
$mgrFile = $root . '/oidc/class/oidcmanager.class.php';
$pageFile = $root . '/oidc/pages/oidcmanagement.page.php';
foreach ([$flowFile, $hookFile, $outFile, $modelFile, $mgrFile, $pageFile] as $f) {
    if (!is_readable($f)) {
        echo "cannot read $f -- run this from the repository\n";
        exit(1);
    }
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
 * Source with comments stripped and whitespace squashed.
 *
 * Comments first: the prose above every method here names opAutoRedirect,
 * management/login.php and LOGIN_PAGE_REDIRECT, so a test reading raw text
 * would be satisfied by the documentation with the code deleted.
 *
 * @param string $file the file
 *
 * @return string
 */
function squashed($file)
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
    return preg_replace('/\s+/', '', $out);
}

/**
 * One method's body, cut at the start of the next method.
 *
 * @param string $s      squashed source
 * @param string $needle the method declaration, squashed
 *
 * @return string body, or ''
 */
function methodBody($s, $needle)
{
    $at = strpos($s, $needle);
    if (false === $at) {
        return '';
    }
    $next = strpos($s, 'function', $at + strlen($needle));
    return false === $next ? substr($s, $at) : substr($s, $at, $next - $at);
}

$flow = squashed($flowFile);
$hook = squashed($hookFile);
$out = squashed($outFile);
$model = squashed($modelFile);
$mgr = squashed($mgrFile);
$page = squashed($pageFile);

echo "1. the column exists, ships off, and reaches every install\n";

if (false !== strpos($model, "'autoRedirect'=>'opAutoRedirect'")) {
    ok('OIDC maps autoRedirect to opAutoRedirect');
} else {
    bad('OIDC no longer maps the autoRedirect field');
}
if (false !== strpos($mgr, "'opAutoRedirect',")) {
    ok('createSql() declares opAutoRedirect (fresh installs)');
} else {
    bad('createSql() no longer declares opAutoRedirect');
}
if (false !== strpos(
    $mgr,
    '"ALTERTABLE`OIDCProviders`ADDCOLUMN`opAutoRedirect`"'
)) {
    ok('a schema step adds opAutoRedirect (existing installs)');
} else {
    bad('no ALTER TABLE step adds opAutoRedirect; every install that'
        . ' already passed the earlier steps keeps a table without it');
}
/*
 * Appended after the single-logout step, not before it and not inserted
 * earlier. installdb() skips by COUNT, so reordering silently skips a step
 * on every install that has already run some of them.
 */
$autoAt = strpos($mgr, 'ADDCOLUMN`opAutoRedirect`');
$sloAt = strpos($mgr, 'ADDCOLUMN`opSingleLogout`');
if (false !== $autoAt && false !== $sloAt && $autoAt > $sloAt) {
    ok('the step is appended after the single-logout step');
} else {
    bad('the opAutoRedirect step is not last; installdb() skips by count,'
        . ' so an inserted step silently skips a later one');
}
/*
 * Default off, and this is the one that matters most. On is a login form
 * replaced by a redirect nobody asked for, on every install that upgrades.
 */
$alterAuto = substr($mgr, (int)$autoAt, 120);
if (false !== strpos($alterAuto, "DEFAULT'0'")) {
    ok("the ALTER defaults to '0'");
} else {
    bad('the ALTER does not default opAutoRedirect off; upgrading would'
        . ' redirect the login page on installs that never asked for it');
}
/*
 * And the create page must not hand a new provider the setting either. A
 * provider is created switched off entirely.
 */
if (false !== strpos($page, "->set('autoRedirect','0')")) {
    ok('a newly created provider has it off');
} else {
    bad('the create path no longer forces autoRedirect off; a provider'
        . ' would be born redirecting the login page');
}

echo "\n2. two flagged providers refuse, and say so where a visitor cannot see\n";

$forced = methodBody($flow, 'publicstaticfunctionforcedProvider()');
if ('' === $forced) {
    bad('OIDCFlow::forcedProvider() is missing');
} else {
    if (false !== strpos($forced, "'autoRedirect'=>[1]")
        && false !== strpos($forced, "'enabled'=>[1]")
    ) {
        ok('it selects on enabled AND autoRedirect');
    } else {
        bad('forcedProvider() no longer requires both enabled and'
            . ' autoRedirect; a provider an admin is still configuring'
            . ' would receive every visitor');
    }
    /*
     * The refusal. Returning an id when more than one is flagged is the
     * "silently pick one" behaviour that was argued down in #17, and it
     * fails invisibly: the login page works, it just sends everybody to a
     * provider that was never chosen.
     */
    if (false !== strpos($forced, 'if(count($ids)>1){')
        && false !== strpos($forced, 'return0;')
    ) {
        ok('more than one flagged provider refuses to redirect');
    } else {
        bad('forcedProvider() no longer refuses when several providers are'
            . ' flagged; it would silently pick one and hide the'
            . ' misconfiguration');
    }
    if (false !== strpos($forced, 'error_log(')) {
        ok('the ambiguity is logged');
    } else {
        bad('forcedProvider() no longer logs the ambiguity; the only'
            . ' symptom would be a checkbox that appears not to work');
    }
    /*
     * Logged, NOT flashed. setMessage() here would tell an anonymous
     * visitor how this server's identity providers are configured.
     */
    if (false === strpos($forced, 'setMessage(')) {
        ok('it does not tell the anonymous visitor');
    } else {
        bad('forcedProvider() puts the misconfiguration on the page; this'
            . ' runs for visitors who have not signed in');
    }
}

echo "\n3. the seam is consumed, and the row re-checked\n";

$redirectUrl = methodBody($flow, 'publicstaticfunctionloginRedirectUrl()');
if ('' === $redirectUrl) {
    bad('OIDCFlow::loginRedirectUrl() is missing');
} else {
    if (false !== strpos($redirectUrl, "get('enabled')")
        && false !== strpos($redirectUrl, "get('autoRedirect')")
    ) {
        ok('it re-reads the provider row before redirecting');
    } else {
        bad('loginRedirectUrl() trusts the id without re-checking the row;'
            . ' a provider disabled a moment ago would still be receiving'
            . ' every visitor');
    }
    if (false !== strpos($redirectUrl, 'OIDC::startUrl(')) {
        ok('it redirects to the provider start URL');
    } else {
        bad('loginRedirectUrl() no longer builds the start URL');
    }
}
/*
 * Absolute. Core's seam refuses anything that is not an absolute http(s)
 * URL, so a relative start path here means the setting silently does
 * nothing at all -- the listener runs, sets a value, and core drops it.
 */
$startUrl = methodBody($model, 'publicstaticfunctionstartUrl(');
if (false !== strpos($startUrl, 'self::absoluteUrl(')) {
    ok('startUrl() is absolute');
} else {
    bad('OIDC::startUrl() is not built through absoluteUrl(); core refuses'
        . ' a relative LOGIN_PAGE_REDIRECT value and the setting would'
        . ' silently do nothing');
}

if (false !== strpos($hook, "['LOGIN_PAGE_REDIRECT','loginRedirect']")) {
    ok('OIDCLoginRedirect registers on LOGIN_PAGE_REDIRECT');
} else {
    bad('OIDCLoginRedirect no longer registers on LOGIN_PAGE_REDIRECT');
}
if (false !== strpos($hook, 'registerInstalled(')) {
    ok('it registers through registerInstalled()');
} else {
    bad('OIDCLoginRedirect registers unconditionally; a plugin present but'
        . ' not installed must not redirect the login page');
}
$listener = methodBody($hook, 'publicfunctionloginRedirect(');
$guardAt = strpos($listener, "if(''===\$url){return;}");
$writeAt = strpos($listener, "\$arguments['redirect']=");
if (false !== $guardAt && false !== $writeAt && $guardAt < $writeAt) {
    ok('it writes the redirect only when there is one');
} else {
    bad('OIDCLoginRedirect::loginRedirect() writes to $arguments even with'
        . ' no URL, so it can clobber another listener');
}

echo "\n4. nothing bounces back into the redirect\n";

/*
 * The two loops. A refused sign-in and a completed logout both used to
 * land on management/index.php, which is precisely the page this feature
 * redirects -- so on a forced-redirect install one is an infinite loop and
 * the other signs the user straight back in.
 */
$failBody = methodBody($flow, 'privatestaticfunction_fail(');
if (false !== strpos($failBody, "'management/login.php'")) {
    ok('a refused sign-in lands on the local login form');
} else {
    bad('_fail() still sends the browser to management/index.php; on a'
        . ' forced-redirect install that is a redirect loop when the'
        . ' provider is down, and an unread flash message when it is not');
}

$logoutListener = methodBody($out, 'publicfunctionproviderLogout(');
if ('' === $logoutListener) {
    bad('OIDCLogout::providerLogout() is missing');
} else {
    if (false !== strpos($logoutListener, 'OIDCFlow::forcedProvider()')
        && false !== strpos($logoutListener, 'OIDC::postLogoutUri()')
    ) {
        ok('logging out of a forced-redirect install lands on the form');
    } else {
        bad('logging out with automatic redirect on but single logout off'
            . ' still lands on management/index.php, which redirects to a'
            . ' provider whose session is still alive and signs the user'
            . ' straight back in');
    }
    /*
     * And only as a fallback. Overriding a real provider-logout URL with
     * the local form would quietly disable single logout for exactly the
     * installs most likely to want it.
     */
    $sloAt2 = strpos($logoutListener, 'OIDCFlow::logoutUrl()');
    $fallbackAt = strpos($logoutListener, 'OIDCFlow::forcedProvider()');
    if (false !== $sloAt2 && false !== $fallbackAt && $sloAt2 < $fallbackAt) {
        ok('the provider logout still wins when there is one');
    } else {
        bad('the local-form fallback runs ahead of the provider logout URL,'
            . ' disabling single logout');
    }
}

echo "\n5. the escape hatch is named where the setting is turned on\n";

/*
 * Not decoration. An admin who ticks this box without knowing login.php
 * exists has one expired certificate between themselves and being locked
 * out of their own server -- and at that point the URL is not something
 * they could guess.
 */
/*
 * Scoped to the autoRedirect label and nothing else. The page prints the
 * same URL a second time in its own read-only Post-Logout Redirect URI
 * field, so a whole-file search passes with the URL removed from exactly
 * the place an admin reads it -- next to the box they are about to tick.
 */
$labelAt = strpos($page, "'autoRedirect',_(");
$inputAt = false === $labelAt
    ? false
    : strpos($page, '=>self::makeInput(', $labelAt);
$label = (false === $labelAt || false === $inputAt)
    ? ''
    : substr($page, $labelAt, $inputAt - $labelAt);
if ('' === $label) {
    bad('could not find the autoRedirect field on the management page');
} elseif (false !== strpos($label, 'OIDC::postLogoutUri()')) {
    ok('the management page prints the local login URL beside the setting');
} else {
    bad('the autoRedirect label no longer names the local login URL; the'
        . ' escape hatch exists but the admin turning this on is not told'
        . ' about it, and cannot guess it once locked out');
}

echo "\n";
if ($fail > 0) {
    echo "FAIL: $fail problem(s), $pass ok\n";
    exit(1);
}
echo "ok: $pass checks passed -- the login page can be sent to a provider,"
    . " and there is still a way back\n";
exit(0);
