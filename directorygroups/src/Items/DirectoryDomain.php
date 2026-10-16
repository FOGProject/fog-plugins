<?php
/**
 * One Active Directory domain and the account FOG reads it with.
 *
 * PHP version 7
 *
 * @category DirectoryDomain
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

namespace FOG\Plugins\DirectoryGroups\Items;

/**
 * One Active Directory domain and the account FOG reads it with.
 *
 * Each domain carries its own connection, so one install can place hosts in
 * several domains (GH-1879). Core asks for a host's connection through
 * DIRECTORY_CONNECTION, and AddDirectoryDomainConnection answers it from the
 * domain whose name matches the host's AD domain.
 *
 * The bind password is stored as typed, the same as the ldap plugin's bind
 * password and the FOG_DIRECTORY_BIND_PASSWORD setting it replaces. It never
 * leaves the server: the API strips it in the 'always' tier, and the edit form
 * does not render it back.
 *
 * @category DirectoryDomain
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class DirectoryDomain extends \FOG\Base\FOGController
{
    /**
     * The table.
     *
     * @var string
     */
    protected $databaseTable = 'directoryDomains';
    /**
     * The fields.
     *
     * name is the domain's DNS name (fogad.local), the value a host carries
     * in ADDomain. baseDN is where placement searches for computer objects;
     * ouBase is where the OU browser starts, and defaults to the domain root.
     *
     * @var array
     */
    protected $databaseFields = [
        'id' => 'ddID',
        'name' => 'ddName',
        'description' => 'ddDesc',
        'createdBy' => 'ddCreatedBy',
        'createdTime' => 'ddCreatedTime',
        'uri' => 'ddURI',
        'bindDN' => 'ddBindDN',
        'bindPwd' => 'ddBindPwd',
        'caCert' => 'ddCACert',
        'baseDN' => 'ddBaseDN',
        'ouBase' => 'ddOUBase',
        'joinUser' => 'ddJoinUser',
        'joinPwd' => 'ddJoinPwd'
    ];
    /**
     * The required fields.
     *
     * @var array
     */
    protected $databaseFieldsRequired = [
        'name',
        'uri'
    ];
    /**
     * The domain whose name matches a host's AD domain, or null.
     *
     * Case-insensitive, because DNS names are, and an admin types
     * FOGAD.local on one host and fogad.local on the next.
     *
     * @param string $domain the host's ADDomain
     *
     * @return self|null
     */
    public static function forName($domain)
    {
        $domain = strtolower(trim((string)$domain));
        if ('' === $domain) {
            return null;
        }
        foreach ((array)\FOG\Router\Route::getIds('directorydomain') as $id) {
            $item = new self($id);
            if ($item->isValid()
                && strtolower(trim((string)$item->get('name'))) === $domain
            ) {
                return $item;
            }
        }
        return null;
    }
    /**
     * The connection core's placement uses, in the DIRECTORY_CONNECTION shape.
     *
     * @return array{uri:string,bindDN:string,password:string,caCert:string,baseDN:string}
     */
    public function connection()
    {
        return [
            'uri' => (string)$this->get('uri'),
            'bindDN' => (string)$this->get('bindDN'),
            'password' => \FOG\Agent\DirectoryPlacement::decodeStored(
                $this->get('bindPwd')
            ),
            'caCert' => (string)$this->get('caCert'),
            'baseDN' => (string)$this->get('baseDN')
        ];
    }
    /**
     * The account hosts in this domain join with, or null.
     *
     * Both halves must be set. A user with no password, or a password with
     * no user, is not an account, and the host keeps its own (GH-1887).
     *
     * @return array|null [user, password]
     */
    public function joinAccount()
    {
        $user = trim((string)$this->get('joinUser'));
        $password = \FOG\Agent\DirectoryPlacement::decodeStored(
            (string)$this->get('joinPwd')
        );
        if ('' === $user || '' === $password) {
            return null;
        }
        return [$user, $password];
    }
    /**
     * Where the OU browser starts.
     *
     * The configured base, or else the domain root built from the name:
     * fogad.local becomes DC=fogad,DC=local. That is the root of every
     * Active Directory domain, so the default shows the whole tree.
     *
     * @return string
     */
    public function browseBase()
    {
        $base = trim((string)$this->get('ouBase'));
        if ('' !== $base) {
            return $base;
        }
        $labels = array_filter(
            explode('.', trim((string)$this->get('name'))),
            'strlen'
        );
        if (!$labels) {
            return '';
        }
        return 'DC=' . implode(',DC=', $labels);
    }
    /**
     * Every OU under the browse base, root first.
     *
     * @throws \Exception with the directory's reason when it cannot be read
     * @return array<int,array{dn:string,name:string}>
     */
    public function listOUs()
    {
        $connection = $this->connection();
        $ldap = new \FOG\Net\FOGLdap();
        try {
            if (!$ldap->connect(
                $connection['uri'],
                $connection['bindDN'],
                $connection['password'],
                $connection['caCert']
            )) {
                throw new \Exception($ldap->error());
            }
            $ous = $ldap->listOUs($this->browseBase());
            if (null === $ous) {
                throw new \Exception($ldap->error());
            }
            return $ous;
        } finally {
            $ldap->close();
        }
    }
}
