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
     * Prepares to clean up associations
     *
     * @param mixed $arguments The items to change.
     *
     * @return void
     */
    public function deletemassitems($arguments)
    {
        switch ($arguments['classname']) {
            case 'host':
                $arguments['removeItems']['directorygroupsassociation'] = [
                    'hostID' => $arguments['itemIDs']
                ];
                break;
            case 'directorygroups':
                // Only treat itemIDs as ouIDs when an OU itself is deleted.
                // A bare default would mis-fire for every other entity now that
                // all delete paths funnel through DELETEMASS_API, deleting
                // ouassociation rows whose directorygroupsID collides with the deleted id.
                $arguments['removeItems']['directorygroupsassociation'] = [
                    'directorygroupsID' => $arguments['itemIDs']
                ];
                break;
        }
    }
}
