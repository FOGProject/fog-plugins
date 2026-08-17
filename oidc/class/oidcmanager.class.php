<?php
/**
 * OpenID Connect providers (collection manager + schema).
 *
 * PHP version 7.4+
 *
 * @category OIDCManager
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
/**
 * OpenID Connect providers (collection manager + schema).
 *
 * @category OIDCManager
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class OIDCManager extends FOGManagerController
{
    /**
     * The table name.
     *
     * @var string
     */
    public $tablename = 'OIDCProviders';
    /**
     * The CREATE TABLE (IF NOT EXISTS) statement for this table.
     *
     * @return string
     */
    public function createSql()
    {
        return Schema::createTable(
            $this->tablename,
            true,
            [
                'opID',
                'opName',
                'opDesc',
                'opCreatedBy',
                'opCreatedTime',
                'opIssuer',
                'opClientID',
                'opClientSecret',
                'opScopes',
                'opUserClaim',
                'opGroupClaim',
                'opEnabled',
                'opJITProvision',
                'opAllowAPI',
                'opIcon'
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
                'VARCHAR(255)',
                "ENUM('0', '1')",
                "ENUM('0', '1')",
                "ENUM('0', '1')",
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
                // Enough to identify a person and nothing more. Anything
                // beyond this is asked for per provider, deliberately: an
                // admin adding a scope has decided to, rather than
                // inheriting it from a default nobody read.
                "'openid profile email'",
                // Entra ID and Keycloak both populate preferred_username,
                // and it is the claim whose value looks like the username an
                // admin already typed into FOG. Providers that leave it
                // empty (bare Google, some Okta configurations) are why this
                // is a column and not a constant.
                "'preferred_username'",
                "'groups'",
                // A provider is created switched OFF. Adding a row is not
                // the same act as putting a new way into the server, and an
                // admin filling in a client secret over several minutes
                // should not have a half-configured login live in between.
                "'0'",
                // Just-in-time provisioning ships off and must stay off
                // unless an admin turns it on. With it off, a successful
                // sign-in for somebody who has no FOG account is refused --
                // which is the whole default-deny position: holding an
                // account at the identity provider is not the same thing as
                // being allowed into FOG.
                "'0'",
                "'0'",
                "'fa fa-id-badge'"
            ],
            [
                // No UNIQUE index on opName. FOGController::save() issues an
                // INSERT ... ON DUPLICATE KEY UPDATE, so a unique column
                // turns "create a second provider with a name already in
                // use" into a silent overwrite of the first one rather than
                // an error. Uniqueness is enforced by the page and by the
                // model instead, where it can report itself.
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
                false,
                false,
                false,
                false,
                false
            ],
            'InnoDB',
            'utf8',
            'opID',
            'opID'
        );
    }
    /**
     * The ordered, APPEND-ONLY schema migration list.
     *
     * Append new steps to the END and never edit or reorder an existing one.
     * Plugin::installdb() passes the stored pSchema as the applied count and
     * applyUpdates() SKIPS that many steps rather than replaying from zero,
     * so rewriting a step is invisible to every install that has passed it.
     * The LDAP plugin's steps 10-16 exist to repair exactly that mistake.
     *
     * @return array
     */
    public function schema()
    {
        return [
            // 0 - create the providers table.
            $this->createSql(),
            // 1 - the provider-subject to FOG-user links.
            function () {
                return self::getClass('OIDCIdentityManager')->install();
            },
            // 2 - the group claim values an admin maps.
            function () {
                return self::getClass('OIDCGroupManager')->install();
            },
            // 3 - what a group grants: roles.
            function () {
                return self::getClass('OIDCGroupRoleAssociationManager')
                    ->install();
            },
            // 4 - what a group grants: user groups.
            function () {
                return self::getClass('OIDCGroupUserGroupAssociationManager')
                    ->install();
            },
            // 5 - the record of what this plugin granted each user, which is
            // what lets a later sign in take a grant back without touching
            // anything an admin assigned by hand.
            function () {
                return self::getClass('OIDCUserGrantManager')->install();
            },
        ];
    }
    /**
     * Installs/upgrades the database non-destructively.
     *
     * @return bool
     */
    public function install()
    {
        $res = Schema::applyUpdates($this->schema(), 0);
        return $res['error'] === null;
    }
    /**
     * Uninstalls the plugin.
     *
     * Drops the providers table (the rows carry client secrets, so there is
     * no reason to leave them behind) and everything keyed to it: the
     * identity links, the group mappings and the record of what was granted.
     * None of them is information once the providers are gone.
     *
     * What it deliberately does NOT do, and what the LDAP plugin's
     * uninstall() does do, is delete user accounts. An OIDC-authenticated
     * account is an ordinary FOG user an admin created and gave roles to;
     * removing the way somebody signs in is not a reason to delete them.
     * They fall back to local password login, which is exactly the
     * break-glass position.
     *
     * @return bool
     */
    public function uninstall()
    {
        self::getClass('OIDCIdentityManager')->uninstall();
        self::getClass('OIDCGroupRoleAssociationManager')->uninstall();
        self::getClass('OIDCGroupUserGroupAssociationManager')->uninstall();
        self::getClass('OIDCGroupManager')->uninstall();
        self::getClass('OIDCUserGrantManager')->uninstall();
        return parent::uninstall();
    }
}
