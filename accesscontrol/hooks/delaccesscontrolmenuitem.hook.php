<?php
/**
 * Adds the Access control menu item.
 *
 * PHP version 5
 *
 * @category AddAccessControlMenuItem
 * @package  FOGProject
 * @author   Fernando Gietz <fernando.gietz@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
/**
 * Adds the Access control menu item.
 *
 * @category AddAccessControlMenuItem
 * @package  FOGProject
 * @author   Fernando Gietz <fernando.gietz@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class DelAccessControlMenuItem extends Hook
{
    /**
     * The name of this hook.
     *
     * @var string
     */
    public $name = 'DelAccessControlMenuItem';
    /**
     * The description of this hook.
     *
     * @var string
     */
    public $description = 'Delete menus item for access control';
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
    public $node = 'accesscontrol';
    /**
     * Initialize object.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        if (!in_array($this->node, (array)self::$pluginsinstalled)) {
            return;
        }
        self::$HookManager->register(
            'DELETE_MENU_DATA',
            [$this, 'deleteMenuData']
        )->register(
            'DELETE_MENULINK_DATA',
            [$this, 'deleteSubMenuData']
        )->register(
            'ACTIONBOX',
            [$this, 'deleteActionBoxData']
        );
    }
    /**
     * Get the access control rules more centrally.
     *
     * @param string $event The rule type to get
     *
     * @return []
     */
    private function getAccessControlRules($event)
    {
        $find = ['userID' => self::$FOGUser->get('id')];
        Route::ids(
            'accesscontrolassociation',
            $find,
            'accesscontrolID'
        );
        $accesscontrols = json_decode(
            Route::getData(),
            true
        );
        if (!$accesscontrols) {
            return new stdClass(['data' => []]);
        }
        $find = ['accesscontrolID' => $accesscontrols];
        Route::ids(
            'accesscontrolruleassociation',
            $find,
            'accesscontrolruleID'
        );
        $ruleIDs = json_decode(
            Route::getData(),
            true
        );
        if (!$ruleIDs) {
            return new stdClass(['data' => []]);
        }
        $find = ['id' => $ruleIDs, 'type' => $event];
        Route::listem(
            'accesscontrolrule',
            $find
        );
        $Rules = json_decode(Route::getData());
        return $Rules;
    }
    /**
     * Remove the action box
     *
     * @param mixed $arguments The arguments to change.
     */
    public function deleteActionBoxData($arguments)
    {
        $Rules = $this->getAccessControlRules($arguments['event']);
        foreach ($Rules->data as &$Rule) {
            $arguments[$Rule->value] = '';
            unset(
                $arguments[$Rule->value],
                $Rule
            );
        }
    }
    /**
     * The menu data to change.
     *
     * @param mixed $arguments The arguments to change.
     *
     * @return void
     */
    public function deleteMenuData($arguments)
    {
        $Rules = $this->getAccessControlRules($arguments['event']);
        foreach ($Rules->data as &$Rule) {
            unset(
                $arguments[$Rule->parent][$Rule->value],
                $Rule
            );
        }
    }
    /**
     * The menu data to change.
     *
     * @param mixed $arguments The arguments to change.
     *
     * @return void
     */
    public function deleteSubMenuData($arguments)
    {
        $Rules = $this->getAccessControlRules($arguments['event']);
        foreach ($Rules->data as &$Rule) {
            // If to impact a specific node.
            if ($Rule->node) {
                if ($arguments['node'] != $Rule->node) {
                    continue;
                }
                unset($arguments[$Rule->parent][$Rule->value]);
                continue;
            }
            // If to impact a specific link.
            unset($arguments[$Rule->parent][$Rule->value]);
            unset($Rule);
        }
    }
}
