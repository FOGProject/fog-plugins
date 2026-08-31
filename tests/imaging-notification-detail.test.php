<?php
/**
 * The imaging notifications must survive a server that has not been updated.
 *
 * fogproject#1202 gave the imaging events a real payload -- ImageName, Reason,
 * and a distinct HOST_IMAGEUP_COMPLETE for a capture -- and these six
 * listeners now use it. A plugin release is not tied to a FOG release, so
 * every one of them has to keep working against a server whose core still
 * sends nothing but HostName. Read a missing key directly and the notification
 * becomes a PHP warning in the middle of an event that only fires when
 * something has already gone wrong.
 *
 * Also pinned here because this repository has no gettext gate of its own
 * (fogproject's tests/gettext-literal-msgid.test.php only scans fogproject):
 * a msgid must not mix literal text with a variable. `_("Image $name")`
 * extracts nothing and never translates, silently and permanently.
 *
 * Source-level. These classes extend the plugin's own Event base, which
 * extends FOG's, so none of them can be loaded without a booted FOG, a
 * session and a database -- the same reason group-tab-permissions.test.php
 * inspects rather than instantiates.
 *
 * Usage: php tests/imaging-notification-detail.test.php
 * Exit status 0 = pass, 1 = fail.
 */

$root = dirname(__DIR__);

// The class-name suffix does not always match the plugin directory's own
// casing (pushbullet's classes are suffixed _PushBullet, not _Pushbullet),
// so it is named explicitly rather than derived.
$suffixes = [
    'slack' => 'Slack',
    'ntfy' => 'Ntfy',
    'pushbullet' => 'PushBullet',
];

$complete = [];
$fail = [];
foreach ($suffixes as $plugin => $suffix) {
    $complete[$plugin] = sprintf(
        '%s/%s/src/Events/ImageComplete_%s.php',
        $root,
        $plugin,
        $suffix
    );
    $fail[$plugin] = sprintf(
        '%s/%s/src/Events/ImageFail_%s.php',
        $root,
        $plugin,
        $suffix
    );
}

$failures = [];
$checks = 0;

/**
 * Records one assertion.
 *
 * @param bool   $ok  Whether it held.
 * @param string $msg What is wrong if it did not.
 *
 * @return void
 */
$check = function ($ok, $msg) use (&$failures, &$checks) {
    ++$checks;
    if (!$ok) {
        $failures[] = $msg;
    }
};

foreach (array_merge(array_values($complete), array_values($fail)) as $file) {
    $src = file_get_contents($file);
    $where = basename($file);

    // The compatibility contract with an un-upgraded server.
    $check(
        false !== strpos($src, "\$data['ImageName'] ?? ''"),
        "$where reads ImageName without a default, so it warns against a"
        . ' server that has not taken fogproject#1202'
    );

    // HostName is the one key that has always been there. Losing it would
    // break the notification on every server, not just an old one.
    $check(
        false !== strpos($src, "\$data['HostName']")
        || false !== strpos($src, 'parent::onEvent'),
        "$where no longer uses HostName, the only key core has always sent"
    );

    // The gettext rule: literal inside _(), variable substituted outside.
    //
    // Only a DOUBLE-quoted literal can interpolate, and only that is a defect
    // -- a `$` inside a single-quoted msgid is a positional format specifier
    // like %1$s, which is exactly the shape a translator needs to reorder the
    // sentence.
    $check(
        !preg_match('#_\(\s*"[^"]*\$#', $src),
        "$where interpolates a variable inside a double-quoted _(), which"
        . ' extracts no msgid and so never translates'
    );
    $check(
        !preg_match('#_\(\s*[\'"][^\'"]*[\'"]\s*\.#', $src),
        "$where concatenates onto a literal inside _(), same defect as"
        . ' interpolating into one'
    );
    $check(
        !preg_match('#_\(\s*sprintf#', $src),
        "$where wraps _() around sprintf() rather than the other way round,"
        . ' so xgettext extracts the format string only by accident'
    );
}

foreach ($complete as $plugin => $file) {
    $src = file_get_contents($file);
    $where = basename($file);

    // The whole point of #1202's core half: one listener, two names.
    $check(
        false !== strpos($src, "'HOST_IMAGEUP_COMPLETE' === \$event"),
        "$where does not distinguish a capture from a deploy, so the name"
        . ' core went to the trouble of firing is wasted'
    );
    $check(
        false !== strpos($src, "'HOST_IMAGEUP_COMPLETE'")
        && false !== strpos($src, "'HOST_IMAGE_COMPLETE'"),
        "$where no longer registers both completion names"
    );
}

foreach ($fail as $plugin => $file) {
    $src = file_get_contents($file);
    $where = basename($file);

    // The reason is the only part of a failure an admin can act on.
    $check(
        false !== strpos($src, "\$data['Reason'] ?? ''"),
        "$where does not report why imaging failed, or reads Reason without a"
        . ' default'
    );
    $check(
        false !== strpos($src, "'HOST_IMAGE_FAIL'"),
        "$where no longer registers HOST_IMAGE_FAIL"
    );
}

if (count($failures) > 0) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . " problem(s):\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - $f\n");
    }
    exit(1);
}

printf("ok: %d checks across 6 imaging notification listeners\n", $checks);
exit(0);
