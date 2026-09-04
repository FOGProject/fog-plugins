<?php
/**
 * The event to call when Images are complete
 *
 * @category ImageComplete_Slack
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

namespace FOG\Plugins\Slack\Events;

class ImageComplete_Slack extends \FOG\Base\Event
{
    /**
     * The name of this event
     *
     * @var string
     */
    public $name = 'ImageComplete_Slack';
    /**
     * The description of this event
     *
     * @var string
     */
    public $description = 'Triggers when a host finishes imaging';
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
            'HOST_IMAGE_COMPLETE',
            $this
        )->register(
            'HOST_IMAGEUP_COMPLETE',
            $this
        );
    }
    /**
     * Perform action
     *
     * One listener, two names: HOST_IMAGE_COMPLETE is a deploy finishing and
     * HOST_IMAGEUP_COMPLETE is a capture. Core never fired the capture name
     * at all until fogproject#1202, so both outcomes arrived as a deploy and
     * the message could not tell them apart. The payload now also carries the
     * image, so the notification can say which one.
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
        $format = (
            'HOST_IMAGEUP_COMPLETE' === $event ?
            _('Host %1$s finished capturing image %2$s.') :
            _('Host %1$s finished deploying image %2$s.')
        );
        $Slacks = \FOG\Router\Route::getList('slack');
        foreach ($Slacks as $Slack) {
            $args = [
                'channel' => $Slack->name,
                'text' => sprintf(
                    $format,
                    $data['HostName'],
                    $image
                )
            ];
            (new \FOG\Plugins\Slack\Items\Slack($Slack->id))->call('chat.postMessage', $args);
        }
    }
}
