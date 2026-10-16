<?php
/**
 * OU management page.
 *
 * PHP version 5
 *
 * @category DirectoryGroupsManagement
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @author   Lee Rowlett <nah@nah.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

namespace FOG\Plugins\DirectoryGroups\Pages;

/**
 * OU management page.
 *
 * @category DirectoryGroupsManagement
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @author   Lee Rowlett <nah@nah.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class DirectoryGroupsManagement extends \FOG\Base\FOGPage
{
    /**
     * The node this page operates on.
     *
     * @var string
     */
    public $node = 'directorygroups';
    /**
     * Initializes the OU management page.
     *
     * @param string $name Something to lay it out as.
     *
     * @return void
     */
    public function __construct($name = '')
    {
        $this->name = _('OU Management');
        parent::__construct($this->name);
        $this->headerData = [
            _('OU Name'),
            _('OU DN')
        ];
        $this->attributes = [
            [],
            []
        ];
    }
    /**
     * Builds the create-form fields (shared by add() and addModal()).
     *
     * @return array
     */
    protected function _addFields()
    {
        $ou = filter_input(INPUT_POST, 'ou');
        $description = filter_input(INPUT_POST, 'description');
        $oudn = filter_input(INPUT_POST, 'oudn');

        $labelClass = 'col-sm-3 col-form-label';

        return [
            self::makeLabel(
                $labelClass,
                'ou',
                _('OU Name')
            ) => self::makeInput(
                'form-control ouname-input',
                'ou',
                _('OU Name'),
                'text',
                'ou',
                $ou,
                true
            ),
            self::makeLabel(
                $labelClass,
                'description',
                _('OU Description')
            ) => self::makeTextarea(
                'form-control oudescription-input',
                'description',
                _('OU Description'),
                'description',
                $description
            ),
            self::makeLabel(
                $labelClass,
                'oudn',
                _('OU DN')
            ) => self::makeInput(
                'form-control oudn-input',
                'oudn',
                'ou=computers,dc=example,dc=com',
                'text',
                'oudn',
                $oudn,
                true
            )
        ];
    }
    /**
     * Creates new item.
     *
     * @return void
     */
    public function add()
    {
        $this->renderAddForm(
            'directorygroups',
            _('Create New OU'),
            'DIRECTORYGROUPS_ADD_FIELDS',
            'DirectoryGroups'
        );
    }
    /**
     * Creates new item.
     *
     * @return void
     */
    public function addModal()
    {
        $this->renderAddModalForm(
            'directorygroups',
            'DIRECTORYGROUPS_ADD_FIELDS',
            'DirectoryGroups'
        );
    }
    /**
     * Actually create the ou.
     *
     * @return void
     */
    public function addPost()
    {
        $this->handleAddPost(
            'DirectoryGroups',
            'DIRECTORYGROUPS_ADD',
            _('OU added!'),
            _('OU Create Success'),
            _('OU Create Fail'),
            function (&$serverFault) {
                $ou = trim(
                    filter_input(INPUT_POST, 'ou')
                );
                $description = trim(
                    filter_input(INPUT_POST, 'description')
                );
                $oudn = trim(
                    filter_input(INPUT_POST, 'oudn')
                );
                $exists = (new \FOG\Plugins\DirectoryGroups\Managers\DirectoryGroupsManager())
                    ->exists($ou);
                if ($exists) {
                    throw new \Exception(
                        _('An ou already exists with this name!')
                    );
                }
                $OU = (new \FOG\Plugins\DirectoryGroups\Items\DirectoryGroups())
                    ->set('name', $ou)
                    ->set('description', $description)
                    ->set('ou', $oudn);
                if (!$OU->save()) {
                    $serverFault = false;
                    throw new \Exception(_('Add ou failed!'));
                }
                return $OU;
            }
        );
    }
    /**
     * Displays the ou general tab.
     *
     * @return void
     */
    public function directorygroupsGeneral()
    {
        $ou = (
            filter_input(INPUT_POST, 'ou') ?:
            $this->obj->get('name')
        );
        $description = (
            filter_input(INPUT_POST, 'description') ?:
            $this->obj->get('description')
        );
        $oudn = (
            filter_input(INPUT_POST, 'oudn') ?:
            $this->obj->get('ou')
        );

        $labelClass = 'col-sm-3 col-form-label';

        $fields = [
            self::makeLabel(
                $labelClass,
                'ou',
                _('OU Name')
            ) => self::makeInput(
                'form-control ouname-input',
                'ou',
                _('OU Name'),
                'text',
                'ou',
                $ou,
                true
            ),
            self::makeLabel(
                $labelClass,
                'description',
                _('OU Description')
            ) => self::makeTextarea(
                'form-control oudescription-input',
                'description',
                _('OU Description'),
                'description',
                $description
            ),
            self::makeLabel(
                $labelClass,
                'oudn',
                _('OU DN')
            ) => self::makeInput(
                'form-control oudn-input',
                'oudn',
                'ou=computers,dc=example,dc=com',
                'text',
                'oudn',
                $oudn,
                true
            )
        ];

        $buttons = self::makeButton(
            'general-send',
            _('Update'),
            'btn btn-primary float-end'
        );
        $buttons .= self::makeButton(
            'general-delete',
            _('Delete'),
            'btn btn-danger float-start'
        );

        self::$HookManager->processEvent(
            'DIRECTORYGROUPS_GENERAL_FIELDS',
            [
                'fields' => &$fields,
                'buttons' => &$buttons,
                'DirectoryGroups' => &$this->obj
            ]
        );
        $rendered = self::formFields($fields);
        unset($fields);

        echo self::makeFormTag(
            '',
            'directorygroups-general-form',
            self::makeTabUpdateURL(
                'directorygroups-general',
                $this->obj->get('id')
            ),
            'post',
            'application/x-www-form-urlencoded',
            true
        );
        echo '<div class="card">';
        echo '<div class="card-body">';
        echo $rendered;
        echo '</div>';
        echo '<div class="card-footer">';
        echo $buttons;
        echo $this->deleteModal();
        echo '</div>';
        echo '</div>';
        echo '</form>';
    }
    /**
     * Actually update the general information.
     *
     * @return void
     */
    public function directorygroupsGeneralPost()
    {
        self::checkAuthAndCSRF();
        $ou = trim(
            filter_input(INPUT_POST, 'ou')
        );
        $description = trim(
            filter_input(INPUT_POST, 'description')
        );
        $oudn = trim(
            filter_input(INPUT_POST, 'oudn')
        );

        $exists = (new \FOG\Plugins\DirectoryGroups\Managers\DirectoryGroupsManager())
            ->exists($ou);
        if ($ou != $this->obj->get('name')
            && $exists
        ) {
            throw new \Exception(
                _('An OU already exists with this name!')
            );
        }
        $this->obj
            ->set('name', $ou)
            ->set('description', $description)
            ->set('ou', $oudn);
    }
    /**
     * Present the host membership tab.
     *
     * @return void
     */
    public function directorygroupsHosts()
    {
        $this->renderAssocTab(
            'directorygroups-host',
            _('OU Host Associations'),
            _('Host Name'),
            'host'
        );
    }
    /**
     * Update host membership.
     *
     * @return void
     */
    public function directorygroupsHostPost()
    {
        $this->assocPost('addHost', 'removeHost');
    }
    /**
     * Present the ou to edit the page.
     *
     * @return void
     */
    public function edit()
    {
        $tabData = [];

        // General
        $tabData[] = [
            'name' => _('General'),
            'id' => 'directorygroups-general',
            'generator' => function () {
                $this->directorygroupsGeneral();
            }
        ];

        // Hosts
        $tabData[] = [
            'tabs' => [
                'name' => _('Associations'),
                'tabData' => [
                    [
                        'name' => _('Host Association'),
                        'id' => 'directorygroups-host',
                        'generator' => function () {
                            $this->directorygroupsHosts();
                        }
                    ]
                ]
            ]
        ];
        $this->renderEditTabs($tabData, $this->obj);
    }
    /**
     * Actually update the ou.
     *
     * @return void
     */
    public function editPost()
    {
        $this->handleEditPost(
            'DirectoryGroups',
            'DIRECTORYGROUPS_EDIT',
            _('OU updated!'),
            _('OU Update Success'),
            _('OU Update Fail'),
            function (&$serverFault) {
                global $tab;
                switch ($tab) {
                    case 'directorygroups-general':
                        $this->directorygroupsGeneralPost();
                        break;
                    case 'directorygroups-host':
                        $this->directorygroupsHostPost();
                }
                if (!$this->obj->save()) {
                    $serverFault = true;
                    throw new \Exception(_('OU update failed!'));
                }
            }
        );
    }
    /**
     * OU -> host membership list
     *
     * @return void
     */
    public function getHostsList()
    {
        return $this->assocItemsList(
            'host',
            'directorygroupsassociation',
            'directoryOUAssoc',
            '`hosts`.`hostID`',
            '`directoryOUAssoc`.`doaHostID`',
            '`directoryOUAssoc`.`doaOUID`',
            [
                [
                    'db' => 'directoryOUAssoc',
                    'dt' => 'association',
                    'removeFromQuery' => true
                ]
            ]
        );
    }
}
