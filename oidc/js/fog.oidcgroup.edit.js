/**
 * Provider group edit page (sub=edit).
 */
$(function() {
    // ---------------------------------------------------------------
    // GENERAL TAB
    $.registerGeneralTab({
        nameInputSel: '#oidcgroup',
        formSel: '#oidcgroup-general-form'
    });

    // ---------------------------------------------------------------
    // ROLE ASSOCIATION TAB
    var oidcGroupRolesTable = $.registerAssociationTab({
        slug: 'oidcgroup-role',
        item: 'role',
        sub: 'getRolesList'
    });
    $.registerCreateAndAssociate('oidcgroup-role', oidcGroupRolesTable);

    // ---------------------------------------------------------------
    // USER GROUP ASSOCIATION TAB
    var oidcGroupUserGroupsTable = $.registerAssociationTab({
        slug: 'oidcgroup-usergroup',
        item: 'usergroup',
        sub: 'getUserGroupsList'
    });
    $.registerCreateAndAssociate('oidcgroup-usergroup', oidcGroupUserGroupsTable);

    if (Common.search && Common.search.length > 0) {
        oidcGroupRolesTable.search(Common.search).draw();
        oidcGroupUserGroupsTable.search(Common.search).draw();
    }
});
