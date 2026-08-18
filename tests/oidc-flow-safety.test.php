<?php
/**
 * The OpenID Connect sign-in flow must not be talked out of any of its checks.
 *
 * Two publicly reachable endpoints that end in a logged-in session are the
 * most attackable surface this project has. Every check below is one where
 * removing it leaves a flow that still WORKS -- a real user still signs in,
 * the button still does what it looks like it does -- and only the attack
 * becomes possible. That is what makes them worth pinning: nothing here
 * fails visibly if it regresses.
 *
 *   state       without it, an attacker's authorization code can be planted
 *               in somebody else's browser and they end up signed in as the
 *               attacker (or, with a stolen code, the attacker as them)
 *   nonce       without it, an ID token captured from an earlier sign-in
 *               can be replayed into a new one
 *   PKCE        without the verifier, a code intercepted on the way back
 *               through the browser is enough on its own
 *   iss / aud   without them, a token minted by any provider, or issued to
 *               a different client entirely, is accepted here
 *   single use  the flow values have to be gone from the session before any
 *               validation can fail, or a code can be presented twice
 *   default deny an identity the provider is happy with, and this server
 *               has no account for, is refused
 *
 * The pure parts run for real against stubbed base classes; the flow itself
 * needs a database, a session and a live provider, so it is pinned by
 * inspecting what it does rather than by doing it.
 *
 * Usage: php tests/oidc-flow-safety.test.php
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
 * Method body with comments and whitespace stripped.
 *
 * Comments go first, because the prose above and inside each method names
 * every symbol searched for below and would satisfy the search on its own.
 *
 * @param string $src    the file contents
 * @param string $method the method to extract
 *
 * @return string|null
 */
function methodBody($src, $method)
{
    $t = token_get_all($src);
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
        $out = '';
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
                        return $out;
                    }
                }
            }
            if ($started) {
                $out .= is_array($c) ? $c[1] : $c;
            }
        }
        return $out;
    }
    return null;
}

$flowFile = $root . '/oidc/class/oidcflow.class.php';
$flowSrc = (string)file_get_contents($flowFile);

/*
 * 1. start(): unguessable values, PKCE with S256, and a real CSPRNG.
 */
$start = methodBody($flowSrc, 'start');
$rand = methodBody($flowSrc, '_randomString');
if (null === $start) {
    fail('OIDCFlow::start() is missing');
} else {
    $needs = [
        "'code_challenge_method'=>'S256'" => 'PKCE with S256; without a code'
            . ' challenge an intercepted authorization code is enough on its'
            . ' own',
        "'nonce'=>" => 'a nonce in the authorization request; the ID token'
            . ' check has nothing to compare against without it',
        "'state'=>" => 'a state in the authorization request',
        "'response_type'=>'code'" => 'the authorization code flow; implicit'
            . ' and hybrid put a token in a URL',
    ];
    $flat = preg_replace('#\s+#', '', $start);
    foreach ($needs as $needle => $why) {
        if (false === strpos($flat, $needle)) {
            fail("OIDCFlow::start() no longer sends $why");
        }
    }
}
if (null === $rand || false === strpos($rand, 'random_bytes')) {
    fail(
        'OIDCFlow::_randomString() does not use random_bytes(); state, nonce'
        . ' and the PKCE verifier are unguessability, so rand()/uniqid() are'
        . ' not substitutes'
    );
}

/*
 * 2. callback(): the flow values are cleared BEFORE anything can fail.
 *
 * Order is the whole check. Clearing at the end of a successful sign-in
 * would leave a usable state and verifier in the session after every
 * failure, which is the same authorization code being presentable twice.
 */
