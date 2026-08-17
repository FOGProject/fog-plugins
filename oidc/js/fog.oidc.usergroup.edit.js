(function($) {
    // The Provider Groups tab this plugin injects onto the user group edit
    // page. See fog.oidc.role.edit.js for why 'url' and 'ownerID' are
    // needed instead of the derived endpoint.
    var oidcGroupsTable = $.registerAssociationTab({
        slug: 'usergroup-oidcgroup',
        item: 'oidcgroup',
        // mainLink / provider / associated -- the provider column is what
        // tells two same-named groups at different providers apart.
        columns: [
            {data: 'mainLink'},
            {data: 'oidcprovider'},
            {data: 'association'}
        ],
        url: '../management/index.php?node=oidcgroup'
            + '&sub=getUserGroupFeedList&ownerID=' + Common.id
    });
    // "Create New Provider Group" -- register a provider group without
    // leaving the page, then associate it here. The oidcgroup create form
    // is inert markup (see fog.oidcgroup.add.js, which only calls
    // wireCreateForm), so no onForm initialiser is needed.
    $.registerCreateAndAssociate('usergroup-oidcgroup', oidcGroupsTable);
})(jQuery);
