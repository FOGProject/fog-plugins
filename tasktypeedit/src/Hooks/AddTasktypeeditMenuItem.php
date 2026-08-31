<?php
/**
 * Adds task type edit menu item.
 *
 * PHP Version 5
 *
 * @category AddTasktypeeditMenuItem
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

namespace FOG\Plugins\TaskTypeEdit\Hooks;

/**
 * Adds task type edit menu item.
 *
 * @category AddTasktypeeditMenuItem
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class AddTasktypeeditMenuItem extends \FOG\Base\Hook
{
    /**
     * Name of the hook.
     *
     * @var string
     */
    public $name = 'AddTasktypeeditMenuItem';
    /**
     * Description of the hook.
     *
     * @var string
     */
    public $description = 'Add menu item for Task Type editing';
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
    public $node = 'tasktypeedit';
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
     * Without this the sidebar shows ucwords() of the FILE name -- "Tasktypeedit Report"
     * -- while the page it opens is headed "Export Task Types". Two names for one
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
        $arguments['titles']['tasktypeedit report'] = _('Export Task Types');
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
        $arguments['menu']['list'] = _('List All Task Types');
        $arguments['menu']['add'] = _('Create New Task Type');
        $arguments['menu']['export'] = _('Export Task Types');
        $arguments['menu']['import'] = _('Import Task Types');
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
            = [_('Task Types'), 'fas fa-tags'];
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