$callback = methodBody($flowSrc, 'callback');
if (null === $callback) {
    fail('OIDCFlow::callback() is missing');
} else {
    $flat = preg_replace('#\s+#', '', $callback);
    $unsetAt = strpos($flat, 'unset($_SESSION[self::SESSION_KEY])');
    $tryAt = strpos($flat, 'try{');
    if (false === $unsetAt) {
        fail(
            'OIDCFlow::callback() never clears the stored flow values; the'
            . ' state and PKCE verifier are single use'
        );
    } elseif (false === $tryAt || $unsetAt > $tryAt) {
        fail(
            'OIDCFlow::callback() clears the flow values inside the try'
            . ' block, so a failure leaves them usable and the authorization'
            . ' code can be presented again'
        );
    }
    if (false === strpos($flat, 'hash_equals((string)$flow[\'state\']')) {
        fail(
            'OIDCFlow::callback() does not compare the returned state with'
            . ' hash_equals() against the stored one'
        );
    }
}

/*
 * 3. _verify(): a signature is necessary and nowhere near sufficient.
 */
$verify = methodBody($flowSrc, '_verify');
if (null === $verify) {
    fail('OIDCFlow::_verify() is missing');
} else {
    $flat = preg_replace('#\s+#', '', $verify);
    $checks = [
        'JWT::decode' => 'verify the ID token signature',
        "\$claims['iss']" => 'check the issuer, so a token minted by any'
            . ' provider is not accepted',
        "\$claims['aud']" => 'check the audience, so a token issued to a'
            . ' different client is not accepted',
        "hash_equals((string)\$flow['nonce']" => 'check the nonce, so an ID'
            . ' token from an earlier sign-in cannot be replayed',
        "\$claims['sub']" => 'require a subject',
    ];
    foreach ($checks as $needle => $why) {
        if (false === strpos($flat, str_replace(' ', '', $needle))) {
            fail("OIDCFlow::_verify() does not $why");
        }
    }
    if (false === strpos($flat, 'JWK::parseKeySet')) {
        fail(
            'OIDCFlow::_verify() no longer builds its keys from the'
            . " provider's published key set"
        );
    }
}

/*
 * 4. _discover(): the document has to claim the issuer we asked for, and the
 *    endpoints it names have to be https.
 *
 *    FOGURLRequests follows redirects, so without the issuer comparison a
 *    redirect can hand back somebody else's configuration -- and every
 *    endpoint below, including the one the client secret is posted to, comes
 *    out of that document.
 */
$discover = methodBody($flowSrc, '_discover');
if (null === $discover) {
    fail('OIDCFlow::_discover() is missing');
} else {
    $flat = preg_replace('#\s+#', '', $discover);
    if (false === strpos($flat, "\$config['issuer']??null)!==\$issuer")) {
        fail(
            "OIDCFlow::_discover() no longer checks that the discovery"
            . ' document names the issuer that was asked for'
        );
    }
    if (false === strpos($flat, "stripos(\$value,'https://')")) {
        fail(
            'OIDCFlow::_discover() no longer requires the published'
            . ' endpoints to be https'
        );
    }
}

/*
 * 5. Default deny, and provisioning only where an admin asked for it.
 *
 * An identity with no FOG account is refused unless the provider carries
 * jitProvision, which ships off. The gate is the point: without it the flow
 * creates an account for anybody the provider will authenticate, and the
 * provider's population is not this server's.
 */
$resolve = methodBody($flowSrc, '_resolveUser');
if (null === $resolve) {
    fail('OIDCFlow::_resolveUser() is missing');
} else {
    $flat = preg_replace('#\s+#', '', $resolve);
    if (false === strpos($flat, "getClass('User')")) {
        fail('OIDCFlow::_resolveUser() no longer looks the account up');
    }
    // Searched in the un-flattened body: collapsing whitespace would also
    // collapse it inside the string literal.
    if (false === strpos($resolve, 'No FOG account exists for')) {
        fail(
            'OIDCFlow::_resolveUser() no longer refuses an identity with no'
            . ' FOG account; holding an account at the identity provider is'
            . ' not the same thing as being allowed into FOG'
        );
    }
    $gateAt = strpos($flat, "if(!\$provider->get('jitProvision'))");
    $denyAt = strpos($flat, 'NoFOGaccountexistsfor');
    $makeAt = strpos($flat, '_provisionUser(');
    if (false === $gateAt) {
        fail(
            'OIDCFlow::_resolveUser() no longer gates provisioning on the'
            . " provider's jitProvision column; an account would be created"
            . ' for anybody the provider will authenticate'
        );
    } elseif (false === $denyAt || $denyAt < $gateAt) {
        fail(
            'OIDCFlow::_resolveUser() refuses the identity before consulting'
            . ' jitProvision, or no longer refuses inside the gate'
        );
    } elseif (false === $makeAt || $makeAt < $denyAt) {
        fail(
            'OIDCFlow::_resolveUser() provisions before the refusal branch;'
            . ' the refusal has to be what happens when the column is off'
        );
    }
}

