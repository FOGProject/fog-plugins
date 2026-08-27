<?php
/**
 * Location report.
 *
 * PHP Version 5
 *
 * @category Location_Report
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
/**
 * Location report.
 *
 * @category Location_Report
 * @package  FOGProject
 * @author   Tom Elliott <tommygunsster@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class Location_Report extends \FOG\ReportManagement
{
    /**
     * The page to display.
     *
     * @return void
     */
    public function file()
    {
        $this->title = _('Export Locations');

        $this->headerData = [
            _('Location Name'),
            _('Description'),
            _('Created By'),
            _('Created Time'),
            _('Storage Group'),
            _('Storage Node'),
            _('Kernels/Inits from location')
        ];
        $this->attributes = [
            [],
            [],
            [],
            [],
            [],
            [],
            []
        ];

        echo '<div class="card">';
        echo '<div class="card-header">';
        echo '<h4 class="card-title">';
        echo _('Export Locations');
        echo '</h4>';
        echo '<p class="form-text">';
        echo _('Use the selector to choose how many items you want exported');
        echo '</p>';
        echo '</div>';
        echo '<div class="card-body">';
        echo $this->render(12, 'location-report-table');
        echo '</div>';
        echo '</div>';
    }
    /**
     * Returns the JSON data for this report.
     *
     * @return void
     */
    public function getList()
    {
        header('Content-type: application/json');
        \FOG\Router\Route::listem('location');
        http_response_code(\FOG\Router\HTTPResponseCodes::HTTP_SUCCESS);
        echo \FOG\Router\Route::getData();
        exit;
    }
}
