<?php
/**
 * Windows Key manager mass management class
 *
 * PHP version 5
 *
 * @category WindowsKeyManager
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

namespace FOG\Plugins\WindowsKey\Managers;

/**
 * Windows Key manager mass management class
 *
 * @category WindowsKeyManager
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class WindowsKeyManager extends \FOG\Base\FOGManagerController
{
    /**
     * The base table name.
     *
     * @var string
     */
    public $tablename = 'windowsKeys';
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
                'wkID',
                'wkName',
                'wkDesc',
                'wkCreatedBy',
                'wkCreatedTime',
                'wkKey'
            ],
            [
                'INTEGER',
                'VARCHAR(255)',
                'LONGTEXT',
                'VARCHAR(40)',
                'TIMESTAMP',
                'VARCHAR(200)'
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
                false,
                false,
                false,
                'CURRENT_TIMESTAMP',
                false
            ],
            // wkName only. wkKey used to be unique here, and a unique index is
            // the wrong instrument for it: FOGController::save() writes
            // INSERT ... ON DUPLICATE KEY UPDATE, so a second record carrying a
            // key another already had did not error -- it silently renamed and
            // repointed that other record while reporting "Windows Key added!".
            // An index can only collide; it cannot explain. Duplicate keys are
            // still refused, but by the page (see windowskeymanagement's
            // addPost/windowsKeyGeneralPost), which can name the offending
            // field. Kept as a false rather
            // than removed so wkName stays `index1`: Schema::createTable()
            // names indexes by position, and shifting it would give fresh
            // installs a different index name from migrated ones.
            [
                false,
                'wkName'
            ],
            'InnoDB',
            'utf8',
            'wkID',
            'wkID'
        );
    }
    /**
     * The plugin's ordered, append-only schema migration list (all tables).
     * Append new steps (e.g. "ALTER TABLE `windowsKeys` ADD COLUMN ...") to
     * the END.
     *
     * @return array
     */
    public function schema()
    {
        return [
            // 0
            $this->createSql(),
            // 1
            self::getClass('WindowsKeyAssociationManager')->createSql(),
            // 2 - retire UNIQUE (wkKey); see createSql() for why it could not
            // hold. It sat at position 0, hence `index0`. applyUpdates()
            // tolerates 1091, which is what a fresh install -- built from the
            // corrected createSql() above -- will hit.
            sprintf(
                'ALTER TABLE `%s` DROP INDEX `index0`',
                $this->tablename
            ),
            // 3 - the plugin's foreign keys.
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
            // commons/schema-constraints.php: windowsKeysAssoc.wkaImageID
            // CASCADE to `images`, windowsKeysAssoc.wkaKeyID CASCADE to
            // `windowsKeys`. Half of them point at core tables, and that map
            // is meant to answer "what points at images?" from one file.
            //
            // CASCADE on wkaImageID is the behavior that already existed:
            // deleting an image was never meant to leave a key assigned to it,
            // and the association carries nothing of its own to preserve.
            //
            // Appended rather than folded into an earlier step because
            // installdb() SKIPS the pSchema steps an install has already
            // passed instead of replaying them.
            //
            // No column change was needed: both columns are already
            // int(11) NOT NULL against int(11) parents, and neither carries a
            // sentinel.
            //
            // Idempotent, and re-run by the unfiltered reconcile after every
            // core schema update.
            function () {
                $res = \FOG\Db\SchemaReconciler::sweepOrphans('windowskey');
                if (is_string($res)) {
                    return $res;
                }
                return \FOG\Db\SchemaReconciler::applyConstraints(
                    'windowskey'
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
    /**
     * Uninstalls the database
     *
     * @return bool
     */
    public function uninstall()
    {
        self::getClass('WindowsKeyAssociationManager')->uninstall();
        return parent::uninstall();
    }
}
