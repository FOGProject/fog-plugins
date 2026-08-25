<?php
/**
 * Location manager mass management class
 *
 * PHP version 5
 *
 * @category LocationManager
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
/**
 * Location manager mass management class
 *
 * @category LocationManager
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class LocationManager extends FOGManagerController
{
    /**
     * The base table name.
     *
     * @var string
     */
    public $tablename = 'location';
    /**
     * Returns the CREATE TABLE (IF NOT EXISTS) statement for this table.
     *
     * Non-destructive: it only creates the table when absent and is safe to
     * re-run. Used as the first step in schema().
     *
     * @return string
     */
    public function createSql()
    {
        return $this->createTableSql(
            $this->tablename,
            true,
            [
                'lID',
                'lName',
                'lDesc',
                'lStorageGroupID',
                'lStorageNodeID',
                'lCreatedBy',
                'lCreatedTime',
                'lTftpEnabled',
                'lStorageNodeProto'
            ],
            [
                'INTEGER',
                'VARCHAR(255)',
                'LONGTEXT',
                'INTEGER',
                'INTEGER',
                'VARCHAR(40)',
                'TIMESTAMP',
                'TINYINT(1)',
                "ENUM('http', 'https')"
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
                false
            ],
            [
                false,
                false,
                false,
                false,
                false,
                false,
                'CURRENT_TIMESTAMP',
                false,
                false
            ],
            // Unique on identity only. There used to be a third entry here,
            // UNIQUE (lStorageGroupID, lStorageNodeID), and it was wrong:
            // nothing stops two locations sharing a storage group, and a
            // location that names no specific node stores lStorageNodeID = 0,
            // so EVERY such location in the same group collided on that pair.
            // Because FOGController::save() writes INSERT ... ON DUPLICATE KEY
            // UPDATE, the collision did not raise an error -- it silently
            // renamed and repointed the existing location (moving its hosts
            // with it) while reporting "Location added!". Dropped in step 2 of
            // schema() below. lName stays unique; that is the constraint the
            // create page actually checks and reports on.
            [
                'lID',
                'lName'
            ],
            'InnoDB',
            'utf8',
            'lID',
            'lID'
        );
    }
    /**
     * The plugin's ordered, append-only schema migration list.
     *
     * One flat list covering every table this plugin owns. New schema
     * changes are appended to the END (e.g. "ALTER TABLE `location` ADD
     * COLUMN ...") and are applied incrementally and non-destructively by
     * Schema::applyUpdates(), tracked via the plugins.pSchema counter.
     *
     * @return array
     */
    public function schema()
    {
        return [
            // 0
            $this->createSql(),
            // 1
            self::getClass('LocationAssociationManager')->createSql(),
            // 2 - retire the bogus UNIQUE (lStorageGroupID, lStorageNodeID).
            // See createSql() for what it was doing: silently overwriting an
            // existing location instead of creating a second one in the same
            // storage group. Existing installs carry the index and only lose
            // it here. Schema::createTable() names its indexes by position,
            // so this one is `index2`; applyUpdates() tolerates error 1091
            // ("Can't DROP; does not exist"), which is what a fresh install
            // -- built from the corrected createSql() above -- will hit.
            sprintf(
                'ALTER TABLE `%s` DROP INDEX `index2`',
                $this->tablename
            ),
            // 3 - lTftpEnabled becomes tinyint(1) (fogproject ADR 0028). It
            // was enum('0','1'), and an integer written to an ENUM is a
            // member INDEX rather than a value: 1 selects the member '0' --
            // FALSE -- and 0 is the error value STRICT_TRANS_TABLES refuses.
            // tinyint has no such trap.
            //
            // Appended rather than folded into step 0, for the same reason
            // as step 2: installdb() SKIPS the pSchema steps an install has
            // already passed instead of replaying them, so an edit to an
            // earlier step is invisible to everyone already past it.
            // createSql() now declares TINYINT(1) for a fresh install; this
            // is what an existing one gets.
            //
            // 🔴 Schema::enumToTinyint() and not a hand-written ALTER: a
            // direct `ALTER TABLE t MODIFY c TINYINT(1)` converts an ENUM BY
            // INDEX, turning every '0' into 1 and every '1' into 2 -- both
            // truthy, silently, on every upgrading server. The helper goes
            // through VARCHAR(1) so the conversion is by label.
            function () {
                return Schema::enumToTinyint(
                    [
                        $this->tablename => ['lTftpEnabled'],
                    ]
                );
            },
        ];
    }
    /**
     * Install our database non-destructively (create-if-absent + apply any
     * pending additive steps). Does not drop existing data.
     *
     * @return bool
     */
    public function install()
    {
        $res = Schema::applyUpdates($this->schema(), 0);
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
        Route::deletemass(
            'setting',
            ['name' => 'FOG_SNAPIN_LOCATION_SEND_ENABLED']
        );
        self::getClass('LocationAssociationManager')->uninstall();
        return parent::uninstall();
    }
    /**
     * Build protocol select box
     *
     * @return string
     */
    public static function buildProtocolSelectBox($preselection)
    {
        $protocols = ['http' => 'HTTP', 'https' => 'HTTPS'];
        ob_start();
        foreach ($protocols as $short => $long) {
            printf(
                '<option value="%s"%s>%s</option>',
                Initiator::e($short),
                ($preselection === $short ? ' selected' : ''),
                Initiator::e($long)
            );
        }
        return '<select class="form-control" name="storagenodeprotocol" '
            . 'id="storagenodeprotocol">'
            . '<option value="">- '
            . self::$foglang['PleaseSelect']
            .' -</option>'
            . ob_get_clean()
            . '</select>';
    }
}
