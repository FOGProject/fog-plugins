<?php
/**
 * Every bare class reference must resolve from the namespace it sits in.
 *
 *   tests/references-resolve.test.php
 *
 * Sibling gate to core-references-are-qualified.test.php, and it exists
 * because that one cannot see this failure. That test asks "does this tree
 * declare a class of this name?" -- a syntax-level question that was
 * sufficient while a plugin was ONE namespace, because every class in the
 * plugin was reachable bare from every other.
 *
 * Bucketing ended that. `class NtfyHandler extends Ntfy` sat in one namespace
 * and now spans two: the handler is FOG\Plugins\Ntfy\Util\NtfyHandler and the
 * model is FOG\Plugins\Ntfy\Items\Ntfy. The bare reference still names a class
 * this tree declares, so the older gate stays green -- and PHP resolves it to
 * FOG\Plugins\Ntfy\Util\Ntfy, which does not exist. It is not a parse error
 * either: `php -l` is happy, and the failure is a fatal at the moment the
 * class is first touched, which for a hook is mid-request on a live server.
 *
 * So the question here is the one PHP itself asks: given this file's
 * namespace and its `use` imports, does this name resolve to something?
 *
 * Resolution order implemented, matching PHP:
 *   1. a leading \ -- fully qualified, checked against core + this tree
 *   2. an exact `use` import of that short name (or an aliased one)
 *   3. the file's own namespace
 *   4. the global namespace, for PHP's own classes only
 *
 * Core's FQCNs are read from a fogproject checkout when one is beside this
 * repository, exactly as bin/qualify-core-references.php does. Without one,
 * core names are taken on trust and only intra-plugin resolution is checked --
 * which is the half this test was written for, so it still earns its keep on
 * a bare clone.
 *
 * Usage: php tests/references-resolve.test.php
 * Exit status 0 = pass, 1 = fail.
 */

$root = dirname(__DIR__);

// A floor. Without one, a change to the layout that makes the walk match
// nothing reports "ok, 0 files" and passes -- which is how the test it
// replaces would have died silently.
const MIN_FILES = 150;

/**
 * Class-shaped names PHP itself declares, so a bare use of one is correct.
 *
 * get_declared_classes() in this process is the honest source: nothing of
 * FOG's is loaded here, so everything it lists is internal.
 */
$builtin = [];
foreach (array_merge(
    get_declared_classes(),
    get_declared_interfaces(),
    get_declared_traits()
) as $c) {
    if (strpos($c, '\\') === false) {
        $builtin[strtolower($c)] = true;
    }
}

/** Core FQCNs, if a fogproject checkout is beside us. */
$coreShort = [];
foreach ([dirname($root) . '/fogproject/packages/web/src'] as $src) {
    if (!is_dir($src)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
            $coreShort[strtolower($f->getBasename('.php'))] = true;
        }
    }
}

/** Every class this tree declares, as lowercased FQCN. */
$declared = [];
$files = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
foreach ($it as $f) {
    $path = $f->getPathname();
    if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') {
        continue;
    }
    foreach (['/tests/', '/bin/', '/.git/', '/vendor/'] as $skip) {
        if (strpos($path, $skip) !== false) {
            continue 2;
        }
    }
    $src = (string) file_get_contents($path);
    if (!preg_match('#^namespace\s+([^;]+);#m', $src, $m)) {
        continue;   // config/plugin.config.php and index.php declare nothing
    }
    $ns = trim($m[1]);
    if (!preg_match(
        '#^\s*(?:final\s+|abstract\s+)*(?:class|interface|trait)\s+'
        . '([A-Za-z0-9_]+)#m',
        $src,
        $c
    )) {
        continue;
    }
    $declared[strtolower($ns . '\\' . $c[1])] = true;
    $files[] = [$path, $ns, $src];
}

$failures = [];

