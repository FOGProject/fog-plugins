<?php
/**
 * Identity provider groups (collection manager + schema).
 *
 * PHP version 7.4+
 *
 * @category OIDCGroupManager
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

namespace FOG\Plugins\OIDC\Managers;

/**
 * Identity provider groups (collection manager + schema).
 *
 * @category OIDCGroupManager
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class OIDCGroupManager extends \FOG\Base\FOGManagerController
{
    /**
     * The table name.
     *
     * @var string
     */
    public $tablename = 'OIDCGroups';
    /**
     * The CREATE TABLE (IF NOT EXISTS) statement for this table.
     *
     * Unique on (provider, name): the same group name at two providers is
     * two different groups, and one provider cannot publish the same name
     * twice.
     *
     * A unique index is normally the thing to avoid on a table this code
     * writes through the ORM -- FOGController::save() issues an
     * INSERT ... ON DUPLICATE KEY UPDATE, so a unique key turns "create a
     * duplicate" into a silent overwrite of the existing row, which is why
     * OIDCProviders deliberately has none. It does not apply here: the key
     * covers every non-id column of this table, so the UPDATE half can only
     * write the values it matched on. There is nothing for it to destroy.
     *
     * The management page still checks first, because the error the index
     * raises is not a sentence anybody wants to read.
     *
     * @return string
     */
    public function createSql()
    {
        return $this->createTableSql(
            $this->tablename,
            true,
            [
                'ogID',
                'ogProviderID',
                'ogName'
            ],
            [
                'INTEGER',
                'INTEGER',
                'VARCHAR(255)'
            ],
            [
                false,
                false,
                false
            ],
            [
                false,
                false,
                false
            ],
            [
                [
                    'ogProviderID',
                    'ogName'
                ]
            ],
            'InnoDB',
            'utf8',
            'ogID',
            'ogID'
        );
    }
    /**
     * Installs the database non-destructively.
     *
     * @return bool
     */
    public function install()
    {
        return self::$DB->query($this->createSql());
    }
}
