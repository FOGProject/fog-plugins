(function($) {
    // The whole tab -- Update wiring plus the create-and-associate button and
    // modal -- is one shared helper (fog.common.js). These nine plugin tabs are
    // the same card with a different noun, and each used to carry its own copy
    // of the submit wiring.
    $.registerSelectTab({
        slug: 'host-directorygroups',
        send: 'directorygroups-send',
        node: 'directorygroups'
    });
})(jQuery);
(function($) {
    // ---------------------------------------------------------------
    // ACTIVE DIRECTORY TAB: Browse Directory
    //
    // Lists a domain's OUs; a click on one fills the tab's Domain Name and
    // Organizational Unit fields. The admin still clicks Update to save.
    var browseBtn = $('#directorygroups-browse'),
        modal = $('#directorygroups-browse-modal'),
        domainSel = $('#directorygroups-browse-domain'),
        table = $('#directorygroups-browse-table'),
        domains = {},
        dt;

    if (!browseBtn.length) {
        return;
    }

    function showDomain(id) {
        if (dt) {
            dt.destroy();
            table.find('tbody').remove();
            dt = null;
        }
        $.get(
            '../management/index.php?node=directorydomain&sub=getOUs&id=' + id,
            function(res) {
                dt = table.DataTable({
                    data: res.data,
                    ordering: false,
                    pageLength: 25,
                    columns: [
                        {data: 'name'},
                        {data: 'dn'}
                    ],
                    columnDefs: [
                        {
                            targets: 0,
                            render: function(data, type, row) {
                                if (type !== 'display') {
                                    return data;
                                }
                                return '<span style="padding-left:' + (row.depth * 1.5)
                                    + 'em"><i class="fas fa-folder"></i> '
                                    + $.escapeHtml(data) + '</span>';
                            }
                        }
                    ]
                });
            },
            'json'
        ).fail(function(jqXHR) {
            $.notifyFromAPI(jqXHR.responseJSON, jqXHR);
        });
    }

    table.on('click', 'tbody tr', function() {
        if (!dt) {
            return;
        }
        var row = dt.row(this).data();
        if (!row) {
            return;
        }
        $('#adDomain').val(domains[domainSel.val()]);
        $('#adOU').val(row.dn);
        modal.modal('hide');
    });

    domainSel.on('change', function() {
        showDomain(this.value);
    });

    browseBtn.on('click', function(e) {
        e.preventDefault();
        modal.modal('show');
        if (domainSel.children().length) {
            return;
        }
        $.get(
            '../management/index.php?node=directorydomain&sub=getDomains',
            function(res) {
                var current = ($('#adDomain').val() || '').toLowerCase(),
                    pick = null;
                $.each(res.data, function(i, d) {
                    domains[d.id] = d.name;
                    domainSel.append($('<option/>').val(d.id).text(d.name));
                    if (d.name.toLowerCase() === current) {
                        pick = d.id;
                    }
                });
                if (pick) {
                    domainSel.val(pick);
                }
                if (domainSel.val()) {
                    showDomain(domainSel.val());
                }
            },
            'json'
        );
    });
})(jQuery);
