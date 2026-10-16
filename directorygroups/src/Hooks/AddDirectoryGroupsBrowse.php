<?php
/**
 * Adds a directory browser to the host's Active Directory tab.
 *
 * PHP version 7
 *
 * @category AddDirectoryGroupsBrowse
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

namespace FOG\Plugins\DirectoryGroups\Hooks;

/**
 * Adds a directory browser to the host's Active Directory tab.
 *
 * The Browse button opens a modal that lists a domain's OUs. Picking one
 * fills the tab's Domain Name and Organizational Unit fields, so an admin
 * no longer types a DN by hand. Nothing is saved until they click Update.
 *
 * Shown only to a user who may view domains: the modal reads them from the
 * directorydomain node, which would refuse anyone else.
 *
 * @category AddDirectoryGroupsBrowse
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class AddDirectoryGroupsBrowse extends \FOG\Base\Hook
{
    /**
     * The name of this hook.
     *
     * @var string
     */
    public $name = 'AddDirectoryGroupsBrowse';
    /**
     * The description of this hook.
     *
     * @var string
     */
    public $description = 'Browse the directory from the host AD tab';
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
     * Initializes object.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->registerInstalled([
            ['HOST_EDIT_AD_FIELDS', 'browseButton'],
        ]);
    }
    /**
     * Adds the Browse button and its modal.
     *
     * The button goes after Update in the footer: both float right, so the
     * first emitted lands rightmost and Update stays the primary action.
     * The modal holds no form of its own, so it can sit inside the tab's.
     *
     * @param mixed $arguments The event arguments.
     *
     * @return void
     */
    public function browseButton($arguments)
    {
        if (!\FOG\Auth\Authorization::can('directorydomain.view')) {
            return;
        }
        $arguments['buttons'] .= \FOG\Base\FOGPage::makeButton(
            'directorygroups-browse',
            _('Browse Directory'),
            'btn btn-secondary float-end'
        );
        $arguments['buttons'] .= '<div class="modal fade" id="directorygroups-browse-modal"'
            . ' tabindex="-1" role="dialog" aria-hidden="true">'
            . '<div class="modal-dialog modal-xl" role="document">'
            . '<div class="modal-content">'
            . '<div class="modal-header"><h4 class="modal-title">'
            . _('Browse Directory')
            . '</h4></div>'
            . '<div class="modal-body">'
            . '<select class="form-control mb-3" id="directorygroups-browse-domain"></select>'
            . '<table id="directorygroups-browse-table" class="table table-bordered table-hover">'
            . '<thead><tr><th>' . _('OU') . '</th><th>'
            . _('Distinguished Name') . '</th></tr></thead>'
            . '</table>'
            . '</div>'
            . '<div class="modal-footer">'
            . '<button type="button" class="btn btn-outline-secondary float-start"'
            . ' data-bs-dismiss="modal">' . _('Cancel') . '</button>'
            . '</div>'
            . '</div></div></div>';
    }
}
