<?php
/**
 * Manager class for Capone
 *
 * PHP version 5
 *
 * @category CaponeManager
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
/**
 * Manager class for Capone
 *
 * @category CaponeManager
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class CaponeManager extends \FOG\Base\FOGManagerController
{
    /**
     * The base table name.
     *
     * @var string
     */
    public $tablename = 'capone';
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
                'cID',
                'cImageID',
                'cOSID',
                'cKey'
            ],
            [
                'INTEGER',
                // MEDIUMINT(9) matches os.osID. InnoDB requires an EXACT type
                // match on both sides of a foreign key and answers errno 150
                // otherwise -- int(11) against mediumint(9) is refused, which
                // is what this column was until fogproject ADR 0031.
                'INTEGER',
                'MEDIUMINT(9)',
                'VARCHAR(255)'
            ],
            [
                false,
                // Nullable, because a foreign key spells "no reference" NULL
                // and nothing else. These carried 0, which is a value: a
                // constraint over them would demand an image with imageID 0
                // and an os with osID 0 and refuse every row without one.
                // Step 2 converts the rows an existing install already has.
                true,
                true,
                false
            ],
            [
                false,
                false,
                false,
                false
            ],
            [
                'cID',
                'cKey'
            ],
            'InnoDB',
            'utf8',
            'cID',
            'cID'
        );
    }
    /**
     * Seeds this plugin's global settings, but only the ones that are
     * missing, so an admin's existing values are never overwritten on a
     * re-run/upgrade. Used as a step in schema().
     *
     * @return bool
     */
    public function seedSettings()
    {
        $category = 'Plugin: Capone';
        $fields = [
            'name',
            'description',
            'value',
            'category'
        ];
        $settings = [
            [
                'FOG_PLUGIN_CAPONE_DMI',
                'This setting is used for the capone '
                . 'module to set the DMI field used.',
                '',
                $category
            ],
            [
                'FOG_PLUGIN_CAPONE_REGEX',
                'This setting is used for the capone '
                . 'module to set the reg ex used.',
                '',
                $category
            ],
            [
                'FOG_PLUGIN_CAPONE_SHUTDOWN',
                'This setting is used for the capone '
                . 'module to set the shutdown after imaging.',
                '',
                $category
            ]
        ];
        $SettingManager = self::getClass('SettingManager');
        $toInsert = [];
        foreach ($settings as $setting) {
            if (!$SettingManager->exists($setting[0], '', 'name')) {
                $toInsert[] = $setting;
            }
        }
        if (count($toInsert)) {
            $SettingManager->insertBatch($fields, $toInsert);
        }
        return true;
    }
    /**
     * The plugin's ordered, append-only schema migration list. Append new
     * steps (e.g. "ALTER TABLE `capone` ADD COLUMN ...") to the END.
     *
     * @return array
     */
    public function schema()
    {
        return [
            // 0
            $this->createSql(),
            // 1
            function () {
                return $this->seedSettings();
            },
            // 2 - cImageID and cOSID stop spelling "no reference" as 0.
            //
            // fogproject ADR 0031. A foreign key accepts NULL for "no
            // reference" and nothing else, so a constraint over these columns
            // would demand an image with imageID 0 and an OS with osID 0 and
            // refuse every row that had neither. cOSID also has to become
            // MEDIUMINT(9): os.osID is mediumint(9) and InnoDB refuses a
            // foreign key whose sides are not the same type, at errno 150.
            //
            // Capone declares no $databaseFieldsRequired, so save()'s
            // optional-`*id` branch is what wrote the 0 -- the same shape
            // that made tasks.taskStateID a runtime 1452 and was fixed in
            // core's schema step 389. Once the column is nullable the branch
            // writes NULL on its own: FOGBase::columnType() falls back to
            // _loadPluginColumnTypes(), which reads the server's own catalog
            // for tables the core manifest does not describe.
            //
            // Appended rather than folded into step 0 because installdb()
            // SKIPS the pSchema steps an install has already passed instead
            // of replaying them. createSql() above now builds both columns
            // this way for a fresh install; this is what an existing one
            // gets.
            function () {
                $sql = sprintf(
                    'ALTER TABLE `%s` MODIFY COLUMN `cImageID` INTEGER NULL'
                    . ' DEFAULT NULL, MODIFY COLUMN `cOSID` MEDIUMINT(9) NULL'
                    . ' DEFAULT NULL',
                    $this->tablename
                );
                if (false !== self::$DB->query($sql)->error) {
                    return self::$DB->error;
                }
                $converted = 0;
                foreach (['cImageID', 'cOSID'] as $column) {
                    $sql = sprintf(
                        'UPDATE `%s` SET `%s` = NULL WHERE `%s` = 0',
                        $this->tablename,
                        $column,
                        $column
                    );
                    if (false !== self::$DB->query($sql)->error) {
                        return self::$DB->error;
                    }
                    $converted += (int)self::$DB->affectedRows();
                }
                // Logged rather than silent: this rewrites rows, and the
                // count is the only evidence it did.
                error_log(
                    sprintf(
                        '%s: %d %s',
                        _('Capone schema'),
                        $converted,
                        _('row(s) converted from 0 to NULL')
                    )
                );
                return true;
            },
            // 3 - the plugin's foreign keys.
            //
            // fogproject ADR 0031 decision 8: sweep, then add. ADD CONSTRAINT
            // validates the rows already in the table and answers 1452 if any
            // of them point at a parent that is gone -- and applyConstraints()
            // REPORTS a refusal rather than returning it, so an install that
            // skipped the sweep would succeed while silently not creating the
            // constraint. Both columns are nullable by step 2, so the sweep
            // nulls the dangling references rather than deleting the rows.
            //
            // Both calls are filtered to this plugin's own group, so neither
            // can reach another plugin's tables or core's. The relationships
            // are declared in fogproject's commons/schema-constraints.php:
            // capone.cImageID RESTRICT to `images`, capone.cOSID RESTRICT to
            // `os`.
            //
            // RESTRICT, not CASCADE, for both. Nothing in FOG deletes a
            // capone row today when its image or OS goes -- there is no hook
            // and deletemass() has no case -- so the reference simply
            // dangles, and the map's rule is RESTRICT exactly where PHP does
            // nothing and the reference dangles. CASCADE would silently
            // delete an administrator's deployment rule as a side effect of
            // deleting an image, which is the argument core used to reject
            // CASCADE for storage nodes.
            //
            // Idempotent, and re-run by the unfiltered reconcile after every
            // core schema update: planConstraints() skips a constraint whose
            // declaration already matches the map.
            function () {
                $res = \FOG\Db\SchemaReconciler::sweepOrphans('capone');
                if (is_string($res)) {
                    return $res;
                }
                return \FOG\Db\SchemaReconciler::applyConstraints('capone');
            },
        ];
    }
    /**
     * Installs the capone database non-destructively (create-if-absent +
     * seed any missing settings). Does not drop existing data or values.
     *
     * @return bool
     */
    public function install()
    {
        $res = \FOG\Items\Schema::applyUpdates($this->schema(), 0);
        return $res['error'] === null;
    }
    /**
     * Removes the database items when plugin is removed.
     *
     * @return bool
     */
    public function uninstall()
    {
        \FOG\Router\Route::deletemass(
            'setting',
            ['name' => 'FOG_PLUGIN_CAPONE_%']
        );
        \FOG\Router\Route::deletemass(
            'pxemenuoptions',
            ['name' => 'fog.capone']
        );
        return parent::uninstall();
    }
}
