/**
 * Provider group list page (sub=list).
 *
 * The provider column comes from AddOIDCAPI::customizeDT(), because a group
 * row carries ogProviderID and no provider name -- and the same claim value
 * can legitimately be published by more than one provider.
 */
(function($) {
    $.registerListPage({
        order: [
            [0, 'asc']
        ],
        columns: [
            {data: 'mainlink'},
            {data: 'oidcprovider'}
        ],
        rowId: 'id',
        columnDefs: [
            {
                responsivePriority: -1,
                targets: 0
            },
            {
                responsivePriority: 0,
                targets: 1
            }
        ]
    });
})(jQuery);
