<?php
/**
 * Adds the ou choice to host.
 *
 * PHP version 5
 *
 * @category AddDirectoryGroupsHost
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @author   Lee Rowlett <nah@nah.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

namespace FOG\Plugins\DirectoryGroups\Hooks;

/**
 * Adds the ou choice to host.
 *
 * @category AddDirectoryGroupsHost
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @author   Lee Rowlett <nah@nah.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class AddDirectoryGroupsHost extends \FOG\Base\Hook
{
    /**
     * The name of this hook.
     *
     * @var string
     */
    public $name = 'AddDirectoryGroupsHost';
    /**
     * The description of this hook.
     *
     * @var string
     */
    public $description = 'Add OU to Hosts';
    /**
     * The active flag (always true but for posterity)
     *
     * @var bool
     */
    public $active = true;
    /**
     * THe node this hook enacts with.
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
            ['PLUGINS_INJECT_TABDATA', 'hostTabData'],
            ['HOST_EDIT_SUCCESS', 'hostAddDirectoryGroupsEdit'],
            ['HOST_MASSEDIT_FIELDS', 'massEditFields'],
            ['HOST_MASSEDIT_APPLY', 'massEditApply'],
            ['HOST_ADD_FIELDS', 'hostAddDirectoryGroupsField'],
            ['HOST_REGISTER', 'hostAddDirectoryGroupsRegister'],
        ]);
    }
    /**
     * The host tab data.
     *
     * @param mixed $arguments The arguments to change.
     *
     * @return void
     */
    public function hostTabData($arguments)
    {
        global $node;
        if ($node != 'host') {
            return;
        }
        $obj = $arguments['obj'];

        $arguments['pluginsTabData'][] = [
            'name' => _('Directory Group'),
            'id' => 'host-directorygroups',
            'generator' => function () use ($obj) {
                $this->hostDirectoryGroups($obj);
            }
        ];
    }
    /**
     * The host ou display
     *
     * @param object $obj The host object we're working with.
     *
     * @return void
     */
    public function hostDirectoryGroups($obj)
    {
        $ou = self::currentDirectoryGroup($obj->get('id'));
        $directorygroupsID = (
            (int)filter_input(INPUT_POST, 'directorygroups') ?:
            $ou
        );
        $ouSelector = (new \FOG\Plugins\DirectoryGroups\Managers\DirectoryGroupsManager())
            ->buildSelectBox($directorygroupsID, 'directorygroups');

        $fields = [
            \FOG\Base\FOGPage::makeLabel(
                'col-sm-3 col-form-label',
                'directorygroups',
                _('Host OU')
            ) => $ouSelector
        ];

        $buttons = \FOG\Base\FOGPage::makeButton(
            'directorygroups-send',
            _('Update'),
            'btn btn-primary float-end'
        );
        // Create-and-associate, the same button and modal the core association
        // tabs get. Added before the *_FIELDS event so a listener can still see
        // it, and immediately after Update so Update stays the row's rightmost
        // (primary) button with this one to its left. The modal it returns is
        // echoed after the form -- see below for why it cannot go inside.
        $createModal = \FOG\Base\FOGPage::renderAssocCreate(
            'host-directorygroups',
            'directorygroups',
            $buttons,
            $obj->get('id'),
            _('OU')
        );

        self::$HookManager->processEvent(
            'HOST_DIRECTORYGROUPS_FIELDS',
            [
                'fields' => &$fields,
                'buttons' => &$buttons,
                'Host' => &$obj
            ]
        );
        $rendered = \FOG\Base\FOGPage::formFields($fields);
        unset($fields);

        echo \FOG\Base\FOGPage::makeFormTag(
            '',
            'host-directorygroups-form',
            \FOG\Base\FOGPage::makeTabUpdateURL(
                'host-directorygroups',
                $obj->get('id')
            ),
            'post',
            'application/x-www-form-urlencoded',
            true
        );
        echo '<div class="card">';
        echo '<div class="card-header">';
        echo '<h4 class="card-title">';
        echo _('OU');
        echo '</h4>';
        echo '</div>';
        echo '<div class="card-body">';
        echo $rendered;
        echo '</div>';
        echo '<div class="card-footer">';
        echo $buttons;
        echo '</div>';
        echo '</div>';
        echo '</form>';
        // Outside the form, deliberately. The modal holds the fetched create
        // form, and a <form> inside another <form> is invalid markup: the
        // browser drops the inner one and the create would post nothing.
        echo $createModal;
    }
    /**
     * The ou updater element.
     *
     * @param object $obj The object we're working with.
     *
     * @return void
     */
    public function hostDirectoryGroupsPost($obj)
    {
        self::checkAuthAndCSRF();
        self::setDirectoryGroup(
            [$obj->get('id')],
            (int)filter_input(INPUT_POST, 'directorygroups')
        );
    }
    /**
     * Associates the OU posted during host (auto) registration.
     *
     * The Registration class decodes $_POST in place before firing
     * HOST_REGISTER, so the posted 'directorygroups' is already a plain id here.
     *
     * @param mixed $arguments The hook arguments (contains Host).
     *
     * @return void
     */
    public function hostAddDirectoryGroupsRegister($arguments)
    {
        $obj = $arguments['Host'];
        if (!$obj->isValid()) {
            return;
        }
        $directorygroupsID = (int)($_POST['directorygroups'] ?? 0);
        if ($directorygroupsID < 1) {
            return;
        }
        self::setDirectoryGroup([$obj->get('id')], $directorygroupsID);
    }
    /**
     * The host ou selector.
     *
     * @param mixed $arguments The arguments to change.
     *
     * @return void
     */
    public function hostAddDirectoryGroupsEdit($arguments)
    {
        self::checkAuthAndCSRF();
        global $tab;
        global $node;
        if ($node != 'host') {
            return;
        }
        $obj = $arguments['Host'];
        try {
            switch ($tab) {
                case 'host-directorygroups':
                    $this->hostDirectoryGroupsPost($obj);
                    break;
                default:
                    return;
            }
            $arguments['code'] = \FOG\Router\HTTPResponseCodes::HTTP_ACCEPTED;
            $arguments['hook'] = 'HOST_EDIT_DIRECTORYGROUPS_SUCCESS';
            $arguments['msg'] = json_encode(
                [
                    'msg' => _('Host OU Updated!'),
                    'title' => _('Host OU Update Success')
                ]
            );
        } catch (\Exception $e) {
            $arguments['code'] = (
                $arguments['serverFault'] ?
                \FOG\Router\HTTPResponseCodes::HTTP_INTERNAL_SERVER_ERROR :
                \FOG\Router\HTTPResponseCodes::HTTP_BAD_REQUEST
            );
            $arguments['hook'] = 'HOST_EDIT_DIRECTORYGROUPS_FAIL';
            $arguments['msg'] = json_encode(
                [
                    'error' => $e->getMessage(),
                    'title' => _('Host Update OU Fail')
                ]
            );
        }
    }
    /**
     * The host ou field for function add.
     *
     * @param mixed $arguments The arguments to change.
     *
     * @return void
     */
    public function hostAddDirectoryGroupsField($arguments)
    {
        global $node;
        if ($node != 'host') {
            return;
        }
        $directorygroupsID = (int)filter_input(INPUT_POST, 'directorygroups');
        $ouSelector = (new \FOG\Plugins\DirectoryGroups\Managers\DirectoryGroupsManager())
            ->buildSelectBox($directorygroupsID, 'directorygroups');

        $arguments['fields'][
            \FOG\Base\FOGPage::makeLabel(
                'col-sm-3 col-form-label',
                'directorygroups',
                _('Host OU')
            )
        ] = $ouSelector;
    }
    /**
     * Contributes the ou to the host list's Mass Edit form.
     *
     * ADR 0038 decision 13. This replaces AddOUGroup, whose only job was
     * to set one ou across a group's members. That hook was a copy, not a
     * grant: it read the membership at the instant the button was pressed and
     * wrote a row per member, so a host added to the group afterward got
     * nothing and a host removed kept what it had. Worse, it had no way to
     * express "leave this host alone" -- every save deleted the association
     * for every member first, so setting one host's ou through the group
     * wiped it from all of them.
     *
     * A ou is single-valued per host: the write path has always been
     * delete-then-insert-one. That is why this is a mass edit rather than one
     * of ADR 0038's grant tables -- a grant is a SET that several groups can
     * union into, and unioning two ous means nothing.
     *
     * Core draws the three-state action control around this field, which is
     * what makes "leave alone" expressible at all. The value control is
     * rendered EMPTY on purpose: there is no honest value to pre-fill it with
     * when the selection disagrees. What the hosts hold is stated in the hint
     * instead, where "(varies)" is sayable.
     *
     * @param mixed $arguments The arguments to change.
     *
     * @return void
     */
    public function massEditFields($arguments)
    {
        $hostIDs = (array)$arguments['hostIDs'];

        $arguments['fields']['directorygroups'] = [
            'label' => _('Host OU'),
            'input' => (new \FOG\Plugins\DirectoryGroups\Managers\DirectoryGroupsManager())->buildSelectBox(
                '',
                'value[directorygroups]',
                'name',
                '',
                false,
                'id',
                'massedit-directorygroups'
            ),
            'hint' => \FOG\Util\SharedHostValues::hint(
                $this->_sharedDirectoryGroups($hostIDs)
            ),
        ];
    }
    /**
     * What the selected hosts hold, as a name rather than an id.
     *
     * _sharedMembership() answers with the directory group id. Rendering
     * that would put a bare number in front of the admin where every other
     * hint shows the value they set. The lookup only happens when the answer
     * is uniform and non-empty, which is the only case where there is one
     * name to show.
     *
     * @param array $hostIDs the selection
     *
     * @return array a SharedHostValues info array
     */
    private function _sharedDirectoryGroups(array $hostIDs)
    {
        $info = self::_sharedMembership($hostIDs);

        if (!empty($info['uniform']) && '' !== (string)$info['value']) {
            $item = new \FOG\Plugins\DirectoryGroups\Items\DirectoryGroups((int)$info['value']);
            if ($item->isValid()) {
                $info['value'] = $item->get('name');
            }
        }

        return $info;
    }
    /**
     * forHostRows() over directory group membership.
     *
     * Membership is a join, not a table forHostRows() can read, so this asks
     * the same two questions itself: does every selected host have a
     * directory group, and is it the same one. A host with none makes the
     * selection "(varies)", as a host with no row does there.
     *
     * @param array $hostIDs the selection
     *
     * @return array ['uniform' => bool, 'value' => string]
     */
    private function _sharedMembership(array $hostIDs)
    {
        $hostIDs = array_values(array_unique(array_filter(array_map('intval', $hostIDs))));
        if (count($hostIDs) < 1) {
            return ['uniform' => false, 'value' => ''];
        }
        $row = self::$DB->query(
            'SELECT COUNT(DISTINCT `gm`.`gmHostID`) AS `n`,'
            . ' COUNT(DISTINCT `d`.`doID`) AS `d`, MIN(`d`.`doID`) AS `v`'
            . ' FROM `groupMembers` `gm`'
            . ' JOIN `directoryOUs` `d` ON `d`.`doGroupID` = `gm`.`gmGroupID`'
            . ' WHERE `gm`.`gmHostID` IN (' . implode(',', $hostIDs) . ')'
        )->fetch();
        $n = (int)$row->get('n');
        return [
            'uniform' => $n === count($hostIDs) && 1 === (int)$row->get('d'),
            'value' => (string)$row->get('v')
        ];
    }
    /**
     * Applies a resolved ou action across the selection.
     *
     * The action arrives already reduced to leave/set/clear, so there is no
     * sentinel to parse and no way for an empty control to be mistaken for
     * "clear it" -- which is the failure the three-state model exists to stop
     * and the one AddOUGroup could not avoid.
     *
     * @param mixed $arguments The arguments to change.
     *
     * @return void
     */
    public function massEditApply($arguments)
    {
        if (!isset($arguments['actions']['directorygroups'])) {
            return;
        }
        $instruction = $arguments['actions']['directorygroups'];
        $action = $instruction['action'];
        if ('leave' === $action) {
            return;
        }
        $hostIDs = array_values(
            array_filter(
                array_map('intval', (array)$arguments['hostIDs']),
                function ($id) {
                    return $id > 0;
                }
            )
        );
        if (count($hostIDs) < 1) {
            return;
        }

        $itemID = 0;
        if ('set' === $action) {
            $itemID = (int)$instruction['value'];
            // A set naming a ou that does not exist is a bad request, not
            // an instruction to clear: silently turning it into one would
            // strip the ou off every selected host.
            if ($itemID < 1
                || !(new \FOG\Plugins\DirectoryGroups\Items\DirectoryGroups($itemID))->isValid()
            ) {
                throw new \Exception(_('Invalid OU selected'));
            }
        }

        self::setDirectoryGroup($hostIDs, $itemID);
    }
    /**
     * The directory group a host is in, or 0.
     *
     * @param int $hostID the host
     *
     * @return int the directory group id
     */
    public static function currentDirectoryGroup($hostID)
    {
        $row = self::$DB->query(
            'SELECT `d`.`doID` AS `id` FROM `directoryOUs` `d`'
            . ' JOIN `groupMembers` `gm` ON `gm`.`gmGroupID` = `d`.`doGroupID`'
            . ' WHERE `gm`.`gmHostID` = :host ORDER BY `d`.`doID` LIMIT 1',
            [],
            [':host' => (int)$hostID]
        )->fetch()->get('id');
        return (int)$row;
    }
    /**
     * Puts hosts in one directory group, or takes them out of all of them.
     *
     * A join goes through the directory group's core group, so core fires
     * ASSOCIATION_UPDATED and AddDirectoryGroupsMembership takes each host
     * out of its old directory group and writes the new OU onto it.
     *
     * @param array $hostIDs           the hosts
     * @param int   $directorygroupsID the directory group, or 0 for none
     *
     * @throws \Exception when the directory group does not exist
     *
     * @return void
     */
    public static function setDirectoryGroup(array $hostIDs, $directorygroupsID)
    {
        $hostIDs = array_values(array_filter(array_map('intval', $hostIDs)));
        if (count($hostIDs) < 1) {
            return;
        }
        if ($directorygroupsID > 0) {
            $item = new \FOG\Plugins\DirectoryGroups\Items\DirectoryGroups($directorygroupsID);
            $Group = new \FOG\Items\Group((int)$item->get('groupID'));
            if (!$item->isValid() || !$Group->isValid()) {
                throw new \Exception(_('Invalid OU selected'));
            }
            $Group->addHost($hostIDs)->save();
            return;
        }
        $rows = self::$DB->query(
            'SELECT DISTINCT `gm`.`gmGroupID` AS `g` FROM `groupMembers` `gm`'
            . ' JOIN `directoryOUs` `d` ON `d`.`doGroupID` = `gm`.`gmGroupID`'
            . ' WHERE `gm`.`gmHostID` IN (' . implode(',', $hostIDs) . ')'
        )->fetch(\PDO::FETCH_ASSOC, 'fetch_all')->get();
        foreach ((array)$rows as $row) {
            (new \FOG\Items\Group((int)$row['g']))->removeHost($hostIDs)->save();
        }
    }
}
