$(function() {
    // ---------------------------------------------------------------
    // GENERAL TAB
    $.registerGeneralTab({
        nameInputSel: '#name',
        formSel: '#directorydomain-general-form'
    });

    // ---------------------------------------------------------------
    // ORGANIZATIONAL UNITS TAB
    //
    // The whole tree, client side: listOUs() already read every page of it,
    // so search and scroll need no further round trip. Indented by depth
    // and kept in the server's root-first order, so it reads as a tree.
    var table = $('#directorydomain-ous-table'),
        sendBtn = $('#directorydomain-ous-send'),
        loaded = false,
        dt;

    function load() {
        if (loaded || !table.length) {
            return;
        }
        loaded = true;
        $.get(
            '../management/index.php?node=directorydomain&sub=getOUs&id='
                + table.data('domain-id'),
            function(res) {
                dt = table.DataTable({
                    data: res.data,
                    ordering: false,
                    pageLength: 25,
                    columns: [
                        {data: 'dn'},
                        {data: 'name'},
                        {data: 'dn'},
                        {data: 'groupID'}
                    ],
                    columnDefs: [
                        {
                            targets: 0,
                            searchable: false,
                            render: function(data, type, row) {
                                if (row.groupID) {
                                    return '';
                                }
                                return '<input type="checkbox" class="ou-pick" value="'
                                    + $.escapeHtml(data) + '"/>';
                            }
                        },
                        {
                            targets: 1,
                            render: function(data, type, row) {
                                if (type !== 'display') {
                                    return data;
                                }
                                return '<span style="padding-left:' + (row.depth * 1.5)
                                    + 'em"><i class="fas fa-folder"></i> '
                                    + $.escapeHtml(data) + '</span>';
                            }
                        },
                        {
                            targets: 3,
                            searchable: false,
                            render: function(data) {
                                if (!data) {
                                    return '';
                                }
                                return '<a href="../management/index.php?node=directorygroups&sub=edit&id='
                                    + data + '">' + $.escapeHtml(String(data)) + '</a>';
                            }
                        }
                    ]
                });
            },
            'json'
        ).fail(function(jqXHR) {
            loaded = false;
            $.notifyFromAPI(jqXHR.responseJSON, jqXHR);
        });
    }

    // Fetched when the tab is first shown, not with the page: a slow or
    // unreachable directory must not hold up the General tab.
    $('a[href="#directorydomain-ous"]').on('shown.bs.tab', load);
    if (window.location.hash === '#directorydomain-ous') {
        load();
    }

    sendBtn.on('click', function() {
        if (!dt) {
            return;
        }
        var ous = dt.$('input.ou-pick:checked').map(function() {
            return this.value;
        }).get();
        sendBtn.prop('disabled', true);
        $.apiCall('POST', table.data('update-url'), {ous: ous}, function(err) {
            sendBtn.prop('disabled', false);
            if (err) {
                return;
            }
            dt.destroy();
            table.find('tbody').remove();
            loaded = false;
            load();
        });
    });
});
