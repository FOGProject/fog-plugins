<?php
/**
 * The OpenID Connect authorization code flow.
 *
 * PHP version 7.4+
 *
 * @category OIDCFlow
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

namespace FOG\Plugins\OIDC\Util;

use FOG\Plugins\OIDC\Items\OIDC;
use FOG\Plugins\OIDC\Items\OIDCIdentity;
use FOG\Plugins\OIDC\Items\OIDCUserGrant;

/**
 * The OpenID Connect authorization code flow.
 *
 * Two entry points, both reached as plugin API routes under /ext/ and both
 * declared public, because the whole point is that they are used by somebody
 * who has no session yet:
 *
 *   start()     mint the one-time values, send the browser to the provider
 *   callback()  take the code back, prove the ID token, sign the user in
 *
 * Authorization code flow with PKCE. The implicit and hybrid flows are not
 * offered: they put a token in a URL, which puts it in the browser history,
 * the Referer header and every proxy log between here and there.
 *
 * @category OIDCFlow
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class OIDCFlow extends \FOG\Base\FOGBase
{
    /**
     * Where the one-time flow values live between the two requests.
     *
     * @var string
     */
    const SESSION_KEY = 'FOG_OIDC_FLOW';
    /**
     * Where the material for RP-initiated logout is kept (#15).
     *
     * Separate from SESSION_KEY because it has the opposite lifetime: the
     * flow values are single use and deleted the moment the callback reads
     * them, while this has to survive for as long as the session it belongs
     * to -- it is read at logout, which may be days later.
     *
     * @var string
     */
    const LOGOUT_KEY = 'FOG_OIDC_LOGOUT';
    /**
     * How long a started flow stays usable, in seconds.
     *
     * Long enough to type a password and answer an MFA prompt at the
     * provider, short enough that an abandoned flow does not leave a usable
     * state value sitting in a session for the rest of the day.
     *
     * @var int
     */
    const FLOW_TTL = 600;
    /**
     * Seconds of clock skew tolerated when checking token times.
     *
     * @var int
     */
    const CLOCK_SKEW = 60;
    /**
     * Seconds to wait on the provider, per request.
     *
     * Explicit because FOG_URL_BASE_TIMEOUT defaults to a day, which is a
     * reasonable ceiling for pulling a kernel off GitHub and an unreasonable
     * one for a login: a provider that stops answering would hold the
     * request, and its php-fpm worker, until something else gave up.
     *
     * @var int
     */
    const HTTP_TIMEOUT = 15;
    /**
     * The largest response this flow will try to parse, in bytes.
     *
     * A discovery document or a key set is a few kilobytes; anything wildly
     * larger is not one, and json_decode on it is wasted work. Deliberately
     * NOT described as a download limit -- curl has already read the body by
     * the time this is checked, and imposing a real transfer cap through
     * FOGURLRequests would mean reaching past its interface. The timeout is
     * what bounds a hostile provider here.
     *
     * @var int
     */
    const MAX_RESPONSE = 262144;
    /**
     * Send the browser to the provider.
     *
     * Every query parameter here and in callback() is read through
     * Route::queryParam(), never filter_input(INPUT_GET). A route under
     * /ext/ is reached by an internal rewrite to api/index.php, and on
     * nginx that rewrite used to hand the router an EMPTY query string --
     * so ?provider=3 arrived as nothing and a configured, enabled provider
     * was refused as "Unknown identity provider". fogproject#1163 gives the
     * installer's vhost $is_args$args, but a server that has not re-run the
     * installer keeps the old one, and queryParam() is what recovers the
     * value from REQUEST_URI on those. Apache carries QSA and never had the
     * problem; this code cannot tell which it is running under.
     *
     * @return void
     */
    public static function start()
    {
        self::_session();
        try {
            $provider = self::_enabledProvider(
                (int)filter_var(
                    (string)\FOG\Router\Route::queryParam('provider'),
                    FILTER_VALIDATE_INT
                )
            );
            $config = self::_discover($provider);

            // Fetching closed the session (FOGURLRequests releases the lock
            // so a called FOG endpoint can read it), so reopen before
            // writing. Everything below has to survive to the callback.
            self::_session();
            $verifier = self::_randomString();
            $flow = [
                'provider' => (int)$provider->get('id'),
                'state' => self::_randomString(),
                'nonce' => self::_randomString(),
                'verifier' => $verifier,
                'created' => time()
            ];
            $_SESSION[self::SESSION_KEY] = $flow;

            $query = [
                'response_type' => 'code',
                'client_id' => $provider->get('clientId'),
                'redirect_uri' => OIDC::redirectUri(),
                'scope' => $provider->get('scopes'),
                'state' => $flow['state'],
                'nonce' => $flow['nonce'],
                // PKCE. The provider will not exchange the code without the
                // verifier, so a code intercepted on its way back through the
                // browser is not enough on its own.
                'code_challenge' => self::_b64url(
                    hash('sha256', $verifier, true)
                ),
                'code_challenge_method' => 'S256'
            ];
            self::_redirect(
                $config['authorization_endpoint']
                . (false === strpos($config['authorization_endpoint'], '?')
                    ? '?'
                    : '&')
                . http_build_query($query)
            );
        } catch (\Exception $e) {
            self::_fail($e->getMessage());
        }
    }
    /**
     * Take the authorization code back and sign the user in.
     *
     * @return void
     */
    public static function callback()
    {
        self::_session();
        /*
         * Read and CLEAR before anything else can fail. The state and the
         * verifier are single use; leaving them in the session after a
         * failure would let the same authorization code be presented again.
         */
        $flow = $_SESSION[self::SESSION_KEY] ?? null;
        unset($_SESSION[self::SESSION_KEY]);
        session_write_close();

        try {
            if (!is_array($flow) || empty($flow['state'])) {
                throw new \Exception(
                    _('No sign-in was in progress; please start again')
                );
            }
            if (time() - (int)$flow['created'] > self::FLOW_TTL) {
                throw new \Exception(
                    _('The sign-in took too long; please start again')
                );
            }
            $error = trim((string)\FOG\Router\Route::queryParam('error'));
            if ('' !== $error) {
                // The provider's own words, which are the useful ones --
                // 'access_denied' means somebody pressed cancel.
                throw new \Exception(
                    sprintf(
                        _('The identity provider refused the sign-in (%s)'),
                        $error
                    )
                );
            }
            $state = (string)\FOG\Router\Route::queryParam('state');
            if (!hash_equals((string)$flow['state'], $state)) {
                // Constant time, and the message says nothing about which
                // half was wrong.
                throw new \Exception(_('The sign-in could not be verified'));
            }
            $code = (string)\FOG\Router\Route::queryParam('code');
            if ('' === $code) {
                throw new \Exception(_('The identity provider sent no code'));
            }

            $provider = self::_enabledProvider((int)$flow['provider']);
            $config = self::_discover($provider);
            $token = self::_exchange($provider, $config, $code, $flow);
            $claims = self::_verify($provider, $config, $token, $flow);
            $user = self::_resolveUser($provider, $claims);
            self::_applyGrants($provider, $claims, $user);

            /*
             * An identity already in this session must not decide the new
             * one. User::establishSession() prefers the boot-time $FOGUser
             * whenever it is valid, so somebody already signed in as one
             * account who completes a sign-in as another would silently keep
             * the first -- the account they end up in would not be the one
             * the identity provider just vouched for. Reachable easily
             * enough: a second tab, or a bookmarked start URL.
             *
             * Both halves have to go. Emptying $_SESSION alone leaves the
             * static, which was populated from it at boot and is what
             * establishSession() actually reads.
             */
            self::_session();
            $_SESSION = [];
            self::$FOGUser = self::getClass('User', 0);

            // Provenance, not decoration: an audit has to be able to tell
            // this session from a password one, and the break-glass rules
            // count sessions by how they were made.
            $user->establishSession(OIDC::AUTH_SOURCE);

            /*
             * After establishSession(), not before: the wipe above empties
             * $_SESSION wholesale, so anything written earlier in this
             * request would be thrown away with the identity it was
             * guarding against.
             */
            self::_rememberLogout($provider, $config, $token);

            self::_redirect(
                OIDC::webrootBase() . 'management/index.php'
            );
        } catch (\Exception $e) {
            self::_fail($e->getMessage());
        }
    }
    /**
     * Stores what RP-initiated logout will need, if it is wanted.
     *
     * Recorded now rather than fetched at logout, and that is the whole
     * design. Discovery is a network request; putting one on the sign-out
     * path means a provider that has gone away turns "log out" into a page
     * that hangs and then fails, at the exact moment somebody is trying to
     * leave. Everything needed is already in hand here.
     *
     * The ID token is kept because id_token_hint is what tells the provider
     * WHICH session to end, and it is the only thing that lets it skip the
     * "are you sure you want to sign out?" interstitial. It is a token this
     * session already holds the fruits of; the session file is not a weaker
     * place to keep it than the authenticated session itself.
     *
     * @param OIDC  $provider the provider signed in with
     * @param array $config   its discovery document
     * @param array $token    the token response
     *
     * @return void
     */
    private static function _rememberLogout($provider, array $config, array $token)
    {
        if ('1' !== (string)$provider->get('singleLogout')) {
            return;
        }
        $endpoint = (string)($config['end_session_endpoint'] ?? '');
        if (0 !== stripos($endpoint, 'https://')) {
            /*
             * An admin turned this on and it cannot work -- the provider
             * publishes no end_session_endpoint, or publishes a plaintext
             * one. Logging out will silently just be a FOG logout, which is
             * indistinguishable from the setting being off, so say so
             * somewhere an admin can find it. Not a thrown exception: the
             * sign-in itself succeeded and refusing it here would turn a
             * logout limitation into a login failure.
             */
            error_log(
                sprintf(
                    'FOG OIDC: provider %d has single logout enabled but'
                    . ' published no https end_session_endpoint; signing out'
                    . ' of FOG will not end the provider session',
                    (int)$provider->get('id')
                )
            );
            return;
        }
        $_SESSION[self::LOGOUT_KEY] = [
            'provider' => (int)$provider->get('id'),
            'endpoint' => $endpoint,
            'idToken' => (string)$token['id_token']
        ];
    }
    /**
     * The one provider the login page must redirect to, or 0 for none.
     *
     * Shared by the login-page listener and the logout listener, because
     * both have to know the same thing: whether landing on
     * management/index.php would bounce the visitor straight to a provider.
     *
     * TWO providers flagged is refused rather than resolved. The login page
     * cannot redirect to both, and silently picking one -- lowest id, first
     * row, whatever -- hides a misconfiguration on the single page an admin
     * is least able to debug, while sending everybody to a provider half of
     * them may not have an account at. Refusing renders FOG's own form,
     * which is a working login for everyone and visibly not what was asked
     * for.
     *
     * The complaint goes to the error log and NOT to the page. This runs
     * for an anonymous visitor, and "this server has two misconfigured
     * identity providers" is not something to tell one.
     *
     * @return int the provider id, or 0
     */
    public static function forcedProvider()
    {
        $ids = (array)\FOG\Router\Route::getIds(
            'oidc',
            ['enabled' => [1], 'autoRedirect' => [1]]
        );
        if (count($ids) < 1) {
            return 0;
        }
        if (count($ids) > 1) {
            error_log(
                sprintf(
                    'FOG OIDC: providers %s all have automatic redirect'
                    . ' enabled; the login page cannot redirect to more than'
                    . ' one, so it is showing the local form instead',
                    implode(', ', array_map('intval', $ids))
                )
            );
            return 0;
        }
        return (int)reset($ids);
    }
    /**
     * Where the login page should send an anonymous visitor, or ''.
     *
     * Consumed by core's LOGIN_PAGE_REDIRECT seam (fogproject#1175), which
     * fires only for a visitor who is NOT signed in and only on the form
     * render -- so this can neither bounce a working session nor interrupt
     * the callback coming back from the provider.
     *
     * The row is re-read and re-checked rather than trusted from the id,
     * for the same reason _enabledProvider() re-checks at the start of every
     * flow: a provider disabled a moment ago must not still be receiving
     * people.
     *
     * @return string
     */
    public static function loginRedirectUrl()
    {
        $id = self::forcedProvider();
        if ($id < 1) {
            return '';
        }
        $provider = self::getClass('OIDC', $id);
        if (!$provider->isValid()
            || '1' !== (string)$provider->get('enabled')
            || '1' !== (string)$provider->get('autoRedirect')
        ) {
            return '';
        }
        return OIDC::startUrl($id);
    }
    /**
     * The provider logout URL for this session, or '' for none.
     *
     * Called from the USER_LOGGING_OUT listener, which core fires BEFORE it
     * destroys the session -- so the values stored at callback time are
     * still readable here, and this is the last moment they are.
     *
     * The provider row is re-read rather than trusted from the session. An
     * admin who turns single logout off means it from that moment, not from
     * the next time everybody happens to sign in; and a provider that has
     * since been deleted or disabled must not have a URL built from a row
     * that no longer says anything.
     *
     * @return string
     */
    public static function logoutUrl()
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }
        $stored = $_SESSION[self::LOGOUT_KEY] ?? null;
        // Single use. A second call must not produce a second redirect, and
        // the session is about to be destroyed anyway.
        unset($_SESSION[self::LOGOUT_KEY]);
        if (!is_array($stored) || empty($stored['endpoint'])) {
            return '';
        }
        $provider = self::getClass('OIDC', (int)($stored['provider'] ?? 0));
        if (!$provider->isValid()
            || '1' !== (string)$provider->get('enabled')
            || '1' !== (string)$provider->get('singleLogout')
        ) {
            return '';
        }
        $query = [
            'id_token_hint' => (string)$stored['idToken'],
            'post_logout_redirect_uri' => OIDC::postLogoutUri(),
            // Sent alongside id_token_hint because some providers key the
            // post-logout redirect allow-list on the client rather than on
            // the token, and it is ignored by the ones that do not.
            'client_id' => (string)$provider->get('clientId')
        ];
        return $stored['endpoint']
            . (false === strpos($stored['endpoint'], '?') ? '?' : '&')
            . http_build_query($query);
    }
    /**
     * Starts (or resumes) the session this flow needs.
     *
     * A route under /ext/ gets no session of its own: api/index.php does not
     * declare FOG_WANTS_SESSION, and a visitor who has not signed in yet
     * carries no cookie, so core's session gate correctly declines to make
     * one. That gate exists to stop BROWSER-LESS callers -- iPXE, the fog
     * client -- minting sessions they can never present back. This handler
     * is mid-redirect in a real browser, which is exactly the case the gate
     * is not about, so it asks for one explicitly.
     *
     * @return void
     */
    private static function _session()
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }
    /**
     * Loads a provider and refuses one that is not usable.
     *
     * @param int $id the provider id
     *
     * @throws Exception
     * @return OIDC
     */
    private static function _enabledProvider($id)
    {
        $provider = self::getClass('OIDC', (int)$id);
        if (!$provider->isValid()) {
            throw new \Exception(_('Unknown identity provider'));
        }
        if (!$provider->get('enabled')) {
            // Checked on the callback as well as the start, so disabling a
            // provider ends flows already in progress rather than only
            // stopping new ones.
            throw new \Exception(
                _('That identity provider is not enabled')
            );
        }
        return $provider;
    }
    /**
     * Reads the provider's discovery document.
     *
     * Fetched per sign-in rather than cached. A cache would save two
     * requests and introduce the failure it is famous for: the provider
     * rotates its signing keys, the cached key set no longer contains the
     * one in use, and every login fails until the entry expires. Two extra
     * round trips at sign-in time is the cheaper side of that trade, and
     * this is not a hot path.
     *
     * @param OIDC $provider the provider
     *
     * @throws Exception
     * @return array the discovery document
     */
    private static function _discover($provider)
    {
        $issuer = (string)$provider->get('issuer');
        $config = self::_getJson(
            $issuer . '/.well-known/openid-configuration'
        );
        /*
         * The document's own issuer must be the one we asked. This is the
         * check that stops a redirect (FOGURLRequests follows up to five)
         * from silently handing us somebody else's configuration -- the
         * endpoints below all come out of this document, so without it the
         * document decides where the client secret gets posted.
         */
        if (($config['issuer'] ?? null) !== $issuer) {
            throw new \Exception(
                _('The provider returned a configuration for a different issuer')
            );
        }
        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $key) {
            $value = (string)($config[$key] ?? '');
            if (0 !== stripos($value, 'https://')) {
                throw new \Exception(
                    sprintf(
                        _('The provider published no https %s'),
                        str_replace('_', ' ', $key)
                    )
                );
            }
        }
        return $config;
    }
    /**
     * Trades the authorization code for tokens.
     *
     * @param OIDC   $provider the provider
     * @param array  $config   its discovery document
     * @param string $code     the authorization code
     * @param array  $flow     the stored flow values
     *
     * @throws Exception
     * @return array the token response
     */
    private static function _exchange($provider, array $config, $code, array $flow)
    {
        $body = self::_post(
            $config['token_endpoint'],
            http_build_query(
                [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => OIDC::redirectUri(),
                    'client_id' => $provider->get('clientId'),
                    'client_secret' => $provider->get('clientSecret'),
                    'code_verifier' => $flow['verifier']
                ]
            )
        );
        $token = json_decode($body, true);
        if (!is_array($token)) {
            throw new \Exception(
                _('The identity provider sent an unreadable token response')
            );
        }
        if (empty($token['id_token'])) {
            throw new \Exception(
                sprintf(
                    _('The identity provider returned no ID token (%s)'),
                    (string)($token['error'] ?? _('no reason given'))
                )
            );
        }
        return $token;
    }
    /**
     * Proves the ID token and returns its claims.
     *
     * The signature check is firebase/php-jwt's; everything after it is the
     * part a library cannot do for you, because it depends on who we think
     * we are talking to.
     *
     * @param OIDC  $provider the provider
     * @param array $config   its discovery document
     * @param array $token    the token response
     * @param array $flow     the stored flow values
     *
     * @throws Exception
     * @return array the ID token claims
     */
    private static function _verify($provider, array $config, array $token, array $flow)
    {
        $keys = \Firebase\JWT\JWK::parseKeySet(
            self::_getJson($config['jwks_uri'])
        );
        \Firebase\JWT\JWT::$leeway = self::CLOCK_SKEW;
        try {
            $claims = (array)json_decode(
                json_encode(
                    \Firebase\JWT\JWT::decode($token['id_token'], $keys)
                ),
                true
            );
        } catch (\Exception $e) {
            // Deliberately not passed through: a signature failure, an
            // expired token and an unknown key are the same answer to the
            // person in front of the browser, and the detail belongs in the
            // log.
            error_log('FOG OIDC: ID token rejected -- ' . $e->getMessage());
            throw new \Exception(_('The ID token could not be verified'));
        }
        if (($claims['iss'] ?? null) !== (string)$provider->get('issuer')) {
            throw new \Exception(_('The ID token names a different issuer'));
        }
        /*
         * aud is a string or an array of strings, and a token issued to
         * somebody else is a token that says nothing about who may sign in
         * here.
         */
        $aud = $claims['aud'] ?? [];
        if (!in_array(
            (string)$provider->get('clientId'),
            array_map('strval', (array)$aud),
            true
        )) {
            throw new \Exception(_('The ID token was issued to someone else'));
        }
        // azp identifies the party the token was issued FOR when aud has
        // more than one value; if it is present it has to be us.
        if (isset($claims['azp'])
            && (string)$claims['azp'] !== (string)$provider->get('clientId')
        ) {
            throw new \Exception(_('The ID token was issued to someone else'));
        }
        if (!isset($claims['nonce'])
            || !hash_equals((string)$flow['nonce'], (string)$claims['nonce'])
        ) {
            // Without this a token captured from an earlier sign-in can be
            // replayed into a new one.
            throw new \Exception(_('The ID token could not be verified'));
        }
        if ('' === trim((string)($claims['sub'] ?? ''))) {
            throw new \Exception(_('The ID token carries no subject'));
        }
        return $claims;
    }
    /**
     * Turns verified claims into the FOG user they identify.
     *
     * Default deny, and this is the method that means it: an identity the
     * provider is happy with, that this server has no account for, is
     * refused. Holding an account at the identity provider is not the same
     * thing as being allowed into FOG. Turning on jitProvision is an admin
     * saying otherwise for one provider, and it ships off.
     *
     * @param OIDC  $provider the provider
     * @param array $claims   the verified claims
     *
     * @throws Exception
     * @return User
     */
    private static function _resolveUser($provider, array $claims)
    {
        $subject = (string)$claims['sub'];
        $claimName = (string)$provider->get('userClaim');
        $username = trim((string)($claims[$claimName] ?? ''));
        if ('' === $username) {
            throw new \Exception(
                sprintf(
                    _('The identity provider sent no %s claim'),
                    $claimName
                )
            );
        }

        $linkedId = OIDCIdentity::userIdFor($provider->get('id'), $subject);
        $byName = self::getClass('User')
            ->set('name', $username)
            ->load('name');
        $namedId = $byName->isValid() ? (int)$byName->get('id') : 0;

        if ($linkedId > 0) {
            /*
             * Second and later sign-ins. The recorded subject wins, because
             * the username claim is reassignable: a directory that reissues
             * a departed person's username would otherwise hand their FOG
             * account to a new starter. A disagreement is refused rather
             * than resolved -- both readings are somebody signing into an
             * account that may not be theirs.
             */
            if ($namedId > 0 && $namedId !== $linkedId) {
                error_log(
                    sprintf(
                        'FOG OIDC: subject linked to user %d but claim "%s"'
                        . ' names user %d; refusing.',
                        $linkedId,
                        $username,
                        $namedId
                    )
                );
                throw new \Exception(
                    _('This identity is linked to a different FOG account')
                );
            }
            $user = self::getClass('User', $linkedId);
            if (!$user->isValid()) {
                throw new \Exception(
                    _('The FOG account for this identity no longer exists')
                );
            }
            self::_refreshProfile($provider, $claims, $user);
            return $user;
        }

        if (0 === $namedId) {
            if (!$provider->get('jitProvision')) {
                // The message names the account on purpose: an admin reading
                // it over somebody's shoulder needs to know which name to
                // create, and it discloses nothing the person signing in did
                // not just type into their own provider.
                throw new \Exception(
                    sprintf(
                        _('No FOG account exists for %s'),
                        $username
                    )
                );
            }
            return self::_provisionUser($provider, $claims, $username);
        }

        /*
         * First sign-in for an account that already existed. Record the
         * subject now so every later sign-in is decided by it. The account
         * is NOT stamped with uAuthSource: that column makes an account
         * unable to use a local password, and taking password login away
         * from an account an admin created is the opposite of break-glass.
         */
        self::getClass('OIDCIdentity')
            ->set('name', $username)
            ->set('providerId', $provider->get('id'))
            ->set('subject', $subject)
            ->set('userId', $namedId)
            ->save();

        self::_refreshProfile($provider, $claims, $byName);

        return $byName;
    }
    /**
     * Brings the FOG row back in line with the provider, on every sign-in.
     *
     * The display name used to be written once, by _provisionUser(), and
     * never again: renaming somebody in the directory left FOG showing the
     * name they had on the day they first signed in, forever, with nothing
     * an admin could do about it short of editing the row by hand. That is
     * not what a directory-backed account means -- the provider is supposed
     * to be the source of truth for who this is, and a value copied once is
     * a value that starts drifting immediately.
     *
     * The LDAP plugin already does this (LDAPPluginHook sets name,
     * display, api and authsource on every login, new row or not), so this
     * is the OIDC plugin catching up to the behaviour beside it rather than
     * a new idea.
     *
     * Deliberately NOT refreshed here:
     *
     * - uName. The username is what oidcIdentity is keyed against for
     *   accounts that predate their link, and renaming a FOG account out
     *   from under its history, tasks and audit rows is a migration, not a
     *   login side effect. A provider-side rename keeps working because the
     *   binding is the subject, not the name.
     * - uAuthSource. Stamping it here would take local password login away
     *   from an account an admin created, which is the opposite of
     *   break-glass. _provisionUser() stamps rows this plugin made; those
     *   are the only ones that get it.
     *
     * uAllowAPI IS re-asserted, and that is a real behaviour choice: the
     * provider's setting wins over a FOG-side edit at the next sign-in. It
     * matches LDAP, and it means "can these accounts use the API" is
     * answered in one place instead of drifting per user.
     *
     * Saves only when something actually changed, so an unchanged sign-in
     * costs no write and leaves no history row.
     *
     * @param OIDC  $provider the provider
     * @param array $claims   the verified claims
     * @param User  $user     the account being signed in
     *
     * @return void
     */
    private static function _refreshProfile($provider, array $claims, $user)
    {
        $display = trim((string)($claims['name'] ?? ''));
        if ('' === $display) {
            // No name claim at all -- Google omits it on some scopes. Leave
            // whatever is there rather than blanking a good value.
            $display = (string)$user->get('display');
        }
        $api = (string)$provider->get('allowapi');

        $changed = false;
        if ($display !== (string)$user->get('display')) {
            $user->set('display', $display);
            $changed = true;
        }
        if ($api !== (string)$user->get('api')) {
            $user->set('api', $api);
            $changed = true;
        }
        if ($changed) {
            $user->save();
        }
    }
    /**
     * Creates the FOG account for an identity that has none.
     *
     * Just-in-time provisioning. The account starts with no roles and no
     * user groups; what it ends up holding is decided a moment later by
     * _applyGrants() from the provider's group claim, which is the only
     * reason turning this on is not the same as handing out a blank
     * administrator. A provider with jitProvision on and no group mappings
     * creates accounts that can sign in and see nothing, deliberately.
     *
     * Unlike an account an admin created, this one IS stamped with
     * users.uAuthSource. That column refuses local password login for the
     * row, which is right here and wrong there: an admin-created account has
     * a password somebody chose and break-glass depends on it still working,
     * while this account's password is a random token nobody has ever seen.
     * Stamping it also means the leftover row cannot become a login if this
     * plugin is later removed.
     *
     * @param OIDC   $provider the provider
     * @param array  $claims   the verified claims
     * @param string $username the value of the configured username claim
     *
     * @throws Exception
     * @return User
     */
    private static function _provisionUser($provider, array $claims, $username)
    {
        $user = self::getClass('User')
            ->set('name', $username)
            ->set('display', trim((string)($claims['name'] ?? '')) ?: $username)
            ->set('authsource', OIDC::AUTH_SOURCE)
            ->set('api', $provider->get('allowapi'))
            // Never a password anybody could type. The identity is proven by
            // the ID token, so uPass only has to be something no typed
            // password can ever match.
            ->set('password', self::getToken(64));
        if (!$user->save()) {
            throw new \Exception(_('The FOG account could not be created'));
        }
        // After the save: the link needs the id, which does not exist until
        // then.
        self::getClass('OIDCIdentity')
            ->set('name', $username)
            ->set('providerId', $provider->get('id'))
            ->set('subject', (string)$claims['sub'])
            ->set('userId', $user->get('id'))
            ->save();
        return $user;
    }
    /**
     * Applies what the provider's group claim grants this user.
     *
     * @param OIDC  $provider the provider
     * @param array $claims   the verified claims
     * @param User  $user     the account being signed in
     *
     * @return void
     */
    private static function _applyGrants($provider, array $claims, $user)
    {
        $targets = self::_targetsForGroups(
            (int)$provider->get('id'),
            self::_claimGroups($provider, $claims)
        );
        self::_syncTargets($user, $targets['roles'], $targets['usergroups']);
        $user->save();
        self::_recordGrants($user, $targets['roles'], $targets['usergroups']);
        // The permission cache is per-request and was populated before the
        // roles changed. This request goes on to establish the session, so
        // anything it asks about permissions has to see what was just
        // written, not what the user held on the way in.
        \FOG\Auth\Authorization::resetCache();
    }
    /**
     * The group values this token carries.
     *
     * A scalar claim is treated as ONE value, not split. Splitting would
     * have to guess a delimiter, and every candidate is legal inside a group
     * name -- a Keycloak group path is free text and an Entra ID display
     * name can contain a space or a comma. Guessing wrong invents a value
     * that could match a different mapping, which is the one failure mode
     * worth ruling out on a code path that hands out roles.
     *
     * @param OIDC  $provider the provider
     * @param array $claims   the verified claims
     *
     * @return array
     */
    private static function _claimGroups($provider, array $claims)
    {
        $claimName = trim((string)$provider->get('groupClaim'));
        if ('' === $claimName || !isset($claims[$claimName])) {
            return [];
        }
        $values = $claims[$claimName];
        if (!is_array($values)) {
            $values = [$values];
        }
        $out = [];
        foreach ($values as $value) {
            // A nested object in the claim is not a group name; a provider
            // sending one has not been configured for this.
            if (!is_scalar($value)) {
                continue;
            }
            $value = trim((string)$value);
            if ('' !== $value) {
                $out[] = $value;
            }
        }
        return array_values(array_unique($out));
    }
    /**
     * The roles and user groups a set of claim values grants.
     *
     * One query per target kind, joining the group table to its association
     * table, rather than one query per value.
     *
     * Raw bound SQL rather than Route::getIds() on purpose: _buildSql()
     * turns '*' and '+' in a scalar filter value into a SQL LIKE wildcard,
     * and a group claim value is an opaque provider string that may contain
     * either. A value of '*' would otherwise match every mapping this
     * provider has.
     *
     * @param int   $providerId the provider the values came from
     * @param array $groups     the claim values
     *
     * @return array ['roles' => [...], 'usergroups' => [...]]
     */
    private static function _targetsForGroups($providerId, array $groups)
    {
        $out = [
            'roles' => [],
            'usergroups' => []
        ];
        if (empty($groups)) {
            return $out;
        }
        $names = [];
        $binds = ['provider' => (int)$providerId];
        foreach ($groups as $index => $group) {
            $key = 'g' . $index;
            $names[] = ':' . $key;
            $binds[$key] = $group;
        }
        $in = implode(',', $names);
        $queries = [
            'roles' => 'SELECT `ograRoleID` AS `target` '
                . 'FROM `oidcGroupRoleAssoc` '
                . 'INNER JOIN `OIDCGroups` ON `ogID` = `ograGroupID` '
                . 'WHERE `ogProviderID` = :provider '
                . 'AND `ogName` IN (' . $in . ')',
            'usergroups' => 'SELECT `ogugUserGroupID` AS `target` '
                . 'FROM `oidcGroupUserGroupAssoc` '
                . 'INNER JOIN `OIDCGroups` ON `ogID` = `ogugGroupID` '
                . 'WHERE `ogProviderID` = :provider '
                . 'AND `ogName` IN (' . $in . ')'
        ];
        foreach ($queries as $kind => $sql) {
            $out[$kind] = self::_targetIds($sql, $binds);
        }
        return $out;
    }
    /**
     * Runs a target-id query and returns the ids it produced.
     *
     * @param string $sql   the query to run
     * @param array  $binds the bound values
     *
     * @return array
     */
    private static function _targetIds($sql, array $binds)
    {
        try {
            $rows = self::$DB
                ->query($sql, [], $binds)
                ->fetch('', 'fetch_all')
                ->get();
        } catch (\Exception $e) {
            error_log(
                'FOG OIDC: could not read the group mappings -- '
                . $e->getMessage()
            );
            return [];
        }
        /*
         * PDODB reports a failed query as false rather than throwing
         * (throwOnQueryError is off), and (array)false is [false], not [].
         */
        if (!is_array($rows)) {
            return [];
        }
        $ids = [];
        foreach ($rows as $row) {
            $id = trim((string)($row['target'] ?? ''));
            if ('' !== $id && '0' !== $id) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }
    /**
     * What this plugin previously granted one user.
     *
     * @param int $userId the user being signed in
     *
     * @return array ['roles' => [...], 'usergroups' => [...]]
     */
    private static function _priorGrants($userId)
    {
        $out = [
            'roles' => [],
            'usergroups' => []
        ];
        if ((int)$userId < 1) {
            return $out;
        }
        try {
            $rows = self::$DB
                ->query(
                    'SELECT `ougTargetType`, `ougTargetID` '
                    . 'FROM `oidcUserGrant` WHERE `ougUserID` = :user',
                    [],
                    ['user' => (int)$userId]
                )
                ->fetch('', 'fetch_all')
                ->get();
        } catch (\Exception $e) {
            error_log(
                'FOG OIDC: could not read the recorded grants -- '
                . $e->getMessage()
            );
            return $out;
        }
        if (!is_array($rows)) {
            return $out;
        }
        foreach ($rows as $row) {
            $id = trim((string)($row['ougTargetID'] ?? ''));
            if ('' === $id || '0' === $id) {
                continue;
            }
            $kind = (
                OIDCUserGrant::TARGET_USERGROUP
                === (string)($row['ougTargetType'] ?? '')
                ? 'usergroups'
                : 'roles'
            );
            $out[$kind][] = $id;
        }
        $out['roles'] = array_values(array_unique($out['roles']));
        $out['usergroups'] = array_values(array_unique($out['usergroups']));
        return $out;
    }
    /**
     * Replaces the record of what this plugin granted one user.
     *
     * Written after the save, because a just-provisioned user has no id
     * until then. Delete-then-insert rather than a diff: the set is tiny,
     * and rewriting it wholesale means the record cannot drift out of step
     * with what was actually applied.
     *
     * @param User  $user     the user that was just saved
     * @param array $roleIds  the roles this sign-in granted
     * @param array $groupIds the user groups this sign-in granted
     *
     * @return void
     */
    private static function _recordGrants($user, array $roleIds, array $groupIds)
    {
        $userId = (int)$user->get('id');
        if ($userId < 1) {
            return;
        }
        try {
            self::$DB->query(
                'DELETE FROM `oidcUserGrant` WHERE `ougUserID` = :user',
                [],
                ['user' => $userId]
            );
            $targets = [
                OIDCUserGrant::TARGET_ROLE => $roleIds,
                OIDCUserGrant::TARGET_USERGROUP => $groupIds
            ];
            foreach ($targets as $type => $ids) {
                foreach (array_unique($ids) as $id) {
                    if ((int)$id < 1) {
                        continue;
                    }
                    self::$DB->query(
                        'INSERT IGNORE INTO `oidcUserGrant` '
                        . '(`ougUserID`, `ougTargetType`, `ougTargetID`) '
                        . 'VALUES (:user, :type, :target)',
                        [],
                        [
                            'user' => $userId,
                            'type' => $type,
                            'target' => (int)$id
                        ]
                    );
                }
            }
        } catch (\Exception $e) {
            error_log(
                'FOG OIDC: could not record the granted targets -- '
                . $e->getMessage()
            );
        }
    }
    /**
     * Makes the provider authoritative over this plugin's own grants.
     *
     * What the provider says is recomputed on each sign in, so removing
     * somebody from a group downgrades them the next time they arrive.
     * Anything an admin attached by hand is left alone -- without that
     * carve-out the sync would silently revoke deliberate grants, and an
     * admin would have no way to give a provider-authenticated user anything
     * extra.
     *
     * The managed set is the union of what this plugin previously recorded
     * for this user and what the provider grants now. Reading it from the
     * record rather than from the mapping tables is what makes removing a
     * mapping actually revoke: a target with no mappings left is still in
     * the record, so it is still this plugin's to take away. See
     * OIDCUserGrant for why the two obvious alternatives are both wrong.
     *
     * Reading get('roles') and get('usergroups') here is also what arms the
     * sync: assocSetter() no-ops on an association that was never loaded or
     * set, so both reads are load-bearing, not just informational.
     *
     * @param User  $user     the user being signed in
     * @param array $roleIds  the role ids this sign-in earns
     * @param array $groupIds the user group ids this sign-in earns
     *
     * @return void
     */
    private static function _syncTargets($user, array $roleIds, array $groupIds)
    {
        $prior = self::_priorGrants((int)$user->get('id'));
        $managedRoles = array_merge(
            $prior['roles'],
            array_map('strval', $roleIds)
        );
        $managedGroups = array_merge(
            $prior['usergroups'],
            array_map('strval', $groupIds)
        );

        $current = array_map('strval', (array)$user->get('roles'));
        $roles = array_diff($current, $managedRoles);
        $roles = array_merge($roles, array_map('strval', $roleIds));
        $user->set('roles', array_values(array_unique($roles)));

        $current = array_map('strval', (array)$user->get('usergroups'));
        $groups = array_diff($current, $managedGroups);
        $groups = array_merge($groups, array_map('strval', $groupIds));
        $user->set('usergroups', array_values(array_unique($groups)));
    }
    /**
     * Fetches and decodes a JSON document from the provider.
     *
     * @param string $url the URL to read
     *
     * @throws Exception
     * @return array
     */
    private static function _getJson($url)
    {
        $data = json_decode(self::_http($url, 'GET', null), true);
        if (!is_array($data)) {
            throw new \Exception(
                _('The identity provider sent an unreadable response')
            );
        }
        return $data;
    }
    /**
     * Posts a form-encoded body to the provider.
     *
     * @param string $url  the URL to post to
     * @param string $body the already-encoded body
     *
     * @throws Exception
     * @return string
     */
    private static function _post($url, $body)
    {
        return self::_http($url, 'POST', $body);
    }
    /**
     * One request to the provider.
     *
     * FOGURLRequests rather than a private curl call, now that it verifies
     * certificates by default and exempts only hosts this install owns -- an
     * identity provider is never one of those, so this traffic is verified
     * without the plugin having to ask. It also keeps the proxy settings, the
     * timeouts and the redirect handling in one place.
     *
     * A fresh instance, not the shared one: process() writes the timeout
     * onto the object it is called on, and the timeout wanted here is much
     * shorter than the one the rest of FOG wants.
     *
     * @param string      $url    the URL
     * @param string      $method GET or POST
     * @param string|null $body   the encoded body for a POST
     *
     * @throws Exception
     * @return string
     */
    private static function _http($url, $method, $body)
    {
        $requests = self::getClass('FOGURLRequests');
        $status = 0;
        $response = $requests->process(
            $url,
            $method,
            $body,
            false,
            false,
            function ($output, $info) use (&$status) {
                $status = (int)$info;
            },
            false,
            self::HTTP_TIMEOUT,
            ['Accept: application/json']
        );
        $response = (string)array_shift($response);
        if ($status < 200 || $status > 299) {
            error_log(
                sprintf(
                    'FOG OIDC: %s %s answered %d',
                    $method,
                    $url,
                    $status
                )
            );
            throw new \Exception(
                _('The identity provider could not be reached')
            );
        }
        if (strlen($response) > self::MAX_RESPONSE) {
            throw new \Exception(
                _('The identity provider sent an unreasonably large response')
            );
        }
        return $response;
    }
    /**
     * A URL-safe random value for state, nonce and the PKCE verifier.
     *
     * 32 bytes from the CSPRNG. All three are unguessability, not secrecy:
     * a state somebody can predict is a CSRF hole, and a verifier somebody
     * can predict is PKCE not being there.
     *
     * @return string
     */
    private static function _randomString()
    {
        return self::_b64url(random_bytes(32));
    }
    /**
     * base64url, per RFC 7636.
     *
     * @param string $raw the bytes
     *
     * @return string
     */
    private static function _b64url($raw)
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
    /**
     * Sends the browser somewhere and stops.
     *
     * @param string $url where to go
     *
     * @return void
     */
    private static function _redirect($url)
    {
        // The buffer commons/base.inc.php opened holds nothing this response
        // wants; a redirect with a body attached is only a chance for
        // something to render instead of following it.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Location: ' . $url, true, 302);
        exit;
    }
    /**
     * Abandons the sign-in and says why on the login page.
     *
     * The message goes through the flash queue rather than being printed
     * here, so the person lands back on the login form with the explanation
     * attached -- an error page at /ext/oidc/callback is a dead end with no
     * way back.
     *
     * @param string $message what went wrong
     *
     * @return void
     */
    private static function _fail($message)
    {
        self::_session();
        self::setMessage($message, _('Sign-in failed'), 'error');
        /*
         * login.php, not index.php. On an install with automatic redirect
         * on (#17), index.php sends the visitor straight back to the
         * provider that just refused them -- which is an infinite redirect
         * for a provider that is down, and an unreadable flash message even
         * when it is not, because nothing renders between the two hops.
         * login.php always renders FOG's own form (fogproject#1175), so the
         * explanation is attached to a page that stays put.
         */
        self::_redirect(OIDC::webrootBase() . 'management/login.php');
    }
}
