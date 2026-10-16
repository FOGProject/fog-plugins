<?php
/**
 * OU manager mass management class
 *
 * PHP version 5
 *
 * @category DirectoryGroupsManager
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

namespace FOG\Plugins\DirectoryGroups\Managers;

/**
 * OU manager mass management class
 *
 * @category DirectoryGroupsManager
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class DirectoryGroupsManager extends \FOG\Base\FOGManagerController
{
    /**
     * The base table name.
     *
     * @var string
     */
    public $tablename = 'directoryOUs';
    /**
     * Returns the CREATE TABLE (IF NOT EXISTS) statement for this table.
     *
     * Non-destructive and safe to re-run. Used as a step in schema().
     *
     * @return string
     */
    public function createSql()
    {
        return $this->createTableSql(
            $this->tablename,
            true,
            [
                'doID',
                'doName',
                'doDesc',
                'doCreatedBy',
                'doCreatedTime',
                'doDN',
                'doDomainID'
            ],
            [
                'INTEGER',
                'VARCHAR(255)',
                'LONGTEXT',
                'VARCHAR(40)',
                'TIMESTAMP',
                'VARCHAR(255)',
                'INTEGER'
            ],
            [
                false,
                false,
                false,
                false,
                false,
                false,
                true
            ],
            [
                false,
                false,
                false,
                false,
                'CURRENT_TIMESTAMP',
                false,
                false
            ],
            [
                'doID',
                'doName'
            ],
            'InnoDB',
            'utf8',
            'doID',
            'doID'
        );
    }
    /**
     * The plugin's ordered, append-only schema migration list (all tables).
     * Append new steps (e.g. "ALTER TABLE `directoryOUs` ADD COLUMN ...") to the END.
     *
     * @return array
     */
    public function schema()
    {
        return [
            // 0
            $this->createSql(),
            // 1
            (new \FOG\Plugins\DirectoryGroups\Managers\DirectoryGroupsAssociationManager())->createSql(),
            // 2 - the plugin's foreign keys.
            //
            // fogproject ADR 0031 decision 8: sweep, then add. ADD CONSTRAINT
            // validates the rows already in the table and answers 1452 if any
            // of them point at a parent that is gone -- and applyConstraints()
            // REPORTS a refusal rather than returning it, so an install that
            // skipped the sweep would succeed while silently not creating the
            // constraint. The sweep is the precondition for the statement, not
            // a policy choice.
            //
            // Both calls are filtered to this plugin's own group, so neither
            // can reach another plugin's tables or core's. The relationships
            // themselves are declared in fogproject's
            // commons/schema-constraints.php: directoryOUAssoc.doaOUID CASCADE
            // to `directoryOUs`, directoryOUAssoc.doaHostID CASCADE to
            // `hosts`, and directoryOUs.doDomainID CASCADE to
            // `directoryDomains`. That last table is created by a separate
            // plugin step, not here. Half of them point at core tables, and
            // that map is meant to answer "what points at hosts?" from one
            // file.
            //
            // Appended rather than folded into an earlier step because
            // installdb() SKIPS the pSchema steps an install has already
            // passed instead of replaying them, so an edit to an earlier step
            // is invisible to everyone already past it.
            //
            // No sentinel is needed: doaOUID and doaHostID are int(11) NOT NULL
            // against int(11) parents, and an association row exists only to
            // name both ends. doDomainID is NULL when an OU belongs to no
            // domain, never 0.
            //
            // Idempotent, and re-run by the unfiltered reconcile after every
            // core schema update: planConstraints() skips a constraint whose
            // declaration already matches the map.
            function () {
                $res = \FOG\Db\SchemaReconciler::sweepOrphans('directorygroups');
                if (is_string($res)) {
                    return $res;
                }
                return \FOG\Db\SchemaReconciler::applyConstraints('directorygroups');
            },
        ];
    }
    /**
     * Installs the database non-destructively (create-if-absent + apply any
     * pending additive steps). Does not drop existing data.
     *
     * @return bool
     */
    public function install()
    {
        $res = \FOG\Items\Schema::applyUpdates($this->schema(), 0);
        return $res['error'] === null;
    }
    /**
     * Uninstalls the database
     *
     * @return bool
     */
    public function uninstall()
    {
        $res = true;
        (new \FOG\Plugins\DirectoryGroups\Managers\DirectoryGroupsAssociationManager())->uninstall();
        return parent::uninstall();
    }
}
