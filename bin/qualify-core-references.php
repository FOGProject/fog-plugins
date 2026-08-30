<?php
/**
 * Rewrites bare references to FOG core classes into fully qualified ones.
 *
 * Plugins are global-namespace by design (ADR 0009) and have always named
 * core classes bare -- `extends Hook`, `Route::listem()`, `new Image()`.
 * Those resolve only because every file under fogproject's packages/web/src/
 * ends in a class_alias() re-exporting itself globally, and that alias set is
 * being retired (fogproject docs/composer-psr4-plan.md, ADR 0013 §2).
 *
 * This qualifies them: `extends \FOG\Base\Hook`, `\FOG\Router\Route::listem()`.
 * The plugin stays in the global namespace -- only the names it reaches into
 * core with change.
 *
 * The map is read from a fogproject checkout rather than hardcoded, because
 * the bucket a class lives in is fogproject's to decide and a stale copy here
 * would rewrite names to somewhere they are not.
 *
 * Tokenised, never regex: a class name in a docblock, a string or a comment
 * must not be rewritten, and `$obj->Route` is not a class reference.
 *
 * Names the plugin tree itself declares are skipped even when core has one of
 * the same name -- a plugin's own class always wins for its own references.
 *
 * Usage:
 *   php bin/qualify-core-references.php --core=/path/to/fogproject [--fix]
 *
 * Without --fix it reports and changes nothing. Exit 1 if anything is left to
 * do (so it doubles as a check), 0 when the tree is clean.
 */

$opts = getopt('', ['core:', 'fix']);
$core = $opts['core'] ?? '/home/telliott/fogproject';
$fix  = isset($opts['fix']);
$srcRoot = rtrim($core, '/') . '/packages/web/src';
if (!is_dir($srcRoot)) {
    fwrite(STDERR, "no fogproject src/ at $srcRoot -- pass --core=/path/to/fogproject\n");
    exit(2);
}

/**
 * Core: lowercased short name => the name to write, WITHOUT a leading
 * backslash (the rewrite adds one).
 *
 * Three sources, because core does not keep all its classes in one place and
 * the src/ map alone silently misses two kinds of reference:
 *
 *  - packages/web/src/       PSR-4, so the path IS the name.
 *  - packages/web/lib/       the 46 discovery-named classes, whose filenames
 *                            are a contract with FOGPageManager and cannot be
 *                            PSR-4. They sit in the flat FOG namespace, so a
 *                            plugin report extends FOG\ReportManagement.
 *  - packages/web/commons/   Initiator, which is genuinely global-namespace
 *                            and stays that way; qualifying it is just a
 *                            leading backslash.
 *
 * Only the first group's aliases are being retired, but the other two are
 * bare names resolving by luck of the global namespace, and the test that
 * gates this cannot tell the three apart -- nor should it have to.
 */
$core = [];
$walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcRoot));
foreach ($walk as $file) {
    if ($file->isFile() && 'php' === $file->getExtension()) {
        $short = $file->getBasename('.php');
        $core[strtolower($short)] = 'FOG\\' . basename(dirname($file->getPathname())) . '\\' . $short;
    }
}
$webRoot = dirname($srcRoot);
// The lib/{pages,hooks,reports,events} pass that used to run here is gone
// with the directories it read. Those 52 classes were the last holdouts in
// the flat `namespace FOG;`, kept there because discovery derived their name
// from the filename; they are PSR-4 files under src/{Pages,Hooks,Reports,
// Events} now, so the src/ walk above already maps them -- and maps them to
// the bucketed FQCN a plugin actually has to write. Keeping the pass would
// have been worse than redundant: it ran AFTER the walk and overwrote each
// entry with the flat `FOG\<Class>` spelling, which no longer resolves.
foreach (glob($webRoot . '/commons/*.php') as $path) {
    if (preg_match_all(
        '/^\\s*(?:final\\s+|abstract\\s+)*class\\s+(\\w+)/mi',
        file_get_contents($path),
        $m
    )) {
        foreach ($m[1] as $name) {
            // Global namespace, and staying there -- the leading backslash
            // the rewrite adds is the whole change.
            $core[strtolower($name)] = $name;
        }
    }
}

$root = dirname(__DIR__);

/** Every class this tree declares. A plugin's own name always wins. */
$own = [];
$walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($walk as $file) {
    // tests/stubs is excluded here as well as from the rewrite. It declares
    // test doubles NAMED for core classes -- FOGController, Schema -- and
    // counting those as the tree's own would make every reference to them
    // look like a plugin's own class and skip it. That is not hypothetical:
    // the first run of this tool rewrote 313 references and silently left
    // ~90 behind for exactly that reason. The strict stubs caught it.
    if (!$file->isFile() || 'php' !== $file->getExtension()
        || false !== strpos($file->getPathname(), '/.git/')
        || false !== strpos($file->getPathname(), '/tests/stubs/')
    ) {
        continue;
    }
    if (preg_match_all(
        '/^\s*(?:final\s+|abstract\s+)*(?:class|interface|trait)\s+(\w+)/mi',
        file_get_contents($file->getPathname()),
        $m
    )) {
        foreach ($m[1] as $name) {
            $own[strtolower($name)] = true;
        }
    }
}

$skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
$changedFiles = 0;
$changedRefs = 0;
$report = [];

$walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($walk as $file) {
    $path = $file->getPathname();
    if (!$file->isFile() || 'php' !== $file->getExtension()
        || false !== strpos($path, '/.git/')
        || false !== strpos($path, '/tests/stubs/')
    ) {
        continue;
    }
    $src = file_get_contents($path);
    $tokens = token_get_all($src);
    $count = count($tokens);
    $out = '';
    $hits = 0;
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (!is_array($token) || T_STRING !== $token[0]) {
            $out .= is_array($token) ? $token[1] : $token;
            continue;
        }
        $name = $token[1];
        $key = strtolower($name);
        if (!isset($core[$key]) || isset($own[$key])) {
            $out .= $name;
            continue;
        }
        // Neighbours, whitespace and comments skipped.
        $prev = null;
        for ($j = $i - 1; $j >= 0; $j--) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], $skip, true)) {
                continue;
            }
            $prev = $tokens[$j];
            break;
        }
        $next = null;
        for ($j = $i + 1; $j < $count; $j++) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], $skip, true)) {
                continue;
            }
            $next = $tokens[$j];
            break;
        }
        // Already qualified, or a member/function/const name rather than a
        // class reference.
        $isQualified = is_array($prev)
            && in_array($prev[0], [T_NS_SEPARATOR, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST], true);
        $followsSeparator = is_array($next) && T_NS_SEPARATOR === $next[0];
        $isClassRef = (is_array($next) && T_DOUBLE_COLON === $next[0])
            || (is_array($prev) && in_array($prev[0], [T_NEW, T_EXTENDS, T_IMPLEMENTS, T_INSTANCEOF], true));
        if ($isQualified || $followsSeparator || !$isClassRef) {
            $out .= $name;
            continue;
        }
        $out .= '\\' . $core[$key];
        $hits++;
    }
    if (!$hits) {
        continue;
    }
    $rel = str_replace($root . '/', '', $path);
    $report[] = sprintf('%-58s %d', $rel, $hits);
    $changedFiles++;
    $changedRefs += $hits;
    if ($fix) {
        file_put_contents($path, $out);
    }
}

sort($report);
echo implode("\n", $report) . "\n";
printf(
    "%s: %d reference(s) in %d file(s)\n",
    $fix ? 'rewrote' : 'would rewrite',
    $changedRefs,
    $changedFiles
);
exit($changedRefs && !$fix ? 1 : 0);
