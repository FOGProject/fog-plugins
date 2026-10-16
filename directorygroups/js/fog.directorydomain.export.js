(function($) {
    // No bindPwd column: the export strips it (AddDirectoryGroupsAPI).
    $('#directorydomain-export-table').registerExportTable([
        {data: 'name'},
        {data: 'description', visible: false},
        {data: 'createdBy', visible: false},
        {data: 'createdTime', visible: false},
        {data: 'uri'},
        {data: 'bindDN'},
        {data: 'caCert', visible: false},
        {data: 'baseDN'},
        {data: 'ouBase', visible: false}
    ]);
})(jQuery);
