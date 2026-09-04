<?php
/**
 * Guards "signing out of FOG can end the provider session too" (#15).
 *
 *   tests/oidc-single-logout.test.php
 *
 * Before this, the plugin implemented no logout at all. Clicking Log out
 * destroyed FOG's session and left the provider's SSO session untouched, so
 * clicking the provider button again re-authenticated silently and dropped
 * the same person straight back into the same account. On an account
 * carrying uAuthSource='oidc' -- which core refuses a local password by
 * design -- there was then no way to sign in as anybody else without
 * clearing cookies.
 *
 * Five properties, and each one fails in a way nobody would notice from a
 * working install:
 *
 *   1. The material is recorded at CALLBACK time, never fetched at logout.
 *      Discovery is a network request; on the sign-out path it turns a
 *      provider that has gone away into a Log out button that hangs and
 *      then fails, at the moment somebody is trying to leave.
 *   2. It is recorded AFTER establishSession(). The callback empties
 *      $_SESSION wholesale just before that call, so anything written
 *      earlier is thrown away -- and the feature is then simply dead, with
 *      no error anywhere.
 *   3. It is gated on the per-provider column, and defaults off. Where FOG
 *      shares a provider with other applications, ending the SSO session
 *      because somebody left FOG reaches applications FOG has nothing to
 *      do with.
 *   4. The endpoint must be https. It comes out of a fetched document and
 *      ends up in a Location header carrying an ID token.
 *   5. post_logout_redirect_uri points at management/login.php, not
 *      index.php. index.php is what a forced-redirect install (#17) bounces
 *      back to the provider -- so a signed-out user landing there is either
 *      signed straight back in or sent round the loop.
 *
 * Source assertions only: reaching any of this for real needs a provider, a
 * browser and a session.
 *
 * Exit 0 = pass, 1 = fail.
 */
