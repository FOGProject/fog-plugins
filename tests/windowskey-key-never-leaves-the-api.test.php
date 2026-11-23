<?php
/**
 * windowskey.key is in the 'always' sensitive tier.
 *
 * The API strips the ordinary tier ('fields') from lists only, and keeps it
 * on a direct single-entity GET. So a key declared there reached any holder
 * of windowskey.view, one GET at a time. Core moved host.productKey to
 * 'always' for the same reason (GHSA-q38j-qx5x-w25v), and this plugin's
 * stated reason for its tier was "the same tier core puts host.productKey
 * in".
 *
 * Pinned against the real hook: the arguments carry references, the way
 * HookManager::processEvent() passes them, so a write the hook makes to a
 * copy fails here as it would in FOG.
 *
 * Usage: php tests/windowskey-key-never-leaves-the-api.test.php
 * Exit status 0 = pass, 1 = fail.
 */

$root = dirname(__DIR__);

require $root . '/tests/stubs/fog-stubs.php';
require $root . '/windowskey/src/Hooks/AddWindowskeyAPI.php';

$fields = [];
$always = [];
$exempt = [];
$hook = new \FOG\Plugins\WindowsKey\Hooks\AddWindowskeyAPI();
$hook->declareSensitiveFields(
    [
        'fields' => &$fields,
        'always' => &$always,
        'exempt' => &$exempt,
    ]
);

$failures = [];
if (!in_array('key', (array)($always['windowskey'] ?? []), true)) {
    $failures[] = "windowskey.key is declared in the 'always' tier";
}
if (in_array('key', (array)($fields['windowskey'] ?? []), true)) {
    $failures[] = "windowskey.key is not declared in the ordinary tier, "
        . "which a single GET keeps";
}
if (!in_array('windowskeyID', (array)($exempt['windowskeyassociation'] ?? []), true)) {
    $failures[] = 'windowskeyassociation.windowskeyID is still exempt';
}

if ($failures) {
    fwrite(
        STDERR,
        "FAIL: windowskey-key-never-leaves-the-api\n  "
        . implode("\n  ", $failures) . "\n"
    );
    exit(1);
}
echo "PASS: windowskey-key-never-leaves-the-api (3 checks)\n";
exit(0);
