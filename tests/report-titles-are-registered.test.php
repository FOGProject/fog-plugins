<?php
/**
 * A plugin report names itself, and serves its rows the way core reads them.
 *
 * TWO SILENT FAILURES, one gate.
 *
 * The label. fogproject's report menu builds an entry per file under
 * `<plugin>/reports/`, and if nothing names that report the label is
 * `ucwords()` of the FILE name -- so `ou_report.report.php` appears as
 * "Ou Report" while the page it opens is headed "Export OUs". Two names for
 * one screen. `REPORT_TITLE_DATA` is the seam that fixes it, and the key it
 * takes has to agree with THREE other things: the file name, the class name
 * (core derives the report's own heading from it) and the base64 `f`
 * parameter. Any one of them out of step gives a label that silently falls
 * back -- a plausible-looking wrong name, never an error.
 *
 * The rows. fogproject's "CSV (All)" button posts to `sub=exportAll`, which
 * serves `ReportManagement::reportRows()`. A report that still overrides
 * `getList()` cannot be reached that way -- `getList()` exits, so nothing
 * can take back control from it -- and the download is an EMPTY FILE. No
 * error, nothing logged, a CSV that looks like it worked. So a report's JS
 * may only ask for the button once the seam is in place, and this pins the
 * pair together.
 *
 * Source analysis, not execution: this repository is fetched on its own by
 * bin/fetch-plugins.sh and CI has no fogproject checkout to load core from.
 * The assertions are therefore on the AGREEMENT between three files rather
 * than on any one of them mentioning a symbol -- a stray mention cannot
 * satisfy a cross-file equality.
 *
 * Usage: php tests/report-titles-are-registered.test.php
 * Exit status 0 = pass, 1 = fail.
 */

$root = dirname(__DIR__);
$fails = [];
$checks = 0;

/**
 * @param string $label what is being asserted
 * @param bool   $cond  the assertion
 *
 * @return void
 */
function check($label, $cond)
{
    global $fails, $checks;
    $checks++;
    if (!$cond) {
        $fails[] = $label;
    }
}

/**
 * The menu key a report name produces: underscores as spaces, lower case.
 *
 * @param string $name file base name or short class name
 *
 * @return string
 */
function reportKey($name)
{
    return strtolower(str_replace('_', ' ', $name));
}

$reports = glob($root . '/*/reports/*.report.php');
check('there are plugin reports to check', count($reports) > 4);

foreach ($reports as $path) {
    $plugin = basename(dirname(dirname($path)));
    $base = basename($path, '.report.php');
    $key = reportKey($base);
    $src = (string) file_get_contents($path);
    $name = $plugin . '/' . basename($path);

    // 1. The class name has to produce the same key as the file name --
    //    core derives the report's own title from the CLASS, the menu from
    //    the FILE, and nothing warns when the two disagree.
    check(
        "$name: declares a class",
        1 === preg_match('/^class\s+(\w+)/m', $src, $m)
    );
    if (isset($m[1])) {
        check(
            "$name: the class name resolves to the same key as the file "
            . "(class '{$m[1]}' -> '" . reportKey($m[1]) . "', file -> '$key')",
            reportKey($m[1]) === $key
        );
    }

    // 2. The title comes from the map, once. A literal beside it is the
    //    two-names-for-one-screen state this whole change removes.
    check(
        "$name: takes its title from self::reportTitle()",
        false !== strpos($src, '$this->title = self::reportTitle();')
    );
    check(
        "$name: does not also set a literal title",
        1 !== preg_match('/\$this->title\s*=\s*_\(/', $src)
    );
    check(
        "$name: the card heading echoes that title rather than a literal",
        false !== strpos($src, 'echo $this->title;')
    );

    // 3. The rows seam. getList() must be GONE, not merely accompanied by
    //    reportRows() -- a report keeping both would serve the grid from
    //    one and the export from the other.
    check(
        "$name: implements reportRows()",
        1 === preg_match('/function\s+reportRows\s*\(/', $src)
    );
    check(
        "$name: no longer overrides getList()",
        0 === preg_match('/function\s+getList\s*\(/', $src)
    );
    check(
        "$name: reportRows() returns rather than echoing and exiting",
        false === strpos($src, 'exit;')
        && false === strpos($src, "header('Content-type: application/json')")
    );

    // 4. Some hook in this plugin registers the event AND names this exact
    //    report. Both halves, because a listener registered under a key
    //    that does not match the file is the silent fallback again.
    $registered = false;
    $named = false;
    foreach ((array) glob($root . '/' . $plugin . '/hooks/*.hook.php') as $hook) {
        $h = (string) file_get_contents($hook);
        if (false !== strpos($h, "'REPORT_TITLE_DATA'")) {
            $registered = true;
        }
        if (false !== strpos($h, "\$arguments['titles']['" . $key . "']")) {
            $named = true;
        }
    }
    check("$name: a hook registers REPORT_TITLE_DATA", $registered);
    check("$name: and names '$key', the key the menu will look up", $named);

    // 5. The toolbar. fullExport is only safe once reportRows() exists, and
    //    every report here now has it -- so its table should ask.
    $js = $root . '/' . $plugin . '/js/fog.' . $plugin . '.report.file.js';
    check("$name: has its table wiring at " . basename($js), file_exists($js));
    if (file_exists($js)) {
        $j = (string) file_get_contents($js);
        check(
            "$name: the table asks for the full export",
            false !== strpos($j, 'fullExport: true')
        );
        check(
            "$name: and its case matches the menu key",
            false !== strpos($j, "case '" . $key . "':")
        );
    }
}

if (count($fails)) {
    fwrite(STDERR, 'FAIL (' . count($fails) . ' of ' . $checks . "):\n");
    foreach ($fails as $f) {
        fwrite(STDERR, "  - $f\n");
    }
    exit(1);
}
echo 'ok  ' . $checks . " checks passed\n";
