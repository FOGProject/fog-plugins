<?php
/**
 * A Directory Domain's join account, and where its password must not go.
 *
 * GH-1887. A domain can carry the account its hosts join with. Core asks
 * for it through DIRECTORY_JOIN_ACCOUNT on every path that hands a join
 * account to a client, and the domain's account beats the host's own when
 * the domain has one.
 *
 * What this pins:
 * - joinAccount() answers only when both the user and the password are set;
 * - the listener is registered for the event;
 * - the join password is stripped from the API in the `always` tier and
 *   dropped from the export, the same as the bind password;
 * - the columns arrive by appended ALTER steps.
 *
 * Requires no database.
 *
 * PHP version 7.4+
 *
 * @category Tests
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

namespace FOG\Agent {
    /**
     * Stand-in for core's stored-secret decoder: hands the value back.
     */
    class DirectoryPlacement
    {
        /**
         * Decode a stored secret.
         *
         * @param string $value the stored value
         *
         * @return string
         */
        public static function decodeStored($value)
        {
            return (string)$value;
        }
    }
}

namespace {
    $root = dirname(__DIR__);
    $failures = [];
    $checks = 0;

    /**
     * Asserts a condition.
     *
     * @param string $label what is being checked
     * @param bool   $cond  the condition
     *
     * @return void
     */
    function check($label, $cond)
    {
        global $failures, $checks;
        $checks++;
        if (!$cond) {
            $failures[] = $label;
        }
    }

    require_once __DIR__ . '/stubs/fog-stubs.php';
    require_once $root . '/directorygroups/src/Items/DirectoryDomain.php';
    require_once $root . '/directorygroups/src/Hooks/AddDirectoryDomainConnection.php';
    require_once $root . '/directorygroups/src/Hooks/AddDirectoryGroupsAPI.php';

    $domain = function ($user, $password) {
        return (new \FOG\Plugins\DirectoryGroups\Items\DirectoryDomain())
            ->set('joinUser', $user)
            ->set('joinPwd', $password);
    };

    // ------------------------------------------------ 1. what is an account

    check(
        'a user and a password make an account, the user trimmed',
        ['svc-join', 'secret'] === $domain(' svc-join ', 'secret')->joinAccount()
    );
    check(
        'a user with no password is not an account',
        null === $domain('svc-join', '')->joinAccount()
    );
    check(
        'a password with no user is not an account',
        null === $domain('', 'secret')->joinAccount()
    );

    // ---------------------------------------------------- 2. the listener

    $hook = new \FOG\Plugins\DirectoryGroups\Hooks\AddDirectoryDomainConnection();
    $events = [];
    foreach ($hook->registered as $pair) {
        $events[$pair[0]] = $pair[1];
    }
    check(
        'the plugin answers DIRECTORY_JOIN_ACCOUNT',
        'joinAccount' === ($events['DIRECTORY_JOIN_ACCOUNT'] ?? null)
    );

    // ------------------------------------------- 3. the password stays put

    $api = new \FOG\Plugins\DirectoryGroups\Hooks\AddDirectoryGroupsAPI();
    // Core hands each listener an array of references, as processEvent()
    // callers do, so the writes land in the caller's variables.
    $always = [];
    $api->declareSensitiveFields(['always' => &$always]);
    check(
        'the API never emits the join password, not even for one domain',
        in_array('joinPwd', (array)($always['directorydomain'] ?? []), true)
    );
    $columns = [
        ['dt' => 'name'], ['dt' => 'bindPwd'], ['dt' => 'joinUser'], ['dt' => 'joinPwd'],
    ];
    $api->stripBindPassword(['columns' => &$columns]);
    check(
        'the export drops both passwords and keeps the join user',
        ['name', 'joinUser'] === array_column($columns, 'dt')
    );

    // -------------------------------------------------------- 4. schema

    $manager = (string)file_get_contents(
        $root . '/directorygroups/src/Managers/DirectoryGroupsManager.php'
    );
    check(
        'ddJoinUser and ddJoinPwd arrive by their own ALTER steps',
        false !== strpos($manager, 'ADD COLUMN `ddJoinUser`')
            && false !== strpos($manager, 'ADD COLUMN `ddJoinPwd`')
    );

    if (count($failures)) {
        echo 'FAIL: ' . count($failures) . " of $checks checks failed.\n";
        foreach ($failures as $f) {
            echo "  - $f\n";
        }
        exit(1);
    }
    echo "directorygroups-join-account: $checks checks passed\n";
}
