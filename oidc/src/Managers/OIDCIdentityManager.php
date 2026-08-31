<?php
/**
 * Provider-subject to FOG-user links (collection manager + schema).
 *
 * PHP version 7.4+
 *
 * @category OIDCIdentityManager
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

namespace FOG\Plugins\OIDC\Managers;

/**
 * Provider-subject to FOG-user links (collection manager + schema).
 *
 * @category OIDCIdentityManager
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class OIDCIdentityManager extends \FOG\Base\FOGManagerController
{
    /**
     * The table name.
     *
     * @var string
     */
    public $tablename = 'oidcIdentity';
    /**
     * The CREATE TABLE (IF NOT EXISTS) statement for this table.
     *
     * @return string
     */
    public function createSql()
    {
        return $this->createTableSql(
            $this->tablename,
            true,
            [
                'oiID',
                'oiName',
                'oiProviderID',
                'oiSubject',
                'oiUserID',
                'oiCreatedTime'
            ],
            [
                'INTEGER',
                'VARCHAR(255)',
                'INTEGER',
                'VARCHAR(255)',
                'INTEGER',
                'TIMESTAMP'
            ],
            [
                false,
                false,
                false,
                false,
                false,
                false
            ],
            [
                false,
                "''",
                false,
                false,
                false,
                'CURRENT_TIMESTAMP'
            ],
            [
                // No UNIQUE index, and not for the usual reason. A unique
                // (provider, subject) would be the obvious constraint, but
                // FOGController::save() writes with INSERT ... ON DUPLICATE
                // KEY UPDATE, so it would turn a second link for the same
                // subject into a SILENT re-point of that subject at a
                // different FOG account -- which is somebody signing into
                // somebody else's account with nothing logged.
                //
                // OIDCIdentity::userIdFor() enforces the constraint the other
                // way instead: if it ever finds a subject against two
                // different users it refuses the login rather than choosing.
                // A visible inconsistency beats a silent re-point.
                false,
                false,
                false,
                false,
                false,
                false
            ],
            'InnoDB',
            'utf8',
            'oiID',
            'oiID'
        );
    }
    /**
     * Installs/upgrades the database non-destructively.
     *
     * @return bool
     */
    public function install()
    {
        $res = \FOG\Items\Schema::applyUpdates($this->schema(), 0);
        return $res['error'] === null;
    }
    /**
     * The ordered, APPEND-ONLY schema migration list.
     *
     * @return array
     */
    public function schema()
    {
        return [
            // 0 - create the identity table.
            $this->createSql(),
        ];
    }
}