foreach ($files as list($path, $ns, $src)) {
    $rel = substr($path, strlen($root) + 1);
    $tokens = token_get_all($src);
    $n = count($tokens);

    // `use A\B\C;` and `use A\B\C as D;` -- the short name this file can say.
    $imports = [];
    for ($i = 0; $i < $n; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_USE) {
            continue;
        }
        // A closure's `use ($x)` is not an import.
        $j = $i + 1;
        while ($j < $n && is_array($tokens[$j])
            && $tokens[$j][0] === T_WHITESPACE
        ) {
            $j++;
        }
        if ($j < $n && $tokens[$j] === '(') {
            continue;
        }
        $buf = '';
        for (; $j < $n; $j++) {
            if ($tokens[$j] === ';' || $tokens[$j] === '{') {
                break;
            }
            $buf .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
        }
        $buf = trim($buf);
        if ($buf === '' || strpos($buf, '\\') === false) {
            continue;   // a trait `use`, not an import
        }
        if (preg_match('#^(.+?)\s+as\s+([A-Za-z0-9_]+)$#i', $buf, $a)) {
            $imports[strtolower($a[2])] = trim($a[1]);
            continue;
        }
        $parts = explode('\\', $buf);
        $imports[strtolower(end($parts))] = $buf;
    }

    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (!is_array($t) || $t[0] !== T_STRING) {
            continue;
        }
        $name = $t[1];
        $lower = strtolower($name);

        // Preceding significant token decides whether this is a class
        // reference at all.
        for ($p = $i - 1; $p >= 0; $p--) {
            if (is_array($tokens[$p])
                && in_array(
                    $tokens[$p][0],
                    [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT],
                    true
                )
            ) {
                continue;
            }
            break;
        }
        $prev = $p >= 0 ? $tokens[$p] : null;
        if (is_array($prev)
            && in_array(
                $prev[0],
                [
                    T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST,
                    T_NS_SEPARATOR, T_USE, T_NAMESPACE, T_CLASS, T_INTERFACE,
                    T_TRAIT, T_GOTO,
                ],
                true
            )
        ) {
            continue;
        }
        if ($prev === '$') {
            continue;
        }

        // Following token tells a class reference from a plain function call.
        for ($q = $i + 1; $q < $n; $q++) {
            if (is_array($tokens[$q]) && $tokens[$q][0] === T_WHITESPACE) {
                continue;
            }
            break;
        }
        $next = $q < $n ? $tokens[$q] : null;
        $isClassRef = false;
        if (is_array($next) && $next[0] === T_DOUBLE_COLON) {
            $isClassRef = true;   // Foo::BAR
        }
        if (is_array($prev)
            && in_array($prev[0], [T_NEW, T_EXTENDS, T_IMPLEMENTS, T_INSTANCEOF], true)
        ) {
            $isClassRef = true;
        }
        if (!$isClassRef) {
            continue;
        }

        // Now resolve it the way PHP would.
        if (isset($imports[$lower])) {
            continue;
        }
        if (isset($declared[strtolower($ns . '\\' . $name)])) {
            continue;
        }
        if (isset($builtin[$lower])) {
            continue;
        }
        // `self`, `static`, `parent` reach here as T_STRING on some versions.
        if (in_array($lower, ['self', 'static', 'parent'], true)) {
            continue;
        }
        // A core class named bare is the OTHER gate's business
        // (core-references-are-qualified), and it reports it far better than
        // a duplicate complaint here would.
        if (isset($coreShort[$lower])) {
            continue;
        }
        $failures[] = sprintf(
            '%s:%d  %s does not resolve from namespace %s '
            . '(add: use <FQCN>;)',
            $rel,
            $t[2],
            $name,
            $ns
        );
    }
}

if (count($files) < MIN_FILES) {
    fwrite(
        STDERR,
        sprintf(
            "FAIL: only %d class file(s) found, expected at least %d -- the "
            . "walk is broken, not the tree\n",
            count($files),
            MIN_FILES
        )
    );
    exit(1);
}

if ($failures) {
    fwrite(
        STDERR,
        sprintf("FAIL: %d unresolvable reference(s)\n", count($failures))
    );
    foreach ($failures as $f) {
        fwrite(STDERR, "  - $f\n");
    }
    exit(1);
}

fwrite(
    STDOUT,
    sprintf(
        "ok  %d class file(s), every bare reference resolves\n",
        count($files)
    )
);
exit(0);
