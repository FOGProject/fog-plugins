(function($) {
    $.registerListPage({
        order: [
            [0, 'asc']
        ],
        columns: [
            {data: 'mainlink'},
            {data: 'storagegroupLink'},
            {data: 'storagenodeLink'},
            {data: 'protocol'},
            {data: 'tftp'}
        ],
        rowId: 'id',
        columnDefs: [
            {
                responsivePriority: -1,
                targets: 0
            },
            {
                render: function(data, type, row) {
                    var enabled = '<span class="badge bg-success"><i class="fas fa-circle-check"></i></span>',
                        disabled = '<span class="badge bg-danger"><i class="fas fa-circle-xmark"></i></span>';
                    if (row.tftp > 0) {
                        return enabled;
                    }
                    return disabled;
                },
                targets: 4
            }
        ]
    });
})(jQuery);
