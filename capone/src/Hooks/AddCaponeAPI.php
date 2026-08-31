<?php
/**
 * Injects capone stuff into the api system.
 *
 * PHP version 5
 *
 * @category AddCaponeAPI
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

namespace FOG\Plugins\Capone\Hooks;

/**
 * Injects capone stuff into the api system.
 *
 * @category AddCaponeAPI
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class AddCaponeAPI extends \FOG\Base\Hook
{
    /**
     * Name of the hook.
     *
     * @var string
     */
    public $name = 'AddCaponeAPI';
    /**
     * Description of the hook.
     *
     * @var string
     */
    public $description = 'Add Capone stuff into the api system.';
    /**
     * For posterity
     *
     * @var bool
     */
    public $active = true;
    /**
     * The node this plugin works with.
     *
     * @var string
     */
    public $node = 'capone';
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
            ['CUSTOMIZE_DT_COLUMNS', 'customizeDT'],
        ]);
    }
    /**
     * Declares capone.key as NOT a credential.
     *
     * It matches Redaction::CREDENTIAL_PATTERN on the bare word "key" and is
     * nothing of the sort: the edit form calls it "Key to match", and it is
     * the DMI string capone compares against to pick an image. The
     * unauthenticated capone endpoint posts it in the clear on every lookup,
     * so treating it as secret would protect a value the protocol publishes
     * anyway -- while blanking the one column that says which rule an audit
     * row changed.
     *
     * The 'exempt' bucket exists so a plugin can make this call about its own
     * model. Core must not: the bundled plugins are a fetched artifact (ADR
     * 0009), and a core entry naming a plugin class fails on any tree that
     * has not fetched them, which includes a fresh clone and CI.
     *
     * @param mixed $arguments The tier maps to modify.
     *
     * @return void
     */
    public function declareSensitiveFields($arguments)
    {
        $arguments['exempt'][$this->node][] = 'key';
    }
    /**
     * Customize our new columns.
     *
     * @param mixed $arguments The arguments to modify.
     *
     * @return void
     */
    public function customizeDT($arguments)
    {
        if ($arguments['classname'] != $this->node) {
            return;
        }
        $arguments['columns'] = [];
        foreach (self::getClass('CaponeManager')
            ->getColumns() as $common => &$real
        ) {
            switch ($common) {
                case 'id':
                    $arguments['columns'][] = [
                        'db' => $real,
                        'dt' => $common
                    ];
                    $arguments['columns'][] = [
                        'db' => $real,
                        'dt' => 'mainlink',
                        'formatter' => function ($d, $row) {
                            return '<a href="../management/index.php?node='
                                . 'capone&sub=edit&id='
                                . $row['cID']
                                . '">'
                                . _('Edit Capone ID')
                                . ': '
                                . $row['cID']
                                . '</a>';
                        }
                    ];
                    break;
                case 'imageID':
                    $arguments['columns'][] = [
                        'db' => $real,
                        'dt' => $common
                    ];
                    $arguments['columns'][] = [
                        'db' => $real,
                        'dt' => 'imageLink',
                        'formatter' => function ($d, $row) {
                            if (!$d) {
                                return;
                            }
                            return '<a href="../management/index.php?node=image&'
                                . 'sub=edit&id='
                                . $d
                                . '">'
                                . self::getClass('Image', $d)->get('name')
                                . '</a>';
                        }
                    ];
                    break;
                default:
                    $arguments['columns'][] = [
                        'db' => $real,
                        'dt' =>$common
                    ];
            }
            unset($real);
        }
        foreach (self::getClass('OSManager')
            ->getColumns() as $common => &$real
        ) {
            $arguments['columns'][] = [
                'db' => $real,
                'dt' => 'os' . $common
            ];
            unset($real);
        }
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
        $arguments['validClasses'][] = $this->node;
    }
}
