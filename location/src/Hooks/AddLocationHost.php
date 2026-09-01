<?php
/**
 * Adds the location choice to host.
 *
 * PHP version 5
 *
 * @category AddLocationHost
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @author   Lee Rowlett <nah@nah.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

namespace FOG\Plugins\Location\Hooks;

/**
 * Adds the location choice to host.
 *
 * @category AddLocationHost
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @author   Lee Rowlett <nah@nah.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class AddLocationHost extends \FOG\Base\Hook
{
    /**
     * The name of this hook.
     *
     * @var string
     */
    public $name = 'AddLocationHost';
    /**
     * The description of this hook.
     *
     * @var string
     */
    public $description = 'Add Location to Hosts';
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
            ['PLUGINS_INJECT_TABDATA', 'hostTabData'],
            ['HOST_EDIT_SUCCESS', 'hostAddLocationEdit'],
            ['HOST_MASSEDIT_FIELDS', 'massEditFields'],
            ['HOST_MASSEDIT_APPLY', 'massEditApply'],
            ['HOST_ADD_FIELDS', 'hostAddLocationField'],
            ['HOST_REGISTER', 'hostAddLocationRegister'],
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
            'name' => _('Location Association'),
            'id' => 'host-location',
            'generator' => function () use ($obj) {
                $this->hostLocation($obj);
            }
        ];
    }
    /**
     * The host location display
     *
     * @param object $obj The host object we're working with.
     *
     * @return void
     */
    public function hostLocation($obj)
    {
        $items = \FOG\Router\Route::getList('locationassociation');
        $location = 0;
        foreach ($items as &$item) {
            if ($item->hostID == $obj->get('id')) {
                $location = $item->locationID;
                unset($item);
                break;
            }
            unset($item);
        }
        $locationID = (
            (int)filter_input(INPUT_POST, 'location') ?:
            $location
        );
        $locationSelector = self::getClass('LocationManager')
            ->buildSelectBox($locationID, 'location');

        $fields = [
            \FOG\Base\FOGPage::makeLabel(
                'col-sm-3 col-form-label',
                'location',
                _('Host Location')
            ) => $locationSelector
        ];

        $buttons = \FOG\Base\FOGPage::makeButton(
            'location-send',
            _('Update'),
            'btn btn-primary float-end'
        );
        // Create-and-associate, the same button and modal the core association
        // tabs get. Added before the *_FIELDS event so a listener can still see
        // it, and immediately after Update so Update stays the row's rightmost
        // (primary) button with this one to its left. The modal it returns is
        // echoed after the form -- see below for why it cannot go inside.
        $createModal = \FOG\Base\FOGPage::renderAssocCreate(
            'host-location',
            'location',
            $buttons,
            $obj->get('id')
        );

        self::$HookManager->processEvent(
            'HOST_LOCATION_FIELDS',
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
            'host-location-form',
            \FOG\Base\FOGPage::makeTabUpdateURL(
                'host-location',
                $obj->get('id')
            ),
            'post',
            'application/x-www-form-urlencoded',
            true
        );
        echo '<div class="card">';
        echo '<div class="card-header">';
        echo '<h4 class="card-title">';
        echo _('Location');
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
     * The location updater element.
     *
     * @param object $obj The object we're working with.
     *
     * @return void
     */
    public function hostLocationPost($obj)
    {
        self::checkAuthAndCSRF();
        $locationID = trim(
            (int)filter_input(INPUT_POST, 'location')
        );
        $insert_fields = ['hostID', 'locationID'];
        $insert_values = [];
        $hosts = [$obj->get('id')];
        if (count($hosts ?: [])) {
            \FOG\Router\Route::deletemass(
                'locationassociation',
                ['hostID' => $hosts]
            );
            if (self::getClass('Location', $locationID)->isValid()) {
                foreach ((array)$hosts as $ind => &$hostID) {
                    $insert_values[] = [$hostID, $locationID];
                    unset($hostID);
                }
            }
        }
        if (count($insert_values) > 0) {
            self::getClass('LocationAssociationManager')
                ->insertBatch(
                    $insert_fields,
                    $insert_values
                );
        }
    }
    /**
     * Associates the location posted during host (auto) registration.
     *
     * The Registration class decodes $_POST in place before firing
     * HOST_REGISTER, so the posted 'location' is already a plain id here.
     *
     * @param mixed $arguments The hook arguments (contains Host).
     *
     * @return void
     */
    public function hostAddLocationRegister($arguments)
    {
        $obj = $arguments['Host'];
        if (!$obj->isValid()) {
            return;
        }
        $locationID = (int)($_POST['location'] ?? 0);
        if (!self::getClass('Location', $locationID)->isValid()) {
            return;
        }
        self::getClass('LocationAssociationManager')
            ->insertBatch(
                ['hostID', 'locationID'],
                [[$obj->get('id'), $locationID]]
            );
    }
    /**
     * The host location selector.
     *
     * @param mixed $arguments The arguments to change.
     *
     * @return void
     */
    public function hostAddLocationEdit($arguments)
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
                case 'host-location':
                    $this->hostLocationPost($obj);
                    break;
                default:
                    return;
            }
            $arguments['code'] = \FOG\Router\HTTPResponseCodes::HTTP_ACCEPTED;
            $arguments['hook'] = 'HOST_EDIT_LOCATION_SUCCESS';
            $arguments['msg'] = json_encode(
                [
                    'msg' => _('Host Location Updated!'),
                    'title' => _('Host Location Update Success')
                ]
            );
        } catch (\Exception $e) {
            $arguments['code'] = (
                $arguments['serverFault'] ?
                \FOG\Router\HTTPResponseCodes::HTTP_INTERNAL_SERVER_ERROR :
                \FOG\Router\HTTPResponseCodes::HTTP_BAD_REQUEST
            );
            $arguments['hook'] = 'HOST_EDIT_LOCATION_FAIL';
            $arguments['msg'] = json_encode(
                [
                    'error' => $e->getMessage(),
                    'title' => _('Host Update Location Fail')
                ]
            );
        }
    }
    /**
     * The host location field for function add.
     *
     * @param mixed $arguments The arguments to change.
     *
     * @return void
     */
    public function hostAddLocationField($arguments)
    {
        global $node;
        if ($node != 'host') {
            return;
        }
        $locationID = (int)filter_input(INPUT_POST, 'location');
        $locationSelector = self::getClass('LocationManager')
            ->buildSelectBox($locationID, 'location');

        $arguments['fields'][
            \FOG\Base\FOGPage::makeLabel(
                'col-sm-3 col-form-label',
                'location',
                _('Host Location')
            )
        ] = $locationSelector;
    }
    /**
     * Contributes the location to the host list's Mass Edit form.
     *
     * ADR 0038 decision 13. This replaces AddLocationGroup, whose only job was
     * to set one location across a group's members. That hook was a copy, not a
     * grant: it read the membership at the instant the button was pressed and
     * wrote a row per member, so a host added to the group afterward got
     * nothing and a host removed kept what it had. Worse, it had no way to
     * express "leave this host alone" -- every save deleted the association
     * for every member first, so setting one host's location through the group
     * wiped it from all of them.
     *
     * A location is single-valued per host: the write path has always been
     * delete-then-insert-one. That is why this is a mass edit rather than one
     * of ADR 0038's grant tables -- a grant is a SET that several groups can
     * union into, and unioning two locations means nothing.
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

        $arguments['fields']['location'] = [
            'label' => _('Host Location'),
            'input' => self::getClass('LocationManager')->buildSelectBox(
                '',
                'value[location]',
                'name',
                '',
                false,
                'id',
                'massedit-location'
            ),
            'hint' => \FOG\Util\SharedHostValues::hint(
                $this->_sharedLocation($hostIDs)
            ),
        ];
    }
    /**
     * What the selected hosts hold, as a name rather than an id.
     *
     * SharedHostValues::forHostRows() answers in the column's own terms, so a
     * uniform selection comes back as the location id. Rendering that would put
     * a bare number in front of the admin where every other hint shows the
     * value they set. The lookup only happens when the answer is uniform and
     * non-empty, which is the only case where there is one name to show.
     *
     * forHostRows() is also the reason this is not a hand-rolled query: a
     * host with no location has no row at all, so counting rows would call
     * three hosts out of five "in agreement". It compares the row count to
     * the selection size for exactly that reason.
     *
     * @param array $hostIDs the selection
     *
     * @return array a SharedHostValues info array
     */
    private function _sharedLocation(array $hostIDs)
    {
        $info = \FOG\Util\SharedHostValues::forHostRows(
            $hostIDs,
            'locationAssoc',
            'laHostID',
            ['location' => 'laLocationID']
        )['location'];

        if (!empty($info['uniform']) && '' !== (string)$info['value']) {
            $item = self::getClass('Location', (int)$info['value']);
            if ($item->isValid()) {
                $info['value'] = $item->get('name');
            }
        }

        return $info;
    }
    /**
     * Applies a resolved location action across the selection.
     *
     * The action arrives already reduced to leave/set/clear, so there is no
     * sentinel to parse and no way for an empty control to be mistaken for
     * "clear it" -- which is the failure the three-state model exists to stop
     * and the one AddLocationGroup could not avoid.
     *
     * @param mixed $arguments The arguments to change.
     *
     * @return void
     */
    public function massEditApply($arguments)
    {
        if (!isset($arguments['actions']['location'])) {
            return;
        }
        $instruction = $arguments['actions']['location'];
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
            // A set naming a location that does not exist is a bad request, not
            // an instruction to clear: silently turning it into one would
            // strip the location off every selected host.
            if ($itemID < 1
                || !self::getClass('Location', $itemID)->isValid()
            ) {
                throw new \Exception(_('Invalid Location selected'));
            }
        }

        // Both branches clear first. The association is single-valued per
        // host, so a set is a replace -- and doing it in one deletemass
        // rather than per host keeps it to one statement.
        \FOG\Router\Route::deletemass(
            'locationassociation',
            ['hostID' => $hostIDs]
        );
        if ($itemID < 1) {
            return;
        }
        $insert_values = [];
        foreach ($hostIDs as $hostID) {
            $insert_values[] = [$hostID, $itemID];
        }
        self::getClass('LocationAssociationManager')
            ->insertBatch(['hostID', 'locationID'], $insert_values);
    }
}
