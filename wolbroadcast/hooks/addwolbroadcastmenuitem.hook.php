<?php
/**
 * Adds the wol menu item.
 *
 * PHP version 5
 *
 * @category AddWOLBroadcastMenuItem
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

namespace FOG\Plugins\Wolbroadcast;

/**
 * Adds the wol menu item.
 *
 * @category AddWOLBroadcastMenuItem
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class AddWOLBroadcastMenuItem extends \FOG\Base\Hook
{
    /**
     * The name of this hook.
     *
     * @var string
     */
    public $name = 'AddWOLBroadcastMenuItem';
    /**
     * The description of this hook.
     *
     * @var string
     */
    public $description = 'Add menu item for WOL Broadcast';
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
    public $node = 'wolbroadcast';
    /**
     * Initializes object.
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
            ['SUB_MENULINK_DATA', 'menuUpdate'],
            ['REPORT_TITLE_DATA', 'reportTitle'],
        ]);
    }
    /**
     * Names this plugin's report in the Reports menu.
     *
     * Without this the sidebar shows ucwords() of the FILE name -- "Wolbroadcast Report"
     * -- while the page it opens is headed "Export WOL Broadcasts". Two names for one
     * screen, and the file name is the half nobody chose.
     *
     * Keyed the way the menu and the base64 `f` parameter are: the file
     * name with underscores as spaces, lower case. The report reads the
     * same map back through reportTitle() for its heading, so the two
     * cannot drift apart again.
     *
     * @param mixed $arguments The titles to modify.
     *
     * @return void
     */
    public function reportTitle($arguments)
    {
        $arguments['titles']['wolbroadcast report'] = _('Export WOL Broadcasts');
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
        if ($arguments['node'] != $this->node) {
            return;
        }
        $arguments['menu']['export'] = _('Export Broadcasts');
        $arguments['menu']['import'] = _('Import Broadcasts');
    }
    /**
     * The menu data to change.
     *
     * @param mixed $arguments The arguments to change.
     *
     * @return void
     */
    public function menuData($arguments)
    {
        $arguments['hook_main'][$this->node]
            = [_('WOL Broadcasts'), 'fas fa-power-off'];
    }
    /**
     * Adds the wol page to search elements.
     *
     * @param mixed $arguments The arguments to change.
     *
     * @return void
     */
    public function addSearch($arguments)
    {
        $arguments['searchPages'][] = $this->node;
    }
    /**
     * Adds the wol page to objects elements.
     *
     * @param mixed $arguments The arguments to change.
     *
     * @return void
     */
    public function addPageWithObject($arguments)
    {
        $arguments['PagesWithObjects'][] = $this->node;
    }

    /**
     * Registers this plugin's permission node and actions so its pages
     * are gated by RBAC and shown in the role permission matrix.
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
    }
}