/*
 * 5b. A provisioned account is stamped, an admin-created one is not.
 *
 * users.uAuthSource refuses local password login for the row it is on. On an
 * account this flow created that is right -- its password is a random token
 * nobody has seen, and the stamp stops the leftover row becoming a login if
 * the plugin goes away. On an account an admin created it would take away
 * the password login that break-glass depends on.
 */
$provision = methodBody($flowSrc, '_provisionUser');
if (null === $provision) {
    fail('OIDCFlow::_provisionUser() is missing');
} else {
    $flat = preg_replace('#\s+#', '', $provision);
    if (false === strpos($flat, "set('authsource',OIDC::AUTH_SOURCE)")) {
        fail(
            'OIDCFlow::_provisionUser() no longer stamps users.uAuthSource;'
            . ' the created row would accept a local password'
        );
    }
    if (false === strpos($flat, "set('password',self::getToken(")) {
        fail(
            'OIDCFlow::_provisionUser() no longer sets an unguessable'
            . ' password on the created account'
        );
    }
    // What a provisioned account holds is decided by _applyGrants() from the
    // group claim a moment later, not handed out here.
    if (false !== strpos($flat, "set('roles'")
        || false !== strpos($flat, "set('usergroups'")
    ) {
        fail(
            'OIDCFlow::_provisionUser() assigns roles directly; what a'
            . ' provisioned account holds must come from the group claim'
        );
    }
}
if (false !== strpos((string)$resolve, "set('authsource'")) {
    fail(
        'OIDCFlow::_resolveUser() stamps users.uAuthSource on an account it'
        . ' did not create; that removes local password login from an'
        . ' account an admin made, which is the opposite of break-glass'
    );
}
$flatFlow = preg_replace('#\s+#', '', $flowSrc);
/*
 * The session must not already hold an identity when the new one is
 * established. User::establishSession() prefers the boot-time $FOGUser
 * whenever it is valid, so without this somebody already signed in as one
 * account who completes a sign-in as another silently keeps the first --
 * and the account they land in is not the one just vouched for.
 */
if (false === strpos($flatFlow, 'self::$FOGUser=self::getClass(\'User\',0)')) {
    fail(
        'the flow does not clear the identity loaded at boot before'
        . ' establishing the new session; establishSession() would keep the'
        . ' old one'
    );
}
if (false === strpos($flatFlow, 'establishSession(OIDC::AUTH_SOURCE)')) {
    fail(
        'the flow does not stamp the session with its provenance; an audit'
        . ' could not tell an identity-provider sign-in from a password one'
    );
}

/*
 * 6. The subject lookup must not go through Route's filter builder.
 *
 * _buildSql() turns '*' and '+' in a scalar filter value into a SQL LIKE
 * wildcard, and a subject identifier is an opaque provider-chosen string.
 * A subject containing one would match rows belonging to somebody else --
 * which here means signing in as them.
 */
