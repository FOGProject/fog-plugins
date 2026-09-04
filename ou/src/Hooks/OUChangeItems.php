<?php
/**
 * Changes the elements we need.
 *
 * PHP version 5
 *
 * @category ChangeItems
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @author   Lee Rowlett <nah@nah.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

namespace FOG\Plugins\OU\Hooks;

/**
 * Changes the elements we need.
 *
 * @category ChangeItems
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @author   Lee Rowlett <nah@nah.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
/**
 * Named for its plugin -- see LocationChangeItems, which shared this hook's
 * old changeitems.hook.php filename and therefore its class name. Only one of
 * the two ever loaded per request, decided by readdir order. Namespacing
 * closed that off structurally (fogproject ADR 0035); the distinct names stay
 * because they are what the log shows.
 */
class OUChangeItems extends \FOG\Base\Hook
{
    /**
     * The name of this hook.
     *
     * @var string
     */
    public $name = 'OUChangeItems';
    /**
     * The description of this hook.
     *
     * @var string
     */
    public $description = 'Add OU During client checkin';
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
    public $node = 'ou';
    /**
     * Initialize object.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->registerInstalled([
            ['HOSTNAME_CHANGER_CLIENT', 'changeADItems'],
        ]);
    }
    /**
     * Sets up host for the new OU
     *
     * @param mixed $arguments The items to change.
     *
     * @return void
     */
    public function changeADItems($arguments)
    {
        if (!$arguments['Host']->isValid()) {
            return;
        }
        $OUAssocs = \FOG\Router\Route::getList(
            'ouassociation',
            ['hostID' => $arguments['Host']->get('id')]
        );
        // listem() returns the paginated envelope, so the rows are under
        // ->data. Iterating the envelope itself walked its scalar counters
        // instead: every client check-in warned "Attempt to read property
        // ouID on int" and ADOU was never set. LocationChangeItems, which is
        // the same shape, has always had this right.
        foreach ($OUAssocs as $OUAssoc) {
            // getClass() rather than Route::indiv(): indiv() answers a missing
            // row with sendResponse(404), and that exits. An ouassociation
            // left behind by a deleted OU would end the whole check-in with a
            // 404 the client reads as a transport failure, rather than skip
            // one association.
            $OU = new \FOG\Plugins\OU\Items\OU($OUAssoc->ouID);
            if (!$OU->isValid()) {
                continue;
            }
            $arguments['val']['ADOU'] = $OU->get('ou');
        }
    }
}
