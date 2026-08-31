<?php
/**
 * Adds the menu item and permission node for this plugin.
 *
 * PHP version 7.4+
 *
 * @category AddOIDCMenuItem
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

namespace FOG\Plugins\OIDC\Hooks;

/**
 * Adds the menu item and permission node for this plugin.
 *
 * @category AddOIDCMenuItem
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class AddOIDCMenuItem extends \FOG\Base\Hook
{
    /**
     * The second node this plugin owns: the provider groups whose
     * associations decide what a signing-in user receives.
     */
    const GROUP_NODE = 'oidcgroup';
    /**
     * The name of this hook.
     *
     * @var string
     */
    public $name = 'AddOIDCMenuItem';
    /**
     * The description of this hook.
     *
     * @var string
     */
    public $description = 'Add menu item for OpenID Connect';
    /**
     * The active flag.
     *
     * @var bool
     */
    public $active = true;
    /**
     * The node to enact on.
     *
     * @var string
     */
    public $node = 'oidc';
    /**
     * Initialize object.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->registerInstalled([
            ['MAIN_MENU_DATA', 'menuData'],
            ['SEARCH_PAGES', 'addSearch'],
            ['PAGES_WITH_OBJECTS', 'addPageWithObject'],
            ['PERMISSION_REGISTRY_DATA', 'permData'],
            ['SUB_MENULINK_DATA', 'menuUpdate']
        ]);
    }
    /**
     * Add the new items beyond list/create.
     *
     * @param mixed $arguments The items to modify.
     *
     * @return void
     */
    public function menuUpdate($arguments)
    {
        // Provider groups are a node of their own, so they need their own
        // Export entry. FOGPage::export() is inherited and works for any
        // node; what the core menu builder keys off ($foglang[$refNode]) has
        // no entry for a plugin node, so without this the page exists and is
        // permission-gated but nothing links to it.
        if ($arguments['node'] == self::GROUP_NODE) {
            $arguments['menu']['export'] = self::$foglang['Export']
                . ' ' . _('Groups');
            return;
        }
        if ($arguments['node'] != $this->node) {
            return;
        }
        $arguments['menu']['export'] = self::$foglang['Export']
            . ' ' . _('Providers');
    }
    /**
     * Sets the menu item into the menu.
     *
     * @param mixed $arguments the item to adjust
     *
     * @return void
     */
    public function menuData($arguments)
    {
        $arguments['hook_main'][$this->node]
            = [_('OpenID Connect'), 'far fa-id-badge'];
        // Groups get their own node because granting a role or a user group
        // is an ordinary association, and the shared association tab needs
        // the group itself to be the owning object. See OIDCGroupManagement.
        $arguments['hook_main'][self::GROUP_NODE]
            = [_('OpenID Connect Groups'), 'fas fa-users'];
    }
    /**
     * Adds the plugin page to the search page lists.
     *
     * @param mixed $arguments the item to adjust
     *
     * @return void
     */
    public function addSearch($arguments)
    {
        $arguments['searchPages'][] = $this->node;
        $arguments['searchPages'][] = self::GROUP_NODE;
    }
    /**
     * Adds the plugin page to use internalized objects.
     *
     * @param mixed $arguments the item to adjust
     *
     * @return void
     */
    public function addPageWithObject($arguments)
    {
        $arguments['PagesWithObjects'][] = $this->node;
        $arguments['PagesWithObjects'][] = self::GROUP_NODE;
    }
    /**
     * Registers this plugin's permission node and actions.
     *
     * Without this the pages are unreachable for everybody who does not hold
     * '*' -- core denies an unregistered node rather than guessing. The four
     * ordinary actions are all this plugin needs; there is no separate action
     * for "may enable a provider", because holding oidc.edit is already the
     * ability to point FOG at a different identity provider, which is the
     * same power.
     *
     * @param mixed $arguments The permission registry to modify.
     *
     * @return void
     */
    public function permData($arguments)
    {
        $arguments['registry'][$this->node] = [
            'view', 'create', 'edit', 'delete'
        ];
        // Registered separately so a role can be given the ability to manage
        // providers without the ability to change what a provider group
        // grants -- the latter is the one that hands out access.
        $arguments['registry'][self::GROUP_NODE] = [
            'view', 'create', 'edit', 'delete'
        ];
    }
}
