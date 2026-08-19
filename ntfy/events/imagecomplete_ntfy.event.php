<?php
/**
 * Pushes notification on image completion.
 *
 * PHP version 5
 *
 * @category ImageComplete_Ntfy
 * @package  FOGProject
 * @author   Tony Lam <tonylam5349@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
/**
 * Pushes notification on image completion.
 *
 * @category ImageComplete_Ntfy
 * @package  FOGProject
 * @author   Tony Lam <tonylam5349@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class ImageComplete_Ntfy extends NtfyExtends
{
    /**
     * Name of this event.
     *
     * @var string
     */
    protected $name = 'ImageComplete_Ntfy';
    /**
     * Description of this event.
     *
     * @var string
     */
    protected $description = 'Triggers when a host finishes imaging';
    /**
     * Active flag.
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
        self::$EventManager
            ->register(
                'HOST_IMAGE_COMPLETE',
                $this
            )
            ->register(
                'HOST_IMAGEUP_COMPLETE',
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
        // One listener, two names: HOST_IMAGE_COMPLETE is a deploy finishing
        // and HOST_IMAGEUP_COMPLETE is a capture. Core never fired the capture
        // name at all until fogproject#1202, so both outcomes arrived as a
        // deploy and this message could not tell them apart.
        //
        // Composed here rather than left as a bare literal for the parent to
        // translate, because the image name has to be substituted OUTSIDE _().
        // The parent's _() then finds no entry and passes the finished string
        // through, which is what we want. Read defensively: this plugin has to
        // keep working against a server that has not taken #1202 yet.
        $image = (string) ($data['ImageName'] ?? '');
        if ('' === $image) {
            $image = _('an unnamed image');
        }
        if ('HOST_IMAGEUP_COMPLETE' === $event) {
            self::$shortdesc = _('Capture Complete');
            self::$message = sprintf(
                _('This host has finished capturing image %s.'),
                $image
            );
        } else {
            self::$shortdesc = _('Deploy Complete');
            self::$message = sprintf(
                _('This host has finished deploying image %s.'),
                $image
            );
        }
        parent::onEvent($event, $data);
    }
}
