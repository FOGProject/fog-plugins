/**
 * OpenID Connect provider export page (sub=export).
 *
 * One entry per column FOGPage::_buildExportColumns() puts in the header
 * row, which is OIDC's $databaseFields minus the id -- and minus
 * clientSecret, which AddOIDCAPI::stripClientSecret() drops from the column
 * set via OIDC_EXPORT_ITEMS. DataTables throws when the two counts disagree,
 * so adding a field to the model means adding a line here.
 */
(function($) {
    $('#oidc-export-table').registerExportTable([
        {data: 'name'},
        {data: 'description', visible: false},
        {data: 'createdBy', visible: false},
        {data: 'createdTime', visible: false},
        {data: 'issuer'},
        {data: 'clientId', visible: false},
        {data: 'scopes', visible: false},
        {data: 'userClaim', visible: false},
        {data: 'groupClaim', visible: false},
        {data: 'enabled'},
        {data: 'jitProvision', visible: false},
        {data: 'allowapi', visible: false},
        {data: 'singleLogout', visible: false},
        {data: 'autoRedirect', visible: false},
        {data: 'icon', visible: false}
    ]);
})(jQuery);
