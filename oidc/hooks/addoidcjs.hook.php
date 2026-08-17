<?php
/**
 * Injects the OpenID Connect JavaScript for the relevant sub-page.
 *
 * The PAGE_JS_FILES event lets a plugin add JS files to the page. The
 * convention is one file per sub-page: fog.<node>.<sub>.js (e.g.
 * fog.oidc.list.js for sub=list, fog.oidc.edit.js for sub=edit).
 *
 * PHP version 7.4+
 *
 * @category AddOIDCJS
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
/**
 * Injects the OpenID Connect JS files.
 *
 * @category AddOIDCJS
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class AddOIDCJS extends Hook
{
    /**
     * The name of this hook.
     *
     * @var string
     */
    public $name = 'AddOIDCJS';
    /**
     * The description.
     *
     * @var string
     */
    public $description = 'Add OpenID Connect JS files.';
    /**
     * For posterity.
     *
     * @var bool
     */
    public $active = true;
    /**
     * What plugin this works against.
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
            ['PAGE_JS_FILES', 'injectJSFiles'],
        ]);
    }
    /**
     * Adds the per-sub-page JS file for the nodes this plugin owns.
     *
     * Through the shared Hook::injectPluginJS() rather than hand-rolled, now
     * that there are two nodes to serve: it already knows the naming
     * convention, folds sub=membership onto the edit file, and falls back to
     * the list JS for a sub-page with no file of its own.
     *
     * @param mixed $arguments The arguments to modify.
     *
     * @return void
     */
    public function injectJSFiles($arguments)
    {
        $this->injectPluginJS($arguments, [
            'oidc' => ['fallback' => true],
            'oidcgroup' => ['fallback' => true],
        ]);
    }
}
