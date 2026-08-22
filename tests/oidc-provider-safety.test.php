<?php
/**
 * The OpenID Connect provider row must fail closed.
 *
 * A provider row is a description of who may become an administrator of this
 * FOG server, so the ways it can be wrong are not cosmetic. This gate pins
 * the four that are silent -- each one produces either a working-looking
 * login or no visible symptom at all:
 *
 *   1. An http:// issuer. Everything the flow trusts is fetched over that
 *      URL: the discovery document naming the token endpoint, the JWKS the
 *      ID token signature is checked against, and the token exchange
 *      carrying the client secret. On http:// somebody on the path serves
 *      their own signing keys and mints a token for any account they like,
 *      and every signature check still passes, because they chose the key.
 *   2. A scope list without 'openid'. The provider then runs a plain OAuth 2
 *      authorization, returns no ID token, and the failure looks like
 *      anything except a scope problem.
 *   3. Provider or JIT provisioning defaulting on. "Enabled" defaulting on
 *      would make a half-typed provider live while the admin is still
 *      pasting the secret; JIT defaulting on would mean holding an account
 *      at the identity provider IS an account on this FOG server, which is
 *      the opposite of the default-deny position.
 *   4. The client secret leaving the server, or being blanked by an edit
 *      that never touched it.
 *
 * Runs without a database and without a FOG checkout: the model's rules are
 * pure statics, so stubbing the two base classes is enough to call them for
 * real rather than reading the source and hoping.
 *
 * Usage: php tests/oidc-provider-safety.test.php
 * Exit status 0 = pass, 1 = fail.
 */

$root = dirname(__DIR__);
$fails = [];

// gettext is not loaded in every CLI, and the model calls _() when it
// throws. Only define the fallback if the extension has not supplied one.
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

require $root . '/tests/stubs/fog-stubs.php';

require $root . '/oidc/class/oidc.class.php';
require $root . '/oidc/class/oidcmanager.class.php';

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
 * Asserts a callable throws.
 *
 * @param callable $fn   the thing to run
 * @param string   $what what was being rejected
 *
 * @return void
 */
function refuses(callable $fn, $what)
{
    try {
        $fn();
    } catch (\Exception $e) {
        return;
    }
    fail("$what was accepted");
}

/**
 * Asserts a callable does not throw.
 *
 * @param callable $fn   the thing to run
 * @param string   $what what was being accepted
 *
 * @return void
 */
function accepts(callable $fn, $what)
{
    try {
        $fn();
    } catch (\Exception $e) {
        fail("$what was refused: " . $e->getMessage());
    }
}

// 1. The issuer.
accepts(
    function () {
        OIDC::assertValidIssuer('https://login.example.com/realms/fog');
    },
    'a plain https issuer'
);
$badIssuers = [
    '' => 'an empty issuer',
    'http://login.example.com' => 'an http:// issuer',
    'HTTP://login.example.com' => 'an upper-case http:// issuer',
    'login.example.com' => 'an issuer with no scheme',
    '//login.example.com' => 'a protocol-relative issuer',
    'ftp://login.example.com' => 'an ftp:// issuer',
    'https://idp/realm?tenant=1' => 'an issuer carrying a query string',
    'https://idp/realm#frag' => 'an issuer carrying a fragment',
];
foreach ($badIssuers as $issuer => $what) {
    refuses(
        function () use ($issuer) {
            OIDC::assertValidIssuer($issuer);
        },
        $what
    );
}
refuses(
    function () {
        OIDC::assertValidIssuer('https://' . str_repeat('a', 250) . '.com');
    },
    'an issuer too long for its column'
);

// 2. The scope list. openid is added rather than demanded, because a list
//    missing it is a typo and the failure it causes is unrecognisable.
$scopeCases = [
    'profile email' => 'openid profile email',
    '' => 'openid',
    'openid profile' => 'openid profile',
    "openid  profile\temail" => 'openid profile email',
    'openid openid profile' => 'openid profile',
];
foreach ($scopeCases as $in => $want) {
    $got = OIDC::normalizeScopes($in);
    if ($got !== $want) {
        fail(
            sprintf(
                'normalizeScopes(%s) returned %s, expected %s',
                var_export($in, true),
                var_export($got, true),
                var_export($want, true)
            )
        );
    }
}

// 3. Claim names. The one that matters is the empty case: an empty claim
//    matches nothing, so every login would be denied with no explanation.
accepts(
    function () {
        OIDC::assertValidClaim('preferred_username', 'user claim');
    },
    'preferred_username'
);
foreach (['', '   ', '-leading', str_repeat('a', 65)] as $claim) {
    refuses(
        function () use ($claim) {
            OIDC::assertValidClaim($claim, 'user claim');
        },
        'claim name ' . var_export($claim, true)
    );
}

// 4. save() applies all of the above, so a REST write is covered by the same
//    rules as the form. Checked by calling it, not by reading it.
refuses(
    function () {
        $o = new OIDC();
        $o->set('issuer', 'http://idp.example.com')
            ->set('clientId', 'fog')
            ->set('userClaim', 'preferred_username')
            ->save();
    },
    'save() with an http:// issuer'
);
refuses(
    function () {
        $o = new OIDC();
        $o->set('issuer', 'https://idp.example.com')
            ->set('clientId', '')
            ->set('userClaim', 'preferred_username')
            ->save();
    },
    'save() with no client ID'
);
$saved = new OIDC();
$saved->set('issuer', 'https://idp.example.com/realms/fog/')
    ->set('clientId', ' fog ')
    ->set('scopes', 'profile')
    ->set('userClaim', ' preferred_username ')
    ->set('groupClaim', '')
    ->save();
