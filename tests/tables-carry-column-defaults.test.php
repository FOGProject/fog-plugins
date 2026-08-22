<?php
/**
 * Every plugin must build its table through FOGManagerController's wrapper,
 * so its optional columns get a DEFAULT.
 *
 * GH-1245. A column declared NOT NULL with no DEFAULT is only mandatory if
 * something enforces it, and for nine years nothing did: PDODB cleared
 * sql_mode on every connection, so the server downgraded the error to a
 * warning and substituted an implicit zero. Removing the clear turned every
 * one of those declarations into a real constraint, and an INSERT that omits
 * one now fails with error 1364.
 *
 * FOG's schema step repairs the tables an install already has. It cannot
 * repair a plugin's: createSql() runs as step 0 of the plugin's own schema(),
 * so a plugin installed AFTER the migration gets a table built the old way --
 * every optional column mandatory again.
 *
 * `$this->createTableSql()` (fogproject, FOGManagerController) fills a default
 * into every NOT NULL column that has none, leaving bare only the primary
 * key, anything the model declares required, and anything whose name ends in
 * ID. `Schema::createTable()` does not: it is the raw builder underneath, and
 * calling it directly is what leaves a table mandatory throughout. Measured
 * against the live 1.6 install, the difference is 31 columns.
 *
 * So this is a one-line rule with a real consequence, and the failure is
 * silent -- a plugin whose table is wrong looks fine until someone saves a
 * record without filling in an optional field.
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
 * Source with comments removed, so a commented-out call can neither satisfy
 * a check nor fail one -- two managers mention Schema::createTable() in
 * prose, explaining how it names its indexes.
 *
 * @param string $file the file to read
 *
 * @return string
 */
function tcStrip($file)
{
    $clean = '';
    foreach (token_get_all(file_get_contents($file)) as $token) {
        if (is_array($token)
            && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)
        ) {
            continue;
        }
        $clean .= is_array($token) ? $token[1] : $token;
    }

    return $clean;
}

/**
 * Records a check.
 *
 * @param bool   $ok      whether it passed
 * @param string $message what failed, stated as the defect
 *
 * @return void
 */
function tcCheck($ok, $message)
{
    global $checks, $failures;
    $checks++;
    if (!$ok) {
        $failures[] = $message;
    }
}

$managers = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($it as $file) {
    $path = $file->getPathname();
    if (!preg_match('/manager\.class\.php$/', $file->getFilename())) {
        continue;
    }
    if (false !== strpos($path, '/tests/')
        || false !== strpos($path, '/.git/')
    ) {
        continue;
    }
    $managers[] = $path;
}
sort($managers);

$builders = 0;
foreach ($managers as $path) {
    $src = tcStrip($path);
    if (false === strpos($src, 'createTable(')
        && false === strpos($src, 'createTableSql(')
    ) {
        continue;
    }
    $builders++;
    $short = str_replace($root . '/', '', $path);
    tcCheck(
        false === strpos($src, 'Schema::createTable('),
        sprintf(
            '%s calls Schema::createTable() directly, so its table is built '
            . 'with every optional column NOT NULL and no default. FOG\'s '
            . 'schema step cannot repair it -- the table is created by this '
            . 'plugin, after the step has run. Use $this->createTableSql(), '
            . 'which takes the identical arguments.',
            $short
        )
    );
    tcCheck(
        false !== strpos($src, 'createTableSql('),
        sprintf(
            '%s builds a table without going through createTableSql()',
            $short
        )
    );
}

tcCheck(
    $builders >= 20,
    sprintf(
        'only %d table-building managers were found, so the checks above '
        . 'pass vacuously -- the scan did not reach the plugins',
        $builders
    )
);

if (count($failures)) {
    fwrite(STDERR, 'FAIL (' . count($failures) . " of $checks):\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - $f\n");
    }
    exit(1);
}

echo "ok  $checks checks passed\n";
exit(0);
