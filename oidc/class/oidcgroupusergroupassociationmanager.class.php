<?php
/**
 * Provider group to user group associations (collection manager + schema).
 *
 * PHP version 7.4+
 *
 * @category OIDCGroupUserGroupAssociationManager
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
/**
 * Provider group to user group associations (collection manager + schema).
 *
 * @category OIDCGroupUserGroupAssociationManager
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class OIDCGroupUserGroupAssociationManager extends FOGManagerController
{
    /**
     * The table name.
     *
     * @var string
     */
    public $tablename = 'oidcGroupUserGroupAssoc';
    /**
     * The CREATE TABLE (IF NOT EXISTS) statement for this table.
     *
     * The composite unique index is wanted here, and this is the case where
     * it is right: an association is idempotent by nature, so ticking an
     * already-ticked box must not create a second row. That is the opposite
     * of the provider and identity tables, where a second row for the same
     * key would be a NEW fact and INSERT ... ON DUPLICATE KEY UPDATE would
     * silently overwrite the first.
     *
     * @return string
     */
    public function createSql()
    {
        return Schema::createTable(
            $this->tablename,
            true,
            [
                'ogugID',
                'ogugName',
                'ogugGroupID',
                'ogugUserGroupID'
            ],
            [
                'INTEGER',
                'VARCHAR(60)',
                'INTEGER',
                'INTEGER'
            ],
            [
                false,
                false,
                false,
                false
            ],
            [
                false,
                "''",
                false,
                false
            ],
            [
                [
                    'ogugGroupID',
                    'ogugUserGroupID'
                ]
            ],
            'InnoDB',
            'utf8',
            'ogugID',
            'ogugID'
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
