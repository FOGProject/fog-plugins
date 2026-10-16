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
            ['API_SENSITIVE_FIELDS', 'declareSensitiveFields'],
            ['API_SERVER_OWNED_FIELDS', 'declareServerOwnedFields'],
            ['DIRECTORYDOMAIN_EXPORT_ITEMS', 'stripBindPassword'],
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
            'directorydomain'
        );
    }
    /**
     * Declares a domain's bind and join passwords as secrets the API never emits.
     *
     * The 'always' tier, as the ldap plugin does for its own bind password:
     * only the server binds with it, so nothing has a reason to read it
     * back, not even from a single-entity GET.
     *
     * @param mixed $arguments The tier maps to modify.
     *
     * @return void
     */
    public function declareSensitiveFields($arguments)
    {
        $arguments['always']['directorydomain'][] = 'bindPwd';
        $arguments['always']['directorydomain'][] = 'joinPwd';
    }
    /**
     * Refuses API writes to a directory group's core group.
     *
     * DirectoryGroups::save() creates that group and keeps its name in step.
     * A client that pointed groupID at another group would move every host
     * of the directory group into it.
     *
     * @param mixed $arguments The field map to modify.
     *
     * @return void
     */
    public function declareServerOwnedFields($arguments)
    {
        $arguments['fields']['directorygroups'][] = 'groupID';
    }
    /**
     * Keeps the bind and join passwords out of the domain export.
     *
     * The list is covered by the sensitive-field tier above. FOGPage::export()
     * builds its CSV from the table's own columns and does not consult that
     * tier, so without this the export would carry the passwords.
     *
     * @param mixed $arguments The arguments to modify.
     *
     * @return void
     */
    public function stripBindPassword($arguments)
    {
        $arguments['columns'] = array_values(
            array_filter(
                $arguments['columns'],
                function ($column) {
                    return !in_array($column['dt'], ['bindPwd', 'joinPwd'], true);
                }
            )
        );
    }
}
