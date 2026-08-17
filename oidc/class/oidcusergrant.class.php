<?php
/**
 * What this plugin granted a user, so it can take it back.
 *
 * PHP version 7.4+
 *
 * @category OIDCUserGrant
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
/**
 * What this plugin granted a user, so it can take it back.
 *
 * The provider is recomputed on every sign in, so removing somebody from a
 * group has to downgrade them at their next login. Doing that needs an
 * answer to "which of this user's roles are ours to remove?", and there are
 * only two ways to answer it without this table, both wrong:
 *
 *   - Remove everything not currently granted. That silently revokes
 *     anything an admin attached by hand, and leaves no way to give a
 *     provider-authenticated user anything extra.
 *   - Derive the managed set from the mapping tables. Then deleting a
 *     mapping stops it being ours, so the role it granted is never taken
 *     away -- removing a mapping would leave everyone who had it holding
 *     the role forever.
 *
 * Recording the grant closes both. The record survives the mapping being
 * deleted, so the next sign in still knows the grant was this plugin's to
 * take away, and it is per-user, which is a more honest answer to "did we
 * give this person this?" than any set derived from mappings can be.
 *
 * @category OIDCUserGrant
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class OIDCUserGrant extends FOGController
{
    /**
     * Target kinds, matching the association tables they mirror.
     */
    const TARGET_ROLE = 'role';
    const TARGET_USERGROUP = 'usergroup';
    /**
     * The table name.
     *
     * @var string
     */
    protected $databaseTable = 'oidcUserGrant';
    /**
     * The table fields.
     *
     * @var array
     */
    protected $databaseFields = [
        'id' => 'ougID',
        'name' => 'ougName',
        'userID' => 'ougUserID',
        'targetType' => 'ougTargetType',
        'targetID' => 'ougTargetID'
    ];
    /**
     * The required fields.
     *
     * @var array
     */
    protected $databaseFieldsRequired = [
        'userID',
        'targetType',
        'targetID'
    ];
}
