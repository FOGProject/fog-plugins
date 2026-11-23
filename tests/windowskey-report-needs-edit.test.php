<?php
/**
 * The windowskey report needs windowskey.edit, not just report.view.
 *
 * The report sits on core's `report` page node, so the page gate asks only
 * for report.view -- which the read-only Legacy Restricted role holds. Its
 * grid and its CSV export carry every Windows key unmasked. Core gates its
 * own Product Keys report on host.edit for the same reason
 * (GHSA-q38j-qx5x-w25v).
 *
 * Pinned against the real report class, with doubles for the core classes
 * it calls:
 *  - a user without windowskey.edit gets no table on the page, and the row
 *    feed (which the grid and ReportManagement::exportAll() both use)
 *    returns no rows and never lists the keys;
 *  - a user with windowskey.edit gets the rows.
 *
 * Usage: php tests/windowskey-report-needs-edit.test.php
 * Exit status 0 = pass, 1 = fail.
 */

namespace FOG\Auth {
    /**
     * Authorization double: grants exactly the listed permissions.
     */
    class Authorization
    {
        /**
         * Permissions the current user holds.
         *
         * @var array
         */
        public static $granted = [];
        /**
         * Permissions asked for, in order.
         *
         * @var array
         */
        public static $asked = [];
        /**
         * Does the user hold $perm?
         *
         * @param string $perm the permission
         *
         * @return bool
         */
        public static function can($perm)
        {
            self::$asked[] = $perm;
            return in_array($perm, self::$granted, true);
        }
    }
}

namespace FOG\Router {
    /**
     * Route double: listem() loads a fixed key row, getData() returns it.
     */
    class Route
    {
        /**
         * Classes listem() was asked for.
         *
         * @var array
         */
        public static $listed = [];
        /**
         * Records the list.
         *
         * @param string $class the class listed
         *
         * @return void
         */
        public static function listem($class)
        {
            self::$listed[] = $class;
        }
        /**
         * The encoded payload listem() would leave.
         *
         * @return string
         */
        public static function getData()
        {
            return json_encode(
                ['data' => [['name' => 'win11', 'key' => 'SECRETKEY']]]
            );
        }
    }
}

namespace FOG\Pages {
    /**
     * ReportManagement double: only what the report calls.
     */
    class ReportManagement
    {
        /**
         * The page title.
         *
         * @var string
         */
        public $title = '';
        /**
         * Grid headings.
         *
         * @var array
         */
        public $headerData = [];
        /**
         * Grid cell attributes.
         *
         * @var array
         */
        public $attributes = [];
        /**
         * The report's title.
         *
         * @return string
         */
        public static function reportTitle()
        {
            return 'Export Windows Keys';
        }
        /**
         * Stand-in for the grid markup.
         *
         * @return string
         */
        public function render()
        {
            return '<table id="windowskey-report-table"></table>';
        }
        /**
         * Exposes the protected row feed.
         *
         * @return array
         */
        public function rows()
        {
            return $this->reportRows();
        }
    }
}

namespace {
    $root = dirname(__DIR__);
    require $root . '/windowskey/src/Reports/WindowsKey_Report.php';

    use FOG\Auth\Authorization;
    use FOG\Plugins\WindowsKey\Reports\WindowsKey_Report;

    $failures = [];

    // Denied: the page draws no table.
    Authorization::$granted = ['report.view', 'windowskey.view'];
    ob_start();
    (new WindowsKey_Report())->file();
    $page = (string)ob_get_clean();
    if (false !== strpos($page, 'windowskey-report-table')) {
        $failures[] = 'without windowskey.edit the page draws no grid';
    }
    if (!in_array('windowskey.edit', Authorization::$asked, true)) {
        $failures[] = 'the report asks for windowskey.edit';
    }

    // Denied: the row feed returns no rows and never lists the keys.
    $rows = (new WindowsKey_Report())->rows();
    if ([] !== ($rows['data'] ?? null)) {
        $failures[] = 'without windowskey.edit the row feed returns no rows';
    }
    if ([] !== \FOG\Router\Route::$listed) {
        $failures[] = 'without windowskey.edit the keys are never listed';
    }

    // Allowed: the page draws the grid and the rows come back.
    Authorization::$granted = ['report.view', 'windowskey.edit'];
    ob_start();
    (new WindowsKey_Report())->file();
    $page = (string)ob_get_clean();
    if (false === strpos($page, 'windowskey-report-table')) {
        $failures[] = 'with windowskey.edit the page draws the grid';
    }
    $rows = (new WindowsKey_Report())->rows();
    if ('SECRETKEY' !== ($rows['data'][0]['key'] ?? null)) {
        $failures[] = 'with windowskey.edit the row feed carries the rows';
    }

    if ($failures) {
        fwrite(
            STDERR,
            "FAIL: windowskey-report-needs-edit\n  "
            . implode("\n  ", $failures) . "\n"
        );
        exit(1);
    }
    echo "PASS: windowskey-report-needs-edit (6 checks)\n";
    exit(0);
}
