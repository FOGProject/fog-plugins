<?php
/**
 * OU association manager class.
 *
 * PHP version 5
 *
 * @category DirectoryGroupsAssociationManager
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

namespace FOG\Plugins\DirectoryGroups\Managers;

/**
 * OU association manager class.
 *
 * @category DirectoryGroupsAssociationManager
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class DirectoryGroupsAssociationManager extends \FOG\Base\FOGManagerController
{
    /**
     * The base table name.
     *
     * @var string
     */
    public $tablename = 'directoryOUAssoc';
    /**
     * Returns the CREATE TABLE (IF NOT EXISTS) statement for this table.
     *
     * Non-destructive and safe to re-run. Used as a step in DirectoryGroupsManager::schema().
     *
     * @return string
     */
    public function createSql()
    {
        return $this->createTableSql(
            $this->tablename,
            true,
            [
                'doaID',
                'doaOUID',
                'doaHostID'
            ],
            [
                'INTEGER',
                'INTEGER',
                'INTEGER'
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
                'doaID',
                'doaHostID'
            ],
            'InnoDB',
            'utf8',
            'doaID',
            'doaID'
        );
    }
    /**
     * Install our table non-destructively.
     *
     * @return bool
     */
    public function install()
    {
        return self::$DB->query($this->createSql());
    }
}
