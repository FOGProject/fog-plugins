<?php
/**
 * Windows Key report.
 *
 * PHP Version 5
 *
 * @category WindowsKey_Report
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

namespace FOG\Plugins\WindowsKey\Reports;

/**
 * Windows Key report.
 *
 * @category WindowsKey_Report
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class WindowsKey_Report extends \FOG\Pages\ReportManagement
{
    /**
     * The page to display.
     *
     * @return void
     */
    public function file()
    {
        $this->title = self::reportTitle();

        $this->headerData = [
            _('Windows Key Name'),
            _('Description'),
            _('Created By'),
            _('Created Time'),
            _('Product Key')
        ];
        $this->attributes = [
            [],
            [],
            [],
            [],
            []
        ];

        echo '<div class="card">';
        echo '<div class="card-header">';
        echo '<h4 class="card-title">';
        echo $this->title;
        echo '</h4>';
        echo '<p class="form-text">';
        echo _('Use the selector to choose how many items you want exported');
        echo '</p>';
        echo '</div>';
        echo '<div class="card-body">';
        if (self::canReadKeys()) {
            echo $this->render(12, 'windowskey-report-table');
        } else {
            echo '<div class="alert alert-info mb-0">';
            echo _('You do not have permission to view Windows keys.');
            echo '</div>';
        }
        echo '</div>';
        echo '</div>';
    }
    /**
     * The rows this report serves.
     *
     * Split from the emit so the grid and the "CSV (All)" export run the
     * same query -- ReportManagement::exportAll() serves this, and cannot
     * take back control from a getList() that exits.
     *
     * @return array
     */
    protected function reportRows()
    {
        // No rows rather than an error: this feeds the grid and the export
        // alike, and both must return through ReportManagement.
        if (!self::canReadKeys()) {
            return ['data' => []];
        }
        \FOG\Router\Route::listem('windowskey');

        return (array) json_decode(\FOG\Router\Route::getData(), true);
    }
    /**
     * May the current user read the full keys this report shows?
     *
     * The report sits on core's `report` page node, so the page gate asks
     * only for report.view -- which the read-only Legacy Restricted role
     * holds. The grid and its CSV export carry every key unmasked. Core
     * gates its own Product Keys report on host.edit for the same reason
     * (GHSA-q38j-qx5x-w25v); this is the plugin's equivalent. A plugin has
     * no way to map its report in Authorization::REPORT_NODES, so the
     * check lives here, in front of both the page and reportRows(), which
     * feeds the grid and ReportManagement::exportAll() alike.
     *
     * windowskey.edit is the permission that can already set a key.
     *
     * @return bool
     */
    protected static function canReadKeys()
    {
        return \FOG\Auth\Authorization::can('windowskey.edit');
    }
}
