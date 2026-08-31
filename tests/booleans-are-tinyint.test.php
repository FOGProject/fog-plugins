<?php
/**
 * A plugin's two-state columns are tinyint(1), and it converts them through
 * the shared helper.
 *
 * fogproject ADR 0028. FOG spelled its two-state columns enum('0','1') for
 * years, which put a trap in every one of them: an integer written to an ENUM
 * is a member INDEX, not a value, so 1 selects the member '0' -- FALSE -- and
 * 0 is the error value STRICT_TRANS_TABLES refuses. Core converted its columns
 * in schema step 368; each plugin owns its own schema (ADR 0009) and so
 * converts its own.
 *
 * WHAT THIS PINS:
 *
 *   1. createSql() declares no ENUM('0','1') column. That is what a fresh
 *      install gets, and it is what a newly added boolean would break.
 *
 *   2. Each plugin that had two-state columns converts them by calling
 *      Schema::enumToTinyint(), not by writing its own ALTER. This is the
 *      load-bearing one: a direct `ALTER TABLE t MODIFY c TINYINT(1)`
 *      converts an ENUM BY INDEX, so every '0' becomes 1 and every '1'
 *      becomes 2 -- both truthy, silently, on every upgrading server. The
 *      helper goes through VARCHAR(1) so the conversion is by label. A plugin
 *      hand-rolling the ALTER is the exact mistake this forbids.
 *
 * It does NOT pin which columns each plugin converts -- check 1 covers a new
 * one without anyone editing this file.
 *
 * Deliberately NOT pinned: the historical `ADD COLUMN ... ENUM('0','1')`
 * steps further down each schema(). installdb() skips the steps an install
 * has already passed rather than replaying them, so editing one is invisible
 * to everyone past it and changes nothing; they must stay as they are.
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
$failures = [];
$checks = 0;

/**
 * Records one assertion.
 *
 * @param string $what     what is being asserted
 * @param bool   $ok       whether it holds
 * @param array  $failures collected failures
 * @param int    $checks   running count
 *
 * @return void
 */
function btCheck($what, $ok, &$failures, &$checks)
{
    $checks++;
    if (!$ok) {
        $failures[] = $what;
    }
}

/**
 * The body of one method, comments stripped -- prose in this repo discusses
 * ENUM spellings at length and must not satisfy or fail a check.
 *
 * @param string $file   the file to read
 * @param string $method the method name
 *
 * @return string
 */
function btMethod($file, $method)
{
    $clean = '';
    foreach (token_get_all((string) file_get_contents($file)) as $token) {
        if (is_array($token)
            && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)
        ) {
            continue;
        }
        $clean .= is_array($token) ? $token[1] : $token;
    }
    $at = strpos($clean, 'function ' . $method . '(');
    if (false === $at) {
        return '';
    }
    // Brace-match rather than cutting at the next `function `: these
    // schema() bodies contain closures, so the naive cut stops short of
    // exactly the step being checked for.
    $open = strpos($clean, '{', $at);
    if (false === $open) {
        return '';
    }
    $depth = 0;
    $len = strlen($clean);
    for ($i = $open; $i < $len; $i++) {
        if ($clean[$i] === '{') {
            $depth++;
        } elseif ($clean[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($clean, $at, $i - $at + 1);
            }
        }
    }
    return substr($clean, $at);
}

// Managers live at <plugin>/src/Managers/<Class>.php under the PSR-4 layout
// (tests/plugin-layout.test.php) -- the bucket directory is what the layout
// guarantees, not any filename suffix.
$managers = glob($root . '/*/src/Managers/*.php');
btCheck('plugin managers were found', count($managers) > 0, $failures, $checks);

// The plugins that shipped two-state columns. Named, because "calls
// enumToTinyint" is only a requirement for a plugin that has something to
// convert -- every other manager must be free of both.
$converters = [
    'ldap/src/Managers/LDAPManager.php',
    'oidc/src/Managers/OIDCManager.php',
    'location/src/Managers/LocationManager.php',
];

foreach ($managers as $file) {
    $rel = str_replace($root . '/', '', $file);
    $create = btMethod($file, 'createSql');
    btCheck(
        sprintf(
            '%s createSql() declares no ENUM(\'0\',\'1\') column -- a '
            . 'two-state column is TINYINT(1) (fogproject ADR 0028); an '
            . 'integer written to that enum is a member index, so 1 stores '
            . 'FALSE',
            $rel
        ),
        !preg_match("/ENUM\(\s*'0'\s*,\s*'1'\s*\)/i", $create),
        $failures,
        $checks
    );

    $schema = btMethod($file, 'schema');
    if (in_array($rel, $converters, true)) {
        btCheck(
            sprintf(
                '%s schema() converts its two-state columns through '
                . 'Schema::enumToTinyint() -- a hand-written '
                . 'ALTER ... MODIFY TINYINT(1) converts an ENUM by INDEX and '
                . 'silently switches on every flag in the table',
                $rel
            ),
            false !== strpos($schema, 'Schema::enumToTinyint'),
            $failures,
            $checks
        );
    }

    btCheck(
        sprintf(
            '%s schema() writes no ALTER of its own to TINYINT -- that is '
            . 'the conversion that goes by index; use '
            . 'Schema::enumToTinyint()',
            $rel
        ),
        !preg_match('/MODIFY[^;]{0,80}TINYINT/i', $schema),
        $failures,
        $checks
    );
}

printf("%d checks\n", $checks);
if (count($failures) > 0) {
    foreach ($failures as $f) {
        printf("  FAIL  %s\n", $f);
    }
    printf("%d failed\n", count($failures));
    exit(1);
}
printf("all passed\n");
exit(0);
