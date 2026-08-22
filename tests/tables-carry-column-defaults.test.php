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
 * Lifts one method's body out of a class file by matching braces.
 *
 * A fixed-length slice does not work: install() is often two lines, so any
 * window wide enough to hold a real body runs past the closing brace into the
 * uninstall() that usually follows -- and then every plugin looks like it
 * drops its own table. Twenty-one false positives on the first run.
 *
 * @param string $src  the source, comments already stripped
 * @param string $name the method name
 *
 * @return string
 */
function tcMethodBody($src, $name)
{
    $at = strpos($src, 'function ' . $name . '(');
    if (false === $at) {
        return '';
    }
    $open = strpos($src, '{', $at);
    if (false === $open) {
        return '';
    }
    $depth = 0;
    for ($i = $open, $n = strlen($src); $i < $n; $i++) {
        if ($src[$i] === '{') {
            $depth++;
        }
        if ($src[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($src, $open, $i - $open + 1);
            }
        }
    }

    return '';
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
    // The schema() contract. Plugin::installdb() uses it when it is there and
    // falls back to calling install() when it is not -- and that fallback is
    // where the destructive pattern lived: install() dropping the table and
    // rebuilding it, throwing away the user's rows on every reinstall.
    // wolbroadcast was the last plugin on that path.
    tcCheck(
        false !== strpos($src, 'function createSql('),
        sprintf(
            '%s builds its table outside a createSql() method, so there is '
            . 'no step 0 for schema() to hand Schema::applyUpdates()',
            $short
        )
    );
    // Only the plugin's OWN manager needs schema(). Plugin::installdb()
    // resolves exactly one class -- <PluginName>Manager -- and a secondary
    // manager (an association table, a sub-table) is reached as a STEP inside
    // that one's schema(), by design. Requiring schema() of every manager
    // flags eleven files that are correct.
    $plugin = basename(dirname(dirname($path)));
    $isOwnManager = strtolower(basename($path))
        === strtolower($plugin) . 'manager.class.php';
    if ($isOwnManager) {
        tcCheck(
            false !== strpos($src, 'function schema('),
            sprintf(
                '%s is its plugin\'s own manager and has no schema(), so '
                . 'Plugin::installdb() falls back to calling install() and '
                . 'the plugin gets no migration tracking at all -- pSchema '
                . 'stays at 0 and an added step never lands.',
                $short
            )
        );
    }
    $install = tcMethodBody($src, 'install');
    tcCheck(
        false === strpos($install, 'uninstall('),
        sprintf(
            '%s drops its own table on install(). uninstall() is a DROP, so '
            . 'reinstalling the plugin -- or repairing it after a failed '
            . 'install -- throws away every row the user entered. Use the '
            . 'non-destructive schema() contract instead.',
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
