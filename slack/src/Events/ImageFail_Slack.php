<?php
/**
 * The event to call when imaging task fails
 *
 * @category ImageFail_Slack
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

namespace FOG\Plugins\Slack\Events;

class ImageFail_Slack extends \FOG\Base\Event
{
    /**
     * The name of this event
     *
     * @var string
     */
    public $name = 'ImageFail_Slack';
    /**
     * The description of this event
     *
     * @var string
     */
    public $description = 'Triggers when a host fails imaging';
    /**
     * The event is active
     *
     * @var bool
     */
    public $active = true;
    /**
     * Initialize object.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        self::$EventManager->register(
            'HOST_IMAGE_FAIL',
            $this
        );
    }
    /**
     * Perform action
     *
     * HOST_IMAGE_FAIL had no core caller at all until fogproject#1202, so this
     * listener has never run on any server. The payload carries the image and
     * the reason FOG rejected the task, which is the part an admin can act on.
     *
     * Every added key is read defensively: this plugin has to keep working
     * against a server that has not taken that change yet.
     *
     * @param string $event the event to enact
     * @param mixed  $data  the data
     *
     * @return void
     */
    public function onEvent($event, $data)
    {
        $image = (string) ($data['ImageName'] ?? '');
        if ('' === $image) {
            $image = _('an unnamed image');
        }
        $reason = (string) ($data['Reason'] ?? '');
        if ('' === $reason) {
            $reason = _('no reason was reported');
        }
        $Slacks = \FOG\Router\Route::getList('slack');
        foreach ($Slacks as $Slack) {
            $args = [
                'channel' => $Slack->name,
                'text' => sprintf(
                    _('Host %1$s failed imaging %2$s: %3$s'),
                    $data['HostName'],
                    $image,
                    $reason
                )
            ];
            self::getClass('Slack', $Slack->id)->call('chat.postMessage', $args);
        }
    }
}
