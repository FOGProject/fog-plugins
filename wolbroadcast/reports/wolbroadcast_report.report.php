<?php
/**
 * WOL Broadcast report.
 *
 * PHP Version 5
 *
 * @category Wolbroadcast_Report
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
/**
 * WOL Broadcast report.
 *
 * @category Wolbroadcast_Report
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class Wolbroadcast_Report extends \FOG\Pages\ReportManagement
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
            _('Broadcast Name'),
            _('Description'),
            _('Broadcast IP')
        ];
        $this->attributes = [
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
        echo $this->render(12, 'wolbroadcast-report-table');
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
        \FOG\Router\Route::listem('wolbroadcast');

        return (array) json_decode(\FOG\Router\Route::getData(), true);
    }
}
