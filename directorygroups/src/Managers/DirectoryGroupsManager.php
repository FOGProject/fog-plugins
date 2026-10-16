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
            // 0 - the domains first: directoryOUs.doDomainID points at them.
            (new \FOG\Plugins\DirectoryGroups\Managers\DirectoryDomainManager())->createSql(),
            // 1
            $this->createSql(),
            // 2
            (new \FOG\Plugins\DirectoryGroups\Managers\DirectoryGroupsAssociationManager())->createSql(),
            // 3 - carry over what the install already had. Before the keys,
            // so the sweep below also clears any orphan the ou plugin left.
            function () {
                return self::migrate();
            },
            // 4 - the plugin's foreign keys.
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
            // `directoryDomains`. One of them points at a core table, and
            // that map is meant to answer "what points at hosts?" from one
            // file.
            //
            // Once the plugin is released, a later change is APPENDED as a new
            // step rather than folded into one of these: installdb() SKIPS the
            // pSchema steps an install has already passed instead of replaying
            // them, so an edit to an earlier step is invisible to everyone
            // already past it.
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
            // 5 - each directory group is backed by a core group (GH-1879
            // follow-up). NULL until step 6 creates it. A re-run answers
            // 1060, which applyUpdates() tolerates; MySQL has no IF NOT EXISTS.
            'ALTER TABLE `directoryOUs` ADD COLUMN `doGroupID` INTEGER NULL DEFAULT NULL',
            // 6 - create the core groups, move the hosts into them, and
            // retire directoryOUAssoc: core groupMembers holds them now.
            function () {
                return self::moveToCoreGroups();
            },
            // 7 - declare directoryOUs.doGroupID, CASCADE to `groups`. Same
            // sweep-then-add as step 4.
            function () {
                $res = \FOG\Db\SchemaReconciler::sweepOrphans('directorygroups');
                if (is_string($res)) {
                    return $res;
                }
                return \FOG\Db\SchemaReconciler::applyConstraints('directorygroups');
            },
            // 8, 9 - the account hosts in a domain join with (GH-1887). The
            // password is stored as typed, like the bind password.
            "ALTER TABLE `directoryDomains` ADD COLUMN `ddJoinUser` VARCHAR(255) NOT NULL DEFAULT ''",
            "ALTER TABLE `directoryDomains` ADD COLUMN `ddJoinPwd` VARCHAR(255) NOT NULL DEFAULT ''",
        ];
    }
    /**
     * Backs every directory group with a core group, and moves its hosts.
     *
     * For each directory group with no core group yet:
     * - create a core group (DirectoryGroups::groupName() picks the name);
     * - copy its directoryOUAssoc rows into groupMembers;
     * - write its OU, and its domain when it has one, onto each host. That
     *   is what joining does from now on, and it replaces the check-in
     *   override the plugin used to apply to the legacy client.
     *
     * Then directoryOUAssoc is dropped. Every write before the drop is
     * idempotent, so a run that fails part way is safe to repeat.
     *
     * @return true|string true, or the database error
     */
    public static function moveToCoreGroups()
    {
        $rows = self::$DB->query(
            'SELECT `d`.`doID` AS `id`, `d`.`doName` AS `name`, `d`.`doDN` AS `dn`,'
            . ' `d`.`doCreatedBy` AS `by`, `dd`.`ddName` AS `domain`'
            . ' FROM `directoryOUs` `d`'
            . ' LEFT JOIN `directoryDomains` `dd` ON `dd`.`ddID` = `d`.`doDomainID`'
            . ' WHERE `d`.`doGroupID` IS NULL'
        )->fetch(\PDO::FETCH_ASSOC, 'fetch_all')->get();
        foreach ((array)$rows as $row) {
            $Group = (new \FOG\Items\Group())
                ->set(
                    'name',
                    \FOG\Plugins\DirectoryGroups\Items\DirectoryGroups::groupName(
                        $row['name'],
                        $row['id']
                    )
                )
                ->set('description', _('Hosts in directory group') . ' ' . $row['name'])
                ->set('createdBy', $row['by'] ?: 'fog');
            if (!$Group->save()) {
                return sprintf(
                    '%s: %s',
                    _('Could not create the core group for directory group'),
                    $row['name']
                );
            }
            $statements = [
                [
                    'UPDATE `directoryOUs` SET `doGroupID` = :gid WHERE `doID` = :id',
                    [':gid' => $Group->get('id'), ':id' => $row['id']]
                ],
            ];
            if (self::_tableExists('directoryOUAssoc')) {
                $statements[] = [
                    'INSERT IGNORE INTO `groupMembers` (`gmHostID`, `gmGroupID`)'
                    . ' SELECT `a`.`doaHostID`, :gid FROM `directoryOUAssoc` `a`'
                    . ' JOIN `hosts` `h` ON `h`.`hostID` = `a`.`doaHostID`'
                    . ' WHERE `a`.`doaOUID` = :id',
                    [':gid' => $Group->get('id'), ':id' => $row['id']]
                ];
                $statements[] = [
                    'UPDATE `hosts` `h` JOIN `directoryOUAssoc` `a`'
                    . ' ON `a`.`doaHostID` = `h`.`hostID`'
                    . ' SET `h`.`hostADOU` = :dn,'
                    . ' `h`.`hostADDomain` = IF(:domain = \'\', `h`.`hostADDomain`, :domain2)'
                    . ' WHERE `a`.`doaOUID` = :id',
                    [
                        ':dn' => (string)$row['dn'],
                        ':domain' => (string)$row['domain'],
                        ':domain2' => (string)$row['domain'],
                        ':id' => $row['id']
                    ]
                ];
            }
            foreach ($statements as list($sql, $params)) {
                if (false !== self::$DB->query($sql, [], $params)->error) {
                    return self::$DB->error;
                }
            }
        }
        $res = (new \FOG\Plugins\DirectoryGroups\Managers\DirectoryGroupsAssociationManager())
            ->uninstall();
        return $res ? true : _('Could not drop directoryOUAssoc');
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
        // Children before parents, so a declared key never refuses a drop.
        (new \FOG\Plugins\DirectoryGroups\Managers\DirectoryGroupsAssociationManager())->uninstall();
        $res = parent::uninstall();
        (new \FOG\Plugins\DirectoryGroups\Managers\DirectoryDomainManager())->uninstall();
        return $res;
    }
    /**
     * Copies the install's existing directory setup into the plugin.
     *
     * Two sources, both read-only here:
     * - the FOG_DIRECTORY_* connection becomes the first domain, so placement
     *   keeps working the moment the plugin hides those settings;
     * - the ou plugin's OUs and host links become directory groups, so
     *   nothing an admin entered there is lost. This has to run while the ou
     *   plugin is still installed: uninstalling it drops its tables.
     *
     * Idempotent. The domain is seeded only into an empty table, and the ou
     * rows keep their ids, so INSERT IGNORE skips what a previous run copied.
     * The ou plugin is left installed; retiring it is a separate step.
     *
     * @return true|string true, or the database error
     */
    public static function migrate()
    {
        $res = self::_seedDomain();
        if (true !== $res) {
            return $res;
        }
        if (!self::_tableExists('ou') || !self::_tableExists('ouAssoc')) {
            return true;
        }
        $statements = [
            'INSERT IGNORE INTO `directoryOUs`'
            . ' (`doID`, `doName`, `doDesc`, `doCreatedBy`, `doCreatedTime`, `doDN`)'
            . ' SELECT `ouID`, `ouName`, `ouDesc`, `ouCreatedBy`, `ouCreatedTime`, `ouDN`'
            . ' FROM `ou`',
            // Only links whose both ends still exist; the ou plugin had no
            // keys for most of its life and can hold orphans.
            'INSERT IGNORE INTO `directoryOUAssoc` (`doaID`, `doaOUID`, `doaHostID`)'
            . ' SELECT `oa`.`oaID`, `oa`.`oaOUID`, `oa`.`oaHostID` FROM `ouAssoc` `oa`'
            . ' JOIN `directoryOUs` `d` ON `d`.`doID` = `oa`.`oaOUID`'
            . ' JOIN `hosts` `h` ON `h`.`hostID` = `oa`.`oaHostID`'
        ];
        foreach ($statements as $sql) {
            if (false !== self::$DB->query($sql)->error) {
                return self::$DB->error;
            }
        }
        return self::_linkGroupsToDomains();
    }
    /**
     * Seeds the first domain from the FOG_DIRECTORY_* settings.
     *
     * Only into an empty table, and only when a server is configured. The
     * name is the DNS name the base DN spells (DC=fogad,DC=local is
     * fogad.local), because that is what hosts carry in ADDomain and what
     * the connection hook matches on. With no DC= parts to read, the
     * install's default AD domain name is used instead.
     *
     * @return true|string
     */
    private static function _seedDomain()
    {
        $uri = trim((string)self::getSetting('FOG_DIRECTORY_LDAP_URI'));
        if ('' === $uri) {
            return true;
        }
        $count = self::$DB->query(
            'SELECT COUNT(*) AS `c` FROM `directoryDomains`'
        )->fetch()->get('c');
        if ((int)$count > 0) {
            return true;
        }
        $baseDN = trim((string)self::getSetting('FOG_DIRECTORY_BASE_DN'));
        $name = self::dnToDomain($baseDN)
            ?: trim((string)self::getSetting('FOG_AD_DEFAULT_DOMAINNAME'));
        if ('' === $name) {
            return true;
        }
        $sql = 'INSERT INTO `directoryDomains`'
            . ' (`ddName`, `ddDesc`, `ddCreatedBy`, `ddURI`, `ddBindDN`,'
            . ' `ddBindPwd`, `ddCACert`, `ddBaseDN`, `ddOUBase`)'
            . ' VALUES (:name, :desc, :by, :uri, :bind, :pwd, :ca, :base, :oubase)';
        $params = [
            ':name' => $name,
            ':desc' => 'Copied from the FOG_DIRECTORY_* settings on install.',
            ':by' => 'fog',
            ':uri' => $uri,
            ':bind' => trim((string)self::getSetting('FOG_DIRECTORY_BIND_DN')),
            // As stored: the item reads it back through decodeStored(), which
            // accepts every shape the setting does.
            ':pwd' => (string)self::getSetting('FOG_DIRECTORY_BIND_PASSWORD'),
            ':ca' => trim((string)self::getSetting('FOG_DIRECTORY_CA_CERT')),
            ':base' => $baseDN,
            ':oubase' => ''
        ];
        if (false !== self::$DB->query($sql, [], $params)->error) {
            return self::$DB->error;
        }
        return true;
    }
    /**
     * Gives each copied group with no domain the domain its DN is under.
     *
     * @return true|string
     */
    private static function _linkGroupsToDomains()
    {
        $domains = self::$DB->query(
            'SELECT `ddID` AS `id`, `ddName` AS `name` FROM `directoryDomains`'
        )->fetch(\PDO::FETCH_ASSOC, 'fetch_all')->get();
        foreach ((array)$domains as $domain) {
            $root = 'DC=' . implode(',DC=', array_filter(explode('.', (string)$domain['name']), 'strlen'));
            // The column's collation is case-insensitive, as DNs are.
            $sql = 'UPDATE `directoryOUs` SET `doDomainID` = :id'
                . ' WHERE `doDomainID` IS NULL AND `doDN` LIKE :suffix';
            $params = [
                ':id' => (int)$domain['id'],
                ':suffix' => '%,' . addcslashes($root, '%_\\')
            ];
            if (false !== self::$DB->query($sql, [], $params)->error) {
                return self::$DB->error;
            }
        }
        return true;
    }
    /**
     * The DNS name a DN's DC= parts spell, or ''.
     *
     * @param string $dn e.g. OU=Sales,DC=fogad,DC=local
     *
     * @return string e.g. fogad.local
     */
    public static function dnToDomain($dn)
    {
        $labels = [];
        foreach (explode(',', (string)$dn) as $rdn) {
            $rdn = trim($rdn);
            if (0 === stripos($rdn, 'DC=')) {
                $labels[] = substr($rdn, 3);
            }
        }
        return strtolower(implode('.', $labels));
    }
    /**
     * Is there a table of this name in FOG's database?
     *
     * @param string $table the table
     *
     * @return bool
     */
    private static function _tableExists($table)
    {
        $count = self::$DB->query(
            'SELECT COUNT(*) AS `c` FROM `information_schema`.`TABLES`'
            . ' WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = :table',
            [],
            [':table' => $table]
        )->fetch()->get('c');
        return (int)$count > 0;
    }
}
