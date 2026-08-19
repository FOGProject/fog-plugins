<?php
/**
 * Pushes notification on imaging failure.
 *
 * PHP version 5
 *
 * @category ImageFail_PushBullet
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
/**
 * Pushes notification on imaging failure.
 *
 * @category ImageFail_PushBullet
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class ImageFail_PushBullet extends PushbulletExtends
{
    /**
     * The name of the event.
     *
     * @var string
     */
    protected $name = 'ImageFail_PushBullet';
    /**
     * The description of the event.
     *
     * @var string
     */
    protected $description = 'Triggers when a host fails imaging';
    /**
     * Active flag.
     *
     * @var bool
     */
    public $active = true;
    /**
     * Initialize object
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        self::$EventManager
            ->register(
                'HOST_IMAGE_FAIL',
                $this
            );
    }
    /**
     * Perform action when event met.
     *
     * @param string $event The event to perform from.
     * @param mixed  $data  The data to send.
     *
     * @return void
     */
    public function onEvent($event, $data)
    {
        // HOST_IMAGE_FAIL had no core caller at all until fogproject#1202, so
        // this listener has never run on any server. The reason is the part an
        // admin can act on, so say it rather than "failed to image". Read
        // defensively: this plugin has to keep working against a server that
        // has not taken #1202 yet.
        $image = (string) ($data['ImageName'] ?? '');
        if ('' === $image) {
            $image = _('an unnamed image');
        }
        $reason = (string) ($data['Reason'] ?? '');
        if ('' === $reason) {
            $reason = _('no reason was reported');
        }
        self::$shortdesc = _('Imaging Failed');
        self::$message = sprintf(
            _('This host failed imaging %1$s: %2$s'),
            $image,
            $reason
        );
        parent::onEvent($event, $data);
    }
}