if ('https://idp.example.com/realms/fog' !== $saved->get('issuer')) {
    fail('save() did not strip the trailing slash from the issuer');
}
if ('fog' !== $saved->get('clientId')) {
    fail('save() did not trim the client ID');
}
if ('openid profile' !== $saved->get('scopes')) {
    fail('save() did not normalise the scope list');
}
if ('preferred_username' !== $saved->get('userClaim')) {
    fail('save() did not trim the user claim');
}

// 5. The column defaults, read out of what the manager asks Schema for.
//    Positional, because that is how Schema::createTable() is called.
(new OIDCManager())->createSql();
$call = Schema::$lastCall;
if (count($call) < 7) {
    fail('OIDCManager::createSql() did not reach Schema::createTable()');
} else {
    list(, , $cols, , , $defaults, $uniques) = $call;
    $defaultFor = array_combine($cols, $defaults);
    $mustBeOff = [
        'opEnabled' => 'a provider must be created switched off',
        'opJITProvision' => 'just-in-time provisioning must ship off, or an '
            . 'account at the identity provider is an account on this server',
        'opAllowAPI' => 'API access must not be granted by default'
    ];
    foreach ($mustBeOff as $column => $why) {
        if (!isset($defaultFor[$column])) {
            fail("$column is not a column on OIDCProviders");
            continue;
        }
        if ("'0'" !== $defaultFor[$column]) {
            fail("$column does not default to '0' -- $why");
        }
    }
    if ('preferred_username' !== trim((string)$defaultFor['opUserClaim'], "'")) {
        fail('opUserClaim does not default to preferred_username');
    }
    if (false === strpos((string)$defaultFor['opScopes'], 'openid')) {
        fail('opScopes does not default to a list containing openid');
    }
    /*
     * No UNIQUE index anywhere on this table. FOGController::save() issues
     * an INSERT ... ON DUPLICATE KEY UPDATE, so a unique column turns
     * "create a second provider with a name already in use" into a silent
     * overwrite of the first one rather than an error -- and the first one
     * is a working login.
     */
    foreach ((array)$uniques as $i => $unique) {
        if ($unique) {
            fail(
                sprintf(
                    'OIDCProviders declares a UNIQUE index on %s; with '
                    . 'INSERT ... ON DUPLICATE KEY UPDATE that makes a '
                    . 'create silently overwrite an existing provider',
                    isset($cols[$i]) ? $cols[$i] : "column $i"
                )
            );
        }
    }
}

// 6. The client secret: declared in the tier that is stripped from a single
//    GET too, kept out of the export, and never echoed back into the form.
$apiHook = (string)file_get_contents($root . '/oidc/hooks/addoidcapi.hook.php');
if (false === strpos($apiHook, "\$arguments['always'][\$this->node][] = 'clientSecret'")) {
    fail(
        'the client secret is not declared in the API_SENSITIVE_FIELDS '
        . "'always' tier; the ordinary tier still emits it on a direct GET"
    );
}
if (false === strpos($apiHook, 'stripClientSecret')) {
    fail('the client secret is not stripped from the CSV export');
}
$page = (string)file_get_contents(
    $root . '/oidc/pages/oidcmanagement.page.php'
);
if (false !== strpos($page, "\$get('clientSecret')")) {
    fail(
        'the edit form renders the stored client secret back into the page; '
        . 'it must show the unchanged-placeholder instead'
    );
}
if (false === strpos($page, 'SECRET_UNCHANGED !== $secret')) {
    fail(
        'the edit form does not guard the secret write, so saving any other '
        . 'field would blank the credential'
    );
}

/*
 * clientId must stay declared as a string.
 *
 * FOGController::save() reads any key ending in "id" as an integer foreign
 * key unless the model opts out, and clientId is required -- so dropping the
 * opt-out does not degrade anything, it makes creating a provider impossible,
 * reported as "Required database field is empty: clientId" about a field the
 * admin filled in. That is a whole feature off, so it is pinned here rather
 * than left to be rediscovered. Needs fogproject#1153.
 */
$declared = [];
if (property_exists('OIDC', 'databaseFieldsNotInt')) {
    $notInt = new \ReflectionProperty('OIDC', 'databaseFieldsNotInt');
    $notInt->setAccessible(true);
    $declared = array_map('strtolower', (array)$notInt->getValue(new OIDC()));
}
if (!in_array('clientid', $declared, true)) {
    fail(
        'OIDC does not declare clientId in $databaseFieldsNotInt, so '
        . 'save() will reject every provider whose client id is not a number'
    );
}

if (count($fails) > 0) {
    fwrite(STDERR, 'FAIL: ' . count($fails) . " problem(s):\n");
    foreach ($fails as $f) {
        fwrite(STDERR, "  - $f\n");
    }
    exit(1);
}

echo "ok: OpenID Connect provider rows fail closed\n";
exit(0);
