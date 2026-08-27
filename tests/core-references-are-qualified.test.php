<?php
/**
 * Every class a plugin reaches for that it does not declare itself must be
 * fully qualified.
 *
 * Plugins are global-namespace by design (fogproject ADR 0009) and have
 * always named FOG core classes bare -- `extends Hook`, `Route::listem()`,
 * `new Image()`. That worked only because every file under fogproject's
 * packages/web/src/ ended in a class_alias() re-exporting itself into the
 * global namespace, and that alias set is being retired (ADR 0013 §2). A bare
 * name resolves to nothing once it goes, and the failure is a fatal on the
 * page or hook that uses it.
 *
 * The invariant is deliberately expressed WITHOUT a copy of core's class
 * list. This repository is fetched on its own by bin/fetch-plugins.sh and
 * cannot assume a fogproject checkout is anywhere nearby, and a hardcoded
 * list here would drift from the buckets core actually declares. So the rule
 * is the self-contained one: a name this tree does not declare, and that PHP
 * does not already have, has to carry its namespace.
 *
 * bin/qualify-core-references.php is the tool that does the rewriting; this
 * is the gate that says it was run. The tool needs a fogproject checkout,
 * which CI does not have -- this does not.
 *
 * Usage: php tests/core-references-are-qualified.test.php
 * Exit status 0 = pass, 1 = fail.
 */

$root = dirname(__DIR__);
$fails = [];

/**
 * Every class this tree declares.
 *
 * tests/stubs is excluded, and that exclusion is load-bearing rather than
 * tidiness: the stubs declare test doubles NAMED for core classes, so
 * counting them here would make FOGController, FOGManagerController and
 * Schema look like the tree's own and wave through every bare reference to
 * them. The first run of the rewriting tool had exactly that bug and left
 * ~90 references behind.
 */
$own = [];
$walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($walk as $file) {
    $path = $file->getPathname();
    if (!$file->isFile() || 'php' !== $file->getExtension()
        || false !== strpos($path, '/.git/')
        || false !== strpos($path, '/tests/stubs/')
    ) {
        continue;
    }
    if (preg_match_all(
        '/^\s*(?:final\s+|abstract\s+)*(?:class|interface|trait)\s+(\w+)/mi',
        file_get_contents($path),
        $m
    )) {
        foreach ($m[1] as $name) {
            $own[strtolower($name)] = true;
        }
    }
}

$skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
$checked = 0;
$qualified = 0;
$walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($walk as $file) {
    $path = $file->getPathname();
    if (!$file->isFile() || 'php' !== $file->getExtension()
        || false !== strpos($path, '/.git/')
        || false !== strpos($path, '/tests/')
        || false !== strpos($path, '/bin/')
    ) {
        continue;
    }
    $rel = str_replace($root . '/', '', $path);
    $tokens = token_get_all(file_get_contents($path));
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tokens[$i])) {
            continue;
        }
        // PHP 8 folds \Foo\Bar into ONE T_NAME_FULLY_QUALIFIED token; 7.4
        // emits T_NS_SEPARATOR + T_STRING per segment. This repository is
        // tested on both, so count the 8.x form here and let the 7.4 form
        // fall through to the T_NS_SEPARATOR check below. Either way an
        // already-qualified name never reaches the bare-name test.
        if (defined('T_NAME_FULLY_QUALIFIED') && T_NAME_FULLY_QUALIFIED === $tokens[$i][0]) {
            $qualified++;
            continue;
        }
        if (T_STRING !== $tokens[$i][0]) {
            continue;
        }
        $name = $tokens[$i][1];
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
        // class reference. Whitespace is skipped in both directions because
        // `new Foo` carries a T_WHITESPACE between the two tokens.
        if (is_array($prev)
            && in_array($prev[0], [T_NS_SEPARATOR, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST], true)
        ) {
            if (T_NS_SEPARATOR === $prev[0]) {
                $qualified++;
            }
            continue;
        }
        if (is_array($next) && T_NS_SEPARATOR === $next[0]) {
            continue;
        }
        $isClassRef = (is_array($next) && T_DOUBLE_COLON === $next[0])
            || (is_array($prev) && in_array($prev[0], [T_NEW, T_EXTENDS, T_IMPLEMENTS, T_INSTANCEOF], true));
        if (!$isClassRef) {
            continue;
        }
        // self/parent/static are scope keywords, not class names, and PHP
        // hands them back as T_STRING like anything else.
        if (in_array(strtolower($name), ['self', 'parent', 'static'], true)) {
            continue;
        }
        $checked++;
        if (isset($own[strtolower($name)])) {
            continue;
        }
        // A PHP built-in or an extension class, reached by its real global
        // name -- \Exception, \PDO, \DateTime. Those genuinely live in the
        // global namespace and are not core's to qualify.
        if (class_exists($name) || interface_exists($name)) {
            continue;
        }
        $fails[] = sprintf(
            '%s:%d references %s bare. This tree does not declare it and PHP '
            . 'does not have it, so it is a FOG core class resolving through '
            . 'a compatibility alias that is being retired -- qualify it '
            . '(run bin/qualify-core-references.php --core=/path/to/fogproject '
            . '--fix)',
            $rel,
            $tokens[$i][2],
            $name
        );
    }
}

$fails = array_values(array_unique($fails));
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
    "ok: %d qualified reference(s), %d bare one(s) checked; every name this "
    . "tree does not declare carries its namespace\n",
    $qualified,
    $checked
);
exit(0);
