<?php
/**
 * OU report.
 *
 * PHP Version 5
 *
 * @category OU_Report
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */

namespace FOG\Plugins\Ou;

/**
 * OU report.
 *
 * @category OU_Report
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class OU_Report extends \FOG\Pages\ReportManagement
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
            _('OU Name'),
            _('Description'),
            _('Created By'),
            _('Created Time'),
            _('OU DN')
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
        echo $this->render(12, 'ou-report-table');
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
        \FOG\Router\Route::listem('ou');

        return (array) json_decode(\FOG\Router\Route::getData(), true);
    }
}
