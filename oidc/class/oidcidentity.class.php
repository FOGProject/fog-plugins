<?php
/**
 * The link between a provider's subject identifier and a FOG user.
 *
 * PHP version 7.4+
 *
 * @category OIDCIdentity
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
/**
 * The link between a provider's subject identifier and a FOG user.
 *
 * Why this table exists rather than matching on the username every time:
 * the claim an admin configures (preferred_username, or email) is
 * REASSIGNABLE. Directories reissue a departed person's username to a new
 * starter, and matching on it alone means the new starter signs in and
 * receives the old one's FOG account, roles included. `sub` is the one
 * identifier OpenID Connect guarantees is stable and never reused within an
 * issuer.
 *
 * Matching on `sub` alone is not an option either -- no account that exists
 * today carries one, so every install would start with nobody able to sign
 * in. So the claim is what finds the account the first time, `sub` is
 * recorded then, and from the second login onwards the recorded `sub` is
 * what decides. A login whose `sub` points at a different account than the
 * claim does is refused rather than guessed at.
 *
 * @category OIDCIdentity
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class OIDCIdentity extends FOGController
{
    /**
     * The identity table.
     *
     * @var string
     */
    protected $databaseTable = 'oidcIdentity';
    /**
     * The identity table fields and common names.
     *
     * @var array
     */
    protected $databaseFields = [
        'id' => 'oiID',
        // Route::ids() orders by name, so an association-shaped table without
        // one has every lookup against it fail. The LDAP plugin needed two
        // repair migrations to learn that; this one carries the column from
        // the start. Holds the username seen when the link was made, which
        // is also what makes the row readable in a list.
        'name' => 'oiName',
        'providerId' => 'oiProviderID',
        'subject' => 'oiSubject',
        'userId' => 'oiUserID',
        'createdTime' => 'oiCreatedTime'
    ];
    /**
     * The required fields.
     *
     * @var array
     */
    protected $databaseFieldsRequired = [
        'providerId',
        'subject',
        'userId'
    ];
    /**
     * The user a provider's subject is linked to, if any.
     *
     * Raw bound SQL rather than Route::getIds(), and that is deliberate:
     * _buildSql() turns '*' and '+' in a scalar filter value into a SQL LIKE
     * wildcard. A subject identifier is an opaque string chosen by the
     * provider -- Entra ID's are base64url and routinely contain '-' and
     * '_', and nothing in the spec forbids the other two -- so a subject
     * containing one of them would match rows belonging to somebody else.
     * The LDAP plugin hit the identical trap with group names.
     *
     * Returns 0 for "no link", and refuses to answer at all if the table
     * somehow holds the same subject against two different users: there is
     * no correct guess between them, and picking one would be picking whose
     * account a stranger signs into.
     *
     * @param int    $providerId the provider the subject came from
     * @param string $subject    the sub claim
     *
     * @throws Exception
     * @return int the user id, or 0
     */
    public static function userIdFor($providerId, $subject)
    {
        $sql = 'SELECT DISTINCT `oiUserID` FROM `oidcIdentity` '
            . 'WHERE `oiProviderID` = :provider AND `oiSubject` = :subject';
        $rows = self::$DB
            ->query(
                $sql,
                [],
                ['provider' => (int)$providerId, 'subject' => (string)$subject]
            )
            ->fetch('', 'fetch_all')
            ->get();
        $ids = [];
        foreach ((array)$rows as $row) {
            $id = (int)($row['oiUserID'] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if (count($ids) > 1) {
            throw new \Exception(
                _('This identity is linked to more than one FOG account')
            );
        }

        return (int)array_shift($ids);
    }
}
