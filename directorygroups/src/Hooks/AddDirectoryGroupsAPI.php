<?php
/**
 * Injects ou stuff into the api system.
 *
 * PHP version 5
 *
 * @category AddDirectoryGroupsAPI
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

namespace FOG\Plugins\DirectoryGroups\Hooks;

/**
 * Injects ou stuff into the api system.
 *
 * @category AddDirectoryGroupsAPI
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class AddDirectoryGroupsAPI extends \FOG\Base\Hook
{
    /**
     * The name of the hook.
     *
     * @var string
     */
    public $name = 'AddDirectoryGroupsAPI';
    /**
     * The description.
     *
     * @var string
     */
    public $description = 'Add OU stuff into the api system.';
    /**
     * For posterity.
     *
     * @var bool
     */
    public $active = true;
    /**
     * The node the hook works with.
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
            ['API_VALID_CLASSES', 'injectAPIElements'],
        ]);
    }
    /**
     * This function injects ou elements for
     * api access.
     *
     * @param mixed $arguments The arguments to modify.
     *
     * @return void
     */
    public function injectAPIElements($arguments)
    {
        array_push(
            $arguments['validClasses'],
            $this->node,
            'directorygroupsassociation'
        );
    }
}
