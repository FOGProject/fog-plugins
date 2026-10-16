<?php
/**
 * Supplies each host's directory connection from its domain.
 *
 * PHP version 7
 *
 * @category AddDirectoryDomainConnection
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

namespace FOG\Plugins\DirectoryGroups\Hooks;

use FOG\Plugins\DirectoryGroups\Items\DirectoryDomain;

/**
 * Supplies each host's directory connection from its domain.
 *
 * Core places a host's computer object with one connection, read from the
 * FOG_DIRECTORY_* settings. This plugin keeps one connection per domain
 * instead, so it answers DIRECTORY_CONNECTION with the domain the host is in,
 * and takes those settings off the FOG Settings page while it is installed.
 * Uninstalling the plugin brings them back, and placement uses them again.
 *
 * @category AddDirectoryDomainConnection
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class AddDirectoryDomainConnection extends \FOG\Base\Hook
{
    /**
     * The settings the domain list replaces. FOG_DIRECTORY_PLACEMENT_ENABLED
     * is not one of them: it is the switch for placement as a whole.
     *
     * @var string[]
     */
    const REPLACED_SETTINGS = [
        'FOG_DIRECTORY_LDAP_URI',
        'FOG_DIRECTORY_BIND_DN',
        'FOG_DIRECTORY_BIND_PASSWORD',
        'FOG_DIRECTORY_CA_CERT',
        'FOG_DIRECTORY_BASE_DN'
    ];
    /**
     * The name of this hook.
     *
     * @var string
     */
    public $name = 'AddDirectoryDomainConnection';
    /**
     * The description of this hook.
     *
     * @var string
     */
    public $description = 'Directory connection per domain';
    /**
     * The active flag.
     *
     * @var bool
     */
    public $active = true;
    /**
     * The node this hook enacts with.
     *
     * @var string
     */
    public $node = 'directorygroups';
    /**
     * Initializes object.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->registerInstalled([
            ['DIRECTORY_CONNECTION', 'connection'],
            ['DIRECTORY_JOIN_ACCOUNT', 'joinAccount'],
            ['SETTINGS_HIDDEN', 'hideSettings'],
        ]);
    }
    /**
     * Answers with the connection of the host's domain.
     *
     * A host whose domain is not in the list gets an empty connection, which
     * core reads as "no server for this host" and skips. It does not fall
     * back to the hidden settings: an admin cannot see or change those while
     * the plugin is installed, so they must not act.
     *
     * @param mixed $arguments The event arguments.
     *
     * @return void
     */
    public function connection($arguments)
    {
        $domain = DirectoryDomain::forName($arguments['domain'] ?? '');
        $arguments['connection'] = $domain
            ? $domain->connection()
            : ['uri' => '', 'bindDN' => '', 'password' => '', 'caCert' => '', 'baseDN' => ''];
    }
    /**
     * Answers with the join account of the host's domain.
     *
     * The domain's account beats the one saved on the host when the domain
     * has one (GH-1887). Registration copies the default account onto every
     * host, so the other way round the domain's account would almost never
     * apply. A domain with no join account, or no domain in the list, leaves
     * the host's own account.
     *
     * @param mixed $arguments The event arguments.
     *
     * @return void
     */
    public function joinAccount($arguments)
    {
        $domain = DirectoryDomain::forName($arguments['domain'] ?? '');
        $account = $domain ? $domain->joinAccount() : null;
        if (null === $account) {
            return;
        }
        list($arguments['user'], $arguments['password']) = $account;
    }
    /**
     * Hides the settings the domain list replaces.
     *
     * @param mixed $arguments The event arguments.
     *
     * @return void
     */
    public function hideSettings($arguments)
    {
        foreach (self::REPLACED_SETTINGS as $key) {
            $arguments['hidden'][$key] = true;
        }
    }
}
