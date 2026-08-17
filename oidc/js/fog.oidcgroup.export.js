/**
 * Provider group export page (sub=export).
 *
 * One entry per column FOGPage::_buildExportColumns() puts in the header
 * row, which is OIDCGroup's $databaseFields minus the id. providerID stays a
 * raw id rather than the provider's name: the header tokens are what the CSV
 * importer matches on, and a group only means anything alongside the
 * provider that published it.
 */
(function($) {
    $('#oidcgroup-export-table').registerExportTable([
        {data: 'providerID'},
        {data: 'name'}
    ]);
})(jQuery);