$identSrc = (string)file_get_contents(
    $root . '/oidc/class/oidcidentity.class.php'
);
$userIdFor = methodBody($identSrc, 'userIdFor');
if (null === $userIdFor) {
    fail('OIDCIdentity::userIdFor() is missing');
} else {
    if (false !== strpos($userIdFor, 'Route::getIds')) {
        fail(
            'OIDCIdentity::userIdFor() looks the subject up through'
            . " Route::getIds(); '*' and '+' in a filter value become SQL"
            . ' LIKE wildcards, and a subject is an opaque provider-chosen'
            . ' string'
        );
    }
    if (false === strpos($userIdFor, ':subject')) {
        fail(
            'OIDCIdentity::userIdFor() no longer binds the subject as a'
            . ' parameter'
        );
    }
    if (false === strpos($userIdFor, 'count($ids) > 1')) {
        fail(
            'OIDCIdentity::userIdFor() no longer refuses a subject linked to'
            . ' more than one account; choosing between them is choosing'
            . " whose account a stranger signs into"
        );
    }
}

/*
 * 7. The routes: under /ext/, declared public, and pointing at the flow.
 */
$routeSrc = (string)file_get_contents(
    $root . '/oidc/hooks/addoidcroutes.hook.php'
);
foreach (['/ext/oidc/start', '/ext/oidc/callback'] as $path) {
    if (false === strpos($routeSrc, "'path' => '$path'")) {
        fail("the route $path is no longer registered");
    }
}
if (2 !== substr_count($routeSrc, "'auth' => 'public'")) {
    fail(
        'both flow routes must declare auth public; anything but that exact'
        . ' string means "required", and a sign-in route that requires a'
        . ' session can never be reached'
    );
}
if (false === strpos($routeSrc, "'enabled' => [1]")) {
    fail(
        'the login page offers providers that are not enabled; a provider an'
        . ' admin is still configuring must not be reachable'
    );
}

/*
 * 8. The webroot, for real. This is the one piece of URL building that can
 *    be wrong without anybody noticing until the callback registered at the
 *    provider turns out to point somewhere that does not exist.
 */
require $root . '/tests/stubs/fog-stubs.php';
require $root . '/oidc/class/oidc.class.php';

FOGController::$settings = ['FOG_WEB_ROOT' => 'fog', 'FOG_WEB_HOST' => 'fog.example'];
$cases = [
    // FOG_WEB_ROOT => [webrootBase, redirectUri]
    'fog' => ['/fog/', 'https://fog.example/fog/ext/oidc/callback'],
    '/fog/' => ['/fog/', 'https://fog.example/fog/ext/oidc/callback'],
    'imaging' => ['/imaging/', 'https://fog.example/imaging/ext/oidc/callback'],
    // Served from the document root. FOGPage::webrootPath() answers 'fog'
    // here, which is why this plugin does not use it.
    '' => ['/', 'https://fog.example/ext/oidc/callback'],
];
foreach ($cases as $setting => $want) {
    FOGController::$settings['FOG_WEB_ROOT'] = $setting;
    $gotBase = OIDC::webrootBase();
    $gotUri = OIDC::redirectUri();
    if ($gotBase !== $want[0] || $gotUri !== $want[1]) {
        fail(
            sprintf(
                'with FOG_WEB_ROOT=%s: base %s / uri %s, expected %s / %s',
                var_export($setting, true),
                var_export($gotBase, true),
                var_export($gotUri, true),
                var_export($want[0], true),
                var_export($want[1], true)
            )
        );
    }
}

/*
 * 9. Claim to role mapping, and the record that makes revocation work.
 */
$targets = methodBody($flowSrc, '_targetsForGroups');
if (null === $targets) {
    fail('OIDCFlow::_targetsForGroups() is missing');
} else {
    if (false !== strpos($targets, 'Route::getIds')) {
        fail(
            'OIDCFlow::_targetsForGroups() looks group values up through'
            . " Route::getIds(); '*' and '+' in a filter value become SQL"
            . " LIKE wildcards, so a claim value of '*' would collect every"
            . " mapping this provider has"
        );
    }
    if (false === strpos($targets, "':' . \$key")
        || false === strpos($targets, ':provider')
    ) {
        fail(
            'OIDCFlow::_targetsForGroups() no longer binds the provider and'
            . ' every group value as parameters'
        );
    }
    if (false === strpos($targets, 'ogProviderID')) {
        fail(
            'OIDCFlow::_targetsForGroups() no longer scopes the lookup to'
            . ' the provider; a group name only means something relative to'
            . ' the directory that published it'
        );
    }
    /*
     * Both queries -- roles and user groups -- have to restrict on the claim
     * values. A query that binds them and then does not use them returns
     * every mapping the provider has, which is every role any of its groups
     * grants, to anybody it will authenticate.
     */
    $flat = preg_replace('#\s+#', '', $targets);
    if (2 !== substr_count($flat, 'IN(\'.$in.\')')) {
        fail(
            'OIDCFlow::_targetsForGroups() has a query that does not restrict'
            . " on the claim values; it would return every one of the"
            . " provider's mappings"
        );
    }
}

