<?php
/**
 * An OpenID Connect identity provider FOG can sign users in with.
 *
 * PHP version 7.4+
 *
 * @category OIDC
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
/**
 * An OpenID Connect identity provider FOG can sign users in with.
 *
 * @category OIDC
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class OIDC extends FOGController
{
    /**
     * Stamped on users.uAuthSource for accounts this plugin owns, and passed
     * to User::establishSession() as the session's provenance.
     *
     * Two different questions, one answer: uAuthSource says which provider
     * owns the ACCOUNT, the session stamp says what proved THIS request.
     * They agree for an ordinary OIDC login and are allowed to differ --
     * that is why core keeps them apart.
     *
     * @var string
     */
    const AUTH_SOURCE = 'oidc';
    /**
     * The callback path, relative to FOG's webroot.
     *
     * Under /ext/ because that is the mount point core reserves for plugin
     * routes: core mints new top-level API paths from its own class list, so
     * a path that is free today is not guaranteed free tomorrow.
     *
     * @var string
     */
    const CALLBACK_PATH = '/ext/oidc/callback';
    /**
     * The scope every provider must be asked for.
     *
     * Without it the provider runs a plain OAuth 2 authorization and returns
     * no ID token, so there is nothing to verify and nobody to identify.
     *
     * @var string
     */
    const REQUIRED_SCOPE = 'openid';
    /**
     * The providers table.
     *
     * @var string
     */
    protected $databaseTable = 'OIDCProviders';
    /**
     * The provider table fields and common names.
     *
     * @var array
     */
    protected $databaseFields = [
        'id' => 'opID',
        'name' => 'opName',
        'description' => 'opDesc',
        'createdBy' => 'opCreatedBy',
        'createdTime' => 'opCreatedTime',
        // The issuer identifier, which is also the discovery base: the
        // endpoints are read from <issuer>/.well-known/openid-configuration
        // rather than stored, so they cannot drift from what the provider
        // currently publishes.
        'issuer' => 'opIssuer',
        'clientId' => 'opClientID',
        'clientSecret' => 'opClientSecret',
        'scopes' => 'opScopes',
        // Which claim names the FOG account. Configurable because providers
        // disagree: Entra ID and Keycloak populate preferred_username, some
        // deployments only have email.
        'userClaim' => 'opUserClaim',
        // Which claim carries group membership, for the role mapping.
        'groupClaim' => 'opGroupClaim',
        'enabled' => 'opEnabled',
        // Create a FOG account for a directory user who has none. Ships off,
        // and stays off unless an admin turns it on -- see the note on the
        // column in OIDCManager::createSql().
        'jitProvision' => 'opJITProvision',
        'allowapi' => 'opAllowAPI',
        // Signing out of FOG also ends the session at the provider (#15).
        // Off by default: it is only the right answer where FOG is the only
        // application behind that provider. See the note on the column in
        // OIDCManager::createSql().
        'singleLogout' => 'opSingleLogout',
        'icon' => 'opIcon'
    ];
    /**
     * The required fields.
     *
     * @var array
     */
    protected $databaseFieldsRequired = [
        'name',
        'issuer',
        'clientId'
    ];
    /**
     * clientId ends in "id" without being a foreign key.
     *
     * FOGController::save() reads a key ending in "id" as an integer id
     * unless the model says otherwise. A client id is a string the provider
     * chooses -- "fog-web", or a GUID on Entra -- so without this every
     * create failed with "Required database field is empty: clientId" about
     * a field that was filled in. Needs fogproject#1153.
     *
     * @var array
     */
    protected $databaseFieldsNotInt = [
        'clientId'
    ];
    /**
     * Validate before storing.
     *
     * Every rule here is enforced in the model rather than on the management
     * page because the page is not the only writer: the REST API reaches
     * these same columns, and a provider row with an http:// issuer or a
     * scope list missing openid is a broken login for everybody, not just
     * for the admin who typed it.
     *
     * @throws Exception
     * @return bool
     */
    public function save()
    {
        $this->set('issuer', rtrim(trim((string)$this->get('issuer')), '/'));
        self::assertValidIssuer($this->get('issuer'));

        $this->set('clientId', trim((string)$this->get('clientId')));
        if ('' === $this->get('clientId')) {
            throw new \Exception(_('A client ID is required'));
        }

        $this->set('scopes', self::normalizeScopes($this->get('scopes')));
        $this->set(
            'userClaim',
            self::assertValidClaim(
                $this->get('userClaim'),
                _('user claim')
            )
        );
        // The group claim is optional: a provider that publishes no groups is
        // usable, it just cannot grant anything beyond the default mapping.
        if ('' !== trim((string)$this->get('groupClaim'))) {
            $this->set(
                'groupClaim',
                self::assertValidClaim(
                    $this->get('groupClaim'),
                    _('group claim')
                )
            );
        }

        return parent::save();
    }
    /**
     * Throws unless the issuer is one this plugin can safely talk to.
     *
     * Public static because the management page validates a POST before any
     * object exists, and because the model calls it for every other writer.
     *
     * https:// only, and that is not a preference. Everything OIDC relies on
     * -- the discovery document that names the token endpoint, the JWKS that
     * the ID token signature is checked against, and the token exchange that
     * carries the client secret -- is fetched over this URL. On http:// an
     * attacker on the path substitutes their own signing keys and mints a
     * token for any account they like, and every signature check still
     * passes because they chose the key.
     *
     * A query string or fragment is refused because the discovery URL is
     * built by appending a path; "https://idp/realm?x=1" would produce
     * "https://idp/realm?x=1/.well-known/openid-configuration", which is not
     * a request anyone intended.
     *
     * @param string $issuer the issuer identifier to check
     *
     * @throws Exception
     * @return void
     */
    public static function assertValidIssuer($issuer)
    {
        $issuer = trim((string)$issuer);
        if ('' === $issuer) {
            throw new \Exception(_('An issuer URL is required'));
        }
        if (strlen($issuer) > 255) {
            // The column is VARCHAR(255); a truncated issuer points nowhere
            // and its discovery fetch fails with no obvious cause.
            throw new \Exception(_('The issuer URL is too long'));
        }
        $parts = parse_url($issuer);
        if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
            throw new \Exception(_('The issuer must be a full URL'));
        }
        if ('https' !== strtolower($parts['scheme'])) {
            throw new \Exception(
                _('The issuer URL must use https')
            );
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new \Exception(
                _('The issuer URL must not carry a query string or fragment')
            );
        }
    }
    /**
     * Reduce a claim name to something safe to put in a lookup.
     *
     * Claim names are ordinary JSON object keys, so this is deliberately
     * permissive about their shape and strict about their length: what it
     * exists to refuse is an empty value (which would silently match no
     * claim and deny every login with no explanation) and the kind of value
     * that only arrives by accident, such as a whole JSON pointer path.
     *
     * @param string $claim the claim name as supplied
     * @param string $label what to call it in the error message
     *
     * @throws Exception
     * @return string the trimmed claim name
     */
    public static function assertValidClaim($claim, $label)
    {
        $claim = trim((string)$claim);
        if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9_.:/-]{0,63}$#', $claim)) {
            throw new \Exception(
                sprintf(_('Enter a valid %s name'), $label)
            );
        }
        return $claim;
    }
    /**
     * Normalise a scope list, guaranteeing 'openid' is in it.
     *
     * Added rather than rejected: a scope list without openid is not a
     * decision anyone makes on purpose, it is a typo or a paste, and the
     * failure it produces (a login that completes at the provider and comes
     * back with no ID token) does not look like a scope problem.
     *
     * @param string $scopes the scope list as supplied
     *
     * @return string
     */
    public static function normalizeScopes($scopes)
    {
        $scopes = preg_split('#\s+#', trim((string)$scopes), -1, PREG_SPLIT_NO_EMPTY);
        $scopes = array_values(array_unique((array)$scopes));
        if (!in_array(self::REQUIRED_SCOPE, $scopes, true)) {
            array_unshift($scopes, self::REQUIRED_SCOPE);
        }
        return implode(' ', $scopes);
    }
    /**
     * The redirect URI this server presents to a provider.
     *
     * Built from FOG_WEB_HOST and FOG_WEB_ROOT rather than from the request,
     * on purpose. The value has to be registered at the provider ahead of
     * time and has to match byte for byte, so it must be one stable string
     * an admin can copy -- and deriving it from the Host header would make
     * it whatever the browser last claimed the server was called.
     *
     * https because the callback carries the authorization code.
     *
     * @return string
     */
    public static function redirectUri()
    {
        return self::absoluteUrl(self::CALLBACK_PATH);
    }
    /**
     * Where a provider sends the browser after ending its own session.
     *
     * management/login.php rather than management/index.php, and the
     * difference is the whole point: index.php is the page an install with
     * forced redirect on (#17) bounces straight back to the provider. A
     * signed-out user landing there would be silently signed back in by the
     * SSO session that was just ended -- or, if it really was ended, sent
     * around the loop again. login.php always renders FOG's own form
     * (fogproject#1175).
     *
     * This URL has to be registered at the provider as a post-logout
     * redirect URI, the same way the callback does. Providers that follow
     * the spec refuse an unregistered one and show their own error page
     * instead of coming back, so the management page prints it next to the
     * setting rather than leaving an admin to work out why logout ends
     * somewhere unexpected.
     *
     * @return string
     */
    public static function postLogoutUri()
    {
        return self::absoluteUrl('management/login.php');
    }
    /**
     * An absolute https URL for a path inside this FOG install.
     *
     * Built from FOG_WEB_HOST and FOG_WEB_ROOT rather than from the request
     * for the reason spelled out on redirectUri(): these values are
     * registered at a provider ahead of time and compared byte for byte, so
     * they cannot be whatever the browser last claimed the server was
     * called.
     *
     * @param string $path a path relative to the webroot
     *
     * @return string
     */
    public static function absoluteUrl($path)
    {
        $host = trim((string)self::getSetting('FOG_WEB_HOST'), '/');
        return sprintf(
            'https://%s%s',
            $host,
            rtrim(self::webrootBase(), '/') . '/' . ltrim((string)$path, '/')
        );
    }
    /**
     * The configured webroot, with a leading and a trailing slash.
     *
     * Derived exactly the way Route's constructor derives its router base
     * (GH-529), and NOT via FOGPage::webrootPath(), which is the obvious
     * choice and is wrong here: webrootPath() answers 'fog' when the setting
     * is empty, while the router anchors its paths at '' -- so on an install
     * served from the document root every URL this plugin built would point
     * one directory too deep, and the callback registered at the provider
     * would never be reached.
     *
     * Read from the setting rather than from Route::webrootbase(), because
     * that value is only filled in once a Route has been constructed and the
     * login page is rendered without one.
     *
     * @return string
     */
    public static function webrootBase()
    {
        $root = trim((string)self::getSetting('FOG_WEB_ROOT'), '/');
        return '/' . ('' === $root ? '' : $root . '/');
    }
}
