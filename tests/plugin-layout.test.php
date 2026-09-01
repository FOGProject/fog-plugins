<?php
/**
 * A plugin is laid out exactly the way core is.
 *
 *   tests/plugin-layout.test.php
 *
 * One rule, in two halves:
 *
 *   <plugin>/src/<Bucket>/<Class>.php   declares
 *   namespace FOG\Plugins\<Segment>\<Bucket>;   class <Class>
 *
 *   strtolower(<Segment>) === <plugin>
 *
 * That is the whole contract, and it is the same one `packages/web/src/`
 * follows in fogproject: `src/Pages/HostManagement.php` declares
 * `FOG\Pages\HostManagement`. Core discovers its own classes by walking a
 * bucket directory (`FOGBase::coreitems()`), and since this layout landed it
 * discovers a plugin's the same way -- so a file in the wrong place is not a
 * style complaint, it is a hook that never registers or a page that 404s.
 *
 * The second half is what lets the autoloader DERIVE a path from a class name
 * instead of scanning every file to read its namespace. `FOG\Plugins\LDAP\
 * Managers\LDAPManager` is `<root>/ldap/src/Managers/LDAPManager.php` and
 * nothing has to be read to know it. That derivation is only sound while
 * strtolower(Segment) matches the directory, which is why it is asserted here
 * rather than assumed: the directory name is also plugins.pName, the ?node=
 * value and the `ldap.view` permission string, so it cannot move, and the
 * segment is free to read properly (`LDAP`, not `Ldap`).
 *
 * WHAT COUNTS AS A PLUGIN DIRECTORY: deliberately not a hardcoded list, for
 * the reason core-references-are-qualified.test.php gives for not hardcoding
 * core's class list -- this repository is fetched on its own and a list here
 * would drift. Every top-level directory except the tooling ones is a plugin.
 *
 * Case-SENSITIVE on the class name, unlike the namespace gate this replaces.
 * PHP does not care, but the derivation above does: on a case-sensitive
 * filesystem `LDAPManager.php` and `Ldapmanager.php` are different files, and
 * only one of them is the one core will open.
 *
 * Also asserted: no `class_alias()` (ADR 0013 §2 retired it from core and a
 * plugin does not get to reintroduce it), and no leftover pre-1.6 directory
 * -- `class/`, `pages/`, `hooks/`, `events/`, `reports/`, `tasks/`,
 * `reg-task/` are all gone, so a half-migrated plugin fails loudly here
 * rather than half-loading on a server.
 *
 * Usage: php tests/plugin-layout.test.php
 * Exit status 0 = pass, 1 = fail.
 */

$root = dirname(__DIR__);

// A floor, and the reason this file is a rewrite rather than an edit. The
// gate it replaces matched on the six discovery suffixes with no minimum, so
// the moment those filenames went away it matched nothing, printed
// "ok: 0 plugin class file(s)" and exited 0 -- green, and testing nothing.
const MIN_FILES = 150;
const MIN_PLUGINS = 14;

const LEGACY_DIRS = [
    'class', 'pages', 'hooks', 'events', 'reports', 'tasks', 'reg-task',
];

$tooling = ['tests', 'bin', '.git', '.github'];

$failures = [];
$checked = 0;
$plugins = 0;

foreach (scandir($root) as $entry) {
    if ($entry[0] === '.' || in_array($entry, $tooling, true)) {
        continue;
    }
    $pdir = $root . '/' . $entry;
    if (!is_dir($pdir)) {
        continue;
    }
    $plugins++;

    foreach (LEGACY_DIRS as $legacy) {
        if (is_dir($pdir . '/' . $legacy)) {
            $failures[] = sprintf(
                '%s/%s/ still exists -- pre-1.6 layout, its classes are '
                . 'invisible to core',
                $entry,
                $legacy
            );
        }
    }

    $srcDir = $pdir . '/src';
    if (!is_dir($srcDir)) {
        // capone has no tasks, but every plugin has SOMETHING, so an
        // absent src/ is a broken plugin.
        $failures[] = sprintf('%s has no src/ directory', $entry);
        continue;
    }

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') {
            continue;
        }
        $path = $f->getPathname();
        $rel = substr($path, strlen($root) + 1);
        $checked++;

        $bucket = basename(dirname($path));
        $class = $f->getBasename('.php');
        $src = (string) file_get_contents($path);

        // Depth: exactly <plugin>/src/<Bucket>/<Class>.php. A file directly
        // in src/, or nested a level deeper, has no derivable namespace.
        if (dirname(dirname($path)) !== $srcDir) {
            $failures[] = sprintf(
                '%s is not <plugin>/src/<Bucket>/<Class>.php',
                $rel
            );
            continue;
        }

        preg_match_all('#^namespace\s+([^;]+);#m', $src, $nsAll);
        if (count($nsAll[1]) !== 1) {
            $failures[] = sprintf(
                '%s declares %d namespaces, expected exactly 1',
                $rel,
                count($nsAll[1])
            );
            continue;
        }
        $ns = trim($nsAll[1][0]);

        $parts = explode('\\', $ns);
        if (count($parts) !== 4
            || $parts[0] !== 'FOG'
            || $parts[1] !== 'Plugins'
        ) {
            $failures[] = sprintf(
                '%s declares %s, expected FOG\\Plugins\\<Segment>\\<Bucket>',
                $rel,
                $ns
            );
            continue;
        }
        list(, , $segment, $nsBucket) = $parts;

        if (strtolower($segment) !== $entry) {
            $failures[] = sprintf(
                '%s: namespace segment %s does not lowercase to the plugin '
                . 'directory %s -- the autoloader derives the path from it',
                $rel,
                $segment,
                $entry
            );
        }
        if ($nsBucket !== $bucket) {
            $failures[] = sprintf(
                '%s: namespace says bucket %s, path says %s',
                $rel,
                $nsBucket,
                $bucket
            );
        }

        preg_match_all(
            '#^\s*(?:final\s+|abstract\s+)*(?:class|interface|trait)\s+'
            . '([A-Za-z0-9_]+)#m',
            $src,
            $decl
        );
        if (count($decl[1]) !== 1) {
            $failures[] = sprintf(
                '%s declares %d classes, expected exactly 1',
                $rel,
                count($decl[1])
            );
            continue;
        }
        if ($decl[1][0] !== $class) {
            $failures[] = sprintf(
                '%s declares %s -- the file name and the class name must '
                . 'match exactly, including case',
                $rel,
                $decl[1][0]
            );
        }

        if (strpos($src, 'class_alias') !== false) {
            $failures[] = sprintf(
                '%s calls class_alias() -- retired with ADR 0013 §2',
                $rel
            );
        }
    }
}

if ($checked < MIN_FILES || $plugins < MIN_PLUGINS) {
    fwrite(
        STDERR,
        sprintf(
            "FAIL: walked %d file(s) in %d plugin(s); expected at least "
            . "%d and %d -- the walk is broken, not the tree\n",
            $checked,
            $plugins,
            MIN_FILES,
            MIN_PLUGINS
        )
    );
    exit(1);
}

if ($failures) {
    fwrite(STDERR, sprintf("FAIL: %d problem(s)\n", count($failures)));
    foreach ($failures as $f) {
        fwrite(STDERR, "  - $f\n");
    }
    exit(1);
}

fwrite(
    STDOUT,
    sprintf(
        "ok  %d class file(s) across %d plugins, all PSR-4\n",
        $checked,
        $plugins
    )
);
exit(0);