/*
 * A scalar group claim is one value. Splitting it would have to guess a
 * delimiter, and every candidate is legal inside a group name -- guessing
 * wrong invents a value that can match a mapping nobody wrote.
 */
$claimGroups = methodBody($flowSrc, '_claimGroups');
if (null === $claimGroups) {
    fail('OIDCFlow::_claimGroups() is missing');
} elseif (preg_match('#\b(explode|preg_split|str_getcsv)\s*\(#', $claimGroups)) {
    fail(
        'OIDCFlow::_claimGroups() splits a scalar group claim; the delimiter'
        . ' would be a guess, and a wrong split invents a group value that'
        . ' can match a mapping nobody wrote'
    );
}

/*
 * The managed set comes from the RECORD, not from the mapping tables.
 * Deriving it from the mappings has a hole an admin hits immediately:
 * remove the last mapping to a role and the role stops being a mapping
 * target, so it drops out of the managed set and everyone already holding
 * it keeps it forever. Removing a mapping reads as "revoke this".
 */
$sync = methodBody($flowSrc, '_syncTargets');
if (null === $sync) {
    fail('OIDCFlow::_syncTargets() is missing');
} else {
    $flat = preg_replace('#\s+#', '', $sync);
    if (false === strpos($flat, '_priorGrants(')) {
        fail(
            'OIDCFlow::_syncTargets() no longer reads the recorded grants;'
            . ' deriving the managed set from the mapping tables means'
            . ' deleting a mapping never revokes what it granted'
        );
    }
    if (false === strpos($flat, 'array_diff($current,$managedRoles)')
        || false === strpos($flat, 'array_diff($current,$managedGroups)')
    ) {
        fail(
            'OIDCFlow::_syncTargets() no longer leaves unmanaged grants'
            . ' alone; an admin would have no way to give a'
            . ' provider-authenticated user anything extra'
        );
    }
}

/*
 * The record is written AFTER the save, because a just-provisioned user has
 * no id before it -- and recording nothing for a new user means their first
 * sign-in grants roles this plugin then cannot take back.
 */
$apply = methodBody($flowSrc, '_applyGrants');
if (null === $apply) {
    fail('OIDCFlow::_applyGrants() is missing');
} else {
    $flat = preg_replace('#\s+#', '', $apply);
    $saveAt = strpos($flat, '$user->save()');
    $recordAt = strpos($flat, '_recordGrants(');
    if (false === $saveAt || false === $recordAt) {
        fail('OIDCFlow::_applyGrants() no longer saves and records');
    } elseif ($recordAt < $saveAt) {
        fail(
            'OIDCFlow::_applyGrants() records the grants before the save; a'
            . ' just-provisioned user has no id until then, so the record'
            . ' would be written against user 0'
        );
    }
}
$flatCallback = preg_replace('#\s+#', '', (string)$callback);
$grantAt = strpos($flatCallback, '_applyGrants(');
$sessionAt = strpos($flatCallback, 'establishSession(');
if (false === $grantAt) {
    fail(
        'OIDCFlow::callback() never applies the group claim; a sign-in would'
        . ' keep whatever roles the account happened to hold'
    );
} elseif (false === $sessionAt || $grantAt > $sessionAt) {
    fail(
        'OIDCFlow::callback() establishes the session before applying the'
        . ' grants, so the first request after sign-in sees the old roles'
    );
}

