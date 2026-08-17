<?php
/**
 * Adds this plugin's class to the API and declares its secret.
 *
 * PHP version 7.4+
 *
 * @category AddOIDCAPI
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
/**
 * Adds this plugin's class to the API and declares its secret.
 *
 * @category AddOIDCAPI
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class AddOIDCAPI extends Hook
{
    /**
     * The name of this hook.
     *
     * @var string
     */
    public $name = 'AddOIDCAPI';
    /**
     * The description.
     *
     * @var string
     */
    public $description = 'Add OpenID Connect into the api system.';
    /**
     * For posterity.
     *
     * @var bool
     */
    public $active = true;
    /**
     * The node to work with.
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
            ['API_VALID_CLASSES', 'injectAPIElements'],
            ['API_SENSITIVE_FIELDS', 'declareSensitiveFields'],
            ['OIDC_EXPORT_ITEMS', 'stripClientSecret'],
            ['CUSTOMIZE_DT_COLUMNS', 'customizeDT']
        ]);
    }
    /**
     * Declares the client secret as something the API must never emit.
     *
     * The 'always' tier, not the ordinary one: 'always' is stripped from a
     * direct single-entity GET as well as from lists. The ordinary tier
     * exists for values a client legitimately reads back, as fog-client does
     * with host.ADPass. Nothing reads this one back -- only the web tier
     * presents it, to the provider's token endpoint, and it does so through
     * the model -- so it has no reason to leave the server at all.
     *
     * @param mixed $arguments The tier maps to modify.
     *
     * @return void
     */
    public function declareSensitiveFields($arguments)
    {
        $arguments['always'][$this->node][] = 'clientSecret';
    }
    /**
     * Keeps the client secret out of the CSV export.
     *
     * declareSensitiveFields() covers the API and the edit form never renders
     * the stored value; the export is the remaining bulk surface, and a
     * downloadable file containing every provider credential is the worst of
     * the three. The cost is that an exported provider re-imports unable to
     * complete a token exchange until the secret is re-entered, which is the
     * right trade.
     *
     * FOGPage::export() builds its header row from these same columns, so
     * dropping the column here drops its <th> too and the table keeps a
     * column for every header.
     *
     * @param mixed $arguments The arguments to modify.
     *
     * @return void
     */
    public function stripClientSecret($arguments)
    {
        $arguments['columns'] = array_values(
            array_filter(
                $arguments['columns'],
                function ($column) {
                    return 'clientSecret' !== $column['dt'];
                }
            )
        );
    }
    /**
     * Adds the owning provider column to the provider group list.
     *
     * The list JSON is built from the table's own columns, so a group row
     * carries ogProviderID and no provider name. Route only knows how to
     * turn a handful of core id columns into a link, and ogProviderID is not
     * one of them -- without this the datatable asks for a column the payload
     * never had and every visit to the list opens a DataTables warning alert.
     *
     * Done through this hook rather than by adding a case to Route so a
     * plugin concern stays in the plugin. A 'providerID' case in core would
     * also be a global claim on a very generic column name.
     *
     * @param mixed $arguments The arguments to modify.
     *
     * @return void
     */
    public function customizeDT($arguments)
    {
        if ($arguments['classname'] != 'oidcgroup') {
            return;
        }
        $arguments['columns'][] = [
            'db' => 'ogProviderID',
            'dt' => 'oidcprovider',
            'formatter' => function ($d, $row) {
                return OIDCGroup::providerLinkCell($d);
            }
        ];
    }
    /**
     * Injects this plugin's classes into the API class list.
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
            // The identity links. Exposed so an admin can see and remove a
            // link over the API as well as watch it in the list -- and so
            // DELETEMASS_API can name it as a removeItems target, which is
            // how a deleted user's links are cleared.
            'oidcidentity',
            'oidcgroup',
            'oidcgrouproleassociation',
            'oidcgroupusergroupassociation',
            // The record of what this plugin granted. Listed for the same
            // DELETEMASS_API reason: a deleted role or user group has to be
            // able to name it as a removeItems target.
            'oidcusergrant'
        );
    }
}
