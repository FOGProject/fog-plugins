<?php
/**
 * The site plugin must never declare fog_max.
 *
 * site is not an ordinary plugin: it carries a security boundary. Its
 * OBJECT_SCOPE_CHECK listener is the only thing that makes
 * Authorization::objectInScope() ever answer false, so while the plugin is
 * how sites are delivered, "site is not active" and "there is no boundary"
 * are the same sentence.
 *
 * fog_max is what makes that reachable by accident. Plugin::compatError()
 * (fogproject packages/web/lib/fog/plugin.class.php:404-430) refuses
 * activation when the running FOG is newer than fog_max, and the activation
 * path treats that as a blocker (plugin.class.php:900). So a future commit
 * adding `$fog_plugin['fog_max'] = '1.6.0';` to this manifest -- a
 * reasonable-looking thing to write -- would, on the next release, stop
 * site activating on every server that upgraded. The hosts each user could
 * see would silently widen to all of them. No error, no log line: the
 * plugin simply reports as incompatible and the boundary is gone.
 *
 * Nothing in fogproject special-cases the string 'site': there is no
 * mention of it in plugin.class.php or pluginmanagement.page.php, so
 * nothing else stands between that commit and the outcome.
 *
 * fog_min is fine and expected -- it refuses activation on a FOG too OLD to
 * support the plugin, which fails safe. It is only the upper bound that
 * turns "this server is newer than we tested" into "the boundary is off".
 *
 * Usage: php tests/site-declares-no-fog-max.test.php
 * Exit status 0 = pass, 1 = fail.
 */

$manifestPath = dirname(__DIR__) . '/site/config/plugin.config.php';

if (!is_readable($manifestPath)) {
    fwrite(STDERR, "FAIL: cannot read $manifestPath\n");
    exit(1);
}

/*
 * Read it the way core does -- include the file and look at $fog_plugin
 * (Plugin::readManifest). Parsing the text instead would pass a manifest
 * that sets the key by some other route, and it is the value core ends up
 * with that decides whether the plugin activates.
 */
$fog_plugin = [];
require $manifestPath;

if (!is_array($fog_plugin) || !isset($fog_plugin['name'])) {
    fwrite(STDERR, "FAIL: $manifestPath did not define a \$fog_plugin manifest\n");
    exit(1);
}

if ('site' !== $fog_plugin['name']) {
    fwrite(
        STDERR,
        "FAIL: expected the manifest to name 'site', got '"
        . $fog_plugin['name'] . "'\n"
    );
    exit(1);
}

if (array_key_exists('fog_max', $fog_plugin)) {
    fwrite(
        STDERR,
        "FAIL: site declares fog_max = '" . $fog_plugin['fog_max'] . "'.\n"
        . "  site carries a security boundary. An upper version bound stops it\n"
        . "  activating on a newer FOG, which silently removes that boundary\n"
        . "  rather than failing loudly. Remove the bound; if site genuinely\n"
        . "  cannot work on a newer FOG, that needs fixing, not fencing off.\n"
    );
    exit(1);
}

echo "ok  site declares no fog_max (fog_min = '"
    . ($fog_plugin['fog_min'] ?? '') . "')\n";
exit(0);
