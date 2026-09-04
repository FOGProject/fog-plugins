<?php
/**
 * Injects slack stuff into the api system.
 *
 * PHP version 5
 *
 * @category AddSlackAPI
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

namespace FOG\Plugins\Slack\Hooks;

/**
 * Injects slack stuff into the api system.
 *
 * @category AddSlackAPI
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class AddSlackAPI extends \FOG\Base\Hook
{
    /**
     * The name of the hook.
     *
     * @var string
     */
    public $name = 'AddSlackAPI';
    /**
     * The description.
     *
     * @var string
     */
    public $description = 'Add Slack stuff into the api system.';
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
    public $node = 'slack';
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
     * Declares the webhook token as a secret the API must never emit.
     *
     * injectAPIElements() below puts this class in $validClasses, so until
     * now every slack row the API returned carried the token in clear to any
     * caller holding slack.view. Holding it is being able to post into that
     * workspace as FOG.
     *
     * The 'always' tier rather than the ordinary one, for the same reason
     * ldap.bindPwd is there: nothing reads it back. Only the web tier sends
     * it, to Slack, and it does so through the model.
     *
     * The audit trail reads this registry too (ADR 0021 Decision 6), so this
     * is also what keeps the old value out of an auditChange row when
     * somebody rotates the token.
     *
     * @param mixed $arguments The tier maps to modify.
     *
     * @return void
     */
    public function declareSensitiveFields($arguments)
    {
        $arguments['always'][$this->node][] = 'token';
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
        foreach ((new \FOG\Plugins\Slack\Managers\SlackManager())
            ->getColumns() as $common => $real
        ) {
            switch ($common) {
                case 'id':
                    $arguments['columns'][] = [
                        'db' => $real,
                        'dt' => 'DT_RowId',
                        'formatter' => function ($d, $row) {
                            return $d;
                        }
                    ];
                    $arguments['columns'][] = [
                        'db' => $real,
                        'dt' => $common,
                        'formatter' => function ($d, $row) {
                            $team = (new \FOG\Plugins\Slack\Items\Slack($d))->call('auth.test');
                            return $team['team'];
                        }
                    ];
                    break;
                case 'token':
                    $arguments['columns'][] = [
                        'db' => $real,
                        'dt' => $common,
                        'formatter' => function ($d, $row) {
                            $team = (new \FOG\Plugins\Slack\Items\Slack($row['sID']))->call('auth.test');
                            return $team['user'];
                            ;
                        }
                    ];
                    break;
                default:
                    $arguments['columns'][] = [
                        'db' => $real,
                        'dt' => $common,
                    ];
            }
        }
    }
    /**
     * This function injects slack elements for
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
