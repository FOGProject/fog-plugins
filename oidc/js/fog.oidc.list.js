/**
 * OpenID Connect provider list page (sub=list).
 *
 * The Enabled column is rendered client-side rather than shown raw: the
 * value is the ENUM '0'/'1' the model stores, and a bare 0 or 1 under a
 * heading of "Enabled" reads as a count of something. Icons rather than
 * words for the same reason the image list uses them -- no string to
 * translate, and it scans at a glance.
 */
(function($) {
    $.registerListPage({
        order: [
            [0, 'asc']
        ],
        columns: [
            {data: 'mainlink'},
            {data: 'issuer'},
            {data: 'enabled'}
        ],
        rowId: 'id',
        columnDefs: [
            {
                responsivePriority: -1,
                targets: 0
            },
            {
                render: function(data, type, row) {
                    var on = '<span class="badge bg-success">'
                        + '<i class="fas fa-circle-check"></i></span>';
                    var off = '<span class="badge bg-secondary">'
                        + '<i class="fas fa-circle-xmark"></i></span>';
                    return row.enabled > 0 ? on : off;
                },
                targets: 2
            }
        ]
    });
})(jQuery);
