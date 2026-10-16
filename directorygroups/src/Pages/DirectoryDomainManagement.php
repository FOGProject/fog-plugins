<?php
/**
 * Directory domain management page.
 *
 * PHP version 7
 *
 * @category DirectoryDomainManagement
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

namespace FOG\Plugins\DirectoryGroups\Pages;

use FOG\Plugins\DirectoryGroups\Items\DirectoryDomain;
use FOG\Plugins\DirectoryGroups\Items\DirectoryGroups;
use FOG\Plugins\DirectoryGroups\Managers\DirectoryDomainManager;

/**
 * Directory domain management page.
 *
 * Lists the domains FOG places hosts in, edits each one's connection, and
 * browses a domain's OUs so they can be added as directory groups without
 * typing a DN.
 *
 * @category DirectoryDomainManagement
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class DirectoryDomainManagement extends \FOG\Base\FOGPage
{
    /**
     * The node this page displays from.
     *
     * @var string
     */
    public $node = 'directorydomain';
    /**
     * Initializes the page.
     *
     * @param string $name The name to pass in.
     *
     * @return void
     */
    public function __construct($name = '')
    {
        $this->name = _('Directory Domains');
        parent::__construct($this->name);
        $this->headerData = [
            _('Domain'),
            _('Server URI')
        ];
        $this->attributes = [
            [],
            []
        ];
    }
    /**
     * The connection fields, shared by the create form and the General tab.
     *
     * The bind password is never rendered back. An empty one on edit keeps
     * the stored value, so editing any other field cannot wipe it.
     *
     * @param array $values the current values, keyed by field
     * @param bool  $isEdit is this the General tab of an existing domain
     *
     * @return array
     */
    private function _fields(array $values, $isEdit)
    {
        $labelClass = 'col-sm-3 col-form-label';
        $text = function ($name, $label, $placeholder, $required = false) use ($values, $labelClass) {
            return [
                self::makeLabel($labelClass, $name, $label) => self::makeInput(
                    'form-control',
                    $name,
                    $placeholder,
                    'text',
                    $name,
                    $values[$name] ?? '',
                    $required
                )
            ];
        };
        return $text('name', _('Domain Name'), 'example.com', true)
            + [
                self::makeLabel($labelClass, 'description', _('Description'))
                => self::makeTextarea(
                    'form-control',
                    'description',
                    _('Description'),
                    'description',
                    $values['description'] ?? ''
                )
            ]
            + $text('uri', _('Server URI'), 'ldaps://dc1.example.com:636', true)
            + $text('bindDN', _('Bind DN'), 'CN=fog-read,CN=Users,DC=example,DC=com')
            + [
                self::makeLabel($labelClass, 'bindPwd', _('Bind Password'))
                => self::makeInput(
                    'form-control',
                    'bindPwd',
                    $isEdit ? _('Leave blank to keep the current password') : '',
                    'password',
                    'bindPwd',
                    ''
                )
            ]
            + $text('caCert', _('CA Certificate Path'), '/etc/ssl/certs/example-ca.pem')
            + $text('baseDN', _('Computer Search Base'), 'DC=example,DC=com')
            + $text('ouBase', _('OU Browse Base'), _('Blank starts at the domain root'));
    }
    /**
     * The posted connection fields, trimmed.
     *
     * The password is the exception: it is used as typed.
     *
     * @return array
     */
    private static function _posted()
    {
        $out = [];
        foreach (['name', 'description', 'uri', 'bindDN', 'caCert', 'baseDN', 'ouBase'] as $key) {
            $out[$key] = trim((string)filter_input(INPUT_POST, $key));
        }
        $out['name'] = strtolower($out['name']);
        $out['bindPwd'] = (string)filter_input(INPUT_POST, 'bindPwd');
        return $out;
    }
    /**
     * Throws when the posted name is taken by another domain.
     *
     * @param string $name the posted name
     * @param int    $id   the domain being edited, or 0
     *
     * @throws \Exception
     * @return void
     */
    private static function _assertNameFree($name, $id = 0)
    {
        if ((new DirectoryDomainManager())->exists($name, $id)) {
            throw new \Exception(_('A domain already exists with this name!'));
        }
    }
    /**
     * The create form's fields.
     *
     * @return array
     */
    protected function _addFields()
    {
        return $this->_fields(self::_posted(), false);
    }
    /**
     * Create new domain.
     *
     * @return void
     */
    public function add()
    {
        $this->renderAddForm(
            'directorydomain',
            _('Create New Domain'),
            'DIRECTORYDOMAIN_ADD_FIELDS',
            'DirectoryDomain'
        );
    }
    /**
     * Create new domain, in a modal.
     *
     * @return void
     */
    public function addModal()
    {
        $this->renderAddModalForm(
            'directorydomain',
            'DIRECTORYDOMAIN_ADD_FIELDS',
            'DirectoryDomain'
        );
    }
    /**
     * Actually create the domain.
     *
     * @return void
     */
    public function addPost()
    {
        $this->handleAddPost(
            'DirectoryDomain',
            'DIRECTORYDOMAIN_ADD',
            _('Domain added!'),
            _('Domain Create Success'),
            _('Domain Create Fail'),
            function (&$serverFault) {
                $in = self::_posted();
                self::_assertNameFree($in['name']);
                $Domain = new DirectoryDomain();
                foreach ($in as $key => $value) {
                    $Domain->set($key, $value);
                }
                if (!$Domain->save()) {
                    $serverFault = true;
                    throw new \Exception(_('Add domain failed!'));
                }
                return $Domain;
            }
        );
    }
    /**
     * The General tab.
     *
     * @return void
     */
    public function directorydomainGeneral()
    {
        $values = [];
        foreach (['name', 'description', 'uri', 'bindDN', 'caCert', 'baseDN', 'ouBase'] as $key) {
            $values[$key] = filter_input(INPUT_POST, $key) ?: $this->obj->get($key);
        }
        $fields = $this->_fields($values, true);

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
            'DIRECTORYDOMAIN_GENERAL_FIELDS',
            [
                'fields' => &$fields,
                'buttons' => &$buttons,
                'DirectoryDomain' => &$this->obj
            ]
        );
        $rendered = self::formFields($fields);
        unset($fields);

        echo self::makeFormTag(
            '',
            'directorydomain-general-form',
            self::makeTabUpdateURL(
                'directorydomain-general',
                $this->obj->get('id')
            ),
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
     * Saves the General tab.
     *
     * @return void
     */
    public function directorydomainGeneralPost()
    {
        $in = self::_posted();
        self::_assertNameFree($in['name'], $this->obj->get('id'));
        if ('' === $in['bindPwd']) {
            unset($in['bindPwd']);
        }
        foreach ($in as $key => $value) {
            $this->obj->set($key, $value);
        }
    }
    /**
     * The Organizational Units tab.
     *
     * The tree itself is fetched by the browser from getOUs, so opening the
     * domain does not wait on the directory.
     *
     * @return void
     */
    public function directorydomainOUs()
    {
        $buttons = '';
        if (\FOG\Auth\Authorization::can('directorygroups.create')) {
            $buttons = self::makeButton(
                'directorydomain-ous-send',
                _('Add selected as groups'),
                'btn btn-primary float-end'
            );
        }
        echo '<div class="card">';
        echo '<div class="card-header"><h4 class="card-title">';
        echo \Initiator::e($this->obj->browseBase());
        echo '</h4></div>';
        echo '<div class="card-body">';
        echo '<table id="directorydomain-ous-table"'
            . ' class="table table-bordered table-striped"'
            . ' data-domain-id="' . (int)$this->obj->get('id') . '"'
            . ' data-update-url="' . \Initiator::e(
                self::makeTabUpdateURL('directorydomain-ous', $this->obj->get('id'))
            ) . '">';
        echo '<thead><tr><th></th><th>' . _('OU') . '</th>'
            . '<th>' . _('Distinguished Name') . '</th>'
            . '<th>' . _('Group') . '</th></tr></thead>';
        echo '</table>';
        echo '</div>';
        echo '<div class="card-footer">';
        echo $buttons;
        echo '</div>';
        echo '</div>';
    }
    /**
     * Adds the selected OUs as directory groups.
     *
     * A DN that already has a group is skipped, not duplicated, so a repeat
     * of the same selection changes nothing. Only DNs the directory lists
     * under this domain's browse base are accepted: the post names DNs, and
     * this is what stops a hand-built one from becoming a group.
     *
     * @throws \Exception
     * @return void
     */
    public function directorydomainOUsPost()
    {
        if (!\FOG\Auth\Authorization::can('directorygroups.create')) {
            throw new \Exception(_('You may not create directory groups.'));
        }
        $wanted = array_flip(array_map('strtolower', array_map(
            'trim',
            (array)filter_input(INPUT_POST, 'ous', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY)
        )));
        if (!$wanted) {
            throw new \Exception(_('No OU selected.'));
        }
        $existing = self::_groupsByDN();
        foreach ($this->obj->listOUs() as $ou) {
            $key = strtolower($ou['dn']);
            if (!isset($wanted[$key]) || isset($existing[$key])) {
                continue;
            }
            $Group = (new DirectoryGroups())
                ->set('name', self::groupName($this->obj->get('name'), $ou['dn']))
                ->set('description', '')
                ->set('ou', $ou['dn'])
                ->set('domainID', $this->obj->get('id'));
            if (!$Group->save()) {
                throw new \Exception(_('Add group failed!'));
            }
        }
    }
    /**
     * The group name for an OU: the domain, then the OU path from the top.
     *
     * OU names repeat across a tree ("Computers" under every site), so the
     * bare name cannot be the group name. fogad.local/Sales/Staging is
     * unique and reads in the order an admin navigates.
     *
     * @param string $domain the domain name
     * @param string $dn     the OU's DN
     *
     * @return string
     */
    public static function groupName($domain, $dn)
    {
        $path = [];
        foreach (self::_rdns($dn) as $rdn) {
            if (0 === stripos($rdn, 'OU=')) {
                array_unshift($path, substr($rdn, 3));
            }
        }
        return $domain . '/' . implode('/', $path);
    }
    /**
     * A DN's components, leaf first.
     *
     * Through FOGLdap's own splitter, which honors an escaped comma
     * (OU=Smith\, John); a plain explode would cut that name in two.
     *
     * @param string $dn the DN
     *
     * @return string[]
     */
    private static function _rdns($dn)
    {
        $out = [];
        $dn = (string)$dn;
        while ('' !== $dn) {
            $out[] = \FOG\Net\FOGLdap::rdn($dn);
            $dn = \FOG\Net\FOGLdap::parentDn($dn);
        }
        return $out;
    }
    /**
     * Group ids keyed by lowercased DN.
     *
     * @return array<string,int>
     */
    private static function _groupsByDN()
    {
        $out = [];
        foreach ((array)\FOG\Router\Route::getIds('directorygroups', [], ['id', 'ou']) as $row) {
            $row = (array)$row;
            $out[strtolower((string)$row['ou'])] = (int)$row['id'];
        }
        return $out;
    }
    /**
     * The domain's OUs, as JSON, for the tree and the host browser.
     *
     * Each row says whether a group already exists for it, so the tree can
     * mark the OUs that are already grouped.
     *
     * @return void
     */
    public function getOUs()
    {
        header('Content-type: application/json');
        try {
            $groups = self::_groupsByDN();
            $base = count(self::_rdns($this->obj->browseBase()));
            $rows = [];
            foreach ($this->obj->listOUs() as $ou) {
                $rows[] = [
                    'dn' => $ou['dn'],
                    'name' => $ou['name'],
                    'depth' => max(0, count(self::_rdns($ou['dn'])) - $base),
                    'groupID' => $groups[strtolower($ou['dn'])] ?? 0
                ];
            }
            $this->jsonSend(
                \FOG\Router\HTTPResponseCodes::HTTP_SUCCESS,
                json_encode([
                    'domain' => $this->obj->get('name'),
                    'data' => $rows
                ])
            );
        } catch (\Exception $e) {
            $this->jsonSend(
                \FOG\Router\HTTPResponseCodes::HTTP_BAD_REQUEST,
                json_encode([
                    'error' => $e->getMessage(),
                    'title' => _('Cannot read the directory')
                ])
            );
        }
    }
    /**
     * The domains, as JSON, for the host browser's domain picker.
     *
     * @return void
     */
    public function getDomains()
    {
        header('Content-type: application/json');
        $rows = [];
        foreach ((array)\FOG\Router\Route::getIds('directorydomain', [], ['id', 'name']) as $row) {
            $row = (array)$row;
            $rows[] = ['id' => (int)$row['id'], 'name' => (string)$row['name']];
        }
        $this->jsonSend(
            \FOG\Router\HTTPResponseCodes::HTTP_SUCCESS,
            json_encode(['data' => $rows])
        );
    }
    /**
     * Present the domain to edit.
     *
     * @return void
     */
    public function edit()
    {
        $tabData = [];
        $tabData[] = [
            'name' => _('General'),
            'id' => 'directorydomain-general',
            'generator' => function () {
                $this->directorydomainGeneral();
            }
        ];
        $tabData[] = [
            'name' => _('Organizational Units'),
            'id' => 'directorydomain-ous',
            'generator' => function () {
                $this->directorydomainOUs();
            }
        ];
        $this->renderEditTabs($tabData, $this->obj);
    }
    /**
     * Saves the tab that was posted.
     *
     * @return void
     */
    public function editPost()
    {
        $this->handleEditPost(
            'DirectoryDomain',
            'DIRECTORYDOMAIN_EDIT',
            _('Domain updated!'),
            _('Domain Update Success'),
            _('Domain Update Fail'),
            function (&$serverFault) {
                global $tab;
                switch ($tab) {
                    case 'directorydomain-general':
                        $this->directorydomainGeneralPost();
                        break;
                    case 'directorydomain-ous':
                        $this->directorydomainOUsPost();
                        return;
                }
                if (!$this->obj->save()) {
                    $serverFault = true;
                    throw new \Exception(_('Domain update failed!'));
                }
            }
        );
    }
}
