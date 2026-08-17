(function($) {
    // The Provider Groups tab this plugin injects onto the role edit page.
    //
    // 'url' is passed because the table cannot be served from the role
    // node: a plugin cannot add a sub method to a core page class, so the
    // list lives on the plugin's own node and the role arrives as ownerID
    // rather than id (id there would name a provider group instead).
    var oidcGroupsTable = $.registerAssociationTab({
        slug: 'role-oidcgroup',
        item: 'oidcgroup',
        // mainLink / provider / associated -- the provider column is what
        // tells two same-named groups at different providers apart.
        columns: [
            {data: 'mainLink'},
            {data: 'oidcprovider'},
            {data: 'association'}
        ],
        url: '../management/index.php?node=oidcgroup'
            + '&sub=getRoleFeedList&ownerID=' + Common.id
    });
    // "Create New Provider Group" -- register a provider group without
    // leaving the page, then associate it here. The oidcgroup create form
    // is inert markup (see fog.oidcgroup.add.js, which only calls
    // wireCreateForm), so no onForm initialiser is needed.
    $.registerCreateAndAssociate('role-oidcgroup', oidcGroupsTable);
})(jQuery);
