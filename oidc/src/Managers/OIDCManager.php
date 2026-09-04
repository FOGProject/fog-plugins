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

namespace FOG\Plugins\OIDC\Managers;

/**
 * OpenID Connect providers (collection manager + schema).
 *
 * @category OIDCManager
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class OIDCManager extends \FOG\Base\FOGManagerController
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
        return $this->createTableSql(
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
                'opSingleLogout',
                'opAutoRedirect',
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
                'TINYINT(1)',
                'TINYINT(1)',
                'TINYINT(1)',
                'TINYINT(1)',
                'TINYINT(1)',
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
                // Signing out of FOG also signing the user out of the
                // provider is off by default, and that is not timidity: it
                // is only right when FOG is the only thing behind that
                // provider. Where an install shares an identity provider
                // with a mail client and a ticket system, ending the SSO
                // session because somebody left FOG is a surprise that
                // reaches applications FOG has nothing to do with.
                "'0'",
                // Sending everyone straight to this provider ships off, and
                // it is the most dangerous switch in this plugin: an
                // unconditional redirect to a provider that is unreachable,
                // whose certificate expired, or whose issuer was mistyped
                // takes the login form away from every administrator at
                // once. management/login.php (fogproject#1175) is the way
                // back, and the management page names it next to the box.
                "'0'",
                "'far fa-id-badge'"
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
                return (new \FOG\Plugins\OIDC\Managers\OIDCIdentityManager())->install();
            },
            // 2 - the group claim values an admin maps.
            function () {
                return (new \FOG\Plugins\OIDC\Managers\OIDCGroupManager())->install();
            },
            // 3 - what a group grants: roles.
            function () {
                return (new \FOG\Plugins\OIDC\Managers\OIDCGroupRoleAssociationManager())
                    ->install();
            },
            // 4 - what a group grants: user groups.
            function () {
                return (new \FOG\Plugins\OIDC\Managers\OIDCGroupUserGroupAssociationManager())
                    ->install();
            },
            // 5 - the record of what this plugin granted each user, which is
            // what lets a later sign in take a grant back without touching
            // anything an admin assigned by hand.
            function () {
                return (new \FOG\Plugins\OIDC\Managers\OIDCUserGrantManager())->install();
            },
            // 6 - RP-initiated logout, per provider (#15). Appended rather
            // than folded into step 0, because installdb() SKIPS the first
            // pSchema steps instead of replaying them: an install that has
            // already passed step 0 would never see an edit to it, and would
            // carry a providers table without this column forever.
            //
            // applyUpdates() tolerates 1060 (duplicate column), so an
            // install created fresh from createSql() -- which already has
            // the column -- runs this harmlessly too.
            "ALTER TABLE `OIDCProviders` ADD COLUMN `opSingleLogout` "
            . "ENUM('0', '1') NOT NULL DEFAULT '0'",
            // 7 - send the login page straight to this provider (#17).
            // Appended for the same reason as step 6, and defaulting off for
            // a sharper one: an install that upgraded into this switched ON
            // would find its login form replaced by a redirect nobody asked
            // for, and the only URL that still shows the form is one nobody
            // has been told about yet.
            "ALTER TABLE `OIDCProviders` ADD COLUMN `opAutoRedirect` "
            . "ENUM('0', '1') NOT NULL DEFAULT '0'",
            // 8 - two-state columns become tinyint(1) (fogproject ADR
            // 0028). They were enum('0','1'), and an integer written to an
            // ENUM is a member INDEX rather than a value: 1 selects the
            // member '0' -- FALSE -- and 0 is the error value
            // STRICT_TRANS_TABLES refuses. tinyint has no such trap.
            //
            // Appended rather than folded into the createSql() step above,
            // for the same reason as every ALTER here: installdb() SKIPS the
            // pSchema steps an install has already passed instead of
            // replaying them, so an edit to an earlier step is invisible to
            // everyone already past it. createSql() now declares these
            // TINYINT(1) for a fresh install; this is what an existing one
            // gets. The historical ADD COLUMN steps above still say ENUM and
            // must stay that way -- rewriting one changes nothing for anyone
            // who ran it, and applyUpdates() tolerates 1060 so a fresh
            // install runs them harmlessly against columns that are already
            // tinyint.
            //
            // 🔴 Schema::enumToTinyint() and not a hand-written ALTER: a
            // direct `ALTER TABLE t MODIFY c TINYINT(1)` converts an ENUM BY
            // INDEX, turning every '0' into 1 and every '1' into 2 -- both
            // truthy, silently, on every upgrading server. The helper goes
            // through VARCHAR(1) so the conversion is by label, and carries
            // this table's nullability and defaults across (all five are NOT NULL DEFAULT '0').
            function () {
                return \FOG\Items\Schema::enumToTinyint(
                    [
                        'OIDCProviders' => [
                            'opAllowAPI',
                            'opAutoRedirect',
                            'opEnabled',
                            'opJITProvision',
                            'opSingleLogout',
                        ],
                    ]
                );
            },
            // 9 - the plugin's eight foreign keys.
            //
            // fogproject ADR 0031 decision 8: sweep, then add. ADD CONSTRAINT
            // validates the rows already in the table and answers 1452 if any
            // of them point at a parent that is gone -- and applyConstraints()
            // REPORTS a refusal rather than returning it, so an install that
            // skipped the sweep would succeed while silently not creating the
            // constraint. Both calls are filtered to this plugin's own group,
            // so neither can reach another plugin's tables or core's.
            //
            // All eight land here even though five of the tables belong to
            // other managers, including oidcIdentity, whose own schema() runs
            // separately at step 1 above. The calls are driven by the table
            // names in fogproject's commons/schema-constraints.php rather than
            // by whose manager is executing, so the plugin needs exactly one
            // constraint step and it belongs in the orchestrator -- by which
            // point every one of its tables exists.
            //
            //   OIDCGroups.ogProviderID                 -> OIDCProviders.opID
            //   oidcIdentity.oiProviderID               -> OIDCProviders.opID
            //   oidcIdentity.oiUserID                   -> users.uId
            //   oidcGroupRoleAssoc.ograGroupID          -> OIDCGroups.ogID
            //   oidcGroupRoleAssoc.ograRoleID           -> roles.rID
            //   oidcGroupUserGroupAssoc.ogugGroupID     -> OIDCGroups.ogID
            //   oidcGroupUserGroupAssoc.ogugUserGroupID -> userGroups.ugID
            //   oidcUserGrant.ougUserID                 -> users.uId
            //
            // All CASCADE. OIDCGroups and oidcIdentity are satellites -- a
            // group claim mapping and a subject-to-user binding both mean
            // nothing without the provider they came from -- and the rest are
            // junctions. oidcIdentity is the one worth being deliberate about:
            // it is the record that this external subject IS this FOG user, so
            // deleting either end has to take it. Leaving it would let the
            // next user created with a recycled id inherit someone else's
            // identity binding.
            //
            // ougTargetID is deliberately excluded and stays polymorphic: its
            // parent table is chosen by the sibling ougTargetType column, so
            // there is no single table to reference. Same shape as core's
            // scheduledTasks.stGroupHostID.
            //
            // No column change was needed: all eight are int(11) NOT NULL
            // against int(11) parents, and none carries a sentinel.
            //
            // Appended, never folded into an earlier step -- installdb() SKIPS
            // the pSchema steps an install has already passed.
            function () {
                $res = \FOG\Db\SchemaReconciler::sweepOrphans('oidc');
                if (is_string($res)) {
                    return $res;
                }
                return \FOG\Db\SchemaReconciler::applyConstraints('oidc');
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
        $res = \FOG\Items\Schema::applyUpdates($this->schema(), 0);
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
        (new \FOG\Plugins\OIDC\Managers\OIDCIdentityManager())->uninstall();
        (new \FOG\Plugins\OIDC\Managers\OIDCGroupRoleAssociationManager())->uninstall();
        (new \FOG\Plugins\OIDC\Managers\OIDCGroupUserGroupAssociationManager())->uninstall();
        (new \FOG\Plugins\OIDC\Managers\OIDCGroupManager())->uninstall();
        (new \FOG\Plugins\OIDC\Managers\OIDCUserGrantManager())->uninstall();
        return parent::uninstall();
    }
}
