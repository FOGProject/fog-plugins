<?php
/**
 * Deletes the OU the elements en-mass.
 *
 * PHP version 5
 *
 * @category DirectoryGroupsDeleteMassItems
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

namespace FOG\Plugins\DirectoryGroups\Hooks;

/**
 * Deletes the OU the elements en-mass.
 *
 * @category DirectoryGroupsDeleteMassItems
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class DirectoryGroupsDeleteMassItems extends \FOG\Base\Hook
{
    /**
     * The name of this hook.
     *
     * @var string
     */
    public $name = 'DirectoryGroupsDeleteMassItems';
    /**
     * The description of this hook.
     *
     * @var string
     */
    public $description = 'Delete En-mass Route altering for OU';
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
     * Initialize object.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->registerInstalled([
            ['DELETEMASS_API', 'deletemassitems'],
        ]);
    }
    /**
     * Deletes a directory group's core group with it
     *
     * @param mixed $arguments The items to change.
     *
     * @return void
     */
    public function deletemassitems($arguments)
    {
        // A deleted host leaves its directory group by core's own cascade:
        // the membership is a groupMembers row now.
        if ('directorygroups' !== $arguments['classname']) {
            return;
        }
        // A deleted directory group takes its core group with it. Only the
        // ids that exist: an empty list would reach deletemass() as "no
        // filter", which matches every group.
        $ids = array_values(array_filter(array_map('intval', (array)$arguments['itemIDs'])));
        if (count($ids) < 1) {
            return;
        }
        $groupIDs = array_values(
            array_filter(
                array_map(
                    'intval',
                    (array)\FOG\Router\Route::getIds('directorygroups', ['id' => $ids], 'groupID')
                )
            )
        );
        if (count($groupIDs) > 0) {
            $arguments['removeItems']['group'] = ['id' => $groupIDs];
        }
    }
}
