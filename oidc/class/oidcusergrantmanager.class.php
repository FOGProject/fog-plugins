<?php
/**
 * Recorded grants (collection manager + schema).
 *
 * PHP version 7.4+
 *
 * @category OIDCUserGrantManager
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
/**
 * Recorded grants (collection manager + schema).
 *
 * @category OIDCUserGrantManager
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class OIDCUserGrantManager extends FOGManagerController
{
    /**
     * The table name.
     *
     * @var string
     */
    public $tablename = 'oidcUserGrant';
    /**
     * The CREATE TABLE (IF NOT EXISTS) statement for this table.
     *
     * Unique on (user, kind, target), for the same reason the association
     * tables are: recording a grant twice is the same fact, so the write has
     * to be idempotent. The rows are written with INSERT IGNORE rather than
     * through the ORM precisely so the duplicate is dropped instead of
     * overwriting.
     *
     * @return string
     */
    public function createSql()
    {
        return $this->createTableSql(
            $this->tablename,
            true,
            [
                'ougID',
                'ougName',
                'ougUserID',
                'ougTargetType',
                'ougTargetID'
            ],
            [
                'INTEGER',
                'VARCHAR(60)',
                'INTEGER',
                "ENUM('role', 'usergroup')",
                'INTEGER'
            ],
            [
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
                "'role'",
                false
            ],
            [
                [
                    'ougUserID',
                    'ougTargetType',
                    'ougTargetID'
                ]
            ],
            'InnoDB',
            'utf8',
            'ougID',
            'ougID'
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
