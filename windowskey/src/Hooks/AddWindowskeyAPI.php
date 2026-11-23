<?php
/**
 * Injects windows key stuff into the api system.
 *
 * PHP version 5
 *
 * @category AddWindowskeyAPI
 * @package  FOGProject
 * @author   Fernando Gietz <fernando.gietz@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

namespace FOG\Plugins\WindowsKey\Hooks;

/**
 * Injects windows key stuff into the api system.
 *
 * @category AddWindowskeyAPI
 * @package  FOGProject
 * @author   Fernando Gietz <fernando.gietz@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class AddWindowskeyAPI extends \FOG\Base\Hook
{
    /**
     * The name of the hook.
     *
     * @var string
     */
    public $name = 'AddWindowskeyAPI';
    /**
     * The hooks description.
     *
     * @var string
     */
    public $description = 'Add windows key stuff into the api system.';
    /**
     * For posterity.
     *
     * @var bool
     */
    public $active = true;
    /**
     * The node the plugin works on.
     *
     * @var string
     */
    public $node = 'windowskey';
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
        ]);
    }
    /**
     * Classifies this plugin's two pattern-matching columns.
     *
     * windowskey.key is a Windows product key, which is the same kind of
     * thing as core's host.productKey -- so it goes in the same tier core
     * puts that one in: 'always', which the API strips from every payload,
     * a direct single-entity GET included. It used to be in the ordinary
     * tier, which a single GET keeps, so any windowskey.view holder could
     * read one key at a time. Core moved host.productKey to 'always' for
     * the same reason (GHSA-q38j-qx5x-w25v). The plugin's own pages are
     * unaffected: they read the model directly, and the list grid is served
     * by the web tier at ?node=windowskey&sub=list, not by the API emitter
     * that strips.
     *
     * windowskeyassociation.windowskeyID is the opposite case. It matches
     * Redaction::CREDENTIAL_PATTERN only because the word "key" is in the
     * plugin's name -- it is a foreign key to the windowskey row, an integer
     * id, and redacting it would blank the association's only meaningful
     * column in the audit trail while protecting nothing. Hence the 'exempt'
     * bucket, which exists so a plugin can say this about its own model:
     * core must not name a plugin's class, because the bundled plugins are a
     * fetched artifact and a core entry for one breaks on any tree that has
     * not fetched them.
     *
     * @param mixed $arguments The tier maps to modify.
     *
     * @return void
     */
    public function declareSensitiveFields($arguments)
    {
        $arguments['always'][$this->node][] = 'key';
        $arguments['exempt']['windowskeyassociation'][] = 'windowskeyID';
    }
    /**
     * This function injects site elements for
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
            'windowskeyassociation'
        );
    }
}
