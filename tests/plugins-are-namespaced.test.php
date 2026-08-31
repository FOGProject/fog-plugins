<?php
/**
 * Every plugin class/interface/trait file declares its plugin's namespace.
 *
 * The design (see the fog-plugins namespacing task) is one mechanical rule:
 * a class file at `<plugin>/<subdir>/<name>.<type>.php` declares
 * `namespace FOG\Plugins\<Ucfirst-plugin-directory-name>;` -- ucfirst() of
 * the plugin's directory name, nothing else. The subdirectory (class/,
 * pages/, hooks/, events/, reports/, tasks/, or an odd one like capone's
 * reg-task/) is not part of it; every class in a plugin shares one flat
 * namespace.
 *
 * This mirrors core's own rule (fogproject's PSR-4 buckets) closely enough
 * that a plugin author can lean on the same instinct, but plugins are NOT
 * PSR-4 mapped -- there is no composer.json or autoloader config here to
 * enforce it structurally, so a gate is the only thing that catches a new
 * plugin file landing bare or a copy-pasted file carrying its donor's
 * namespace.
 *
 * WHAT COUNTS AS A CLASS FILE: the same six extensions the fetch/deploy
 * tooling already treats as plugin source -- *.class.php, *.hook.php,
 * *.page.php, *.event.php, *.report.php, *.task.php -- found anywhere under
 * a plugin directory, subdirectory name irrelevant. config/plugin.config.php
 * (a $fog_plugin array, no class) is deliberately excluded by extension
 * alone, not by name, so a plugin that ever adds a second config-shaped file
 * is not silently exempted.
 *
 * WHAT COUNTS AS A "PLUGIN DIRECTORY": deliberately NOT a hardcoded list,
 * for the same reason core-references-are-qualified.test.php gives for not
 * hardcoding core's class list -- this repository is fetched on its own
 * (bin/fetch-plugins.sh) and a list here would drift as plugins are added or
 * removed. Every top-level directory except the tooling ones (tests/, bin/,
 * .github/, .git/) is treated as a plugin directory, matching how the repo
 * is actually laid out (see README.md / bin/fetch-plugins.sh).
 *
 * The namespace comparison is case-INSENSITIVE, deliberately -- PHP
 * namespaces are themselves case-insensitive, and a third-party plugin
 * author may reasonably spell a namespace segment differently from a strict
 * ucfirst() of their directory name (e.g. `MyPlugin` for directory
 * `myplugin`). Getting the case slightly "wrong" is not the bug this gate
 * exists to catch; declaring no namespace, the WRONG plugin's namespace, or
 * more than one namespace is.
 *
 * Also asserted, same rule as core: no plugin file may declare
 * class_alias() -- the compatibility mechanism ADR 0013 §2 is retiring from
 * core is not something a plugin gets to reintroduce on its own.
 *
 * Usage: php tests/plugins-are-namespaced.test.php
 * Exit status 0 = pass, 1 = fail.
 *
 * PHP version 7.4+
 *
 * @category Tests
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

$root = dirname(__DIR__);
$fails = [];
$checked = 0;

$skipTopLevel = ['tests', 'bin', '.github', '.git'];

$pluginDirs = [];
foreach (scandir($root) as $entry) {
    if ('.' === $entry || '..' === $entry) {
        continue;
    }
    if (in_array($entry, $skipTopLevel, true)) {
        continue;
    }
    if (!is_dir($root . '/' . $entry)) {
        continue;
    }
    $pluginDirs[] = $entry;
}
sort($pluginDirs);

/**
 * Every `namespace ...;` declaration in a file, and every class_alias()
 * call, found by walking the token stream rather than by regex -- a
 * docblock or a string literal can contain the word "namespace" or
 * "class_alias" without either being code.
 *
 * @param string $path file to scan
 *
 * @return array{namespaces: string[], hasClassAlias: bool}
 */
function scanFile($path)
{
    $namespaces = [];
    $hasClassAlias = false;
    $tokens = token_get_all(file_get_contents($path));
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tokens[$i])) {
            continue;
        }
        if (T_NAMESPACE === $tokens[$i][0]) {
            // PHP 8 folds FOG\Plugins\Ldap into a single T_NAME_QUALIFIED
            // token; 7.4 emits T_STRING/T_NS_SEPARATOR per segment. Handle
            // both, same reasoning as core-references-are-qualified's
            // T_NAME_FULLY_QUALIFIED handling for FQCNs.
            $name = '';
            for ($j = $i + 1; $j < $count; $j++) {
                if (is_array($tokens[$j])
                    && in_array(
                        $tokens[$j][0],
                        array_filter([
                            T_STRING,
                            T_NS_SEPARATOR,
                            defined('T_NAME_QUALIFIED') ? T_NAME_QUALIFIED : null,
                        ], static function ($t) {
                            return null !== $t;
                        }),
                        true
                    )
                ) {
                    $name .= $tokens[$j][1];
                    continue;
                }
                if (is_array($tokens[$j]) && T_WHITESPACE === $tokens[$j][0]) {
                    continue;
                }
                break;
            }
            $namespaces[] = $name;
            continue;
        }
        if (T_STRING === $tokens[$i][0]
            && 0 === strcasecmp($tokens[$i][1], 'class_alias')
        ) {
            for ($j = $i + 1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && T_WHITESPACE === $tokens[$j][0]) {
                    continue;
                }
                if ('(' === $tokens[$j]) {
                    $hasClassAlias = true;
                }
                break;
            }
        }
    }
    return ['namespaces' => $namespaces, 'hasClassAlias' => $hasClassAlias];
}

foreach ($pluginDirs as $dir) {
    $expected = 'fog\\plugins\\' . strtolower(ucfirst($dir));
    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/' . $dir)
    );
    foreach ($walk as $file) {
        $path = $file->getPathname();
        if (!$file->isFile()) {
            continue;
        }
        if (!preg_match(
            '/\.(class|hook|page|event|report|task)\.php$/',
            $path
        )) {
            continue;
        }
        $rel = str_replace($root . '/', '', $path);
        $checked++;
        $scan = scanFile($path);

        if (0 === count($scan['namespaces'])) {
            $fails[] = "$rel: declares no namespace (expected $expected)";
            continue;
        }
        if (count($scan['namespaces']) > 1) {
            $fails[] = sprintf(
                '%s: declares %d namespaces (%s), expected exactly one (%s)',
                $rel,
                count($scan['namespaces']),
                implode(', ', $scan['namespaces']),
                $expected
            );
            continue;
        }
        $actual = strtolower($scan['namespaces'][0]);
        if ($actual !== $expected) {
            $fails[] = sprintf(
                "%s: declares namespace '%s', expected '%s' (case-insensitive)",
                $rel,
                $scan['namespaces'][0],
                $expected
            );
        }
        if ($scan['hasClassAlias']) {
            $fails[] = "$rel: declares class_alias() -- not permitted in a "
                . 'namespaced plugin (fogproject ADR 0013 §2)';
        }
    }
}

if (count($fails)) {
    fwrite(STDERR, 'FAIL:' . PHP_EOL);
    foreach (array_slice($fails, 0, 25) as $fail) {
        fwrite(STDERR, "  - $fail\n");
    }
    if (count($fails) > 25) {
        fwrite(STDERR, '  ... and ' . (count($fails) - 25) . " more\n");
    }
    exit(1);
}

printf(
    "ok: %d plugin class file(s) across %d plugin director(ies), each "
    . "namespaced FOG\\Plugins\\<Plugin> with no class_alias()\n",
    $checked,
    count($pluginDirs)
);
exit(0);