$root = dirname(__DIR__);
$flowFile = $root . '/oidc/src/Util/OIDCFlow.php';
$hookFile = $root . '/oidc/src/Hooks/OIDCLogout.php';
$modelFile = $root . '/oidc/src/Items/OIDC.php';
$mgrFile = $root . '/oidc/src/Managers/OIDCManager.php';
foreach ([$flowFile, $hookFile, $modelFile, $mgrFile] as $f) {
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
 * Comments first, and it is load-bearing here: every symbol this file
 * searches for is named in the prose above the code it guards, so a test
 * reading raw text would pass with the implementation deleted.
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
 * A fixed-length window would overrun into whatever follows and report its
 * code as this method's -- the mistake that produced two false failures in
 * tests/oidc-profile-refresh.test.php.
 *
 * @param string $s      squashed source
 * @param string $needle the method's declaration, squashed
 *
 * @return string body, or '' if not found
 */
function methodBody($s, $needle)
{
    $at = strpos($s, $needle);
    if (false === $at) {
        return '';
    }
    $next = strpos($s, 'function', $at + strlen($needle));
    return false === $next
        ? substr($s, $at)
        : substr($s, $at, $next - $at);
}

$flow = squashed($flowFile);
$hook = squashed($hookFile);
$model = squashed($modelFile);
$mgr = squashed($mgrFile);

echo "1. the column exists, ships off, and reaches an existing install\n";

if (false !== strpos($model, "'singleLogout'=>'opSingleLogout'")) {
    ok('OIDC maps singleLogout to opSingleLogout');
} else {
    bad('OIDC no longer maps the singleLogout field');
}

/*
 * BOTH halves. createSql() alone serves only servers installed after this
 * commit; the ALTER alone serves only servers installed before it. Shipping
 * one is the classic plugin-schema half-fix, and it looks complete in
 * review because the install it was tested on happened to be the served
 * kind.
 */
if (false !== strpos($mgr, "'opSingleLogout',")) {
    ok('createSql() declares opSingleLogout (fresh installs)');
} else {
    bad('createSql() no longer declares opSingleLogout; a fresh install'
        . ' would have no column for the setting');
}
if (false !== strpos(
    $mgr,
    '"ALTERTABLE`OIDCProviders`ADDCOLUMN`opSingleLogout`"'
)) {
    ok('a schema step adds opSingleLogout (existing installs)');
} else {
    bad('no ALTER TABLE step adds opSingleLogout; every install that'
        . ' already passed step 0 keeps a table without the column');
}

/*
 * Appended, not inserted. Plugin::installdb() SKIPS the first pSchema steps
 * rather than replaying them, so putting a step anywhere but the end shifts
 * every later step's index and silently skips one on installs that have
 * already run. The LDAP plugin carries repair steps for exactly this.
 */
$alterAt = strpos($mgr, 'ALTERTABLE`OIDCProviders`ADDCOLUMN`opSingleLogout`');
$grantAt = strpos(
    $mgr,
    '\\FOG\\Plugins\\OIDC\\Managers\\OIDCUserGrantManager())->install()'
);
if (false !== $alterAt && false !== $grantAt && $alterAt > $grantAt) {
    ok('the step is appended after the existing ones');
} else {
    bad('the opSingleLogout step is not at the end of schema(); installdb()'
        . ' skips by COUNT, so an inserted step silently skips a later one');
}

/*
 * Default off, in both places a default can be stated. A column that
 * defaults on turns an upgrade into a behaviour change for every install
 * already using this plugin.
 */
if (false !== strpos($mgr, "ENUM('0','1')NOTNULLDEFAULT'0'\"")) {
    ok("the ALTER defaults to '0'");
} else {
    bad('the ALTER does not default the column off; upgrading would enable'
        . ' single logout for installs that never asked for it');
}

echo "\n2. the material is recorded at sign-in, not fetched at logout\n";

if (false !== strpos($flow, 'privatestaticfunction_rememberLogout(')) {
    ok('_rememberLogout() is defined');
} else {
    bad('_rememberLogout() is gone');
}

$callbackBody = methodBody($flow, 'publicstaticfunctioncallback(');
if ('' === $callbackBody) {
    bad('could not isolate callback()');
} else {
    if (false !== strpos($callbackBody, 'self::_rememberLogout(')) {
        ok('callback() records the logout material');
    } else {
        bad('callback() no longer records the logout material; single'
            . ' logout is dead with nothing to show for it');
    }
    /*
     * Ordering, and it is the whole of property 2. callback() does
     * `$_SESSION = []` immediately before establishSession(), to stop an
     * identity already in the session deciding the new one. Anything stored
     * before that point is wiped by it.
     */
    $wipeAt = strpos($callbackBody, '$_SESSION=[];');
    $rememberAt = strpos($callbackBody, 'self::_rememberLogout(');
    if (false !== $wipeAt && false !== $rememberAt && $rememberAt > $wipeAt) {
        ok('it records AFTER the session wipe');
    } else {
        bad('_rememberLogout() runs before callback() empties $_SESSION, so'
            . ' everything it stores is thrown away');
    }
}

$remember = methodBody($flow, 'privatestaticfunction_rememberLogout(');
if ('' === $remember) {
    bad('could not isolate _rememberLogout()');
} else {
    /*
     * Gated on the column, and gated with an early return rather than by
     * wrapping the write -- either shape is fine, but the read has to be
     * there. Without it every OIDC sign-in arms single logout.
     */
    if (false !== strpos($remember, "get('singleLogout')")) {
        ok('it is gated on the provider column');
    } else {
        bad('_rememberLogout() no longer reads singleLogout; every OIDC'
            . ' session would end its provider session, including on an'
            . ' install sharing that provider with other applications');
    }
    if (false !== strpos($remember, "stripos(\$endpoint,'https://')")) {
        ok('the end_session_endpoint must be https');
    } else {
        bad('_rememberLogout() no longer requires an https'
            . ' end_session_endpoint; it comes from a fetched document and'
            . ' ends up in a Location header carrying an ID token');
    }
    if (false !== strpos($remember, "\$config['end_session_endpoint']")) {
        ok('it reads the endpoint from the discovery document');
    } else {
        bad('_rememberLogout() no longer reads end_session_endpoint');
    }
    /*
     * No network call in here. The reason the material is stored at all is
     * that logout must not depend on the provider being reachable; a fetch
     * that crept in here would be on the sign-in path instead, which is
     * merely wrong rather than harmful -- but a fetch in logoutUrl() below
     * is the actual failure, and both are worth refusing.
     */
    foreach (['_getJson(', '_post(', '_http(', '_discover('] as $call) {
        if (false !== strpos($remember, $call)) {
            bad('_rememberLogout() calls ' . $call . '; everything it needs'
                . ' is already in hand');
        }
    }
}

echo "\n3. the logout URL is built from what was stored, and nothing else\n";

$logoutUrl = methodBody($flow, 'publicstaticfunctionlogoutUrl(');
if ('' === $logoutUrl) {
    bad('OIDCFlow::logoutUrl() is missing');
} else {
    foreach (['_getJson(', '_post(', '_http(', '_discover('] as $call) {
        if (false !== strpos($logoutUrl, $call)) {
            bad('logoutUrl() calls ' . $call . ' -- a network request on the'
                . ' sign-out path means a provider that has gone away turns'
                . ' Log out into a page that hangs and then fails');
        }
    }
    ok('logoutUrl() makes no network request');

    if (false !== strpos($logoutUrl, 'id_token_hint')) {
        ok('it sends id_token_hint');
    } else {
        bad('logoutUrl() sends no id_token_hint; the provider cannot tell'
            . ' which session to end and prompts instead');
    }
    /*
     * The key as well as the value. Asserting only that postLogoutUri() is
     * mentioned passes when it is bound to some other parameter name, and
     * the provider then ends its session and leaves the person on its own
     * page -- a logout that worked and looks broken.
     */
    if (false !== strpos(
        $logoutUrl,
        "'post_logout_redirect_uri'=>OIDC::postLogoutUri(),"
    )) {
        ok('it returns the browser to FOG\'s login page');
    } else {
        bad('logoutUrl() no longer sends a post_logout_redirect_uri built'
            . ' by OIDC::postLogoutUri(); the person ends the journey on'
            . ' the provider\'s own page with no way back to FOG');
    }
    /*
     * Single use. Left in place, a second pass through logout would build
     * the redirect again from a session that is already gone.
     */
    if (false !== strpos($logoutUrl, 'unset($_SESSION[self::LOGOUT_KEY]);')) {
        ok('it clears what it read');
    } else {
        bad('logoutUrl() leaves the stored material in the session');
    }
    /*
     * Re-read the provider. Turning the setting off has to mean from that
     * moment, not from the next time everybody happens to sign in -- and a
     * provider since deleted or disabled must not get a redirect built from
     * a row that no longer says anything.
     */
    if (false !== strpos($logoutUrl, "get('singleLogout')")
        && false !== strpos($logoutUrl, "get('enabled')")
    ) {
        ok('it re-checks the provider row rather than trusting the session');
    } else {
        bad('logoutUrl() trusts the session copy of the setting; turning'
            . ' single logout off would not take effect until every user'
            . ' had signed in again');
    }
}

/*
 * The two landings are different pages and the difference is the whole
 * point, so pin both.
 *
 * postLogoutUri() -> the ORDINARY login page. Single logout has just run,
 * so a forced-redirect install sending the browser back to the provider is
 * correct: the provider now has no session and asks who you are, which is
 * how you sign out and back in as somebody else. Pointing this at
 * login.php instead strands the person on the break-glass page after a
 * perfectly successful logout.
 *
 * localLoginUrl() -> the page no provider setting can redirect away from,
 * used by _fail() and by the single-logout-off fallback, where bouncing to
 * the provider really would loop or silently sign the person back in.
 */
$postLogout = methodBody($model, 'publicstaticfunctionpostLogoutUri()');
if ('' === $postLogout) {
    bad('OIDC::postLogoutUri() is missing');
} elseif (false !== strpos($postLogout, "'management/index.php'")) {
    ok('postLogoutUri() names the ordinary login page');
} else {
    bad('postLogoutUri() no longer points at management/index.php; after a'
        . ' successful single logout the person is stranded on whatever it'
        . ' does name instead of being able to sign in again');
}

$localLogin = methodBody($model, 'publicstaticfunctionlocalLoginUrl()');
if ('' === $localLogin) {
    bad('OIDC::localLoginUrl() is missing');
} elseif (false !== strpos($localLogin, "'management/login.php'")) {
    ok('localLoginUrl() names management/login.php');
} else {
    bad('localLoginUrl() no longer points at management/login.php, the one'
        . ' page a forced-redirect install cannot bounce to the provider');
}
if ($postLogout === $localLogin && '' !== $postLogout) {
    bad('postLogoutUri() and localLoginUrl() return the same thing; they'
        . ' answer different questions and collapsing them re-creates'
        . ' whichever bug the other one was avoiding');
}

/*
 * The callback URI must not move. It is registered at every provider by
 * hand and compared byte for byte, so a refactor that changes its output --
 * even by a slash -- breaks every existing install's sign-in with a
 * provider-side error.
 */
$redirectUri = methodBody($model, 'publicstaticfunctionredirectUri()');
if (false !== strpos($redirectUri, 'self::CALLBACK_PATH')) {
    ok('redirectUri() still builds from CALLBACK_PATH');
} else {
    bad('redirectUri() no longer uses CALLBACK_PATH; this value is'
        . ' registered at providers by hand and compared byte for byte');
}

echo "\n4. the hook wires it to core's seam and to nothing else\n";

if (false !== strpos($hook, "['USER_LOGGING_OUT','providerLogout']")) {
    ok('OIDCLogout registers on USER_LOGGING_OUT');
} else {
    bad('OIDCLogout no longer registers on USER_LOGGING_OUT; nothing calls'
        . ' logoutUrl() and Log out goes back to being FOG-only');
}
if (false !== strpos($hook, 'registerInstalled(')) {
    ok('it registers through registerInstalled()');
} else {
    bad('OIDCLogout registers unconditionally; a plugin that is present but'
        . ' not installed must not touch logout');
}
$listener = methodBody($hook, 'publicfunctionproviderLogout(');
if ('' === $listener) {
    bad('OIDCLogout::providerLogout() is missing');
} else {
    /*
     * The empty case must return without writing. Assigning '' into
     * $arguments['redirect'] would be harmless today only because core
     * checks for an empty string -- but it makes this listener able to
     * overwrite a redirect another listener set, which is a rule about
     * hooks and not about this plugin.
     */
    $guardAt = strpos($listener, "if(''===\$url){return;}");
    $writeAt = strpos($listener, "\$arguments['redirect']=");
    if (false !== $guardAt && false !== $writeAt && $guardAt < $writeAt) {
        ok('it writes the redirect only when there is one');
    } else {
        bad('OIDCLogout::providerLogout() writes to $arguments even with no'
            . ' logout URL, so it can clobber another listener');
    }
}

echo "\n";
if ($fail > 0) {
    echo "FAIL: $fail problem(s), $pass ok\n";
    exit(1);
}
echo "ok: $pass checks passed -- signing out of FOG can end the provider"
    . " session, and only when asked\n";
exit(0);
