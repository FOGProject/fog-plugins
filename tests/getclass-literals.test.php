<?php
/**
 * A plugin may not call getClass() with a string literal.
 *
 * `self::getClass('LDAPGroupManager')` used to be the documented way for a
 * plugin to reach a class without spelling its namespace, and it still
 * resolves -- core's FOGBase::qualify() is unchanged. It is refused anyway,
 * for the reason fogproject ADR 0043 gives: getClass() is declared
 * `@return object|mixed`, so nothing can check what you then do with the
 * result and no editor can follow it to a definition. Converting core's 459
 * literal sites surfaced 90 PHPStan errors on a baseline that reported zero,
 * every one of them a pre-existing annotation that had drifted from its body.
 *
 * Write `new \FOG\Plugins\LDAP\Managers\LDAPGroupManager()` instead. Fully
 * qualified rather than imported, because tests/core-references-are-qualified
 * .test.php in this repository refuses a bare core name outright.
 *
 * Two forms survive, because neither has a `new` equivalent:
 *
 *   - `getClass('X', '', true)` returns ReflectionClass::getDefaultProperties()
 *     rather than an instance;
 *   - `getClass('ReflectionClass', ...)`, which the factory special-cases.
 *
 * And getClass() with a VARIABLE is untouched -- that is the one shape `new`
 * cannot express, and it is what the function is for now.
 *
 * Token-based on purpose. `getClass(` appears inside strings, docblocks and
 * commented-out code in this tree, and a grep would report every one of them.
 *
 * Usage: php tests/getclass-literals.test.php
 * Exit status 0 = pass, 1 = fail.
 */

$root = dirname(__DIR__);
chdir($root);

/*
 * Below this and the scan is not scanning -- a bad file filter or a broken
 * tokenizer loop would otherwise report a clean pass over nothing.
 */
const MIN_FILES = 100;

$files = array_values(array_filter(
    explode("\n", (string) shell_exec('git ls-files "*.php"')),
    function ($f) {
        return '' !== $f && is_readable($f);
    }
));

$banned = [];
$scanned = 0;

foreach ($files as $file) {
    $src = file_get_contents($file);
    $scanned++;
    if (false === strpos($src, 'getClass')) {
        continue;
    }
    $tokens = token_get_all($src);
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (!is_array($token)
            || T_STRING !== $token[0]
            || 'getClass' !== $token[1]
        ) {
            continue;
        }
        // A call, not a declaration and not a word inside a string: the
        // previous significant token has to be `::`.
        $p = $i - 1;
        while ($p >= 0
            && is_array($tokens[$p])
            && T_WHITESPACE === $tokens[$p][0]
        ) {
            $p--;
        }
        if ($p < 0
            || !is_array($tokens[$p])
            || T_DOUBLE_COLON !== $tokens[$p][0]
        ) {
            continue;
        }
        $j = $i + 1;
        while ($j < $count
            && is_array($tokens[$j])
            && T_WHITESPACE === $tokens[$j][0]
        ) {
            $j++;
        }
        if (!isset($tokens[$j]) || '(' !== $tokens[$j]) {
            continue;
        }
        $k = $j + 1;
        while ($k < $count
            && is_array($tokens[$k])
            && T_WHITESPACE === $tokens[$k][0]
        ) {
            $k++;
        }
        if (!isset($tokens[$k])
            || !is_array($tokens[$k])
            || T_CONSTANT_ENCAPSED_STRING !== $tokens[$k][0]
        ) {
            // A variable. This is what getClass() is for.
            continue;
        }
        $name = trim($tokens[$k][1], "'\"");
        if ('reflectionclass' === strtolower($name)) {
            continue;
        }
        // Count the arguments; a third one asks for the default properties.
        $depth = 0;
        $argc = 1;
        $third = '';
        for ($m = $j; $m < $count; $m++) {
            $tok = $tokens[$m];
            if (is_string($tok) && false !== strpos('([{', $tok)) {
                $depth++;
                continue;
            }
            if (is_string($tok) && false !== strpos(')]}', $tok)) {
                if (0 === --$depth) {
                    break;
                }
                continue;
            }
            if (1 === $depth && ',' === $tok) {
                $argc++;
                continue;
            }
            if (3 === $argc && is_array($tok) && T_WHITESPACE !== $tok[0]) {
                $third .= $tok[1];
            }
        }
        if (3 === $argc && 'true' === strtolower(trim($third))) {
            continue;
        }
        $banned[] = [$name, $file . ':' . $token[2]];
    }
}

$fail = false;

if ($scanned < MIN_FILES) {
    fwrite(
        STDERR,
        "FAIL: only $scanned file(s) scanned, expected at least "
        . MIN_FILES . ". The scan is not scanning.\n"
    );
    $fail = true;
}

if ($banned !== []) {
    fwrite(
        STDERR,
        'FAIL: ' . count($banned) . " literal getClass() call(s):\n"
    );
    foreach ($banned as list($name, $where)) {
        fwrite(
            STDERR,
            "  $where: getClass('$name') -- write a fully qualified new\n"
        );
    }
    $fail = true;
}

if ($fail) {
    exit(1);
}

echo "ok: no literal getClass() in $scanned file(s)\n";
exit(0);
