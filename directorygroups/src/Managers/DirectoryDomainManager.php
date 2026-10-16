<?php
/**
 * Directory domain manager.
 *
 * PHP version 7
 *
 * @category DirectoryDomainManager
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

namespace FOG\Plugins\DirectoryGroups\Managers;

/**
 * Directory domain manager.
 *
 * The table is created and dropped by DirectoryGroupsManager, which owns the
 * plugin's whole schema, so one install and one uninstall cover all three
 * tables.
 *
 * @category DirectoryDomainManager
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class DirectoryDomainManager extends \FOG\Base\FOGManagerController
{
    /**
     * The base table name.
     *
     * @var string
     */
    public $tablename = 'directoryDomains';
    /**
     * The create statement for the domain table.
     *
     * @return string
     */
    public function createSql()
    {
        return $this->createTableSql(
            $this->tablename,
            true,
            [
                'ddID',
                'ddName',
                'ddDesc',
                'ddCreatedBy',
                'ddCreatedTime',
                'ddURI',
                'ddBindDN',
                'ddBindPwd',
                'ddCACert',
                'ddBaseDN',
                'ddOUBase'
            ],
            [
                'INTEGER',
                'VARCHAR(255)',
                'LONGTEXT',
                'VARCHAR(40)',
                'TIMESTAMP',
                'VARCHAR(255)',
                'VARCHAR(255)',
                'LONGTEXT',
                'VARCHAR(255)',
                'VARCHAR(255)',
                'VARCHAR(255)'
            ],
            [
                false,
                false,
                false,
                false,
                false,
                false,
                false,
                false,
                false,
                false,
                false
            ],
            [
                false,
                false,
                false,
                false,
                'CURRENT_TIMESTAMP',
                false,
                false,
                false,
                false,
                false,
                false
            ],
            [
                'ddID',
                'ddName'
            ],
            'InnoDB',
            'utf8',
            'ddID',
            'ddID'
        );
    }
}