/*
 * 10. The two tables whose indexes decide whether a write destroys anything.
 *
 * Run for real against the Schema stub, which records what it was asked for.
 */
require $root . '/oidc/class/oidcgroupmanager.class.php';
require $root . '/oidc/class/oidcusergrantmanager.class.php';

$indexCases = [
    // class => [table, expected unique index, why]
    'OIDCGroupManager' => [
        'OIDCGroups',
        [['ogProviderID', 'ogName']],
        'one mapping per (provider, group value). The key covers every'
        . ' non-id column, so the ON DUPLICATE KEY UPDATE half of a save'
        . ' can only rewrite what it matched on -- which is why a unique'
        . ' index is safe here and is deliberately absent from'
        . ' OIDCProviders'
    ],
    'OIDCUserGrantManager' => [
        'oidcUserGrant',
        [['ougUserID', 'ougTargetType', 'ougTargetID']],
        'the sync rewrites a user\'s grants with plain INSERT IGNORE after'
        . ' clearing them, and a double sign-in must not record the same'
        . ' grant twice'
    ],
];
foreach ($indexCases as $class => $want) {
    Schema::$lastCall = [];
    $manager = new $class();
    $manager->createSql();
    $args = Schema::$lastCall;
    if (($args[0] ?? null) !== $want[0]) {
        fail(
            sprintf(
                '%s::createSql() builds table %s, expected %s',
                $class,
                var_export($args[0] ?? null, true),
                var_export($want[0], true)
            )
        );
        continue;
    }
    // Schema::createTable($table, $ifNotExists, $cols, $types, $notNulls,
    // $defaults, $indexes, ...) -- the index list is argument seven.
    if (($args[6] ?? null) !== $want[1]) {
        fail(
            sprintf(
                '%s::createSql() declares unique index %s, expected %s: %s',
                $class,
                var_export($args[6] ?? null, true),
                var_export($want[1], true),
                $want[2]
            )
        );
    }
}

/*
 * N. Query parameters come from Route::queryParam(), never filter_input().
 *
 * Both entry points are reached by an internal rewrite to api/index.php.
 * On nginx that rewrite handed the router an EMPTY query string, so
 * filter_input(INPUT_GET, 'provider') returned null and start() refused a
 * configured, enabled provider as "Unknown identity provider"; callback()
 * would have lost state, code and error the same way. fogproject#1163
 * fixes the vhost, but only for a server that re-runs the installer, and
 * Route::queryParam() is what recovers the value from REQUEST_URI on every
 * server that does not.
 */
$flowCode = '';
foreach (token_get_all($flowSrc) as $tok) {
    // Comments stripped first: this file's own docblock names the wrong
    // call so a reader knows what not to write, and a gate that reads its
    // own documentation as a violation is a gate nobody can document.
    if (is_array($tok)
        && ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT)
    ) {
        continue;
    }
    $flowCode .= is_array($tok) ? $tok[1] : $tok;
}
if (false !== strpos($flowCode, 'filter_input(INPUT_GET')) {
    fail(
        'OIDCFlow reads a query parameter with filter_input(INPUT_GET, ...),'
        . ' which is empty on a routed request behind an nginx vhost that'
        . ' predates fogproject#1163 -- use Route::queryParam()'
    );
}
foreach (['provider', 'error', 'state', 'code'] as $param) {
    if (false === strpos($flowCode, "Route::queryParam('" . $param . "')")) {
        fail(
            sprintf(
                'OIDCFlow no longer reads the %s query parameter through'
                . ' Route::queryParam()',
                $param
            )
        );
    }
}

if (count($fails) > 0) {
    fwrite(STDERR, 'FAIL: ' . count($fails) . " problem(s):\n");
    foreach ($fails as $f) {
        fwrite(STDERR, "  - $f\n");
    }
    exit(1);
}

echo "ok: the OpenID Connect flow keeps its checks\n";
exit(0);
