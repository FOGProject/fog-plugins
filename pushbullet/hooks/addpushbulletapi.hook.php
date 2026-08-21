<?php
/**
 * Injects pushbullet stuff into the api system.
 *
 * PHP version 5
 *
 * @category AddPushbulletAPI
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
/**
 * Injects pushbullet stuff into the api system.
 *
 * @category AddPushbulletAPI
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class AddPushbulletAPI extends Hook
{
    /**
     * The name of the hook.
     *
     * @var string
     */
    public $name = 'AddPushbulletAPI';
    /**
     * The description.
     *
     * @var string
     */
    public $description = 'Add Pushbullet stuff into the api system.';
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
    public $node = 'pushbullet';
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
        ]);
    }
    /**
     * Declares the access token as a secret the API must never emit.
     *
     * injectAPIElements() below puts this class in $validClasses, so until
     * now every pushbullet row the API returned carried the token in clear
     * to any caller holding pushbullet.view. The token is the whole
     * credential: it posts as that Pushbullet account.
     *
     * The 'always' tier rather than the ordinary one, for the same reason
     * ldap.bindPwd is there: nothing reads it back. Only the web tier sends
     * it, to Pushbullet's API, and it does so through the model.
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
     * This function injects pushbullet elements for
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
