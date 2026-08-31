<?php
/**
 * SubnetGroup plugin
 *
 * PHP version 5
 *
 * @category SubnetGroupManager
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @author   sctt <none@none.org>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

namespace FOG\Plugins\SubnetGroup\Managers;

/**
 * Site plugin
 *
 * @category SubnetGroupManager
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @author   sctt <none@none.org>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class SubnetGroupManager extends \FOG\Base\FOGManagerController
{
    /**
     * The table name.
     *
     * @var string
     */
    public $tablename = 'subnetgroup';
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
                'sgID',
                'sgName',
                'sgDesc',
                'sgGroupID',
                'sgSubnets'
            ],
            [
                'INTEGER',
                'VARCHAR(255)',
                'LONGTEXT',
                'INTEGER',
                'TEXT'
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
                false,
                false,
                false,
                false
            ],
            [
                'sgID'
            ],
            'InnoDB',
            'utf8',
            'sgID',
            'sgID'
        );
    }
    /**
     * The plugin's ordered, append-only schema migration list. Append new
     * steps (e.g. "ALTER TABLE `subnetgroup` ADD COLUMN ...") to the END.
     *
     * @return array
     */
    public function schema()
    {
        return [
            // 0
            $this->createSql(),
            // 1 - the plugin's foreign key.
            //
            // fogproject ADR 0031 decision 8: sweep, then add. ADD CONSTRAINT
            // validates the rows already in the table and answers 1452 if any
            // of them point at a parent that is gone -- and applyConstraints()
            // REPORTS a refusal rather than returning it, so an install that
            // skipped the sweep would succeed while silently not creating the
            // constraint. The sweep is the precondition for the statement,
            // not a policy choice.
            //
            // Both calls are filtered to this plugin's own group, so neither
            // can reach another plugin's tables or core's. The relationship
            // is declared in fogproject's commons/schema-constraints.php:
            // subnetgroup.sgGroupID CASCADE to `groups`.
            //
            // CASCADE PINS WHAT ALREADY HAPPENS, so nothing observable
            // changes. RemoveSubnetGroupGroup already calls
            // Route::deletemass('subnetgroup', ['groupID' => ...]) when a
            // group is destroyed; the constraint states that in the schema
            // instead of relying on a hook being registered. The hook stays
            // -- it is what fires the plugin's own events -- and the two
            // agree rather than compete.
            //
            // No column change was needed: sgGroupID is already int(11) NOT
            // NULL against an int(11) parent, and it carries no sentinel
            // because `groupID` IS in the model's $databaseFieldsRequired,
            // so save() refuses an empty value rather than writing 0.
            //
            // Appended rather than folded into step 0 because installdb()
            // SKIPS the pSchema steps an install has already passed instead
            // of replaying them.
            //
            // Idempotent, and re-run by the unfiltered reconcile after every
            // core schema update: planConstraints() skips a constraint whose
            // declaration already matches the map.
            function () {
                $res = \FOG\Db\SchemaReconciler::sweepOrphans('subnetgroup');
                if (is_string($res)) {
                    return $res;
                }
                return \FOG\Db\SchemaReconciler::applyConstraints(
                    'subnetgroup'
                );
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
}
