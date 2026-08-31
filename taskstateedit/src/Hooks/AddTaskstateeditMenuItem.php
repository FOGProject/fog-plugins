<?php
/**
 * Adds task state edit menu item.
 *
 * PHP Version 5
 *
 * @category AddTaskstateeditMenuItem
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

namespace FOG\Plugins\TaskStateEdit\Hooks;

/**
 * Adds task state edit menu item.
 *
 * @category AddTaskstateeditMenuItem
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class AddTaskstateeditMenuItem extends \FOG\Base\Hook
{
    /**
     * Name of the hook.
     *
     * @var string
     */
    public $name = 'AddTaskstateeditMenuItem';
    /**
     * Description of the hook.
     *
     * @var string
     */
    public $description = 'Add menu item for Task State editing';
    /**
     * Active?
     *
     * @var bool
     */
    public $active = true;
    /**
     * Node to work with.
     *
     * @var string
     */
    public $node = 'taskstateedit';
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
            ['SUB_MENULINK_DATA', 'menuUpdate'],
            ['REPORT_TITLE_DATA', 'reportTitle'],
        ]);
    }
    /**
     * Names this plugin's report in the Reports menu.
     *
     * Without this the sidebar shows ucwords() of the FILE name -- "Taskstateedit Report"
     * -- while the page it opens is headed "Export Task States". Two names for one
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
        $arguments['titles']['taskstateedit report'] = _('Export Task States');
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
        $arguments['menu']['list'] = _('List All Task States');
        $arguments['menu']['add'] = _('Create New Task State');
        $arguments['menu']['export'] = _('Export Task States');
        $arguments['menu']['import'] = _('Import Task States');
    }
    /**
     * Adds the menu item.
     *
     * @param mixed $arguments The items to modify.
     *
     * @return void
     */
    public function menuData($arguments)
    {
        $arguments['hook_main'][$this->node]
            = [_('Task States'), 'fas fa-traffic-light'];
    }
    /**
     * Adds search element.
     *
     * @param mixed $arguments The items to modify.
     *
     * @return void
     */
    public function addSearch($arguments)
    {
        $arguments['searchPages'][] = $this->node;
    }
    /**
     * Adds page with object.
     *
     * @param mixed $arguments The items to modify.
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
