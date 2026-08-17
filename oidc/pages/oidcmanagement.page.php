<?php
/**
 * OpenID Connect provider management page.
 *
 * PHP version 7.4+
 *
 * @category OIDCManagement
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
/**
 * OpenID Connect provider management page.
 *
 * @category OIDCManagement
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class OIDCManagement extends FOGPage
{
    /**
     * Placeholder shown in the secret field of an existing provider.
     *
     * The stored secret is never rendered back into the form. Posting this
     * value means "leave it alone", which is what an admin editing the group
     * claim expects to happen to a credential they did not touch.
     *
     * @var string
     */
    const SECRET_UNCHANGED = '********';
    /**
     * The node this page operates on.
     *
     * @var string
     */
    public $node = 'oidc';
    /**
     * Initialize the page and define the list table columns.
     *
     * @param string $name The page name.
     *
     * @return void
     */
    public function __construct($name = '')
    {
        $this->name = _('OpenID Connect Providers');
        parent::__construct($this->name);
        $this->headerData = [
            _('Name'),
            _('Issuer'),
            _('Enabled')
        ];
        $this->attributes = [
            [],
            [],
            []
        ];
    }
    /**
     * The label column class every field on this page uses.
     *
     * @var string
     */
    private $_labelClass = 'col-sm-3 col-form-label';
    /**
     * Builds the create-form fields (shared by add() and addModal()).
     *
     * @return array
     */
    protected function _addFields()
    {
        $name = filter_input(INPUT_POST, 'name');
        $description = filter_input(INPUT_POST, 'description');
        $issuer = filter_input(INPUT_POST, 'issuer');
        $clientId = filter_input(INPUT_POST, 'clientId');
        $scopes = filter_input(INPUT_POST, 'scopes');
        $userClaim = filter_input(INPUT_POST, 'userClaim');
        $groupClaim = filter_input(INPUT_POST, 'groupClaim');
        $icon = filter_input(INPUT_POST, 'icon');

        return [
            self::makeLabel($this->_labelClass, 'name', _('Name'))
            => self::makeInput(
                'form-control oidcname-input',
                'name',
                _('Name'),
                'text',
                'name',
                $name,
                true
            ),
            self::makeLabel(
                $this->_labelClass,
                'description',
                _('Description')
            ) => self::makeTextarea(
                'form-control oidcdescription-input',
                'description',
                _('Description'),
                'description',
                $description
            ),
            self::makeLabel($this->_labelClass, 'issuer', _('Issuer URL'))
            => self::makeInput(
                'form-control oidcissuer-input',
                'issuer',
                'https://login.example.com/realms/fog',
                'url',
                'issuer',
                $issuer,
                true
            ),
            self::makeLabel($this->_labelClass, 'clientId', _('Client ID'))
            => self::makeInput(
                'form-control oidcclientid-input',
                'clientId',
                _('Client ID'),
                'text',
                'clientId',
                $clientId,
                true
            ),
            self::makeLabel(
                $this->_labelClass,
                'clientSecret',
                _('Client Secret')
            ) => self::makeInput(
                'form-control oidcclientsecret-input',
                'clientSecret',
                _('Client Secret'),
                'password',
                'clientSecret',
                ''
            ),
            self::makeLabel($this->_labelClass, 'scopes', _('Scopes'))
            => self::makeInput(
                'form-control oidcscopes-input',
                'scopes',
                'openid profile email',
                'text',
                'scopes',
                $scopes ?: 'openid profile email'
            ),
            self::makeLabel(
                $this->_labelClass,
                'userClaim',
                _('Username Claim')
            ) => self::makeInput(
                'form-control oidcuserclaim-input',
                'userClaim',
                'preferred_username',
                'text',
                'userClaim',
                $userClaim ?: 'preferred_username'
            ),
            self::makeLabel(
                $this->_labelClass,
                'groupClaim',
                _('Group Claim')
            ) => self::makeInput(
                'form-control oidcgroupclaim-input',
                'groupClaim',
                'groups',
                'text',
                'groupClaim',
                $groupClaim ?: 'groups'
            ),
            self::makeLabel($this->_labelClass, 'icon', _('Button Icon'))
            => self::makeInput(
                'form-control oidcicon-input',
                'icon',
                'fa fa-id-badge',
                'text',
                'icon',
                $icon ?: 'fa fa-id-badge'
            ),
            self::makeLabel(
                $this->_labelClass,
                'redirectUri',
                _('Redirect URI')
                . '<br/>('
                . _('register this at your provider')
                . ')'
            ) => self::makeInput(
                'form-control',
                'redirectUri',
                '',
                'text',
                'redirectUri',
                OIDC::redirectUri(),
                false,
                false,
                -1,
                -1,
                '',
                true
            )
        ];
    }
    /**
     * The standalone "create" page (sub=add).
     *
     * @return void
     */
    public function add()
    {
        $this->renderAddForm(
            'oidc',
            _('Create New OpenID Connect Provider'),
            'OIDC_ADD_FIELDS',
            'OIDC'
        );
    }
    /**
     * The "create" form rendered inside the list page modal.
     *
     * @return void
     */
    public function addModal()
    {
        $this->renderAddModalForm(
            'oidc',
            'OIDC_ADD_FIELDS',
            'OIDC'
        );
    }
    /**
     * Persist a new provider. Returns JSON.
     *
     * A provider is created switched off and with JIT provisioning off --
     * neither is offered on the create form. Adding a row is not the same
     * act as opening a new way into the server, and an admin part way
     * through pasting a client secret should not have a half-configured
     * login live while they finish.
     *
     * @return void
     */
    public function addPost()
    {
        $this->handleAddPost(
            'OIDC',
            'OIDC_ADD',
            _('Provider added!'),
            _('OpenID Connect Provider Create Success'),
            _('OpenID Connect Provider Create Fail'),
            function (&$serverFault) {
                $name = trim((string)filter_input(INPUT_POST, 'name'));
                if ('' === $name) {
                    throw new \Exception(_('Please enter a name'));
                }
                if (self::getClass('OIDCManager')->exists($name)) {
                    throw new \Exception(
                        _('A provider already exists with this name!')
                    );
                }
                $OIDC = self::getClass('OIDC')
                    ->set('name', $name)
                    ->set(
                        'description',
                        trim((string)filter_input(INPUT_POST, 'description'))
                    )
                    ->set('issuer', filter_input(INPUT_POST, 'issuer'))
                    ->set('clientId', filter_input(INPUT_POST, 'clientId'))
                    ->set(
                        'clientSecret',
                        (string)filter_input(INPUT_POST, 'clientSecret')
                    )
                    ->set('scopes', filter_input(INPUT_POST, 'scopes'))
                    ->set('userClaim', filter_input(INPUT_POST, 'userClaim'))
                    ->set('groupClaim', filter_input(INPUT_POST, 'groupClaim'))
                    ->set('icon', trim((string)filter_input(INPUT_POST, 'icon')))
                    ->set('enabled', '0')
                    ->set('jitProvision', '0')
                    ->set('allowapi', '0');
                if (!$OIDC->save()) {
                    $serverFault = true;
                    throw new \Exception(_('Add provider failed!'));
                }
                return $OIDC;
            }
        );
    }
    /**
     * The "General" tab body shown on the edit page.
     *
     * @return void
     */
    public function oidcGeneral()
    {
        $get = function ($key) {
            $posted = filter_input(INPUT_POST, $key);
            return null === $posted ? $this->obj->get($key) : $posted;
        };
        $checked = function ($key) {
            return $this->obj->get($key) ? 'checked' : '';
        };

        $fields = [
            self::makeLabel($this->_labelClass, 'name', _('Name'))
            => self::makeInput(
                'form-control oidcname-input',
                'name',
                _('Name'),
                'text',
                'name',
                $get('name'),
                true
            ),
            self::makeLabel(
                $this->_labelClass,
                'description',
                _('Description')
            ) => self::makeTextarea(
                'form-control oidcdescription-input',
                'description',
                _('Description'),
                'description',
                $get('description')
            ),
            self::makeLabel($this->_labelClass, 'issuer', _('Issuer URL'))
            => self::makeInput(
                'form-control oidcissuer-input',
                'issuer',
                'https://login.example.com/realms/fog',
                'url',
                'issuer',
                $get('issuer'),
                true
            ),
            self::makeLabel($this->_labelClass, 'clientId', _('Client ID'))
            => self::makeInput(
                'form-control oidcclientid-input',
                'clientId',
                _('Client ID'),
                'text',
                'clientId',
                $get('clientId'),
                true
            ),
            // The stored secret is never sent back to the browser. The field
            // shows a placeholder, and posting it unchanged leaves the
            // stored value alone -- see oidcGeneralPost().
            self::makeLabel(
                $this->_labelClass,
                'clientSecret',
                _('Client Secret')
                . '<br/>('
                . _('leave to keep the current one')
                . ')'
            ) => self::makeInput(
                'form-control oidcclientsecret-input',
                'clientSecret',
                '',
                'password',
                'clientSecret',
                ('' !== (string)$this->obj->get('clientSecret')
                    ? self::SECRET_UNCHANGED
                    : '')
            ),
            self::makeLabel($this->_labelClass, 'scopes', _('Scopes'))
            => self::makeInput(
                'form-control oidcscopes-input',
                'scopes',
                'openid profile email',
                'text',
                'scopes',
                $get('scopes')
            ),
            self::makeLabel(
                $this->_labelClass,
                'userClaim',
                _('Username Claim')
            ) => self::makeInput(
                'form-control oidcuserclaim-input',
                'userClaim',
                'preferred_username',
                'text',
                'userClaim',
                $get('userClaim')
            ),
            self::makeLabel(
                $this->_labelClass,
                'groupClaim',
                _('Group Claim')
            ) => self::makeInput(
                'form-control oidcgroupclaim-input',
                'groupClaim',
                'groups',
                'text',
                'groupClaim',
                $get('groupClaim')
            ),
            self::makeLabel($this->_labelClass, 'icon', _('Button Icon'))
            => self::makeInput(
                'form-control oidcicon-input',
                'icon',
                'fa fa-id-badge',
                'text',
                'icon',
                $get('icon')
            ),
            self::makeLabel(
                $this->_labelClass,
                'redirectUri',
                _('Redirect URI')
                . '<br/>('
                . _('register this at your provider')
                . ')'
            ) => self::makeInput(
                'form-control',
                'redirectUri',
                '',
                'text',
                'redirectUri',
                OIDC::redirectUri(),
                false,
                false,
                -1,
                -1,
                '',
                true
            ),
            self::makeLabel($this->_labelClass, 'enabled', _('Enabled'))
            => self::makeInput(
                '',
                'enabled',
                '',
                'checkbox',
                'enabled',
                '',
                false,
                false,
                -1,
                -1,
                $checked('enabled')
            ),
            self::makeLabel(
                $this->_labelClass,
                'jitProvision',
                _('Create Users On First Login')
                . '<br/>('
                . _('off means an account must already exist')
                . ')'
            ) => self::makeInput(
                '',
                'jitProvision',
                '',
                'checkbox',
                'jitProvision',
                '',
                false,
                false,
                -1,
                -1,
                $checked('jitProvision')
            ),
            self::makeLabel($this->_labelClass, 'allowapi', _('Allow API'))
            => self::makeInput(
                '',
                'allowapi',
                '',
                'checkbox',
                'allowapi',
                '',
                false,
                false,
                -1,
                -1,
                $checked('allowapi')
            )
        ];

        $buttons = self::makeButton(
            'general-send',
            _('Update'),
            'btn btn-primary float-end'
        );
        $buttons .= self::makeButton(
            'general-delete',
            _('Delete'),
            'btn btn-danger float-start'
        );

        self::$HookManager->processEvent(
            'OIDC_GENERAL_FIELDS',
            [
                'fields' => &$fields,
                'buttons' => &$buttons,
                'OIDC' => $this->obj
            ]
        );
        $rendered = self::formFields($fields);
        unset($fields);

        echo self::makeFormTag(
            '',
            'oidc-general-form',
            self::makeTabUpdateURL('oidc-general', $this->obj->get('id')),
            'post',
            'application/x-www-form-urlencoded',
            true
        );
        echo '<div class="card">';
        echo '<div class="card-body">';
        echo $rendered;
        echo '</div>';
        echo '<div class="card-footer">';
        echo $buttons;
        echo $this->deleteModal();
        echo '</div>';
        echo '</div>';
        echo '</form>';
    }
    /**
     * Apply the General tab edits to $this->obj (saved by editPost()).
     *
     * @return void
     */
    public function oidcGeneralPost()
    {
        self::checkAuthAndCSRF();
        $name = trim((string)filter_input(INPUT_POST, 'name'));
        if ('' === $name) {
            throw new \Exception(_('Please enter a name'));
        }
        if ($name != $this->obj->get('name')
            && self::getClass('OIDCManager')->exists($name)
        ) {
            throw new \Exception(
                _('A provider already exists with this name!')
            );
        }
        $this->obj
            ->set('name', $name)
            ->set(
                'description',
                trim((string)filter_input(INPUT_POST, 'description'))
            )
            ->set('issuer', filter_input(INPUT_POST, 'issuer'))
            ->set('clientId', filter_input(INPUT_POST, 'clientId'))
            ->set('scopes', filter_input(INPUT_POST, 'scopes'))
            ->set('userClaim', filter_input(INPUT_POST, 'userClaim'))
            ->set('groupClaim', filter_input(INPUT_POST, 'groupClaim'))
            ->set('icon', trim((string)filter_input(INPUT_POST, 'icon')))
            ->set('enabled', isset($_POST['enabled']) ? '1' : '0')
            ->set(
                'jitProvision',
                isset($_POST['jitProvision']) ? '1' : '0'
            )
            ->set('allowapi', isset($_POST['allowapi']) ? '1' : '0');

        // The secret is only written when the admin actually typed one. An
        // empty field and the placeholder both mean "unchanged"; without
        // this, editing any other setting would blank the credential and the
        // next login would fail with a provider-side error nobody would
        // connect to the edit they just made.
        $secret = (string)filter_input(INPUT_POST, 'clientSecret');
        if ('' !== $secret && self::SECRET_UNCHANGED !== $secret) {
            $this->obj->set('clientSecret', $secret);
        }
    }
    /**
     * The edit page (sub=edit) -- renders tabs.
     *
     * @return void
     */
    public function edit()
    {
        $tabData = [];
        $tabData[] = [
            'name' => _('General'),
            'id' => 'oidc-general',
            'generator' => function () {
                $this->oidcGeneral();
            }
        ];
        $this->renderEditTabs($tabData, $this->obj);
    }
    /**
     * Persist edits. Returns JSON.
     *
     * @return void
     */
    public function editPost()
    {
        $this->handleEditPost(
            'OIDC',
            'OIDC_EDIT',
            _('Provider updated!'),
            _('OpenID Connect Provider Update Success'),
            _('OpenID Connect Provider Update Fail'),
            function (&$serverFault) {
                global $tab;
                switch ($tab) {
                    case 'oidc-general':
                        $this->oidcGeneralPost();
                }
                if (!$this->obj->save()) {
                    $serverFault = true;
                    throw new \Exception(_('Provider update failed!'));
                }
            }
        );
    }
}
