<?php
/**
 * Deletes the Location the elements en-mass.
 *
 * PHP version 5
 *
 * @category LocationDeleteMassItems
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

namespace FOG\Plugins\Location\Hooks;

/**
 * Deletes the Location the elements en-mass.
 *
 * @category LocationDeleteMassItems
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class LocationDeleteMassItems extends \FOG\Base\Hook
{
    /**
     * The name of this hook.
     *
     * @var string
     */
    public $name = 'LocationDeleteMassItems';
    /**
     * The description of this hook.
     *
     * @var string
     */
    public $description = 'Delete En-mass Route altering for Location';
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
    public $node = 'location';
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
                $arguments['removeItems']['locationassociation'] = [
                    'hostID' => $arguments['itemIDs']
                ];
                break;
            case 'storagegroup':
                // Nothing to do, and nothing that CAN be done. `location`.
                // `lStorageGroupID` is NOT NULL with a RESTRICT foreign key
                // to `nfsGroups` (ADR 0031), so a storage group that any
                // location still points at cannot be deleted at all -- the
                // database refuses it, which is the intended answer. A
                // location without a storage group is not a valid location.
                //
                // This arm used to call
                //   ->update(['storagegroupID' => $ids], '', 0)
                // which passed 0 as the UPDATE DATA. FOGManagerController::
                // update() takes an associative array or an array of them
                // and returns false for anything else, so the call has
                // always been a silent no-op. It read as cleanup that made
                // the delete safe; it never ran.
                break;
            case 'storagenode':
                // NULL, not 0. The column is nullable and its foreign key is
                // ON DELETE SET NULL, so "no node" IS null -- a 0 names a
                // storage node that does not exist and the constraint
                // rejects it. Same silent no-op as above before this: the
                // third argument was the bare integer 0, not a map.
                //
                // Kept rather than left to the constraint, because a server
                // between deploying this code and running the schema updater
                // has the column and not the foreign key, and there this is
                // the only thing doing the work.
                self::getClass('LocationManager')->update(
                    ['storagenodeID' => $arguments['itemIDs']],
                    '',
                    ['storagenodeID' => null]
                );
                break;
            case 'location':
                $arguments['removeItems']['locationassociation'] = [
                    'locationID' => $arguments['itemIDs']
                ];
                break;
        }
    }
}
